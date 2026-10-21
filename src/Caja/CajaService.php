<?php
declare(strict_types=1);

namespace App\Caja;

use DomainException;
use PDO;
use PDOException;
use Throwable;

/**
 * Turnos de caja: apertura con fondo inicial, ingresos/retiros de efectivo y cierre con arqueo.
 *
 *   efectivo esperado = fondo inicial + ventas cobradas en efectivo + ingresos − retiros
 *   diferencia        = efectivo contado − esperado   (negativo = faltante, positivo = sobrante)
 *
 * Un usuario solo puede tener un turno abierto (lo garantiza un índice único en la BD).
 * Sin turno abierto la caja no puede facturar.
 */
final class CajaService
{
    /** Denominaciones vigentes del lempira, en centavos. */
    public const DENOMINACIONES = [
        'billetes' => [50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100],
        'monedas' => [50, 20, 10],
    ];

    public function __construct(private PDO $db)
    {
    }

    public function turnoAbierto(int $usuarioId): ?array
    {
        $st = $this->db->prepare(
            "SELECT tc.*, t.nombre AS tienda FROM turnos_caja tc JOIN tiendas t ON t.id = tc.tienda_id
             WHERE tc.usuario_id = ? AND tc.estado = 'ABIERTO'"
        );
        $st->execute([$usuarioId]);
        return $st->fetch() ?: null;
    }

    public function abrir(int $tiendaId, int $usuarioId, int $fondo): int
    {
        if ($fondo < 0) {
            throw new DomainException('El fondo inicial no puede ser negativo.');
        }
        if ($abierto = $this->turnoAbierto($usuarioId)) {
            throw new DomainException("Ya tiene un turno abierto en {$abierto['tienda']} desde " . date('d/m H:i', strtotime($abierto['apertura'])) . '. Ciérrelo primero.');
        }
        try {
            $this->db->prepare('INSERT INTO turnos_caja (tienda_id, usuario_id, fondo_inicial) VALUES (?, ?, ?)')
                ->execute([$tiendaId, $usuarioId, $fondo]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // doble clic: el índice único lo frena
                throw new DomainException('Ya tiene un turno abierto.');
            }
            throw $e;
        }
        return (int) $this->db->lastInsertId();
    }

    public function movimiento(int $turnoId, int $usuarioId, string $tipo, int $monto, string $motivo): void
    {
        $turno = $this->turno($turnoId);
        if (!$turno || $turno['estado'] !== 'ABIERTO') {
            throw new DomainException('El turno no está abierto.');
        }
        if (!in_array($tipo, ['INGRESO', 'RETIRO'], true)) {
            throw new DomainException('Tipo de movimiento inválido.');
        }
        if ($monto <= 0) {
            throw new DomainException('El monto debe ser mayor que cero.');
        }
        if (trim($motivo) === '') {
            throw new DomainException('Escriba el motivo (ej.: remesa a bóveda, compra de hielo).');
        }
        if ($tipo === 'RETIRO' && $monto > $this->resumen($turnoId)['efectivo_esperado']) {
            throw new DomainException('No puede retirar más efectivo del que hay en caja.');
        }
        $this->db->prepare('INSERT INTO caja_movimientos (turno_id, usuario_id, tipo, monto, motivo) VALUES (?,?,?,?,?)')
            ->execute([$turnoId, $usuarioId, $tipo, $monto, trim($motivo)]);
    }

    /** Totales del turno para el arqueo y el reporte de cierre (corte X mientras está abierto, Z al cerrar). */
    public function resumen(int $turnoId): array
    {
        $turno = $this->turno($turnoId);
        if (!$turno) {
            throw new DomainException('Turno no encontrado.');
        }
        $st = $this->db->prepare(
            "SELECT forma_pago, COUNT(*) facturas, SUM(total) total, SUM(monto_efectivo) efectivo
             FROM ventas WHERE turno_id = ? AND estado = 'EMITIDA' GROUP BY forma_pago"
        );
        $st->execute([$turnoId]);
        $porPago = $st->fetchAll(PDO::FETCH_UNIQUE);

        $st = $this->db->prepare(
            "SELECT SUM(estado = 'EMITIDA') facturas, SUM(estado = 'ANULADA') anuladas,
                    COALESCE(SUM(IF(estado = 'EMITIDA', total, 0)),0) total,
                    COALESCE(SUM(IF(estado = 'EMITIDA', monto_efectivo, 0)),0) efectivo,
                    COALESCE(SUM(IF(estado = 'EMITIDA', descuento_promos, 0)),0) promos,
                    COALESCE(SUM(IF(estado = 'EMITIDA', descuento_legal, 0)),0) legal,
                    COALESCE(SUM(IF(estado = 'EMITIDA', isv_15 + isv_18, 0)),0) isv,
                    MIN(numero_factura) primera, MAX(numero_factura) ultima
             FROM ventas WHERE turno_id = ?"
        );
        $st->execute([$turnoId]);
        $v = $st->fetch();
        $efectivoVentas = (int) $v['efectivo']; // las anuladas no cuentan

        $st = $this->db->prepare('SELECT m.*, u.nombre AS usuario FROM caja_movimientos m JOIN usuarios u ON u.id = m.usuario_id WHERE turno_id = ? ORDER BY m.id');
        $st->execute([$turnoId]);
        $movs = $st->fetchAll();
        $ingresos = array_sum(array_map(fn ($m) => $m['tipo'] === 'INGRESO' ? (int) $m['monto'] : 0, $movs));
        $retiros = array_sum(array_map(fn ($m) => $m['tipo'] === 'RETIRO' ? (int) $m['monto'] : 0, $movs));

        return [
            'turno' => $turno,
            'por_pago' => $porPago,
            'ventas' => $v,
            'movimientos' => $movs,
            'ingresos' => $ingresos,
            'retiros' => $retiros,
            'efectivo_ventas' => $efectivoVentas,
            'efectivo_esperado' => (int) $turno['fondo_inicial'] + $efectivoVentas + $ingresos - $retiros,
        ];
    }

    /**
     * Cierra el turno con el conteo físico por denominación.
     * @param array<int|string,int|string> $conteo  centavos de la denominación => cantidad de piezas
     */
    public function cerrar(int $turnoId, int $cerradoPor, array $conteo, string $notas): array
    {
        $contado = self::totalConteo($conteo);
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT estado FROM turnos_caja WHERE id = ? FOR UPDATE');
            $st->execute([$turnoId]);
            if ($st->fetchColumn() !== 'ABIERTO') {
                throw new DomainException('El turno ya está cerrado.');
            }
            $esperado = $this->resumen($turnoId)['efectivo_esperado'];
            $diferencia = $contado - $esperado;
            if ($diferencia !== 0 && trim($notas) === '') {
                throw new DomainException(sprintf(
                    'Hay un %s de L %s. Explique la diferencia en las notas para poder cerrar.',
                    $diferencia < 0 ? 'faltante' : 'sobrante', number_format(abs($diferencia) / 100, 2)
                ));
            }
            $this->db->prepare(
                "UPDATE turnos_caja SET estado = 'CERRADO', cierre = NOW(), cerrado_por = ?, efectivo_esperado = ?,
                        efectivo_contado = ?, diferencia = ?, conteo = ?, notas = ? WHERE id = ?"
            )->execute([$cerradoPor, $esperado, $contado, $diferencia, json_encode(self::limpiarConteo($conteo)), trim($notas) ?: null, $turnoId]);
            $this->db->commit();
            return ['esperado' => $esperado, 'contado' => $contado, 'diferencia' => $diferencia];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function turno(int $turnoId): ?array
    {
        $st = $this->db->prepare(
            'SELECT tc.*, t.nombre AS tienda, u.nombre AS cajero, c.nombre AS cerrado_por_nombre
             FROM turnos_caja tc JOIN tiendas t ON t.id = tc.tienda_id JOIN usuarios u ON u.id = tc.usuario_id
             LEFT JOIN usuarios c ON c.id = tc.cerrado_por WHERE tc.id = ?'
        );
        $st->execute([$turnoId]);
        return $st->fetch() ?: null;
    }

    public static function totalConteo(array $conteo): int
    {
        $total = 0;
        foreach (self::limpiarConteo($conteo) as $denom => $piezas) {
            $total += $denom * $piezas;
        }
        return $total;
    }

    /** Solo denominaciones válidas y cantidades enteras no negativas. */
    public static function limpiarConteo(array $conteo): array
    {
        $validas = array_merge(...array_values(self::DENOMINACIONES));
        $limpio = [];
        foreach ($conteo as $denom => $piezas) {
            $denom = (int) $denom;
            $piezas = (int) $piezas;
            if (in_array($denom, $validas, true) && $piezas > 0) {
                $limpio[$denom] = $piezas;
            }
        }
        return $limpio;
    }
}
