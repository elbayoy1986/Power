<?php
use App\Caja\CajaService;
use App\Catalogo\CatalogoRepository;
use App\Mesas\CuentasService;
use App\Support\Auth;
use App\Usuarios\UsuariosService;
use App\Ventas\CarritoService;

$tiendaId = Auth::tiendaId();
$cuentas = new CuentasService($db, new CarritoService(new CatalogoRepository($db)));
$st = $db->prepare('SELECT m.*, z.nombre AS zona FROM mesas m JOIN zonas z ON z.id = m.zona_id WHERE m.id = ? AND m.tienda_id = ?');
$st->execute([(int) ($_GET['id'] ?? 0), $tiendaId]);
$mesa = $st->fetch() ?: redirigir('salon', 'Esa mesa no existe en esta tienda.');
$esSupervisor = in_array($usuario['rol'], ['ADMIN', 'GERENTE'], true);
$volver = 'mesa&id=' . (int) $mesa['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cuentaId = (int) ($_POST['cuenta_id'] ?? 0);
        switch ($_POST['accion'] ?? '') {
            case 'abrir':
                // El mesero abre a su nombre; caja y gerencia pueden asignar a otro
                $mesero = $usuario['rol'] === 'MESERO' ? $usuario['id'] : (int) ($_POST['mesero_id'] ?? $usuario['id']);
                $cuentas->abrir($tiendaId, (int) $mesa['id'], $mesero, (int) ($_POST['comensales'] ?? 0));
                redirigir($volver);
            case 'transferir':
                $destino = (int) ($_POST['mesa_id'] ?? 0);
                $cuentas->transferir($tiendaId, $cuentaId, $destino);
                redirigir('mesa&id=' . $destino, 'Cuenta pasada a esta mesa. Lo pendiente en cocina ya muestra la mesa nueva.');
            case 'comensales':
                $cuentas->cambiarComensales($tiendaId, $cuentaId, (int) ($_POST['comensales'] ?? 0));
                redirigir($volver, 'Personas en la mesa actualizadas.');
            case 'mesero':
                $esSupervisor || throw new DomainException('Solo gerencia puede cambiar el mesero de una mesa.');
                $cuentas->cambiarMesero($tiendaId, $cuentaId, (int) ($_POST['mesero_id'] ?? 0));
                redirigir($volver, 'Mesero actualizado.');
            case 'liberar':
                $esSupervisor || throw new DomainException('Solo gerencia puede liberar una mesa.');
                $cuentas->anular($tiendaId, $cuentaId, $usuario['id'], (string) ($_POST['motivo'] ?? ''));
                redirigir('salon', "Mesa {$mesa['nombre']} liberada.");
        }
    } catch (DomainException $e) {
        redirigir($volver, $e->getMessage());
    }
}

$cuentaId = $cuentas->activaDeMesa($tiendaId, (int) $mesa['id']);
$meseros = (new UsuariosService($db))->meseros($tiendaId);

require __DIR__ . '/_layout.php';
require __DIR__ . '/_catalogo.php';
layout_inicio('Mesa ' . $mesa['nombre'], 'salon', $db);

if (!$cuentaId):
    ?>
<p><a href="index.php?p=salon">← Salón</a></p>
<section class="tarjeta apertura">
  <h2>Mesa <?= e($mesa['nombre']) ?> <small class="tenue">· <?= e($mesa['zona']) ?> · <?= (int) $mesa['capacidad'] ?> personas</small></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <input type="hidden" name="accion" value="abrir">
    <div class="fila">
      <label>¿Cuántas personas? <input type="number" name="comensales" min="1" max="99" required inputmode="numeric"
        value="<?= min(2, (int) $mesa['capacidad']) ?>" id="comensales" autofocus></label>
      <?php if ($usuario['rol'] !== 'MESERO'): ?>
        <label>Mesero
          <select name="mesero_id" id="mesero">
            <?php foreach ($meseros as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === $usuario['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    </div>
    <button class="btn primario grande">Abrir mesa</button>
  </form>
</section>
<?php layout_fin(); return; endif;

$c = $cuentas->obtener($tiendaId, $cuentaId);
$turno = (new CajaService($db))->turnoAbierto($usuario['id']);
$puedeCobrar = in_array($usuario['rol'], ['ADMIN', 'GERENTE', 'CAJERO'], true);
$turnoAqui = $turno && (int) $turno['tienda_id'] === $tiendaId;
$st = $db->prepare(
    'SELECT m.id, m.nombre, z.nombre AS zona FROM mesas m JOIN zonas z ON z.id = m.zona_id LEFT JOIN cuentas c ON c.mesa_activa = m.id
     WHERE m.tienda_id = ? AND m.activa = 1 AND z.activa = 1 AND c.id IS NULL ORDER BY z.orden, m.orden, m.id'
);
$st->execute([$tiendaId]);
$libres = $st->fetchAll();
$hidden = fn (string $accion) => '<input type="hidden" name="csrf" value="' . e(Auth::csrf()) . '"><input type="hidden" name="accion" value="' . $accion . '">'
    . '<input type="hidden" name="cuenta_id" value="' . (int) $c['id'] . '">';
?>
<div class="pos mesa" id="mesa" data-csrf="<?= e(Auth::csrf()) ?>" data-cuenta="<?= (int) $c['id'] ?>"
     data-supervisor="<?= $esSupervisor ? 1 : 0 ?>" data-cobrar="<?= $puedeCobrar ? 1 : 0 ?>" data-turno="<?= $turnoAqui ? 1 : 0 ?>"
     data-propina="<?= (float) ($GLOBALS['config']['propina_sugerida'] ?? 10) ?>">
  <?php catalogo_seccion(); ?>

  <aside class="orden" id="orden">
    <header class="orden-cab">
      <div>
        <h2><a href="index.php?p=salon" class="volver" title="Volver al salón">←</a> Mesa <span id="mesa-nombre"><?= e($c['mesa']) ?></span></h2>
        <p class="tenue chico" id="mesa-info"><?= e($c['mesero']) ?> · <?= (int) $c['comensales'] ?> pers.</p>
      </div>
      <button class="btn texto solo-movil" onclick="Mesa.cerrarPanel()">Cerrar ✕</button>
    </header>

    <div id="por-entregar"></div>

    <section id="bloque-nuevos" hidden>
      <h3 class="subtitulo">Por enviar</h3>
      <ul class="lineas" id="nuevos"></ul>
      <button class="btn primario grande" id="btn-enviar">Enviar a cocina</button>
    </section>

    <h3 class="subtitulo">En la mesa</h3>
    <ul class="lineas" id="enviados"><li class="vacio tenue">Cargando…</li></ul>

    <dl class="totales" id="totales"></dl>
    <p class="tenue chico" id="ya-cobrado"></p>

    <div class="acciones-mesa">
      <a class="btn" href="index.php?p=precuenta&cuenta=<?= (int) $c['id'] ?>" target="_blank" id="btn-precuenta">Pre-cuenta</a>
      <button class="btn" id="btn-pedir">Pidió la cuenta</button>
      <?php if ($puedeCobrar): ?><button class="btn primario" id="btn-cobrar">Cobrar</button><?php endif; ?>
    </div>
    <p class="aviso error" id="error" hidden></p>

    <details class="bloque">
      <summary>Más opciones de la mesa</summary>
      <form method="post" class="fila">
        <?= $hidden('transferir') ?>
        <label>Pasar a la mesa
          <select name="mesa_id" required id="destino"><option value="">— Elija una mesa libre —</option>
            <?php foreach ($libres as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['nombre']) ?> · <?= e($l['zona']) ?></option><?php endforeach; ?>
          </select></label>
        <button class="btn">Transferir</button>
      </form>
      <form method="post" class="fila">
        <?= $hidden('comensales') ?>
        <label>Personas <input type="number" name="comensales" min="1" max="99" value="<?= (int) $c['comensales'] ?>" class="corto"></label>
        <button class="btn">Guardar</button>
      </form>
      <?php if ($esSupervisor): ?>
        <form method="post" class="fila">
          <?= $hidden('mesero') ?>
          <label>Mesero <select name="mesero_id">
            <?php foreach ($meseros as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === $c['mesero_id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
          </select></label>
          <button class="btn">Cambiar</button>
        </form>
        <form method="post" class="fila" onsubmit="return confirm('¿Liberar la mesa sin cobrar?')">
          <?= $hidden('liberar') ?>
          <label>Liberar mesa (abierta por error, se fueron sin consumir) <input name="motivo" required maxlength="150" placeholder="Motivo"></label>
          <button class="btn">Liberar</button>
        </form>
      <?php endif; ?>
    </details>
  </aside>

  <button class="barra-orden solo-movil" id="barra-orden" onclick="Mesa.abrirPanel()">
    <span id="barra-cant">Ver cuenta</span><b id="barra-total">L 0.00</b>
  </button>
</div>

<!-- Nota para cocina -->
<dialog id="dlg-nota" class="dialogo">
  <form method="dialog">
    <header><h2>Nota para cocina</h2><p class="tenue" id="nota-producto"></p></header>
    <div class="cuerpo-dialogo">
      <input id="nota-texto" maxlength="150" list="notas-frecuentes" placeholder="Ej.: sin cebolla, término medio" autocomplete="off">
      <datalist id="notas-frecuentes">
        <option>Sin cebolla</option><option>Sin hielo</option><option>Sin salsa</option><option>Término medio</option>
        <option>Bien cocido</option><option>Salsa aparte</option><option>Para llevar</option><option>Alergia: maní</option>
      </datalist>
    </div>
    <footer><button value="cancelar" class="btn texto" formnovalidate>Cancelar</button><button value="ok" class="btn primario">Guardar nota</button></footer>
  </form>
</dialog>

<!-- Anular lo enviado -->
<dialog id="dlg-anular" class="dialogo">
  <form method="dialog">
    <header><h2>Anular artículo enviado</h2><p class="tenue" id="anular-producto"></p></header>
    <div class="cuerpo-dialogo">
      <p class="chico">La estación recibe un aviso de anulación. Queda registrado quién lo anuló y por qué.</p>
      <input id="anular-motivo" maxlength="150" required placeholder="Motivo: el cliente cambió el pedido…" autocomplete="off">
    </div>
    <footer><button value="cancelar" class="btn texto" formnovalidate>Cancelar</button><button value="ok" class="btn primario">Anular</button></footer>
  </form>
</dialog>

<?php if ($puedeCobrar): ?>
<!-- Cobro: una factura por todo o por parte de la cuenta -->
<dialog id="dlg-cobro" class="dialogo ancho">
  <form method="dialog" id="form-cobro">
    <header><h2>Cobrar mesa <?= e($c['mesa']) ?></h2>
      <p class="tenue chico">Marque lo que paga esta persona. Lo que no marque queda en la cuenta para otra factura.</p></header>
    <div class="cuerpo-dialogo">
      <?php if (!$turnoAqui): ?>
        <p class="aviso">Para cobrar abra su turno de caja en <a href="index.php?p=caja">Turno</a>.</p>
      <?php endif; ?>
      <ul class="lineas" id="cobro-items"></ul>
      <details class="bloque">
        <summary>Descuento de ley (tercera / cuarta edad, discapacidad)</summary>
        <label>Tipo <select id="legal"><option value="">— Ninguno —</option></select></label>
        <div id="legal-datos" hidden>
          <label>Nombre del beneficiario <input id="ben-nombre" autocomplete="off"></label>
          <label>DNI / Carné <input id="ben-doc" inputmode="numeric" autocomplete="off"></label>
          <p class="tenue chico">Desmarque <b>“Beneficiario”</b> en lo que no consumió la persona beneficiada.</p>
        </div>
      </details>
      <details class="bloque">
        <summary>Datos de factura (RTN)</summary>
        <label>RTN <input id="cli-rtn" inputmode="numeric" maxlength="14" placeholder="14 dígitos"></label>
        <label>Nombre / Razón social <input id="cli-nombre" placeholder="CONSUMIDOR FINAL"></label>
      </details>
      <dl class="totales" id="cobro-totales"></dl>
      <div class="pago">
        <select id="forma-pago" aria-label="Forma de pago">
          <option>EFECTIVO</option><option>TARJETA</option><option>TRANSFERENCIA</option><option>MIXTO</option>
        </select>
        <label id="bloque-mixto" hidden>Parte en efectivo (L) <input id="monto-efectivo" inputmode="decimal" autocomplete="off"></label>
        <fieldset class="propina">
          <legend>Propina voluntaria <small class="tenue">(no forma parte de la factura)</small></legend>
          <div class="fila">
            <span class="chips-propina" id="chips-propina"></span>
            <label>Monto (L) <input id="propina" inputmode="decimal" autocomplete="off" placeholder="0.00"></label>
            <label>Se paga con <select id="propina-forma"><option>EFECTIVO</option><option>TARJETA</option></select></label>
          </div>
        </fieldset>
        <div class="fila" id="bloque-efectivo">
          <label>Efectivo recibido (L) <input id="recibido" inputmode="decimal" placeholder="Opcional" autocomplete="off"></label>
          <p class="cambio" id="cambio" aria-live="polite"></p>
        </div>
      </div>
      <p class="aviso error" id="cobro-error" hidden></p>
    </div>
    <footer>
      <button value="cancelar" class="btn texto" formnovalidate>Cancelar</button>
      <button type="button" class="btn primario" id="btn-facturar" <?= $turnoAqui ? '' : 'disabled' ?>>Facturar</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>
<?php combo_dialogo(); ?>
<?php layout_fin(['assets/comun.js', 'assets/catalogo.js', 'assets/mesa.js']); ?>
