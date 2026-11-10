<?php
use App\Support\Auth;
use App\Support\Parametros;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Parametros::guardar($_POST['param'] ?? []);
        config_volver('parametros', 'Parámetros guardados. Aplican de inmediato en todas las sucursales.');
    } catch (DomainException $e) {
        config_volver('parametros', $e->getMessage(), true);
    }
}

$valores = Parametros::todos();
$ayuda = [
    'cai_alerta_facturas' => 'El tablero y la pantalla de CAI avisan cuando a un rango le quedan menos números que este valor.',
    'cai_alerta_dias' => 'Días de anticipación para avisar que un CAI está por vencer. Pedir el nuevo a la SAR toma tiempo.',
    'fondo_caja_sugerido' => 'Se propone al cajero al abrir turno; puede cambiarlo si recibe otro monto.',
    'mensaje_factura' => 'Última línea de la factura impresa.',
];

config_inicio('parametros', $db);
?>
<h1>Parámetros generales</h1>
<section class="tarjeta" style="max-width:640px">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <?php foreach (Parametros::DEFINICION as $clave => [$etiqueta, $tipo]): ?>
      <label><?= e($etiqueta) ?>
        <input name="param[<?= $clave ?>]" id="par-<?= $clave ?>" value="<?= e($valores[$clave]) ?>" required
          <?= $tipo === 'entero' ? 'inputmode="numeric" pattern="\d{1,6}"' : ($tipo === 'dinero' ? 'inputmode="decimal"' : 'maxlength="255"') ?>>
      </label>
      <p class="ayuda"><?= e($ayuda[$clave] ?? '') ?></p>
    <?php endforeach; ?>
    <button class="btn primario">Guardar parámetros</button>
  </form>
</section>
<?php layout_fin(); ?>
