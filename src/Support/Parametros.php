<?php
declare(strict_types=1);

namespace App\Support;

use PDOException;

/** Parámetros generales editables en Configuración → Parámetros (tabla `parametros`). */
final class Parametros
{
    /** clave => [etiqueta, tipo, valor por defecto] */
    public const DEFINICION = [
        'cai_alerta_facturas' => ['Alerta de CAI: números restantes', 'entero', '200'],
        'cai_alerta_dias' => ['Alerta de CAI: días antes de la fecha límite', 'entero', '30'],
        'fondo_caja_sugerido' => ['Fondo de caja sugerido al abrir turno (L)', 'dinero', '1000.00'],
        'mensaje_factura' => ['Mensaje al pie de la factura', 'texto', '¡Gracias por su compra!'],
    ];

    private static ?array $cache = null;

    public static function todos(): array
    {
        if (self::$cache === null) {
            self::$cache = array_map(fn ($d) => $d[2], self::DEFINICION);
            try {
                foreach (Database::pdo()->query('SELECT clave, valor FROM parametros') as $r) {
                    self::$cache[$r['clave']] = $r['valor'];
                }
            } catch (PDOException) {
                // Instalación sin migración 003: se usan los valores por defecto
            }
        }
        return self::$cache;
    }

    public static function texto(string $clave): string
    {
        return (string) (self::todos()[$clave] ?? '');
    }

    public static function entero(string $clave): int
    {
        return (int) self::texto($clave);
    }

    /** Valida y guarda todos los parámetros recibidos del formulario. */
    public static function guardar(array $valores): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO parametros (clave, valor, descripcion) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        foreach (self::DEFINICION as $clave => [$etiqueta, $tipo]) {
            $v = trim((string) ($valores[$clave] ?? ''));
            $v = match ($tipo) {
                'entero' => preg_match('/^\d{1,6}$/', $v) ? $v : throw new \DomainException("$etiqueta: escriba un número entero."),
                'dinero' => preg_match('/^\d{1,7}(\.\d{1,2})?$/', str_replace(',', '', $v))
                    ? number_format(Money::aCentavos($v) / 100, 2, '.', '') : throw new \DomainException("$etiqueta: monto inválido."),
                default => mb_strlen($v) <= 255 ? $v : throw new \DomainException("$etiqueta: máximo 255 caracteres."),
            };
            $st->execute([$clave, $v, $etiqueta]);
        }
        self::$cache = null;
    }
}
