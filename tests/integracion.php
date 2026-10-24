<?php
declare(strict_types=1);

/*
 * Pruebas de integración contra MySQL/MariaDB: caja (apertura/cierre), ventas, compras e inventario.
 * ¡Usa una base de PRUEBA! Cargue schema.sql + seed.sql en una BD vacía y ejecute:
 *   DB_DSN='mysql:host=127.0.0.1;dbname=power_pos;charset=utf8mb4' DB_USER=... DB_PASS=... php tests/integracion.php
 */
use App\Caja\CajaService;
use App\Catalogo\CatalogoRepository;
use App\Catalogo\ProductosService;
use App\Compras\ComprasService;
use App\Inventario\InventarioService;
use App\Ventas\CarritoService;
use App\Ventas\FacturacionService;

require __DIR__ . '/_ayuda.php';

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

echo "\n$ok/" . ($ok + $fallos) . " pruebas de integración OK\n";
exit($fallos ? 1 : 0);
