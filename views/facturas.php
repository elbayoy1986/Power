<?php
// Consulta y reimpresión de facturas.
//  - Cajero: solo las suyas, en su sucursal.
//  - Gerente de tienda: todas las de su sucursal.
//  - Oficina central: todas las sucursales (o una, con el filtro).
use App\Support\Menu;

$deTienda = Menu::esDeTienda($usuario);
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-d');
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');
$buscar = trim((string) ($_GET['q'] ?? ''));
$tiendaFiltro = $deTienda ? (int) $usuario['tienda_id'] : (int) ($_GET['tienda'] ?? 0);
$limite = 300;

$where = ['v.fecha >= ?', 'v.fecha < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$desde, $hasta];
if ($tiendaFiltro) {
    $where[] = 'v.tienda_id = ?';
    $params[] = $tiendaFiltro;
}
if ($usuario['rol'] === 'CAJERO') {
    $where[] = 'v.usuario_id = ?';
    $params[] = $usuario['id'];
}
if ($buscar !== '') {
    $where[] = '(v.numero_factura LIKE ? OR v.cliente_nombre LIKE ? OR v.cliente_rtn LIKE ?)';
    array_push($params, "%$buscar%", "%$buscar%", "%$buscar%");
}
$sqlWhere = implode(' AND ', $where);

$st = $db->prepare(
    "SELECT v.id, v.numero_factura, v.fecha, v.cliente_nombre, v.cliente_rtn, v.forma_pago, v.total, v.estado,
            v.descuento_promos + v.descuento_legal AS descuentos, t.nombre AS tienda, u.nombre AS cajero
     FROM ventas v JOIN tiendas t ON t.id = v.tienda_id JOIN usuarios u ON u.id = v.usuario_id
     WHERE $sqlWhere ORDER BY v.id DESC LIMIT " . ($limite + 1)
);
$st->execute($params);
$facturas = $st->fetchAll();
$hayMas = count($facturas) > $limite;
$facturas = array_slice($facturas, 0, $limite);

$st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(IF(v.estado = 'EMITIDA', v.total, 0)), 0) total FROM ventas v WHERE $sqlWhere");
$st->execute($params);
$resumen = $st->fetch();
$tiendas = $deTienda ? [] : $db->query('SELECT id, nombre FROM tiendas ORDER BY codigo_establecimiento')->fetchAll();

require __DIR__ . '/_layout.php';
layout_inicio('Facturas', 'facturas', $db);
?>
<h1>Facturas <?= $usuario['rol'] === 'CAJERO' ? 'emitidas por mí' : '' ?></h1>
<form method="get" class="fila tarjeta">
  <input type="hidden" name="p" value="facturas">
  <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>" id="fac-desde"></label>
  <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>" id="fac-hasta"></label>
  <?php if (!$deTienda): ?>
    <label>Sucursal
      <select name="tienda" id="fac-tienda">
        <option value="0">Todas</option>
        <?php foreach ($tiendas as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $tiendaFiltro === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
  <label>Buscar <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="No. de factura, cliente o RTN" id="fac-q"></label>
  <button class="btn primario">Consultar</button>
</form>

<div class="kpis">
  <div class="kpi"><span>Facturas</span><b><?= number_format((int) $resumen['n']) ?></b></div>
  <div class="kpi"><span>Total facturado</span><b><?= lps($resumen['total']) ?></b></div>
</div>

<div class="tabla-scroll"><table class="tabla">
  <thead><tr><th>Factura</th><th>Fecha</th><?php if (!$deTienda): ?><th>Sucursal</th><?php endif; ?>
    <?php if ($usuario['rol'] !== 'CAJERO'): ?><th>Cajero</th><?php endif; ?><th>Cliente</th><th>Pago</th><th class="num">Total</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($facturas as $v): ?>
    <tr class="<?= $v['estado'] === 'ANULADA' ? 'inactivo' : '' ?>">
      <td class="sin-corte"><a class="mono chico" href="index.php?p=factura&id=<?= (int) $v['id'] ?>" target="_blank" rel="noopener" title="Ver o reimprimir"><?= e($v['numero_factura']) ?></a><?= $v['estado'] === 'ANULADA' ? ' <span class="pill mal">Anulada</span>' : '' ?></td>
      <td class="sin-corte"><?= e(date('d/m/Y H:i', strtotime($v['fecha']))) ?></td>
      <?php if (!$deTienda): ?><td><?= e($v['tienda']) ?></td><?php endif; ?>
      <?php if ($usuario['rol'] !== 'CAJERO'): ?><td><?= e($v['cajero']) ?></td><?php endif; ?>
      <td><?= e($v['cliente_nombre']) ?><?= $v['cliente_rtn'] ? '<br><small class="tenue mono">' . e($v['cliente_rtn']) . '</small>' : '' ?></td>
      <td><?= e(ucfirst(strtolower($v['forma_pago']))) ?><?= $v['descuentos'] > 0 ? '<br><small class="ahorro">Desc. ' . lps($v['descuentos']) . '</small>' : '' ?></td>
      <td class="num"><b><?= lps($v['total']) ?></b></td>
      <td class="num"><a href="index.php?p=factura&id=<?= (int) $v['id'] ?>" target="_blank" rel="noopener">Reimprimir</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$facturas): ?><tr><td colspan="8" class="tenue">No hay facturas con esos filtros.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php if ($hayMas): ?><p class="tenue chico">Se muestran las <?= $limite ?> más recientes. Acorte el rango de fechas o use el buscador para ver las demás.</p><?php endif; ?>
<?php layout_fin(); ?>
