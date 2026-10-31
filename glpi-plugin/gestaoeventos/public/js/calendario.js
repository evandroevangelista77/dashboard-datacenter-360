/**
 * Gestão de Eventos — calendário: dica ao passar o mouse nas barras e nas marcas, e resumo do dia
 * (clicar no dia) no painel lateral, o mesmo do Painel e dos documentos.
 */
(() => {
    const el = document.getElementById('ge-cal-data');
    if (!el) return;
    const data = JSON.parse(el.textContent || '{}');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const clsMap = {ok: 'ok', now: 'now', late: 'late', crit: 'late', warn: 'over', info: 'now', draft: 'skip', canceled: 'skip'};

    const tip = Object.assign(document.createElement('div'), {className: 'ge-g-tip'});
    document.body.appendChild(tip);
    const show = (node, ev) => {
        const d = JSON.parse(node.dataset.ctip);
        const k = clsMap[d.cls] || 'now';
        tip.innerHTML = `<div class="ge-g-tip-head ${k}"><b>${esc(d.title)}</b><span>${esc(d.sub)}</span></div>`
            + (d.status ? `<div class="ge-g-tip-status ${k}">${esc(d.status)}</div>` : '')
            + (d.rows || []).map(([l, v]) => (v ? `<div class="ge-g-tip-row"><span>${esc(l)}</span><b>${esc(v)}</b></div>` : '')).join('');
        tip.classList.add('on');
        move(ev);
    };
    const move = (ev) => {
        const pad = 14; const w = tip.offsetWidth; const h = tip.offsetHeight;
        let x = ev.clientX + pad; let y = ev.clientY + pad;
        if (x + w > window.innerWidth - 8) x = ev.clientX - w - pad;
        if (y + h > window.innerHeight - 8) y = ev.clientY - h - pad;
        tip.style.left = `${Math.max(8, x)}px`; tip.style.top = `${Math.max(8, y)}px`;
    };
    document.querySelectorAll('[data-ctip]').forEach((n) => {
        n.addEventListener('mouseenter', (ev) => show(n, ev));
        n.addEventListener('mousemove', move);
        n.addEventListener('mouseleave', () => tip.classList.remove('on'));
    });

    // clicar no dia (fora das barras e marcas) abre o resumo do dia
    document.querySelectorAll('.ge-cal-day').forEach((cell) => cell.addEventListener('click', (ev) => {
        if (ev.target.closest('a') || !window.OakDrawer) return;
        const day = cell.dataset.day;
        const items = data[day] || [];
        const [y, m, d] = day.split('-');
        const icon = {crit: '#ef4444', warn: '#f59e0b', ok: '#22c55e', info: '#3b82f6', now: '#3b82f6', late: '#ef4444', draft: '#94a3b8', canceled: '#94a3b8'};
        OakDrawer.open({title: `${d}/${m}/${y}`, icon: 'ti-calendar', color: '#14b8a6', html: items.length
            ? `<p class="oak-dr-sub">${items.length} item(ns) neste dia.</p><div class="ge-dr-list">${items.map((i) => `<div class="ge-dr-item ${i.cls === 'crit' || i.cls === 'late' ? 'bad' : (i.cls === 'ok' ? 'good' : (i.cls === 'warn' ? 'warn' : ''))}"><div class="ge-dr-row"><a href="${esc(i.url)}"><i class="ti ${esc(i.icon)} me-1" style="color:${icon[i.cls] || '#3b82f6'}"></i><b>${esc(i.title)}</b></a></div><div class="ge-dr-sub">${esc(i.sub)}</div></div>`).join('')}</div>`
            : '<p class="text-secondary">Nada neste dia.</p>'});
    }));
})();
