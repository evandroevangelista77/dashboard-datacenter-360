/**
 * Lista de tarefas do projeto: barra de evolução no "Percentual finalizado" e
 * linhas alternadas (zebra). As listas chegam por AJAX nas abas, por isso o observer.
 */
(() => {
    const color = (pct) => pct >= 100 ? 'done' : pct >= 50 ? 'high' : pct > 0 ? 'low' : 'zero';

    const decorate = (table) => {
        if (table.dataset.cronogramaTarefas) return;
        if (!table.querySelector('tbody > tr[data-itemtype="ProjectTask"]')) return;
        const headers = [...table.querySelectorAll('thead > tr:last-child > th')];
        const col = headers.findIndex((th) => /percent|% /i.test(th.textContent.trim()));
        table.dataset.cronogramaTarefas = '1';
        table.classList.add('cronograma-tarefas');
        if (col < 0) return;
        table.querySelectorAll('tbody > tr').forEach((tr) => {
            const td = tr.children[col];
            const m = td && td.textContent.match(/(\d+(?:[.,]\d+)?)\s*%/);
            if (!m) return;
            const pct = Math.max(0, Math.min(100, Math.round(parseFloat(m[1].replace(',', '.')))));
            td.innerHTML = `<div class="cg-progress" title="${pct}% concluído">`
                + `<div class="cg-track"><div class="cg-fill cg-${color(pct)}" style="width:${pct}%"></div></div>`
                + `<span class="cg-label">${pct}%</span></div>`;
        });
    };

    let pending = false;
    const scan = () => {
        pending = false;
        document.querySelectorAll('table.table').forEach(decorate);
    };
    const schedule = () => {
        if (!pending) {
            pending = true;
            requestAnimationFrame(scan);
        }
    };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', scan) : scan();
    new MutationObserver(schedule).observe(document.documentElement, {childList: true, subtree: true});
})();
