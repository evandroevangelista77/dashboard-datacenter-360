/**
 * PMO — backlog priorizado (arrastar para ordenar) e, no Scrum/Híbrido,
 * planejamento das sprints: criar, iniciar, concluir e mover itens entre elas.
 */
(() => {
    const {boot, esc, api, toast, fail, modal, avs, typeIcon, prio, fmt, sortable, editCard} = PMO;
    const app = document.getElementById('pmo-app');
    let data = null;
    let showClosed = false;

    async function load() {
        try {
            data = await api('backlog');
            render();
        } catch (e) {
            app.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    const col = (id) => data.columns.find((c) => c.id === id) || {name: '?', color: '#64748b', kind: 'todo'};
    const isDone = (c) => col(c.column).kind === 'done';
    const size = (c) => (data.config.estimation === 'hours' ? c.hours : c.points) || 0;
    const sum = (list) => Math.round(list.reduce((a, c) => a + size(c), 0) * 10) / 10;
    const unit = () => (data.config.estimation === 'hours' ? 'h' : 'pts');
    const ctx = () => ({users: data.users, types: data.types, sprints: data.sprints, config: data.config});

    function row(c) {
        const k = col(c.column);
        return `<div class="pmo-row" draggable="${boot.edit}" data-id="${c.id}">
            ${typeIcon(c.typeName, c.milestone)}<span class="key">${c.key}</span>${prio(c.priority)}
            <span class="nm">${c.parent ? `<span class="badge bg-purple-lt me-1">${esc(c.parent.name)}</span>` : ''}${esc(c.name)}</span>
            ${c.end ? `<span class="small ${c.due === 'late' ? 'text-danger' : 'text-secondary'}">${PMO.fmtShort(c.end)}</span>` : ''}
            <span class="badge pmo-badge st" style="--c:${k.color}">${esc(k.name)}</span>
            ${avs(c.users)}
            <span class="pts" title="${data.config.estimation === 'hours' ? 'Horas' : 'Story points'}">${size(c) || '–'}</span>
        </div>`;
    }

    function box({id, title, sub, badges = '', actions = '', items, empty, sprint}) {
        return `<div class="pmo-bl-box" data-box="${id}">
            <div class="pmo-bl-head"><span class="name">${title}</span>${badges}<span class="sub">${sub}</span>
                <span class="pmo-spacer"></span><span class="sub">${items.length} item(ns) · ${sum(items)} ${unit()}</span>${actions}</div>
            <div class="pmo-bl-list" data-sprint="${sprint}">${items.map(row).join('') || `<div class="pmo-bl-empty">${empty}</div>`}</div>
            ${sprint === 0 && boot.edit ? `<div class="p-2 border-top"><div class="input-group input-group-sm"><span class="input-group-text"><i class="ti ti-plus"></i></span>
                <input class="form-control" placeholder="Criar item no backlog (Enter)" data-new></div></div>` : ''}
        </div>`;
    }

    function render() {
        const cards = data.cards;
        let html = `<div class="pmo-toolbar">
            <span class="text-secondary small">Arraste os itens para priorizar${boot.sprints ? ' ou para planejar as sprints' : ''}. Clique para editar pontos, prioridade e responsáveis.</span>
            <span class="pmo-spacer"></span>
            ${boot.sprints && boot.edit ? `<button class="btn btn-sm btn-primary" data-act="create"><i class="ti ti-plus me-1"></i>Criar sprint</button>` : ''}
            <a class="btn btn-sm btn-outline-secondary" href="${boot.urls.board}"><i class="ti ti-layout-kanban me-1"></i>Quadro</a>
        </div>`;

        if (boot.sprints) {
            const open = data.sprints.filter((s) => s.state !== 'closed').sort((a, b) => (a.state === 'active' ? -1 : 0) - (b.state === 'active' ? -1 : 0));
            open.forEach((s) => {
                const items = cards.filter((c) => c.sprint === s.id);
                const active = s.state === 'active';
                let actions = '';
                if (boot.edit) {
                    actions += active
                        ? `<button class="btn btn-sm btn-primary" data-act="close" data-s="${s.id}"><i class="ti ti-flag-check me-1"></i>Concluir sprint</button>`
                        : `<button class="btn btn-sm btn-outline-primary" data-act="start" data-s="${s.id}" ${data.active ? 'disabled title="Já existe uma sprint em andamento"' : ''}><i class="ti ti-player-play me-1"></i>Iniciar sprint</button>`;
                    actions += `<button class="btn btn-sm btn-ghost-secondary" data-act="edit" data-s="${s.id}" title="Editar"><i class="ti ti-pencil"></i></button>`;
                    if (!active) actions += `<button class="btn btn-sm btn-ghost-danger" data-act="delete" data-s="${s.id}" title="Excluir"><i class="ti ti-trash"></i></button>`;
                }
                html += box({
                    id: s.id, sprint: s.id, items, actions,
                    title: `<i class="ti ti-run me-1"></i>${esc(s.name)}`,
                    badges: `<span class="badge ${active ? 'bg-green' : 'bg-secondary-lt'}">${esc(s.stateName)}</span>`,
                    sub: `${fmt(s.start_date)} – ${fmt(s.end_date)}${s.goal ? ` · <i class="ti ti-target"></i> ${esc(s.goal)}` : ''}`,
                    empty: 'Arraste itens do backlog para planejar esta sprint.',
                });
            });
        }
        const backlog = cards.filter((c) => (!boot.sprints || c.sprint === 0 || !data.sprints.some((s) => s.id === c.sprint && s.state !== 'closed')) && !isDone(c));
        html += box({
            id: 0, sprint: 0, items: backlog, title: '<i class="ti ti-stack-2 me-1"></i>Backlog', sub: boot.sprints ? 'itens fora de sprint' : 'itens abertos, em ordem de prioridade',
            empty: 'Backlog vazio.',
        });
        const doneCount = cards.filter((c) => isDone(c) && (!boot.sprints || c.sprint === 0)).length;
        if (doneCount) html += `<div class="small text-secondary mb-3"><i class="ti ti-circle-check me-1"></i>${doneCount} item(ns) concluído(s) fora de sprint não aparecem no backlog.</div>`;

        if (boot.sprints) {
            const closed = data.sprints.filter((s) => s.state === 'closed').reverse();
            if (closed.length) {
                html += `<div class="pmo-bl-box"><div class="pmo-bl-head" style="cursor:pointer" data-act="toggle"><span class="name"><i class="ti ti-chevron-${showClosed ? 'down' : 'right'} me-1"></i>Sprints encerradas (${closed.length})</span></div>`;
                if (showClosed) {
                    html += `<div class="table-responsive"><table class="table table-sm card-table mb-0"><thead><tr><th>Sprint</th><th>Período</th><th>Meta</th><th class="text-end">Comprometido</th><th class="text-end">Entregue</th></tr></thead><tbody>`
                        + closed.map((s) => `<tr><td>${esc(s.name)}</td><td>${fmt(s.start_date)} – ${fmt(s.end_date)}</td><td class="small">${esc(s.goal || '')}</td><td class="text-end">${s.committed}</td><td class="text-end">${s.completed}</td></tr>`).join('')
                        + `</tbody></table></div>`;
                }
                html += `</div>`;
            }
        }
        app.innerHTML = html;
        bind();
    }

    function sprintForm(s = {}) {
        return `<div class="mb-2"><label class="form-label">Nome</label><input class="form-control" name="name" value="${esc(s.name || '')}"></div>
            <div class="row g-2 mb-2"><div class="col"><label class="form-label">Início</label><input type="date" class="form-control" name="start_date" value="${s.start_date || ''}"></div>
            <div class="col"><label class="form-label">Fim</label><input type="date" class="form-control" name="end_date" value="${s.end_date || ''}"></div></div>
            <div><label class="form-label">Meta da sprint</label><textarea class="form-control" rows="2" name="goal">${esc(s.goal || '')}</textarea></div>`;
    }
    const formData = (m) => Object.fromEntries([...m.el.querySelectorAll('[name]')].map((i) => [i.name, i.value]));

    function bind() {
        app.querySelectorAll('.pmo-row').forEach((el) => el.addEventListener('click', () => {
            const card = data.cards.find((c) => c.id === +el.dataset.id);
            editCard(card, ctx(), (u) => { Object.assign(card, u); render(); });
        }));
        app.querySelector('[data-new]')?.addEventListener('keydown', async (e) => {
            if (e.key !== 'Enter' || !e.target.value.trim()) return;
            const first = data.columns.find((c) => c.kind === 'todo') || data.columns[0];
            e.target.disabled = true;
            try {
                const res = await api('create', {name: e.target.value.trim(), column: first.id, sprint: 0}, true);
                data.cards.push(res.card);
                render();
                app.querySelector('[data-new]')?.focus();
            } catch (err) { fail(err); e.target.disabled = false; }
        });
        app.querySelectorAll('[data-act]').forEach((el) => el.addEventListener('click', () => action(el.dataset.act, +el.dataset.s)));
    }

    async function action(act, sid) {
        const s = data.sprints.find((x) => x.id === sid);
        if (act === 'toggle') { showClosed = !showClosed; render(); return; }
        if (act === 'create') {
            try { await api('sprint_create', {data: {}}, true); toast('Sprint criada.'); load(); } catch (e) { fail(e); }
            return;
        }
        if (act === 'edit') {
            modal({title: 'Editar sprint', body: sprintForm(s), buttons: [{label: 'Cancelar', cls: 'btn-ghost-secondary'},
                {label: 'Salvar', cls: 'btn-primary', right: true, onClick: async (m) => { await api('sprint_update', {sprint: sid, data: formData(m)}, true); load(); }}]});
            return;
        }
        if (act === 'delete') {
            if (!confirm(`Excluir a ${s.name}? Os itens voltam para o backlog.`)) return;
            try { await api('sprint_delete', {sprint: sid}, true); load(); } catch (e) { fail(e); }
            return;
        }
        if (act === 'start') {
            const items = data.cards.filter((c) => c.sprint === sid);
            modal({
                title: `<i class="ti ti-player-play me-1"></i>Iniciar ${esc(s.name)}`,
                body: `<p class="small text-secondary">${items.length} item(ns), ${sum(items)} ${unit()} comprometidos.${items.some((c) => !size(c)) ? ' <span class="text-warning">Há itens sem estimativa.</span>' : ''}</p>` + sprintForm(s),
                buttons: [{label: 'Cancelar', cls: 'btn-ghost-secondary'}, {label: 'Iniciar sprint', cls: 'btn-primary', right: true, onClick: async (m) => {
                    await api('sprint_start', {sprint: sid, data: formData(m)}, true);
                    toast('Sprint iniciada. O quadro agora mostra esta sprint.');
                    load();
                }}],
            });
            return;
        }
        if (act === 'close') {
            const items = data.cards.filter((c) => c.sprint === sid);
            const done = items.filter(isDone);
            const pending = items.length - done.length;
            const targets = `<option value="0">Backlog</option><option value="-1">Nova sprint</option>`
                + data.sprints.filter((x) => x.state === 'future').map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join('');
            modal({
                title: `<i class="ti ti-flag-check me-1"></i>Concluir ${esc(s.name)}`,
                body: `<div class="row g-2 mb-3"><div class="col"><div class="pmo-kpi"><div class="pmo-kpi-label">Concluídos</div><div class="pmo-kpi-value">${done.length}</div><div class="pmo-kpi-sub">${sum(done)} ${unit()}</div></div></div>
                    <div class="col"><div class="pmo-kpi"><div class="pmo-kpi-label">Pendentes</div><div class="pmo-kpi-value">${pending}</div><div class="pmo-kpi-sub">${sum(items) - sum(done)} ${unit()}</div></div></div></div>
                    ${pending ? `<label class="form-label">Mover os ${pending} item(ns) pendentes para</label><select class="form-select" name="target">${targets}</select>` : '<p>Todos os itens foram concluídos. 🎉</p>'}
                    <p class="small text-secondary mt-2">Dica: registre a retrospectiva em <i>Documentação → Retrospectiva da Sprint</i>.</p>`,
                buttons: [{label: 'Cancelar', cls: 'btn-ghost-secondary'}, {label: 'Concluir sprint', cls: 'btn-primary', right: true, onClick: async (m) => {
                    const target = +(m.el.querySelector('[name=target]')?.value || 0);
                    const res = await api('sprint_close', {sprint: sid, target}, true);
                    toast(`Sprint concluída: ${res.done} ${unit()} entregues de ${res.total}. ${res.moved} item(ns) movido(s).`);
                    load();
                }}],
            });
        }
    }

    if (boot.edit) {
        sortable(app, '.pmo-bl-list', '.pmo-row', async (el, list) => {
            const sprint = +list.dataset.sprint;
            const id = +el.dataset.id;
            const order = [...list.querySelectorAll('.pmo-row')].map((r) => +r.dataset.id);
            const card = data.cards.find((c) => c.id === id);
            const prev = card.sprint;
            if (boot.sprints) card.sprint = sprint;
            const ranks = order.map((oid) => data.cards.find((c) => c.id === oid).rank).sort((a, b) => a - b);
            order.forEach((oid, i) => { data.cards.find((c) => c.id === oid).rank = ranks[i]; });
            data.cards.sort((a, b) => a.rank - b.rank);
            render();
            try {
                await api('reorder', boot.sprints ? {order, sprint} : {order}, true);
                if (boot.sprints && prev !== sprint) {
                    const s = data.sprints.find((x) => x.id === sprint);
                    toast(`${card.key} movido para ${s ? s.name : 'o backlog'}.`);
                }
            } catch (e) { fail(e); load(); }
        });
    }

    load();
})();
