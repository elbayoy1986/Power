/* Power POS · Cuenta de mesa: tomar el pedido por rondas, enviarlo a cocina, seguirlo y cobrar (todo o por partes).
   Lo que aún no se envía se guarda en este equipo; los montos SIEMPRE los calcula el servidor. Requiere comun.js y catalogo.js. */
const Mesa = (() => {
  const { $, api, lps, esc, aCent } = Power;
  const raiz = $('#mesa');
  const cuentaId = +raiz.dataset.cuenta;
  const esSupervisor = raiz.dataset.supervisor === '1';
  const propinaSugerida = +raiz.dataset.propina;
  const clave = 'mesa-pendiente-' + cuentaId;

  let cuenta = null;       // estado del servidor
  let pendiente = null;    // totales de lo que falta cobrar
  let nuevos = leer();     // por enviar
  let uid = nuevos.reduce((m, l) => Math.max(m, l.uid), 0);

  function leer() { try { return JSON.parse(localStorage.getItem(clave) || '[]'); } catch { return []; } }
  function guardar() { try { localStorage.setItem(clave, JSON.stringify(nuevos)); } catch { /* sin almacenamiento */ } }
  const tiempo = (min) => min < 60 ? `${min} min` : `${Math.floor(min / 60)} h ${min % 60} min`;
  const hora = (f) => String(f).slice(11, 16);

  function mostrarError(msg, sel = '#error') { const el = $(sel); el.textContent = msg; el.className = 'aviso error'; el.hidden = false; }
  function mostrarAviso(msg, sel = '#error') { const el = $(sel); el.textContent = msg; el.className = 'aviso'; el.hidden = false; }
  function limpiarAviso(sel = '#error') { $(sel).hidden = true; }

  // ---------- Por enviar ----------
  function agregar(item) {
    const igual = item.tipo === 'PRODUCTO' && nuevos.find((l) => l.tipo === 'PRODUCTO' && l.producto_id === item.producto_id && !l.nota);
    if (igual) igual.cantidad++;
    else nuevos.push({ uid: ++uid, ...item, nota: '' });
    guardar();
    pintarNuevos();
  }

  function pintarNuevos() {
    $('#bloque-nuevos').hidden = !nuevos.length;
    $('#nuevos').innerHTML = nuevos.map((l) => `<li class="linea">
      <div class="linea-info"><b>${esc(l.nombre)}</b>${l.detalle ? `<small>${esc(l.detalle)}</small>` : ''}
        ${l.nota ? `<small class="nota">📝 ${esc(l.nota)}</small>` : ''}</div>
      <div class="cantidad">
        <button class="btn" data-uid="${l.uid}" data-d="-1" aria-label="Quitar">−</button><output>${l.cantidad}</output>
        <button class="btn" data-uid="${l.uid}" data-d="1" aria-label="Agregar">+</button>
      </div>
      <button class="btn texto" data-nota="${l.uid}">${l.nota ? 'Editar nota' : '+ Nota'}</button>
    </li>`).join('');
    const n = nuevos.reduce((a, l) => a + l.cantidad, 0);
    $('#btn-enviar').textContent = `Enviar a cocina (${n})`;
    pintarBarra();
  }

  $('#nuevos').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.nota) return abrirNota(nuevos.find((l) => l.uid === +b.dataset.nota));
    const l = nuevos.find((x) => x.uid === +b.dataset.uid);
    l.cantidad += +b.dataset.d;
    if (l.cantidad < 1) nuevos = nuevos.filter((x) => x !== l);
    guardar();
    pintarNuevos();
  });

  const dlgNota = $('#dlg-nota');
  let enNota = null;
  function abrirNota(l) {
    enNota = l;
    $('#nota-producto').textContent = l.nombre;
    $('#nota-texto').value = l.nota;
    dlgNota.returnValue = '';
    dlgNota.showModal();
  }
  dlgNota.addEventListener('close', () => {
    if (dlgNota.returnValue !== 'ok' || !enNota) return;
    enNota.nota = $('#nota-texto').value.trim();
    guardar();
    pintarNuevos();
  });

  $('#btn-enviar').addEventListener('click', async () => {
    const b = $('#btn-enviar');
    b.disabled = true;
    limpiarAviso();
    try {
      const r = await api('enviar', {
        cuenta_id: cuentaId,
        items: nuevos.map(({ tipo, producto_id, combo_id, cantidad, opciones, nota }) => ({ tipo, producto_id, combo_id, cantidad, opciones, nota })),
      });
      nuevos = [];
      guardar();
      pintarNuevos();
      mostrarAviso(r.comandas.length
        ? `Ronda ${r.ronda} enviada a ${r.comandas.length === 1 ? '1 estación' : r.comandas.length + ' estaciones'}.`
        : `Ronda ${r.ronda} anotada en la cuenta (no hay estaciones de cocina activas).`);
      await refrescar();
    } catch (err) {
      mostrarError(err.message);
    }
    b.disabled = false;
  });

  // ---------- En la mesa ----------
  const ESTADOS = {
    EN_COCINA: ['⏳ En preparación', 'alerta'], LISTO: ['🔔 Listo', 'ok'], ENTREGADO: ['✓ Servido', 'neutro'],
    ANULADO: ['Anulado', 'mal'], SIN_COMANDA: ['Anotado', 'neutro'],
  };

  function pintarCuenta() {
    const activa = cuenta.estado === 'ABIERTA' || cuenta.estado === 'POR_COBRAR';
    $('#mesa-nombre').textContent = cuenta.mesa;
    $('#mesa-info').textContent = `${cuenta.mesero} · ${cuenta.comensales} pers. · ${tiempo(cuenta.minutos)}`
      + (cuenta.estado === 'POR_COBRAR' ? ' · pidió la cuenta' : '');
    $('#por-entregar').innerHTML = cuenta.por_entregar.map((k) =>
      `<div class="aviso listo-servir"><span>🔔 Listo en <b>${esc(k.estacion)}</b> para servir</span>
        <button class="btn" data-entregar="${k.id}">Ya lo serví</button></div>`).join('');

    const filas = [];
    let ronda = 0;
    for (const i of cuenta.items) {
      if (i.ronda !== ronda) {
        ronda = i.ronda;
        filas.push(`<li class="ronda">Ronda ${ronda} · ${hora(i.agregado_en)} · ${esc(i.agregado_por)}</li>`);
      }
      const [txt, cls] = ESTADOS[i.cocina];
      filas.push(`<li class="linea ${i.estado === 'ANULADO' ? 'anulada' : ''}">
        <div class="linea-info"><b>${i.cantidad}× ${esc(i.descripcion)}</b>${i.detalle ? `<small>${esc(i.detalle)}</small>` : ''}
          ${i.nota ? `<small class="nota">📝 ${esc(i.nota)}</small>` : ''}
          ${i.motivo_anulacion ? `<small class="mal-txt">Motivo: ${esc(i.motivo_anulacion)}</small>` : ''}</div>
        <span><span class="pill ${cls}">${txt}</span>${i.venta_id ? ' <span class="pill ok">Cobrado</span>' : ''}</span>
        ${activa && esSupervisor && i.estado === 'ACTIVO' && !i.venta_id ? `<button class="btn texto" data-anular="${i.id}">Anular</button>` : '<span></span>'}
      </li>`);
    }
    $('#enviados').innerHTML = filas.join('') || '<li class="vacio tenue">Toque los productos del menú para tomar el pedido.</li>';

    const r = pendiente;
    const fila = (t, v, cls = '') => v ? `<dt class="${cls}">${t}</dt><dd class="${cls}">${lps(v)}</dd>` : '';
    $('#totales').innerHTML = r && r.total ? [
      fila('Subtotal', r.subtotal),
      ...r.promociones.map((p) => fila('Promo: ' + esc(p.nombre), -p.descuento, 'ahorro')),
      fila('ISV', r.isv_15 + r.isv_18),
      `<dt class="total">Por cobrar</dt><dd class="total">${lps(r.total)}</dd>`,
    ].join('') : '';
    const cobrado = cuenta.facturas.reduce((a, f) => a + +f.total, 0);
    $('#ya-cobrado').textContent = cuenta.facturas.length
      ? `Ya cobrado: ${lps(cobrado)} en ${cuenta.facturas.length === 1 ? '1 factura' : cuenta.facturas.length + ' facturas'} (${cuenta.facturas.map((f) => f.numero_factura.slice(-8)).join(', ')}).` : '';

    $('#btn-pedir').hidden = !activa || cuenta.estado === 'POR_COBRAR' || !r?.total;
    if ($('#btn-cobrar')) $('#btn-cobrar').disabled = !activa || !r?.total;
    if (!activa) {
      mostrarAviso(cuenta.estado === 'COBRADA' ? 'La cuenta se cobró completa y la mesa quedó libre.' : 'La mesa se liberó.');
      $('#btn-enviar').disabled = true;
      setTimeout(() => { location.href = 'index.php?p=salon'; }, 2500);
    }
    pintarBarra();
  }

  function pintarBarra() {
    const n = nuevos.reduce((a, l) => a + l.cantidad, 0);
    $('#barra-cant').textContent = n ? `${n} por enviar · Ver cuenta` : 'Ver cuenta';
    $('#barra-total').textContent = lps(pendiente?.total || 0);
  }

  $('#por-entregar').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-entregar]');
    if (!b) return;
    try { await api('entregar', { comanda_id: +b.dataset.entregar }); await refrescar(); } catch (err) { mostrarError(err.message); }
  });

  const dlgAnular = $('#dlg-anular');
  let aAnular = null;
  $('#enviados').addEventListener('click', (e) => {
    const b = e.target.closest('[data-anular]');
    if (!b) return;
    aAnular = cuenta.items.find((i) => i.id === +b.dataset.anular);
    $('#anular-producto').textContent = `${aAnular.cantidad}× ${aAnular.descripcion}`;
    $('#anular-motivo').value = '';
    dlgAnular.returnValue = '';
    dlgAnular.showModal();
  });
  dlgAnular.addEventListener('close', async () => {
    if (dlgAnular.returnValue !== 'ok' || !aAnular) return;
    try {
      await api('anular_item', { item_id: aAnular.id, motivo: $('#anular-motivo').value });
      mostrarAviso('Artículo anulado. La estación recibió el aviso.');
      await refrescar();
    } catch (err) { mostrarError(err.message); }
  });

  $('#btn-pedir').addEventListener('click', async () => {
    try { await api('pedir_cuenta', { cuenta_id: cuentaId }); mostrarAviso('Listo: la caja ve la mesa como “pidió la cuenta”.'); await refrescar(); } catch (err) { mostrarError(err.message); }
  });

  // ---------- Cobro (una factura por todo o por parte de la cuenta) ----------
  const dlgCobro = $('#dlg-cobro');
  const cobro = { sel: new Set(), legal: {}, cot: null };
  const porCobrar = () => cuenta.items.filter((i) => i.estado === 'ACTIVO' && !i.venta_id);
  const propinaEfectivo = () => $('#propina-forma').value === 'EFECTIVO' ? (aCent($('#propina').value) || 0) : 0;

  if (dlgCobro) {
    $('#btn-cobrar').addEventListener('click', () => {
      limpiarAviso();
      if (nuevos.length) return mostrarError('Envíe o quite lo que está “Por enviar” antes de cobrar.');
      cobro.sel = new Set(porCobrar().map((i) => i.id));
      cobro.legal = {};
      cobro.cot = null;
      ['#ben-nombre', '#ben-doc', '#cli-rtn', '#cli-nombre', '#legal', '#recibido', '#monto-efectivo', '#propina'].forEach((s) => { $(s).value = ''; });
      $('#forma-pago').value = 'EFECTIVO';
      $('#propina-forma').value = 'EFECTIVO';
      $('#legal-datos').hidden = true;
      limpiarAviso('#cobro-error');
      pintarCobro();
      cotizarCobro();
      dlgCobro.showModal();
    });

    $('#cobro-items').addEventListener('change', (e) => {
      const t = e.target;
      if (t.id === 'cobro-todos') cobro.sel = new Set(t.checked ? porCobrar().map((i) => i.id) : []);
      else if (t.dataset.sel) t.checked ? cobro.sel.add(+t.dataset.sel) : cobro.sel.delete(+t.dataset.sel);
      else if (t.dataset.legal) cobro.legal[t.dataset.legal] = t.checked;
      cotizarCobro();
    });
    $('#legal').addEventListener('change', () => { $('#legal-datos').hidden = !$('#legal').value; cotizarCobro(); });
    ['#forma-pago', '#recibido', '#propina', '#propina-forma'].forEach((s) => $(s).addEventListener('input', pintarPago));
    $('#chips-propina').addEventListener('click', (e) => {
      const b = e.target.closest('[data-pct]');
      if (!b || !cobro.cot) return;
      $('#propina').value = (Math.round(cobro.cot.resumen.total * +b.dataset.pct / 100) / 100).toFixed(2);
      pintarPago();
    });
    $('#btn-facturar').addEventListener('click', facturar);
  }

  let timerCobro;
  function cotizarCobro() {
    pintarCobro();
    clearTimeout(timerCobro);
    timerCobro = setTimeout(async () => {
      limpiarAviso('#cobro-error');
      if (!cobro.sel.size) { cobro.cot = null; return pintarCobro(); }
      try {
        cobro.cot = await api('cotizar_cuenta', {
          cuenta_id: cuentaId, item_ids: [...cobro.sel], aplica_legal: cobro.legal, descuento_legal_id: $('#legal').value || null,
        });
      } catch (err) { cobro.cot = null; mostrarError(err.message, '#cobro-error'); }
      pintarCobro();
    }, 120);
  }

  function pintarCobro() {
    const pend = porCobrar();
    const conLegal = !!$('#legal').value;
    const elegidos = pend.filter((i) => cobro.sel.has(i.id));
    $('#cobro-items').innerHTML = `<li class="linea-cobro todos"><label><input type="checkbox" id="cobro-todos"
        ${elegidos.length === pend.length ? 'checked' : ''}> <b>Todo lo pendiente</b></label><span></span></li>`
      + pend.map((i) => {
        const pos = elegidos.indexOf(i);
        const c = pos >= 0 ? cobro.cot?.lineas?.[pos] : null;
        return `<li class="linea-cobro"><label><input type="checkbox" data-sel="${i.id}" ${pos >= 0 ? 'checked' : ''}>
            ${i.cantidad}× ${esc(i.descripcion)}${i.detalle ? ` <small class="tenue">${esc(i.detalle)}</small>` : ''}</label>
          <span class="num">${c ? lps(c.neto) : ''}</span>
          ${conLegal && pos >= 0 ? `<label class="chico benef"><input type="checkbox" data-legal="${i.id}" ${cobro.legal[i.id] !== false ? 'checked' : ''}> Beneficiario</label>` : ''}
        </li>`;
      }).join('');
    const r = cobro.cot?.resumen;
    const fila = (t, v, cls = '') => v ? `<dt class="${cls}">${t}</dt><dd class="${cls}">${lps(v)}</dd>` : '';
    $('#cobro-totales').innerHTML = r ? [
      fila('Subtotal', r.subtotal),
      ...cobro.cot.promociones_aplicadas.map((p) => fila('Promo: ' + esc(p.nombre), -p.descuento, 'ahorro')),
      fila(esc(cobro.cot.regla_legal?.nombre || 'Descuento de ley'), -r.descuento_legal, 'ahorro'),
      fila('Importe exento', r.importe_exento),
      fila('Importe gravado 15%', r.importe_gravado_15),
      fila('Importe gravado 18%', r.importe_gravado_18),
      fila('ISV 15%', r.isv_15),
      fila('ISV 18%', r.isv_18),
      `<dt class="total">Total factura</dt><dd class="total">${lps(r.total)}</dd>`,
    ].join('') : '<dt class="tenue">Marque al menos un artículo.</dt><dd></dd>';
    const pcts = [...new Set([0, propinaSugerida, propinaSugerida + 5])];
    $('#chips-propina').innerHTML = pcts.map((p) => `<button type="button" class="chip" data-pct="${p}">${p ? p + '%' : 'Sin propina'}</button>`).join('');
    pintarPago();
  }

  function pintarPago() {
    const forma = $('#forma-pago').value;
    $('#bloque-efectivo').hidden = forma !== 'EFECTIVO';
    $('#bloque-mixto').hidden = forma !== 'MIXTO';
    const total = cobro.cot?.resumen?.total || 0;
    const aCobrar = total + (forma === 'EFECTIVO' ? propinaEfectivo() : 0);
    const rec = aCent($('#recibido').value);
    const el = $('#cambio');
    $('#btn-facturar').textContent = total ? `Facturar ${lps(total)}${aCent($('#propina').value) > 0 ? ' + propina' : ''}` : 'Facturar';
    if (forma !== 'EFECTIVO' || !rec || !total) { el.textContent = ''; return; }
    el.textContent = rec >= aCobrar ? 'Cambio: ' + lps(rec - aCobrar) : 'Falta: ' + lps(aCobrar - rec);
    el.className = 'cambio ' + (rec >= aCobrar ? 'ok-txt' : 'mal-txt');
  }

  async function facturar() {
    const b = $('#btn-facturar');
    b.disabled = true;
    limpiarAviso('#cobro-error');
    try {
      const r = await api('cobrar', {
        cuenta_id: cuentaId,
        item_ids: [...cobro.sel],
        aplica_legal: cobro.legal,
        descuento_legal_id: $('#legal').value || null,
        beneficiario_nombre: $('#ben-nombre').value,
        beneficiario_documento: $('#ben-doc').value,
        cliente_rtn: $('#cli-rtn').value,
        cliente_nombre: $('#cli-nombre').value,
        forma_pago: $('#forma-pago').value,
        efectivo_recibido: $('#recibido').value ? aCent($('#recibido').value) : '',
        monto_efectivo: $('#monto-efectivo').value ? aCent($('#monto-efectivo').value) : 0,
        propina: aCent($('#propina').value) || 0,
        propina_forma: $('#propina-forma').value,
      });
      window.open('index.php?p=factura&id=' + r.venta_id, '_blank');
      dlgCobro.close();
      mostrarAviso(`Factura ${r.numero} emitida.` + (r.cambio > 0 ? ` Entregue cambio: ${lps(r.cambio)}.` : '')
        + (r.cuenta_cerrada ? ' La mesa quedó libre.' : ' Queda saldo pendiente en la mesa.'));
      if (r.cuenta_cerrada) {
        try { localStorage.removeItem(clave); } catch { /* sin almacenamiento */ }
        setTimeout(() => { location.href = 'index.php?p=salon'; }, 4000);
      }
      await refrescar();
    } catch (err) {
      mostrarError(err.message, '#cobro-error');
    }
    b.disabled = false;
  }

  // ---------- Carga y actualización ----------
  async function refrescar() {
    try {
      const d = await api('cuenta&id=' + cuentaId);
      cuenta = d.cuenta;
      pendiente = d.pendiente;
      pintarCuenta();
    } catch (err) { mostrarError(err.message); }
  }

  window.addEventListener('beforeunload', (e) => { if (nuevos.length) { e.preventDefault(); e.returnValue = ''; } });
  setInterval(() => { if (!document.hidden && !document.querySelector('dialog[open]')) refrescar(); }, 8000);

  function abrirPanel() { document.body.classList.add('orden-abierta'); }
  function cerrarPanel() { document.body.classList.remove('orden-abierta'); }

  api('catalogo').then((d) => {
    if ($('#legal')) {
      $('#legal').insertAdjacentHTML('beforeend', d.descuentos_legales.map((x) =>
        `<option value="${x.id}">${esc(x.nombre)} — ${(+x.porcentaje)}%</option>`).join(''));
    }
    Catalogo.montar(d, agregar);
  }).catch((e) => mostrarError(e.message));
  pintarNuevos();
  refrescar();

  return { abrirPanel, cerrarPanel };
})();
