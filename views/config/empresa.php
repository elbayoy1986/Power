<?php
use App\Support\Auth;
use App\Support\Parametros;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = array_map(fn ($v) => trim((string) $v), $_POST);
    $rtn = preg_replace('/\D/', '', $d['rtn'] ?? '');
    $error = match (true) {
        ($d['razon_social'] ?? '') === '' => 'Escriba la razón social (el nombre legal ante la SAR).',
        ($d['nombre_comercial'] ?? '') === '' => 'Escriba el nombre comercial.',
        strlen($rtn) !== 14 => 'El RTN debe tener 14 dígitos.',
        ($d['direccion'] ?? '') === '' => 'Escriba la dirección fiscal.',
        ($d['email'] ?? '') !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL) => 'Correo inválido.',
        default => null,
    };
    if ($error) {
        $_SESSION['form_empresa'] = $d;
        config_volver('empresa', $error, true);
    }
    $db->prepare(
        'INSERT INTO empresa (id, razon_social, nombre_comercial, rtn, direccion, telefono, email) VALUES (1,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE razon_social = VALUES(razon_social), nombre_comercial = VALUES(nombre_comercial), rtn = VALUES(rtn),
                                 direccion = VALUES(direccion), telefono = VALUES(telefono), email = VALUES(email)'
    )->execute([$d['razon_social'], $d['nombre_comercial'], $rtn, $d['direccion'], $d['telefono'] ?: null, $d['email'] ?: null]);
    config_volver('empresa', 'Datos de la empresa actualizados. Las próximas facturas saldrán con estos datos.');
}

$em = $_SESSION['form_empresa'] ?? ($db->query('SELECT * FROM empresa WHERE id = 1')->fetch() ?: []);
unset($_SESSION['form_empresa']);

config_inicio('empresa', $db);
?>
<h1>Empresa</h1>
<div class="columnas">
  <section class="tarjeta">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <label>Razón social <input name="razon_social" required maxlength="150" value="<?= e($em['razon_social'] ?? '') ?>" id="emp-razon"></label>
      <div class="fila">
        <label>Nombre comercial <input name="nombre_comercial" required maxlength="150" value="<?= e($em['nombre_comercial'] ?? '') ?>" id="emp-nombre"></label>
        <label>RTN <input name="rtn" required maxlength="16" inputmode="numeric" class="mono" value="<?= e($em['rtn'] ?? '') ?>" id="emp-rtn"></label>
      </div>
      <label>Dirección fiscal <input name="direccion" required maxlength="255" value="<?= e($em['direccion'] ?? '') ?>" id="emp-dir"></label>
      <div class="fila">
        <label>Teléfono <input name="telefono" maxlength="30" value="<?= e($em['telefono'] ?? '') ?>" id="emp-tel"></label>
        <label>Correo <input type="email" name="email" maxlength="120" value="<?= e($em['email'] ?? '') ?>" id="emp-email"></label>
      </div>
      <button class="btn primario">Guardar</button>
    </form>
    <p class="tenue chico">Las facturas ya emitidas no cambian: conservan los datos con que se imprimieron.</p>
  </section>
  <section class="tarjeta">
    <h2>Así sale el encabezado de la factura</h2>
    <div class="vista-factura">
      <b><?= e($em['nombre_comercial'] ?? '') ?></b><br>
      <?= e($em['razon_social'] ?? '') ?><br>RTN: <?= e($em['rtn'] ?? '') ?><br>
      <i>Nombre y dirección de la sucursal</i><br>
      <?= e($em['email'] ?? '') ?><br>· · ·<br>
      <?= e(Parametros::texto('mensaje_factura')) ?>
    </div>
    <p class="tenue chico">La dirección y el teléfono de cada tienda se editan en <a href="index.php?p=configuracion&s=sucursales">Sucursales</a>;
      el mensaje final, en <a href="index.php?p=configuracion&s=parametros">Parámetros</a>.</p>
  </section>
</div>
<?php layout_fin(); ?>
