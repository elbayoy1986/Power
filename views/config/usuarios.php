<?php
use App\Configuracion\UsuariosService;
use App\Support\Auth;

$servicio = new UsuariosService($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if (($_POST['accion'] ?? '') === 'clave') {
            $servicio->restablecerClave($id, (string) ($_POST['clave'] ?? ''));
            config_volver('usuarios', 'Contraseña restablecida. Entréguela a la persona y pídale que la cambie en "Mi cuenta".');
        }
        $servicio->guardar($_POST, $usuario['id']);
        config_volver('usuarios', $id ? 'Usuario actualizado.' : 'Usuario creado. Ya puede ingresar con su correo y la contraseña temporal.');
    } catch (DomainException $e) {
        $datos = $_POST;
        unset($datos['clave']); // nunca se devuelve una contraseña al formulario
        $_SESSION['form_usuario'] = $datos;
        config_volver('usuarios', $e->getMessage(), true, ($id ? '&editar=' . $id : '&nuevo=1') . '#form-usuario');
    }
}

$usuarios = $servicio->listar();
$porId = array_column($usuarios, null, 'id');
$editar = $porId[(int) ($_GET['editar'] ?? 0)] ?? null;
$f = $_SESSION['form_usuario'] ?? $editar ?? ['tienda_id' => (int) ($_GET['tienda'] ?? 0), 'rol' => 'CAJERO'];
unset($_SESSION['form_usuario']);
$mostrarForm = $editar || !empty($_GET['nuevo']) || isset($f['email']);
$tiendas = $db->query('SELECT id, nombre FROM tiendas WHERE activa = 1 ORDER BY codigo_establecimiento')->fetchAll();
$pillRol = ['ADMIN' => 'mal', 'GERENTE' => 'alerta', 'CAJERO' => 'ok'];

config_inicio('usuarios', $db);
?>
<div class="fila-entre">
  <h1>Usuarios y roles</h1>
  <a class="btn primario" href="index.php?p=configuracion&s=usuarios&nuevo=1#form-usuario">+ Nuevo usuario</a>
</div>

<?php if ($mostrarForm): ?>
<section class="tarjeta" id="form-usuario">
  <h2><?= $editar ? 'Editar ' . e($editar['nombre']) : 'Nuevo usuario' ?></h2>
  <div class="columnas">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>
      <label>Nombre completo <input name="nombre" required maxlength="100" value="<?= e($f['nombre'] ?? '') ?>" id="usr-nombre"></label>
      <label>Correo (usuario para ingresar) <input type="email" name="email" required maxlength="120" value="<?= e($f['email'] ?? '') ?>" id="usr-email" autocomplete="off"></label>
      <div class="fila">
        <label>Rol
          <select name="rol" id="usr-rol">
            <?php foreach (UsuariosService::ROLES as $k => $t): ?><option value="<?= $k ?>" <?= ($f['rol'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Sucursal
          <select name="tienda_id" id="usr-tienda">
            <option value="">Oficina central (todas)</option>
            <?php foreach ($tiendas as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) ($f['tienda_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <p class="ayuda" id="usr-ayuda"></p>
      <?php if (!$editar): ?>
        <label>Contraseña temporal <input type="text" name="clave" required minlength="<?= UsuariosService::LARGO_MINIMO_CLAVE ?>" id="usr-clave" autocomplete="off" placeholder="Mínimo 8 caracteres, con letras y números"></label>
      <?php else: ?>
        <label><input type="checkbox" name="activo" value="1" <?= !empty($f['activo']) ? 'checked' : '' ?> id="usr-activo"> Usuario activo (puede ingresar)</label>
      <?php endif; ?>
      <div class="acciones">
        <a class="btn texto" href="index.php?p=configuracion&s=usuarios">Cancelar</a>
        <button class="btn primario"><?= $editar ? 'Guardar cambios' : 'Crear usuario' ?></button>
      </div>
    </form>

    <?php if ($editar): ?>
      <form method="post">
        <h3>Restablecer contraseña</h3>
        <p class="tenue chico">Si la persona olvidó su contraseña, asigne una temporal. Ella la cambia después en "Mi cuenta".</p>
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="accion" value="clave"><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>">
        <label>Nueva contraseña temporal <input type="text" name="clave" required minlength="<?= UsuariosService::LARGO_MINIMO_CLAVE ?>" id="usr-clave-nueva" autocomplete="off"></label>
        <button class="btn">Restablecer</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<script>
  // Explica qué menú verá la persona según su rol y sucursal
  (() => {
    const rol = document.getElementById('usr-rol'), tienda = document.getElementById('usr-tienda'), ayuda = document.getElementById('usr-ayuda');
    const sync = () => {
      const central = tienda.value === '';
      if (rol.value === 'ADMIN') { tienda.value = ''; tienda.disabled = true; } else { tienda.disabled = false; }
      ayuda.textContent = rol.value === 'ADMIN' ? 'Administrador: menú administrativo completo, incluida Configuración, en todas las sucursales.'
        : rol.value === 'CAJERO' ? (central ? 'Un cajero debe pertenecer a una sucursal: elija una.' : 'Cajero: menú de tienda con Vender, Turno de caja y sus Facturas.')
        : central ? 'Gerente de oficina central: menú administrativo (ventas, catálogo y abastecimiento), sin Configuración.'
        : 'Gerente de tienda: menú de tienda más inventario, compras y reporte de su sucursal.';
    };
    rol.addEventListener('change', sync); tienda.addEventListener('change', sync); sync();
  })();
</script>
<?php endif; ?>

<div class="tabla-scroll"><table class="tabla">
  <thead><tr><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Menú</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($usuarios as $x): ?>
    <tr class="<?= $x['activo'] ? '' : 'inactivo' ?>">
      <td><b><?= e($x['nombre']) ?></b><?= (int) $x['id'] === $usuario['id'] ? ' <small class="tenue">(usted)</small>' : '' ?><br><small class="tenue"><?= e($x['email']) ?></small></td>
      <td><span class="pill <?= $pillRol[$x['rol']] ?>"><?= e(UsuariosService::ROLES[$x['rol']]) ?></span></td>
      <td><?= e($x['tienda'] ?? 'Oficina central') ?></td>
      <td><?= $x['tienda_id'] !== null ? 'De tienda' : 'Administrativo' ?></td>
      <td><?= $x['activo'] ? 'Activo' : 'Inactivo' ?><?= $x['turno_abierto'] ? ' <span class="pill ok">Turno abierto</span>' : '' ?></td>
      <td class="num"><a href="index.php?p=configuracion&s=usuarios&editar=<?= (int) $x['id'] ?>#form-usuario">Editar</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<section class="tarjeta" style="margin-top:1rem">
  <h2>Qué puede hacer cada rol</h2>
  <div class="tabla-scroll"><table class="tabla">
    <thead><tr><th>Función</th><th>Cajero</th><th>Gerente de tienda</th><th>Gerente central</th><th>Administrador</th></tr></thead>
    <tbody>
      <tr><td>Vender, turno de caja, mi cuenta</td><td>✔</td><td>✔</td><td>✔</td><td>✔</td></tr>
      <tr><td>Facturas</td><td>Las suyas</td><td>Su sucursal</td><td>Todas</td><td>Todas</td></tr>
      <tr><td>Cerrar turnos de otros cajeros</td><td>—</td><td>Su sucursal</td><td>✔</td><td>✔</td></tr>
      <tr><td>Inventario y compras</td><td>—</td><td>Su sucursal</td><td>✔</td><td>✔</td></tr>
      <tr><td>Reporte / tablero</td><td>—</td><td>Su sucursal</td><td>Todas</td><td>Todas</td></tr>
      <tr><td>Catálogo: productos, combos, promociones</td><td>—</td><td>—</td><td>✔</td><td>✔</td></tr>
      <tr><td>Configuración</td><td>—</td><td>—</td><td>—</td><td>✔</td></tr>
    </tbody>
  </table></div>
</section>
<?php layout_fin(); ?>
