<?php
declare(strict_types=1);

namespace App\Ventas;

use App\Inventario\InventarioService;
use App\Support\NumeroALetras;
use DomainException;
use PDO;
use Throwable;

/**
 * Emisión de facturas con CAI (Régimen de Facturación SAR).
 *
 * - Toma el siguiente correlativo del CAI vigente de la tienda con bloqueo de fila
 *   (SELECT ... FOR UPDATE), por lo que dos cajas nunca obtienen el mismo número.
 * - Rechaza la emisión si el CAI venció (fecha límite) o si el rango se agotó.
 * - Guarda una copia inmutable (snapshot) de lo facturado y descarga inventario.
 * - Toda venta pertenece a un turno de caja abierto; la parte cobrada en efectivo
 *   alimenta el arqueo del cierre.
 * - $alEmitir corre dentro de la misma transacción (cobro de mesa, comandas a cocina):
 *   si falla, la factura no se emite y el correlativo no se consume.
 */
final class FacturacionService
{
    public const FORMAS_PAGO = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'MIXTO'];

    private InventarioService $inventario;

    public function __construct(private PDO $db, private CarritoService $carrito, ?InventarioService $inventario = null)
    {
        $this->inventario = $inventario ?? new InventarioService($db);
    }

    /**
     * @param ?callable(int $ventaId, array $calculo, string $numeroFactura): void $alEmitir
     * @return array{venta_id:int, numero:string, total:int, cambio:int}
     */
    public function emitir(int $tiendaId, int $usuarioId, int $turnoId, array $datos, ?callable $alEmitir = null): array
    {
        $legalId = !empty($datos['descuento_legal_id']) ? (int) $datos['descuento_legal_id'] : null;
        $calculo = $this->carrito->cotizar($tiendaId, $datos['items'] ?? [], $legalId);
        if (!$calculo['lineas']) {
            throw new DomainException('La orden está vacía.');
        }
        $regla = $calculo['regla_legal'];
        if ($regla && $regla['requiere_documento'] && trim((string) ($datos['beneficiario_documento'] ?? '')) === '') {
            throw new DomainException('El descuento de ley requiere el número de DNI o carné del beneficiario.');
        }
        $rtn = preg_replace('/\D/', '', (string) ($datos['cliente_rtn'] ?? ''));
        if ($rtn !== '' && strlen($rtn) !== 14) {
            throw new DomainException('El RTN debe tener 14 dígitos.');
        }

        $r = $calculo['resumen'];
        [$formaPago, $montoEfectivo, $cambio] = self::pago($datos, $r['total']);

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT tienda_id FROM turnos_caja WHERE id = ? AND usuario_id = ? AND estado = 'ABIERTO' FOR UPDATE");
            $st->execute([$turnoId, $usuarioId]);
            if ((int) $st->fetchColumn() !== $tiendaId) {
                throw new DomainException('Su turno de caja no está abierto en esta tienda. Abra turno para facturar.');
            }
            $cai = $this->tomarCorrelativo($tiendaId);
            $st = $this->db->prepare(
                'INSERT INTO ventas (tienda_id, usuario_id, turno_id, cai_id, cai, numero_factura, rango_autorizado, fecha_limite_emision,
                    cliente_rtn, cliente_nombre, descuento_legal_id, beneficiario_nombre, beneficiario_documento,
                    subtotal, descuento_promos, descuento_legal, importe_exonerado, importe_exento,
                    importe_gravado_15, importe_gravado_18, isv_15, isv_18, total, total_letras, forma_pago, monto_efectivo)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $tiendaId, $usuarioId, $turnoId, $cai['id'], $cai['cai'], $cai['numero'], $cai['rango'], $cai['fecha_limite'],
                $rtn ?: null, trim((string) ($datos['cliente_nombre'] ?? '')) ?: 'CONSUMIDOR FINAL',
                $regla['id'] ?? null,
                $regla ? (trim((string) ($datos['beneficiario_nombre'] ?? '')) ?: null) : null,
                $regla ? (trim((string) ($datos['beneficiario_documento'] ?? '')) ?: null) : null,
                $r['subtotal'], $r['descuento_promos'], $r['descuento_legal'], $r['importe_exonerado'], $r['importe_exento'],
                $r['importe_gravado_15'], $r['importe_gravado_18'], $r['isv_15'], $r['isv_18'], $r['total'],
                NumeroALetras::lempiras($r['total']),
                $formaPago, $montoEfectivo,
            ]);
            $ventaId = (int) $this->db->lastInsertId();

            $stLinea = $this->db->prepare(
                'INSERT INTO venta_lineas (venta_id, tipo, producto_id, combo_id, descripcion, cantidad, precio_unitario,
                    descuento_promo, descuento_legal, tipo_impuesto, total) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stOpcion = $this->db->prepare(
                'INSERT INTO venta_linea_opciones (venta_linea_id, producto_id, descripcion, recargo) VALUES (?,?,?,?)'
            );

            foreach ($calculo['lineas'] as $l) {
                $esCombo = $l['tipo'] === 'COMBO';
                $stLinea->execute([
                    $ventaId, $l['tipo'], $esCombo ? null : $l['ref_id'], $esCombo ? $l['ref_id'] : null,
                    $l['descripcion'], $l['cantidad'], $l['precio_unitario'], $l['descuento_promo'],
                    $l['descuento_legal'], $l['impuesto_final'], $l['neto'],
                ]);
                $lineaId = (int) $this->db->lastInsertId();

                // Descarga de inventario: producto directo o cada componente del combo
                $componentes = $esCombo ? array_column($l['opciones'], 'producto_id') : [$l['ref_id']];
                foreach ($l['opciones'] as $o) {
                    $stOpcion->execute([$lineaId, $o['producto_id'], $o['descripcion'], $o['recargo']]);
                }
                foreach ($componentes as $pid) {
                    $this->inventario->registrarVenta($tiendaId, (int) $pid, (float) $l['cantidad'], $cai['numero'], $usuarioId);
                }
            }

            if ($alEmitir) {
                $alEmitir($ventaId, $calculo, $cai['numero']);
            }
            $this->db->commit();
            return ['venta_id' => $ventaId, 'numero' => $cai['numero'], 'total' => $r['total'], 'cambio' => $cambio];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Forma de pago y parte en efectivo (centavos) para el arqueo.
     *  - EFECTIVO: todo el total; si se indica lo recibido, debe cubrir el total y se calcula el cambio.
     *  - MIXTO: el cajero indica cuánto fue en efectivo; debe ser mayor que 0 y menor que el total.
     *  - TARJETA / TRANSFERENCIA: nada en efectivo.
     * @return array{0:string,1:int,2:int} [forma, monto_efectivo, cambio]
     */
    public static function pago(array $datos, int $total): array
    {
        $forma = in_array($datos['forma_pago'] ?? '', self::FORMAS_PAGO, true) ? $datos['forma_pago'] : 'EFECTIVO';
        $recibido = isset($datos['efectivo_recibido']) && $datos['efectivo_recibido'] !== '' ? (int) $datos['efectivo_recibido'] : null;
        if ($forma === 'EFECTIVO') {
            if ($recibido !== null && $recibido < $total) {
                throw new DomainException(sprintf('El efectivo recibido (L %s) no cubre el total (L %s).',
                    number_format($recibido / 100, 2), number_format($total / 100, 2)));
            }
            return [$forma, $total, $recibido !== null ? $recibido - $total : 0];
        }
        if ($forma === 'MIXTO') {
            $efectivo = (int) ($datos['monto_efectivo'] ?? 0);
            if ($efectivo <= 0 || $efectivo >= $total) {
                throw new DomainException('En pago mixto indique la parte en efectivo: mayor que cero y menor que el total.');
            }
            return [$forma, $efectivo, 0];
        }
        return [$forma, 0, 0];
    }

    private function tomarCorrelativo(int $tiendaId): array
    {
        $st = $this->db->prepare(
            'SELECT c.*, t.codigo_establecimiento FROM cai c JOIN tiendas t ON t.id = c.tienda_id
             WHERE c.tienda_id = ? AND c.activo = 1 AND c.fecha_limite >= CURDATE()
               AND c.correlativo_actual < c.rango_final
             ORDER BY c.fecha_limite, c.id LIMIT 1 FOR UPDATE'
        );
        $st->execute([$tiendaId]);
        $c = $st->fetch();
        if (!$c) {
            throw new DomainException('No hay CAI vigente con números disponibles para esta tienda. No se puede facturar.');
        }
        $siguiente = (int) $c['correlativo_actual'] + 1;
        $this->db->prepare('UPDATE cai SET correlativo_actual = ? WHERE id = ?')->execute([$siguiente, $c['id']]);

        $prefijo = sprintf('%s-%s-%s-', $c['codigo_establecimiento'], $c['punto_emision'], $c['tipo_documento']);
        return [
            'id' => (int) $c['id'],
            'cai' => $c['cai'],
            'numero' => $prefijo . sprintf('%08d', $siguiente),
            'rango' => $prefijo . sprintf('%08d', $c['rango_inicial']) . ' al ' . $prefijo . sprintf('%08d', $c['rango_final']),
            'fecha_limite' => $c['fecha_limite'],
        ];
    }

    public function obtener(int $ventaId): ?array
    {
        $st = $this->db->prepare(
            'SELECT v.*, t.nombre AS tienda, t.direccion AS tienda_direccion, t.telefono AS tienda_telefono,
                    u.nombre AS cajero, d.nombre AS descuento_legal_nombre
             FROM ventas v JOIN tiendas t ON t.id = v.tienda_id JOIN usuarios u ON u.id = v.usuario_id
             LEFT JOIN descuentos_legales d ON d.id = v.descuento_legal_id WHERE v.id = ?'
        );
        $st->execute([$ventaId]);
        $v = $st->fetch();
        if (!$v) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM venta_lineas WHERE venta_id = ? ORDER BY id');
        $st->execute([$ventaId]);
        $v['lineas'] = $st->fetchAll();
        $st = $this->db->prepare(
            'SELECT o.* FROM venta_linea_opciones o JOIN venta_lineas l ON l.id = o.venta_linea_id WHERE l.venta_id = ?'
        );
        $st->execute([$ventaId]);
        $porLinea = [];
        foreach ($st->fetchAll() as $o) {
            $porLinea[$o['venta_linea_id']][] = $o;
        }
        foreach ($v['lineas'] as &$l) {
            $l['opciones'] = $porLinea[$l['id']] ?? [];
        }
        unset($l);
        $v['empresa'] = $this->db->query('SELECT * FROM empresa WHERE id = 1')->fetch();
        return $v;
    }
}
