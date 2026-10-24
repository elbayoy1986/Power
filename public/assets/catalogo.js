/* Power POS · Catálogo y armador de combos, compartidos por la caja (pos.js) y la cuenta de mesa (mesa.js).
   El navegador solo arma el pedido; los montos SIEMPRE los calcula el servidor. Requiere comun.js. */
const Catalogo = (() => {
  const { $, lps, esc } = Power;

  let cat = { productos: [], combos: [], promociones: [] };
  let filtro = 'Todo';
  let alAgregar = () => {};

  function pintar() {
    const items = [
      ...cat.combos.map((c) => ({ ...c, tipo: 'COMBO', categoria: 'Combos' })),
      ...cat.productos.map((p) => ({ ...p, tipo: 'PRODUCTO' })),
    ];
    const categorias = ['Todo', ...new Set(items.map((i) => i.categoria))];
    $('#categorias').innerHTML = categorias.map((c) =>
      `<button role="tab" class="chip ${c === filtro ? 'activo' : ''}" data-cat="${esc(c)}">${esc(c)}</button>`).join('');
    $('#grilla').innerHTML = items.filter((i) => filtro === 'Todo' || i.categoria === filtro).map((i) => `
      <button class="tile ${i.tipo === 'COMBO' ? 'combo' : ''}" data-tipo="${i.tipo}" data-id="${i.id}">
        <span class="tile-nombre">${esc(i.nombre)}</span>
        ${i.descripcion ? `<span class="tile-desc">${esc(i.descripcion)}</span>` : ''}
        <span class="tile-precio">${lps(i.precio)}</span>
      </button>`).join('');
    $('#promos-hoy').innerHTML = cat.promociones.length
      ? '🔥 Hoy: ' + cat.promociones.map((p) => `<span>${esc(p.nombre)}</span>`).join(' · ') : '';
  }

  $('#categorias').addEventListener('click', (e) => {
    const b = e.target.closest('[data-cat]');
    if (b) { filtro = b.dataset.cat; pintar(); }
  });

  $('#grilla').addEventListener('click', (e) => {
    const t = e.target.closest('.tile');
    if (!t) return;
    const id = +t.dataset.id;
    if (t.dataset.tipo === 'COMBO') return abrirCombo(cat.combos.find((c) => c.id === id));
    const p = cat.productos.find((x) => x.id === id);
    alAgregar({ tipo: 'PRODUCTO', producto_id: id, nombre: p.nombre, cantidad: 1, opciones: [], detalle: '' });
  });

  // ---------- Armador de combos ----------
  const dlg = $('#dlg-combo');
  let comboActual = null;
  let seleccion = {};      // grupoId -> {productoId: cantidad}

  function abrirCombo(c) {
    comboActual = c;
    seleccion = {};
    c.grupos.forEach((g) => {
      seleccion[g.id] = {};
      const def = g.opciones.find((o) => o.por_defecto) || g.opciones[0];
      if (def) seleccion[g.id][def.producto_id] = g.min;
    });
    $('#combo-titulo').textContent = `${c.nombre} · ${lps(c.precio)}`;
    $('#combo-desc').textContent = c.descripcion || '';
    $('#combo-cant').value = 1;
    dlg.returnValue = '';   // Esc no reinicia returnValue
    pintarGrupos();
    dlg.showModal();
  }

  function pintarGrupos() {
    $('#combo-grupos').innerHTML = comboActual.grupos.map((g) => {
      const sel = seleccion[g.id];
      const usadas = Object.values(sel).reduce((a, b) => a + b, 0);
      const regla = g.min === g.max ? `Elige ${g.max}` : `Elige de ${g.min} a ${g.max}`;
      const opciones = g.opciones.map((o) => {
        const extra = o.recargo ? ` <small>+${lps(o.recargo)}</small>` : '';
        if (g.max === 1) {
          return `<label class="opcion"><input type="radio" name="g${g.id}" value="${o.producto_id}" data-g="${g.id}"
            ${sel[o.producto_id] ? 'checked' : ''}> ${esc(o.nombre)}${extra}</label>`;
        }
        const n = sel[o.producto_id] || 0;
        return `<div class="opcion"><span>${esc(o.nombre)}${extra}</span>
          <span class="cantidad"><button type="button" class="btn" data-g="${g.id}" data-p="${o.producto_id}" data-d="-1" ${n ? '' : 'disabled'}>−</button>
          <output>${n}</output>
          <button type="button" class="btn" data-g="${g.id}" data-p="${o.producto_id}" data-d="1" ${usadas >= g.max ? 'disabled' : ''}>+</button></span></div>`;
      }).join('');
      const ok = usadas >= g.min && usadas <= g.max;
      return `<fieldset class="grupo ${ok ? '' : 'pendiente'}"><legend>${esc(g.nombre)} <small>${regla} · ${usadas}/${g.max}</small></legend>${opciones}</fieldset>`;
    }).join('');
    const valido = comboActual.grupos.every((g) => {
      const n = Object.values(seleccion[g.id]).reduce((a, b) => a + b, 0);
      return n >= g.min && n <= g.max;
    });
    $('#combo-ok').disabled = !valido;
  }

  $('#combo-grupos').addEventListener('change', (e) => {
    if (e.target.type === 'radio') { seleccion[e.target.dataset.g] = { [e.target.value]: 1 }; pintarGrupos(); }
  });
  $('#combo-grupos').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-d]');
    if (!b) return;
    const s = seleccion[b.dataset.g];
    s[b.dataset.p] = Math.max(0, (s[b.dataset.p] || 0) + +b.dataset.d);
    pintarGrupos();
  });
  dlg.querySelector('footer .cantidad').addEventListener('click', (e) => {
    const b = e.target.closest('[data-delta]');
    if (b) $('#combo-cant').value = Math.max(1, Math.min(99, +$('#combo-cant').value + +b.dataset.delta));
  });
  dlg.addEventListener('close', () => {
    if (dlg.returnValue !== 'ok') return;
    const opciones = [];
    const detalle = [];
    comboActual.grupos.forEach((g) => g.opciones.forEach((o) => {
      const n = seleccion[g.id][o.producto_id] || 0;
      for (let i = 0; i < n; i++) opciones.push(o.producto_id);
      if (n) detalle.push((n > 1 ? n + '× ' : '') + o.nombre);
    }));
    alAgregar({ tipo: 'COMBO', combo_id: comboActual.id, nombre: comboActual.nombre,
      cantidad: +$('#combo-cant').value, opciones, detalle: detalle.join(', ') });
  });

  /** Pinta el catálogo; alAgregar(item) recibe lo que el usuario toca o arma. */
  function montar(datos, fn) {
    cat = datos;
    alAgregar = fn;
    pintar();
  }

  return { montar };
})();
