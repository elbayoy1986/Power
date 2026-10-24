<?php
declare(strict_types=1);

namespace App\Cocina;

use DomainException;
use PDO;

/**
 * Comandas a cocina: reparte lo pedido entre las estaciones de preparación y lleva su estado
 *   EN_COCINA → LISTA (la cocina la despacha) → ENTREGADA (el mesero la llevó a la mesa).
 * Las mismas comandas se ven en la pantalla de cada estación y/o se imprimen.
 *
 * Ruta de cada producto: estación del producto → estación de su categoría → primera estación activa.
 * Un combo se reparte por componentes: cada estación recibe solo lo que prepara
 * (la hamburguesa a Cocina, el refresco a Bar). Sin estaciones activas no se generan comandas.
 *
 * Los métodos que escriben NO abren transacción: la comanda nace dentro de la misma
 * transacción que el pedido o la factura que la origina.
 */
final class ComandasService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Crea una comanda por estación con las líneas indicadas.
     * @param array<int,array{tipo:string, ref_id:int, cantidad:int, descripcion:string,
     *   componentes:int[], nota?:?string, cuenta_item_id?:?int}> $lineas  componentes = productos elegidos del combo
     * @return int[] ids de las comandas creadas
     */
    public function generar(int $tiendaId, ?int $cuentaId, ?int $ventaId, string $referencia, int $usuarioId, array $lineas): array
    {
        $rutas = $this->rutas();
        if (!$rutas) {
            return [];
        }
        $nombres = $this->db->query('SELECT id, nombre FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
        $porEstacion = [];
        foreach ($lineas as $l) {
            $base = [
                'cantidad' => (int) $l['cantidad'], 'descripcion' => $l['descripcion'],
                'nota' => $l['nota'] ?? null, 'cuenta_item_id' => $l['cuenta_item_id'] ?? null,
            ];
            if ($l['tipo'] === 'PRODUCTO') {
                $porEstacion[$rutas['productos'][$l['ref_id']] ?? $rutas['defecto']][] = $base + ['detalle' => ''];
                continue;
            }
            // La parte fija del combo (el pollo del "Combo Pollo") se prepara en la estación de su categoría,
            // así que esa estación siempre lo recibe, aunque todo lo elegible vaya a otras
            $partes = [$rutas['combos'][$l['ref_id']] ?? $rutas['defecto'] => []];
            foreach ($l['componentes'] as $pid) {
                $partes[$rutas['productos'][$pid] ?? $rutas['defecto']][] = $nombres[$pid] ?? ('#' . $pid);
            }
            foreach ($partes as $est => $componentes) {
                $porEstacion[$est][] = $base + ['detalle' => self::resumir($componentes)];
            }
        }

        $ids = [];
        $stC = $this->db->prepare(
            'INSERT INTO comandas (tienda_id, estacion_id, cuenta_id, venta_id, referencia, enviada_por) VALUES (?,?,?,?,?,?)'
        );
        foreach ($rutas['orden'] as $est) {
            if (empty($porEstacion[$est])) {
                continue;
            }
            $stC->execute([$tiendaId, $est, $cuentaId, $ventaId, mb_substr($referencia, 0, 60), $usuarioId]);
            $ids[] = $comandaId = (int) $this->db->lastInsertId();
            $this->insertarItems($comandaId, $porEstacion[$est]);
        }
        return $ids;
    }

    /**
     * Un artículo enviado se anuló: lo tacha en las comandas donde iba y avisa a esas
     * estaciones con una comanda de ANULACIÓN (se ve en rojo y se imprime).
     */
    public function anularItem(int $cuentaItemId, string $referencia, int $usuarioId): void
    {
        $st = $this->db->prepare(
            'SELECT ci.*, k.estacion_id, k.tienda_id, k.cuenta_id FROM comanda_items ci JOIN comandas k ON k.id = ci.comanda_id
             WHERE ci.cuenta_item_id = ? AND k.tipo = \'PEDIDO\' AND ci.anulado = 0'
        );
        $st->execute([$cuentaItemId]);
        $partes = $st->fetchAll();
        if (!$partes) {
            return;
        }
        $this->db->prepare('UPDATE comanda_items SET anulado = 1 WHERE cuenta_item_id = ?')->execute([$cuentaItemId]);

        $porEstacion = [];
        foreach ($partes as $p) {
            $porEstacion[$p['estacion_id']][] = $p;
            // Si la comanda original ya no tiene nada que preparar, sale de la pantalla:
            // todo anulado = cerrada; lo demás ya listo = lista para servir
            $this->db->prepare(
                "UPDATE comandas SET estado = 'ENTREGADA', lista_en = COALESCE(lista_en, NOW()), entregada_en = NOW()
                 WHERE id = ? AND NOT EXISTS (SELECT 1 FROM comanda_items WHERE comanda_id = ? AND anulado = 0)"
            )->execute([$p['comanda_id'], $p['comanda_id']]);
            $this->db->prepare(
                "UPDATE comandas SET estado = 'LISTA', lista_en = NOW()
                 WHERE id = ? AND estado = 'EN_COCINA' AND NOT EXISTS (SELECT 1 FROM comanda_items WHERE comanda_id = ? AND anulado = 0 AND listo = 0)"
            )->execute([$p['comanda_id'], $p['comanda_id']]);
        }
        $stC = $this->db->prepare(
            "INSERT INTO comandas (tienda_id, estacion_id, cuenta_id, referencia, tipo, enviada_por) VALUES (?,?,?,?,'ANULACION',?)"
        );
        foreach ($porEstacion as $est => $items) {
            $stC->execute([$items[0]['tienda_id'], $est, $items[0]['cuenta_id'], mb_substr($referencia, 0, 60), $usuarioId]);
            $this->insertarItems((int) $this->db->lastInsertId(), array_map(fn ($i) => [
                'cantidad' => (int) $i['cantidad'], 'descripcion' => $i['descripcion'], 'detalle' => $i['detalle'],
                'nota' => $i['nota'], 'cuenta_item_id' => $cuentaItemId,
            ], $items));
        }
    }

    /** Comandas de la pantalla: las que están en cocina (la más antigua primero) y las últimas despachadas. */
    public function pantalla(int $tiendaId, ?int $estacionId): array
    {
        $base = "SELECT k.*, e.nombre AS estacion, u.nombre AS enviada_por_nombre, m.nombre AS mesero, cu.comensales,
                        TIMESTAMPDIFF(SECOND, k.creada_en, NOW()) AS segundos
                 FROM comandas k JOIN estaciones e ON e.id = k.estacion_id JOIN usuarios u ON u.id = k.enviada_por
                 LEFT JOIN cuentas cu ON cu.id = k.cuenta_id LEFT JOIN usuarios m ON m.id = cu.mesero_id
                 WHERE k.tienda_id = ? AND k.creada_en >= NOW() - INTERVAL 1 DAY" . ($estacionId ? ' AND k.estacion_id = ?' : '');
        $params = $estacionId ? [$tiendaId, $estacionId] : [$tiendaId];
        $st = $this->db->prepare($base . " AND k.estado = 'EN_COCINA' ORDER BY k.creada_en, k.id");
        $st->execute($params);
        $activas = $st->fetchAll();
        $st = $this->db->prepare($base . " AND k.estado <> 'EN_COCINA' ORDER BY k.lista_en DESC, k.id DESC LIMIT 8");
        $st->execute($params);
        $recientes = $st->fetchAll();
        return ['activas' => $this->conItems($activas), 'recientes' => $this->conItems($recientes)];
    }

    public function obtener(int $tiendaId, int $comandaId): ?array
    {
        $st = $this->db->prepare(
            'SELECT k.*, e.nombre AS estacion, u.nombre AS enviada_por_nombre, m.nombre AS mesero, cu.comensales, t.nombre AS tienda
             FROM comandas k JOIN estaciones e ON e.id = k.estacion_id JOIN usuarios u ON u.id = k.enviada_por
             JOIN tiendas t ON t.id = k.tienda_id
             LEFT JOIN cuentas cu ON cu.id = k.cuenta_id LEFT JOIN usuarios m ON m.id = cu.mesero_id
             WHERE k.id = ? AND k.tienda_id = ?'
        );
        $st->execute([$comandaId, $tiendaId]);
        $k = $st->fetch();
        return $k ? $this->conItems([$k])[0] : null;
    }

    /** Marca o desmarca un artículo. Cuando no queda nada por preparar, la comanda pasa a LISTA sola. */
    public function marcarItem(int $tiendaId, int $itemId, bool $listo): void
    {
        $st = $this->db->prepare(
            'SELECT ci.comanda_id, k.estado FROM comanda_items ci JOIN comandas k ON k.id = ci.comanda_id WHERE ci.id = ? AND k.tienda_id = ?'
        );
        $st->execute([$itemId, $tiendaId]);
        $fila = $st->fetch() ?: throw new DomainException('Artículo no encontrado.');
        if ($fila['estado'] === 'ENTREGADA') {
            throw new DomainException('Esa comanda ya se entregó.');
        }
        $this->db->prepare('UPDATE comanda_items SET listo = ? WHERE id = ?')->execute([(int) $listo, $itemId]);
        $pendientes = $this->db->prepare('SELECT COUNT(*) FROM comanda_items WHERE comanda_id = ? AND listo = 0 AND anulado = 0');
        $pendientes->execute([$fila['comanda_id']]);
        if ((int) $pendientes->fetchColumn() === 0) {
            $this->db->prepare("UPDATE comandas SET estado = 'LISTA', lista_en = NOW() WHERE id = ? AND estado = 'EN_COCINA'")
                ->execute([$fila['comanda_id']]);
        } else {
            $this->db->prepare("UPDATE comandas SET estado = 'EN_COCINA', lista_en = NULL WHERE id = ? AND estado = 'LISTA'")
                ->execute([$fila['comanda_id']]);
        }
    }

    /** La cocina despacha la comanda completa. */
    public function despachar(int $tiendaId, int $comandaId): void
    {
        $k = $this->obtener($tiendaId, $comandaId) ?? throw new DomainException('Comanda no encontrada.');
        if ($k['estado'] !== 'EN_COCINA') {
            throw new DomainException('Esa comanda ya salió de cocina.');
        }
        $this->db->prepare('UPDATE comanda_items SET listo = 1 WHERE comanda_id = ?')->execute([$comandaId]);
        $this->db->prepare("UPDATE comandas SET estado = 'LISTA', lista_en = NOW() WHERE id = ?")->execute([$comandaId]);
    }

    /** Devuelve a la pantalla una comanda despachada por error. */
    public function recuperar(int $tiendaId, int $comandaId): void
    {
        $k = $this->obtener($tiendaId, $comandaId) ?? throw new DomainException('Comanda no encontrada.');
        if ($k['estado'] !== 'LISTA') {
            throw new DomainException($k['estado'] === 'ENTREGADA' ? 'Esa comanda ya se entregó en la mesa.' : 'La comanda sigue en cocina.');
        }
        $this->db->prepare('UPDATE comanda_items SET listo = 0 WHERE comanda_id = ?')->execute([$comandaId]);
        $this->db->prepare("UPDATE comandas SET estado = 'EN_COCINA', lista_en = NULL WHERE id = ?")->execute([$comandaId]);
    }

    /** El mesero la llevó a la mesa (también si la estación no la marcó, p. ej. bebidas de barra). */
    public function entregar(int $tiendaId, int $comandaId): void
    {
        $st = $this->db->prepare(
            "UPDATE comandas SET estado = 'ENTREGADA', lista_en = COALESCE(lista_en, NOW()), entregada_en = NOW()
             WHERE id = ? AND tienda_id = ? AND tipo = 'PEDIDO' AND estado <> 'ENTREGADA'"
        );
        $st->execute([$comandaId, $tiendaId]);
        if ($st->rowCount() !== 1) {
            throw new DomainException('Esa comanda ya se entregó o no existe.');
        }
    }

    /**
     * Reserva la impresión: solo el primer equipo que la pide la imprime, aunque haya
     * dos pantallas de la misma estación con impresión automática.
     */
    public function reclamarImpresion(int $tiendaId, int $comandaId): bool
    {
        $st = $this->db->prepare('UPDATE comandas SET impresa_en = NOW() WHERE id = ? AND tienda_id = ? AND impresa_en IS NULL');
        $st->execute([$comandaId, $tiendaId]);
        return $st->rowCount() === 1;
    }

    /**
     * Para FacturacionService::emitir(): la venta de mostrador sale a cocina en la misma transacción.
     * La referencia lleva el nombre del cliente si lo dieron, o los últimos dígitos de la factura para llamarlo.
     */
    public function alFacturarMostrador(int $tiendaId, int $usuarioId, string $cliente = ''): callable
    {
        return function (int $ventaId, array $calculo, string $numero) use ($tiendaId, $usuarioId, $cliente): void {
            $ref = 'Mostrador · ' . (trim($cliente) !== '' && strtoupper(trim($cliente)) !== 'CONSUMIDOR FINAL' ? trim($cliente) : '#' . substr($numero, -4));
            $this->generar($tiendaId, null, $ventaId, $ref, $usuarioId, self::desdeLineas($calculo['lineas']));
        };
    }

    /** Convierte las líneas del motor de precios (venta de mostrador) al formato de generar(). */
    public static function desdeLineas(array $lineas): array
    {
        return array_map(fn ($l) => [
            'tipo' => $l['tipo'], 'ref_id' => (int) $l['ref_id'], 'cantidad' => (int) $l['cantidad'],
            'descripcion' => $l['descripcion'], 'componentes' => array_map('intval', array_column($l['opciones'] ?? [], 'producto_id')),
        ], $lineas);
    }

    /** "Hamburguesa, Hamburguesa, Papas" → "2× Hamburguesa, Papas" */
    public static function resumir(array $nombres): string
    {
        $partes = [];
        foreach (array_count_values($nombres) as $n => $veces) {
            $partes[] = ($veces > 1 ? $veces . '× ' : '') . $n;
        }
        return implode(', ', $partes);
    }

    /** @return array{productos:array<int,int>, combos:array<int,int>, defecto:int, orden:int[]}|array{} */
    private function rutas(): array
    {
        $orden = array_map('intval', $this->db->query('SELECT id FROM estaciones WHERE activa = 1 ORDER BY orden, id')->fetchAll(PDO::FETCH_COLUMN));
        if (!$orden) {
            return [];
        }
        $productos = [];
        foreach ($this->db->query(
            'SELECT p.id, COALESCE(ep.id, ec.id) AS est FROM productos p JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN estaciones ep ON ep.id = p.estacion_id AND ep.activa = 1
             LEFT JOIN estaciones ec ON ec.id = c.estacion_id AND ec.activa = 1'
        ) as $r) {
            $productos[(int) $r['id']] = (int) ($r['est'] ?? $orden[0]);
        }
        $combos = [];
        foreach ($this->db->query(
            'SELECT co.id, e.id AS est FROM combos co JOIN categorias c ON c.id = co.categoria_id
             LEFT JOIN estaciones e ON e.id = c.estacion_id AND e.activa = 1'
        ) as $r) {
            $combos[(int) $r['id']] = (int) ($r['est'] ?? $orden[0]);
        }
        return ['productos' => $productos, 'combos' => $combos, 'defecto' => $orden[0], 'orden' => $orden];
    }

    private function insertarItems(int $comandaId, array $items): void
    {
        $st = $this->db->prepare(
            'INSERT INTO comanda_items (comanda_id, cuenta_item_id, cantidad, descripcion, detalle, nota) VALUES (?,?,?,?,?,?)'
        );
        foreach ($items as $i) {
            $st->execute([$comandaId, $i['cuenta_item_id'], $i['cantidad'], $i['descripcion'], mb_substr($i['detalle'], 0, 255), $i['nota'] ?: null]);
        }
    }

    private function conItems(array $comandas): array
    {
        if (!$comandas) {
            return [];
        }
        $ids = implode(',', array_map(fn ($k) => (int) $k['id'], $comandas));
        $porComanda = [];
        foreach ($this->db->query("SELECT * FROM comanda_items WHERE comanda_id IN ($ids) ORDER BY id") as $i) {
            $porComanda[$i['comanda_id']][] = [
                'id' => (int) $i['id'], 'cantidad' => (int) $i['cantidad'], 'descripcion' => $i['descripcion'],
                'detalle' => $i['detalle'], 'nota' => $i['nota'], 'listo' => (bool) $i['listo'], 'anulado' => (bool) $i['anulado'],
            ];
        }
        return array_map(fn ($k) => [
            'id' => (int) $k['id'], 'estacion_id' => (int) $k['estacion_id'], 'estacion' => $k['estacion'],
            'cuenta_id' => $k['cuenta_id'] !== null ? (int) $k['cuenta_id'] : null,
            'referencia' => $k['referencia'], 'tipo' => $k['tipo'], 'estado' => $k['estado'],
            'mesero' => $k['mesero'], 'comensales' => $k['comensales'] !== null ? (int) $k['comensales'] : null,
            'enviada_por' => $k['enviada_por_nombre'], 'creada_en' => $k['creada_en'], 'lista_en' => $k['lista_en'],
            'segundos' => isset($k['segundos']) ? (int) $k['segundos'] : null, 'impresa' => $k['impresa_en'] !== null,
            'tienda' => $k['tienda'] ?? null,
            'items' => $porComanda[$k['id']] ?? [],
        ], $comandas);
    }
}
