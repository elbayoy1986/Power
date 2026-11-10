<?php
use App\Caja\CajaService;
use App\Support\Auth;
use App\Support\Parametros;

/**
 * Formulario de apertura de turno (se usa en Vender y en Turno de caja).
 * Si la sucursal tiene varias cajas (puntos de emisión con CAI vigente), el cajero elige la suya.
 */
function apertura_form(CajaService $caja, int $tiendaId, string $volver): void
{
    $puntos = $caja->puntosEmision($tiendaId);
    ?>
    <form method="post" action="index.php?p=caja" class="fila">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <input type="hidden" name="accion" value="abrir">
      <input type="hidden" name="volver" value="<?= e($volver) ?>">
      <label>Fondo inicial (L)
        <input name="fondo" inputmode="decimal" required value="<?= e(Parametros::texto('fondo_caja_sugerido')) ?>" id="fondo" autofocus>
      </label>
      <?php if (count($puntos) > 1): ?>
        <label>Caja (punto de emisión)
          <select name="punto_emision" required id="punto-emision">
            <option value="">Elija su caja…</option>
            <?php foreach ($puntos as $pe): ?>
              <option value="<?= e($pe['punto_emision']) ?>">Caja <?= e($pe['punto_emision']) ?> · <?= number_format((int) $pe['disponibles']) ?> facturas disponibles</option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <button class="btn primario">Abrir turno</button>
    </form>
    <?php if (!$puntos): ?>
      <p class="aviso error">Esta sucursal no tiene un CAI vigente: puede abrir turno, pero no podrá facturar. Avise a un administrador.</p>
    <?php endif;
}
