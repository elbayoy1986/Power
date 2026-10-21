<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Database;

Auth::iniciar();

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function lps(int|string|null $centavos): string
{
    return App\Support\Money::fmt((int) $centavos);
}

function redirigir(string $pagina, string $msg = ''): never
{
    if ($msg !== '') {
        $_SESSION['flash'] = $msg;
    }
    header('Location: index.php?p=' . $pagina);
    exit;
}

$rutas = [
    'login'        => ['vista' => 'login', 'roles' => null],
    'pos'          => ['vista' => 'pos', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO']],
    'factura'      => ['vista' => 'factura', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO']],
    'combos'       => ['vista' => 'combos', 'roles' => ['ADMIN', 'GERENTE']],
    'promociones'  => ['vista' => 'promociones', 'roles' => ['ADMIN', 'GERENTE']],
    'descuentos'   => ['vista' => 'descuentos', 'roles' => ['ADMIN']],
    'cai'          => ['vista' => 'cai', 'roles' => ['ADMIN']],
    'erp'          => ['vista' => 'erp', 'roles' => ['ADMIN', 'GERENTE']],
];

$p = $_GET['p'] ?? 'pos';

if ($p === 'logout') {
    session_destroy();
    redirigir('login');
}

// Cambio de tienda (solo usuarios sin tienda fija)
if ($p === 'tienda' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = Auth::requerir(['ADMIN', 'GERENTE']);
    Auth::verificarCsrf($_POST['csrf'] ?? null);
    if ($u['tienda_id'] === null) {
        $_SESSION['tienda_id'] = (int) $_POST['tienda_id'];
    }
    redirigir($_POST['volver'] ?? 'pos');
}

$ruta = $rutas[$p] ?? $rutas['pos'];
$usuario = $ruta['roles'] ? Auth::requerir($ruta['roles']) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $p !== 'login') {
    Auth::verificarCsrf($_POST['csrf'] ?? null);
}

$db = $usuario || $p === 'login' ? Database::pdo() : null;
$vistas = dirname(__DIR__) . '/views/';
require $vistas . $ruta['vista'] . '.php';
