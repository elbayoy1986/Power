<?php
declare(strict_types=1);

namespace App\Compras;

use App\Inventario\InventarioService;
use App\Pricing\PricingEngine;
use DomainException;
use PDO;
use Throwable;

/**
 * Compras a proveedores. Flujo: BORRADOR → RECIBIDA (entra al inventario y actualiza costo)
 *                                 BORRADOR → ANULADA
 * Una compra recibida no se edita ni se anula: las correcciones se hacen con ajustes de inventario,
 * así el kárdex conserva la historia completa.
 */
final class ComprasService
{
    public function __construct(private PDO $db, private InventarioService $inventario)
    {
    }

    /**
     * $datos: proveedor_id, numero_documento, fecha_documento, notas,
     *         lineas: [{producto_id, cantidad, costo (centavos sin ISV), tipo_impuesto}]
     */
    public function crear(int $tiendaId, int $usuarioId, array $datos, bool $recibir = false): int
    {
        $proveedorId = (int) ($datos['proveedor_id'] ?? 0);
        $doc = trim((string) ($datos['numero_documento'] ?? ''));
        $fecha = (string) ($datos['fecha_documento'] ?? '');

        $st = $this->db->prepare('SELECT 1 FROM proveedores WHERE id = ? AND activo = 1');
        $st->execute([$proveedorId]);
        if (!$st->fetchColumn()) {
            throw new DomainException('Seleccione un proveedor activo.');
        }
        if ($doc === '') {
            throw new DomainException('Escriba el número de factura del proveedor.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) {
            throw new DomainException('La fecha del documento es inválida o está en el futuro.');
        }
        $st = $this->db->prepare("SELECT id FROM compras WHERE proveedor_id = ? AND numero_documento = ? AND estado <> 'ANULADA'");
        $st->execute([$proveedorId, $doc]);
        if ($existente = $st->fetchColumn()) {
            throw new DomainException("La factura $doc de este proveedor ya está registrada (compra #$existente).");
        }

        $lineas = $this->validarLineas($datos['lineas'] ?? []);
        $subtotal = array_sum(array_column($lineas, 'subtotal'));
        $isv = array_sum(array_column($lineas, 'isv'));

        $this->db->beginTransaction();
        try {
            // Una anulada con el mismo número se renombra para liberar la llave única
            $this->db->prepare(
                "UPDATE compras SET numero_documento = CONCAT(numero_documento, '-ANUL', id)
                 WHERE proveedor_id = ? AND numero_documento = ? AND estado = 'ANULADA'"
            )->execute([$proveedorId, $doc]);

            $this->db->prepare(
                'INSERT INTO compras (tienda_id, proveedor_id, usuario_id, numero_documento, fecha_documento, subtotal, isv, total, notas)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$tiendaId, $proveedorId, $usuarioId, $doc, $fecha, $subtotal, $isv, $subtotal + $isv,
                trim((string) ($datos['notas'] ?? '')) ?: null]);
            $compraId = (int) $this->db->lastInsertId();

            $stL = $this->db->prepare(
                'INSERT INTO compra_lineas (compra_id, producto_id, cantidad, costo_unitario, tipo_impuesto, subtotal, isv) VALUES (?,?,?,?,?,?,?)'
            );
            foreach ($lineas as $l) {
                $stL->execute([$compraId, $l['producto_id'], $l['cantidad'], $l['costo'], $l['tipo_impuesto'], $l['subtotal'], $l['isv']]);
            }
            if ($recibir) {
                $this->recibirDentro($compraId, $usuarioId);
            }
            $this->db->commit();
            return $compraId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function recibir(int $compraId, int $usuarioId): void
    {
        $this->db->beginTransaction();
        try {
            $this->recibirDentro($compraId, $usuarioId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function anular(int $compraId): void
    {
        $st = $this->db->prepare("UPDATE compras SET estado = 'ANULADA' WHERE id = ? AND estado = 'BORRADOR'");
        $st->execute([$compraId]);
        if ($st->rowCount() === 0) {
            throw new DomainException('Solo se anulan compras en borrador. Para corregir una recibida, haga un ajuste de inventario.');
        }
    }

    public function obtener(int $compraId): ?array
    {
        $st = $this->db->prepare(
            'SELECT c.*, p.nombre AS proveedor, p.rtn AS proveedor_rtn, t.nombre AS tienda, u.nombre AS usuario, r.nombre AS receptor
             FROM compras c JOIN proveedores p ON p.id = c.proveedor_id JOIN tiendas t ON t.id = c.tienda_id
             JOIN usuarios u ON u.id = c.usuario_id LEFT JOIN usuarios r ON r.id = c.recibida_por WHERE c.id = ?'
        );
        $st->execute([$compraId]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        $st = $this->db->prepare(
            'SELECT l.*, p.nombre, p.sku FROM compra_lineas l JOIN productos p ON p.id = l.producto_id WHERE l.compra_id = ? ORDER BY l.id'
        );
        $st->execute([$compraId]);
        $c['lineas'] = $st->fetchAll();
        return $c;
    }

    private function recibirDentro(int $compraId, int $usuarioId): void
    {
        $st = $this->db->prepare('SELECT * FROM compras WHERE id = ? FOR UPDATE');
        $st->execute([$compraId]);
        $c = $st->fetch();
        if (!$c || $c['estado'] !== 'BORRADOR') {
            throw new DomainException('La compra no existe o ya fue recibida/anulada.');
        }
        $st = $this->db->prepare('SELECT * FROM compra_lineas WHERE compra_id = ?');
        $st->execute([$compraId]);
        foreach ($st->fetchAll() as $l) {
            $this->inventario->entradaConCosto(
                (int) $c['tienda_id'], (int) $l['producto_id'], (float) $l['cantidad'], (int) $l['costo_unitario'],
                'COMPRA', 'COMPRA #' . $compraId . ' · ' . $c['numero_documento'], $usuarioId
            );
        }
        $this->db->prepare("UPDATE compras SET estado = 'RECIBIDA', recibida_en = NOW(), recibida_por = ? WHERE id = ?")
            ->execute([$usuarioId, $compraId]);
    }

    /** Normaliza y valida las líneas; calcula subtotal e ISV en centavos. */
    public function validarLineas(array $lineas): array
    {
        $productos = $this->db->query('SELECT id, nombre FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
        $ok = [];
        foreach ($lineas as $i => $l) {
            $pid = (int) ($l['producto_id'] ?? 0);
            if ($pid === 0 && ($l['cantidad'] ?? '') === '') {
                continue; // fila vacía del formulario
            }
            $n = $i + 1;
            if (!isset($productos[$pid])) {
                throw new DomainException("Línea $n: seleccione un producto.");
            }
            $cant = (float) ($l['cantidad'] ?? 0);
            $costo = (int) ($l['costo'] ?? -1);
            $tipo = in_array($l['tipo_impuesto'] ?? '', ['G15', 'G18', 'EXE'], true) ? $l['tipo_impuesto'] : 'G15';
            if ($cant <= 0 || $cant > 999999) {
                throw new DomainException("Línea $n ({$productos[$pid]}): la cantidad debe ser mayor que cero.");
            }
            if ($costo < 0) {
                throw new DomainException("Línea $n ({$productos[$pid]}): el costo no puede ser negativo.");
            }
            $subtotal = (int) round($cant * $costo);
            $ok[] = [
                'producto_id' => $pid, 'cantidad' => $cant, 'costo' => $costo, 'tipo_impuesto' => $tipo,
                'subtotal' => $subtotal, 'isv' => (int) round($subtotal * PricingEngine::TASAS[$tipo]),
            ];
        }
        if (!$ok) {
            throw new DomainException('Agregue al menos un producto a la compra.');
        }
        return $ok;
    }
}
