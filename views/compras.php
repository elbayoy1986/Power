<?php
use App\Compras\ComprasService;
use App\Inventario\InventarioService;
use App\Support\Auth;
use App\Support\Money;

$compras = new ComprasService($db, new InventarioService($db));
$tiendaId = Auth::tiendaId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['accion'] ?? '') {
            case 'proveedor':
                $rtn = preg_replace('/\D/', '', $_POST['rtn'] ?? '');
                if (trim($_POST['nombre'] ?? '') === '') {
                    throw new DomainException('Escriba el nombre del proveedor.');
                }
                if ($rtn !== '' && strlen($rtn) !== 14) {
                    throw new DomainException('El RTN del proveedor debe tener 14 dígitos.');
                }
                try {
                    $db->prepare('INSERT INTO proveedores (rtn, nombre, telefono, email) VALUES (?,?,?,?)')
                        ->execute([$rtn ?: null, trim($_POST['nombre']), trim($_POST['telefono'] ?? '') ?: null, trim($_POST['email'] ?? '') ?: null]);
                } catch (PDOException $e) {
                    throw new DomainException('Ya existe un proveedor con ese RTN.');
                }
                redirigir('compras', 'Proveedor agregado.');
            case 'crear':
                $lineas = [];
                foreach ($_POST['lineas'] ?? [] as $l) {
                    $lineas[] = ['producto_id' => $l['producto_id'] ?? 0, 'cantidad' => $l['cantidad'] ?? '',
                        'costo' => ($l['costo'] ?? '') === '' ? -1 : Money::aCentavos($l['costo']), 'tipo_impuesto' => $l['tipo_impuesto'] ?? 'G15'];
                }
                $recibir = ($_POST['guardar'] ?? '') === 'recibir';
                $id = $compras->crear($tiendaId, $usuario['id'], ['lineas' => $lineas] + $_POST, $recibir);
                redirigir('compras&ver=' . $id, $recibir ? "Compra #$id recibida: el inventario y los costos se actualizaron." : "Compra #$id guardada como borrador.");
            case 'recibir':
                $compras->recibir((int) $_POST['compra_id'], $usuario['id']);
                redirigir('compras&ver=' . (int) $_POST['compra_id'], 'Compra recibida: el inventario y los costos se actualizaron.');
            case 'anular':
                $compras->anular((int) $_POST['compra_id']);
                redirigir('compras', 'Compra anulada.');
        }
    } catch (DomainException $e) {
        $_SESSION['form_compra'] = $_POST;
        redirigir('compras' . (($_POST['accion'] ?? '') === 'crear' ? '&nueva=1' : ''), $e->getMessage());
    }
}

$ver = !empty($_GET['ver']) ? $compras->obtener((int) $_GET['ver']) : null;
if ($ver && $usuario['tienda_id'] !== null && (int) $ver['tienda_id'] !== $usuario['tienda_id']) {
    $ver = null;
}
$st = $db->prepare(
    'SELECT c.*, p.nombre AS proveedor FROM compras c JOIN proveedores p ON p.id = c.proveedor_id
     WHERE c.tienda_id = ? ORDER BY c.id DESC LIMIT 50'
);
$st->execute([$tiendaId]);
$lista = $st->fetchAll();
$proveedores = $db->query('SELECT * FROM proveedores WHERE activo = 1 ORDER BY nombre')->fetchAll();
$productos = $db->query('SELECT p.id, p.nombre, p.sku, p.tipo_impuesto FROM productos p WHERE p.activo = 1 AND p.controla_stock = 1 ORDER BY p.nombre')->fetchAll();
$st = $db->prepare('SELECT producto_id, costo_promedio FROM inventario WHERE tienda_id = ?');
$st->execute([$tiendaId]);
$costos = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$f = $_SESSION['form_compra'] ?? [];
unset($_SESSION['form_compra']);
$estadoPill = ['BORRADOR' => 'alerta', 'RECIBIDA' => 'ok', 'ANULADA' => 'mal'];

require __DIR__ . '/_layout.php';
layout_inicio('Compras', 'compras', $db);
?>
<div class="fila-entre">
  <h1>Compras a proveedores</h1>
  <a class="btn primario" href="index.php?p=compras&nueva=1#nueva">+ Nueva compra</a>
</div>

<?php if ($ver): ?>
<section class="tarjeta">
  <div class="fila-entre">
    <h2>Compra #<?= (int) $ver['id'] ?> · <?= e($ver['proveedor']) ?> <span class="pill <?= $estadoPill[$ver['estado']] ?>"><?= e($ver['estado']) ?></span></h2>
    <?php if ($ver['estado'] === 'BORRADOR'): ?>
      <div class="fila">
        <form method="post"><input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="accion" value="anular">
          <input type="hidden" name="compra_id" value="<?= (int) $ver['id'] ?>"><button class="btn texto">Anular</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="accion" value="recibir">
          <input type="hidden" name="compra_id" value="<?= (int) $ver['id'] ?>"><button class="btn primario">Recibir mercadería</button></form>
      </div>
    <?php endif; ?>
  </div>
  <p class="chico tenue">Factura proveedor <b class="mono"><?= e($ver['numero_documento']) ?></b> del <?= e(date('d/m/Y', strtotime($ver['fecha_documento']))) ?>
    · RTN <?= e($ver['proveedor_rtn'] ?: '—') ?> · <?= e($ver['tienda']) ?> · registrada por <?= e($ver['usuario']) ?>
    <?= $ver['recibida_en'] ? ' · recibida ' . e(date('d/m/Y H:i', strtotime($ver['recibida_en']))) . ' por ' . e($ver['receptor']) : '' ?></p>
  <div class="tabla-scroll"><table class="tabla">
    <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Costo unit.</th><th>Imp.</th><th class="num">Subtotal</th><th class="num">ISV</th></tr></thead>
    <tbody><?php foreach ($ver['lineas'] as $l): ?>
      <tr><td><?= e($l['nombre']) ?> <small class="tenue mono"><?= e($l['sku']) ?></small></td>
        <td class="num"><?= InventarioService::cant($l['cantidad']) ?></td><td class="num"><?= lps($l['costo_unitario']) ?></td>
        <td><?= e($l['tipo_impuesto']) ?></td><td class="num"><?= lps($l['subtotal']) ?></td><td class="num"><?= lps($l['isv']) ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot>
      <tr><td colspan="4">Subtotal</td><td colspan="2" class="num"><?= lps($ver['subtotal']) ?></td></tr>
      <tr><td colspan="4">ISV (crédito fiscal)</td><td colspan="2" class="num"><?= lps($ver['isv']) ?></td></tr>
      <tr><td colspan="4"><b>Total</b></td><td colspan="2" class="num"><b><?= lps($ver['total']) ?></b></td></tr>
    </tfoot>
  </table></div>
  <?php if ($ver['notas']): ?><p class="chico">Notas: <?= e($ver['notas']) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($_GET['nueva'])): ?>
<section class="tarjeta" id="nueva">
  <h2>Nueva compra</h2>
  <?php if (!$proveedores): ?><p class="aviso">Primero agregue un proveedor (abajo).</p><?php endif; ?>
  <form method="post" id="form-compra">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="accion" value="crear">
    <div class="fila">
      <label>Proveedor <select name="proveedor_id" required id="c-prov">
        <?php foreach ($proveedores as $p): ?><option value="<?= $p['id'] ?>" <?= (int) ($f['proveedor_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?>
      </select></label>
      <label>No. factura del proveedor <input name="numero_documento" required maxlength="40" value="<?= e($f['numero_documento'] ?? '') ?>" placeholder="000-001-01-00012345" class="mono" id="c-doc"></label>
      <label>Fecha <input type="date" name="fecha_documento" required max="<?= date('Y-m-d') ?>" value="<?= e($f['fecha_documento'] ?? date('Y-m-d')) ?>" id="c-fecha"></label>
    </div>
    <div class="tabla-scroll">
      <table class="tabla lineas-compra">
        <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Costo unit. sin ISV (L)</th><th>Imp.</th><th class="num">Subtotal</th><th></th></tr></thead>
        <tbody id="c-lineas"></tbody>
      </table>
    </div>
    <button type="button" class="btn" id="c-agregar">+ Agregar producto</button>
    <dl class="totales angosto">
      <dt>Subtotal</dt><dd id="c-sub">L 0.00</dd>
      <dt>ISV</dt><dd id="c-isv">L 0.00</dd>
      <dt class="total">Total factura</dt><dd class="total" id="c-total">L 0.00</dd>
    </dl>
    <label>Notas <input name="notas" maxlength="255" value="<?= e($f['notas'] ?? '') ?>" id="c-notas"></label>
    <div class="fila">
      <button class="btn" name="guardar" value="borrador">Guardar borrador</button>
      <button class="btn primario" name="guardar" value="recibir">Guardar y recibir mercadería</button>
    </div>
    <p class="tenue chico">El total debe coincidir con la factura física del proveedor. Al recibir, la existencia sube y el costo promedio se recalcula.</p>
  </form>
  <template id="tpl-linea">
    <tr>
      <td><select data-c="producto_id" required aria-label="Producto"><option value="">Seleccione…</option>
        <?php foreach ($productos as $p): ?><option value="<?= $p['id'] ?>" data-imp="<?= $p['tipo_impuesto'] ?>" data-costo="<?= (int) ($costos[$p['id']] ?? 0) ?>"><?= e($p['nombre']) ?> · <?= e($p['sku']) ?></option><?php endforeach; ?>
      </select></td>
      <td class="num"><input data-c="cantidad" inputmode="decimal" class="corto" required aria-label="Cantidad"></td>
      <td class="num"><input data-c="costo" inputmode="decimal" class="corto" required aria-label="Costo"></td>
      <td><select data-c="tipo_impuesto" aria-label="Impuesto"><option>G15</option><option>G18</option><option>EXE</option></select></td>
      <td class="num sub">L 0.00</td>
      <td><button type="button" class="btn texto quitar" aria-label="Quitar línea">✕</button></td>
    </tr>
  </template>
  <script>
    (() => {
      const lps = (c) => 'L ' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const tasas = { G15: 0.15, G18: 0.18, EXE: 0 };
      const cuerpo = document.getElementById('c-lineas');
      let n = 0;
      const previas = <?= json_encode(array_values($f['lineas'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      const agregar = (v = {}) => {
        const fila = document.getElementById('tpl-linea').content.firstElementChild.cloneNode(true);
        const i = n++;
        fila.querySelectorAll('[data-c]').forEach((el) => { el.name = `lineas[${i}][${el.dataset.c}]`; if (v[el.dataset.c] !== undefined) el.value = v[el.dataset.c]; });
        cuerpo.appendChild(fila);
        calc();
      };
      // Al elegir producto sugiere su impuesto y el último costo promedio de la tienda
      cuerpo.addEventListener('change', (e) => {
        if (e.target.dataset.c !== 'producto_id') return;
        const op = e.target.selectedOptions[0];
        const fila = e.target.closest('tr');
        fila.querySelector('[data-c=tipo_impuesto]').value = op.dataset.imp || 'G15';
        const costo = fila.querySelector('[data-c=costo]');
        if (!costo.value && +op.dataset.costo) costo.value = (op.dataset.costo / 100).toFixed(2);
        calc();
      });
      cuerpo.addEventListener('click', (e) => { if (e.target.closest('.quitar')) { e.target.closest('tr').remove(); calc(); } });
      cuerpo.addEventListener('input', calc);
      function calc() {
        let sub = 0, isv = 0;
        cuerpo.querySelectorAll('tr').forEach((tr) => {
          const cant = parseFloat(tr.querySelector('[data-c=cantidad]').value) || 0;
          const costo = Math.round((parseFloat(tr.querySelector('[data-c=costo]').value) || 0) * 100);
          const s = Math.round(cant * costo);
          sub += s; isv += Math.round(s * tasas[tr.querySelector('[data-c=tipo_impuesto]').value]);
          tr.querySelector('.sub').textContent = lps(s);
        });
        document.getElementById('c-sub').textContent = lps(sub);
        document.getElementById('c-isv').textContent = lps(isv);
        document.getElementById('c-total').textContent = lps(sub + isv);
      }
      document.getElementById('c-agregar').addEventListener('click', () => agregar());
      (previas.length ? previas : [{}, {}]).forEach(agregar);
    })();
  </script>
</section>
<?php endif; ?>

<div class="columnas">
  <section>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>#</th><th>Proveedor / documento</th><th>Fecha</th><th>Estado</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $c): ?>
        <tr>
          <td><a href="index.php?p=compras&ver=<?= (int) $c['id'] ?>">#<?= (int) $c['id'] ?></a></td>
          <td><?= e($c['proveedor']) ?><br><small class="mono tenue"><?= e($c['numero_documento']) ?></small></td>
          <td><?= e(date('d/m/Y', strtotime($c['fecha_documento']))) ?></td>
          <td><span class="pill <?= $estadoPill[$c['estado']] ?>"><?= e($c['estado']) ?></span></td>
          <td class="num"><?= lps($c['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="5" class="tenue">Aún no hay compras en esta tienda.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
  <section class="tarjeta">
    <h2>Proveedores</h2>
    <ul class="lista-simple">
      <?php foreach ($proveedores as $p): ?><li><b><?= e($p['nombre']) ?></b> <small class="tenue">RTN <?= e($p['rtn'] ?: '—') ?> · <?= e($p['telefono'] ?? '') ?></small></li><?php endforeach; ?>
    </ul>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="accion" value="proveedor">
      <label>Nombre <input name="nombre" required maxlength="150" id="pv-nombre"></label>
      <div class="fila">
        <label>RTN <input name="rtn" maxlength="16" inputmode="numeric" id="pv-rtn"></label>
        <label>Teléfono <input name="telefono" maxlength="30" id="pv-tel"></label>
      </div>
      <label>Correo <input type="email" name="email" maxlength="120" id="pv-email"></label>
      <button class="btn">Agregar proveedor</button>
    </form>
  </section>
</div>
<?php layout_fin(); ?>
