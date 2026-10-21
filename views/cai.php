<?php
use App\Support\Auth;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['desactivar'])) {
        $db->prepare('UPDATE cai SET activo = 0 WHERE id = ?')->execute([(int) $_POST['desactivar']]);
        redirigir('cai', 'CAI desactivado.');
    }
    $cai = strtoupper(trim($_POST['cai'] ?? ''));
    $ini = (int) ($_POST['rango_inicial'] ?? 0);
    $fin = (int) ($_POST['rango_final'] ?? 0);
    $errores = [];
    if (!preg_match('/^[0-9A-F]{6}(-[0-9A-F]{6}){4}-[0-9A-F]{2}$/', $cai)) {
        $errores[] = 'El CAI debe tener el formato XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX (hexadecimal).';
    }
    if ($ini < 1 || $fin < $ini) {
        $errores[] = 'Rango inválido.';
    }
    if (!preg_match('/^\d{3}$/', $_POST['punto_emision'] ?? '')) {
        $errores[] = 'Punto de emisión: 3 dígitos.';
    }
    if (($_POST['fecha_limite'] ?? '') < date('Y-m-d')) {
        $errores[] = 'La fecha límite de emisión ya pasó.';
    }
    if ($errores) {
        redirigir('cai', implode(' ', $errores));
    }
    $db->prepare(
        'INSERT INTO cai (tienda_id, cai, punto_emision, tipo_documento, rango_inicial, rango_final, correlativo_actual, fecha_limite)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([(int) $_POST['tienda_id'], $cai, $_POST['punto_emision'], '01', $ini, $fin, $ini - 1, $_POST['fecha_limite']]);
    redirigir('cai', 'CAI registrado.');
}

$cfg = $GLOBALS['config'];
$lista = $db->query(
    'SELECT c.*, t.nombre AS tienda, t.codigo_establecimiento,
            c.rango_final - c.correlativo_actual AS disponibles, DATEDIFF(c.fecha_limite, CURDATE()) AS dias
     FROM cai c JOIN tiendas t ON t.id = c.tienda_id ORDER BY c.activo DESC, t.id, c.fecha_limite'
)->fetchAll();
$tiendas = $db->query('SELECT id, nombre FROM tiendas WHERE activa = 1')->fetchAll();

require __DIR__ . '/_layout.php';
layout_inicio('CAI', 'cai', $db);
?>
<h1>Facturación · CAI (SAR)</h1>
<div class="columnas">
  <section>
    <?php foreach ($lista as $c):
        $pref = "{$c['codigo_establecimiento']}-{$c['punto_emision']}-{$c['tipo_documento']}-";
        $usados = $c['correlativo_actual'] - $c['rango_inicial'] + 1;
        $total = $c['rango_final'] - $c['rango_inicial'] + 1;
        $alerta = match (true) {
            !$c['activo'] => '',
            $c['dias'] < 0 || $c['disponibles'] <= 0 => 'error',
            $c['dias'] <= $cfg['cai_alerta_dias'] || $c['disponibles'] <= $cfg['cai_alerta_facturas'] => 'alerta',
            default => '',
        };
    ?>
      <article class="tarjeta <?= $c['activo'] ? '' : 'inactivo' ?> <?= $alerta ?: '' ?>">
        <header class="fila-entre">
          <h3><?= e($c['tienda']) ?> · PE <?= e($c['punto_emision']) ?></h3>
          <?php if ($c['activo']): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
              <button class="btn texto" name="desactivar" value="<?= $c['id'] ?>" onclick="return confirm('¿Desactivar este CAI?')">Desactivar</button></form>
          <?php endif; ?>
        </header>
        <p class="mono"><?= e($c['cai']) ?></p>
        <p class="chico">Rango: <?= e($pref . sprintf('%08d', $c['rango_inicial'])) ?> al <?= e($pref . sprintf('%08d', $c['rango_final'])) ?><br>
          Último emitido: <?= $usados > 0 ? e($pref . sprintf('%08d', $c['correlativo_actual'])) : '—' ?><br>
          Fecha límite: <?= e($c['fecha_limite']) ?> (<?= (int) $c['dias'] ?> días) · Disponibles: <?= number_format((int) $c['disponibles']) ?></p>
        <progress max="<?= $total ?>" value="<?= max(0, $usados) ?>"></progress>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="tarjeta">
    <h2>Registrar CAI</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <label>Tienda (establecimiento)
        <select name="tienda_id"><?php foreach ($tiendas as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?></select>
      </label>
      <label>CAI <input name="cai" required maxlength="37" class="mono" placeholder="XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX"></label>
      <div class="fila">
        <label>Punto de emisión <input name="punto_emision" value="001" maxlength="3" inputmode="numeric"></label>
        <label>Desde <input type="number" name="rango_inicial" min="1" required></label>
        <label>Hasta <input type="number" name="rango_final" min="1" required></label>
      </div>
      <label>Fecha límite de emisión <input type="date" name="fecha_limite" required></label>
      <button class="btn primario">Guardar</button>
    </form>
    <p class="tenue chico">Al agotarse el rango o vencer la fecha, la caja bloquea la facturación de esa tienda hasta registrar un CAI nuevo.</p>
  </section>
</div>
<?php layout_fin(); ?>
