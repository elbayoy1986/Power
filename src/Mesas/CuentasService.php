<?php
declare(strict_types=1);

namespace App\Mesas;

use App\Cocina\ComandasService;
use App\Ventas\CarritoService;
use App\Ventas\FacturacionService;
use DomainException;
use PDO;
use PDOException;
use Throwable;

/**
 * Cuenta de mesa: se abre al sentar a los clientes, recibe rondas de pedidos que salen
 * como comandas a cocina y se cobra con una o varias facturas (cuenta dividida).
 *
 * - Una mesa tiene a lo sumo una cuenta activa (lo garantiza un índice único en la BD).
 * - Los precios NO se guardan en la cuenta: se calculan al cobrar con el mismo motor de
 *   la caja (promociones, descuentos de ley e ISV), igual que en mostrador.
 * - Lo enviado a cocina solo se quita anulándolo con motivo: queda registrado quién y por
 *   qué, y la estación recibe una comanda de anulación.
 * - La propina es voluntaria: se registra aparte, fuera del total facturado y del ISV.
 */
final class CuentasService
{
    public const ACTIVAS = ['ABIERTA', 'POR_COBRAR'];
    public const FORMAS_PROPINA = ['EFECTIVO', 'TARJETA'];

    private ComandasService $comandas;

    public function __construct(private PDO $db, private CarritoService $carrito, ?ComandasService $comandas = null)
    {
        $this->comandas = $comandas ?? new ComandasService($db);
    }

    public function abrir(int $tiendaId, int $mesaId, int $meseroId, int $comensales): int
    {
        $mesa = $this->mesa($tiendaId, $mesaId);
        if (!$mesa['activa']) {
            throw new DomainException("La mesa {$mesa['nombre']} está desactivada.");
        }
        if ($comensales < 1 || $comensales > 99) {
            throw new DomainException('Indique cuántas personas se sientan (1 a 99).');
        }
        $st = $this->db->prepare("SELECT 1 FROM usuarios WHERE id = ? AND activo = 1 AND rol <> 'COCINA' AND (tienda_id IS NULL OR tienda_id = ?)");
        $st->execute([$meseroId, $tiendaId]);
        if (!$st->fetchColumn()) {
            throw new DomainException('Mesero no válido para esta tienda.');
        }
        try {
            $this->db->prepare('INSERT INTO cuentas (tienda_id, mesa_id, mesero_id, comensales) VALUES (?,?,?,?)')
                ->execute([$tiendaId, $mesaId, $meseroId, $comensales]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // otro mesero la abrió primero: el índice único lo frena
                throw new DomainException("La mesa {$mesa['nombre']} ya está ocupada.");
            }
            throw $e;
        }
        return (int) $this->db->lastInsertId();
    }

    public function activaDeMesa(int $tiendaId, int $mesaId): ?int
    {
        $st = $this->db->prepare('SELECT id FROM cuentas WHERE mesa_activa = ? AND tienda_id = ?');
        $st->execute([$mesaId, $tiendaId]);
        $id = $st->fetchColumn();
        return $id ? (int) $id : null;
    }

    /** Cuenta con su mesa y mesero, los artículos con su estado en cocina y las facturas emitidas. */
    public function obtener(int $tiendaId, int $cuentaId): ?array
    {
        $st = $this->db->prepare(
            'SELECT c.*, m.nombre AS mesa, z.nombre AS zona, u.nombre AS mesero, TIMESTAMPDIFF(MINUTE, c.abierta_en, NOW()) AS minutos
             FROM cuentas c JOIN mesas m ON m.id = c.mesa_id JOIN zonas z ON z.id = m.zona_id JOIN usuarios u ON u.id = c.mesero_id
             WHERE c.id = ? AND c.tienda_id = ?'
        );
        $st->execute([$cuentaId, $tiendaId]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        $st = $this->db->prepare(
            "SELECT i.*, a.nombre AS agregado_por_nombre, COUNT(ci.id) AS partes,
                    COALESCE(SUM(ci.listo = 1 OR k.estado <> 'EN_COCINA'), 0) AS listas,
                    COALESCE(SUM(k.estado = 'ENTREGADA'), 0) AS entregadas
             FROM cuenta_items i JOIN usuarios a ON a.id = i.agregado_por
             LEFT JOIN (comanda_items ci JOIN comandas k ON k.id = ci.comanda_id AND k.tipo = 'PEDIDO')
                    ON ci.cuenta_item_id = i.id AND ci.anulado = 0
             WHERE i.cuenta_id = ? GROUP BY i.id ORDER BY i.ronda, i.id"
        );
        $st->execute([$cuentaId]);
        $items = array_map(function (array $i): array {
            [$partes, $listas, $entregadas] = [(int) $i['partes'], (int) $i['listas'], (int) $i['entregadas']];
            return [
                'id' => (int) $i['id'], 'ronda' => (int) $i['ronda'], 'tipo' => $i['tipo'],
                'producto_id' => $i['producto_id'] !== null ? (int) $i['producto_id'] : null,
                'combo_id' => $i['combo_id'] !== null ? (int) $i['combo_id'] : null,
                'descripcion' => $i['descripcion'], 'detalle' => $i['detalle'], 'cantidad' => (int) $i['cantidad'],
                'nota' => $i['nota'], 'estado' => $i['estado'], 'venta_id' => $i['venta_id'] !== null ? (int) $i['venta_id'] : null,
                'agregado_por' => $i['agregado_por_nombre'], 'agregado_en' => $i['agregado_en'], 'motivo_anulacion' => $i['motivo_anulacion'],
                'cocina' => match (true) {
                    $i['estado'] === 'ANULADO' => 'ANULADO',
                    $partes === 0 => 'SIN_COMANDA',
                    $entregadas === $partes => 'ENTREGADO',
                    $listas === $partes => 'LISTO',
                    default => 'EN_COCINA',
                },
            ];
        }, $st->fetchAll());

        $st = $this->db->prepare(
            "SELECT k.id, e.nombre AS estacion, k.lista_en FROM comandas k JOIN estaciones e ON e.id = k.estacion_id
             WHERE k.cuenta_id = ? AND k.tipo = 'PEDIDO' AND k.estado = 'LISTA' ORDER BY k.lista_en"
        );
        $st->execute([$cuentaId]);
        $porEntregar = $st->fetchAll();
        $st = $this->db->prepare("SELECT id, numero_factura, total, propina FROM ventas WHERE cuenta_id = ? AND estado = 'EMITIDA' ORDER BY id");
        $st->execute([$cuentaId]);

        return [
            'id' => (int) $c['id'], 'tienda_id' => (int) $c['tienda_id'], 'mesa_id' => (int) $c['mesa_id'], 'mesa' => $c['mesa'],
            'zona' => $c['zona'], 'mesero_id' => (int) $c['mesero_id'], 'mesero' => $c['mesero'], 'comensales' => (int) $c['comensales'],
            'estado' => $c['estado'], 'abierta_en' => $c['abierta_en'], 'minutos' => (int) $c['minutos'],
            'items' => $items, 'por_entregar' => $porEntregar, 'facturas' => $st->fetchAll(),
        ];
    }

    /**
     * Envía una ronda a cocina: guarda los artículos y genera las comandas por estación.
     * @param array $items formato del carrito más 'nota' por artículo
     * @return array{ronda:int, comandas:int[]}
     */
    public function enviar(int $tiendaId, int $cuentaId, int $usuarioId, array $items): array
    {
        if (!$items) {
            throw new DomainException('No hay artículos para enviar.');
        }
        $lineas = $this->carrito->construirLineas($tiendaId, $items); // valida productos, combos y opciones
        $notas = array_map(fn ($it) => mb_substr(trim((string) ($it['nota'] ?? '')), 0, 150), array_values($items));

        return $this->enTransaccion(function () use ($tiendaId, $cuentaId, $usuarioId, $lineas, $notas) {
            $c = $this->bloquearActiva($tiendaId, $cuentaId);
            $st = $this->db->prepare('SELECT COALESCE(MAX(ronda), 0) + 1 FROM cuenta_items WHERE cuenta_id = ?');
            $st->execute([$cuentaId]);
            $ronda = (int) $st->fetchColumn();

            $ins = $this->db->prepare(
                'INSERT INTO cuenta_items (cuenta_id, ronda, tipo, producto_id, combo_id, descripcion, opciones, detalle, cantidad, nota, agregado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $paraCocina = [];
            foreach ($lineas as $k => $l) {
                $esCombo = $l['tipo'] === 'COMBO';
                $componentes = array_map('intval', array_column($l['opciones'], 'producto_id'));
                $ins->execute([
                    $cuentaId, $ronda, $l['tipo'], $esCombo ? null : $l['ref_id'], $esCombo ? $l['ref_id'] : null, $l['descripcion'],
                    implode(',', $componentes), ComandasService::resumir(array_column($l['opciones'], 'nombre')),
                    $l['cantidad'], $notas[$k] ?: null, $usuarioId,
                ]);
                $paraCocina[] = [
                    'tipo' => $l['tipo'], 'ref_id' => $l['ref_id'], 'cantidad' => $l['cantidad'], 'descripcion' => $l['descripcion'],
                    'componentes' => $componentes, 'nota' => $notas[$k] ?: null, 'cuenta_item_id' => (int) $this->db->lastInsertId(),
                ];
            }
            $comandas = $this->comandas->generar($tiendaId, $cuentaId, null, 'Mesa ' . $c['mesa'], $usuarioId, $paraCocina);
            // Si ya habían pedido la cuenta y piden algo más, la mesa vuelve a estar en servicio
            $this->db->prepare("UPDATE cuentas SET estado = 'ABIERTA' WHERE id = ? AND estado = 'POR_COBRAR'")->execute([$cuentaId]);
            return ['ronda' => $ronda, 'comandas' => $comandas];
        });
    }

    /** Anula un artículo ya enviado (con motivo). La estación recibe el aviso. */
    public function anularItem(int $tiendaId, int $itemId, int $usuarioId, string $motivo): void
    {
        if (trim($motivo) === '') {
            throw new DomainException('Escriba el motivo de la anulación (ej.: el cliente cambió el pedido).');
        }
        $st = $this->db->prepare('SELECT cuenta_id FROM cuenta_items WHERE id = ?');
        $st->execute([$itemId]);
        $cuentaId = (int) ($st->fetchColumn() ?: throw new DomainException('Artículo no encontrado.'));

        $this->enTransaccion(function () use ($tiendaId, $cuentaId, $itemId, $usuarioId, $motivo) {
            $c = $this->bloquearActiva($tiendaId, $cuentaId);
            $st = $this->db->prepare('SELECT estado, venta_id FROM cuenta_items WHERE id = ? FOR UPDATE');
            $st->execute([$itemId]);
            $i = $st->fetch();
            if ($i['estado'] === 'ANULADO') {
                throw new DomainException('Ese artículo ya está anulado.');
            }
            if ($i['venta_id'] !== null) {
                throw new DomainException('Ese artículo ya se cobró; no se puede anular.');
            }
            $this->db->prepare(
                "UPDATE cuenta_items SET estado = 'ANULADO', anulado_por = ?, anulado_en = NOW(), motivo_anulacion = ? WHERE id = ?"
            )->execute([$usuarioId, mb_substr(trim($motivo), 0, 150), $itemId]);
            $this->comandas->anularItem($itemId, 'Mesa ' . $c['mesa'], $usuarioId);
            $this->cerrarSiSaldada($cuentaId, $usuarioId);
        });
    }

    /** Pasa la cuenta a otra mesa libre (los clientes se cambiaron de lugar). */
    public function transferir(int $tiendaId, int $cuentaId, int $mesaDestino): void
    {
        $destino = $this->mesa($tiendaId, $mesaDestino);
        if (!$destino['activa']) {
            throw new DomainException("La mesa {$destino['nombre']} está desactivada.");
        }
        $this->enTransaccion(function () use ($tiendaId, $cuentaId, $destino) {
            $c = $this->bloquearActiva($tiendaId, $cuentaId);
            if ((int) $c['mesa_id'] === (int) $destino['id']) {
                throw new DomainException('La cuenta ya está en esa mesa.');
            }
            try {
                $this->db->prepare('UPDATE cuentas SET mesa_id = ? WHERE id = ?')->execute([$destino['id'], $cuentaId]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new DomainException("La mesa {$destino['nombre']} está ocupada.");
                }
                throw $e;
            }
            // Lo que sigue en cocina debe llegar a la mesa nueva
            $this->db->prepare("UPDATE comandas SET referencia = ? WHERE cuenta_id = ? AND estado <> 'ENTREGADA'")
                ->execute(['Mesa ' . $destino['nombre'], $cuentaId]);
        });
    }

    public function cambiarMesero(int $tiendaId, int $cuentaId, int $meseroId): void
    {
        $st = $this->db->prepare("SELECT 1 FROM usuarios WHERE id = ? AND activo = 1 AND rol <> 'COCINA' AND (tienda_id IS NULL OR tienda_id = ?)");
        $st->execute([$meseroId, $tiendaId]);
        if (!$st->fetchColumn()) {
            throw new DomainException('Mesero no válido para esta tienda.');
        }
        $this->enTransaccion(function () use ($tiendaId, $cuentaId, $meseroId) {
            $this->bloquearActiva($tiendaId, $cuentaId);
            $this->db->prepare('UPDATE cuentas SET mesero_id = ? WHERE id = ?')->execute([$meseroId, $cuentaId]);
        });
    }

    public function cambiarComensales(int $tiendaId, int $cuentaId, int $comensales): void
    {
        if ($comensales < 1 || $comensales > 99) {
            throw new DomainException('Indique cuántas personas hay en la mesa (1 a 99).');
        }
        $this->enTransaccion(function () use ($tiendaId, $cuentaId, $comensales) {
            $this->bloquearActiva($tiendaId, $cuentaId);
            $this->db->prepare('UPDATE cuentas SET comensales = ? WHERE id = ?')->execute([$comensales, $cuentaId]);
        });
    }

    /** El cliente pidió la cuenta: la mesa se resalta para la caja. */
    public function pedirCuenta(int $tiendaId, int $cuentaId): void
    {
        $this->enTransaccion(function () use ($tiendaId, $cuentaId) {
            $this->bloquearActiva($tiendaId, $cuentaId);
            if (!$this->itemsPorCobrar($tiendaId, $cuentaId)['ids']) {
                throw new DomainException('La cuenta no tiene artículos por cobrar.');
            }
            $this->db->prepare("UPDATE cuentas SET estado = 'POR_COBRAR' WHERE id = ?")->execute([$cuentaId]);
        });
    }

    /** Libera una mesa abierta por error: solo si no tiene nada activo ni cobrado. */
    public function anular(int $tiendaId, int $cuentaId, int $usuarioId, string $motivo): void
    {
        if (trim($motivo) === '') {
            throw new DomainException('Escriba el motivo para liberar la mesa (ej.: los clientes se fueron).');
        }
        $this->enTransaccion(function () use ($tiendaId, $cuentaId, $usuarioId, $motivo) {
            $this->bloquearActiva($tiendaId, $cuentaId);
            $st = $this->db->prepare("SELECT COUNT(*) FROM cuenta_items WHERE cuenta_id = ? AND (estado = 'ACTIVO' OR venta_id IS NOT NULL)");
            $st->execute([$cuentaId]);
            if ((int) $st->fetchColumn() > 0) {
                throw new DomainException('La cuenta tiene artículos enviados a cocina: cóbrelos o anúlelos uno por uno con su motivo.');
            }
            $this->db->prepare(
                "UPDATE cuentas SET estado = 'ANULADA', cerrada_en = NOW(), cerrada_por = ?, motivo_anulacion = ? WHERE id = ?"
            )->execute([$usuarioId, mb_substr(trim($motivo), 0, 150), $cuentaId]);
        });
    }

    /**
     * Artículos pendientes de cobro en formato del carrito.
     * @param int[]|null $ids  null = todos; si alguno ya no está pendiente se rechaza
     * @param array<int,bool> $aplicaLegal  item_id => consumo del beneficiario del descuento de ley
     * @return array{ids:int[], items:array}
     */
    public function itemsPorCobrar(int $tiendaId, int $cuentaId, ?array $ids = null, array $aplicaLegal = []): array
    {
        $st = $this->db->prepare(
            "SELECT i.* FROM cuenta_items i JOIN cuentas c ON c.id = i.cuenta_id
             WHERE i.cuenta_id = ? AND c.tienda_id = ? AND i.estado = 'ACTIVO' AND i.venta_id IS NULL ORDER BY i.ronda, i.id"
        );
        $st->execute([$cuentaId, $tiendaId]);
        $pendientes = array_column($st->fetchAll(), null, 'id');
        if ($ids !== null) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (array_diff($ids, array_keys($pendientes))) {
                throw new DomainException('Algunos artículos ya se cobraron o se anularon. Recargue la cuenta.');
            }
            $pendientes = array_intersect_key($pendientes, array_flip($ids));
        }
        $items = [];
        foreach ($pendientes as $id => $i) {
            $items[] = [
                'tipo' => $i['tipo'], 'producto_id' => $i['producto_id'], 'combo_id' => $i['combo_id'], 'cantidad' => (int) $i['cantidad'],
                'opciones' => $i['opciones'] === '' ? [] : array_map('intval', explode(',', $i['opciones'])),
                'aplica_legal' => (bool) ($aplicaLegal[$id] ?? true),
            ];
        }
        return ['ids' => array_map('intval', array_keys($pendientes)), 'items' => $items];
    }

    /** Totales de lo que se va a cobrar (todo lo pendiente o una parte, con o sin descuento de ley). */
    public function cotizar(int $tiendaId, int $cuentaId, ?array $ids = null, array $aplicaLegal = [], ?int $legalId = null): array
    {
        $p = $this->itemsPorCobrar($tiendaId, $cuentaId, $ids, $aplicaLegal);
        return $this->carrito->cotizar($tiendaId, $p['items'], $legalId) + ['item_ids' => $p['ids']];
    }

    /**
     * Cobra los artículos indicados con una factura. Si no queda nada pendiente, la cuenta
     * se cierra y la mesa queda libre. Todo ocurre en la transacción de la factura.
     * @return array{venta_id:int, numero:string, total:int, cambio:int, propina:int, cuenta_cerrada:bool}
     */
    public function cobrar(int $tiendaId, int $cuentaId, int $usuarioId, int $turnoId, array $datos, FacturacionService $facturacion): array
    {
        $seleccion = array_map('intval', (array) ($datos['item_ids'] ?? []));
        if (!$seleccion) {
            throw new DomainException('Seleccione los artículos que se van a cobrar.');
        }
        ['ids' => $ids, 'items' => $items] = $this->itemsPorCobrar($tiendaId, $cuentaId, $seleccion, (array) ($datos['aplica_legal'] ?? []));
        [$propina, $propinaEfectivo] = self::propina($datos);
        // Si paga en efectivo y deja la propina en efectivo, lo recibido cubre factura + propina:
        // a la factura solo le corresponde lo recibido menos la propina (así el cambio sale bien)
        if (($datos['forma_pago'] ?? 'EFECTIVO') === 'EFECTIVO' && $propinaEfectivo > 0 && ($datos['efectivo_recibido'] ?? '') !== '') {
            $total = $this->carrito->cotizar($tiendaId, $items, !empty($datos['descuento_legal_id']) ? (int) $datos['descuento_legal_id'] : null)['resumen']['total'];
            if ((int) $datos['efectivo_recibido'] < $total + $propinaEfectivo) {
                throw new DomainException(sprintf('El efectivo recibido (L %s) no cubre la factura más la propina (L %s).',
                    number_format((int) $datos['efectivo_recibido'] / 100, 2), number_format(($total + $propinaEfectivo) / 100, 2)));
            }
            $datos['efectivo_recibido'] = (int) $datos['efectivo_recibido'] - $propinaEfectivo;
        }
        $cerrada = false;

        $r = $facturacion->emitir($tiendaId, $usuarioId, $turnoId, ['items' => $items] + $datos,
            function (int $ventaId, array $calculo) use ($tiendaId, $cuentaId, $usuarioId, $ids, $propina, $propinaEfectivo, &$cerrada) {
                $this->bloquearActiva($tiendaId, $cuentaId);
                if ($propina > $calculo['resumen']['total']) {
                    throw new DomainException('La propina no puede ser mayor que el total de la factura.');
                }
                $n = $this->db->exec(
                    "UPDATE cuenta_items SET venta_id = $ventaId WHERE cuenta_id = $cuentaId AND id IN (" . implode(',', $ids) . ")
                       AND estado = 'ACTIVO' AND venta_id IS NULL"
                );
                if ($n !== count($ids)) { // otra caja cobró o anuló a la vez: se revierte la factura
                    throw new DomainException('Otro usuario cobró o anuló artículos de esta cuenta al mismo tiempo. Recargue y vuelva a intentar.');
                }
                $this->db->prepare('UPDATE ventas SET cuenta_id = ?, propina = ?, propina_efectivo = ? WHERE id = ?')
                    ->execute([$cuentaId, $propina, $propinaEfectivo, $ventaId]);
                $cerrada = $this->cerrarSiSaldada($cuentaId, $usuarioId);
            });
        return $r + ['propina' => $propina, 'cuenta_cerrada' => $cerrada];
    }

    /** @return array{0:int,1:int} [propina, parte recibida en efectivo] en centavos */
    public static function propina(array $datos): array
    {
        $propina = (int) ($datos['propina'] ?? 0);
        if ($propina < 0) {
            throw new DomainException('La propina no puede ser negativa.');
        }
        $forma = $datos['propina_forma'] ?? 'EFECTIVO';
        if (!in_array($forma, self::FORMAS_PROPINA, true)) {
            throw new DomainException('Forma de pago de la propina inválida.');
        }
        return [$propina, $forma === 'EFECTIVO' ? $propina : 0];
    }

    /** Cierra la cuenta cuando ya no queda nada por cobrar y al menos algo se cobró. */
    private function cerrarSiSaldada(int $cuentaId, int $usuarioId): bool
    {
        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(estado = 'ACTIVO' AND venta_id IS NULL), 0) AS pendientes, COALESCE(SUM(venta_id IS NOT NULL), 0) AS cobrados
             FROM cuenta_items WHERE cuenta_id = ?"
        );
        $st->execute([$cuentaId]);
        $r = $st->fetch();
        if ((int) $r['pendientes'] > 0 || (int) $r['cobrados'] === 0) {
            return false;
        }
        $this->db->prepare("UPDATE cuentas SET estado = 'COBRADA', cerrada_en = NOW(), cerrada_por = ? WHERE id = ?")
            ->execute([$usuarioId, $cuentaId]);
        return true;
    }

    private function bloquearActiva(int $tiendaId, int $cuentaId): array
    {
        $st = $this->db->prepare(
            'SELECT c.*, m.nombre AS mesa FROM cuentas c JOIN mesas m ON m.id = c.mesa_id WHERE c.id = ? AND c.tienda_id = ? FOR UPDATE'
        );
        $st->execute([$cuentaId, $tiendaId]);
        $c = $st->fetch() ?: throw new DomainException('Cuenta no encontrada en esta tienda.');
        if (!in_array($c['estado'], self::ACTIVAS, true)) {
            throw new DomainException("La cuenta de la mesa {$c['mesa']} ya está cerrada.");
        }
        return $c;
    }

    private function mesa(int $tiendaId, int $mesaId): array
    {
        $st = $this->db->prepare('SELECT * FROM mesas WHERE id = ? AND tienda_id = ?');
        $st->execute([$mesaId, $tiendaId]);
        return $st->fetch() ?: throw new DomainException('Mesa no encontrada en esta tienda.');
    }

    private function enTransaccion(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try {
            $r = $fn();
            $this->db->commit();
            return $r;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
