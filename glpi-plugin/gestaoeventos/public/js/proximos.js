/**
 * Gestão de Eventos — pop-up "Próximos eventos" do Painel, com contagem regressiva ao vivo.
 * Abre sozinho uma vez por dia (se ligado na configuração) e a qualquer momento pelo botão do Painel.
 */
(() => {
    const el = document.getElementById('ge-prox-data');
    if (!el) return;
    const d = JSON.parse(el.textContent || '{}');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const offsetMs = d.now * 1000 - Date.now();
    const nowSec = () => (Date.now() + offsetMs) / 1000;
    const p2 = (n) => String(n).padStart(2, '0');
    const parts = (sec) => ({d: Math.floor(sec / 86400), h: Math.floor(sec % 86400 / 3600), m: Math.floor(sec % 3600 / 60), s: Math.floor(sec % 60)});
    const level = (left) => (left <= 0 ? 'now' : left <= 172800 ? 'hot' : left <= 604800 ? 'warm' : 'cool');
    const cdHtml = (left) => {
        if (left <= 0) return '<span class="ge-cd-big now">Em andamento</span>';
        const t = parts(left);
        return `<div class="ge-cd-grid"><div><b>${t.d}</b><small>dias</small></div><div><b>${p2(t.h)}</b><small>horas</small></div><div><b>${p2(t.m)}</b><small>min</small></div><div><b>${p2(t.s)}</b><small>seg</small></div></div>`;
    };
    let modal = null; let tick = null;

    function build() {
        const rows = d.events.map((e) => {
            const left = e.start - nowSec();
            return `<div class="ge-prox ${level(left)}" data-start="${e.start}" data-end="${e.end}"><div class="ge-prox-cd">${cdHtml(left)}</div>`
                + `<div class="ge-prox-main"><a class="ge-prox-name" href="${esc(e.url)}">${esc(e.name)}</a>`
                + `<div class="ge-prox-sub"><i class="ti ti-calendar"></i>${esc(e.dateFmt)}${e.city ? ` · <i class="ti ti-map-pin"></i>${esc(e.city)}` : ''} · ${esc(e.type)}</div>`
                + `<div class="ge-prox-chips">${e.phase ? `<span class="badge ge-badge" style="--c:${e.phaseLate ? '#ef4444' : '#3b82f6'}">${esc(e.phase)}${e.phaseLate ? ' · atrasada' : ''}</span>` : ''}`
                + `<span class="badge ge-badge" style="--c:${esc(e.approvalColor)}">${esc(e.approval)}</span>`
                + (e.risk && e.risk.length ? `<span class="badge ge-badge" style="--c:#ef4444" title="${esc(e.risk.join('; '))}"><i class="ti ti-alert-triangle me-1"></i>em risco</span>` : '')
                + (e.gp ? `<span class="small text-secondary"><i class="ti ti-user me-1"></i>${esc(e.gp)}</span>` : '') + `</div></div>`
                + `<a class="btn btn-sm btn-outline-primary ge-prox-go" href="${esc(e.url)}">Abrir</a></div>`;
        }).join('') || '<div class="ge-empty" style="min-height:160px"><i class="ti ti-calendar-off"></i>Nenhum evento nos próximos ' + d.days + ' dias.</div>';
        modal = Object.assign(document.createElement('div'), {className: 'ge-modal', hidden: true});
        modal.innerHTML = `<div class="ge-modal-bg"></div><div class="ge-modal-box" role="dialog" aria-modal="true" aria-label="Próximos eventos">`
            + `<div class="ge-modal-head"><span class="ge-modal-icon"><i class="ti ti-hourglass-high"></i></span><div class="me-auto"><h3>Próximos eventos</h3><small>contagem regressiva até o início · próximos ${d.days} dias</small></div>`
            + `<button type="button" class="btn btn-sm btn-ghost-secondary" data-act="mute" title="Ligar ou desligar o som dos avisos"><i class="ti ti-volume"></i></button><button type="button" class="btn-close" aria-label="Fechar" data-act="close"></button></div>`
            + `<div class="ge-modal-body">${rows}</div>`
            + `<div class="ge-modal-foot"><label class="form-check m-0"><input class="form-check-input" type="checkbox" id="ge-prox-hide"><span class="form-check-label small">Não abrir sozinho de novo hoje</span></label>`
            + `<a class="btn btn-sm btn-outline-secondary" href="${esc(d.calendar)}"><i class="ti ti-calendar me-1"></i>Calendário</a></div></div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', (ev) => {
            if (ev.target.classList.contains('ge-modal-bg') || ev.target.closest('[data-act="close"]')) close();
            if (ev.target.closest('[data-act="mute"]')) {
                const muted = localStorage.getItem('ge-mute') === '1'; localStorage.setItem('ge-mute', muted ? '0' : '1');
                modal.querySelector('[data-act="mute"] i').className = `ti ${muted ? 'ti-volume' : 'ti-volume-off'}`;
            }
        });
        document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && !modal.hidden) close(); });
        if (localStorage.getItem('ge-mute') === '1') modal.querySelector('[data-act="mute"] i').className = 'ti ti-volume-off';
    }
    function refresh() {
        modal.querySelectorAll('.ge-prox').forEach((n) => {
            const left = Number(n.dataset.start) - nowSec();
            n.className = `ge-prox ${level(left)}`;
            n.querySelector('.ge-prox-cd').innerHTML = cdHtml(left);
        });
    }
    function open() {
        if (!modal) build();
        modal.hidden = false; refresh(); clearInterval(tick); tick = setInterval(refresh, 1000);
        modal.querySelector('.btn-close')?.focus();
    }
    function close() {
        if (modal.querySelector('#ge-prox-hide')?.checked) { try { localStorage.setItem(`ge-prox-${d.today}-${d.user}`, '1'); } catch (e) { /* ok */ } }
        modal.hidden = true; clearInterval(tick);
    }
    document.getElementById('ge-prox-open')?.addEventListener('click', open);
    let seen = false; try { seen = localStorage.getItem(`ge-prox-${d.today}-${d.user}`) === '1'; } catch (e) { /* ok */ }
    if (d.auto && d.events.length && !seen) setTimeout(open, 600);
})();
