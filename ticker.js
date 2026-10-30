/* NOC 360 — faixa "ao vivo" (problemas ativos do Zabbix) para as páginas de topologia; lê api.php?action=data */
(() => {
  'use strict';
  const el = document.getElementById('tickerMove');
  if (!el) return;
  const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
  const SEV = ['Não classificada', 'Informação', 'Atenção', 'Média', 'Alta', 'Desastre'];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const dur = (s) => {
    s = Math.max(0, Math.round(s));
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
  };
  const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  async function load() {
    try {
      const to = new Date(), from = new Date(to.getTime() - 6 * 86400000);
      const r = await fetch(`api.php?action=data&from=${ymd(from)}&to=${ymd(to)}`, { cache: 'no-store' });
      const active = ((await r.json()).active || []).slice().sort((a, b) => b.severity - a.severity || a.clock - b.clock);
      const now = Date.now() / 1000;
      el.innerHTML = active.length ? active.map((p) => `
        <a class="ticker-item" href="${esc(p.url)}" target="_blank" rel="noopener" title="Abrir este alerta no Zabbix">
          <i style="background:${css(`--sev-${p.severity}`)}"></i><b>${esc(p.host)}</b> ${esc(p.name)} <em>${SEV[p.severity]} · há ${dur(now - p.clock)}${p.acknowledged ? '' : ' · sem ACK'}</em>
        </a>`).join('')
        : '<span class="ticker-item"><i style="background:var(--good)"></i><b>Nenhum alerta ativo</b> — ambiente estável</span>';
      requestAnimationFrame(() => el.style.setProperty('--ticker-time', `${Math.max(25, el.scrollWidth / 70)}s`));
    } catch (e) { /* mantém o que já está na faixa */ }
  }
  load();
  setInterval(load, 60000);
})();
