<?php
use App\Catalogo\CatalogoRepository;
use App\Support\Auth;
use App\Support\Money;

$repo = new CatalogoRepository($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['alternar'])) {
        $db->prepare('UPDATE combos SET activo = 1 - activo WHERE id = ?')->execute([(int) $_POST['alternar']]);
        redirigir('combos', 'Estado del combo actualizado.');
    }

    $grupos = array_filter($_POST['grupos'] ?? [], fn ($g) => trim($g['nombre'] ?? '') !== ''
        && array_filter($g['opciones'] ?? [], fn ($o) => !empty($o['on'])));
    $nombre = trim($_POST['nombre'] ?? '');
    $precio = Money::aCentavos($_POST['precio'] ?? 0);
    if ($nombre === '' || $precio <= 0 || !$grupos) {
        redirigir('combos', 'Complete nombre, precio y al menos un grupo con opciones.');
    }

    $db->beginTransaction();
    $db->prepare('INSERT INTO combos (categoria_id, nombre, descripcion, precio_centavos, tipo_impuesto) VALUES (?,?,?,?,?)')
       ->execute([(int) $_POST['categoria_id'], $nombre, trim($_POST['descripcion'] ?? ''), $precio,
                  in_array($_POST['tipo_impuesto'] ?? '', ['G15', 'G18', 'EXE'], true) ? $_POST['tipo_impuesto'] : 'G15']);
    $comboId = (int) $db->lastInsertId();
    $stG = $db->prepare('INSERT INTO combo_grupos (combo_id, nombre, min_sel, max_sel, orden) VALUES (?,?,?,?,?)');
    $stO = $db->prepare('INSERT INTO combo_opciones (grupo_id, producto_id, recargo_centavos, por_defecto) VALUES (?,?,?,?)');
    $orden = 0;
    foreach ($grupos as $g) {
        $min = max(0, (int) $g['min']);
        $stG->execute([$comboId, trim($g['nombre']), $min, max($min, 1, (int) $g['max']), ++$orden]);
        $grupoId = (int) $db->lastInsertId();
        foreach ($g['opciones'] as $pid => $o) {
            if (!empty($o['on'])) {
                $stO->execute([$grupoId, (int) $pid, Money::aCentavos($o['recargo'] ?: 0), (int) (($g['defecto'] ?? '') == $pid)]);
            }
        }
    }
    $db->commit();
    redirigir('combos', "Combo \"$nombre\" creado.");
}

$combos = $repo->combos(false);
$productos = $repo->productos(Auth::tiendaId(), false);
$categorias = $db->query('SELECT * FROM categorias ORDER BY orden')->fetchAll();

require __DIR__ . '/_layout.php';
layout_inicio('Combos', 'combos', $db);
?>
<h1>Combos</h1>
<div class="columnas">
  <section>
    <?php foreach ($combos as $c): ?>
      <article class="tarjeta <?= $c['activo'] ? '' : 'inactivo' ?>">
        <header class="fila-entre">
          <h3><?= e($c['nombre']) ?> <span class="precio"><?= lps($c['precio']) ?></span></h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
            <button class="btn texto" name="alternar" value="<?= $c['id'] ?>"><?= $c['activo'] ? 'Desactivar' : 'Activar' ?></button>
          </form>
        </header>
        <p class="tenue"><?= e($c['descripcion']) ?></p>
        <ul class="grupos">
          <?php foreach ($c['grupos'] as $g): ?>
            <li><b><?= e($g['nombre']) ?></b> <small>(<?= $g['min'] ?>–<?= $g['max'] ?>)</small>:
              <?= implode(', ', array_map(fn ($o) => e($o['nombre']) . ($o['recargo'] ? ' +' . lps($o['recargo']) : '') . ($o['por_defecto'] ? ' ★' : ''), $g['opciones'])) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="tarjeta">
    <h2>Nuevo combo</h2>
    <form method="post" id="form-nuevo-combo">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <label>Nombre <input name="nombre" required placeholder="Combo Mega"></label>
      <label>Descripción <input name="descripcion" placeholder="Hamburguesa doble + papas grandes + refresco"></label>
      <div class="fila">
        <label>Precio (L, con ISV) <input name="precio" inputmode="decimal" required placeholder="199.00"></label>
        <label>Impuesto
          <select name="tipo_impuesto"><option value="G15">ISV 15%</option><option value="G18">ISV 18%</option><option value="EXE">Exento</option></select>
        </label>
        <label>Categoría
          <select name="categoria_id"><?php foreach ($categorias as $cat): ?><option value="<?= $cat['id'] ?>"><?= e($cat['nombre']) ?></option><?php endforeach; ?></select>
        </label>
      </div>

      <div id="grupos-nuevos"></div>
      <button type="button" class="btn" id="agregar-grupo">+ Agregar grupo de selección</button>
      <button class="btn primario">Guardar combo</button>
    </form>

    <template id="tpl-grupo">
      <fieldset class="grupo">
        <legend>Grupo <span class="n"></span></legend>
        <div class="fila">
          <label>Nombre <input data-campo="nombre" placeholder="Elige tu bebida"></label>
          <label>Mín. <input data-campo="min" type="number" min="0" value="1"></label>
          <label>Máx. <input data-campo="max" type="number" min="1" value="1"></label>
        </div>
        <div class="opciones-tabla">
          <?php foreach ($productos as $p): ?>
            <div class="opcion-fila">
              <label><input type="checkbox" data-op="<?= $p['id'] ?>" data-sub="on" value="1"> <?= e($p['nombre']) ?></label>
              <input data-op="<?= $p['id'] ?>" data-sub="recargo" inputmode="decimal" placeholder="Recargo L" aria-label="Recargo">
              <label class="chico"><input type="radio" data-def="<?= $p['id'] ?>"> Defecto</label>
            </div>
          <?php endforeach; ?>
        </div>
      </fieldset>
    </template>
  </section>
</div>
<script>
  // Cada grupo nuevo se clona de la plantilla y se le asignan nombres grupos[i][...]
  (() => {
    let i = 0;
    const agregar = () => {
      const n = i++;
      const nodo = document.getElementById('tpl-grupo').content.cloneNode(true);
      nodo.querySelector('.n').textContent = n + 1;
      nodo.querySelectorAll('[data-campo]').forEach((el) => { el.name = `grupos[${n}][${el.dataset.campo}]`; });
      nodo.querySelectorAll('[data-op]').forEach((el) => { el.name = `grupos[${n}][opciones][${el.dataset.op}][${el.dataset.sub}]`; });
      nodo.querySelectorAll('[data-def]').forEach((el) => { el.name = `grupos[${n}][defecto]`; el.value = el.dataset.def; });
      document.getElementById('grupos-nuevos').appendChild(nodo);
    };
    document.getElementById('agregar-grupo').addEventListener('click', agregar);
    agregar();
  })();
</script>
<?php layout_fin(); ?>
