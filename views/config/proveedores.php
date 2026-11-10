<?php
use App\Support\Auth;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['alternar'])) {
        $db->prepare('UPDATE proveedores SET activo = 1 - activo WHERE id = ?')->execute([(int) $_POST['alternar']]);
        config_volver('proveedores', 'Estado del proveedor actualizado.');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $rtn = preg_replace('/\D/', '', (string) ($_POST['rtn'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $volver = function (string $msg) use ($id): never {
        $_SESSION['form_proveedor'] = $_POST;
        config_volver('proveedores', $msg, true, ($id ? '&editar=' . $id : '') . '#form-proveedor');
    };
    if ($nombre === '') {
        $volver('Escriba el nombre del proveedor.');
    }
    if ($rtn !== '' && strlen($rtn) !== 14) {
        $volver('El RTN del proveedor debe tener 14 dígitos (o déjelo vacío).');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $volver('Correo inválido.');
    }
    if ($rtn !== '') {
        $st = $db->prepare('SELECT nombre FROM proveedores WHERE rtn = ? AND id <> ?');
        $st->execute([$rtn, $id]);
        if ($otro = $st->fetchColumn()) {
            $volver("Ese RTN ya pertenece al proveedor $otro.");
        }
    }
    $datos = [$rtn ?: null, $nombre, trim((string) ($_POST['telefono'] ?? '')) ?: null, $email ?: null];
    if ($id) {
        $db->prepare('UPDATE proveedores SET rtn = ?, nombre = ?, telefono = ?, email = ? WHERE id = ?')->execute([...$datos, $id]);
        config_volver('proveedores', 'Proveedor actualizado.');
    }
    $db->prepare('INSERT INTO proveedores (rtn, nombre, telefono, email) VALUES (?,?,?,?)')->execute($datos);
    config_volver('proveedores', "Proveedor \"$nombre\" agregado.");
}

$proveedores = $db->query(
    "SELECT p.*, (SELECT COUNT(*) FROM compras c WHERE c.proveedor_id = p.id AND c.estado = 'RECIBIDA') AS compras,
            (SELECT COALESCE(SUM(c.total), 0) FROM compras c WHERE c.proveedor_id = p.id AND c.estado = 'RECIBIDA') AS comprado
     FROM proveedores p ORDER BY p.activo DESC, p.nombre"
)->fetchAll();
$porId = array_column($proveedores, null, 'id');
$editar = $porId[(int) ($_GET['editar'] ?? 0)] ?? null;
$f = $_SESSION['form_proveedor'] ?? $editar ?? [];
unset($_SESSION['form_proveedor']);

config_inicio('proveedores', $db);
?>
<h1>Proveedores</h1>
<div class="columnas">
  <section>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>Proveedor</th><th>Contacto</th><th class="num">Compras</th><th class="num">Comprado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($proveedores as $p): ?>
        <tr class="<?= $p['activo'] ? '' : 'inactivo' ?>">
          <td><b><?= e($p['nombre']) ?></b><br><small class="tenue mono">RTN <?= e($p['rtn'] ?: '—') ?></small></td>
          <td class="chico"><?= e($p['telefono'] ?? '') ?><br><?= e($p['email'] ?? '') ?></td>
          <td class="num"><?= (int) $p['compras'] ?></td>
          <td class="num"><?= lps($p['comprado']) ?></td>
          <td class="num acciones">
            <a href="index.php?p=configuracion&s=proveedores&editar=<?= (int) $p['id'] ?>#form-proveedor">Editar</a>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
              <button class="btn texto" name="alternar" value="<?= (int) $p['id'] ?>"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$proveedores): ?><tr><td colspan="5" class="tenue">Aún no hay proveedores.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <p class="tenue chico">Un proveedor inactivo deja de aparecer al registrar compras; sus compras anteriores se conservan.</p>
  </section>
  <section class="tarjeta" id="form-proveedor">
    <h2><?= $editar ? 'Editar proveedor' : 'Nuevo proveedor' ?></h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>
      <label>Nombre <input name="nombre" required maxlength="150" value="<?= e($f['nombre'] ?? '') ?>" id="prov-nombre"></label>
      <div class="fila">
        <label>RTN <input name="rtn" maxlength="16" inputmode="numeric" class="mono" value="<?= e($f['rtn'] ?? '') ?>" id="prov-rtn"></label>
        <label>Teléfono <input name="telefono" maxlength="30" value="<?= e($f['telefono'] ?? '') ?>" id="prov-tel"></label>
      </div>
      <label>Correo <input type="email" name="email" maxlength="120" value="<?= e($f['email'] ?? '') ?>" id="prov-email"></label>
      <div class="acciones">
        <?php if ($editar): ?><a class="btn texto" href="index.php?p=configuracion&s=proveedores">Cancelar</a><?php endif; ?>
        <button class="btn primario"><?= $editar ? 'Guardar cambios' : 'Agregar proveedor' ?></button>
      </div>
    </form>
  </section>
</div>
<?php layout_fin(); ?>
