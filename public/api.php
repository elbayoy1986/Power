<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Caja\CajaService;
use App\Catalogo\CatalogoRepository;
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
$entrada = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verificarCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}

try {
    switch ($_GET['a'] ?? '') {
        case 'catalogo':
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
            $legalId = !empty($entrada['descuento_legal_id']) ? (int) $entrada['descuento_legal_id'] : null;
            responder($carrito->cotizar($tiendaId, $entrada['items'] ?? [], $legalId));

        case 'facturar':
            $turno = (new CajaService($db))->turnoAbierto($usuario['id']);
            if (!$turno) {
                throw new DomainException('Abra su turno de caja antes de facturar.');
            }
            responder((new FacturacionService($db, $carrito))->emitir($tiendaId, $usuario['id'], (int) $turno['id'], $entrada));

        default:
            responder(['error' => 'Acción desconocida.'], 404);
    }
} catch (InvalidArgumentException | DomainException $e) {
    responder(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log((string) $e);
    responder(['error' => 'Error interno. Intente de nuevo.'], 500);
}
