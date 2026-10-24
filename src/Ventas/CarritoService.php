<?php
declare(strict_types=1);

namespace App\Ventas;

use App\Catalogo\CatalogoRepository;
use App\Pricing\PricingEngine;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Convierte el carrito enviado por el navegador (solo IDs y cantidades) en líneas
 * con precios del servidor y lo pasa por el PricingEngine.
 *
 * Formato de entrada:
 *   items: [{tipo:'PRODUCTO', producto_id, cantidad, aplica_legal},
 *           {tipo:'COMBO', combo_id, cantidad, opciones:[producto_id...], aplica_legal}]
 */
final class CarritoService
{
    public function __construct(private CatalogoRepository $catalogo, private PricingEngine $motor = new PricingEngine())
    {
    }

    public function cotizar(int $tiendaId, array $items, ?int $descuentoLegalId, ?DateTimeImmutable $ahora = null): array
    {
        $lineas = $this->construirLineas($tiendaId, $items);
        $promos = $this->catalogo->promocionesVigentes($tiendaId, $ahora ?? new DateTimeImmutable());
        $legal = $this->catalogo->reglaLegal($descuentoLegalId);
        return $this->motor->calcular($lineas, $promos, $legal) + ['regla_legal' => $legal];
    }

    public function construirLineas(int $tiendaId, array $items): array
    {
        $productos = array_column($this->catalogo->productos($tiendaId, false), null, 'id');
        $combos = array_column($this->catalogo->combos(), null, 'id');
        $lineas = [];

        foreach ($items as $it) {
            $cantidad = (int) ($it['cantidad'] ?? 0);
            if ($cantidad < 1 || $cantidad > 99) {
                throw new InvalidArgumentException('Cantidad inválida.');
            }
            $aplicaLegal = (bool) ($it['aplica_legal'] ?? true);

            if (($it['tipo'] ?? '') === 'PRODUCTO') {
                $p = $productos[(int) ($it['producto_id'] ?? 0)] ?? null;
                if (!$p) {
                    throw new InvalidArgumentException('Producto no disponible.');
                }
                $lineas[] = [
                    'tipo' => 'PRODUCTO', 'ref_id' => (int) $p['id'], 'categoria_id' => (int) $p['categoria_id'],
                    'descripcion' => $p['nombre'], 'cantidad' => $cantidad, 'precio_unitario' => $p['precio'],
                    'tipo_impuesto' => $p['tipo_impuesto'], 'aplica_legal' => $aplicaLegal, 'opciones' => [],
                ];
                continue;
            }

            $c = $combos[(int) ($it['combo_id'] ?? 0)] ?? null;
            if (!$c) {
                throw new InvalidArgumentException('Combo no disponible.');
            }
            [$opciones, $recargo] = $this->validarOpciones($c, array_map('intval', $it['opciones'] ?? []));
            $lineas[] = [
                'tipo' => 'COMBO', 'ref_id' => (int) $c['id'], 'categoria_id' => (int) $c['categoria_id'],
                'descripcion' => $c['nombre'], 'cantidad' => $cantidad, 'precio_unitario' => $c['precio'] + $recargo,
                'tipo_impuesto' => $c['tipo_impuesto'], 'aplica_legal' => $aplicaLegal, 'opciones' => $opciones,
            ];
        }
        return $lineas;
    }

    /**
     * Verifica que cada grupo del combo tenga entre min y max selecciones válidas.
     * Las selecciones se consumen en el orden de los grupos; un mismo producto puede
     * elegirse varias veces (ej. "2 refrescos medianos" en el Combo Pareja).
     */
    private function validarOpciones(array $combo, array $seleccion): array
    {
        $elegidas = [];
        $recargo = 0;
        $pendientes = $seleccion;
        foreach ($combo['grupos'] as $g) {
            $permitidas = array_column($g['opciones'], null, 'producto_id');
            $enGrupo = 0;
            foreach ($pendientes as $k => $pid) {
                if ($enGrupo >= $g['max']) {
                    break;
                }
                if (isset($permitidas[$pid])) {
                    $o = $permitidas[$pid];
                    $elegidas[] = ['producto_id' => $pid, 'nombre' => $o['nombre'], 'descripcion' => $g['nombre'] . ': ' . $o['nombre'], 'recargo' => $o['recargo']];
                    $recargo += $o['recargo'];
                    unset($pendientes[$k]);
                    $enGrupo++;
                }
            }
            if ($enGrupo < $g['min']) {
                throw new InvalidArgumentException("Seleccione {$g['min']} opción(es) en \"{$g['nombre']}\" del {$combo['nombre']}.");
            }
        }
        if ($pendientes) {
            throw new InvalidArgumentException("Hay opciones que no pertenecen al {$combo['nombre']}.");
        }
        return [$elegidas, $recargo];
    }
}
