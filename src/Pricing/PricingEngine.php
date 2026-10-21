<?php
declare(strict_types=1);

namespace App\Pricing;

/**
 * Motor de precios del POS. Es la ÚNICA fuente de verdad para montos:
 * el navegador solo envía qué se pidió, nunca precios.
 *
 * Todo se calcula en centavos (int) para evitar errores de punto flotante.
 * Los precios de catálogo INCLUYEN ISV (práctica habitual en Honduras);
 * la base imponible se obtiene dividiendo entre (1 + tasa).
 *
 * Orden de aplicación:
 *   1. Promociones (mix & match, NxM, porcentaje, compra X lleva Y) por prioridad.
 *      Cada unidad participa como máximo en una promoción.
 *   2. Descuento de ley (tercera edad, cuarta edad, discapacidad...) sobre las
 *      líneas marcadas como consumo del beneficiario, con tope opcional.
 *      Si la regla NO es acumulable, el cliente recibe el mayor de los dos
 *      beneficios (promo o ley), nunca la suma.
 *   3. Desglose fiscal SAR: gravado 15%, gravado 18%, exento, ISV.
 */
final class PricingEngine
{
    public const TASAS = ['G15' => 0.15, 'G18' => 0.18, 'EXE' => 0.0];

    /**
     * @param array<int,array{
     *   descripcion:string, cantidad:int, precio_unitario:int, tipo_impuesto:string,
     *   tipo:string, ref_id:int, categoria_id?:int|null, aplica_legal?:bool
     * }> $lineas
     * @param array<int,array{
     *   id:int, nombre:string, tipo:string, prioridad?:int,
     *   cantidad_n?:int, cantidad_m?:int, precio?:int, porcentaje?:float,
     *   items_a:array<int,array{producto_id?:?int,combo_id?:?int,categoria_id?:?int}>,
     *   items_b?:array<int,array{producto_id?:?int,combo_id?:?int,categoria_id?:?int}>
     * }> $promociones  Promociones ya filtradas por vigencia, día, hora y tienda.
     * @param array{id:int,nombre:string,porcentaje:float,tope:int,acumulable:bool,exento_isv:bool}|null $legal
     */
    public function calcular(array $lineas, array $promociones = [], ?array $legal = null): array
    {
        $unidades = [];
        foreach (array_values($lineas) as $i => $l) {
            $cantidad = max(0, (int) $l['cantidad']);
            for ($q = 0; $q < $cantidad; $q++) {
                $unidades[] = [
                    'linea' => $i,
                    'precio' => (int) $l['precio_unitario'],
                    'promo' => 0,
                    'legal' => 0,
                    'usada' => false,
                ];
            }
        }
        $lineas = array_values($lineas);
        $aplicadas = [];

        usort($promociones, fn ($a, $b) => ($a['prioridad'] ?? 100) <=> ($b['prioridad'] ?? 100));
        foreach ($promociones as $promo) {
            $desc = $this->aplicarPromocion($promo, $lineas, $unidades);
            if ($desc > 0) {
                $aplicadas[] = ['id' => $promo['id'], 'nombre' => $promo['nombre'], 'descuento' => $desc];
            }
        }

        if ($legal !== null) {
            $this->aplicarLegal($legal, $lineas, $unidades);
        }

        return $this->totalizar($lineas, $unidades, $aplicadas, $legal);
    }

    private function aplicarPromocion(array $p, array $lineas, array &$u): int
    {
        $elegibles = fn (array $items) => array_keys(array_filter(
            $u,
            fn ($x) => !$x['usada'] && self::coincide($lineas[$x['linea']], $items)
        ));
        $porPrecioDesc = function (array $idx) use (&$u): array {
            usort($idx, fn ($a, $b) => $u[$b]['precio'] <=> $u[$a]['precio']);
            return $idx;
        };
        $total = 0;

        switch ($p['tipo']) {
            // "Cualquier N artículos del grupo por L X"
            case 'MIX_PRECIO':
                $n = max(1, (int) $p['cantidad_n']);
                $idx = $porPrecioDesc($elegibles($p['items_a']));
                while (count($idx) >= $n) {
                    $grupo = array_splice($idx, 0, $n);
                    $suma = array_sum(array_map(fn ($k) => $u[$k]['precio'], $grupo));
                    $ahorro = $suma - (int) $p['precio'];
                    if ($ahorro <= 0) {
                        break; // ordenado desc: los grupos siguientes suman menos
                    }
                    $this->repartir($u, $grupo, $ahorro);
                    $total += $ahorro;
                }
                break;

            // "Lleva N paga M" (2x1, 3x2...): en cada grupo, las más baratas van gratis
            case 'NXM':
                $n = max(1, (int) $p['cantidad_n']);
                $m = max(0, min($n, (int) $p['cantidad_m']));
                $idx = $porPrecioDesc($elegibles($p['items_a']));
                while (count($idx) >= $n) {
                    $grupo = array_splice($idx, 0, $n);
                    $precios = array_map(fn ($k) => $u[$k]['precio'], $grupo);
                    $ahorro = array_sum(array_slice($precios, $m));
                    $this->repartir($u, $grupo, $ahorro);
                    $total += $ahorro;
                }
                break;

            // "X% en todos los artículos del grupo"
            case 'PORCENTAJE':
                foreach ($elegibles($p['items_a']) as $k) {
                    $d = (int) round($u[$k]['precio'] * (float) $p['porcentaje'] / 100);
                    $u[$k]['promo'] = $d;
                    $u[$k]['usada'] = true;
                    $total += $d;
                }
                break;

            // "Compra un artículo del grupo A y lleva uno del grupo B con X% (100 = gratis)"
            // El descuento se aplica al artículo B de menor precio.
            case 'X_LLEVA_Y':
                $disparadores = $porPrecioDesc($elegibles($p['items_a']));
                $premios = array_reverse($porPrecioDesc($elegibles($p['items_b'] ?? [])));
                foreach ($disparadores as $a) {
                    if ($u[$a]['usada']) {
                        continue;
                    }
                    $b = null;
                    foreach ($premios as $cand) {
                        if ($cand !== $a && !$u[$cand]['usada']) {
                            $b = $cand;
                            break;
                        }
                    }
                    if ($b === null) {
                        break;
                    }
                    $d = (int) round($u[$b]['precio'] * (float) $p['porcentaje'] / 100);
                    $this->repartir($u, [$a, $b], $d);
                    $total += $d;
                }
                break;
        }
        return $total;
    }

    /**
     * El ahorro de una promoción se reparte entre TODAS las unidades del grupo
     * (proporcional al precio): así el desglose por línea es fiel y la regla de
     * "no acumulable" compara contra el beneficio real que recibió cada unidad.
     */
    private function repartir(array &$u, array $grupo, int $ahorro): void
    {
        $partes = self::prorratear($ahorro, array_map(fn ($k) => $u[$k]['precio'], $grupo));
        foreach (array_values($grupo) as $j => $k) {
            $u[$k]['promo'] = $partes[$j];
            $u[$k]['usada'] = true;
        }
    }

    private function aplicarLegal(array $r, array $lineas, array &$u): void
    {
        $restante = $r['tope'] > 0 ? (int) $r['tope'] : PHP_INT_MAX;
        foreach ($u as $k => $x) {
            if ($restante <= 0) {
                break;
            }
            if (!($lineas[$x['linea']]['aplica_legal'] ?? true)) {
                continue;
            }
            $neto = $x['precio'] - $x['promo'];
            $base = min($r['acumulable'] ? $neto : $x['precio'], $restante);
            $restante -= $base;
            $d = (int) round($base * (float) $r['porcentaje'] / 100);
            if (!$r['acumulable']) {
                $d = max(0, $d - $x['promo']); // el mayor de los dos beneficios
            }
            $u[$k]['legal'] = min($d, $neto);
        }
    }

    private function totalizar(array $lineas, array $u, array $aplicadas, ?array $legal): array
    {
        $exentoLegal = $legal !== null && !empty($legal['exento_isv']);
        $res = [];
        $s = [
            'subtotal' => 0, 'descuento_promos' => 0, 'descuento_legal' => 0,
            'importe_exento' => 0, 'importe_exonerado' => 0,
            'importe_gravado_15' => 0, 'importe_gravado_18' => 0,
            'isv_15' => 0, 'isv_18' => 0, 'total' => 0,
        ];

        foreach ($lineas as $i => $l) {
            $mias = array_filter($u, fn ($x) => $x['linea'] === $i);
            $bruto = array_sum(array_column($mias, 'precio'));
            $promo = array_sum(array_column($mias, 'promo'));
            $leg = array_sum(array_column($mias, 'legal'));
            $neto = $bruto - $promo - $leg;
            $tipo = $l['tipo_impuesto'];

            // Beneficiario exento de ISV: paga solo la base (sin impuesto)
            if ($exentoLegal && ($l['aplica_legal'] ?? true) && $tipo !== 'EXE') {
                $neto = (int) round($neto / (1 + self::TASAS[$tipo]));
                $tipo = 'EXE';
            }

            $base = $tipo === 'EXE' ? $neto : (int) round($neto / (1 + self::TASAS[$tipo]));
            $isv = $neto - $base;

            $s['subtotal'] += $bruto;
            $s['descuento_promos'] += $promo;
            $s['descuento_legal'] += $leg;
            $s['total'] += $neto;
            if ($tipo === 'G15') {
                $s['importe_gravado_15'] += $base;
                $s['isv_15'] += $isv;
            } elseif ($tipo === 'G18') {
                $s['importe_gravado_18'] += $base;
                $s['isv_18'] += $isv;
            } else {
                $s['importe_exento'] += $base;
            }

            $res[] = $l + [
                'bruto' => $bruto, 'descuento_promo' => $promo, 'descuento_legal' => $leg,
                'neto' => $neto, 'impuesto_final' => $tipo, 'base' => $base, 'isv' => $isv,
            ];
        }

        return ['lineas' => $res, 'resumen' => $s, 'promociones_aplicadas' => $aplicadas];
    }

    /** Coincidencia de una línea contra la lista de artículos de una promoción. */
    public static function coincide(array $linea, array $items): bool
    {
        foreach ($items as $it) {
            if (!empty($it['producto_id']) && $linea['tipo'] === 'PRODUCTO' && (int) $it['producto_id'] === (int) $linea['ref_id']) {
                return true;
            }
            if (!empty($it['combo_id']) && $linea['tipo'] === 'COMBO' && (int) $it['combo_id'] === (int) $linea['ref_id']) {
                return true;
            }
            if (!empty($it['categoria_id']) && (int) $it['categoria_id'] === (int) ($linea['categoria_id'] ?? 0)) {
                return true;
            }
        }
        return false;
    }

    /** Reparte $monto proporcional a $pesos (método del residuo mayor); la suma es exacta. */
    public static function prorratear(int $monto, array $pesos): array
    {
        $totalPeso = array_sum($pesos);
        if ($totalPeso <= 0) {
            return array_fill(0, count($pesos), 0);
        }
        $partes = [];
        $residuos = [];
        foreach ($pesos as $i => $p) {
            $exacto = $monto * $p / $totalPeso;
            $partes[$i] = (int) floor($exacto);
            $residuos[$i] = $exacto - $partes[$i];
        }
        arsort($residuos);
        $faltan = $monto - array_sum($partes);
        foreach (array_keys($residuos) as $i) {
            if ($faltan-- <= 0) {
                break;
            }
            $partes[$i]++;
        }
        return $partes;
    }
}
