/* Power POS · Utilidades de todas las pantallas con JavaScript: llamadas a la API y formato. */
const Power = (() => {
  const $ = (s) => document.querySelector(s);
  const csrf = document.querySelector('[data-csrf]')?.dataset.csrf || '';
  const lps = (c) => 'L ' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /** GET si no hay cuerpo; POST con JSON y token CSRF si lo hay. */
  async function api(accion, cuerpo) {
    const r = await fetch('api.php?a=' + accion, cuerpo === undefined ? {} : {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(cuerpo),
    });
    if (r.status === 401) location.href = 'index.php?p=login';
    const data = await r.json().catch(() => ({ error: 'Respuesta inválida del servidor. Recargue la página.' }));
    if (!r.ok) throw new Error(data.error || 'Error');
    return data;
  }

  /** "1,125.50" → 112550 centavos. */
  const aCent = (v) => Math.round(parseFloat(String(v).replace(/,/g, '')) * 100);

  return { $, api, lps, esc, aCent };
})();
