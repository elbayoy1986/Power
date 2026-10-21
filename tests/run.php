<?php
declare(strict_types=1);

// Pruebas sin dependencias: php tests/run.php
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Pricing\PricingEngine;
use App\Support\NumeroALetras;

$fallos = 0;
$total = 0;
function igual(string $nombre, mixed $esperado, mixed $real): void
{
    global $fallos, $total;
    $total++;
    if ($esperado === $real) {
        echo "  ✔ $nombre\n";
        return;
    }
    $fallos++;
    echo "  ✘ $nombre\n    esperado: " . var_export($esperado, true) . "\n    obtenido: " . var_export($real, true) . "\n";
}

$linea = fn (string $d, int $precio, int $cant, string $tipo = 'PRODUCTO', int $ref = 1, int $cat = 2, string $isv = 'G15', bool $legal = true) => [
    'descripcion' => $d, 'precio_unitario' => $precio, 'cantidad' => $cant, 'tipo' => $tipo,
    'ref_id' => $ref, 'categoria_id' => $cat, 'tipo_impuesto' => $isv, 'aplica_legal' => $legal,
];
$m = new PricingEngine();

echo "Totales e ISV\n";
$r = $m->calcular([$linea('Hamburguesa', 11500, 1)]);
igual('total con ISV incluido', 11500, $r['resumen']['total']);
igual('base gravada 15%', 10000, $r['resumen']['importe_gravado_15']);
igual('ISV 15%', 1500, $r['resumen']['isv_15']);
$r = $m->calcular([$linea('Cerveza', 5900, 2, isv: 'G18', ref: 14, cat: 7)]);
igual('ISV 18% (bebida alcohólica)', 1800, $r['resumen']['isv_18']);

echo "Mix & match precio fijo (2 hamburguesas por L 199)\n";
$promo = ['id' => 1, 'nombre' => '2x199', 'tipo' => 'MIX_PRECIO', 'cantidad_n' => 2, 'precio' => 19900, 'items_a' => [['categoria_id' => 2]]];
$r = $m->calcular([$linea('Clásica', 12000, 1, ref: 1), $linea('Doble', 16500, 1, ref: 2)], [$promo]);
igual('ahorro = 285 - 199', 8600, $r['resumen']['descuento_promos']);
igual('total = 199', 19900, $r['resumen']['total']);
igual('ahorro prorrateado suma exacta', 8600, $r['lineas'][0]['descuento_promo'] + $r['lineas'][1]['descuento_promo']);
$r = $m->calcular([$linea('Clásica', 12000, 3, ref: 1)], [$promo]);
igual('3 unidades: solo un grupo de 2', 12000 + 19900, $r['resumen']['total']);
$r = $m->calcular([$linea('Clásica', 9000, 2, ref: 1)], [$promo]);
igual('no aplica si el grupo cuesta menos que el precio promo', 0, $r['resumen']['descuento_promos']);

echo "NxM (2x1 en postres)\n";
$nxm = ['id' => 2, 'nombre' => '2x1', 'tipo' => 'NXM', 'cantidad_n' => 2, 'cantidad_m' => 1, 'items_a' => [['categoria_id' => 6]]];
$r = $m->calcular([$linea('Helado', 3000, 1, ref: 12, cat: 6), $linea('Pie', 3500, 1, ref: 13, cat: 6)], [$nxm]);
igual('gratis el más barato', 3000, $r['resumen']['descuento_promos']);
$r = $m->calcular([$linea('Helado', 3000, 3, ref: 12, cat: 6)], [$nxm]);
igual('3 helados: paga 2', 6000, $r['resumen']['total']);

echo "Porcentaje y compra X lleva Y\n";
$pct = ['id' => 3, 'nombre' => '20% bebidas', 'tipo' => 'PORCENTAJE', 'porcentaje' => 20, 'items_a' => [['categoria_id' => 5]]];
$r = $m->calcular([$linea('Refresco', 3500, 2, ref: 9, cat: 5)], [$pct]);
igual('20% de 2 refrescos', 1400, $r['resumen']['descuento_promos']);
$xy = ['id' => 4, 'nombre' => 'Combo + postre 50%', 'tipo' => 'X_LLEVA_Y', 'porcentaje' => 50,
    'items_a' => [['categoria_id' => 1]], 'items_b' => [['categoria_id' => 6]]];
$r = $m->calcular([
    $linea('Combo', 17500, 1, 'COMBO', 1, 1),
    $linea('Helado', 3000, 1, ref: 12, cat: 6), $linea('Pie', 3500, 1, ref: 13, cat: 6),
], [$xy]);
igual('1 combo: 50% al postre más barato', 1500, $r['resumen']['descuento_promos']);
igual('el pie queda sin descuento', 0, $r['lineas'][2]['descuento_promo']);

echo "Prioridad: cada unidad participa en una sola promoción\n";
$r = $m->calcular([$linea('Helado', 3000, 2, ref: 12, cat: 6)], [
    array_merge($pct, ['prioridad' => 50, 'items_a' => [['categoria_id' => 6]]]),
    ['prioridad' => 10] + $nxm,
]);
igual('gana 2x1 (prioridad 10) y no se suma el %', 3000, $r['resumen']['descuento_promos']);
igual('solo una promo aplicada', 1, count($r['promociones_aplicadas']));

echo "Descuentos de ley\n";
$tercera = ['id' => 1, 'nombre' => 'Tercera edad', 'porcentaje' => 25, 'tope' => 0, 'acumulable' => false, 'exento_isv' => false];
$r = $m->calcular([$linea('Combo', 20000, 1, 'COMBO', 1, 1)], [], $tercera);
igual('25% tercera edad', 5000, $r['resumen']['descuento_legal']);
igual('total con descuento', 15000, $r['resumen']['total']);

$r = $m->calcular([$linea('Combo', 20000, 1, 'COMBO', 1, 1, legal: true), $linea('Combo acompañante', 20000, 1, 'COMBO', 1, 1, legal: false)], [], $tercera);
igual('solo el consumo del beneficiario', 5000, $r['resumen']['descuento_legal']);

$r = $m->calcular([$linea('Combo', 20000, 2, 'COMBO', 1, 1)], [], ['tope' => 20000] + $tercera);
igual('tope de consumo L 200', 5000, $r['resumen']['descuento_legal']);

$cuarta = ['nombre' => 'Cuarta edad', 'porcentaje' => 30] + $tercera;
$r = $m->calcular([$linea('Combo', 20000, 1, 'COMBO', 1, 1)], [], $cuarta);
igual('30% cuarta edad (parámetro)', 6000, $r['resumen']['descuento_legal']);

// No acumulable: el cliente recibe el MAYOR beneficio, no la suma
$r = $m->calcular([$linea('Helado', 3000, 2, ref: 12, cat: 6)], [$nxm], $tercera);
igual('no acumulable: 2x1 (L 30) > 25% (L 15) → solo promo', 3000, $r['resumen']['descuento_promos'] + $r['resumen']['descuento_legal']);
$r = $m->calcular([$linea('Refresco', 3500, 2, ref: 9, cat: 5)], [['porcentaje' => 10] + $pct], $tercera);
igual('no acumulable: 25% > 10% → se completa hasta 25%', 1750, $r['resumen']['descuento_promos'] + $r['resumen']['descuento_legal']);
$r = $m->calcular([$linea('Refresco', 3500, 2, ref: 9, cat: 5)], [['porcentaje' => 10] + $pct], ['acumulable' => true] + $tercera);
igual('acumulable: 10% y luego 25% sobre el neto', 700 + 2 * 788, $r['resumen']['descuento_promos'] + $r['resumen']['descuento_legal']);

$r = $m->calcular([$linea('Combo', 23000, 1, 'COMBO', 1, 1)], [], ['exento_isv' => true] + $tercera);
igual('exento ISV: (230 - 25%) / 1.15', 15000, $r['resumen']['total']);
igual('exento ISV: va a importe exento', 15000, $r['resumen']['importe_exento']);
igual('exento ISV: sin ISV', 0, $r['resumen']['isv_15']);

echo "Cuadre fiscal (gravado + ISV + exento = total)\n";
$r = $m->calcular([
    $linea('Clásica', 12000, 3, ref: 1), $linea('Cerveza', 5500, 2, ref: 14, cat: 7, isv: 'G18'),
    $linea('Helado', 3000, 3, ref: 12, cat: 6),
], [$promo, $nxm], $tercera);
$s = $r['resumen'];
igual('cuadra', $s['total'], $s['importe_gravado_15'] + $s['isv_15'] + $s['importe_gravado_18'] + $s['isv_18'] + $s['importe_exento']);
igual('subtotal - descuentos = total', $s['total'], $s['subtotal'] - $s['descuento_promos'] - $s['descuento_legal']);

echo "Prorrateo\n";
igual('residuo mayor', [34, 33, 33], PricingEngine::prorratear(100, [1, 1, 1]));

echo "Montos en letras\n";
igual('120.50', 'CIENTO VEINTE LEMPIRAS CON 50/100', NumeroALetras::lempiras(12050));
igual('1.00', 'UN LEMPIRA CON 00/100', NumeroALetras::lempiras(100));
igual('100', 'CIEN LEMPIRAS CON 00/100', NumeroALetras::lempiras(10000));
igual('21,001', 'VEINTIÚN MIL UN LEMPIRAS CON 00/100', NumeroALetras::lempiras(2100100));
igual('1,000,000', 'UN MILLÓN DE LEMPIRAS CON 00/100', NumeroALetras::lempiras(100000000));
igual('1,575.99', 'MIL QUINIENTOS SETENTA Y CINCO LEMPIRAS CON 99/100', NumeroALetras::lempiras(157599));

echo "\n" . ($total - $fallos) . "/$total pruebas OK\n";
exit($fallos ? 1 : 0);
