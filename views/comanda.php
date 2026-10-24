<?php
// Comanda impresa (80 mm): la imprime la pantalla de cocina, sola o con el botón 🖨.
use App\Cocina\ComandasService;
use App\Support\Auth;

$k = (new ComandasService($db))->obtener(Auth::tiendaId(), (int) ($_GET['id'] ?? 0));
if (!$k) {
    http_response_code(404);
    exit('Comanda no encontrada.');
}
$anulacion = $k['tipo'] === 'ANULACION';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Comanda #<?= (int) $k['id'] ?></title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="pantalla-factura">
<article class="factura ticket-comanda">
  <header>
    <p class="estacion"><?= e(mb_strtoupper($k['estacion'])) ?><?= $anulacion ? ' · ANULAR' : '' ?></p>
    <h1><?= e($k['referencia']) ?></h1>
    <p>Comanda #<?= (int) $k['id'] ?> · <?= e(date('d/m H:i', strtotime($k['creada_en']))) ?><br>
      <?= $k['mesero'] ? 'Mesero: ' . e($k['mesero']) . ' · ' . (int) $k['comensales'] . ' pers.' : 'Envió: ' . e($k['enviada_por']) ?></p>
  </header>
  <?php if ($anulacion): ?><p class="centro"><b>*** NO PREPARAR ***</b></p><?php endif; ?>
  <table>
    <?php foreach ($k['items'] as $i): ?>
      <tr class="<?= $i['anulado'] ? 'tachado' : '' ?>">
        <td class="cant"><?= (int) $i['cantidad'] ?>×</td>
        <td><b><?= e($i['descripcion']) ?></b>
          <?php if ($i['detalle']): ?><br><?= e($i['detalle']) ?><?php endif; ?>
          <?php if ($i['nota']): ?><br><b>» <?= e(mb_strtoupper($i['nota'])) ?></b><?php endif; ?>
          <?php if ($i['anulado']): ?><br>(ANULADO)<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="centro chico"><?= e($k['tienda']) ?></p>
</article>
<div class="no-imprimir centro"><button class="btn primario" onclick="print()">Imprimir</button></div>
</body>
</html>
