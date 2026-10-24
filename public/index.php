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
    'caja'         => ['vista' => 'caja', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO']],
    'cierre'       => ['vista' => 'cierre', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO']],
    'productos'    => ['vista' => 'productos', 'roles' => ['ADMIN', 'GERENTE']],
    'inventario'   => ['vista' => 'inventario', 'roles' => ['ADMIN', 'GERENTE']],
    'compras'      => ['vista' => 'compras', 'roles' => ['ADMIN', 'GERENTE']],
    'combos'       => ['vista' => 'combos', 'roles' => ['ADMIN', 'GERENTE']],
    'promociones'  => ['vista' => 'promociones', 'roles' => ['ADMIN', 'GERENTE']],
    'descuentos'   => ['vista' => 'descuentos', 'roles' => ['ADMIN']],
    'cai'          => ['vista' => 'cai', 'roles' => ['ADMIN']],
    'erp'          => ['vista' => 'erp', 'roles' => ['ADMIN', 'GERENTE']],
    // Restaurante: salón, cuenta de mesa, cocina e impresos
    'salon'        => ['vista' => 'salon', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO']],
    'mesa'         => ['vista' => 'mesa', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO']],
    'precuenta'    => ['vista' => 'precuenta', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO']],
    'cocina'       => ['vista' => 'cocina', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO', 'COCINA']],
    'comanda'      => ['vista' => 'comanda', 'roles' => ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO', 'COCINA']],
    'salon_config' => ['vista' => 'salon_config', 'roles' => ['ADMIN', 'GERENTE']],
    'personal'     => ['vista' => 'personal', 'roles' => ['ADMIN', 'GERENTE']],
];

// Sin página: cada rol entra a su pantalla (caja, salón, cocina o tablero)
$p = ($_GET['p'] ?? '') ?: (Auth::usuario() ? Auth::inicio(Auth::usuario()['rol']) : 'login');

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

$ruta = $rutas[$p] ?? $rutas[Auth::usuario() ? Auth::inicio(Auth::usuario()['rol']) : 'login'];
$usuario = $ruta['roles'] ? Auth::requerir($ruta['roles']) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $p !== 'login') {
    Auth::verificarCsrf($_POST['csrf'] ?? null);
}

$db = $usuario || $p === 'login' ? Database::pdo() : null;
$vistas = dirname(__DIR__) . '/views/';
require $vistas . $ruta['vista'] . '.php';
