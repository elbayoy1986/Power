/* Power POS · Caja rápida (mostrador). El navegador solo arma la orden; los montos SIEMPRE los calcula el servidor. */
const POS = (() => {
  const { $, api, lps, esc, aCent } = Power;

  let orden = [];          // [{uid, tipo, producto_id|combo_id, nombre, cantidad, opciones, detalle, aplica_legal}]
  let cotizacion = null;
  let uid = 0;

  /** Lo que se toca en el catálogo: un producto repetido suma cantidad; cada combo armado es su propia línea. */
  function agregar(item) {
    const existente = item.tipo === 'PRODUCTO' && orden.find((l) => l.tipo === 'PRODUCTO' && l.producto_id === item.producto_id);
    if (existente) existente.cantidad++;
    else orden.push({ uid: ++uid, ...item, aplica_legal: true });
    cambio();
  }

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
    pintarPago();
  }

  // ---------- Pago: efectivo recibido, cambio y mixto ----------
  function pintarPago() {
    const forma = $('#forma-pago').value;
    $('#bloque-efectivo').hidden = forma !== 'EFECTIVO';
    $('#bloque-mixto').hidden = forma !== 'MIXTO';
    const total = cotizacion?.resumen?.total || 0;
    const rec = aCent($('#recibido').value);
    const el = $('#cambio');
    if (forma !== 'EFECTIVO' || !rec || !total) { el.textContent = ''; return; }
    el.textContent = rec >= total ? 'Cambio: ' + lps(rec - total) : 'Falta: ' + lps(total - rec);
    el.className = 'cambio ' + (rec >= total ? 'ok-txt' : 'mal-txt');
  }
  ['#forma-pago', '#recibido'].forEach((s) => $(s).addEventListener('input', pintarPago));
  $('#forma-pago').addEventListener('change', pintarPago);

  function mostrarError(msg) { $('#error').textContent = msg; $('#error').className = 'aviso error'; $('#error').hidden = false; }
  function mostrarAviso(msg) { $('#error').textContent = msg; $('#error').className = 'aviso'; $('#error').hidden = false; }

  $('#btn-facturar').addEventListener('click', async () => {
    const b = $('#btn-facturar');
    b.disabled = true;
    try {
      const { venta_id, cambio } = await api('facturar', {
        ...cuerpo(),
        beneficiario_nombre: $('#ben-nombre').value,
        beneficiario_documento: $('#ben-doc').value,
        cliente_rtn: $('#cli-rtn').value,
        cliente_nombre: $('#cli-nombre').value,
        forma_pago: $('#forma-pago').value,
        efectivo_recibido: $('#recibido').value ? aCent($('#recibido').value) : '',
        monto_efectivo: $('#monto-efectivo').value ? aCent($('#monto-efectivo').value) : 0,
      });
      window.open('index.php?p=factura&id=' + venta_id, '_blank');
      orden = []; cotizacion = null;
      ['#ben-nombre', '#ben-doc', '#cli-rtn', '#cli-nombre', '#legal', '#recibido', '#monto-efectivo'].forEach((s) => { $(s).value = ''; });
      $('#forma-pago').value = 'EFECTIVO';
      pintarPago();
      $('#legal-datos').hidden = true;
      pintarOrden();
      cerrarOrden();
      if (cambio > 0) mostrarAviso('Factura emitida. Entregue cambio: ' + lps(cambio));
    } catch (err) {
      mostrarError(err.message);
      b.disabled = false;
    }
  });

  function abrirOrden() { document.body.classList.add('orden-abierta'); }
  function cerrarOrden() { document.body.classList.remove('orden-abierta'); }

  api('catalogo').then((d) => {
    $('#legal').insertAdjacentHTML('beforeend', d.descuentos_legales.map((x) =>
      `<option value="${x.id}">${esc(x.nombre)} — ${(+x.porcentaje)}%</option>`).join(''));
    Catalogo.montar(d, agregar);
    pintarOrden();
  }).catch((e) => mostrarError(e.message));

  return { abrirOrden, cerrarOrden };
})();
