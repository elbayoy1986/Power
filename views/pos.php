<?php
use App\Support\Auth;

require __DIR__ . '/_layout.php';
layout_inicio('Caja', 'pos', $db);
?>
<div class="pos" id="pos" data-csrf="<?= e(Auth::csrf()) ?>">
  <section class="catalogo">
    <div class="filtros" id="categorias" role="tablist"></div>
    <div class="promos-hoy" id="promos-hoy"></div>
    <div class="grilla" id="grilla"><p class="tenue">Cargando catálogo…</p></div>
  </section>

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
      <button class="btn primario grande" id="btn-facturar" disabled>Facturar</button>
    </div>
    <p class="aviso error" id="error" hidden></p>
  </aside>

  <button class="barra-orden solo-movil" id="barra-orden" onclick="POS.abrirOrden()">
    <span id="barra-cant">0 artículos</span><b id="barra-total">L 0.00</b>
  </button>
</div>

<!-- Armador de combos -->
<dialog id="dlg-combo" class="dialogo">
  <form method="dialog" id="form-combo">
    <header><h2 id="combo-titulo"></h2><p class="tenue" id="combo-desc"></p></header>
    <div id="combo-grupos"></div>
    <footer>
      <div class="cantidad">
        <button type="button" class="btn" data-delta="-1">−</button>
        <output id="combo-cant">1</output>
        <button type="button" class="btn" data-delta="1">+</button>
      </div>
      <button value="cancelar" class="btn texto" formnovalidate>Cancelar</button>
      <button value="ok" class="btn primario" id="combo-ok">Agregar</button>
    </footer>
  </form>
</dialog>
<?php layout_fin(['assets/pos.js']); ?>
