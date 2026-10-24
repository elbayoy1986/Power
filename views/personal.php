<?php
// Personal: cajeros, meseros, cocina y gerencia. El gerente gestiona al personal operativo de su tienda.
use App\Support\Auth;
use App\Usuarios\UsuariosService;

$servicio = new UsuariosService($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['alternar'])) {
            $servicio->alternar($usuario, (int) $_POST['alternar']);
            redirigir('personal', 'Usuario actualizado.');
        }
        $servicio->guardar($usuario, $_POST);
        redirigir('personal', 'Usuario "' . trim($_POST['nombre'] ?? '') . '" guardado.' . (empty($_POST['id']) ? ' Ya puede ingresar con su correo y contraseña.' : ''));
    } catch (DomainException $e) {
        $_SESSION['form_usuario'] = array_diff_key($_POST, ['clave' => 1, 'csrf' => 1]);
        redirigir('personal' . (!empty($_POST['id']) ? '&editar=' . (int) $_POST['id'] : ''), $e->getMessage());
    }
}

$usuarios = $servicio->listar($usuario);
$editar = !empty($_GET['editar']) ? $servicio->obtener((int) $_GET['editar']) : null;
if ($editar && (int) $editar['id'] !== $usuario['id'] && !$servicio->puedeGestionar($usuario, $editar)) {
    $editar = null;
}
$f = $_SESSION['form_usuario'] ?? ($editar ?: ['rol' => 'MESERO', 'tienda_id' => Auth::tiendaId()]);
unset($_SESSION['form_usuario']);
$esUnoMismo = !empty($f['id']) && (int) $f['id'] === $usuario['id'];
$roles = $servicio->rolesAsignables($usuario);
$tiendas = $db->query('SELECT id, nombre FROM tiendas WHERE activa = 1 ORDER BY id')->fetchAll();
$tiendaFija = $usuario['rol'] === 'GERENTE' && $usuario['tienda_id'] !== null;

require __DIR__ . '/_layout.php';
layout_inicio('Personal', 'personal', $db);
?>
<h1>Personal</h1>
<div class="columnas">
  <section class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Nombre</th><th>Rol</th><th>Tienda</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr class="<?= $u['activo'] ? '' : 'inactivo' ?>">
          <td><b><?= e($u['nombre']) ?></b><br><small class="tenue"><?= e($u['email']) ?></small></td>
          <td><?= e(UsuariosService::ROLES[$u['rol']]) ?></td>
          <td><?= e($u['tienda'] ?? 'Todas') ?></td>
          <td class="num">
            <a href="index.php?p=personal&editar=<?= (int) $u['id'] ?>">Editar</a>
            <?php if ((int) $u['id'] !== $usuario['id']): ?>
              <form method="post" class="en-linea"><input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                <button class="btn texto" name="alternar" value="<?= (int) $u['id'] ?>"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="tarjeta" id="form-usuario">
    <h2><?= !empty($f['id']) ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <?php if (!empty($f['id'])): ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><?php endif; ?>
      <label>Nombre <input name="nombre" required maxlength="100" value="<?= e($f['nombre'] ?? '') ?>" placeholder="Ana López" id="usr-nombre"></label>
      <label>Correo (usuario para ingresar) <input type="email" name="email" required maxlength="120" value="<?= e($f['email'] ?? '') ?>" placeholder="ana@power.hn" id="usr-email" autocomplete="off"></label>
      <div class="fila">
        <label>Rol
          <select name="rol" id="usr-rol" <?= $esUnoMismo ? 'disabled' : '' ?>>
            <?php foreach ($roles as $r): ?><option value="<?= $r ?>" <?= ($f['rol'] ?? '') === $r ? 'selected' : '' ?>><?= e(UsuariosService::ROLES[$r]) ?></option><?php endforeach; ?>
            <?php if ($esUnoMismo && !in_array($f['rol'], $roles, true)): ?><option selected><?= e(UsuariosService::ROLES[$f['rol']]) ?></option><?php endif; ?>
          </select>
        </label>
        <label>Tienda
          <select name="tienda_id" id="usr-tienda" <?= $esUnoMismo || $tiendaFija ? 'disabled' : '' ?>>
            <?php if (!$tiendaFija): ?><option value="">Todas (solo administración y gerencia)</option><?php endif; ?>
            <?php foreach ($tiendas as $t): ?>
              <?php if (!$tiendaFija || (int) $t['id'] === $usuario['tienda_id']): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (string) ($f['tienda_id'] ?? '') === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <?php if ($esUnoMismo): ?><p class="tenue chico">No puede cambiar su propio rol ni su tienda.</p><?php endif; ?>
      <label>Contraseña <input type="password" name="clave" minlength="8" <?= empty($f['id']) ? 'required' : '' ?> autocomplete="new-password"
        placeholder="<?= empty($f['id']) ? 'Mínimo 8 caracteres' : 'Déjela vacía para no cambiarla' ?>" id="usr-clave"></label>
      <p class="tenue chico"><b>Mesero</b>: abre mesas, toma pedidos y los envía a cocina; no cobra. <b>Cocina</b>: solo ve la pantalla de comandas.
        <b>Cajero</b>: vende en mostrador y cobra mesas.</p>
      <div class="fila">
        <?php if (!empty($f['id'])): ?><a class="btn texto" href="index.php?p=personal">Cancelar</a><?php endif; ?>
        <button class="btn primario">Guardar usuario</button>
      </div>
    </form>
  </section>
</div>
<?php layout_fin(); ?>
