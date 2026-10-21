<?php
// Reporte de turno en 80 mm: "Corte X" si el turno sigue abierto, "Cierre Z" si ya se cerró.
use App\Caja\CajaService;

$caja = new CajaService($db);
$t = $caja->turno((int) ($_GET['id'] ?? 0));
$puedeVer = $t && ((int) $t['usuario_id'] === $usuario['id']
    || ($usuario['rol'] !== 'CAJERO' && ($usuario['tienda_id'] === null || (int) $t['tienda_id'] === $usuario['tienda_id'])));
if (!$puedeVer) {
    http_response_code(404);
    exit('Turno no encontrado.');
}
$r = $caja->resumen((int) $t['id']);
$cerrado = $t['estado'] === 'CERRADO';
$conteo = $cerrado ? (json_decode((string) $t['conteo'], true) ?: []) : [];
$em = $db->query('SELECT * FROM empresa WHERE id = 1')->fetch();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $cerrado ? 'Cierre Z' : 'Corte X' ?> · Turno <?= (int) $t['id'] ?></title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="pantalla-factura">
<article class="factura">
  <header>
    <h1><?= e($em['nombre_comercial']) ?></h1>
    <p><?= e($t['tienda']) ?><br><b><?= $cerrado ? 'CIERRE DE CAJA (Z)' : 'CORTE PARCIAL (X)' ?></b><br>Turno #<?= (int) $t['id'] ?></p>
  </header>
  <p class="chico">
    Cajero: <?= e($t['cajero']) ?><br>
    Apertura: <?= e(date('d/m/Y H:i', strtotime($t['apertura']))) ?><br>
    <?= $cerrado ? 'Cierre: ' . e(date('d/m/Y H:i', strtotime($t['cierre']))) . ' por ' . e($t['cerrado_por_nombre']) : 'Impreso: ' . date('d/m/Y H:i') ?><br>
    Facturas: <?= e($r['ventas']['primera'] ?? '—') ?> a <?= e($r['ventas']['ultima'] ?? '—') ?>
  </p>

  <table class="resumen">
    <tr><td colspan="2"><b>VENTAS</b></td></tr>
    <?php foreach ($r['por_pago'] as $forma => $p): ?>
      <tr><td><?= e($forma) ?> (<?= (int) $p['facturas'] ?>)</td><td class="num"><?= lps($p['total']) ?></td></tr>
    <?php endforeach; ?>
    <tr class="total"><td>Total vendido (<?= (int) $r['ventas']['facturas'] ?>)</td><td class="num"><?= lps($r['ventas']['total']) ?></td></tr>
    <tr><td>Descuentos promociones</td><td class="num"><?= lps($r['ventas']['promos']) ?></td></tr>
    <tr><td>Descuentos de ley</td><td class="num"><?= lps($r['ventas']['legal']) ?></td></tr>
    <tr><td>ISV incluido</td><td class="num"><?= lps($r['ventas']['isv']) ?></td></tr>
    <tr><td>Facturas anuladas</td><td class="num"><?= (int) $r['ventas']['anuladas'] ?></td></tr>
  </table>

  <table class="resumen">
    <tr><td colspan="2"><b>EFECTIVO</b></td></tr>
    <tr><td>Fondo inicial</td><td class="num"><?= lps($t['fondo_inicial']) ?></td></tr>
    <tr><td>+ Ventas en efectivo</td><td class="num"><?= lps($r['efectivo_ventas']) ?></td></tr>
    <?php foreach ($r['movimientos'] as $m): ?>
      <tr><td><?= $m['tipo'] === 'RETIRO' ? '−' : '+' ?> <?= e($m['motivo']) ?></td><td class="num"><?= lps($m['monto']) ?></td></tr>
    <?php endforeach; ?>
    <tr class="total"><td>Esperado en caja</td><td class="num"><?= lps($r['efectivo_esperado']) ?></td></tr>
    <?php if ($cerrado): ?>
      <tr><td>Contado</td><td class="num"><?= lps($t['efectivo_contado']) ?></td></tr>
      <tr class="total"><td><?= (int) $t['diferencia'] === 0 ? 'Diferencia' : ((int) $t['diferencia'] < 0 ? 'FALTANTE' : 'SOBRANTE') ?></td>
        <td class="num"><?= lps(abs((int) $t['diferencia'])) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php if ($conteo): ?>
    <table class="resumen">
      <tr><td colspan="3"><b>ARQUEO</b></td></tr>
      <?php foreach ($conteo as $d => $n): ?>
        <tr><td><?= $d >= 100 ? 'L ' . ($d / 100) : $d . ' ctv' ?></td><td>× <?= (int) $n ?></td><td class="num"><?= lps($d * $n) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($t['notas']): ?><p class="chico">Notas: <?= e($t['notas']) ?></p><?php endif; ?>

  <footer class="chico">
    <p><br>_______________________<br>Firma cajero</p>
    <p><br>_______________________<br>Firma supervisor</p>
  </footer>
</article>
<div class="no-imprimir centro"><button class="btn primario" onclick="print()">Imprimir</button> <a class="btn" href="index.php?p=caja">Volver</a></div>
</body>
</html>
