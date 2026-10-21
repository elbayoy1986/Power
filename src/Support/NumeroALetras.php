<?php
declare(strict_types=1);

namespace App\Support;

/** Convierte montos a letras para la factura: "CIENTO VEINTE LEMPIRAS CON 50/100". */
final class NumeroALetras
{
    private const UNIDADES = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIÚN', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS',
        'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];
    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function lempiras(int $centavos): string
    {
        $enteros = intdiv($centavos, 100);
        $cent = $centavos % 100;
        $texto = $enteros === 0 ? 'CERO' : self::entero($enteros);
        $moneda = $enteros === 1 ? 'LEMPIRA' : 'LEMPIRAS';
        // "UN MILLÓN DE LEMPIRAS"
        if ($enteros >= 1_000_000 && $enteros % 1_000_000 === 0) {
            $moneda = 'DE ' . $moneda;
        }
        return sprintf('%s %s CON %02d/100', $texto, $moneda, $cent);
    }

    public static function entero(int $n): string
    {
        if ($n >= 1_000_000) {
            $mill = intdiv($n, 1_000_000);
            $resto = $n % 1_000_000;
            $t = $mill === 1 ? 'UN MILLÓN' : self::entero($mill) . ' MILLONES';
            return trim($t . ' ' . ($resto ? self::entero($resto) : ''));
        }
        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;
            $t = $miles === 1 ? 'MIL' : self::entero($miles) . ' MIL';
            return trim($t . ' ' . ($resto ? self::entero($resto) : ''));
        }
        if ($n >= 100) {
            if ($n === 100) {
                return 'CIEN';
            }
            $resto = $n % 100;
            return trim(self::CENTENAS[intdiv($n, 100)] . ' ' . ($resto ? self::entero($resto) : ''));
        }
        if ($n < 30) {
            return self::UNIDADES[$n];
        }
        $u = $n % 10;
        return self::DECENAS[intdiv($n, 10)] . ($u ? ' Y ' . self::UNIDADES[$u] : '');
    }
}
