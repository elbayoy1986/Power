<?php
use App\Support\Auth;

function layout_inicio(string $titulo, string $activo, PDO $db): void
{
    $u = Auth::usuario();
    $tiendas = $db->query('SELECT id, nombre FROM tiendas WHERE activa = 1 ORDER BY id')->fetchAll();
    $menu = [
        'pos' => ['Vender', ['ADMIN', 'GERENTE', 'CAJERO']],
        'caja' => ['Turno', ['ADMIN', 'GERENTE', 'CAJERO']],
        'productos' => ['Productos', ['ADMIN', 'GERENTE']],
        'combos' => ['Combos', ['ADMIN', 'GERENTE']],
        'promociones' => ['Promociones', ['ADMIN', 'GERENTE']],
        'descuentos' => ['Descuentos', ['ADMIN']],
        'compras' => ['Compras', ['ADMIN', 'GERENTE']],
        'inventario' => ['Inventario', ['ADMIN', 'GERENTE']],
        'cai' => ['CAI', ['ADMIN']],
        'erp' => ['Tablero ERP', ['ADMIN', 'GERENTE']],
    ];
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
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
<body>
<header class="barra">
  <a class="marca" href="index.php?p=pos">Power<span>POS</span></a>
  <button class="menu-btn" aria-label="Menú" onclick="document.body.classList.toggle('menu-abierto')">☰</button>
  <nav class="nav">
    <?php foreach ($menu as $clave => [$texto, $roles]): if (in_array($u['rol'], $roles, true)): ?>
      <a href="index.php?p=<?= $clave ?>" class="<?= $clave === $activo ? 'activo' : '' ?>"><?= e($texto) ?></a>
    <?php endif; endforeach; ?>
    <?php if ($u['tienda_id'] === null): ?>
      <form method="post" action="index.php?p=tienda" class="selector-tienda">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="volver" value="<?= e($activo) ?>">
        <select name="tienda_id" onchange="this.form.submit()" aria-label="Tienda">
          <?php foreach ($tiendas as $t): ?>
            <option value="<?= $t['id'] ?>" <?= (int) $t['id'] === Auth::tiendaId() ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <a href="index.php?p=logout" class="salir" title="Cerrar sesión de <?= e($u['nombre']) ?>"><span class="solo-menu"><?= e($u['nombre']) ?> · </span>Salir</a>
  </nav>
</header>
<main class="contenido">
  <?php if ($flash): ?><div class="aviso"><?= e($flash) ?></div><?php endif; ?>
<?php
}

function layout_fin(array $scripts = []): void
{
    ?>
</main>
<?php foreach ($scripts as $s): ?><script src="<?= e($s) ?>"></script><?php endforeach; ?>
</body>
</html>
<?php
}
