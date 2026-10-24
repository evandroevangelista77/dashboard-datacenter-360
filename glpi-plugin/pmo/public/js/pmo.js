/**
 * PMO — utilitários comuns às visões (API, janelas, avatares, arrastar e soltar,
 * editor de cartão).
 */
window.PMO = (() => {
    const bootEl = document.getElementById('pmo-boot');
    const boot = bootEl ? JSON.parse(bootEl.textContent) : {};

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    async function api(action, params = {}, post = false, b = boot) {
        const url = new URL(b.urls.ajax, location.origin);
        const opts = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
        if (post) {
            opts.method = 'POST';
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({action, project: b.project, ...params});
        } else {
            url.searchParams.set('action', action);
            if (b.project) url.searchParams.set('project', b.project);
            Object.entries(params).forEach(([k, v]) => v !== undefined && v !== null && url.searchParams.set(k, v));
        }
        const res = await fetch(url, opts);
        let data;
        try {
            data = await res.json();
        } catch (e) {
            throw new Error(`Resposta inválida do servidor (HTTP ${res.status}).`);
        }
        if (!res.ok || data.ok === false) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    let toastBox;
    function toast(msg, kind = '') {
        toastBox ??= document.body.appendChild(Object.assign(document.createElement('div'), {className: 'pmo-toast'}));
        const el = toastBox.appendChild(Object.assign(document.createElement('div'), {className: kind, textContent: msg}));
        setTimeout(() => el.remove(), kind === 'err' ? 7000 : 3500);
    }
    const fail = (e) => toast(e.message || String(e), 'err');

    /** Janela simples. buttons: [{label, cls, onClick(modal) -> false mantém aberta}] */
    function modal({title, body, buttons = [], wide = false}) {
        const back = document.createElement('div');
        back.className = 'pmo-modal-back';
        back.innerHTML = `<div class="pmo-modal" ${wide ? 'style="max-width:820px"' : ''}><div class="pmo-modal-h"><h3>${title}</h3>`
            + `<button type="button" class="btn-close" aria-label="Fechar"></button></div><div class="pmo-modal-b">${body}</div>`
            + `<div class="pmo-modal-f"></div></div>`;
        const close = () => { back.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => e.key === 'Escape' && close();
        const m = {el: back.querySelector('.pmo-modal'), close};
        const foot = back.querySelector('.pmo-modal-f');
        buttons.forEach((b) => {
            const btn = document.createElement(b.href ? 'a' : 'button');
            btn.className = 'btn ' + (b.cls || 'btn-secondary');
            btn.innerHTML = b.label;
            if (b.href) { btn.href = b.href; btn.target = '_blank'; }
            if (b.right) btn.style.marginLeft = 'auto';
            btn.addEventListener('click', async () => {
                if (!b.onClick) return close();
                btn.disabled = true;
                try {
                    if (await b.onClick(m) !== false) close();
                } catch (e) { fail(e); } finally { btn.disabled = false; }
            });
            foot.appendChild(btn);
        });
        back.querySelector('.btn-close').onclick = close;
        back.addEventListener('mousedown', (e) => e.target === back && close());
        document.addEventListener('keydown', onKey);
        document.body.appendChild(back);
        back.querySelector('input,select,textarea')?.focus();
        return m;
    }

    const av = (u) => `<span class="pmo-av" style="--h:${u.hue}" title="${esc(u.name)}">${esc(u.initials)}</span>`;
    const avs = (list, max = 3) => list.length ? `<span class="pmo-avs">${list.slice(0, max).map(av).join('')}${list.length > max ? `<span class="pmo-av" style="--h:0;background:#475569">+${list.length - max}</span>` : ''}</span>` : '';

    const TYPE_ICONS = [[/bug|defeito|erro/i, 'ti-bug', '#ef4444'], [/hist|story/i, 'ti-bookmark', '#22c55e'], [/melhor|improv/i, 'ti-trending-up', '#06b6d4'],
        [/épico|epico|epic/i, 'ti-bolt', '#a855f7'], [/subtarefa|sub-task/i, 'ti-subtask', '#64748b'], [/./, 'ti-square-check', '#3b82f6']];
    const typeIcon = (name, milestone) => {
        if (milestone) return `<i class="ti ti-diamond pmo-type" style="color:#f59e0b" title="Marco"></i>`;
        const [, icon, color] = TYPE_ICONS.find(([re]) => re.test(name || 'tarefa'));
        return `<i class="ti ${icon} pmo-type" style="color:${color}" title="${esc(name || 'Tarefa')}"></i>`;
    };
    const PRIOS = {1: ['Altíssima', 'ti-chevrons-up'], 2: ['Alta', 'ti-chevron-up'], 3: ['Média', 'ti-equal'], 4: ['Baixa', 'ti-chevron-down'], 5: ['Mínima', 'ti-chevrons-down']};
    const prio = (p) => `<i class="ti ${PRIOS[p]?.[1] || 'ti-equal'} pmo-prio pmo-prio-${p}" title="Prioridade ${PRIOS[p]?.[0] || ''}"></i>`;
    const PRIO_COLORS = {1: '#ef4444', 2: '#f97316', 3: '#eab308', 4: '#3b82f6', 5: '#64748b'};
    const fmt = (d) => d ? d.slice(0, 10).split('-').reverse().join('/') : '';
    const fmtShort = (d) => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';

    /**
     * Arrastar e soltar (HTML5) com delegação: listas `listSel` dentro de `root`,
     * itens `itemSel` com draggable=true. onDrop(item, list) depois de reposicionar no DOM.
     */
    function sortable(root, listSel, itemSel, onDrop) {
        let dragged = null;
        const ph = document.createElement('div');
        ph.className = 'pmo-placeholder';
        root.addEventListener('dragstart', (e) => {
            const it = e.target.closest(itemSel);
            if (!it) return;
            dragged = it;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', it.dataset.id);
            ph.style.height = it.offsetHeight + 'px';
            requestAnimationFrame(() => it.classList.add('dragging'));
        });
        root.addEventListener('dragend', () => {
            dragged?.classList.remove('dragging');
            ph.remove();
            root.querySelectorAll('.drag-over').forEach((l) => l.classList.remove('drag-over'));
            dragged = null;
        });
        root.addEventListener('dragover', (e) => {
            const list = e.target.closest(listSel);
            if (!dragged || !list) return;
            e.preventDefault();
            root.querySelectorAll('.drag-over').forEach((l) => l !== list && l.classList.remove('drag-over'));
            list.classList.add('drag-over');
            const after = [...list.querySelectorAll(itemSel + ':not(.dragging)')].find((el) => {
                const r = el.getBoundingClientRect();
                return e.clientY < r.top + r.height / 2;
            });
            after ? list.insertBefore(ph, after) : list.appendChild(ph);
        });
        root.addEventListener('drop', (e) => {
            const list = e.target.closest(listSel);
            if (!dragged || !list) return;
            e.preventDefault();
            list.classList.remove('drag-over');
            ph.parentNode === list ? list.insertBefore(dragged, ph) : list.appendChild(dragged);
            ph.remove();
            list.querySelector('.pmo-bl-empty')?.remove();
            onDrop(dragged, list);
        });
    }

    /** Janela de edição rápida do cartão. ctx: {users, types, sprints, config}. */
    function editCard(card, ctx, onSaved) {
        const est = ctx.config?.estimation || 'points';
        const sel = new Set(card.users.map((u) => u.id));
        const typeOpts = `<option value="0">—</option>` + ctx.types.map((t) => `<option value="${t.id}" ${t.id === card.type ? 'selected' : ''}>${esc(t.name)}</option>`).join('');
        const prioOpts = Object.entries(PRIOS).map(([k, [n]]) => `<option value="${k}" ${+k === card.priority ? 'selected' : ''}>${n}</option>`).join('');
        const sprintOpts = boot.sprints
            ? `<option value="0">Backlog</option>` + ctx.sprints.filter((s) => s.state !== 'closed' || s.id === card.sprint)
                .map((s) => `<option value="${s.id}" ${s.id === card.sprint ? 'selected' : ''}>${esc(s.name)} (${esc(s.stateName)})</option>`).join('')
            : '';
        const users = ctx.users.map((u) => `<label data-n="${esc(u.name.toLowerCase())}"><input type="checkbox" class="form-check-input" value="${u.id}" ${sel.has(u.id) ? 'checked' : ''}>${av(u)}${esc(u.name)}</label>`).join('');
        const ro = boot.edit ? '' : 'disabled';
        const body = `
            <div class="mb-2"><label class="form-label">Título</label><input class="form-control" name="name" value="${esc(card.name)}" ${ro}></div>
            ${card.parent ? `<div class="small text-secondary mb-2"><i class="ti ti-hierarchy-2 me-1"></i>Dentro de: ${esc(card.parent.name)}</div>` : ''}
            <div class="row g-2 mb-2">
                <div class="col-4"><label class="form-label">Tipo</label><select class="form-select" name="type" ${ro}>${typeOpts}</select></div>
                <div class="col-4"><label class="form-label">Prioridade</label><select class="form-select" name="priority" ${ro}>${prioOpts}</select></div>
                <div class="col-4"><label class="form-label">${est === 'points' ? 'Story points' : 'Pontos'}</label><input type="number" step="0.5" min="0" class="form-control" name="points" value="${card.points || ''}" ${ro}></div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-4"><label class="form-label">Início planejado</label><input type="date" class="form-control" name="plan_start_date" value="${card.start}" ${ro}></div>
                <div class="col-4"><label class="form-label">Fim planejado</label><input type="date" class="form-control" name="plan_end_date" value="${card.end}" ${ro}></div>
                <div class="col-4"><label class="form-label">Horas planejadas</label><input type="number" step="0.5" min="0" class="form-control" name="hours" value="${card.hours || ''}" ${ro}></div>
            </div>
            ${sprintOpts ? `<div class="mb-2"><label class="form-label">Sprint</label><select class="form-select" name="sprint" ${ro}>${sprintOpts}</select></div>` : ''}
            <div><label class="form-label">Responsáveis (equipe da tarefa)</label>
                <input class="form-control form-control-sm mb-1" placeholder="Filtrar pessoas…" data-filter>
                <div class="pmo-userpick">${users || '<span class="text-secondary small">Nenhum usuário ativo.</span>'}</div></div>
            <div class="small text-secondary mt-2">${card.key} · ${card.percent}% concluído</div>`;
        const m = modal({
            title: `${typeIcon(card.typeName, card.milestone)} Editar cartão`,
            body,
            buttons: [
                {label: '<i class="ti ti-external-link me-1"></i>Abrir tarefa completa', cls: 'btn-ghost-secondary', href: card.url},
                {label: 'Cancelar', cls: 'btn-ghost-secondary', right: true},
                ...(boot.edit ? [{label: '<i class="ti ti-device-floppy me-1"></i>Salvar', cls: 'btn-primary', onClick: async (mm) => {
                    const f = (n) => mm.el.querySelector(`[name="${n}"]`);
                    const data = {
                        name: f('name').value, type: +f('type').value, priority: +f('priority').value,
                        points: f('points').value === '' ? 0 : +f('points').value,
                        plan_start_date: f('plan_start_date').value, plan_end_date: f('plan_end_date').value,
                        users: [...mm.el.querySelectorAll('.pmo-userpick input:checked')].map((i) => +i.value),
                    };
                    if (f('hours').value !== '') data.hours = +f('hours').value;
                    if (f('sprint')) data.sprint = +f('sprint').value;
                    const res = await api('update', {task: card.id, data}, true);
                    toast('Cartão salvo.');
                    onSaved?.(res.card);
                }}] : []),
            ],
        });
        m.el.querySelector('[data-filter]').addEventListener('input', (e) => {
            const q = e.target.value.toLowerCase();
            m.el.querySelectorAll('.pmo-userpick label').forEach((l) => { l.style.display = l.dataset.n.includes(q) ? '' : 'none'; });
        });
    }

    /** Cores e textos do tema atual para o ECharts. */
    function chartTheme() {
        const cs = getComputedStyle(document.querySelector('.pmo') || document.body);
        const text = cs.getPropertyValue('--pmo-text').trim() || cs.color;
        const muted = cs.getPropertyValue('--pmo-muted').trim() || '#94a3b8';
        const border = cs.getPropertyValue('--pmo-border').trim() || '#334155';
        return {
            text, muted, border,
            base: {
                backgroundColor: 'transparent',
                textStyle: {color: text, fontFamily: 'inherit'},
                grid: {left: 44, right: 16, top: 34, bottom: 30, containLabel: true},
                tooltip: {trigger: 'axis', backgroundColor: cs.getPropertyValue('--pmo-surface').trim() || '#111827', borderColor: border, textStyle: {color: text}},
                legend: {top: 0, textStyle: {color: muted}, icon: 'roundRect', itemWidth: 12, itemHeight: 8},
            },
            axis: {axisLine: {lineStyle: {color: border}}, axisLabel: {color: muted}, splitLine: {lineStyle: {color: border, opacity: .5}}},
        };
    }

    return {boot, esc, api, toast, fail, modal, av, avs, typeIcon, prio, PRIOS, PRIO_COLORS, fmt, fmtShort, sortable, editCard, chartTheme};
})();
