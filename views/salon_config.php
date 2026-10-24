<?php
// Configuración del restaurante: zonas y mesas (por tienda), estaciones de cocina y a qué estación va cada categoría.
use App\Mesas\MesasService;
use App\Support\Auth;

$tiendaId = Auth::tiendaId();
$servicio = new MesasService($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['accion'] ?? '') {
            case 'zona':
                $servicio->guardarZona($tiendaId, $_POST);
                redirigir('salon_config', 'Zona guardada.');
            case 'zona_alternar':
                $servicio->alternarZona($tiendaId, (int) $_POST['id']);
                redirigir('salon_config', 'Zona actualizada.');
            case 'mesa':
                $servicio->guardarMesa($tiendaId, $_POST);
                redirigir('salon_config', 'Mesa guardada.');
            case 'mesas_varias':
                $n = $servicio->crearVarias($tiendaId, (int) $_POST['zona_id'], (string) ($_POST['prefijo'] ?? ''),
                    (int) $_POST['desde'], (int) $_POST['hasta'], (int) ($_POST['capacidad'] ?? 4));
                redirigir('salon_config', $n ? "Se crearon $n mesas." : 'Esas mesas ya existían; no se creó ninguna.');
            case 'mesa_alternar':
                $servicio->alternarMesa($tiendaId, (int) $_POST['id']);
                redirigir('salon_config', 'Mesa actualizada.');
            case 'estacion':
                $servicio->guardarEstacion($_POST);
                redirigir('salon_config', 'Estación guardada.');
            case 'estacion_alternar':
                $servicio->alternarEstacion((int) $_POST['id']);
                redirigir('salon_config', 'Estación actualizada.');
            case 'rutas':
                $servicio->rutasCategorias($_POST['ruta'] ?? []);
                redirigir('salon_config', 'Rutas de cocina guardadas.');
        }
    } catch (DomainException $e) {
        redirigir('salon_config', $e->getMessage());
    }
}

$zonas = $servicio->zonas($tiendaId);
$mesas = $servicio->mesas($tiendaId);
$estaciones = $servicio->estaciones();
$categorias = $db->query('SELECT * FROM categorias ORDER BY orden, nombre')->fetchAll();
$zonasActivas = array_filter($zonas, fn ($z) => $z['activa']);
$editarMesa = null;
foreach ($mesas as $m) {
    if ((int) $m['id'] === (int) ($_GET['mesa'] ?? 0)) {
        $editarMesa = $m;
    }
}
$csrf = '<input type="hidden" name="csrf" value="' . e(Auth::csrf()) . '">';
$tienda = $db->query('SELECT nombre FROM tiendas WHERE id = ' . $tiendaId)->fetchColumn();

require __DIR__ . '/_layout.php';
layout_inicio('Salón y cocina', 'salon', $db);
?>
<div class="fila-entre">
  <h1>Salón y cocina <small class="tenue">· <?= e($tienda) ?></small></h1>
  <a class="btn" href="index.php?p=salon">Ver el salón</a>
</div>

<div class="columnas">
  <section class="tarjeta">
    <h2>Zonas del salón</h2>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>Zona y orden</th><th class="num">Mesas</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($zonas as $z): ?>
        <tr class="<?= $z['activa'] ? '' : 'inactivo' ?>">
          <td><form method="post" class="fila en-tabla"><?= $csrf ?><input type="hidden" name="accion" value="zona"><input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
            <input name="nombre" value="<?= e($z['nombre']) ?>" maxlength="60" aria-label="Nombre"><input name="orden" value="<?= (int) $z['orden'] ?>" class="corto" inputmode="numeric" aria-label="Orden">
            <button class="btn">Guardar</button></form></td>
          <td class="num"><?= (int) $z['mesas'] ?></td>
          <td class="num"><form method="post" class="en-linea"><?= $csrf ?><input type="hidden" name="accion" value="zona_alternar"><input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
            <button class="btn texto"><?= $z['activa'] ? 'Desactivar' : 'Activar' ?></button></form></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$zonas): ?><tr><td colspan="3" class="tenue">Aún no hay zonas. Cree la primera (por ejemplo "Salón").</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <form method="post" class="fila"><?= $csrf ?><input type="hidden" name="accion" value="zona">
      <label>Nueva zona <input name="nombre" required maxlength="60" placeholder="Terraza" id="zona-nombre"></label>
      <label>Orden <input name="orden" value="<?= count($zonas) + 1 ?>" class="corto" inputmode="numeric"></label>
      <button class="btn primario">Agregar zona</button>
    </form>
  </section>

  <section class="tarjeta">
    <h2><?= $editarMesa ? 'Editar mesa ' . e($editarMesa['nombre']) : 'Agregar mesas' ?></h2>
    <?php if (!$zonasActivas): ?><p class="tenue">Primero cree una zona.</p><?php else: ?>
      <form method="post"><?= $csrf ?><input type="hidden" name="accion" value="mesa">
        <?php if ($editarMesa): ?><input type="hidden" name="id" value="<?= (int) $editarMesa['id'] ?>"><?php endif; ?>
        <div class="fila">
          <label>Mesa <input name="nombre" required maxlength="20" placeholder="12" value="<?= e($editarMesa['nombre'] ?? '') ?>" id="mesa-nombre"></label>
          <label>Zona <select name="zona_id"><?php foreach ($zonasActivas as $z): ?><option value="<?= (int) $z['id'] ?>" <?= (int) ($editarMesa['zona_id'] ?? 0) === (int) $z['id'] ? 'selected' : '' ?>><?= e($z['nombre']) ?></option><?php endforeach; ?></select></label>
          <label>Personas <input name="capacidad" type="number" min="1" max="50" value="<?= (int) ($editarMesa['capacidad'] ?? 4) ?>" class="corto"></label>
          <label>Orden <input name="orden" value="<?= (int) ($editarMesa['orden'] ?? 0) ?>" class="corto" inputmode="numeric"></label>
        </div>
        <div class="fila">
          <?php if ($editarMesa): ?><a class="btn texto" href="index.php?p=salon_config">Cancelar</a><?php endif; ?>
          <button class="btn primario">Guardar mesa</button>
        </div>
      </form>
      <?php if (!$editarMesa): ?>
        <h3 class="subtitulo">Crear varias de una vez</h3>
        <form method="post"><?= $csrf ?><input type="hidden" name="accion" value="mesas_varias">
          <div class="fila">
            <label>Prefijo <input name="prefijo" maxlength="10" placeholder="(vacío) o T" class="corto"></label>
            <label>Del <input name="desde" type="number" min="1" value="1" class="corto"></label>
            <label>al <input name="hasta" type="number" min="1" value="10" class="corto"></label>
            <label>Personas <input name="capacidad" type="number" min="1" max="50" value="4" class="corto"></label>
            <label>Zona <select name="zona_id"><?php foreach ($zonasActivas as $z): ?><option value="<?= (int) $z['id'] ?>"><?= e($z['nombre']) ?></option><?php endforeach; ?></select></label>
          </div>
          <button class="btn">Crear mesas</button>
          <p class="tenue chico">Ej.: prefijo "T" del 1 al 6 crea T1…T6. Las que ya existen se saltan.</p>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<section class="tarjeta">
  <h2>Mesas</h2>
  <div class="tabla-scroll"><table class="tabla">
    <thead><tr><th>Mesa</th><th>Zona</th><th class="num">Personas</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($mesas as $m): ?>
      <tr class="<?= $m['activa'] ? '' : 'inactivo' ?>">
        <td><b><?= e($m['nombre']) ?></b></td>
        <td><?= e($m['zona']) ?></td>
        <td class="num"><?= (int) $m['capacidad'] ?></td>
        <td><?= !$m['activa'] ? 'Desactivada' : ($m['cuenta_id'] ? '<span class="pill alerta">Ocupada</span>' : '<span class="pill ok">Libre</span>') ?></td>
        <td class="num"><a href="index.php?p=salon_config&mesa=<?= (int) $m['id'] ?>">Editar</a>
          <form method="post" class="en-linea"><?= $csrf ?><input type="hidden" name="accion" value="mesa_alternar"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button class="btn texto"><?= $m['activa'] ? 'Desactivar' : 'Activar' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$mesas): ?><tr><td colspan="5" class="tenue">Sin mesas todavía.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<div class="columnas">
  <section class="tarjeta">
    <h2>Estaciones de cocina</h2>
    <p class="tenue chico">Cada estación recibe solo sus comandas, en su pantalla y/o su impresora. Son las mismas en todas las tiendas.</p>
    <div class="tabla-scroll"><table class="tabla">
      <thead><tr><th>Estación y orden</th><th class="num">Categorías</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($estaciones as $est): ?>
        <tr class="<?= $est['activa'] ? '' : 'inactivo' ?>">
          <td><form method="post" class="fila en-tabla"><?= $csrf ?><input type="hidden" name="accion" value="estacion"><input type="hidden" name="id" value="<?= (int) $est['id'] ?>">
            <input name="nombre" value="<?= e($est['nombre']) ?>" maxlength="60" aria-label="Nombre"><input name="orden" value="<?= (int) $est['orden'] ?>" class="corto" inputmode="numeric" aria-label="Orden">
            <button class="btn">Guardar</button></form></td>
          <td class="num"><?= (int) $est['categorias'] ?></td>
          <td class="num"><a href="index.php?p=cocina&estacion=<?= (int) $est['id'] ?>" target="_blank">Abrir pantalla</a>
            <form method="post" class="en-linea"><?= $csrf ?><input type="hidden" name="accion" value="estacion_alternar"><input type="hidden" name="id" value="<?= (int) $est['id'] ?>">
              <button class="btn texto"><?= $est['activa'] ? 'Desactivar' : 'Activar' ?></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <form method="post" class="fila"><?= $csrf ?><input type="hidden" name="accion" value="estacion">
      <label>Nueva estación <input name="nombre" required maxlength="60" placeholder="Parrilla" id="estacion-nombre"></label>
      <label>Orden <input name="orden" value="<?= count($estaciones) + 1 ?>" class="corto" inputmode="numeric"></label>
      <button class="btn primario">Agregar estación</button>
    </form>
  </section>

  <section class="tarjeta">
    <h2>¿A dónde va cada categoría?</h2>
    <p class="tenue chico">Los combos se reparten por componente: la hamburguesa va a la estación de Hamburguesas y el refresco a la de Bebidas.
      Para mandar un producto a otra estación distinta a la de su categoría, edítelo en <a href="index.php?p=productos">Productos</a>.</p>
    <form method="post"><?= $csrf ?><input type="hidden" name="accion" value="rutas">
      <div class="tabla-scroll"><table class="tabla">
        <thead><tr><th>Categoría</th><th>Estación</th></tr></thead>
        <tbody>
        <?php foreach ($categorias as $cat): ?>
          <tr><td><?= e($cat['nombre']) ?></td>
            <td><select name="ruta[<?= (int) $cat['id'] ?>]" aria-label="Estación de <?= e($cat['nombre']) ?>">
              <option value="">— La primera activa —</option>
              <?php foreach ($estaciones as $est): ?><option value="<?= (int) $est['id'] ?>" <?= (int) $cat['estacion_id'] === (int) $est['id'] ? 'selected' : '' ?>><?= e($est['nombre']) ?><?= $est['activa'] ? '' : ' (inactiva)' ?></option><?php endforeach; ?>
            </select></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <button class="btn primario">Guardar rutas</button>
    </form>
  </section>
</div>
<?php layout_fin(); ?>
