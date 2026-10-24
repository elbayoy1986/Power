<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Caja\CajaService;
use App\Catalogo\CatalogoRepository;
use App\Cocina\ComandasService;
use App\Mesas\CuentasService;
use App\Mesas\MesasService;
use App\Support\Auth;
use App\Support\Database;
use App\Ventas\CarritoService;
use App\Ventas\FacturacionService;

header('Content-Type: application/json; charset=utf-8');
Auth::iniciar();

function responder(array $data, int $codigo = 200): never
{
    http_response_code($codigo);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuario = Auth::usuario() ?? responder(['error' => 'Sesión expirada.'], 401);
$tiendaId = Auth::tiendaId();
$db = Database::pdo();
$catalogo = new CatalogoRepository($db);
$carrito = new CarritoService($catalogo);
$comandas = new ComandasService($db);
$cuentas = new CuentasService($db, $carrito, $comandas);
$entrada = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verificarCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}

const VENTAS = ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'];
const CAJA = ['ADMIN', 'GERENTE', 'CAJERO'];
const SUPERVISOR = ['ADMIN', 'GERENTE'];
const PANTALLA_COCINA = ['ADMIN', 'GERENTE', 'CAJERO', 'COCINA'];

function permitir(array $roles): void
{
    global $usuario;
    if (!in_array($usuario['rol'], $roles, true)) {
        responder(['error' => 'Sin permiso para esta acción.'], 403);
    }
}

/** Turno abierto del usuario en esta tienda, requisito para cobrar. */
function turnoParaCobrar(PDO $db, array $usuario, int $tiendaId): int
{
    $turno = (new CajaService($db))->turnoAbierto($usuario['id']);
    if (!$turno || (int) $turno['tienda_id'] !== $tiendaId) {
        throw new DomainException('Abra su turno de caja en esta tienda antes de cobrar.');
    }
    return (int) $turno['id'];
}

try {
    switch ($_GET['a'] ?? '') {
        // ---------- Caja (mostrador) ----------
        case 'catalogo':
            permitir(VENTAS);
            responder([
                'productos' => $catalogo->productos($tiendaId),
                'combos' => $catalogo->combos(),
                'descuentos_legales' => $catalogo->descuentosLegales(),
                'promociones' => array_map(
                    fn ($p) => ['id' => $p['id'], 'nombre' => $p['nombre']],
                    $catalogo->promocionesVigentes($tiendaId, new DateTimeImmutable())
                ),
            ]);

        case 'cotizar':
            permitir(VENTAS);
            $legalId = !empty($entrada['descuento_legal_id']) ? (int) $entrada['descuento_legal_id'] : null;
            responder($carrito->cotizar($tiendaId, $entrada['items'] ?? [], $legalId));

        case 'facturar':
            permitir(CAJA);
            $turnoId = turnoParaCobrar($db, $usuario, $tiendaId);
            $alEmitir = ($GLOBALS['config']['comandas_mostrador'] ?? true)
                ? $comandas->alFacturarMostrador($tiendaId, $usuario['id'], (string) ($entrada['cliente_nombre'] ?? ''))
                : null;
            responder((new FacturacionService($db, $carrito))->emitir($tiendaId, $usuario['id'], $turnoId, $entrada, $alEmitir));

        // ---------- Salón y cuentas de mesa ----------
        case 'salon':
            permitir(VENTAS);
            responder(['zonas' => (new MesasService($db))->salon($tiendaId)]);

        case 'cuenta':
            permitir(VENTAS);
            $c = $cuentas->obtener($tiendaId, (int) ($_GET['id'] ?? 0)) ?? responder(['error' => 'Cuenta no encontrada.'], 404);
            $pendiente = in_array($c['estado'], CuentasService::ACTIVAS, true) ? $cuentas->cotizar($tiendaId, $c['id']) : null;
            responder(['cuenta' => $c, 'pendiente' => $pendiente ? $pendiente['resumen'] + ['promociones' => $pendiente['promociones_aplicadas']] : null]);

        case 'enviar':
            permitir(VENTAS);
            responder($cuentas->enviar($tiendaId, (int) ($entrada['cuenta_id'] ?? 0), $usuario['id'], $entrada['items'] ?? []));

        case 'anular_item':
            permitir(SUPERVISOR);
            $cuentas->anularItem($tiendaId, (int) ($entrada['item_id'] ?? 0), $usuario['id'], (string) ($entrada['motivo'] ?? ''));
            responder(['ok' => true]);

        case 'entregar':
            permitir(VENTAS);
            $comandas->entregar($tiendaId, (int) ($entrada['comanda_id'] ?? 0));
            responder(['ok' => true]);

        case 'pedir_cuenta':
            permitir(VENTAS);
            $cuentas->pedirCuenta($tiendaId, (int) ($entrada['cuenta_id'] ?? 0));
            responder(['ok' => true]);

        case 'cotizar_cuenta':
            permitir(CAJA);
            $legalId = !empty($entrada['descuento_legal_id']) ? (int) $entrada['descuento_legal_id'] : null;
            responder($cuentas->cotizar($tiendaId, (int) ($entrada['cuenta_id'] ?? 0), (array) ($entrada['item_ids'] ?? []),
                (array) ($entrada['aplica_legal'] ?? []), $legalId));

        case 'cobrar':
            permitir(CAJA);
            $turnoId = turnoParaCobrar($db, $usuario, $tiendaId);
            responder($cuentas->cobrar($tiendaId, (int) ($entrada['cuenta_id'] ?? 0), $usuario['id'], $turnoId, $entrada,
                new FacturacionService($db, $carrito)));

        // ---------- Pantalla de cocina ----------
        case 'cocina':
            permitir(PANTALLA_COCINA);
            responder($comandas->pantalla($tiendaId, (int) ($_GET['estacion'] ?? 0) ?: null));

        case 'item_listo':
            permitir(PANTALLA_COCINA);
            $comandas->marcarItem($tiendaId, (int) ($entrada['item_id'] ?? 0), (bool) ($entrada['listo'] ?? true));
            responder(['ok' => true]);

        case 'despachar':
            permitir(PANTALLA_COCINA);
            $comandas->despachar($tiendaId, (int) ($entrada['comanda_id'] ?? 0));
            responder(['ok' => true]);

        case 'recuperar':
            permitir(PANTALLA_COCINA);
            $comandas->recuperar($tiendaId, (int) ($entrada['comanda_id'] ?? 0));
            responder(['ok' => true]);

        case 'reclamar_impresion':
            permitir(PANTALLA_COCINA);
            responder(['imprimir' => $comandas->reclamarImpresion($tiendaId, (int) ($entrada['comanda_id'] ?? 0))]);

        default:
            responder(['error' => 'Acción desconocida.'], 404);
    }
} catch (InvalidArgumentException | DomainException $e) {
    responder(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log((string) $e);
    responder(['error' => 'Error interno. Intente de nuevo.'], 500);
}
