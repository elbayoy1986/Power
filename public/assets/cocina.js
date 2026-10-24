/* Power POS · Pantalla de cocina (KDS): comandas por estación, se actualiza cada 4 segundos.
   Toque un artículo para marcarlo listo; cuando no queda nada pendiente la comanda sale sola.
   Con "Imprimir automáticamente", este equipo imprime cada comanda nueva una sola vez (aunque haya otra pantalla igual). */
(() => {
  const { $, api, esc } = Power;
  const raiz = $('#cocina');
  const estacion = +raiz.dataset.estacion;
  const todas = raiz.dataset.todas === '1';
  const pref = (k) => { try { return localStorage.getItem(`cocina-${estacion}-${k}`) === '1'; } catch { return false; } };
  const guardarPref = (k, v) => { try { localStorage.setItem(`cocina-${estacion}-${k}`, v ? '1' : '0'); } catch { /* sin almacenamiento */ } };

  const chkSonido = $('#sonido');
  const chkImprimir = $('#auto-imprimir');
  chkSonido.checked = pref('sonido');
  chkImprimir.checked = pref('imprimir');
  chkSonido.addEventListener('change', () => { guardarPref('sonido', chkSonido.checked); if (chkSonido.checked) pitar(); });
  chkImprimir.addEventListener('change', () => { guardarPref('imprimir', chkImprimir.checked); refrescar(); });

  let vistas = null;       // ids ya vistos, para avisar solo lo nuevo
  let datos = { activas: [], recientes: [] };
  let recibido = Date.now();

  // ---------- Sonido (el navegador lo permite después de que alguien toca la pantalla) ----------
  let audio;
  function pitar() {
    try {
      audio ??= new AudioContext();
      [0, 0.18].forEach((t) => {
        const o = audio.createOscillator();
        const g = audio.createGain();
        o.frequency.value = 880;
        g.gain.setValueAtTime(0.25, audio.currentTime + t);
        g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + t + 0.15);
        o.connect(g).connect(audio.destination);
        o.start(audio.currentTime + t);
        o.stop(audio.currentTime + t + 0.16);
      });
    } catch { /* sin audio */ }
  }

  // ---------- Impresión ----------
  const iframe = $('#impresora');
  const cola = [];
  let imprimiendo = false;
  function imprimir(id) { cola.push(id); siguiente(); }
  function siguiente() {
    if (imprimiendo || !cola.length) return;
    imprimiendo = true;
    iframe.onload = () => {
      try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch { /* bloqueado */ }
      setTimeout(() => { imprimiendo = false; siguiente(); }, 800);
    };
    iframe.src = 'index.php?p=comanda&id=' + cola.shift();
  }
  async function autoImprimir() {
    if (!chkImprimir.checked) return;
    for (const k of datos.activas.filter((x) => !x.impresa)) {
      try { if ((await api('reclamar_impresion', { comanda_id: k.id })).imprimir) { k.impresa = true; imprimir(k.id); } } catch { /* reintenta luego */ }
    }
  }

  // ---------- Pintar ----------
  const reloj = (seg) => `${Math.floor(seg / 60)}:${String(seg % 60).padStart(2, '0')}`;
  const nivel = (seg) => seg >= 1200 ? 'tarde' : seg >= 600 ? 'atento' : '';

  function tarjeta(k) {
    const seg = k.segundos + Math.floor((Date.now() - recibido) / 1000);
    const anul = k.tipo === 'ANULACION';
    return `<article class="comanda ${anul ? 'anulacion' : nivel(seg)}" data-id="${k.id}">
      <header>
        <b class="ref">${esc(k.referencia)}</b>
        <span class="reloj" data-seg="${k.segundos}">${reloj(seg)}</span>
        <small>${anul ? '⚠ ANULAR' : esc(k.mesero ? `${k.mesero} · ${k.comensales} pers.` : k.enviada_por)}${todas ? ' · ' + esc(k.estacion) : ''} · #${k.id}</small>
      </header>
      <ul>${k.items.map((i) => `<li class="${i.listo ? 'listo' : ''} ${i.anulado ? 'anulado' : ''}" ${anul || i.anulado ? '' : `data-item="${i.id}" data-listo="${i.listo ? 1 : 0}"`}>
          <b>${i.cantidad}×</b> <span>${esc(i.descripcion)}${i.detalle ? `<small>${esc(i.detalle)}</small>` : ''}
          ${i.nota ? `<em class="nota">📝 ${esc(i.nota)}</em>` : ''}${i.anulado ? '<small class="mal-txt">ANULADO</small>' : ''}</span></li>`).join('')}</ul>
      <footer>
        <button class="btn" data-reimprimir="${k.id}" title="Imprimir">🖨</button>
        <button class="btn primario" data-despachar="${k.id}">${anul ? 'Enterado' : 'Listo ✓'}</button>
      </footer>
    </article>`;
  }

  function pintar() {
    raiz.innerHTML = datos.activas.map(tarjeta).join('') || '<p class="tenue vacio-kds">Sin comandas pendientes. 👌</p>';
    $('#recientes').innerHTML = datos.recientes.map((k) => `<div class="reciente">
        <span><b>${esc(k.referencia)}</b> · ${k.items.map((i) => `${i.cantidad}× ${esc(i.descripcion)}`).join(', ')}</span>
        <span class="tenue chico">${k.estado === 'ENTREGADA' ? 'Servida' : 'Lista'} ${String(k.lista_en).slice(11, 16)}</span>
        ${k.estado === 'LISTA' && k.tipo === 'PEDIDO' ? `<button class="btn texto" data-recuperar="${k.id}">Recuperar</button>` : '<span></span>'}
      </div>`).join('') || '<p class="tenue chico">Nada todavía.</p>';
    $('#contador').textContent = `${datos.activas.length} en preparación`;
  }

  // Los relojes avanzan cada segundo sin pedir datos al servidor
  setInterval(() => {
    const extra = Math.floor((Date.now() - recibido) / 1000);
    raiz.querySelectorAll('.reloj').forEach((el) => {
      const seg = +el.dataset.seg + extra;
      el.textContent = reloj(seg);
      const tarj = el.closest('.comanda');
      if (!tarj.classList.contains('anulacion')) {
        tarj.classList.toggle('atento', seg >= 600 && seg < 1200);
        tarj.classList.toggle('tarde', seg >= 1200);
      }
    });
  }, 1000);

  async function refrescar() {
    try {
      datos = await api('cocina' + (estacion ? '&estacion=' + estacion : ''));
      recibido = Date.now();
      const ids = new Set(datos.activas.map((k) => k.id));
      if (vistas && chkSonido.checked && [...ids].some((id) => !vistas.has(id))) pitar();
      vistas = ids;
      pintar();
      autoImprimir();
    } catch (err) {
      raiz.innerHTML = `<p class="aviso error">${esc(err.message)} · Reintentando…</p>`;
    }
  }

  async function accion(nombre, cuerpo) {
    try { await api(nombre, cuerpo); } catch (err) { alert(err.message); }
    refrescar();
  }

  document.addEventListener('click', (e) => {
    const item = e.target.closest('[data-item]');
    if (item) return accion('item_listo', { item_id: +item.dataset.item, listo: item.dataset.listo !== '1' });
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.despachar) accion('despachar', { comanda_id: +b.dataset.despachar });
    else if (b.dataset.recuperar) accion('recuperar', { comanda_id: +b.dataset.recuperar });
    else if (b.dataset.reimprimir) imprimir(+b.dataset.reimprimir);
  });

  refrescar();
  setInterval(() => { if (!document.hidden) refrescar(); }, 4000);
})();
