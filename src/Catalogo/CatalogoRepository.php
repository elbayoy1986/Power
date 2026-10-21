<?php
declare(strict_types=1);

namespace App\Catalogo;

use DateTimeImmutable;
use PDO;

final class CatalogoRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** Productos vendibles con el precio de la tienda (si tiene) o el precio central. */
    public function productos(int $tiendaId, bool $soloVendibles = true): array
    {
        $st = $this->db->prepare(
            'SELECT p.id, p.categoria_id, c.nombre AS categoria, p.sku, p.nombre, p.tipo_impuesto,
                    COALESCE(pt.precio_centavos, p.precio_centavos) AS precio
             FROM productos p
             JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN precios_tienda pt ON pt.producto_id = p.id AND pt.tienda_id = ?
             WHERE p.activo = 1' . ($soloVendibles ? ' AND p.vendible_solo = 1' : '') . '
             ORDER BY c.orden, p.nombre'
        );
        $st->execute([$tiendaId]);
        return array_map(fn ($r) => ['precio' => (int) $r['precio']] + $r, $st->fetchAll());
    }

    /** Combos con sus grupos y opciones, listos para el armador de combos. */
    public function combos(bool $soloActivos = true): array
    {
        $combos = $this->db->query(
            'SELECT id, categoria_id, nombre, descripcion, precio_centavos AS precio, tipo_impuesto, activo
             FROM combos' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY nombre'
        )->fetchAll(PDO::FETCH_UNIQUE);
        if (!$combos) {
            return [];
        }
        foreach ($combos as $id => &$c) {
            $c = ['id' => $id, 'precio' => (int) $c['precio'], 'grupos' => []] + $c;
        }
        unset($c);

        $grupos = $this->db->query('SELECT * FROM combo_grupos ORDER BY orden')->fetchAll();
        $opciones = $this->db->query(
            'SELECT o.*, p.nombre FROM combo_opciones o JOIN productos p ON p.id = o.producto_id ORDER BY o.id'
        )->fetchAll();

        $porGrupo = [];
        foreach ($opciones as $o) {
            $porGrupo[$o['grupo_id']][] = [
                'producto_id' => (int) $o['producto_id'], 'nombre' => $o['nombre'],
                'recargo' => (int) $o['recargo_centavos'], 'por_defecto' => (bool) $o['por_defecto'],
            ];
        }
        foreach ($grupos as $g) {
            if (isset($combos[$g['combo_id']])) {
                $combos[$g['combo_id']]['grupos'][] = [
                    'id' => (int) $g['id'], 'nombre' => $g['nombre'],
                    'min' => (int) $g['min_sel'], 'max' => (int) $g['max_sel'],
                    'opciones' => $porGrupo[$g['id']] ?? [],
                ];
            }
        }
        return array_values($combos);
    }

    /** Promociones vigentes para la tienda en el momento dado, en formato del PricingEngine. */
    public function promocionesVigentes(int $tiendaId, DateTimeImmutable $ahora): array
    {
        $st = $this->db->prepare(
            "SELECT p.* FROM promociones p
             WHERE p.activa = 1
               AND :fecha BETWEEN p.fecha_inicio AND p.fecha_fin
               AND LOCATE(:dia, p.dias_semana) > 0
               AND :hora BETWEEN p.hora_inicio AND p.hora_fin
               AND (NOT EXISTS (SELECT 1 FROM promocion_tiendas x WHERE x.promocion_id = p.id)
                    OR EXISTS (SELECT 1 FROM promocion_tiendas x WHERE x.promocion_id = p.id AND x.tienda_id = :tienda))"
        );
        $st->execute([
            'fecha' => $ahora->format('Y-m-d'), 'dia' => $ahora->format('N'),
            'hora' => $ahora->format('H:i:s'), 'tienda' => $tiendaId,
        ]);
        $promos = $st->fetchAll();
        if (!$promos) {
            return [];
        }

        $ids = implode(',', array_map('intval', array_column($promos, 'id')));
        $items = [];
        foreach ($this->db->query("SELECT * FROM promocion_items WHERE promocion_id IN ($ids)") as $it) {
            $items[$it['promocion_id']][$it['grupo']][] = [
                'producto_id' => $it['producto_id'], 'combo_id' => $it['combo_id'], 'categoria_id' => $it['categoria_id'],
            ];
        }

        return array_map(fn ($p) => [
            'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'tipo' => $p['tipo'],
            'prioridad' => (int) $p['prioridad'],
            'cantidad_n' => (int) $p['cantidad_n'], 'cantidad_m' => (int) $p['cantidad_m'],
            'precio' => (int) $p['precio_centavos'], 'porcentaje' => (float) $p['porcentaje'],
            'items_a' => $items[$p['id']]['A'] ?? [], 'items_b' => $items[$p['id']]['B'] ?? [],
        ], $promos);
    }

    public function descuentosLegales(): array
    {
        return $this->db->query('SELECT * FROM descuentos_legales WHERE activo = 1 ORDER BY id')->fetchAll();
    }

    /** Regla de ley en formato del PricingEngine, o null si no existe/inactiva. */
    public function reglaLegal(?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM descuentos_legales WHERE id = ? AND activo = 1');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? [
            'id' => (int) $r['id'], 'nombre' => $r['nombre'], 'porcentaje' => (float) $r['porcentaje'],
            'tope' => (int) $r['tope_centavos'], 'acumulable' => (bool) $r['acumulable_promos'],
            'exento_isv' => (bool) $r['exento_isv'], 'requiere_documento' => (bool) $r['requiere_documento'],
        ] : null;
    }
}
