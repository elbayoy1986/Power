<?php
// Tablero central: consolida todas las tiendas (un gerente con tienda fija solo ve la suya).
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$soloTienda = $usuario['tienda_id'];
$filtro = 'v.estado = \'EMITIDA\' AND v.fecha >= ? AND v.fecha < DATE_ADD(?, INTERVAL 1 DAY)' . ($soloTienda ? ' AND v.tienda_id = ?' : '');
$params = array_merge([$desde, $hasta], $soloTienda ? [$soloTienda] : []);

$q = function (string $sql) use ($db, $params): array {
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
};

$k = $q("SELECT COUNT(*) facturas, COALESCE(SUM(total),0) ventas, COALESCE(AVG(total),0) ticket,
                COALESCE(SUM(descuento_promos),0) promos, COALESCE(SUM(descuento_legal),0) legal,
                COALESCE(SUM(isv_15 + isv_18),0) isv
         FROM ventas v WHERE $filtro")[0];
$porTienda = $q("SELECT t.nombre, COUNT(v.id) facturas, SUM(v.total) ventas, AVG(v.total) ticket
                 FROM ventas v JOIN tiendas t ON t.id = v.tienda_id WHERE $filtro GROUP BY t.id, t.nombre ORDER BY ventas DESC");
$porDia = $q("SELECT DATE(v.fecha) dia, SUM(v.total) ventas FROM ventas v WHERE $filtro GROUP BY DATE(v.fecha) ORDER BY dia");
$top = $q("SELECT l.descripcion, SUM(l.cantidad) unidades, SUM(l.total) ventas
           FROM venta_lineas l JOIN ventas v ON v.id = l.venta_id WHERE $filtro
           GROUP BY l.descripcion ORDER BY ventas DESC LIMIT 8");
$porHora = $q("SELECT HOUR(v.fecha) hora, COUNT(*) facturas FROM ventas v WHERE $filtro GROUP BY HOUR(v.fecha) ORDER BY hora");

$sqlStock = 'SELECT t.nombre tienda, p.nombre producto, i.existencia, i.minimo
             FROM inventario i JOIN tiendas t ON t.id = i.tienda_id JOIN productos p ON p.id = i.producto_id
             WHERE p.controla_stock = 1 AND i.existencia <= i.minimo' . ($soloTienda ? ' AND i.tienda_id = ' . (int) $soloTienda : '') . '
             ORDER BY i.existencia - i.minimo LIMIT 10';
$stockBajo = $db->query($sqlStock)->fetchAll();
$cfg = $GLOBALS['config'];
$st = $db->prepare('SELECT * FROM v_estado_cai WHERE dias_restantes <= ? OR disponibles <= ?');
$st->execute([$cfg['cai_alerta_dias'], $cfg['cai_alerta_facturas']]);
$caiAlertas = $st->fetchAll();

$maxDia = max(1, ...array_map(fn ($d) => (int) $d['ventas'], $porDia ?: [['ventas' => 1]]));
$maxHora = max(1, ...array_map(fn ($h) => (int) $h['facturas'], $porHora ?: [['facturas' => 1]]));

require __DIR__ . '/_layout.php';
layout_inicio('Tablero ERP', 'erp', $db);
?>
<div class="fila-entre">
  <h1>Tablero central <?= $soloTienda ? '' : '· todas las tiendas' ?></h1>
  <form class="fila" method="get">
    <input type="hidden" name="p" value="erp">
    <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
    <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
    <button class="btn">Ver</button>
  </form>
</div>

<?php foreach ($caiAlertas as $a): ?>
  <div class="aviso error">CAI de <b><?= e($a['tienda']) ?></b> (PE <?= e($a['punto_emision']) ?>):
    <?= (int) $a['disponibles'] ?> números disponibles, vence en <?= (int) $a['dias_restantes'] ?> días. Solicite nuevo CAI a la SAR.</div>
<?php endforeach; ?>

<div class="kpis">
  <div class="kpi"><span>Ventas</span><b><?= lps($k['ventas']) ?></b></div>
  <div class="kpi"><span>Facturas</span><b><?= number_format((int) $k['facturas']) ?></b></div>
  <div class="kpi"><span>Ticket promedio</span><b><?= lps((int) round($k['ticket'])) ?></b></div>
  <div class="kpi"><span>Descuento promociones</span><b><?= lps($k['promos']) ?></b></div>
  <div class="kpi"><span>Descuento de ley</span><b><?= lps($k['legal']) ?></b></div>
  <div class="kpi"><span>ISV generado</span><b><?= lps($k['isv']) ?></b></div>
</div>

<div class="columnas">
  <section class="tarjeta">
    <h2>Ventas por día</h2>
    <div class="barras" role="img" aria-label="Ventas por día">
      <?php foreach ($porDia as $d): ?>
        <div class="barra-col" title="<?= e($d['dia']) ?>: <?= lps($d['ventas']) ?>">
          <i style="height: <?= round($d['ventas'] / $maxDia * 100) ?>%"></i><small><?= e(date('d', strtotime($d['dia']))) ?></small>
        </div>
      <?php endforeach; ?>
      <?php if (!$porDia): ?><p class="tenue">Sin ventas en el período.</p><?php endif; ?>
    </div>
  </section>
  <section class="tarjeta">
    <h2>Facturas por hora</h2>
    <div class="barras" role="img" aria-label="Facturas por hora">
      <?php foreach ($porHora as $h): ?>
        <div class="barra-col" title="<?= (int) $h['hora'] ?>:00 · <?= (int) $h['facturas'] ?> facturas">
          <i style="height: <?= round($h['facturas'] / $maxHora * 100) ?>%"></i><small><?= (int) $h['hora'] ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<div class="columnas">
  <section class="tarjeta">
    <h2>Ranking de tiendas</h2>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>Tienda</th><th class="num">Facturas</th><th class="num">Ticket</th><th class="num">Ventas</th></tr></thead>
      <tbody><?php foreach ($porTienda as $t): ?>
        <tr><td><?= e($t['nombre']) ?></td><td class="num"><?= (int) $t['facturas'] ?></td>
          <td class="num"><?= lps((int) round($t['ticket'])) ?></td><td class="num"><b><?= lps($t['ventas']) ?></b></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </section>
  <section class="tarjeta">
    <h2>Top productos y combos</h2>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>Artículo</th><th class="num">Unidades</th><th class="num">Ventas</th></tr></thead>
      <tbody><?php foreach ($top as $t): ?>
        <tr><td><?= e($t['descripcion']) ?></td><td class="num"><?= (int) $t['unidades'] ?></td><td class="num"><?= lps($t['ventas']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </section>
</div>

<section class="tarjeta">
  <h2>Inventario bajo mínimo</h2>
  <div class="tabla-scroll"><table class="tabla">
    <thead><tr><th>Tienda</th><th>Producto</th><th class="num">Existencia</th><th class="num">Mínimo</th></tr></thead>
    <tbody><?php foreach ($stockBajo as $s): ?>
      <tr><td><?= e($s['tienda']) ?></td><td><?= e($s['producto']) ?></td>
        <td class="num"><?= (float) $s['existencia'] ?></td><td class="num"><?= (float) $s['minimo'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$stockBajo): ?><tr><td colspan="4" class="tenue">Todo el inventario está sobre el mínimo.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>
<?php layout_fin(); ?>
