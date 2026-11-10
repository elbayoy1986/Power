<?php
use App\Configuracion\TiendasService;
use App\Support\Auth;

$servicio = new TiendasService($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['alternar'])) {
            $activa = $servicio->alternarActiva((int) $_POST['alternar']);
            config_volver('sucursales', $activa ? 'Sucursal activada.' : 'Sucursal desactivada: sus usuarios ya no pueden ingresar.');
        }
        $nueva = empty($_POST['id']);
        $id = $servicio->guardar($_POST);
        config_volver('sucursales', $nueva
            ? 'Sucursal "' . trim($_POST['nombre']) . '" creada. Siguiente paso: registre su CAI y cree sus usuarios.'
            : 'Sucursal actualizada.');
    } catch (DomainException $e) {
        $_SESSION['form_sucursal'] = $_POST;
        config_volver('sucursales', $e->getMessage(), true, !empty($_POST['id']) ? '&editar=' . (int) $_POST['id'] : '&nueva=1');
    }
}

$tiendas = $servicio->listar();
$porId = array_column($tiendas, null, 'id');
$editar = $porId[(int) ($_GET['editar'] ?? 0)] ?? null;
$f = $_SESSION['form_sucursal'] ?? $editar ?? [];
unset($_SESSION['form_sucursal']);
$mostrarForm = $editar || !empty($_GET['nueva']) || !empty($f);
// Sugerir el siguiente código de establecimiento libre
$usados = array_map('intval', array_column($tiendas, 'codigo_establecimiento'));
$sugerido = sprintf('%03d', $usados ? max($usados) + 1 : 1);

config_inicio('sucursales', $db);
?>
<div class="fila-entre">
  <h1>Sucursales</h1>
  <a class="btn primario" href="index.php?p=configuracion&s=sucursales&nueva=1#form-sucursal">+ Nueva sucursal</a>
</div>
<p class="tenue">Cada sucursal es un <b>establecimiento</b> ante la SAR: su código de 3 dígitos es la primera parte del número de factura.</p>

<?php if ($mostrarForm): ?>
<section class="tarjeta" id="form-sucursal">
  <h2><?= $editar ? 'Editar ' . e($editar['nombre']) : 'Nueva sucursal' ?></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>
    <div class="fila">
      <label>Nombre <input name="nombre" required maxlength="100" value="<?= e($f['nombre'] ?? '') ?>" placeholder="Power Choluteca" id="suc-nombre"></label>
      <label>Código interno <input name="codigo" required maxlength="10" value="<?= e($f['codigo'] ?? '') ?>" placeholder="CHO-01" class="mono" id="suc-codigo"></label>
      <label>Establecimiento SAR <input name="codigo_establecimiento" required maxlength="3" inputmode="numeric" pattern="\d{3}"
        value="<?= e($f['codigo_establecimiento'] ?? $sugerido) ?>" class="mono" id="suc-est"></label>
    </div>
    <p class="ayuda">El establecimiento debe coincidir con la resolución de la SAR. No se puede cambiar después de emitir la primera factura.</p>
    <label>Dirección (sale en la factura) <input name="direccion" required maxlength="255" value="<?= e($f['direccion'] ?? '') ?>" placeholder="Blvd. Enrique Weddle, Choluteca" id="suc-dir"></label>
    <div class="fila">
      <label>Teléfono <input name="telefono" maxlength="30" value="<?= e($f['telefono'] ?? '') ?>" placeholder="+504 2782-0001" id="suc-tel"></label>
      <?php if (!$editar): ?>
        <label>Copiar mínimos de inventario y precios especiales de
          <select name="copiar_de" id="suc-copiar">
            <option value="">— No copiar —</option>
            <?php foreach ($tiendas as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) ($f['copiar_de'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    </div>
    <div class="acciones">
      <a class="btn texto" href="index.php?p=configuracion&s=sucursales">Cancelar</a>
      <button class="btn primario"><?= $editar ? 'Guardar cambios' : 'Crear sucursal' ?></button>
    </div>
  </form>
</section>
<?php endif; ?>

<div class="columnas">
  <?php foreach ($tiendas as $t):
      $lista = $t['cai_vigentes'] > 0 && $t['usuarios'] > 0; ?>
    <article class="tarjeta sucursal <?= $t['activa'] ? '' : 'inactivo' ?>" id="suc-<?= (int) $t['id'] ?>">
      <div class="fila-entre">
        <h3><span class="cod"><?= e($t['codigo_establecimiento']) ?></span> <?= e($t['nombre']) ?></h3>
        <span class="pill <?= !$t['activa'] ? '' : ($lista ? 'ok' : 'alerta') ?>"><?= !$t['activa'] ? 'Inactiva' : ($lista ? 'Lista para vender' : 'Falta configurar') ?></span>
      </div>
      <p class="chico tenue"><span class="mono"><?= e($t['codigo']) ?></span> · <?= e($t['direccion']) ?><?= $t['telefono'] ? ' · ' . e($t['telefono']) : '' ?></p>
      <div class="estado">
        <?php if ($t['cai_vigentes'] > 0): ?>
          <span class="pill ok"><?= (int) $t['cai_vigentes'] ?> CAI vigente(s)</span>
        <?php else: ?>
          <a class="pill mal" href="index.php?p=configuracion&s=cai&tienda=<?= (int) $t['id'] ?>#form-cai">Sin CAI vigente · registrar</a>
        <?php endif; ?>
        <?php if ($t['usuarios'] > 0): ?>
          <span class="pill"><?= (int) $t['usuarios'] ?> usuario(s)</span>
        <?php else: ?>
          <a class="pill alerta" href="index.php?p=configuracion&s=usuarios&nuevo=1&tienda=<?= (int) $t['id'] ?>#form-usuario">Sin usuarios · crear</a>
        <?php endif; ?>
        <?php if ($t['turnos_abiertos'] > 0): ?><span class="pill ok"><?= (int) $t['turnos_abiertos'] ?> turno(s) abierto(s)</span><?php endif; ?>
      </div>
      <div class="acciones">
        <a class="btn" href="index.php?p=configuracion&s=sucursales&editar=<?= (int) $t['id'] ?>#form-sucursal">Editar</a>
        <a class="btn" href="index.php?p=configuracion&s=cai&tienda=<?= (int) $t['id'] ?>">CAI</a>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
          <button class="btn texto" name="alternar" value="<?= (int) $t['id'] ?>"><?= $t['activa'] ? 'Desactivar' : 'Activar' ?></button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php layout_fin(); ?>
