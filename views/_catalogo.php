<?php
// Catálogo con filtros y armador de combos: los usan la caja (pos.php) y la cuenta de mesa (mesa.php) con assets/catalogo.js.

function catalogo_seccion(): void
{
    ?>
  <section class="catalogo">
    <div class="filtros" id="categorias" role="tablist"></div>
    <div class="promos-hoy" id="promos-hoy"></div>
    <div class="grilla" id="grilla"><p class="tenue">Cargando catálogo…</p></div>
  </section>
<?php
}

function combo_dialogo(): void
{
    ?>
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
<?php
}
