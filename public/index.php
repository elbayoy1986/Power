<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Database;
use App\Support\Menu;

Auth::iniciar();

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function lps(int|string|null $centavos): string
{
    return App\Support\Money::fmt((int) $centavos);
}

/** Redirige a index.php?p=$pagina dejando un mensaje para la siguiente pantalla. */
function redirigir(string $pagina, string $msg = '', bool $error = false): never
{
    if ($msg !== '') {
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_error'] = $error;
    }
    header('Location: index.php?p=' . $pagina);
    exit;
}

$p = (string) ($_GET['p'] ?? '');
$vistas = dirname(__DIR__) . '/views/';

if ($p === 'login') {
    $db = Database::pdo();
    require $vistas . 'login.php';
    exit;
}
if ($p === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?p=login');
    exit;
}

$usuario = Auth::requerir();
$db = Database::pdo();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verificarCsrf($_POST['csrf'] ?? null);
}

// Cambio de sucursal de trabajo (solo oficina central: usuarios sin sucursal fija)
if ($p === 'tienda' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $volver = Menu::pagina((string) ($_POST['volver'] ?? ''));
    if (!Menu::esDeTienda($usuario)) {
        $st = $db->prepare('SELECT id FROM tiendas WHERE id = ? AND activa = 1');
        $st->execute([(int) ($_POST['tienda_id'] ?? 0)]);
        if ($id = $st->fetchColumn()) {
            $_SESSION['tienda_id'] = (int) $id;
        }
    }
    redirigir(isset(Menu::RUTAS[$volver]) ? $volver : Menu::inicio($usuario));
}

// Enlaces de versiones anteriores
$antiguas = ['cai' => 'configuracion&s=cai', 'descuentos' => 'configuracion&s=descuentos'];
if (isset($antiguas[$p])) {
    redirigir($antiguas[$p]);
}

if (!isset(Menu::RUTAS[$p])) {
    $p = Menu::inicio($usuario);
}
if (!Menu::puede($usuario, $p)) {
    http_response_code(403);
    require $vistas . '_layout.php';
    layout_inicio('Sin permiso', '', $db);
    echo '<section class="tarjeta apertura"><h2>Sin permiso para esta sección.</h2>'
        . '<p class="tenue">Su usuario no tiene acceso a esta pantalla. Si la necesita, pídala a un administrador.</p>'
        . '<a class="btn primario" href="index.php?p=' . e(Menu::inicio($usuario)) . '">Volver al inicio</a></section>';
    layout_fin();
    exit;
}

require $vistas . Menu::RUTAS[$p][0] . '.php';
