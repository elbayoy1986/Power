<?php
declare(strict_types=1);

namespace App\Support;

final class Money
{
    public static function fmt(int $centavos): string
    {
        return 'L ' . number_format($centavos / 100, 2, '.', ',');
    }

    /** "1,125.50" -> 112550. La coma se trata como separador de miles. */
    public static function aCentavos(string|float|int $valor): int
    {
        return (int) round((float) str_replace(',', '', (string) $valor) * 100);
    }
}
