<?php
use App\Catalogo\ProductosService;
use App\Inventario\InventarioService;
use App\Support\Auth;

$servicio = new ProductosService($db, new InventarioService($db));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['alternar'])) {
            $db->prepare('UPDATE productos SET activo = 1 - activo WHERE id = ?')->execute([(int) $_POST['alternar']]);
            redirigir('productos', 'Estado del producto actualizado.');
        }
        if (($_POST['accion'] ?? '') === 'importar') {
            $csv = !empty($_FILES['archivo']['tmp_name']) && is_uploaded_file($_FILES['archivo']['tmp_name'])
                ? (string) file_get_contents($_FILES['archivo']['tmp_name'])
                : (string) ($_POST['csv'] ?? '');
            $r = $servicio->importarCsv($csv);
            redirigir('productos', "Importación lista: {$r['creados']} creados, {$r['actualizados']} actualizados.");
        }
        $datos = $_POST;
        if (($datos['categoria_id'] ?? '') === 'nueva') {
            unset($datos['categoria_id']);
            $datos['categoria'] = $datos['categoria_nueva'] ?? '';
        }
        $id = $servicio->guardar($datos);
        redirigir('productos', 'Producto "' . trim($_POST['nombre']) . '" guardado.' . (empty($_POST['id']) ? ' Ya puede cargar existencias con una compra.' : ''));
    } catch (DomainException $e) {
        $_SESSION['form_producto'] = $_POST;
        redirigir('productos' . (!empty($_POST['id']) ? '&editar=' . (int) $_POST['id'] : ''), $e->getMessage());
    }
}

$buscar = trim($_GET['q'] ?? '');
$st = $db->prepare(
    "SELECT p.*, c.nombre AS categoria FROM productos p JOIN categorias c ON c.id = p.categoria_id
     WHERE (? = '' OR p.nombre LIKE ? OR p.sku LIKE ?) ORDER BY p.activo DESC, c.orden, p.nombre"
);
$st->execute([$buscar, "%$buscar%", "%$buscar%"]);
$productos = $st->fetchAll();
$categorias = $db->query('SELECT * FROM categorias ORDER BY orden')->fetchAll();

$editar = null;
if (!empty($_GET['editar'])) {
    $st = $db->prepare('SELECT * FROM productos WHERE id = ?');
    $st->execute([(int) $_GET['editar']]);
    $editar = $st->fetch() ?: null;
}
$f = $_SESSION['form_producto'] ?? ($editar ? [
    'id' => $editar['id'], 'sku' => $editar['sku'], 'nombre' => $editar['nombre'], 'categoria_id' => $editar['categoria_id'],
    'precio' => number_format($editar['precio_centavos'] / 100, 2, '.', ''), 'impuesto' => $editar['tipo_impuesto'],
    'vendible_solo' => $editar['vendible_solo'], 'controla_stock' => $editar['controla_stock'],
] : ['vendible_solo' => 1, 'controla_stock' => 1]);
unset($_SESSION['form_producto']);
$imp = ['G15' => 'ISV 15%', 'G18' => 'ISV 18%', 'EXE' => 'Exento'];

require __DIR__ . '/_layout.php';
layout_inicio('Productos', 'productos', $db);
?>
<h1>Productos</h1>
<div class="columnas">
  <section>
    <form method="get" class="fila buscar">
      <input type="hidden" name="p" value="productos">
      <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar por nombre o SKU" aria-label="Buscar" id="buscar-producto">
      <button class="btn">Buscar</button>
    </form>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>SKU</th><th>Producto</th><th class="num">Precio</th><th>Impuesto</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($productos as $p): ?>
        <tr class="<?= $p['activo'] ? '' : 'inactivo' ?>">
          <td class="mono chico"><?= e($p['sku']) ?></td>
          <td><b><?= e($p['nombre']) ?></b><br><small class="tenue"><?= e($p['categoria']) ?>
            <?= $p['vendible_solo'] ? '' : ' · solo en combos' ?><?= $p['controla_stock'] ? '' : ' · sin inventario' ?></small></td>
          <td class="num"><?= lps($p['precio_centavos']) ?></td>
          <td><?= e($imp[$p['tipo_impuesto']]) ?></td>
          <td class="num">
            <a href="index.php?p=productos&editar=<?= (int) $p['id'] ?>">Editar</a>
            <form method="post" class="en-linea"><input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
              <button class="btn texto" name="alternar" value="<?= (int) $p['id'] ?>"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$productos): ?><tr><td colspan="5" class="tenue">No hay productos que coincidan.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>

  <div>
    <section class="tarjeta" id="form-producto">
      <h2><?= !empty($f['id']) ? 'Editar producto' : 'Nuevo producto' ?></h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <?php if (!empty($f['id'])): ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><?php endif; ?>
        <div class="fila">
          <label>SKU <input name="sku" required maxlength="30" value="<?= e($f['sku'] ?? '') ?>" placeholder="HAM-BBQ" class="mono" id="prod-sku"></label>
          <label>Precio de venta (L, con ISV) <input name="precio" required inputmode="decimal" value="<?= e($f['precio'] ?? '') ?>" placeholder="145.00" id="prod-precio"></label>
        </div>
        <label>Nombre <input name="nombre" required maxlength="120" value="<?= e($f['nombre'] ?? '') ?>" placeholder="Hamburguesa BBQ" id="prod-nombre"></label>
        <div class="fila">
          <label>Categoría
            <select name="categoria_id" id="prod-cat" onchange="document.getElementById('cat-nueva').hidden = this.value !== 'nueva'">
              <?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= (int) ($f['categoria_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
              <option value="nueva">+ Nueva categoría…</option>
            </select>
          </label>
          <label>Impuesto
            <select name="impuesto" id="prod-imp"><?php foreach ($imp as $k => $t): ?><option value="<?= $k ?>" <?= ($f['impuesto'] ?? 'G15') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
          </label>
        </div>
        <label id="cat-nueva" hidden>Nombre de la nueva categoría <input name="categoria_nueva" id="prod-cat-nueva"></label>
        <fieldset class="chips">
          <label><input type="hidden" name="vendible_solo" value="0"><input type="checkbox" name="vendible_solo" value="1" <?= !empty($f['vendible_solo']) ? 'checked' : '' ?>> Se vende solo (si no, solo dentro de combos)</label>
          <label><input type="hidden" name="controla_stock" value="0"><input type="checkbox" name="controla_stock" value="1" <?= !empty($f['controla_stock']) ? 'checked' : '' ?>> Controla inventario</label>
        </fieldset>
        <div class="fila">
          <?php if (!empty($f['id'])): ?><a class="btn texto" href="index.php?p=productos">Cancelar</a><?php endif; ?>
          <button class="btn primario">Guardar producto</button>
        </div>
      </form>
    </section>

    <section class="tarjeta">
      <h2>Carga masiva (CSV / Excel)</h2>
      <p class="tenue chico">Guarde su Excel como CSV. Si el SKU ya existe se actualiza; si no, se crea. Si una fila tiene error no se importa nada y se le indica qué corregir.</p>
      <pre class="mono ejemplo-csv">sku,nombre,categoria,precio,impuesto,vendible_solo,controla_stock,minimo
HAM-BBQ,Hamburguesa BBQ,Hamburguesas,145.00,G15,1,1,20
CER-IMP,Cerveza Importada,Cervezas,75.00,G18,1,1,24
SAL-EXT,Salsa extra,Acompañantes,10.00,G15,0,0,</pre>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="accion" value="importar">
        <label>Archivo CSV <input type="file" name="archivo" accept=".csv,text/csv" id="csv-archivo"></label>
        <label>…o pegue el contenido aquí <textarea name="csv" rows="5" class="mono" id="csv-texto" placeholder="sku,nombre,categoria,precio,impuesto"></textarea></label>
        <button class="btn primario">Importar</button>
      </form>
    </section>
  </div>
</div>
<?php layout_fin(); ?>
