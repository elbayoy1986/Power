<?php
declare(strict_types=1);

namespace App\Configuracion;

use DomainException;
use PDO;
use Throwable;

/** Sucursales: alta, edición y activación. Cada sucursal es un establecimiento SAR. */
final class TiendasService
{
    public function __construct(private PDO $db)
    {
    }

    public function listar(): array
    {
        return $this->db->query(
            "SELECT t.*,
                (SELECT COUNT(*) FROM usuarios u WHERE u.tienda_id = t.id AND u.activo = 1) AS usuarios,
                (SELECT COUNT(*) FROM turnos_caja tc WHERE tc.tienda_id = t.id AND tc.estado = 'ABIERTO') AS turnos_abiertos,
                (SELECT COUNT(*) FROM cai c WHERE c.tienda_id = t.id AND c.activo = 1 AND c.fecha_limite >= CURDATE()
                    AND c.correlativo_actual < c.rango_final) AS cai_vigentes
             FROM tiendas t ORDER BY t.activa DESC, t.codigo_establecimiento"
        )->fetchAll();
    }

    /**
     * Crea o actualiza una sucursal. Al crearla:
     *  - genera su fila de inventario (existencia 0) para todos los productos;
     *  - opcionalmente copia mínimos de inventario y precios especiales de una sucursal base.
     */
    public function guardar(array $d): int
    {
        $id = (int) ($d['id'] ?? 0);
        $codigo = strtoupper(trim((string) ($d['codigo'] ?? '')));
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $direccion = trim((string) ($d['direccion'] ?? ''));
        $telefono = trim((string) ($d['telefono'] ?? ''));
        $est = trim((string) ($d['codigo_establecimiento'] ?? ''));

        if (!preg_match('/^[A-Z0-9-]{2,10}$/', $codigo)) {
            throw new DomainException('Código interno inválido: 2 a 10 letras, números o guion (ej.: TGU-02).');
        }
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            throw new DomainException('Escriba el nombre de la sucursal (máx. 100 caracteres).');
        }
        if ($direccion === '') {
            throw new DomainException('Escriba la dirección: aparece en la factura.');
        }
        if (!preg_match('/^\d{3}$/', $est) || $est === '000') {
            throw new DomainException('El código de establecimiento SAR lleva 3 dígitos (001, 002…).');
        }
        foreach (['codigo' => [$codigo, 'código interno'], 'codigo_establecimiento' => [$est, 'código de establecimiento']] as $col => [$valor, $nombreCampo]) {
            $st = $this->db->prepare("SELECT nombre FROM tiendas WHERE $col = ? AND id <> ?");
            $st->execute([$valor, $id]);
            if ($otra = $st->fetchColumn()) {
                throw new DomainException("El $nombreCampo $valor ya lo usa la sucursal $otra.");
            }
        }
        if ($id) {
            // Cambiar el establecimiento cambiaría el prefijo de facturas ya emitidas con CAI de esa sucursal
            $st = $this->db->prepare('SELECT codigo_establecimiento FROM tiendas WHERE id = ?');
            $st->execute([$id]);
            $actual = $st->fetchColumn();
            if ($actual !== false && $actual !== $est && $this->tieneFacturas($id)) {
                throw new DomainException('No se puede cambiar el código de establecimiento: la sucursal ya emitió facturas con él.');
            }
            $this->db->prepare('UPDATE tiendas SET codigo = ?, nombre = ?, direccion = ?, telefono = ?, codigo_establecimiento = ? WHERE id = ?')
                ->execute([$codigo, $nombre, $direccion, $telefono ?: null, $est, $id]);
            return $id;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO tiendas (codigo, nombre, direccion, telefono, codigo_establecimiento) VALUES (?,?,?,?,?)')
                ->execute([$codigo, $nombre, $direccion, $telefono ?: null, $est]);
            $id = (int) $this->db->lastInsertId();
            $base = (int) ($d['copiar_de'] ?? 0);
            $this->db->prepare(
                'INSERT INTO inventario (tienda_id, producto_id, existencia, minimo, costo_promedio)
                 SELECT ?, p.id, 0, COALESCE(b.minimo, 0), 0 FROM productos p
                 LEFT JOIN inventario b ON b.producto_id = p.id AND b.tienda_id = ?'
            )->execute([$id, $base]);
            if ($base) {
                $this->db->prepare('INSERT INTO precios_tienda (tienda_id, producto_id, precio_centavos) SELECT ?, producto_id, precio_centavos FROM precios_tienda WHERE tienda_id = ?')
                    ->execute([$id, $base]);
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function alternarActiva(int $id): bool
    {
        $st = $this->db->prepare('SELECT activa FROM tiendas WHERE id = ?');
        $st->execute([$id]);
        $activa = $st->fetchColumn();
        if ($activa === false) {
            throw new DomainException('Sucursal no encontrada.');
        }
        if ((int) $activa === 1) {
            $st = $this->db->prepare("SELECT COUNT(*) FROM turnos_caja WHERE tienda_id = ? AND estado = 'ABIERTO'");
            $st->execute([$id]);
            if ((int) $st->fetchColumn() > 0) {
                throw new DomainException('La sucursal tiene turnos de caja abiertos. Ciérrelos antes de desactivarla.');
            }
            if ((int) $this->db->query('SELECT COUNT(*) FROM tiendas WHERE activa = 1')->fetchColumn() <= 1) {
                throw new DomainException('Debe quedar al menos una sucursal activa.');
            }
        }
        $this->db->prepare('UPDATE tiendas SET activa = 1 - activa WHERE id = ?')->execute([$id]);
        return (int) $activa !== 1;
    }

    private function tieneFacturas(int $id): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM ventas WHERE tienda_id = ? LIMIT 1');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }
}
