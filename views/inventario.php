<?php
use App\Inventario\InventarioService;
use App\Support\Auth;

$inv = new InventarioService($db);
$tiendaId = Auth::tiendaId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int) ($_POST['producto_id'] ?? 0);
    try {
        $db->beginTransaction();
        switch ($_POST['accion'] ?? '') {
            case 'minimo':
                $inv->actualizarMinimo($tiendaId, $pid, (float) $_POST['minimo']);
                $msg = 'Mínimo actualizado.';
                break;
            case 'ajuste':
                $cant = (float) $_POST['cantidad'] * (($_POST['sentido'] ?? '+') === '-' ? -1 : 1);
                $inv->ajustar($tiendaId, $pid, $_POST['tipo'] ?? 'AJUSTE', $cant, $_POST['motivo'] ?? '', $usuario['id']);
                $msg = 'Ajuste registrado en el kárdex.';
                break;
            case 'traslado':
                $inv->trasladar($tiendaId, (int) $_POST['destino'], $pid, (float) $_POST['cantidad'], $usuario['id']);
                $msg = 'Traslado registrado.';
                break;
            default:
                throw new DomainException('Acción desconocida.');
        }
        $db->commit();
        redirigir('inventario&kardex=' . $pid, $msg);
    } catch (DomainException $e) {
        $db->rollBack();
        redirigir('inventario' . ($pid ? '&kardex=' . $pid : ''), $e->getMessage());
    }
}

$buscar = trim($_GET['q'] ?? '');
$filas = $inv->existencias($tiendaId, $buscar);
$valorTotal = array_sum(array_column($filas, 'valor'));
$bajos = count(array_filter($filas, fn ($f) => $f['bajo_minimo']));
$kardexId = (int) ($_GET['kardex'] ?? 0);
$kardex = $kardexId ? $inv->kardex($tiendaId, $kardexId) : [];
$kProd = $kardexId ? $db->query('SELECT nombre, sku FROM productos WHERE id = ' . $kardexId)->fetch() : null;
$st = $db->prepare('SELECT id, nombre FROM tiendas WHERE activa = 1 AND id <> ?');
$st->execute([$tiendaId]);
$otras = $st->fetchAll();
$tiendaNombre = $db->query('SELECT nombre FROM tiendas WHERE id = ' . $tiendaId)->fetchColumn();
$opciones = implode('', array_map(fn ($f) => '<option value="' . $f['id'] . '"' . ((int) $f['id'] === $kardexId ? ' selected' : '') . '>'
    . e($f['nombre']) . ' (' . InventarioService::cant($f['existencia']) . ')</option>', $filas));
$tipos = ['VENTA' => 'Venta', 'COMPRA' => 'Compra', 'ENTRADA' => 'Entrada', 'AJUSTE' => 'Ajuste', 'MERMA' => 'Merma',
    'TRASLADO_SALIDA' => 'Traslado sale', 'TRASLADO_ENTRADA' => 'Traslado entra'];

require __DIR__ . '/_layout.php';
layout_inicio('Inventario', 'inventario', $db);
?>
<h1>Inventario · <?= e($tiendaNombre) ?></h1>
<div class="kpis">
  <div class="kpi"><span>Productos con inventario</span><b><?= count($filas) ?></b></div>
  <div class="kpi"><span>Bajo mínimo</span><b class="<?= $bajos ? 'mal-txt' : '' ?>"><?= $bajos ?></b></div>
  <div class="kpi"><span>Valor al costo (sin ISV)</span><b><?= lps((int) $valorTotal) ?></b></div>
</div>

<div class="columnas">
  <section>
    <form method="get" class="fila buscar">
      <input type="hidden" name="p" value="inventario">
      <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar producto o SKU" aria-label="Buscar" id="buscar-inv">
      <button class="btn">Buscar</button>
    </form>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>Producto</th><th class="num">Existencia</th><th class="num">Mínimo</th><th class="num">Costo prom.</th><th class="num">Valor</th></tr></thead>
      <tbody>
      <?php foreach ($filas as $f): ?>
        <tr>
          <td><a href="index.php?p=inventario&kardex=<?= (int) $f['id'] ?>#kardex"><b><?= e($f['nombre']) ?></b></a><br>
            <small class="tenue mono"><?= e($f['sku']) ?></small>
            <?php if ($f['bajo_minimo']): ?><span class="pill mal">Reponer</span><?php endif; ?></td>
          <td class="num <?= (float) $f['existencia'] < 0 ? 'mal-txt' : '' ?>"><?= InventarioService::cant($f['existencia']) ?></td>
          <td class="num">
            <form method="post" class="en-linea mini">
              <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="accion" value="minimo">
              <input type="hidden" name="producto_id" value="<?= (int) $f['id'] ?>">
              <input name="minimo" value="<?= InventarioService::cant($f['minimo']) ?>" inputmode="decimal" aria-label="Mínimo de <?= e($f['nombre']) ?>" class="corto" onchange="this.form.submit()">
            </form>
          </td>
          <td class="num"><?= lps($f['costo_promedio']) ?></td>
          <td class="num"><?= lps((int) $f['valor']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$filas): ?><tr><td colspan="5" class="tenue">Sin productos. Créelos en Productos y cárguelos con una compra.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <p class="tenue chico">La existencia baja sola con cada venta (también los componentes de combos) y sube al recibir compras. Cambie el mínimo y presione Enter.</p>
  </section>

  <div>
    <section class="tarjeta">
      <h2>Ajuste o merma</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="accion" value="ajuste">
        <label>Producto <select name="producto_id" required id="aj-prod"><?= $opciones ?></select></label>
        <div class="fila">
          <label>Tipo <select name="tipo" id="aj-tipo" onchange="document.getElementById('aj-sentido').disabled = this.value === 'MERMA'">
            <option value="AJUSTE">Ajuste por conteo físico</option><option value="MERMA">Merma (vencido, dañado)</option></select></label>
          <label>Sentido <select name="sentido" id="aj-sentido"><option value="+">Sumar (+)</option><option value="-">Restar (−)</option></select></label>
          <label>Cantidad <input name="cantidad" inputmode="decimal" required id="aj-cant"></label>
        </div>
        <label>Motivo <input name="motivo" required maxlength="150" placeholder="Conteo físico semanal" id="aj-motivo"></label>
        <button class="btn primario">Registrar ajuste</button>
      </form>
    </section>

    <?php if ($otras): ?>
    <section class="tarjeta">
      <h2>Traslado a otra tienda</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="accion" value="traslado">
        <label>Producto <select name="producto_id" required id="tr-prod"><?= $opciones ?></select></label>
        <div class="fila">
          <label>Destino <select name="destino" id="tr-dest"><?php foreach ($otras as $o): ?><option value="<?= $o['id'] ?>"><?= e($o['nombre']) ?></option><?php endforeach; ?></select></label>
          <label>Cantidad <input name="cantidad" inputmode="decimal" required id="tr-cant"></label>
        </div>
        <button class="btn">Trasladar</button>
        <p class="tenue chico">Sale de <?= e($tiendaNombre) ?> y entra en el destino al costo promedio de origen.</p>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($kProd): ?>
<section class="tarjeta" id="kardex">
  <h2>Kárdex · <?= e($kProd['nombre']) ?> <small class="tenue mono"><?= e($kProd['sku']) ?></small></h2>
  <div class="tabla-scroll"><table class="tabla">
    <thead><tr><th>Fecha</th><th>Movimiento</th><th>Referencia</th><th>Usuario</th><th class="num">Cantidad</th><th class="num">Costo</th><th class="num">Saldo</th></tr></thead>
    <tbody>
    <?php foreach ($kardex as $m): ?>
      <tr>
        <td><?= e(date('d/m/Y H:i', strtotime($m['fecha']))) ?></td>
        <td><?= e($tipos[$m['tipo']] ?? $m['tipo']) ?><?= $m['motivo'] ? '<br><small class="tenue">' . e($m['motivo']) . '</small>' : '' ?></td>
        <td class="chico"><?= e($m['referencia']) ?></td>
        <td class="chico"><?= e($m['usuario'] ?? '—') ?></td>
        <td class="num <?= (float) $m['cantidad'] < 0 ? 'mal-txt' : 'ok-txt' ?>"><?= (float) $m['cantidad'] > 0 ? '+' : '' ?><?= InventarioService::cant($m['cantidad']) ?></td>
        <td class="num"><?= $m['costo_unitario'] !== null ? lps($m['costo_unitario']) : '' ?></td>
        <td class="num"><b><?= InventarioService::cant($m['saldo']) ?></b></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$kardex): ?><tr><td colspan="7" class="tenue">Sin movimientos.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php layout_fin(); ?>
