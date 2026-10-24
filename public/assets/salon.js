/* Power POS · Salón en vivo: estado de cada mesa, se actualiza solo cada 10 segundos. */
(() => {
  const { $, api, esc } = Power;
  const raiz = $('#salon');
  const yo = +raiz.dataset.usuario;
  const filtro = $('#solo-mias');
  let zonas = JSON.parse(raiz.dataset.inicial || '[]');

  try { filtro.checked = (localStorage.getItem('salon-solo-mias') ?? (raiz.dataset.rol === 'MESERO' ? '1' : '0')) === '1'; } catch { /* sin almacenamiento */ }
  filtro.addEventListener('change', () => {
    try { localStorage.setItem('salon-solo-mias', filtro.checked ? '1' : '0'); } catch { /* sin almacenamiento */ }
    pintar();
  });

  const estado = (c) => !c ? 'libre' : c.estado === 'POR_COBRAR' ? 'cobrar' : c.listas ? 'lista' : 'ocupada';
  const tiempo = (min) => min < 60 ? `${min} min` : `${Math.floor(min / 60)} h ${min % 60} min`;

  function tarjeta(m) {
    const c = m.cuenta;
    const est = estado(c);
    const detalle = !c
      ? `<span class="mesa-info">${m.capacidad} personas</span>`
      : `<span class="mesa-info"><b>${esc(c.mesero)}</b> · ${c.comensales} pers. · ${tiempo(c.minutos)}</span>
         <span class="mesa-flags">${c.listas ? `<b>🔔 ${c.listas === 1 ? 'Listo para servir' : c.listas + ' listos'}</b>` : ''}
           ${c.en_cocina ? `<span>🍳 ${c.en_cocina} en cocina</span>` : ''}
           ${!c.articulos ? '<span>Sin pedido</span>' : ''}
           ${est === 'cobrar' ? '<b>💳 Cobrar</b>' : ''}</span>`;
    return `<a class="mesa-tarjeta ${est}" href="index.php?p=mesa&id=${m.id}" data-mesa="${m.id}">
      <span class="mesa-num">${esc(m.nombre)}</span>${detalle}</a>`;
  }

  function pintar() {
    const soloMias = filtro.checked;
    let libres = 0; let ocupadas = 0; let cobrar = 0;
    raiz.innerHTML = zonas.map((z) => {
      z.mesas.forEach((m) => { if (!m.cuenta) libres++; else { ocupadas++; if (m.cuenta.estado === 'POR_COBRAR') cobrar++; } });
      const visibles = z.mesas.filter((m) => !soloMias || !m.cuenta || m.cuenta.mesero_id === yo);
      return `<section class="zona"><h2>${esc(z.nombre)}</h2>
        <div class="mesas">${visibles.map(tarjeta).join('') || '<p class="tenue">Sin mesas que mostrar.</p>'}</div></section>`;
    }).join('');
    $('#resumen-salon').textContent = zonas.length ? `· ${libres} libres · ${ocupadas} ocupadas${cobrar ? ` · ${cobrar} por cobrar` : ''}` : '';
  }

  async function refrescar() {
    if (document.hidden) return;
    try { zonas = (await api('salon')).zonas; pintar(); } catch { /* reintenta en el próximo ciclo */ }
  }

  pintar();
  setInterval(refrescar, 10000);
  document.addEventListener('visibilitychange', refrescar);
})();
