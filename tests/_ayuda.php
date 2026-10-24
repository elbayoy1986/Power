<?php
declare(strict_types=1);

// Utilidades compartidas por las pruebas de integración (tests/integracion.php y tests/restaurante.php).
require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Caja\CajaService;
use App\Support\Database;

$db = Database::pdo();
if ((int) $db->query('SELECT COUNT(*) FROM ventas')->fetchColumn() > 0 || (int) $db->query('SELECT COUNT(*) FROM turnos_caja')->fetchColumn() > 0) {
    fwrite(STDERR, "La base ya tiene ventas o turnos. Ejecute las pruebas sobre una base recién cargada con schema.sql + seed.sql.\n");
    exit(2);
}

$ok = 0;
$fallos = 0;
function prueba(string $nombre, callable $fn): void
{
    global $ok, $fallos;
    try {
        $fn();
        $ok++;
        echo "  ✔ $nombre\n";
    } catch (Throwable $e) {
        $fallos++;
        echo "  ✘ $nombre\n    " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
function igual(mixed $esperado, mixed $real, string $que = ''): void
{
    if ($esperado != $real) {
        throw new RuntimeException("$que esperado " . var_export($esperado, true) . ', obtenido ' . var_export($real, true));
    }
}
/** La operación debe fallar con un mensaje que contenga $texto. */
function rechaza(string $texto, callable $fn): void
{
    try {
        $fn();
    } catch (DomainException | InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), $texto)) {
            throw new RuntimeException('Rechazó con otro mensaje: ' . $e->getMessage());
        }
        return;
    }
    throw new RuntimeException("Debía rechazarse ($texto) y se aceptó.");
}
function existencia(int $tienda, int $producto): float
{
    global $db;
    return (float) $db->query("SELECT existencia FROM inventario WHERE tienda_id = $tienda AND producto_id = $producto")->fetchColumn();
}
function costo(int $tienda, int $producto): int
{
    global $db;
    return (int) $db->query("SELECT costo_promedio FROM inventario WHERE tienda_id = $tienda AND producto_id = $producto")->fetchColumn();
}
/** Conteo de billetes/monedas que suma exactamente $centavos. */
function conteoExacto(int $centavos): array
{
    $c = [];
    foreach (array_merge(...array_values(CajaService::DENOMINACIONES)) as $d) {
        if ($n = intdiv($centavos, $d)) {
            $c[$d] = $n;
            $centavos -= $n * $d;
        }
    }
    return $c;
}
