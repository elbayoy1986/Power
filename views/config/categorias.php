<?php
use App\Support\Auth;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $orden = (int) ($_POST['orden'] ?? 0);
    if ($nombre === '' || mb_strlen($nombre) > 80) {
        config_volver('categorias', 'Escriba el nombre de la categoría (máx. 80 caracteres).', true);
    }
    $st = $db->prepare('SELECT 1 FROM categorias WHERE nombre = ? AND id <> ?');
    $st->execute([$nombre, $id]);
    if ($st->fetchColumn()) {
        config_volver('categorias', "Ya existe la categoría \"$nombre\".", true);
    }
    if ($id) {
        $db->prepare('UPDATE categorias SET nombre = ?, orden = ? WHERE id = ?')->execute([$nombre, $orden, $id]);
        config_volver('categorias', 'Categoría actualizada.');
    }
    $db->prepare('INSERT INTO categorias (nombre, orden) SELECT ?, COALESCE(MAX(orden), 0) + 1 FROM categorias')->execute([$nombre]);
    config_volver('categorias', "Categoría \"$nombre\" creada.");
}

$categorias = $db->query(
    'SELECT c.*, (SELECT COUNT(*) FROM productos p WHERE p.categoria_id = c.id AND p.activo = 1) AS productos,
            (SELECT COUNT(*) FROM combos k WHERE k.categoria_id = c.id AND k.activo = 1) AS combos
     FROM categorias c ORDER BY c.orden, c.nombre'
)->fetchAll();

config_inicio('categorias', $db);
?>
<h1>Categorías del menú</h1>
<p class="tenue">Son las pestañas que ve el cajero en la pantalla Vender. El <b>orden</b> decide cuál aparece primero.</p>
<div class="columnas">
  <section>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>Categoría</th><th class="num">Orden</th><th class="num">Productos</th><th class="num">Combos</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($categorias as $c): $fid = 'cat-' . (int) $c['id']; ?>
        <tr>
          <td><input form="<?= $fid ?>" name="nombre" value="<?= e($c['nombre']) ?>" required maxlength="80" aria-label="Nombre de <?= e($c['nombre']) ?>"></td>
          <td class="num"><input form="<?= $fid ?>" name="orden" type="number" value="<?= (int) $c['orden'] ?>" class="corto" aria-label="Orden de <?= e($c['nombre']) ?>"></td>
          <td class="num"><?= (int) $c['productos'] ?></td>
          <td class="num"><?= (int) $c['combos'] ?></td>
          <td class="num">
            <form method="post" id="<?= $fid ?>">
              <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn">Guardar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <section class="tarjeta">
    <h2>Nueva categoría</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <label>Nombre <input name="nombre" required maxlength="80" placeholder="Ensaladas" id="cat-nueva"></label>
      <button class="btn primario">Agregar</button>
    </form>
    <p class="tenue chico">Una categoría no se borra: si ya no se usa, mueva sus productos a otra desde <a href="index.php?p=productos">Productos</a> y dejará de mostrarse en la caja.</p>
  </section>
</div>
<?php layout_fin(); ?>
