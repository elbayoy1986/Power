/* Power POS · Caja. El navegador solo arma la orden; los montos SIEMPRE los calcula el servidor. */
const POS = (() => {
  const $ = (s) => document.querySelector(s);
  const csrf = $('#pos').dataset.csrf;
  const lps = (c) => 'L ' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let cat = { productos: [], combos: [], descuentos_legales: [], promociones: [] };
  let orden = [];          // [{uid, tipo, producto_id|combo_id, nombre, cantidad, opciones, detalle, aplica_legal}]
  let cotizacion = null;
  let filtro = 'Todo';
  let uid = 0;

  async function api(accion, cuerpo) {
    const r = await fetch('api.php?a=' + accion, cuerpo === undefined ? {} : {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(cuerpo),
    });
    const data = await r.json();
    if (r.status === 401) location.href = 'index.php?p=login';
    if (!r.ok) throw new Error(data.error || 'Error');
    return data;
  }

  // ---------- Catálogo ----------
  function pintarCatalogo() {
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
    if (b) { filtro = b.dataset.cat; pintarCatalogo(); }
  });

  $('#grilla').addEventListener('click', (e) => {
    const t = e.target.closest('.tile');
    if (!t) return;
    const id = +t.dataset.id;
    if (t.dataset.tipo === 'COMBO') return abrirCombo(cat.combos.find((c) => c.id === id));
    const p = cat.productos.find((x) => x.id === id);
    const existente = orden.find((l) => l.tipo === 'PRODUCTO' && l.producto_id === id);
    if (existente) existente.cantidad++;
    else orden.push({ uid: ++uid, tipo: 'PRODUCTO', producto_id: id, nombre: p.nombre, cantidad: 1, opciones: [], detalle: '', aplica_legal: true });
    cambio();
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
    orden.push({ uid: ++uid, tipo: 'COMBO', combo_id: comboActual.id, nombre: comboActual.nombre,
      cantidad: +$('#combo-cant').value, opciones, detalle: detalle.join(', '), aplica_legal: true });
    cambio();
  });

  // ---------- Orden y cotización ----------
  $('#lineas').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-uid]');
    if (!b) return;
    const l = orden.find((x) => x.uid === +b.dataset.uid);
    l.cantidad += +b.dataset.d;
    if (l.cantidad < 1) orden = orden.filter((x) => x !== l);
    cambio();
  });
  $('#lineas').addEventListener('change', (e) => {
    if (!e.target.dataset.legal) return;
    orden.find((x) => x.uid === +e.target.dataset.legal).aplica_legal = e.target.checked;
    cambio();
  });
  $('#legal').addEventListener('change', () => { $('#legal-datos').hidden = !$('#legal').value; cambio(); });

  function cuerpo() {
    return {
      items: orden.map(({ tipo, producto_id, combo_id, cantidad, opciones, aplica_legal }) =>
        ({ tipo, producto_id, combo_id, cantidad, opciones, aplica_legal })),
      descuento_legal_id: $('#legal').value || null,
    };
  }

  let timer;
  function cambio() {
    pintarOrden();
    clearTimeout(timer);
    timer = setTimeout(cotizar, 150);
  }

  async function cotizar() {
    $('#error').hidden = true;
    if (!orden.length) { cotizacion = null; return pintarOrden(); }
    try {
      cotizacion = await api('cotizar', cuerpo());
    } catch (err) {
      cotizacion = null;
      mostrarError(err.message);
    }
    pintarOrden();
  }

  function pintarOrden() {
    const conLegal = !!$('#legal').value;
    $('#lineas').innerHTML = orden.length ? orden.map((l, i) => {
      const c = cotizacion?.lineas?.[i];
      const desc = c ? c.descuento_promo + c.descuento_legal : 0;
      return `<li class="linea">
        <div class="linea-info"><b>${esc(l.nombre)}</b>${l.detalle ? `<small>${esc(l.detalle)}</small>` : ''}
          ${desc ? `<small class="ahorro">Ahorro ${lps(desc)}</small>` : ''}
          ${conLegal ? `<label class="chico"><input type="checkbox" data-legal="${l.uid}" ${l.aplica_legal ? 'checked' : ''}> Beneficiario</label>` : ''}
        </div>
        <div class="cantidad">
          <button class="btn" data-uid="${l.uid}" data-d="-1" aria-label="Quitar">−</button><output>${l.cantidad}</output>
          <button class="btn" data-uid="${l.uid}" data-d="1" aria-label="Agregar">+</button>
        </div>
        <span class="linea-total">${c ? lps(c.neto) : '…'}</span>
      </li>`;
    }).join('') : '<li class="vacio tenue">Toque un producto o combo para empezar.</li>';

    const r = cotizacion?.resumen;
    const fila = (t, v, cls = '') => v ? `<dt class="${cls}">${t}</dt><dd class="${cls}">${lps(v)}</dd>` : '';
    $('#totales').innerHTML = r ? [
      fila('Subtotal', r.subtotal),
      ...cotizacion.promociones_aplicadas.map((p) => fila('Promo: ' + esc(p.nombre), -p.descuento, 'ahorro')),
      fila(esc(cotizacion.regla_legal?.nombre || 'Descuento de ley'), -r.descuento_legal, 'ahorro'),
      fila('Importe exento', r.importe_exento),
      fila('Importe gravado 15%', r.importe_gravado_15),
      fila('Importe gravado 18%', r.importe_gravado_18),
      fila('ISV 15%', r.isv_15),
      fila('ISV 18%', r.isv_18),
      `<dt class="total">Total</dt><dd class="total">${lps(r.total)}</dd>`,
    ].join('') : '';

    const cant = orden.reduce((a, l) => a + l.cantidad, 0);
    $('#barra-cant').textContent = `${cant} artículo${cant === 1 ? '' : 's'} · Ver orden`;
    $('#barra-total').textContent = lps(r?.total || 0);
    $('#btn-facturar').disabled = !r;
  }

  function mostrarError(msg) { $('#error').textContent = msg; $('#error').hidden = false; }

  $('#btn-facturar').addEventListener('click', async () => {
    const b = $('#btn-facturar');
    b.disabled = true;
    try {
      const { venta_id } = await api('facturar', {
        ...cuerpo(),
        beneficiario_nombre: $('#ben-nombre').value,
        beneficiario_documento: $('#ben-doc').value,
        cliente_rtn: $('#cli-rtn').value,
        cliente_nombre: $('#cli-nombre').value,
        forma_pago: $('#forma-pago').value,
      });
      window.open('index.php?p=factura&id=' + venta_id, '_blank');
      orden = []; cotizacion = null;
      ['#ben-nombre', '#ben-doc', '#cli-rtn', '#cli-nombre', '#legal'].forEach((s) => { $(s).value = ''; });
      $('#legal-datos').hidden = true;
      pintarOrden();
      cerrarOrden();
    } catch (err) {
      mostrarError(err.message);
      b.disabled = false;
    }
  });

  function abrirOrden() { document.body.classList.add('orden-abierta'); }
  function cerrarOrden() { document.body.classList.remove('orden-abierta'); }

  api('catalogo').then((d) => {
    cat = d;
    $('#legal').insertAdjacentHTML('beforeend', d.descuentos_legales.map((x) =>
      `<option value="${x.id}">${esc(x.nombre)} — ${(+x.porcentaje)}%</option>`).join(''));
    pintarCatalogo();
    pintarOrden();
  }).catch((e) => mostrarError(e.message));

  return { abrirOrden, cerrarOrden };
})();
