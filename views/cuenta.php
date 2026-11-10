<?php
use App\Configuracion\UsuariosService;
use App\Support\Auth;
use App\Support\Menu;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new UsuariosService($db))->cambiarPropiaClave(
            $usuario['id'], (string) ($_POST['actual'] ?? ''), (string) ($_POST['nueva'] ?? ''), (string) ($_POST['confirmar'] ?? '')
        );
        redirigir('cuenta', 'Contraseña cambiada. Úsela la próxima vez que ingrese.');
    } catch (DomainException $e) {
        redirigir('cuenta', $e->getMessage(), true);
    }
}

$st = $db->prepare('SELECT u.email, t.nombre AS tienda FROM usuarios u LEFT JOIN tiendas t ON t.id = u.tienda_id WHERE u.id = ?');
$st->execute([$usuario['id']]);
$yo = $st->fetch();

require __DIR__ . '/_layout.php';
layout_inicio('Mi cuenta', 'cuenta', $db);
?>
<h1>Mi cuenta</h1>
<div class="columnas">
  <section class="tarjeta">
    <h2><?= e($usuario['nombre']) ?></h2>
    <dl class="totales">
      <dt>Correo</dt><dd><?= e($yo['email']) ?></dd>
      <dt>Rol</dt><dd><?= e(UsuariosService::ROLES[$usuario['rol']]) ?></dd>
      <dt>Sucursal</dt><dd><?= e($yo['tienda'] ?? 'Oficina central') ?></dd>
      <dt>Menú</dt><dd><?= Menu::esDeTienda($usuario) ? 'De tienda' : 'Administrativo' ?></dd>
    </dl>
    <p class="tenue chico">Para cambiar su nombre, rol o sucursal, pídalo a un administrador.</p>
  </section>
  <section class="tarjeta">
    <h2>Cambiar contraseña</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <label>Contraseña actual <input type="password" name="actual" required autocomplete="current-password" id="cta-actual"></label>
      <label>Nueva contraseña <input type="password" name="nueva" required minlength="<?= UsuariosService::LARGO_MINIMO_CLAVE ?>" autocomplete="new-password" id="cta-nueva"></label>
      <label>Repita la nueva contraseña <input type="password" name="confirmar" required minlength="<?= UsuariosService::LARGO_MINIMO_CLAVE ?>" autocomplete="new-password" id="cta-confirmar"></label>
      <p class="ayuda">Mínimo <?= UsuariosService::LARGO_MINIMO_CLAVE ?> caracteres, con letras y números. No comparta su usuario: cada factura y cada turno quedan a su nombre.</p>
      <button class="btn primario">Cambiar contraseña</button>
    </form>
  </section>
</div>
<?php layout_fin(); ?>
