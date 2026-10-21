<?php
use App\Support\Auth;
use App\Support\Money;

$tipos = [
    'MIX_PRECIO' => 'Mix & match: N artículos por precio fijo',
    'NXM' => 'Lleva N paga M (2x1, 3x2…)',
    'PORCENTAJE' => 'Porcentaje de descuento',
    'X_LLEVA_Y' => 'Compra X y lleva Y con % (100 = gratis)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['alternar'])) {
        $db->prepare('UPDATE promociones SET activa = 1 - activa WHERE id = ?')->execute([(int) $_POST['alternar']]);
        redirigir('promociones', 'Estado de la promoción actualizado.');
    }
    $tipo = array_key_exists($_POST['tipo'] ?? '', $tipos) ? $_POST['tipo'] : null;
    $itemsA = $_POST['items_a'] ?? [];
    $itemsB = $_POST['items_b'] ?? [];
    if (!$tipo || trim($_POST['nombre'] ?? '') === '' || !$itemsA || ($tipo === 'X_LLEVA_Y' && !$itemsB)) {
        redirigir('promociones', 'Complete nombre, tipo y artículos participantes.');
    }
    $dias = implode('', array_intersect(['1', '2', '3', '4', '5', '6', '7'], $_POST['dias'] ?? [])) ?: '1234567';

    $db->beginTransaction();
    $db->prepare(
        'INSERT INTO promociones (nombre, tipo, cantidad_n, cantidad_m, precio_centavos, porcentaje, prioridad,
                                  fecha_inicio, fecha_fin, dias_semana, hora_inicio, hora_fin)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        trim($_POST['nombre']), $tipo,
        ($_POST['cantidad_n'] ?? '') !== '' ? (int) $_POST['cantidad_n'] : null,
        ($_POST['cantidad_m'] ?? '') !== '' ? (int) $_POST['cantidad_m'] : null,
        ($_POST['precio'] ?? '') !== '' ? Money::aCentavos($_POST['precio']) : null,
        ($_POST['porcentaje'] ?? '') !== '' ? (float) $_POST['porcentaje'] : null,
        (int) ($_POST['prioridad'] ?? 100),
        $_POST['fecha_inicio'], $_POST['fecha_fin'], $dias,
        ($_POST['hora_inicio'] ?: '00:00') . ':00', ($_POST['hora_fin'] ?: '23:59') . ':59',
    ]);
    $promoId = (int) $db->lastInsertId();

    // Cada artículo llega como "P:12" (producto), "C:3" (combo) o "K:2" (categoría)
    $st = $db->prepare('INSERT INTO promocion_items (promocion_id, grupo, producto_id, combo_id, categoria_id) VALUES (?,?,?,?,?)');
    foreach (['A' => $itemsA, 'B' => $tipo === 'X_LLEVA_Y' ? $itemsB : []] as $grupo => $lista) {
        foreach ($lista as $ref) {
            [$clase, $id] = explode(':', $ref) + [null, 0];
            $st->execute([$promoId, $grupo, $clase === 'P' ? (int) $id : null, $clase === 'C' ? (int) $id : null, $clase === 'K' ? (int) $id : null]);
        }
    }
    $stT = $db->prepare('INSERT INTO promocion_tiendas (promocion_id, tienda_id) VALUES (?, ?)');
    foreach ($_POST['tiendas'] ?? [] as $t) {
        $stT->execute([$promoId, (int) $t]);
    }
    $db->commit();
    redirigir('promociones', 'Promoción creada.');
}

$promos = $db->query(
    "SELECT p.*,
       (SELECT GROUP_CONCAT(CONCAT(i.grupo, ': ', COALESCE(pr.nombre, co.nombre, CONCAT('Categoría ', ca.nombre))) SEPARATOR ' · ')
          FROM promocion_items i LEFT JOIN productos pr ON pr.id = i.producto_id
          LEFT JOIN combos co ON co.id = i.combo_id LEFT JOIN categorias ca ON ca.id = i.categoria_id
         WHERE i.promocion_id = p.id) AS articulos,
       (SELECT GROUP_CONCAT(t.nombre SEPARATOR ', ') FROM promocion_tiendas pt JOIN tiendas t ON t.id = pt.tienda_id
         WHERE pt.promocion_id = p.id) AS tiendas
     FROM promociones p ORDER BY p.activa DESC, p.prioridad"
)->fetchAll();
$opciones = [
    'Categorías' => $db->query("SELECT CONCAT('K:', id) v, nombre FROM categorias ORDER BY orden")->fetchAll(),
    'Combos' => $db->query("SELECT CONCAT('C:', id) v, nombre FROM combos ORDER BY nombre")->fetchAll(),
    'Productos' => $db->query("SELECT CONCAT('P:', id) v, nombre FROM productos ORDER BY nombre")->fetchAll(),
];
$tiendas = $db->query('SELECT id, nombre FROM tiendas WHERE activa = 1')->fetchAll();
$diasNombre = ['1' => 'Lun', '2' => 'Mar', '3' => 'Mié', '4' => 'Jue', '5' => 'Vie', '6' => 'Sáb', '7' => 'Dom'];
$selector = function (string $nombre) use ($opciones): string {
    $h = "<select name=\"{$nombre}[]\" multiple size=\"8\">";
    foreach ($opciones as $grupo => $lista) {
        $h .= '<optgroup label="' . e($grupo) . '">';
        foreach ($lista as $o) {
            $h .= '<option value="' . e($o['v']) . '">' . e($o['nombre']) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h . '</select>';
};

require __DIR__ . '/_layout.php';
layout_inicio('Promociones', 'promociones', $db);
?>
<h1>Promociones mix &amp; match</h1>
<div class="columnas">
  <section>
    <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Promoción</th><th>Regla</th><th>Vigencia</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($promos as $p): ?>
        <tr class="<?= $p['activa'] ? '' : 'inactivo' ?>">
          <td><b><?= e($p['nombre']) ?></b><br><small class="tenue"><?= e($p['articulos']) ?></small></td>
          <td><?= e($tipos[$p['tipo']]) ?><br><small>
            <?= match ($p['tipo']) {
                'MIX_PRECIO' => $p['cantidad_n'] . ' por ' . lps($p['precio_centavos']),
                'NXM' => 'Lleva ' . $p['cantidad_n'] . ' paga ' . $p['cantidad_m'],
                default => (float) $p['porcentaje'] . '%',
            } ?> · prioridad <?= (int) $p['prioridad'] ?></small></td>
          <td><small><?= e($p['fecha_inicio']) ?> → <?= e($p['fecha_fin']) ?><br>
            <?= e(implode(' ', array_map(fn ($d) => $diasNombre[$d], str_split($p['dias_semana'])))) ?>
            · <?= e(substr($p['hora_inicio'], 0, 5)) ?>–<?= e(substr($p['hora_fin'], 0, 5)) ?><br>
            <?= e($p['tiendas'] ?: 'Todas las tiendas') ?></small></td>
          <td><form method="post"><input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
            <button class="btn texto" name="alternar" value="<?= $p['id'] ?>"><?= $p['activa'] ? 'Pausar' : 'Activar' ?></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>

  <section class="tarjeta">
    <h2>Nueva promoción</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <label>Nombre <input name="nombre" required placeholder="3 postres por L 75"></label>
      <label>Tipo
        <select name="tipo" id="promo-tipo"><?php foreach ($tipos as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select>
      </label>
      <div class="fila">
        <label data-para="MIX_PRECIO NXM">Lleva (N) <input type="number" name="cantidad_n" min="1"></label>
        <label data-para="NXM">Paga (M) <input type="number" name="cantidad_m" min="0"></label>
        <label data-para="MIX_PRECIO">Precio del grupo (L) <input name="precio" inputmode="decimal"></label>
        <label data-para="PORCENTAJE X_LLEVA_Y">Porcentaje % <input name="porcentaje" inputmode="decimal"></label>
        <label>Prioridad <input type="number" name="prioridad" value="100"></label>
      </div>
      <label><span id="lbl-a">Artículos participantes</span> <?= $selector('items_a') ?></label>
      <label data-para="X_LLEVA_Y">Artículos premio (Y) <?= $selector('items_b') ?></label>
      <div class="fila">
        <label>Desde <input type="date" name="fecha_inicio" required value="<?= date('Y-m-d') ?>"></label>
        <label>Hasta <input type="date" name="fecha_fin" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></label>
        <label>Hora inicio <input type="time" name="hora_inicio" value="00:00"></label>
        <label>Hora fin <input type="time" name="hora_fin" value="23:59"></label>
      </div>
      <fieldset class="chips"><legend>Días</legend>
        <?php foreach ($diasNombre as $n => $d): ?><label><input type="checkbox" name="dias[]" value="<?= $n ?>" checked> <?= $d ?></label><?php endforeach; ?>
      </fieldset>
      <fieldset class="chips"><legend>Tiendas (ninguna = todas)</legend>
        <?php foreach ($tiendas as $t): ?><label><input type="checkbox" name="tiendas[]" value="<?= $t['id'] ?>"> <?= e($t['nombre']) ?></label><?php endforeach; ?>
      </fieldset>
      <button class="btn primario">Guardar promoción</button>
    </form>
  </section>
</div>
<script>
  // Muestra solo los campos que aplican al tipo de promoción elegido
  (() => {
    const tipo = document.getElementById('promo-tipo');
    const sync = () => {
      document.querySelectorAll('[data-para]').forEach((el) => { el.hidden = !el.dataset.para.split(' ').includes(tipo.value); });
      document.getElementById('lbl-a').textContent = tipo.value === 'X_LLEVA_Y' ? 'Artículos que disparan (X)' : 'Artículos participantes';
    };
    tipo.addEventListener('change', sync);
    sync();
  })();
</script>
<?php layout_fin(); ?>
