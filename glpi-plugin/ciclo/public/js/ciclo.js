/**
 * Ciclo Financeiro — documentos (visualizar no painel lateral e escolher os arquivos
 * para anexar) e a dica das barras do Gantt da linha do tempo.
 */
(() => {
    // ---------------------------------------------------------------- documentos: visualizar e escolher arquivos
    if (!window.__cfDocs) {
        window.__cfDocs = true;
        const escD = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        document.addEventListener('click', (ev) => {
            const el = ev.target.closest('[data-doc]');
            if (!el || !window.OakDrawer) return;
            ev.preventDefault();
            const d = JSON.parse(el.dataset.doc);
            const view = d.kind === 'pdf'
                ? `<iframe class="cf-doc-frame" src="${escD(d.url)}" title="${escD(d.title)}"></iframe>`
                : `<div class="cf-doc-img"><img src="${escD(d.url)}" alt="${escD(d.title)}"></div>`;
            OakDrawer.open({title: d.title, icon: d.icon, color: d.color,
                html: `<div class="oak-dr-sub mb-2">${escD(d.meta)}</div>${view}<div class="oak-dr-foot"><a class="btn btn-outline-primary" href="${escD(d.url)}" download><i class="ti ti-download me-1"></i>Baixar</a>`
                    + `<a class="btn btn-ghost-secondary" href="${escD(d.url)}" target="_blank" rel="noopener"><i class="ti ti-external-link me-1"></i>Abrir em outra aba</a></div>`});
            document.querySelector('.oak-drawer')?.classList.add('wide');
        });
        const fmt = (f) => `${f.name} (${f.size > 1048576 ? (f.size / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.round(f.size / 1024) + ' KB'})`;
        document.addEventListener('change', (ev) => {
            const input = ev.target.closest('[data-doc-input]');
            if (!input) return;
            const drop = input.parentElement;
            const chosen = drop.parentElement.querySelector('[data-doc-chosen]');
            const files = [...input.files];
            if (chosen) chosen.textContent = files.length ? `${files.length} arquivo(s): ` + files.map(fmt).join(', ') : '';
            drop.classList.toggle('has', files.length > 0);
        });
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach((t) => document.addEventListener(t, (ev) => {
            const input = ev.target.closest?.('[data-doc-input]');
            if (input) input.parentElement.classList.toggle('over', t === 'dragenter' || t === 'dragover');
        }));
    }

    // ---------------------------------------------------------------- dica das barras do Gantt
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
