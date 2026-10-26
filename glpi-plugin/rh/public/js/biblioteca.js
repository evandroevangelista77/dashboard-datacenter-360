/**
 * Biblioteca de documentos (a mesma nos plugins RH, Reembolso, PMO e Ciclo):
 * visualizar PDF e imagens no painel lateral (drawer.js) e mostrar os arquivos
 * escolhidos para anexar.
 */
(() => {
    if (window.__oakLib) return;
    window.__oakLib = true;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    document.addEventListener('click', (ev) => {
        const el = ev.target.closest('[data-doc]');
        if (!el || !window.OakDrawer) return;
        ev.preventDefault();
        const d = JSON.parse(el.dataset.doc);
        const view = d.kind === 'pdf'
            ? `<iframe class="oak-doc-frame" src="${esc(d.url)}" title="${esc(d.title)}"></iframe>`
            : `<div class="oak-doc-img"><img src="${esc(d.url)}" alt="${esc(d.title)}"></div>`;
        OakDrawer.open({title: d.title, icon: d.icon, color: d.color,
            html: `<div class="oak-dr-sub mb-2">${esc(d.meta)}</div>${view}<div class="oak-dr-foot"><a class="btn btn-outline-primary" href="${esc(d.url)}" download><i class="ti ti-download me-1"></i>Baixar</a>`
                + `<a class="btn btn-ghost-secondary" href="${esc(d.url)}" target="_blank" rel="noopener"><i class="ti ti-external-link me-1"></i>Abrir em outra aba</a></div>`});
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
})();
