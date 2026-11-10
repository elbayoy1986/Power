<?php
declare(strict_types=1);

/*
 * Pruebas de integración contra MySQL/MariaDB: caja (apertura/cierre), ventas, compras e inventario.
 * ¡Usa una base de PRUEBA! Cargue schema.sql + seed.sql en una BD vacía y ejecute:
 *   DB_DSN='mysql:host=127.0.0.1;dbname=power_pos;charset=utf8mb4' DB_USER=... DB_PASS=... php tests/integracion.php
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Caja\CajaService;
use App\Catalogo\CatalogoRepository;
use App\Catalogo\ProductosService;
use App\Compras\ComprasService;
use App\Configuracion\CaiService;
use App\Configuracion\TiendasService;
use App\Configuracion\UsuariosService;
use App\Support\Menu;
use App\Support\Parametros;
use App\Inventario\InventarioService;
use App\Support\Database;
use App\Ventas\CarritoService;
use App\Ventas\FacturacionService;

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

$T = 1;   // Power Morazán
$T2 = 2;  // Power Circunvalación
$CAJERO = 2;
$ADMIN = 1;
$caja = new CajaService($db);
$inv = new InventarioService($db);
$fact = new FacturacionService($db, new CarritoService(new CatalogoRepository($db)), $inv);
$compras = new ComprasService($db, $inv);
$combo = ['tipo' => 'COMBO', 'combo_id' => 1, 'cantidad' => 1, 'opciones' => [1, 6, 9]]; // L 175
$hamb = ['tipo' => 'PRODUCTO', 'producto_id' => 2, 'cantidad' => 2];                      // 2 × L 165 (promo 2 por L 199)
$turnoId = 0;

echo "Caja: apertura\n";
prueba('abrir turno con fondo L 1,000', function () use ($caja, $T, $CAJERO, &$turnoId) {
    $turnoId = $caja->abrir($T, $CAJERO, 100000);
    igual('ABIERTO', $caja->turnoAbierto($CAJERO)['estado']);
});
prueba('no permite dos turnos abiertos al mismo cajero', fn () => rechaza('Ya tiene un turno abierto', fn () => $caja->abrir($T, $CAJERO, 0)));
prueba('rechaza fondo negativo', fn () => rechaza('negativo', fn () => $caja->abrir($T, $ADMIN, -1)));
prueba('no factura con un turno que no es suyo', fn () => rechaza('no está abierto', fn () => $fact->emitir($T, $ADMIN, $turnoId, ['items' => [$combo]])));
prueba('no factura en otra tienda con su turno', fn () => rechaza('no está abierto en esta tienda', fn () => $fact->emitir($T2, $CAJERO, $turnoId, ['items' => [$combo]])));

echo "Ventas y forma de pago\n";
$antesHamb = existencia($T, 1);
$antesDoble = existencia($T, 2);
prueba('efectivo insuficiente se rechaza', fn () => rechaza('no cubre', fn () => $fact->emitir($T, $CAJERO, $turnoId, ['items' => [$combo], 'forma_pago' => 'EFECTIVO', 'efectivo_recibido' => 10000])));
prueba('efectivo con cambio: recibe L 200 por L 175 → cambio L 25', function () use ($fact, $T, $CAJERO, &$turnoId, $combo) {
    $r = $fact->emitir($T, $CAJERO, $turnoId, ['items' => [$combo], 'forma_pago' => 'EFECTIVO', 'efectivo_recibido' => 20000]);
    igual(17500, $r['total'], 'total');
    igual(2500, $r['cambio'], 'cambio');
});
prueba('tarjeta: no suma efectivo', function () use ($fact, $T, $CAJERO, &$turnoId, $hamb, $db) {
    $r = $fact->emitir($T, $CAJERO, $turnoId, ['items' => [$hamb], 'forma_pago' => 'TARJETA']);
    igual(19900, $r['total'], 'total con promo');
    igual(0, (int) $db->query('SELECT monto_efectivo FROM ventas WHERE id = ' . $r['venta_id'])->fetchColumn(), 'efectivo');
});
prueba('mixto sin parte en efectivo válida se rechaza', fn () => rechaza('pago mixto', fn () => $fact->emitir($T, $CAJERO, $turnoId, ['items' => [$combo], 'forma_pago' => 'MIXTO', 'monto_efectivo' => 17500])));
prueba('mixto: L 100 en efectivo y el resto con tarjeta', function () use ($fact, $T, $CAJERO, &$turnoId, $combo, $db) {
    $r = $fact->emitir($T, $CAJERO, $turnoId, ['items' => [$combo], 'forma_pago' => 'MIXTO', 'monto_efectivo' => 10000]);
    igual(10000, (int) $db->query('SELECT monto_efectivo FROM ventas WHERE id = ' . $r['venta_id'])->fetchColumn());
});
prueba('la venta descarga inventario (componentes del combo y producto)', function () use ($T, $antesHamb, $antesDoble) {
    igual($antesHamb - 2, existencia($T, 1), 'hamburguesa clásica (2 combos)');
    igual($antesDoble - 2, existencia($T, 2), 'hamburguesa doble');
});

echo "Caja: movimientos y cierre\n";
prueba('retiro mayor al efectivo en caja se rechaza', fn () => rechaza('más efectivo', fn () => $caja->movimiento($turnoId, $CAJERO, 'RETIRO', 9999999, 'Remesa')));
prueba('retiro sin motivo se rechaza', fn () => rechaza('motivo', fn () => $caja->movimiento($turnoId, $CAJERO, 'RETIRO', 1000, ' ')));
prueba('retiro de L 500 a bóveda', fn () => $caja->movimiento($turnoId, $CAJERO, 'RETIRO', 50000, 'Remesa a bóveda'));
prueba('efectivo esperado = 1000 + 175 + 100 − 500 = L 775', fn () => igual(77500, $caja->resumen($turnoId)['efectivo_esperado']));
prueba('cierre con faltante y sin notas se rechaza', fn () => rechaza('faltante', fn () => $caja->cerrar($turnoId, $CAJERO, conteoExacto(77000), '')));
prueba('cierre con arqueo exacto: diferencia 0', function () use ($caja, $turnoId, $CAJERO) {
    $r = $caja->cerrar($turnoId, $CAJERO, conteoExacto(77500), '');
    igual(0, $r['diferencia']);
    igual(null, $caja->turnoAbierto($CAJERO));
});
prueba('no cierra dos veces', fn () => rechaza('ya está cerrado', fn () => $caja->cerrar($turnoId, $CAJERO, [], '')));
prueba('después del cierre no puede facturar', fn () => rechaza('no está abierto', fn () => $fact->emitir($T, $CAJERO, $turnoId, ['items' => [$combo]])));
prueba('cierre con sobrante y notas queda registrado', function () use ($caja, $T, $CAJERO) {
    $id = $caja->abrir($T, $CAJERO, 50000);
    $r = $caja->cerrar($id, $CAJERO, conteoExacto(52000), 'Cliente dejó propina');
    igual(2000, $r['diferencia']);
});

echo "Compras\n";
$datosCompra = fn (string $doc, array $lineas, string $fecha = '') => [
    'proveedor_id' => 1, 'numero_documento' => $doc, 'fecha_documento' => $fecha ?: date('Y-m-d'), 'lineas' => $lineas,
];
$antes = existencia($T, 1);
$costoAntes = costo($T, 1);
$compraId = 0;
prueba('compra sin líneas se rechaza', fn () => rechaza('al menos un producto', fn () => $compras->crear($T, $ADMIN, $datosCompra('F-1', []))));
prueba('cantidad cero se rechaza', fn () => rechaza('cantidad', fn () => $compras->crear($T, $ADMIN, $datosCompra('F-1', [['producto_id' => 1, 'cantidad' => 0, 'costo' => 3000]]))));
prueba('fecha futura se rechaza', fn () => rechaza('futuro', fn () => $compras->crear($T, $ADMIN, $datosCompra('F-1', [['producto_id' => 1, 'cantidad' => 1, 'costo' => 3000]], date('Y-m-d', strtotime('+2 days'))))));
prueba('borrador no mueve inventario', function () use ($compras, $T, $ADMIN, $datosCompra, &$compraId, $antes) {
    $compraId = $compras->crear($T, $ADMIN, $datosCompra('F-100', [['producto_id' => 1, 'cantidad' => 100, 'costo' => 5000, 'tipo_impuesto' => 'G15']]));
    igual($antes, existencia($T, 1));
});
prueba('total con ISV 15%: 100 × L 50 = 5,000 + 750', fn () => igual(575000, (int) $GLOBALS['db']->query("SELECT total FROM compras WHERE id = $compraId")->fetchColumn()));
prueba('misma factura del mismo proveedor se rechaza', fn () => rechaza('ya está registrada', fn () => $compras->crear($T, $ADMIN, $datosCompra('F-100', [['producto_id' => 1, 'cantidad' => 1, 'costo' => 1]]))));
prueba('recibir suma existencia y recalcula costo promedio', function () use ($compras, &$compraId, $ADMIN, $T, $antes, $costoAntes) {
    $compras->recibir($compraId, $ADMIN);
    igual($antes + 100, existencia($T, 1), 'existencia');
    igual((int) round(($antes * $costoAntes + 100 * 5000) / ($antes + 100)), costo($T, 1), 'costo promedio');
});
prueba('no se recibe dos veces', fn () => rechaza('ya fue recibida', fn () => $compras->recibir($compraId, $ADMIN)));
prueba('una compra recibida no se anula', fn () => rechaza('Solo se anulan compras en borrador', fn () => $compras->anular($compraId)));
prueba('anular borrador y reutilizar el número', function () use ($compras, $T, $ADMIN, $datosCompra) {
    $id = $compras->crear($T, $ADMIN, $datosCompra('F-200', [['producto_id' => 9, 'cantidad' => 24, 'costo' => 1200]]));
    $compras->anular($id);
    $compras->crear($T, $ADMIN, $datosCompra('F-200', [['producto_id' => 9, 'cantidad' => 24, 'costo' => 1200]]), true);
});

echo "Inventario: ajustes, mermas y traslados\n";
$enTx = function (callable $fn) use ($db) {
    $db->beginTransaction();
    try {
        $fn();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
};
prueba('merma sin motivo se rechaza', fn () => rechaza('motivo', fn () => $enTx(fn () => $inv->ajustar($T, 12, 'MERMA', 5, '', $ADMIN))));
prueba('merma mayor a la existencia se rechaza', fn () => rechaza('existencia suficiente', fn () => $enTx(fn () => $inv->ajustar($T, 12, 'MERMA', 99999, 'Vencido', $ADMIN))));
prueba('merma de 5 helados vencidos', function () use ($enTx, $inv, $T, $ADMIN) {
    $a = existencia($T, 12);
    $enTx(fn () => $inv->ajustar($T, 12, 'MERMA', 5, 'Vencido', $ADMIN));
    igual($a - 5, existencia($T, 12));
});
prueba('ajuste positivo por conteo físico', function () use ($enTx, $inv, $T, $ADMIN) {
    $a = existencia($T, 13);
    $enTx(fn () => $inv->ajustar($T, 13, 'AJUSTE', 3, 'Conteo físico', $ADMIN));
    igual($a + 3, existencia($T, 13));
});
prueba('traslado a la misma tienda se rechaza', fn () => rechaza('distinta', fn () => $enTx(fn () => $inv->trasladar($T, $T, 1, 1, $ADMIN))));
prueba('traslado mayor a la existencia se rechaza', fn () => rechaza('insuficiente', fn () => $enTx(fn () => $inv->trasladar($T, $T2, 1, 999999, $ADMIN))));
prueba('traslado de 10: sale de origen y entra al destino con costo promedio', function () use ($enTx, $inv, $T, $T2, $ADMIN) {
    [$o, $d, $co, $cd] = [existencia($T, 1), existencia($T2, 1), costo($T, 1), costo($T2, 1)];
    $enTx(fn () => $inv->trasladar($T, $T2, 1, 10, $ADMIN));
    igual($o - 10, existencia($T, 1), 'origen');
    igual($d + 10, existencia($T2, 1), 'destino');
    igual((int) round(($d * $cd + 10 * $co) / ($d + 10)), costo($T2, 1), 'costo destino');
});
prueba('kárdex cuadra: el saldo reconstruido parte de 0', function () use ($inv, $T) {
    foreach ([1, 2, 12, 13] as $pid) {
        $k = $inv->kardex($T, $pid, 1000);
        igual(existencia($T, $pid), $k[0]['saldo'], "saldo actual producto $pid");
        $ultimo = end($k);
        igual(0.0, round($ultimo['saldo'] - (float) $ultimo['cantidad'], 3), "saldo inicial producto $pid");
    }
});

echo "Productos y carga masiva\n";
$prod = new ProductosService($db, $inv);
prueba('SKU duplicado se rechaza', fn () => rechaza('ya existe', fn () => $prod->guardar(['sku' => 'HAM-CLA', 'nombre' => 'X', 'categoria_id' => 2, 'precio' => '10'])));
prueba('precio inválido se rechaza', fn () => rechaza('Precio inválido', fn () => $prod->guardar(['sku' => 'NUEVO-1', 'nombre' => 'X', 'categoria_id' => 2, 'precio' => 'abc'])));
prueba('producto nuevo crea su fila de inventario en todas las tiendas', function () use ($prod, $db) {
    $id = $prod->guardar(['sku' => 'NUEVO-1', 'nombre' => 'Wrap de pollo', 'categoria_id' => 3, 'precio' => '95.50', 'impuesto' => 'G15']);
    igual((int) $db->query('SELECT COUNT(*) FROM tiendas')->fetchColumn(), (int) $db->query("SELECT COUNT(*) FROM inventario WHERE producto_id = $id")->fetchColumn());
});
prueba('CSV con una fila mala no importa nada', function () use ($prod, $db) {
    $antes = (int) $db->query('SELECT COUNT(*) FROM productos')->fetchColumn();
    rechaza('Fila 3', fn () => $prod->importarCsv("sku,nombre,categoria,precio,impuesto\nCSV-1,Té frío,Bebidas,30.00,G15\nCSV-2,Malo,Bebidas,xx,G15\n"));
    igual($antes, (int) $db->query('SELECT COUNT(*) FROM productos')->fetchColumn());
});
prueba('CSV con ; (Excel en español): crea, actualiza y crea categoría', function () use ($prod, $db) {
    $r = $prod->importarCsv("sku;nombre;categoria;precio;impuesto;vendible_solo;controla_stock;minimo\nCSV-1;Té frío;Bebidas;30,00;G15;1;1;12\nHAM-CLA;Hamburguesa Clásica;Hamburguesas;125.00;15;;;\nENS-CES;Ensalada César;Ensaladas;89.00;G15;1;1;5\n");
    igual(['creados' => 2, 'actualizados' => 1], $r);
    igual(12500, (int) $db->query("SELECT precio_centavos FROM productos WHERE sku = 'HAM-CLA'")->fetchColumn(), 'precio actualizado');
    igual(1, (int) $db->query("SELECT COUNT(*) FROM categorias WHERE nombre = 'Ensaladas'")->fetchColumn(), 'categoría nueva');
    igual(12.0, (float) $db->query("SELECT minimo FROM inventario i JOIN productos p ON p.id = i.producto_id WHERE p.sku = 'CSV-1' LIMIT 1")->fetchColumn(), 'mínimo');
});

echo "Configuración: sucursales y CAI por caja\n";
$tiendas = new TiendasService($db);
$cais = new CaiService($db);
$T4 = 0;
$suc = fn (array $d = []) => $d + ['codigo' => 'CHO-01', 'nombre' => 'Power Choluteca', 'direccion' => 'Blvd. Enrique Weddle', 'telefono' => '', 'codigo_establecimiento' => '004'];
$enUnAnio = date('Y-m-d', strtotime('+1 year'));
// Closure con referencia: $T4 se conoce hasta que se crea la sucursal
$cai = function (array $d = []) use (&$T4, $enUnAnio): array {
    return $d + ['tienda_id' => $T4, 'cai' => 'AAAAAA-000001-BBBBBB-000001-CCCCCC-01', 'punto_emision' => '001',
        'rango_inicial' => 1, 'rango_final' => 500, 'fecha_limite' => $enUnAnio];
};
$numero = fn (int $ventaId) => $db->query("SELECT numero_factura FROM ventas WHERE id = $ventaId")->fetchColumn();

prueba('establecimiento SAR repetido se rechaza', fn () => rechaza('ya lo usa', fn () => $tiendas->guardar($suc(['codigo_establecimiento' => '001']))));
prueba('establecimiento que no tiene 3 dígitos se rechaza', fn () => rechaza('3 dígitos', fn () => $tiendas->guardar($suc(['codigo_establecimiento' => '12']))));
prueba('sucursal sin dirección se rechaza', fn () => rechaza('dirección', fn () => $tiendas->guardar($suc(['direccion' => ' ']))));
prueba('crear sucursal: inventario en cero para todos los productos y mínimos copiados', function () use ($tiendas, $suc, $db, &$T4) {
    $T4 = $tiendas->guardar($suc(['copiar_de' => 1]));
    igual((int) $db->query('SELECT COUNT(*) FROM productos')->fetchColumn(), (int) $db->query("SELECT COUNT(*) FROM inventario WHERE tienda_id = $T4")->fetchColumn(), 'filas de inventario');
    igual(0.0, (float) $db->query("SELECT SUM(existencia) FROM inventario WHERE tienda_id = $T4")->fetchColumn(), 'existencia');
    igual((float) $db->query('SELECT minimo FROM inventario WHERE tienda_id = 1 AND producto_id = 2')->fetchColumn(),
        (float) $db->query("SELECT minimo FROM inventario WHERE tienda_id = $T4 AND producto_id = 2")->fetchColumn(), 'mínimo copiado');
});
prueba('sucursal nueva sin CAI: alerta roja y no puede facturar', function () use ($cais, $caja, $fact, $combo, $ADMIN, &$T4) {
    igual(['error'], array_column($cais->alertas($T4, 30, 200), 'nivel'));
    $t = $caja->abrir($T4, $ADMIN, 0);
    rechaza('No hay CAI vigente', fn () => $fact->emitir($T4, $ADMIN, $t, ['items' => [$combo], 'forma_pago' => 'TARJETA']));
    $caja->cerrar($t, $ADMIN, [], '');
});
prueba('CAI con formato inválido se rechaza', fn () => rechaza('formato', fn () => $cais->registrar($cai(['cai' => 'ABC-123']))));
prueba('CAI con fecha límite vencida se rechaza', fn () => rechaza('ya pasó', fn () => $cais->registrar($cai(['fecha_limite' => date('Y-m-d', strtotime('-1 day'))]))));
prueba('CAI con rango invertido se rechaza', fn () => rechaza('Rango inválido', fn () => $cais->registrar($cai(['rango_inicial' => 500, 'rango_final' => 1]))));
prueba('registrar CAI para la caja 001 y la caja 002 (mismo rango, distinto punto de emisión)', function () use ($cais, $cai, $caja, &$T4) {
    $cais->registrar($cai());
    $cais->registrar($cai(['cai' => 'AAAAAA-000002-BBBBBB-000002-CCCCCC-02', 'punto_emision' => '002']));
    igual(['001', '002'], array_column($caja->puntosEmision($T4), 'punto_emision'));
    igual([], $cais->alertas($T4, 30, 200), 'sin alertas');
});
prueba('rango que se cruza con otro CAI de la misma caja se rechaza', fn () => rechaza('se cruza', fn () => $cais->registrar($cai(['cai' => 'AAAAAA-000003-BBBBBB-000003-CCCCCC-03', 'rango_inicial' => 400, 'rango_final' => 900]))));
prueba('el mismo CAI no se registra dos veces', fn () => rechaza('ya está registrado', fn () => $cais->registrar($cai(['punto_emision' => '003']))));
prueba('con varias cajas hay que elegir punto de emisión al abrir turno', fn () => rechaza('varias cajas', fn () => $caja->abrir($T4, $ADMIN, 0)));
prueba('abrir en una caja sin CAI se rechaza', fn () => rechaza('no tiene un CAI vigente', fn () => $caja->abrir($T4, $ADMIN, 0, '009')));
prueba('el turno de la caja 002 factura con el prefijo 004-002', function () use ($caja, $fact, $combo, $numero, $ADMIN, &$T4) {
    $t = $caja->abrir($T4, $ADMIN, 0, '002');
    $r = $fact->emitir($T4, $ADMIN, $t, ['items' => [$combo], 'forma_pago' => 'TARJETA']);
    igual('004-002-01-00000001', $numero($r['venta_id']));
    $caja->cerrar($t, $ADMIN, [], '');
});
prueba('el turno de la caja 001 usa su propio correlativo', function () use ($caja, $fact, $combo, $numero, $ADMIN, &$T4) {
    $t = $caja->abrir($T4, $ADMIN, 0, '001');
    $r = $fact->emitir($T4, $ADMIN, $t, ['items' => [$combo], 'forma_pago' => 'TARJETA']);
    igual('004-001-01-00000001', $numero($r['venta_id']));
    $caja->cerrar($t, $ADMIN, [], '');
});
prueba('no se cambia el establecimiento de una sucursal que ya facturó', fn () => rechaza('ya emitió facturas', fn () => $tiendas->guardar($suc(['id' => $T4, 'codigo_establecimiento' => '009']))));
prueba('sí se pueden editar nombre y dirección', function () use ($tiendas, $suc, $db, &$T4) {
    $tiendas->guardar($suc(['id' => $T4, 'nombre' => 'Power Choluteca Centro']));
    igual('Power Choluteca Centro', $db->query("SELECT nombre FROM tiendas WHERE id = $T4")->fetchColumn());
});
prueba('no se desactiva una sucursal con turno abierto; sin turnos sí', function () use ($tiendas, $caja, $cais, $cai, $ADMIN, &$T4) {
    $t = $caja->abrir($T4, $ADMIN, 0, '001');
    rechaza('turnos de caja abiertos', fn () => $tiendas->alternarActiva($T4));
    $caja->cerrar($t, $ADMIN, [], '');
    igual(false, $tiendas->alternarActiva($T4), 'queda inactiva');
    rechaza('sucursal activa', fn () => $cais->registrar($cai(['cai' => 'AAAAAA-000009-BBBBBB-000009-CCCCCC-09', 'punto_emision' => '005'])));
    igual(true, $tiendas->alternarActiva($T4), 'queda activa');
});
prueba('alerta de CAI por agotarse; desaparece al registrar el siguiente rango de esa caja', function () use ($cais, $cai, $db, &$T4) {
    $db->exec("UPDATE cai SET correlativo_actual = 450 WHERE tienda_id = $T4 AND punto_emision = '001'");
    $a = $cais->alertas($T4, 30, 200);
    igual(['alerta'], array_column($a, 'nivel'));
    igual('001', $a[0]['punto_emision']);
    $cais->registrar($cai(['cai' => 'AAAAAA-000004-BBBBBB-000004-CCCCCC-04', 'rango_inicial' => 501, 'rango_final' => 5000]));
    igual([], $cais->alertas($T4, 30, 200));
});
prueba('al agotarse un CAI la caja sigue con el siguiente rango registrado', function () use ($caja, $fact, $combo, $numero, $db, $ADMIN, &$T4) {
    $db->exec("UPDATE cai SET correlativo_actual = 500 WHERE tienda_id = $T4 AND punto_emision = '001' AND rango_final = 500");
    $t = $caja->abrir($T4, $ADMIN, 0, '001');
    $r = $fact->emitir($T4, $ADMIN, $t, ['items' => [$combo], 'forma_pago' => 'TARJETA']);
    igual('004-001-01-00000501', $numero($r['venta_id']));
    $caja->cerrar($t, $ADMIN, [], '');
});

echo "Configuración: usuarios, parámetros y menús\n";
$usuarios = new UsuariosService($db);
$nuevoId = 0;
$usr = fn (array $d = []) => $d + ['nombre' => 'Ana Cajera', 'email' => 'ana@power.hn', 'rol' => 'CAJERO', 'tienda_id' => $T4, 'clave' => 'Temporal2026'];
$clave = fn (int $id) => $db->query("SELECT password_hash FROM usuarios WHERE id = $id")->fetchColumn();
prueba('cajero sin sucursal se rechaza', fn () => rechaza('debe pertenecer a una sucursal', fn () => $usuarios->guardar($usr(['tienda_id' => '']), $ADMIN)));
prueba('correo repetido se rechaza', fn () => rechaza('ya está registrado', fn () => $usuarios->guardar($usr(['email' => 'CAJA1@power.hn']), $ADMIN)));
prueba('contraseña débil se rechaza', fn () => rechaza('al menos 8', fn () => $usuarios->guardar($usr(['clave' => 'abc123']), $ADMIN)));
prueba('crear cajero de la sucursal nueva con contraseña temporal', function () use ($usuarios, $usr, $clave, $ADMIN, &$nuevoId) {
    $nuevoId = $usuarios->guardar($usr(), $ADMIN);
    igual(true, password_verify('Temporal2026', $clave($nuevoId)));
});
prueba('un administrador siempre queda sin sucursal fija', function () use ($usuarios, $usr, $db, $ADMIN) {
    $id = $usuarios->guardar($usr(['email' => 'admin2@power.hn', 'rol' => 'ADMIN', 'tienda_id' => 1]), $ADMIN);
    igual(null, $db->query("SELECT tienda_id FROM usuarios WHERE id = $id")->fetchColumn());
});
prueba('nadie se quita su propio rol ni se desactiva', fn () => rechaza('No puede desactivarse', fn () => $usuarios->guardar(
    ['id' => $ADMIN, 'nombre' => 'Administrador', 'email' => 'admin@power.hn', 'rol' => 'GERENTE', 'activo' => 1], $ADMIN)));
prueba('debe quedar al menos un administrador activo', function () use ($usuarios, $db, $ADMIN) {
    $db->exec("UPDATE usuarios SET activo = 0 WHERE email = 'admin2@power.hn'");
    rechaza('al menos un administrador', fn () => $usuarios->guardar(
        ['id' => $ADMIN, 'nombre' => 'Administrador', 'email' => 'admin@power.hn', 'rol' => 'ADMIN'], 999));
});
prueba('no se desactiva ni se cambia de sucursal a quien tiene turno abierto', function () use ($usuarios, $caja, $CAJERO, $ADMIN, $T) {
    $t = $caja->abrir($T, $CAJERO, 0);
    $datos = ['id' => $CAJERO, 'nombre' => 'Cajero Morazán', 'email' => 'caja1@power.hn', 'rol' => 'CAJERO'];
    rechaza('turno de caja abierto', fn () => $usuarios->guardar($datos + ['tienda_id' => $T], $ADMIN));
    rechaza('antes de cambiarlo de sucursal', fn () => $usuarios->guardar($datos + ['tienda_id' => 2, 'activo' => 1], $ADMIN));
    $caja->cerrar($t, $CAJERO, [], '');
});
prueba('cambiar la contraseña propia exige la actual y que coincidan', function () use ($usuarios, $clave, &$nuevoId) {
    rechaza('no es correcta', fn () => $usuarios->cambiarPropiaClave($nuevoId, 'equivocada', 'Nueva2026x', 'Nueva2026x'));
    rechaza('no coinciden', fn () => $usuarios->cambiarPropiaClave($nuevoId, 'Temporal2026', 'Nueva2026x', 'Otra2026xx'));
    rechaza('distinta de la actual', fn () => $usuarios->cambiarPropiaClave($nuevoId, 'Temporal2026', 'Temporal2026', 'Temporal2026'));
    $usuarios->cambiarPropiaClave($nuevoId, 'Temporal2026', 'Nueva2026x', 'Nueva2026x');
    igual(true, password_verify('Nueva2026x', $clave($nuevoId)));
});
prueba('el administrador restablece una contraseña olvidada', function () use ($usuarios, $clave, &$nuevoId) {
    $usuarios->restablecerClave($nuevoId, 'Reinicio2026');
    igual(true, password_verify('Reinicio2026', $clave($nuevoId)));
});
$par = fn (array $d = []) => $d + ['cai_alerta_facturas' => '150', 'cai_alerta_dias' => '45', 'fondo_caja_sugerido' => '1,500.00', 'mensaje_factura' => 'Vuelva pronto'];
prueba('parámetro que no es número se rechaza', fn () => rechaza('número entero', fn () => Parametros::guardar($par(['cai_alerta_dias' => 'treinta']))));
prueba('guardar parámetros y leerlos', function () use ($par) {
    Parametros::guardar($par());
    igual(150, Parametros::entero('cai_alerta_facturas'));
    igual('1500.00', Parametros::texto('fondo_caja_sugerido'));
    igual('Vuelva pronto', Parametros::texto('mensaje_factura'));
});
prueba('menú de tienda para cajero y gerente de tienda; menú administrativo para oficina central', function () {
    $u = fn (string $rol, ?int $tienda) => ['id' => 9, 'nombre' => 'X', 'rol' => $rol, 'tienda_id' => $tienda];
    igual(['Vender', 'Turno de caja', 'Facturas'], array_column(Menu::para($u('CAJERO', 1)), 'texto'), 'cajero');
    igual(['Vender', 'Turno de caja', 'Facturas', 'Mi tienda'], array_column(Menu::para($u('GERENTE', 1)), 'texto'), 'gerente de tienda');
    igual(['Tablero', 'Ventas', 'Catálogo', 'Abastecimiento'], array_column(Menu::para($u('GERENTE', null)), 'texto'), 'gerente central');
    igual(['Tablero', 'Ventas', 'Catálogo', 'Abastecimiento', 'Configuración'], array_column(Menu::para($u('ADMIN', null)), 'texto'), 'administrador');
});
prueba('permisos: el catálogo y la configuración son de oficina central', function () {
    $u = fn (string $rol, ?int $tienda) => ['id' => 9, 'nombre' => 'X', 'rol' => $rol, 'tienda_id' => $tienda];
    igual([false, false, true, true], array_map(fn ($p) => Menu::puede($u('GERENTE', 1), $p), ['productos', 'configuracion', 'inventario', 'erp']), 'gerente de tienda');
    igual([true, false], array_map(fn ($p) => Menu::puede($u('GERENTE', null), $p), ['productos', 'configuracion']), 'gerente central');
    igual([false, false, true, true], array_map(fn ($p) => Menu::puede($u('CAJERO', 1), $p), ['inventario', 'erp', 'facturas', 'cuenta']), 'cajero');
    igual(false, Menu::puede($u('ADMIN', null), 'pagina-que-no-existe'), 'página desconocida');
});

echo "\n$ok/" . ($ok + $fallos) . " pruebas de integración OK\n";
exit($fallos ? 1 : 0);
