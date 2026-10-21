<?php
declare(strict_types=1);

namespace App\Inventario;

use DomainException;
use PDO;

/**
 * Existencias por tienda y kárdex. TODO cambio de existencia pasa por aquí para que
 * siempre quede un movimiento que lo explique.
 *
 * Los métodos públicos de escritura NO abren transacción: el llamador decide
 * (una compra recibe varias líneas en una sola transacción). Los que validan
 * existencia bloquean la fila con FOR UPDATE, así que deben ejecutarse dentro de una.
 */
final class InventarioService
{
    public const MOTIVOS_AJUSTE = ['AJUSTE', 'MERMA'];

    public function __construct(private PDO $db)
    {
    }

    /** Crea la fila de inventario (existencia 0) en todas las tiendas para productos nuevos. */
    public function asegurarFilas(int $productoId): void
    {
        $this->db->prepare('INSERT IGNORE INTO inventario (tienda_id, producto_id) SELECT id, ? FROM tiendas')
            ->execute([$productoId]);
    }

    /** Salida por venta. No bloquea la venta si no hay existencia (la cocina ya entregó). */
    public function registrarVenta(int $tiendaId, int $productoId, float $cantidad, string $factura, int $usuarioId): void
    {
        if (!$this->controlaStock($productoId)) {
            return;
        }
        $this->mover($tiendaId, $productoId, 'VENTA', -$cantidad, $factura, $usuarioId);
    }

    /**
     * Entrada con costo (compra o traslado recibido): actualiza el costo promedio ponderado.
     *   nuevo = (existencia × costo_actual + cantidad × costo_entrada) / (existencia + cantidad)
     * Si la existencia previa es 0 o negativa, el costo pasa a ser el de la entrada.
     */
    public function entradaConCosto(int $tiendaId, int $productoId, float $cantidad, int $costo, string $tipo, string $ref, int $usuarioId): void
    {
        if ($cantidad <= 0) {
            throw new DomainException('La cantidad de entrada debe ser mayor que cero.');
        }
        $fila = $this->bloquear($tiendaId, $productoId);
        $exist = (float) $fila['existencia'];
        $nuevoCosto = $exist > 0
            ? (int) round(($exist * (int) $fila['costo_promedio'] + $cantidad * $costo) / ($exist + $cantidad))
            : $costo;
        $this->db->prepare('UPDATE inventario SET existencia = existencia + ?, costo_promedio = ? WHERE tienda_id = ? AND producto_id = ?')
            ->execute([$cantidad, $nuevoCosto, $tiendaId, $productoId]);
        $this->registrar($tiendaId, $productoId, $tipo, $cantidad, $ref, $usuarioId, $costo, null);
    }

    /**
     * Ajuste manual o merma. $cantidad con signo para AJUSTE (+ sobrante, − faltante);
     * para MERMA se toma siempre como salida. El motivo es obligatorio.
     */
    public function ajustar(int $tiendaId, int $productoId, string $tipo, float $cantidad, string $motivo, int $usuarioId): void
    {
        if (!in_array($tipo, self::MOTIVOS_AJUSTE, true)) {
            throw new DomainException('Tipo de ajuste inválido.');
        }
        if (trim($motivo) === '') {
            throw new DomainException('Escriba el motivo del ajuste (ej.: conteo físico, producto vencido).');
        }
        if ($cantidad == 0) {
            throw new DomainException('La cantidad no puede ser cero.');
        }
        if ($tipo === 'MERMA') {
            $cantidad = -abs($cantidad);
        }
        $fila = $this->bloquear($tiendaId, $productoId);
        if ($cantidad < 0 && (float) $fila['existencia'] + $cantidad < 0) {
            throw new DomainException(sprintf('No hay existencia suficiente: hay %s y se intenta sacar %s.', self::cant($fila['existencia']), self::cant(-$cantidad)));
        }
        $this->db->prepare('UPDATE inventario SET existencia = existencia + ? WHERE tienda_id = ? AND producto_id = ?')
            ->execute([$cantidad, $tiendaId, $productoId]);
        $this->registrar($tiendaId, $productoId, $tipo, $cantidad, 'MANUAL', $usuarioId, (int) $fila['costo_promedio'], trim($motivo));
    }

    /** Traslado entre tiendas: salida en origen y entrada al costo promedio del origen. */
    public function trasladar(int $origen, int $destino, int $productoId, float $cantidad, int $usuarioId): void
    {
        if ($origen === $destino) {
            throw new DomainException('La tienda destino debe ser distinta de la de origen.');
        }
        if ($cantidad <= 0) {
            throw new DomainException('La cantidad a trasladar debe ser mayor que cero.');
        }
        // Bloquear siempre en el mismo orden (id menor primero) evita interbloqueos entre traslados cruzados
        $a = $this->bloquear(min($origen, $destino), $productoId);
        $b = $this->bloquear(max($origen, $destino), $productoId);
        $filaOrigen = $origen < $destino ? $a : $b;
        if ((float) $filaOrigen['existencia'] < $cantidad) {
            throw new DomainException(sprintf('Existencia insuficiente en la tienda de origen: hay %s.', self::cant($filaOrigen['existencia'])));
        }
        $ref = 'TRASLADO ' . $origen . '→' . $destino;
        $costo = (int) $filaOrigen['costo_promedio'];
        $this->db->prepare('UPDATE inventario SET existencia = existencia - ? WHERE tienda_id = ? AND producto_id = ?')
            ->execute([$cantidad, $origen, $productoId]);
        $this->registrar($origen, $productoId, 'TRASLADO_SALIDA', -$cantidad, $ref, $usuarioId, $costo, null);
        $this->entradaConCosto($destino, $productoId, $cantidad, $costo, 'TRASLADO_ENTRADA', $ref, $usuarioId);
    }

    public function actualizarMinimo(int $tiendaId, int $productoId, float $minimo): void
    {
        if ($minimo < 0) {
            throw new DomainException('El mínimo no puede ser negativo.');
        }
        $this->asegurarFilas($productoId);
        $this->db->prepare('UPDATE inventario SET minimo = ? WHERE tienda_id = ? AND producto_id = ?')
            ->execute([$minimo, $tiendaId, $productoId]);
    }

    /** Existencias de la tienda con valor del inventario y bandera de mínimo. */
    public function existencias(int $tiendaId, string $buscar = ''): array
    {
        $st = $this->db->prepare(
            "SELECT p.id, p.sku, p.nombre, c.nombre AS categoria, i.existencia, i.minimo, i.costo_promedio,
                    ROUND(i.existencia * i.costo_promedio) AS valor, i.existencia <= i.minimo AS bajo_minimo
             FROM productos p
             JOIN categorias c ON c.id = p.categoria_id
             JOIN inventario i ON i.producto_id = p.id AND i.tienda_id = ?
             WHERE p.controla_stock = 1 AND p.activo = 1 AND (? = '' OR p.nombre LIKE ? OR p.sku LIKE ?)
             ORDER BY bajo_minimo DESC, c.orden, p.nombre"
        );
        $like = '%' . $buscar . '%';
        $st->execute([$tiendaId, $buscar, $like, $like]);
        return $st->fetchAll();
    }

    /**
     * Kárdex de un producto en una tienda: últimos movimientos con el saldo después de cada uno.
     * El saldo se reconstruye hacia atrás desde la existencia actual, así siempre cuadra con ella.
     */
    public function kardex(int $tiendaId, int $productoId, int $limite = 100): array
    {
        $st = $this->db->prepare('SELECT existencia FROM inventario WHERE tienda_id = ? AND producto_id = ?');
        $st->execute([$tiendaId, $productoId]);
        $saldo = (float) ($st->fetchColumn() ?: 0);

        $st = $this->db->prepare(
            'SELECT m.*, u.nombre AS usuario FROM inventario_movimientos m LEFT JOIN usuarios u ON u.id = m.usuario_id
             WHERE m.tienda_id = ? AND m.producto_id = ? ORDER BY m.id DESC LIMIT ' . max(1, $limite)
        );
        $st->execute([$tiendaId, $productoId]);
        $movs = $st->fetchAll();
        foreach ($movs as &$m) {
            $m['saldo'] = $saldo;
            $saldo -= (float) $m['cantidad'];
        }
        unset($m);
        return $movs;
    }

    private function mover(int $tiendaId, int $productoId, string $tipo, float $cantidad, string $ref, int $usuarioId): void
    {
        $this->db->prepare(
            'INSERT INTO inventario (tienda_id, producto_id, existencia) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE existencia = existencia + VALUES(existencia)'
        )->execute([$tiendaId, $productoId, $cantidad]);
        $this->registrar($tiendaId, $productoId, $tipo, $cantidad, $ref, $usuarioId, null, null);
    }

    private function bloquear(int $tiendaId, int $productoId): array
    {
        $this->db->prepare('INSERT IGNORE INTO inventario (tienda_id, producto_id) VALUES (?, ?)')->execute([$tiendaId, $productoId]);
        $st = $this->db->prepare('SELECT * FROM inventario WHERE tienda_id = ? AND producto_id = ? FOR UPDATE');
        $st->execute([$tiendaId, $productoId]);
        return $st->fetch();
    }

    private function registrar(int $tiendaId, int $productoId, string $tipo, float $cantidad, string $ref, int $usuarioId, ?int $costo, ?string $motivo): void
    {
        $this->db->prepare(
            'INSERT INTO inventario_movimientos (tienda_id, producto_id, tipo, cantidad, referencia, costo_unitario, usuario_id, motivo)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$tiendaId, $productoId, $tipo, $cantidad, $ref, $costo, $usuarioId, $motivo]);
    }

    private function controlaStock(int $productoId): bool
    {
        $st = $this->db->prepare('SELECT controla_stock FROM productos WHERE id = ?');
        $st->execute([$productoId]);
        return (bool) $st->fetchColumn();
    }

    public static function cant(float|string $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');
    }
}
