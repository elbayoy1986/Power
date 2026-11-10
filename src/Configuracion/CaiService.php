<?php
declare(strict_types=1);

namespace App\Configuracion;

use DomainException;
use PDO;
use PDOException;

/** Registro de CAI por sucursal y punto de emisión, con las validaciones de la resolución SAR. */
final class CaiService
{
    public const FORMATO = '/^[0-9A-F]{6}(-[0-9A-F]{6}){4}-[0-9A-F]{2}$/';

    public function __construct(private PDO $db)
    {
    }

    public function registrar(array $d): int
    {
        $tienda = (int) ($d['tienda_id'] ?? 0);
        $cai = strtoupper(trim((string) ($d['cai'] ?? '')));
        $pe = trim((string) ($d['punto_emision'] ?? ''));
        $tipo = trim((string) ($d['tipo_documento'] ?? '01')) ?: '01';
        $ini = (int) ($d['rango_inicial'] ?? 0);
        $fin = (int) ($d['rango_final'] ?? 0);
        $fecha = (string) ($d['fecha_limite'] ?? '');

        $st = $this->db->prepare('SELECT nombre, activa FROM tiendas WHERE id = ?');
        $st->execute([$tienda]);
        $t = $st->fetch();
        if (!$t || !$t['activa']) {
            throw new DomainException('Seleccione una sucursal activa.');
        }
        if (!preg_match(self::FORMATO, $cai)) {
            throw new DomainException('El CAI debe tener el formato XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX (0-9, A-F).');
        }
        if (!preg_match('/^\d{3}$/', $pe) || $pe === '000') {
            throw new DomainException('El punto de emisión lleva 3 dígitos (001, 002…).');
        }
        if (!preg_match('/^\d{2}$/', $tipo)) {
            throw new DomainException('Tipo de documento inválido (01 = factura).');
        }
        if ($ini < 1 || $fin < $ini || $fin > 99999999) {
            throw new DomainException('Rango inválido: "hasta" debe ser mayor o igual que "desde" y de máximo 8 dígitos.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < date('Y-m-d')) {
            throw new DomainException('La fecha límite de emisión es inválida o ya pasó.');
        }
        // Dos CAI de la misma sucursal, punto y tipo no pueden autorizar los mismos números
        $st = $this->db->prepare(
            'SELECT cai, rango_inicial, rango_final FROM cai
             WHERE tienda_id = ? AND punto_emision = ? AND tipo_documento = ? AND rango_inicial <= ? AND rango_final >= ?'
        );
        $st->execute([$tienda, $pe, $tipo, $fin, $ini]);
        if ($choque = $st->fetch()) {
            throw new DomainException(sprintf('El rango se cruza con el CAI %s (%d al %d) del mismo punto de emisión.',
                $choque['cai'], $choque['rango_inicial'], $choque['rango_final']));
        }
        try {
            $this->db->prepare(
                'INSERT INTO cai (tienda_id, cai, punto_emision, tipo_documento, rango_inicial, rango_final, correlativo_actual, fecha_limite)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$tienda, $cai, $pe, $tipo, $ini, $fin, $ini - 1, $fecha]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException('Ese CAI ya está registrado.');
            }
            throw $e;
        }
        return (int) $this->db->lastInsertId();
    }

    /**
     * Alertas por sucursal y caja (punto de emisión), sumando todos sus CAI activos: un CAI agotado
     * o vencido no alerta si esa misma caja ya tiene otro vigente registrado.
     * $tiendaId = 0 revisa todas las sucursales activas.
     * @return array<int,array{tienda_id:int, tienda:string, punto_emision:?string, nivel:string, mensaje:string}>
     */
    public function alertas(int $tiendaId, int $diasAviso, int $numerosAviso): array
    {
        $st = $this->db->prepare(
            'SELECT t.id AS tienda_id, t.nombre AS tienda, c.punto_emision,
                    SUM(IF(c.id IS NOT NULL AND c.fecha_limite >= CURDATE(), c.rango_final - c.correlativo_actual, 0)) AS disponibles,
                    DATEDIFF(MAX(IF(c.correlativo_actual < c.rango_final, c.fecha_limite, NULL)), CURDATE()) AS dias
             FROM tiendas t LEFT JOIN cai c ON c.tienda_id = t.id AND c.activo = 1
             WHERE t.activa = 1 AND (? = 0 OR t.id = ?)
             GROUP BY t.id, t.nombre, t.codigo_establecimiento, c.punto_emision
             ORDER BY t.codigo_establecimiento, c.punto_emision'
        );
        $st->execute([$tiendaId, $tiendaId]);
        $alertas = [];
        foreach ($st->fetchAll() as $r) {
            $base = ['tienda_id' => (int) $r['tienda_id'], 'tienda' => $r['tienda'], 'punto_emision' => $r['punto_emision']];
            $caja = $r['punto_emision'] !== null ? " (caja {$r['punto_emision']})" : '';
            $disp = (int) $r['disponibles'];
            if ($r['punto_emision'] === null) {
                $alertas[] = $base + ['nivel' => 'error', 'mensaje' => "{$r['tienda']} no tiene ningún CAI registrado: no puede facturar."];
            } elseif ($disp <= 0 || $r['dias'] === null || (int) $r['dias'] < 0) {
                $alertas[] = $base + ['nivel' => 'error', 'mensaje' => "{$r['tienda']}$caja: CAI vencido o agotado. No puede facturar hasta registrar uno nuevo."];
            } elseif ($disp <= $numerosAviso || (int) $r['dias'] <= $diasAviso) {
                $alertas[] = $base + ['nivel' => 'alerta', 'mensaje' => sprintf(
                    '%s%s: quedan %s números y %d días de vigencia. Solicite el CAI nuevo a la SAR.',
                    $r['tienda'], $caja, number_format($disp), (int) $r['dias']
                )];
            }
        }
        return $alertas;
    }

    public function desactivar(int $id): void
    {
        $this->db->prepare('UPDATE cai SET activo = 0 WHERE id = ?')->execute([$id]);
    }
}
