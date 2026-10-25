/**
 * Ciclo Financeiro — dica das barras do Gantt da linha do tempo (fase, situação,
 * datas, duração contra o SLO, responsável e comentário).
 */
(() => {
    const bars = document.querySelectorAll('.cf-g-bar[data-tip]');
    if (!bars.length) return;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const tip = Object.assign(document.createElement('div'), {className: 'cf-g-tip'});
    document.body.appendChild(tip);
    const row = (label, value, cls = '') => (value ? `<div class="cf-g-tip-row"><span>${label}</span><b class="${cls}">${esc(value)}</b></div>` : '');

    function show(bar, ev) {
        const d = JSON.parse(bar.dataset.tip);
        tip.innerHTML = `<div class="cf-g-tip-head ${esc(d.cls)}"><b>${esc(d.title)}</b><span>${esc(d.area)}</span></div>
            <div class="cf-g-tip-status ${esc(d.cls)}">${esc(d.status)}</div>
            ${row('Início', d.from)}${row('Fim', d.to)}${row('Duração', d.days)}${row('SLO', d.slo)}${row('Além do SLO', d.over, 'bad')}${row('Responsável', d.who)}
            ${d.note ? `<div class="cf-g-tip-note">${esc(d.note)}</div>` : ''}`;
        tip.classList.add('on');
        move(ev);
    }
    function move(ev) {
        const pad = 14;
        const w = tip.offsetWidth;
        const h = tip.offsetHeight;
        let x = ev.clientX + pad;
        let y = ev.clientY + pad;
        if (x + w > window.innerWidth - 8) x = ev.clientX - w - pad;
        if (y + h > window.innerHeight - 8) y = ev.clientY - h - pad;
        tip.style.left = `${Math.max(8, x)}px`;
        tip.style.top = `${Math.max(8, y)}px`;
    }
    bars.forEach((b) => {
        b.addEventListener('mouseenter', (ev) => show(b, ev));
        b.addEventListener('mousemove', move);
        b.addEventListener('mouseleave', () => tip.classList.remove('on'));
    });
})();
