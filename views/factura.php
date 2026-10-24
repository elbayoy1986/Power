<?php
use App\Catalogo\CatalogoRepository;
use App\Ventas\CarritoService;
use App\Ventas\FacturacionService;

$servicio = new FacturacionService($db, new CarritoService(new CatalogoRepository($db)));
$v = $servicio->obtener((int) ($_GET['id'] ?? 0));
if (!$v || ($usuario['tienda_id'] !== null && (int) $v['tienda_id'] !== $usuario['tienda_id'])) {
    http_response_code(404);
    exit('Factura no encontrada.');
}
$em = $v['empresa'];
$mesa = null;
if ($v['cuenta_id']) {
    $st = $db->prepare('SELECT m.nombre AS mesa, u.nombre AS mesero FROM cuentas c JOIN mesas m ON m.id = c.mesa_id JOIN usuarios u ON u.id = c.mesero_id WHERE c.id = ?');
    $st->execute([$v['cuenta_id']]);
    $mesa = $st->fetch() ?: null;
}
$fila = fn (string $t, int $c) => $c ? '<tr><td>' . e($t) . '</td><td class="num">' . lps($c) . '</td></tr>' : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Factura <?= e($v['numero_factura']) ?></title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="pantalla-factura">
<article class="factura">
  <header>
    <h1><?= e($em['nombre_comercial']) ?></h1>
    <p><?= e($em['razon_social']) ?><br>RTN: <?= e($em['rtn']) ?><br>
       <?= e($v['tienda']) ?> · <?= e($v['tienda_direccion']) ?><br>Tel. <?= e($v['tienda_telefono']) ?> · <?= e($em['email']) ?></p>
  </header>

  <p class="centro"><b>FACTURA</b><br>No. <?= e($v['numero_factura']) ?></p>
  <p class="chico">
    CAI: <?= e($v['cai']) ?><br>
    Fecha: <?= e(date('d/m/Y H:i', strtotime($v['fecha']))) ?><br>
    Cliente: <?= e($v['cliente_nombre']) ?><br>
    RTN cliente: <?= e($v['cliente_rtn'] ?: '—') ?><br>
    Cajero: <?= e($v['cajero']) ?> · Pago: <?= e($v['forma_pago']) ?>
    <?php if ($mesa): ?><br>Mesa: <?= e($mesa['mesa']) ?> · Mesero: <?= e($mesa['mesero']) ?><?php endif; ?>
  </p>

  <table class="detalle">
    <thead><tr><th>Cant.</th><th>Descripción</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($v['lineas'] as $l): ?>
      <tr>
        <td><?= (int) $l['cantidad'] ?></td>
        <td><?= e($l['descripcion']) ?> <small>(<?= e($l['tipo_impuesto']) ?>)</small>
          <?php foreach ($l['opciones'] as $o): ?><br><small>· <?= e($o['descripcion']) ?><?= $o['recargo'] ? ' +' . lps($o['recargo']) : '' ?></small><?php endforeach; ?>
          <?php if ($l['descuento_promo'] + $l['descuento_legal']): ?><br><small>Desc. −<?= lps($l['descuento_promo'] + $l['descuento_legal']) ?></small><?php endif; ?>
        </td>
        <td class="num"><?= lps($l['precio_unitario'] * $l['cantidad']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <table class="resumen">
    <?= $fila('Subtotal', (int) $v['subtotal']) ?>
    <tr><td>Descuentos y rebajas otorgados</td><td class="num"><?= lps($v['descuento_promos'] + $v['descuento_legal']) ?></td></tr>
    <?php if ($v['descuento_legal']): ?>
      <tr><td colspan="2" class="chico">Incluye <?= e($v['descuento_legal_nombre']) ?>: <?= lps($v['descuento_legal']) ?>
        — <?= e($v['beneficiario_nombre'] ?? '') ?> (<?= e($v['beneficiario_documento']) ?>)</td></tr>
    <?php endif; ?>
    <tr><td>Importe exonerado</td><td class="num"><?= lps($v['importe_exonerado']) ?></td></tr>
    <tr><td>Importe exento</td><td class="num"><?= lps($v['importe_exento']) ?></td></tr>
    <tr><td>Importe gravado 15%</td><td class="num"><?= lps($v['importe_gravado_15']) ?></td></tr>
    <tr><td>Importe gravado 18%</td><td class="num"><?= lps($v['importe_gravado_18']) ?></td></tr>
    <tr><td>ISV 15%</td><td class="num"><?= lps($v['isv_15']) ?></td></tr>
    <tr><td>ISV 18%</td><td class="num"><?= lps($v['isv_18']) ?></td></tr>
    <tr class="total"><td>TOTAL A PAGAR</td><td class="num"><?= lps($v['total']) ?></td></tr>
  </table>
  <p class="chico"><?= e($v['total_letras']) ?></p>

  <footer class="chico">
    <p>No. correlativo de orden de compra exenta: ________<br>
       No. correlativo constancia de registro exonerado: ________<br>
       No. identificativo del registro de la SAG: ________</p>
    <p>Rango autorizado: <?= e($v['rango_autorizado']) ?><br>
       Fecha límite de emisión: <?= e(date('d/m/Y', strtotime($v['fecha_limite_emision']))) ?></p>
    <p class="centro">ORIGINAL: CLIENTE · COPIA: EMISOR<br>¡Gracias por su compra!</p>
  </footer>
  <?php if ($v['propina'] > 0): ?>
    <p class="chico centro propina-aparte">Propina voluntaria recibida: <?= lps($v['propina']) ?><br>(no forma parte de esta factura)</p>
  <?php endif; ?>
</article>
<div class="no-imprimir centro"><button class="btn primario" onclick="print()">Imprimir</button></div>
</body>
</html>
