<?php
declare(strict_types=1);

/*
 * Pruebas del módulo de restaurante contra MySQL/MariaDB: mesas, meseros, comandas a cocina,
 * cuenta dividida, propina y personal. Use una base de PRUEBA recién cargada con schema.sql + seed.sql:
 *   DB_DSN='mysql:host=127.0.0.1;dbname=power_pos;charset=utf8mb4' DB_USER=... DB_PASS=... php tests/restaurante.php
 */
use App\Caja\CajaService;
use App\Catalogo\CatalogoRepository;
use App\Catalogo\ProductosService;
use App\Cocina\ComandasService;
use App\Inventario\InventarioService;
use App\Mesas\CuentasService;
use App\Mesas\MesasService;
use App\Usuarios\UsuariosService;
use App\Ventas\CarritoService;
use App\Ventas\FacturacionService;

require __DIR__ . '/_ayuda.php';

$T = 1;          // Power Morazán (tiene salón)
$T2 = 2;         // Power Circunvalación (solo mostrador)
[$ADMIN, $CAJERO, $ANA, $LUIS, $COCINA] = [1, 2, 3, 4, 5];
[$EST_COCINA, $EST_BAR] = [1, 2];
$mesa = fn (string $nombre) => (int) $db->query('SELECT id FROM mesas WHERE tienda_id = 1 AND nombre = ' . $db->quote($nombre))->fetchColumn();

$carrito = new CarritoService(new CatalogoRepository($db));
$comandas = new ComandasService($db);
$cuentas = new CuentasService($db, $carrito, $comandas);
$fact = new FacturacionService($db, $carrito);
$caja = new CajaService($db);
$salon = new MesasService($db);

$combo = ['tipo' => 'COMBO', 'combo_id' => 1, 'cantidad' => 2, 'opciones' => [1, 6, 9], 'nota' => 'Sin cebolla'];  // Clásica + papas + refresco
$cerveza = ['tipo' => 'PRODUCTO', 'producto_id' => 14, 'cantidad' => 2];
$helado = ['tipo' => 'PRODUCTO', 'producto_id' => 12, 'cantidad' => 1];
$cuentaId = 0;
$deEstacion = fn (array $ids, int $est) => array_values(array_filter(array_map(fn ($id) => $comandas->obtener($T, $id), $ids), fn ($k) => $k['estacion_id'] === $est));
$item = fn (int $cuenta, string $desc) => current(array_filter($cuentas->obtener($T, $cuenta)['items'], fn ($i) => $i['descripcion'] === $desc));

echo "Abrir mesa\n";
prueba('Ana abre la mesa 1 con 4 personas', function () use ($cuentas, $T, $mesa, $ANA, &$cuentaId) {
    $cuentaId = $cuentas->abrir($T, $mesa('1'), $ANA, 4);
    $c = $cuentas->obtener($T, $cuentaId);
    igual(['1', 'Ana (mesera)', 4, 'ABIERTA'], [$c['mesa'], $c['mesero'], $c['comensales'], $c['estado']]);
});
prueba('la misma mesa no se abre dos veces', fn () => rechaza('ya está ocupada', fn () => $cuentas->abrir($T, $mesa('1'), $LUIS, 2)));
prueba('cero comensales se rechaza', fn () => rechaza('cuántas personas', fn () => $cuentas->abrir($T, $mesa('2'), $ANA, 0)));
prueba('una mesa de otra tienda no se puede abrir', fn () => rechaza('no encontrada', fn () => $cuentas->abrir($T2, $mesa('2'), $ANA, 2)));
prueba('el usuario de cocina no puede atender mesas', fn () => rechaza('Mesero no válido', fn () => $cuentas->abrir($T, $mesa('2'), $COCINA, 2)));
prueba('el salón muestra la mesa 1 ocupada por Ana', function () use ($salon, $T, $mesa) {
    $mesas = array_merge(...array_column($salon->salon($T), 'mesas'));
    $m1 = current(array_filter($mesas, fn ($m) => $m['id'] === $mesa('1')));
    igual('Ana (mesera)', $m1['cuenta']['mesero']);
    igual(12, count($mesas), 'mesas en el salón');
});

echo "Enviar a cocina: comandas por estación\n";
$ronda1 = [];
prueba('combo con opción ajena se rechaza y no guarda nada', function () use ($cuentas, $T, &$cuentaId, $ANA, $db) {
    rechaza('no pertenecen', fn () => $cuentas->enviar($T, $cuentaId, $ANA, [['tipo' => 'COMBO', 'combo_id' => 1, 'cantidad' => 1, 'opciones' => [1, 6, 9, 14]]]));
    igual(0, (int) $db->query("SELECT COUNT(*) FROM cuenta_items WHERE cuenta_id = $cuentaId")->fetchColumn());
});
prueba('ronda 1: el combo se reparte entre Cocina y Bar', function () use ($cuentas, $T, &$cuentaId, $ANA, $combo, $cerveza, $helado, &$ronda1, $deEstacion, $EST_COCINA, $EST_BAR) {
    $r = $cuentas->enviar($T, $cuentaId, $ANA, [$combo, $cerveza, $helado]);
    igual(1, $r['ronda'], 'ronda');
    igual(2, count($r['comandas']), 'comandas');
    $ronda1 = $r['comandas'];
    $cocina = $deEstacion($r['comandas'], $EST_COCINA)[0];
    $bar = $deEstacion($r['comandas'], $EST_BAR)[0];
    igual([['Combo Clásico', 'Hamburguesa Clásica, Papas Medianas', 'Sin cebolla', 2], ['Helado de Vainilla', '', null, 1]],
        array_map(fn ($i) => [$i['descripcion'], $i['detalle'], $i['nota'], $i['cantidad']], $cocina['items']), 'cocina');
    igual([['Combo Clásico', 'Refresco Mediano'], ['Cerveza Nacional', '']],
        array_map(fn ($i) => [$i['descripcion'], $i['detalle']], $bar['items']), 'bar');
    igual('Mesa 1', $cocina['referencia']);
});
prueba('la pantalla de Cocina la muestra con mesero y comensales', function () use ($comandas, $T, $EST_COCINA) {
    $p = $comandas->pantalla($T, $EST_COCINA);
    igual(1, count($p['activas']));
    igual(['Mesa 1', 'Ana (mesera)', 4], [$p['activas'][0]['referencia'], $p['activas'][0]['mesero'], $p['activas'][0]['comensales']]);
});
prueba('la vista de todas las estaciones muestra las dos', fn () => igual(2, count($comandas->pantalla($T, null)['activas'])));
prueba('otra tienda no ve las comandas', fn () => igual(0, count($comandas->pantalla($T2, null)['activas'])));

echo "Cocina: listo, despachar y recuperar\n";
prueba('marcar un artículo deja la comanda en cocina; el último la despacha sola', function () use ($comandas, $T, &$ronda1, $deEstacion, $EST_COCINA) {
    $k = $deEstacion($ronda1, $EST_COCINA)[0];
    $comandas->marcarItem($T, $k['items'][0]['id'], true);
    igual('EN_COCINA', $comandas->obtener($T, $k['id'])['estado']);
    $comandas->marcarItem($T, $k['items'][1]['id'], true);
    igual('LISTA', $comandas->obtener($T, $k['id'])['estado']);
    igual($k['id'], $comandas->pantalla($T, $EST_COCINA)['recientes'][0]['id'], 'aparece en recientes');
});
prueba('recuperar la devuelve a la pantalla y despachar la saca', function () use ($comandas, $T, &$ronda1, $deEstacion, $EST_COCINA) {
    $id = $deEstacion($ronda1, $EST_COCINA)[0]['id'];
    $comandas->recuperar($T, $id);
    igual(1, count($comandas->pantalla($T, $EST_COCINA)['activas']));
    $comandas->despachar($T, $id);
    igual(0, count($comandas->pantalla($T, $EST_COCINA)['activas']));
    rechaza('ya salió de cocina', fn () => $comandas->despachar($T, $id));
});
prueba('la cuenta muestra el helado listo y el combo esperando al bar', function () use ($item, &$cuentaId) {
    igual('LISTO', $item($cuentaId, 'Helado de Vainilla')['cocina']);
    igual('EN_COCINA', $item($cuentaId, 'Combo Clásico')['cocina']);
});
prueba('el mesero entrega lo de cocina', function () use ($comandas, $cuentas, $T, &$cuentaId, &$ronda1, $deEstacion, $EST_COCINA, $item) {
    igual(1, count($cuentas->obtener($T, $cuentaId)['por_entregar']), 'por entregar');
    $comandas->entregar($T, $deEstacion($ronda1, $EST_COCINA)[0]['id']);
    igual('ENTREGADO', $item($cuentaId, 'Helado de Vainilla')['cocina']);
    igual(0, count($cuentas->obtener($T, $cuentaId)['por_entregar']));
});
prueba('la impresión automática nunca imprime dos veces', function () use ($comandas, $T, &$ronda1) {
    igual(true, $comandas->reclamarImpresion($T, $ronda1[0]));
    igual(false, $comandas->reclamarImpresion($T, $ronda1[0]));
    igual(false, $comandas->reclamarImpresion(2, $ronda1[1]), 'otra tienda');
});

echo "Transferir y anular\n";
$cuentaLuis = 0;
prueba('transferir a la mesa 2 actualiza las comandas pendientes', function () use ($cuentas, $comandas, $T, &$cuentaId, $mesa, &$ronda1, $deEstacion, $EST_BAR) {
    $cuentas->transferir($T, $cuentaId, $mesa('2'));
    igual('2', $cuentas->obtener($T, $cuentaId)['mesa']);
    igual('Mesa 2', $deEstacion($ronda1, $EST_BAR)[0]['referencia']);
    igual(null, $cuentas->activaDeMesa($T, $mesa('1')), 'mesa 1 libre');
});
prueba('no se transfiere a una mesa ocupada', function () use ($cuentas, $T, &$cuentaId, &$cuentaLuis, $mesa, $LUIS) {
    $cuentaLuis = $cuentas->abrir($T, $mesa('3'), $LUIS, 2);
    rechaza('ocupada', fn () => $cuentas->transferir($T, $cuentaId, $mesa('3')));
});
prueba('anular lo enviado exige motivo', fn () => rechaza('motivo', fn () => $cuentas->anularItem($T, $item($cuentaId, 'Cerveza Nacional')['id'], $ADMIN, ' ')));
prueba('anular la cerveza avisa al bar con una comanda de anulación', function () use ($cuentas, $comandas, $T, &$cuentaId, $ADMIN, $item, $EST_BAR) {
    $cuentas->anularItem($T, $item($cuentaId, 'Cerveza Nacional')['id'], $ADMIN, 'Cliente cambió a refresco');
    igual('ANULADO', $item($cuentaId, 'Cerveza Nacional')['cocina']);
    $bar = $comandas->pantalla($T, $EST_BAR)['activas'];
    $anulacion = current(array_filter($bar, fn ($k) => $k['tipo'] === 'ANULACION'));
    igual(['Mesa 2', 'Cerveza Nacional', 2], [$anulacion['referencia'], $anulacion['items'][0]['descripcion'], $anulacion['items'][0]['cantidad']]);
    $pedido = current(array_filter($bar, fn ($k) => $k['tipo'] === 'PEDIDO'));
    igual([false, true], array_column($pedido['items'], 'anulado'), 'la cerveza aparece tachada en la comanda original');
});
prueba('no se anula dos veces', fn () => rechaza('ya está anulado', fn () => $cuentas->anularItem($T, $item($cuentaId, 'Cerveza Nacional')['id'], $ADMIN, 'x')));

echo "Cobro: cuenta dividida y propina\n";
$turno = 0;
$totalCombo = 0;
prueba('la cuenta cuesta lo mismo que en la caja (promociones incluidas)', function () use ($cuentas, $carrito, $T, &$cuentaId, $combo, $helado) {
    $mesa = $cuentas->cotizar($T, $cuentaId);
    $caja = $carrito->cotizar($T, [$combo, $helado], null);
    igual($caja['resumen']['total'], $mesa['resumen']['total']);
    igual(2, count($mesa['item_ids']), 'la cerveza anulada no se cobra');
});
prueba('sin turno de caja no se cobra', fn () => rechaza('no está abierto', fn () => $cuentas->cobrar($T, $cuentaId, $CAJERO, 999, ['item_ids' => [$item($cuentaId, 'Helado de Vainilla')['id']]], $fact)));
prueba('cobrar sin elegir artículos se rechaza', function () use ($caja, $cuentas, $fact, $T, &$cuentaId, $CAJERO, &$turno) {
    $turno = $caja->abrir($T, $CAJERO, 100000);
    rechaza('Seleccione', fn () => $cuentas->cobrar($T, $cuentaId, $CAJERO, $turno, ['item_ids' => []], $fact));
});
prueba('propina mayor que la factura se rechaza sin gastar correlativo', function () use ($cuentas, $fact, $T, &$cuentaId, $CAJERO, &$turno, $item, $db) {
    $antes = (int) $db->query('SELECT correlativo_actual FROM cai WHERE tienda_id = 1')->fetchColumn();
    rechaza('propina no puede ser mayor', fn () => $cuentas->cobrar($T, $cuentaId, $CAJERO, $turno,
        ['item_ids' => [$item($cuentaId, 'Helado de Vainilla')['id']], 'forma_pago' => 'TARJETA', 'propina' => 999999], $fact));
    igual($antes, (int) $db->query('SELECT correlativo_actual FROM cai WHERE tienda_id = 1')->fetchColumn(), 'correlativo');
    igual(null, $item($cuentaId, 'Helado de Vainilla')['venta_id'], 'sigue sin cobrar');
});
prueba('primera factura: solo el helado, con tarjeta; la mesa sigue abierta', function () use ($cuentas, $fact, $T, &$cuentaId, $CAJERO, &$turno, $item) {
    $r = $cuentas->cobrar($T, $cuentaId, $CAJERO, $turno, ['item_ids' => [$item($cuentaId, 'Helado de Vainilla')['id']], 'forma_pago' => 'TARJETA'], $fact);
    igual(false, $r['cuenta_cerrada']);
    igual($r['venta_id'], $item($cuentaId, 'Helado de Vainilla')['venta_id']);
    igual('ABIERTA', $cuentas->obtener($T, $cuentaId)['estado']);
});
prueba('lo ya cobrado no se cobra otra vez', fn () => rechaza('ya se cobraron', fn () => $cuentas->cobrar($T, $cuentaId, $CAJERO, $turno, ['item_ids' => [$item($cuentaId, 'Helado de Vainilla')['id']]], $fact)));
prueba('segunda factura: el combo en efectivo con L 35 de propina cierra la cuenta', function () use ($cuentas, $fact, $T, &$cuentaId, $CAJERO, &$turno, $item, $mesa, $db, &$totalCombo) {
    $antes = existencia($T, 1);
    $r = $cuentas->cobrar($T, $cuentaId, $CAJERO, $turno, ['item_ids' => [$item($cuentaId, 'Combo Clásico')['id']], 'forma_pago' => 'EFECTIVO',
        'efectivo_recibido' => 50000, 'propina' => 3500, 'propina_forma' => 'EFECTIVO'], $fact);
    $totalCombo = $r['total'];
    igual(50000 - 3500 - $totalCombo, $r['cambio'], 'cambio descontando la propina en efectivo');
    igual(true, $r['cuenta_cerrada']);
    igual('COBRADA', $cuentas->obtener($T, $cuentaId)['estado']);
    igual(null, $cuentas->activaDeMesa($T, $mesa('2')), 'mesa 2 libre');
    $v = $db->query('SELECT cuenta_id, propina, propina_efectivo, total FROM ventas WHERE id = ' . $r['venta_id'])->fetch();
    igual([$cuentaId, 3500, 3500], [(int) $v['cuenta_id'], (int) $v['propina'], (int) $v['propina_efectivo']]);
    igual(2, count($cuentas->obtener($T, $cuentaId)['facturas']), 'facturas de la cuenta');
    igual($antes - 2, existencia($T, 1), 'inventario de la hamburguesa del combo');
});
prueba('el arqueo espera la propina en efectivo (no la de tarjeta)', function () use ($caja, &$turno, &$totalCombo) {
    $r = $caja->resumen($turno);
    igual(100000 + $totalCombo + 3500, $r['efectivo_esperado']);
    igual(3500, $r['propinas_efectivo']);
});
prueba('una cuenta cerrada ya no recibe pedidos', fn () => rechaza('ya está cerrada', fn () => $cuentas->enviar($T, $cuentaId, $ANA, [$helado])));

echo "Pedir la cuenta y liberar mesas\n";
prueba('no se pide la cuenta sin artículos', fn () => rechaza('no tiene artículos', fn () => $cuentas->pedirCuenta($T, $cuentaLuis)));
prueba('pedir la cuenta y luego pedir más la regresa a servicio', function () use ($cuentas, $T, &$cuentaLuis, $LUIS, $helado) {
    $cuentas->enviar($T, $cuentaLuis, $LUIS, [$helado]);
    $cuentas->pedirCuenta($T, $cuentaLuis);
    igual('POR_COBRAR', $cuentas->obtener($T, $cuentaLuis)['estado']);
    igual(2, $cuentas->enviar($T, $cuentaLuis, $LUIS, [$helado])['ronda'], 'ronda');
    igual('ABIERTA', $cuentas->obtener($T, $cuentaLuis)['estado']);
});
prueba('no se libera una mesa con artículos activos', fn () => rechaza('cóbrelos o anúlelos', fn () => $cuentas->anular($T, $cuentaLuis, $ADMIN, 'Se fueron')));
prueba('anular todo lo pendiente no cierra la cuenta como cobrada; liberar sí', function () use ($cuentas, $T, &$cuentaLuis, $ADMIN, $mesa) {
    foreach ($cuentas->obtener($T, $cuentaLuis)['items'] as $i) {
        $cuentas->anularItem($T, $i['id'], $ADMIN, 'Se fueron sin consumir');
    }
    igual('ABIERTA', $cuentas->obtener($T, $cuentaLuis)['estado']);
    $cuentas->anular($T, $cuentaLuis, $ADMIN, 'Se fueron sin consumir');
    igual('ANULADA', $cuentas->obtener($T, $cuentaLuis)['estado']);
    igual(null, $cuentas->activaDeMesa($T, $mesa('3')));
});

echo "Mostrador y rutas de cocina\n";
prueba('la venta de mostrador sale a cocina con el número para llamar al cliente', function () use ($fact, $comandas, $T, $CAJERO, &$turno, $EST_BAR, $db) {
    $r = $fact->emitir($T, $CAJERO, $turno, ['items' => [['tipo' => 'PRODUCTO', 'producto_id' => 10, 'cantidad' => 1]]],
        $comandas->alFacturarMostrador($T, $CAJERO, 'CONSUMIDOR FINAL'));
    $k = $db->query('SELECT * FROM comandas WHERE venta_id = ' . $r['venta_id'])->fetch();
    igual([$EST_BAR, 'Mostrador · #' . substr($r['numero'], -4)], [(int) $k['estacion_id'], $k['referencia']]);
});
prueba('con nombre del cliente la referencia lo lleva', function () use ($fact, $comandas, $T, $CAJERO, &$turno, $db) {
    $r = $fact->emitir($T, $CAJERO, $turno, ['items' => [['tipo' => 'PRODUCTO', 'producto_id' => 6, 'cantidad' => 1]]],
        $comandas->alFacturarMostrador($T, $CAJERO, 'Carlos'));
    igual('Mostrador · Carlos', $db->query('SELECT referencia FROM comandas WHERE venta_id = ' . $r['venta_id'])->fetchColumn());
});
prueba('el combo llega a la estación de su categoría aunque todo lo elegible vaya a otra', function () use ($db, $cuentas, $comandas, $T, $LUIS, $mesa, $deEstacion, $EST_COCINA, $EST_BAR) {
    $db->exec('UPDATE categorias SET estacion_id = 2 WHERE id = 4'); // Acompañantes al Bar: el pollo (fijo) sigue en Cocina
    $c = $cuentas->abrir($T, $mesa('6'), $LUIS, 2);
    $ids = $cuentas->enviar($T, $c, $LUIS, [['tipo' => 'COMBO', 'combo_id' => 2, 'cantidad' => 1, 'opciones' => [6, 9]]])['comandas'];
    igual(['Combo Pollo', ''], [$deEstacion($ids, $EST_COCINA)[0]['items'][0]['descripcion'], $deEstacion($ids, $EST_COCINA)[0]['items'][0]['detalle']]);
    igual('Papas Medianas, Refresco Mediano', $deEstacion($ids, $EST_BAR)[0]['items'][0]['detalle']);
    $db->exec('UPDATE categorias SET estacion_id = 1 WHERE id = 4');
});
prueba('el producto puede ir a otra estación que su categoría', function () use ($db, $cuentas, $comandas, $T, $ANA, $mesa, $helado, $EST_BAR) {
    $db->exec('UPDATE productos SET estacion_id = 2 WHERE id = 12');
    $c = $cuentas->abrir($T, $mesa('4'), $ANA, 1);
    $ids = $cuentas->enviar($T, $c, $ANA, [$helado])['comandas'];
    igual($EST_BAR, $comandas->obtener($T, $ids[0])['estacion_id']);
    $db->exec('UPDATE productos SET estacion_id = NULL WHERE id = 12');
});
prueba('si la estación está desactivada va a la primera activa', function () use ($db, $salon, $cuentas, $comandas, $T, $ANA, $mesa, $EST_BAR, $EST_COCINA) {
    $salon->alternarEstacion($EST_BAR);
    $c = $cuentas->activaDeMesa($T, $mesa('4'));
    $ids = $cuentas->enviar($T, $c, $ANA, [['tipo' => 'PRODUCTO', 'producto_id' => 9, 'cantidad' => 1]])['comandas'];
    igual($EST_COCINA, $comandas->obtener($T, $ids[0])['estacion_id']);
    $salon->alternarEstacion($EST_BAR);
});
prueba('sin estaciones activas no se generan comandas', function () use ($salon, $cuentas, $T, $ANA, $mesa, $EST_BAR, $EST_COCINA, $item) {
    $salon->alternarEstacion($EST_BAR);
    $salon->alternarEstacion($EST_COCINA);
    $c = $cuentas->activaDeMesa($T, $mesa('4'));
    igual([], $cuentas->enviar($T, $c, $ANA, [['tipo' => 'PRODUCTO', 'producto_id' => 13, 'cantidad' => 1]])['comandas']);
    igual('SIN_COMANDA', $item($c, 'Pie de Manzana')['cocina']);
    $salon->alternarEstacion($EST_BAR);
    $salon->alternarEstacion($EST_COCINA);
});

echo "Configuración del salón\n";
prueba('crear varias mesas de una vez salta las que ya existen', function () use ($salon, $T) {
    igual(2, $salon->crearVarias($T, 2, 'T', 3, 6, 4), 'T5 y T6 nuevas');
});
prueba('no se desactiva una mesa ocupada', fn () => rechaza('ocupada', fn () => $salon->alternarMesa($T, $mesa('4'))));
prueba('nombre de mesa repetido se rechaza', fn () => rechaza('Ya existe la mesa', fn () => $salon->guardarMesa($T, ['zona_id' => 1, 'nombre' => '5', 'capacidad' => 4])));
prueba('una zona de otra tienda no se usa', fn () => rechaza('zona de esta tienda', fn () => $salon->guardarMesa($T2, ['zona_id' => 1, 'nombre' => '1'])));

echo "Personal\n";
$us = new UsuariosService($db);
$admin = ['id' => $ADMIN, 'rol' => 'ADMIN', 'tienda_id' => null];
$gerente = ['id' => 99, 'rol' => 'GERENTE', 'tienda_id' => $T];
prueba('el mesero necesita tienda', fn () => rechaza('Asigne la tienda', fn () => $us->guardar($admin, ['nombre' => 'Pedro', 'email' => 'pedro@power.hn', 'rol' => 'MESERO', 'clave' => 'Clave1234'])));
prueba('contraseña corta se rechaza', fn () => rechaza('8 caracteres', fn () => $us->guardar($admin, ['nombre' => 'Pedro', 'email' => 'pedro@power.hn', 'rol' => 'MESERO', 'tienda_id' => 1, 'clave' => '123'])));
prueba('correo repetido se rechaza', fn () => rechaza('ya lo usa', fn () => $us->guardar($admin, ['nombre' => 'Otra', 'email' => 'MESERO1@power.hn', 'rol' => 'MESERO', 'tienda_id' => 1, 'clave' => 'Clave1234'])));
prueba('el gerente no crea administradores', fn () => rechaza('Rol no permitido', fn () => $us->guardar($gerente, ['nombre' => 'Xavier', 'email' => 'x@power.hn', 'rol' => 'ADMIN', 'clave' => 'Clave1234'])));
prueba('el gerente da de alta meseros solo en su tienda', function () use ($us, $gerente, $db) {
    $id = $us->guardar($gerente, ['nombre' => 'Pedro', 'email' => 'pedro@power.hn', 'rol' => 'MESERO', 'tienda_id' => 3, 'clave' => 'Clave1234']);
    igual(1, (int) $db->query("SELECT tienda_id FROM usuarios WHERE id = $id")->fetchColumn());
});
prueba('el nuevo mesero puede ingresar', fn () => igual(true, password_verify('Clave1234', (string) $db->query("SELECT password_hash FROM usuarios WHERE email = 'pedro@power.hn'")->fetchColumn())));
prueba('editar sin clave conserva la anterior', function () use ($us, $admin, $db) {
    $id = (int) $db->query("SELECT id FROM usuarios WHERE email = 'pedro@power.hn'")->fetchColumn();
    $us->guardar($admin, ['id' => $id, 'nombre' => 'Pedro Gómez', 'email' => 'pedro@power.hn', 'rol' => 'MESERO', 'tienda_id' => 1, 'clave' => '']);
    igual(true, password_verify('Clave1234', (string) $db->query("SELECT password_hash FROM usuarios WHERE id = $id")->fetchColumn()));
});
prueba('nadie se desactiva a sí mismo', fn () => rechaza('propio usuario', fn () => $us->alternar($admin, $ADMIN)));
prueba('nadie se cambia su propio rol', function () use ($us, $admin, $db, $ADMIN) {
    $us->guardar($admin, ['id' => $ADMIN, 'nombre' => 'Administrador', 'email' => 'admin@power.hn', 'rol' => 'CAJERO', 'tienda_id' => 1]);
    igual('ADMIN', $db->query("SELECT rol FROM usuarios WHERE id = $ADMIN")->fetchColumn());
});
prueba('la estación de un producto se guarda desde Productos', function () use ($db, $EST_BAR) {
    $prod = new ProductosService($db, new InventarioService($db));
    $prod->guardar(['id' => 12, 'sku' => 'HEL-VAI', 'nombre' => 'Helado de Vainilla', 'categoria_id' => 6, 'precio' => '30.00', 'impuesto' => 'G15',
        'vendible_solo' => 1, 'controla_stock' => 1, 'estacion_id' => (string) $EST_BAR]);
    igual($EST_BAR, (int) $db->query('SELECT estacion_id FROM productos WHERE id = 12')->fetchColumn());
    rechaza('no válida', fn () => $prod->guardar(['id' => 12, 'sku' => 'HEL-VAI', 'nombre' => 'Helado', 'categoria_id' => 6, 'precio' => '30', 'estacion_id' => '999']));
});

echo "\n$ok/" . ($ok + $fallos) . " pruebas del restaurante OK\n";
exit($fallos ? 1 : 0);
