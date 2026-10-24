<?php
// Pre-cuenta (80 mm): lo que lleva la mesa y cuánto va, para entregar al cliente antes de facturar. No es factura.
use App\Catalogo\CatalogoRepository;
use App\Mesas\CuentasService;
use App\Support\Auth;
use App\Ventas\CarritoService;

$tiendaId = Auth::tiendaId();
$cuentas = new CuentasService($db, new CarritoService(new CatalogoRepository($db)));
$c = $cuentas->obtener($tiendaId, (int) ($_GET['cuenta'] ?? 0));
if (!$c) {
    http_response_code(404);
    exit('Cuenta no encontrada.');
}
$cot = $cuentas->cotizar($tiendaId, $c['id']);
$r = $cot['resumen'];
$pct = (float) ($GLOBALS['config']['propina_sugerida'] ?? 10);
$sugerida = (int) round($r['total'] * $pct / 100);
$em = $db->query('SELECT * FROM empresa WHERE id = 1')->fetch();
$tienda = $db->query('SELECT * FROM tiendas WHERE id = ' . $tiendaId)->fetch();
$fila = fn (string $t, int $v) => $v ? '<tr><td>' . e($t) . '</td><td class="num">' . lps($v) . '</td></tr>' : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pre-cuenta mesa <?= e($c['mesa']) ?></title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="pantalla-factura">
<article class="factura">
  <header>
    <h1><?= e($em['nombre_comercial']) ?></h1>
    <p><?= e($tienda['nombre']) ?><br>Tel. <?= e($tienda['telefono']) ?></p>
  </header>
  <p class="centro"><b>PRE-CUENTA</b><br>Mesa <?= e($c['mesa']) ?> · <?= (int) $c['comensales'] ?> pers.</p>
  <p class="chico">Fecha: <?= e(date('d/m/Y H:i')) ?><br>Le atendió: <?= e($c['mesero']) ?></p>
  <table class="detalle">
    <thead><tr><th>Cant.</th><th>Descripción</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($cot['lineas'] as $l): ?>
      <tr>
        <td><?= (int) $l['cantidad'] ?></td>
        <td><?= e($l['descripcion']) ?>
          <?php foreach ($l['opciones'] as $o): ?><br><small>· <?= e($o['nombre']) ?><?= $o['recargo'] ? ' +' . lps($o['recargo']) : '' ?></small><?php endforeach; ?>
          <?php if ($l['descuento_promo']): ?><br><small>Promo −<?= lps($l['descuento_promo']) ?></small><?php endif; ?>
        </td>
        <td class="num"><?= lps($l['neto']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$cot['lineas']): ?><tr><td colspan="3">No hay consumo pendiente de cobro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <table class="resumen">
    <?= $fila('Subtotal', $r['subtotal']) ?>
    <?= $fila('Descuentos', -$r['descuento_promos']) ?>
    <?= $fila('ISV', $r['isv_15'] + $r['isv_18']) ?>
    <tr class="total"><td>TOTAL</td><td class="num"><?= lps($r['total']) ?></td></tr>
    <?php if ($sugerida): ?>
      <tr><td>Propina sugerida (<?= rtrim(rtrim(number_format($pct, 2), '0'), '.') ?>%)</td><td class="num"><?= lps($sugerida) ?></td></tr>
      <tr><td><b>Total con propina</b></td><td class="num"><b><?= lps($r['total'] + $sugerida) ?></b></td></tr>
    <?php endif; ?>
  </table>
  <?php if ($c['facturas']): ?><p class="chico">Ya se cobraron <?= count($c['facturas']) ?> factura(s) de esta mesa; aquí solo va lo pendiente.</p><?php endif; ?>
  <footer class="chico">
    <p class="centro">La propina es voluntaria.<br>¿Factura con RTN? Indíquelo al pagar.<br><b>ESTE DOCUMENTO NO ES UNA FACTURA</b></p>
  </footer>
</article>
<div class="no-imprimir centro"><button class="btn primario" onclick="print()">Imprimir</button></div>
</body>
</html>
