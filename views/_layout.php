<?php
use App\Support\Auth;
use App\Support\Menu;

/**
 * $activo es la página actual; para Configuración se pasa "configuracion&s=cai"
 * y así se resalta tanto el grupo como la sección.
 */
function layout_inicio(string $titulo, string $activo, PDO $db): void
{
    $u = Auth::usuario();
    $deTienda = Menu::esDeTienda($u);
    $tiendas = $db->query('SELECT id, nombre FROM tiendas WHERE activa = 1 ORDER BY codigo_establecimiento')->fetchAll();
    $tiendaActual = '';
    foreach ($tiendas as $t) {
        if ((int) $t['id'] === Auth::tiendaId()) {
            $tiendaActual = $t['nombre'];
        }
    }
    $menu = Menu::para($u);
    $flash = $_SESSION['flash'] ?? null;
    $flashError = !empty($_SESSION['flash_error']);
    unset($_SESSION['flash'], $_SESSION['flash_error']);
    $es = fn (string $p) => $p === $activo;
    $roles = ['ADMIN' => 'Administrador', 'GERENTE' => 'Gerente', 'CAJERO' => 'Cajero'];
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#d93a1e">
  <title><?= e($titulo) ?> · Power POS</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="<?= $deTienda ? 'menu-tienda' : 'menu-admin' ?>">
<header class="barra">
  <a class="marca" href="index.php?p=<?= e(Menu::inicio($u)) ?>">Power<span>POS</span></a>
  <span class="contexto" title="<?= $deTienda ? 'Menú de tienda' : 'Menú administrativo' ?>"><?= $deTienda ? e($tiendaActual) : 'Administración' ?></span>
  <button class="menu-btn" aria-label="Menú" aria-controls="nav" onclick="document.body.classList.toggle('menu-abierto')">☰</button>
  <nav class="nav" id="nav" aria-label="<?= $deTienda ? 'Menú de tienda' : 'Menú administrativo' ?>">
    <?php foreach ($menu as $item): ?>
      <?php if (isset($item['hijos'])):
          $grupoActivo = (bool) array_filter($item['hijos'], fn ($h) => Menu::pagina($h['p']) === Menu::pagina($activo)); ?>
        <details class="grupo <?= $grupoActivo ? 'activo' : '' ?>">
          <summary><?= e($item['texto']) ?></summary>
          <div class="submenu">
            <?php foreach ($item['hijos'] as $h): ?>
              <a href="index.php?p=<?= e($h['p']) ?>" class="<?= $es($h['p']) ? 'activo' : '' ?>">
                <b><?= e($h['texto']) ?></b><?php if (!empty($h['nota'])): ?><small><?= e($h['nota']) ?></small><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php else: ?>
        <a href="index.php?p=<?= e($item['p']) ?>" class="<?= $es($item['p']) ? 'activo' : '' ?>"><?= e($item['texto']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>

    <div class="nav-fin">
      <?php if (!$deTienda): ?>
        <form method="post" action="index.php?p=tienda" class="selector-tienda">
          <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
          <input type="hidden" name="volver" value="<?= e(Menu::pagina($activo)) ?>">
          <label><span>Sucursal</span>
            <select name="tienda_id" onchange="this.form.submit()" id="selector-sucursal">
              <?php foreach ($tiendas as $t): ?>
                <option value="<?= $t['id'] ?>" <?= (int) $t['id'] === Auth::tiendaId() ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>
      <?php endif; ?>
      <details class="grupo usuario">
        <summary><?= e($u['nombre']) ?></summary>
        <div class="submenu derecha">
          <span class="quien"><b><?= e($u['nombre']) ?></b><small><?= e($roles[$u['rol']]) ?> · <?= $deTienda ? e($tiendaActual) : 'Oficina central' ?></small></span>
          <a href="index.php?p=cuenta" class="<?= $activo === 'cuenta' ? 'activo' : '' ?>"><b>Mi cuenta</b><small>Cambiar contraseña</small></a>
          <a href="index.php?p=logout"><b>Salir</b></a>
        </div>
      </details>
    </div>
  </nav>
</header>
<main class="contenido">
  <?php if ($flash): ?><div class="aviso <?= $flashError ? 'error' : 'exito' ?>" role="<?= $flashError ? 'alert' : 'status' ?>"><?= e($flash) ?></div><?php endif; ?>
<?php
}

function layout_fin(array $scripts = []): void
{
    ?>
</main>
<script>
  // Menús desplegables: solo uno abierto a la vez; se cierran con Esc o al tocar fuera
  (() => {
    const grupos = [...document.querySelectorAll('.nav details.grupo')];
    grupos.forEach((g) => g.addEventListener('toggle', () => {
      if (g.open) grupos.forEach((o) => { if (o !== g) o.open = false; });
    }));
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.nav details.grupo')) grupos.forEach((g) => { g.open = false; });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      const abierto = grupos.find((g) => g.open);
      if (abierto) { abierto.open = false; abierto.querySelector('summary').focus(); }
    });
  })();
</script>
<?php foreach ($scripts as $s): ?><script src="<?= e($s) ?>"></script><?php endforeach; ?>
</body>
</html>
<?php
}
