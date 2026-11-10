<?php
// Resumen de Configuración: una tarjeta por sección con su estado actual.
use App\Support\Menu;
use App\Support\Parametros;

$em = $db->query('SELECT * FROM empresa WHERE id = 1')->fetch() ?: [];
$n = fn (string $sql) => (int) $db->query($sql)->fetchColumn();
$dias = Parametros::entero('cai_alerta_dias');
$nums = Parametros::entero('cai_alerta_facturas');

$alertas = (new App\Configuracion\CaiService($db))->alertas(0, $dias, $nums);
$sinCai = count(array_unique(array_column(array_filter($alertas, fn ($a) => $a['nivel'] === 'error'), 'tienda_id')));
$caiAlerta = count(array_filter($alertas, fn ($a) => $a['nivel'] === 'alerta'));
$sinUsuarios = $n('SELECT COUNT(*) FROM tiendas t WHERE t.activa = 1 AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.tienda_id = t.id AND u.activo = 1)');

$pill = fn (string $texto, string $clase = '') => '<span class="pill ' . $clase . '">' . e($texto) . '</span>';
$estado = [
    'empresa' => $pill(($em['nombre_comercial'] ?? 'Sin configurar')) . $pill('RTN ' . ($em['rtn'] ?? '—')),
    'sucursales' => $pill($n('SELECT COUNT(*) FROM tiendas WHERE activa = 1') . ' activas', 'ok')
        . ($n('SELECT COUNT(*) FROM tiendas WHERE activa = 0') ? $pill($n('SELECT COUNT(*) FROM tiendas WHERE activa = 0') . ' inactivas') : ''),
    'cai' => ($sinCai ? $pill("$sinCai sucursal(es) con una caja sin CAI vigente", 'mal') : $pill('Todas las sucursales con CAI', 'ok'))
        . ($caiAlerta ? $pill("$caiAlerta por renovar", 'alerta') : ''),
    'usuarios' => $pill($n('SELECT COUNT(*) FROM usuarios WHERE activo = 1') . ' activos', 'ok')
        . ($sinUsuarios ? $pill("$sinUsuarios sucursal(es) sin usuarios", 'alerta') : ''),
    'descuentos' => $pill($n('SELECT COUNT(*) FROM descuentos_legales WHERE activo = 1') . ' beneficios activos', 'ok'),
    'categorias' => $pill($n('SELECT COUNT(*) FROM categorias') . ' categorías'),
    'proveedores' => $pill($n('SELECT COUNT(*) FROM proveedores WHERE activo = 1') . ' activos'),
    'parametros' => $pill("CAI: $dias días / $nums números") . $pill('Fondo L ' . Parametros::texto('fondo_caja_sugerido')),
];

layout_inicio('Configuración', 'configuracion', $db);
?>
<h1>Configuración</h1>
<p class="tenue">Todo lo que se define una vez y aplica a la cadena completa. Solo los administradores entran aquí.</p>

<?php if ($sinCai || $sinUsuarios): ?>
  <div class="aviso" role="status">
    <?php if ($sinCai): ?><b><?= $sinCai ?> sucursal(es) tienen una caja que no puede facturar</b> por falta de CAI vigente. <a href="index.php?p=configuracion&s=cai">Registrar CAI</a>. <?php endif; ?>
    <?php if ($sinUsuarios): ?><b><?= $sinUsuarios ?> sucursal(es)</b> no tienen usuarios asignados. <a href="index.php?p=configuracion&s=usuarios">Crear usuarios</a>.<?php endif; ?>
  </div>
<?php endif; ?>

<div class="centro-config">
  <?php foreach (Menu::CONFIGURACION as $clave => [$titulo, $nota]): ?>
    <a href="index.php?p=configuracion&s=<?= $clave ?>" id="cfg-<?= $clave ?>">
      <h3><?= e($titulo) ?></h3>
      <p><?= e($nota) ?></p>
      <div class="estado"><?= $estado[$clave] ?></div>
    </a>
  <?php endforeach; ?>
</div>

<section class="tarjeta" style="margin-top:1rem">
  <h2>Para abrir una sucursal nueva</h2>
  <ol class="lista-pasos">
    <li><div><b><a href="index.php?p=configuracion&s=sucursales">Sucursales</a></b>: cree la tienda con su código de establecimiento SAR.</div></li>
    <li><div><b><a href="index.php?p=configuracion&s=cai">CAI</a></b>: registre el rango autorizado para esa sucursal y su punto de emisión.</div></li>
    <li><div><b><a href="index.php?p=configuracion&s=usuarios">Usuarios</a></b>: cree al gerente y a los cajeros de la tienda.</div></li>
    <li><div><b>Abastecimiento</b>: cargue su inventario inicial con una compra o un traslado desde otra sucursal.</div></li>
    <li><div>El cajero entra, <b>abre su turno</b> y ya puede facturar.</div></li>
  </ol>
</section>
<?php layout_fin(); ?>
