<?php
use App\Caja\CajaService;
use App\Support\Auth;

$turno = (new CajaService($db))->turnoAbierto($usuario['id']);

require __DIR__ . '/_layout.php';
require __DIR__ . '/_catalogo.php';
layout_inicio('Vender', 'pos', $db);

if (!$turno || (int) $turno['tienda_id'] !== Auth::tiendaId()):
?>
<section class="tarjeta apertura">
  <?php if ($turno): ?>
    <h2>Su turno está abierto en <?= e($turno['tienda']) ?></h2>
    <p>Cambie a esa tienda en la barra superior, o cierre ese turno en <a href="index.php?p=caja">Turno de caja</a> antes de vender aquí.</p>
  <?php else: ?>
    <h2>Abra su turno para empezar a vender</h2>
    <p class="tenue">Cuente el efectivo con que inicia la caja (fondo de cambio).</p>
    <form method="post" action="index.php?p=caja" class="fila">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <input type="hidden" name="accion" value="abrir">
      <input type="hidden" name="volver" value="pos">
      <label>Fondo inicial (L) <input name="fondo" inputmode="decimal" required placeholder="1,000.00" id="fondo-pos" autofocus></label>
      <button class="btn primario">Abrir turno</button>
    </form>
  <?php endif; ?>
</section>
<?php layout_fin(); return; endif; ?>
<div class="pos" id="pos" data-csrf="<?= e(Auth::csrf()) ?>">
  <?php catalogo_seccion(); ?>

  <aside class="orden" id="orden">
    <header class="orden-cab">
      <h2>Orden</h2>
      <button class="btn texto solo-movil" onclick="POS.cerrarOrden()">Cerrar ✕</button>
    </header>
    <ul class="lineas" id="lineas"></ul>

    <details class="bloque" id="bloque-legal">
      <summary>Descuento de ley (tercera / cuarta edad, discapacidad)</summary>
      <label>Tipo
        <select id="legal"><option value="">— Ninguno —</option></select>
      </label>
      <div id="legal-datos" hidden>
        <label>Nombre del beneficiario <input id="ben-nombre" autocomplete="off"></label>
        <label>DNI / Carné <input id="ben-doc" inputmode="numeric" autocomplete="off"></label>
        <p class="tenue chico">Desmarque <b>“Beneficiario”</b> en las líneas que no son consumo de la persona beneficiada.</p>
      </div>
    </details>

    <details class="bloque">
      <summary>Datos de factura (RTN)</summary>
      <label>RTN <input id="cli-rtn" inputmode="numeric" maxlength="14" placeholder="14 dígitos"></label>
      <label>Nombre / Razón social <input id="cli-nombre" placeholder="CONSUMIDOR FINAL"></label>
    </details>

    <dl class="totales" id="totales"></dl>

    <div class="pago">
      <select id="forma-pago" aria-label="Forma de pago">
        <option>EFECTIVO</option><option>TARJETA</option><option>TRANSFERENCIA</option><option>MIXTO</option>
      </select>
      <div class="fila" id="bloque-efectivo">
        <label>Efectivo recibido (L) <input id="recibido" inputmode="decimal" placeholder="Opcional" autocomplete="off"></label>
        <p class="cambio" id="cambio" aria-live="polite"></p>
      </div>
      <label id="bloque-mixto" hidden>Parte en efectivo (L) <input id="monto-efectivo" inputmode="decimal" autocomplete="off"></label>
      <button class="btn primario grande" id="btn-facturar" disabled>Facturar</button>
    </div>
    <p class="aviso error" id="error" hidden></p>
  </aside>

  <button class="barra-orden solo-movil" id="barra-orden" onclick="POS.abrirOrden()">
    <span id="barra-cant">0 artículos</span><b id="barra-total">L 0.00</b>
  </button>
</div>

<?php combo_dialogo(); ?>
<?php layout_fin(['assets/comun.js', 'assets/catalogo.js', 'assets/pos.js']); ?>
