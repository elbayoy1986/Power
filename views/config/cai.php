<?php
use App\Configuracion\CaiService;
use App\Support\Auth;
use App\Support\Parametros;

$servicio = new CaiService($db);
$filtro = (int) ($_GET['tienda'] ?? $_POST['filtro'] ?? 0);
$extra = $filtro ? '&tienda=' . $filtro : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['desactivar'])) {
            $servicio->desactivar((int) $_POST['desactivar']);
            config_volver('cai', 'CAI desactivado. Ya no se usará para facturar.', false, $extra);
        }
        $servicio->registrar($_POST);
        config_volver('cai', 'CAI registrado. La sucursal ya puede facturar con este rango.', false, '&tienda=' . (int) $_POST['tienda_id']);
    } catch (DomainException $e) {
        $_SESSION['form_cai'] = $_POST;
        config_volver('cai', $e->getMessage(), true, '&tienda=' . (int) ($_POST['tienda_id'] ?? $filtro) . '#form-cai');
    }
}

$alertaDias = Parametros::entero('cai_alerta_dias');
$alertaNums = Parametros::entero('cai_alerta_facturas');
$st = $db->prepare(
    'SELECT c.*, t.nombre AS tienda, t.codigo_establecimiento,
            c.rango_final - c.correlativo_actual AS disponibles, DATEDIFF(c.fecha_limite, CURDATE()) AS dias
     FROM cai c JOIN tiendas t ON t.id = c.tienda_id
     WHERE (? = 0 OR c.tienda_id = ?)
     ORDER BY c.activo DESC, t.codigo_establecimiento, c.punto_emision, c.fecha_limite'
);
$st->execute([$filtro, $filtro]);
$lista = $st->fetchAll();
$tiendas = $db->query('SELECT id, nombre, codigo_establecimiento FROM tiendas WHERE activa = 1 ORDER BY codigo_establecimiento')->fetchAll();
$f = $_SESSION['form_cai'] ?? ['tienda_id' => $filtro, 'punto_emision' => '001'];
unset($_SESSION['form_cai']);

config_inicio('cai', $db);
?>
<div class="fila-entre">
  <h1>CAI por sucursal</h1>
  <form method="get" class="fila">
    <input type="hidden" name="p" value="configuracion"><input type="hidden" name="s" value="cai">
    <label>Sucursal
      <select name="tienda" onchange="this.form.submit()" id="cai-filtro">
        <option value="0">Todas</option>
        <?php foreach ($tiendas as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $filtro === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['codigo_establecimiento'] . ' · ' . $t['nombre']) ?></option><?php endforeach; ?>
      </select>
    </label>
  </form>
</div>
<p class="tenue">Cada CAI autoriza un rango de facturas para una sucursal y un <b>punto de emisión</b> (caja). Con varias cajas, cada cajero elige la suya al abrir turno.</p>

<div class="columnas">
  <section>
    <?php foreach ($lista as $c):
        $pref = "{$c['codigo_establecimiento']}-{$c['punto_emision']}-{$c['tipo_documento']}-";
        $usados = $c['correlativo_actual'] - $c['rango_inicial'] + 1;
        $total = $c['rango_final'] - $c['rango_inicial'] + 1;
        [$clase, $estado] = match (true) {
            !$c['activo'] => ['', 'Inactivo'],
            $c['dias'] < 0 => ['mal', 'Vencido'],
            $c['disponibles'] <= 0 => ['mal', 'Agotado'],
            $c['dias'] <= $alertaDias || $c['disponibles'] <= $alertaNums => ['alerta', 'Por renovar'],
            default => ['ok', 'Vigente'],
        };
    ?>
      <article class="tarjeta <?= $c['activo'] ? '' : 'inactivo' ?> <?= $clase === 'mal' ? 'error' : ($clase === 'alerta' ? 'alerta' : '') ?>">
        <header class="fila-entre">
          <h3><?= e($c['tienda']) ?> · Caja <?= e($c['punto_emision']) ?> <span class="pill <?= $clase ?>"><?= $estado ?></span></h3>
          <?php if ($c['activo']): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="filtro" value="<?= $filtro ?>">
              <button class="btn texto" name="desactivar" value="<?= (int) $c['id'] ?>" onclick="return confirm('¿Desactivar este CAI? No se podrá facturar con él.')">Desactivar</button></form>
          <?php endif; ?>
        </header>
        <p class="mono"><?= e($c['cai']) ?></p>
        <p class="chico">Rango: <?= e($pref . sprintf('%08d', $c['rango_inicial'])) ?> al <?= e($pref . sprintf('%08d', $c['rango_final'])) ?><br>
          Último emitido: <?= $usados > 0 ? e($pref . sprintf('%08d', $c['correlativo_actual'])) : '—' ?><br>
          Fecha límite: <?= e(date('d/m/Y', strtotime($c['fecha_limite']))) ?> (<?= (int) $c['dias'] ?> días) · Disponibles: <?= number_format(max(0, (int) $c['disponibles'])) ?></p>
        <progress max="<?= $total ?>" value="<?= max(0, $usados) ?>" aria-label="Números usados"></progress>
      </article>
    <?php endforeach; ?>
    <?php if (!$lista): ?><p class="aviso error">Esta sucursal no tiene ningún CAI registrado: no puede facturar.</p><?php endif; ?>
  </section>

  <section class="tarjeta" id="form-cai">
    <h2>Registrar CAI</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="filtro" value="<?= $filtro ?>">
      <label>Sucursal (establecimiento)
        <select name="tienda_id" required id="cai-tienda">
          <?php foreach ($tiendas as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) ($f['tienda_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['codigo_establecimiento'] . ' · ' . $t['nombre']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>CAI <input name="cai" required maxlength="37" class="mono" value="<?= e($f['cai'] ?? '') ?>" placeholder="XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX" id="cai-cai" autocomplete="off" spellcheck="false"></label>
      <div class="fila">
        <label>Punto de emisión (caja) <input name="punto_emision" value="<?= e($f['punto_emision'] ?? '001') ?>" maxlength="3" inputmode="numeric" pattern="\d{3}" required class="mono" id="cai-pe"></label>
        <label>Desde <input type="number" name="rango_inicial" min="1" max="99999999" required value="<?= e($f['rango_inicial'] ?? '') ?>" id="cai-ini"></label>
        <label>Hasta <input type="number" name="rango_final" min="1" max="99999999" required value="<?= e($f['rango_final'] ?? '') ?>" id="cai-fin"></label>
      </div>
      <label>Fecha límite de emisión <input type="date" name="fecha_limite" required min="<?= date('Y-m-d') ?>" value="<?= e($f['fecha_limite'] ?? '') ?>" id="cai-fecha"></label>
      <button class="btn primario">Registrar CAI</button>
    </form>
    <p class="tenue chico">Copie los datos de la resolución de la SAR. El sistema rechaza rangos que se crucen con otro CAI del mismo punto de emisión.
      Puede registrar el CAI nuevo antes de que se agote el actual: se usa primero el que vence antes.</p>
    <p class="tenue chico">Alertas: se avisa cuando faltan <?= $alertaDias ?> días o quedan <?= $alertaNums ?> números (<a href="index.php?p=configuracion&s=parametros">cambiar</a>).</p>
  </section>
</div>
<?php layout_fin(); ?>
