/**
 * PMO — quadro Kanban (estilo Jira): colunas configuráveis com WIP, arrastar e
 * soltar, filtros, raias por épico/responsável e criação rápida.
 * No Scrum/Híbrido mostra só a sprint em andamento.
 */
(() => {
    const {boot, esc, api, toast, fail, avs, av, typeIcon, prio, PRIO_COLORS, fmtShort, sortable, editCard} = PMO;
    const app = document.getElementById('pmo-app');
    const KEY = `pmo-board-${boot.project}`;
    let data = null;
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { /* sem armazenamento local */ }
    const state = {q: '', users: new Set(saved.users || []), mine: !!saved.mine, type: saved.type || 0, group: saved.group || 'none'};
    const remember = () => {
        try { localStorage.setItem(KEY, JSON.stringify({users: [...state.users], mine: state.mine, type: state.type, group: state.group})); } catch (e) { /* ignora */ }
    };

    async function load() {
        try {
            data = await api('board');
            render();
        } catch (e) {
            app.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    /** Cartões que entram no quadro (no Scrum, só os da sprint ativa). */
    const scope = () => {
        if (!boot.sprints) return data.cards;
        return data.active ? data.cards.filter((c) => c.sprint === data.active.id) : [];
    };

    const visible = (c) => {
        if (state.q && !(`${c.name} ${c.key} ${c.parent?.name || ''}`.toLowerCase().includes(state.q))) return false;
        if (state.mine && !c.users.some((u) => u.id === boot.me)) return false;
        if (state.users.size && !c.users.some((u) => state.users.has(u.id))) return false;
        if (state.type && c.type !== state.type) return false;
        return true;
    };

    function cardHtml(c) {
        const due = c.end ? `<span class="due ${c.due}" title="Fim planejado"><i class="ti ti-calendar-event"></i> ${fmtShort(c.end)}</span>` : '';
        const size = data.config.estimation === 'hours' ? (c.hours ? `${c.hours}h` : '') : (c.points ? c.points : '');
        const bar = c.percent > 0 && c.percent < 100 ? `<div class="bar"><i style="width:${c.percent}%"></i></div>` : '';
        return `<div class="pmo-card" draggable="${boot.edit}" data-id="${c.id}" style="--pc:${PRIO_COLORS[c.priority]}">`
            + (c.parent && state.group !== 'epic' ? `<div class="epic" title="${esc(c.parent.name)}">${esc(c.parent.name)}</div>` : '')
            + `<div class="ttl">${esc(c.name)}</div>`
            + `<div class="meta">${typeIcon(c.typeName, c.milestone)}<span>${c.key}</span>${prio(c.priority)}${due}`
            + `<span class="ms-auto d-inline-flex align-items-center gap-1">${size !== '' ? `<span class="pts" title="${data.config.estimation === 'hours' ? 'Horas' : 'Story points'}">${size}</span>` : ''}${avs(c.users)}</span></div>`
            + bar + `</div>`;
    }

    function lanes(cards) {
        if (state.group === 'epic') {
            const map = new Map();
            cards.forEach((c) => {
                const k = c.parent ? c.parent.id : 0;
                if (!map.has(k)) map.set(k, {key: k, title: c.parent ? c.parent.name : 'Sem fase / épico', cards: []});
                map.get(k).cards.push(c);
            });
            return [...map.values()].sort((a, b) => (a.key === 0) - (b.key === 0));
        }
        if (state.group === 'user') {
            const map = new Map();
            cards.forEach((c) => {
                const u = c.users[0];
                const k = u ? u.id : 0;
                if (!map.has(k)) map.set(k, {key: k, title: u ? u.name : 'Sem responsável', user: u, cards: []});
                map.get(k).cards.push(c);
            });
            return [...map.values()].sort((a, b) => (a.key === 0) - (b.key === 0) || a.title.localeCompare(b.title));
        }
        return [{key: 'all', title: '', cards}];
    }

    function render() {
        const all = scope();
        const cards = all.filter(visible);
        const people = new Map();
        all.forEach((c) => c.users.forEach((u) => people.set(u.id, u)));
        const typeOpts = `<option value="0">Todos os tipos</option>` + data.types.map((t) => `<option value="${t.id}" ${t.id === state.type ? 'selected' : ''}>${esc(t.name)}</option>`).join('');

        let html = `<div class="pmo-toolbar">
            <div class="input-icon"><span class="input-icon-addon"><i class="ti ti-search"></i></span>
                <input class="form-control form-control-sm" placeholder="Buscar no quadro" value="${esc(state.q)}" data-q style="width:220px"></div>
            <span class="d-inline-flex gap-1">${[...people.values()].map((u) => `<span class="pmo-filter-av ${state.users.has(u.id) ? 'on' : ''}" data-user="${u.id}">${av(u)}</span>`).join('')}</span>
            <label class="form-check form-check-inline mb-0 small"><input class="form-check-input" type="checkbox" data-mine ${state.mine ? 'checked' : ''}><span class="form-check-label">Só os meus</span></label>
            <select class="form-select form-select-sm" data-type>${typeOpts}</select>
            <select class="form-select form-select-sm" data-group>
                <option value="none">Sem raias</option>
                <option value="epic" ${state.group === 'epic' ? 'selected' : ''}>Raias por fase / épico</option>
                <option value="user" ${state.group === 'user' ? 'selected' : ''}>Raias por responsável</option>
            </select>
            <span class="pmo-spacer"></span>
            <span class="small text-secondary">${cards.length} de ${all.length} cartões</span>
            <button class="btn btn-sm btn-ghost-secondary" data-reload title="Recarregar"><i class="ti ti-refresh"></i></button>
            ${boot.edit ? `<a class="btn btn-sm btn-outline-primary" href="${boot.urls.newtask}"><i class="ti ti-plus me-1"></i>Tarefa completa</a>` : ''}
        </div>`;

        if (boot.sprints) {
            const s = data.active;
            if (!s) {
                app.innerHTML = html + `<div class="card"><div class="card-body"><h3 class="card-title"><i class="ti ti-run me-2"></i>Nenhuma sprint em andamento</h3>
                    <p class="text-secondary">O quadro do Scrum mostra a sprint ativa. Planeje a sprint no backlog (arraste os itens para ela) e clique em <b>Iniciar sprint</b>.</p>
                    <a class="btn btn-primary" href="${boot.urls.backlog}"><i class="ti ti-list-numbers me-1"></i>Ir para o backlog</a></div></div>`;
                bind();
                return;
            }
            const done = all.filter((c) => colKind(c.column) === 'done');
            const size = (list) => list.reduce((a, c) => a + (data.config.estimation === 'hours' ? c.hours : c.points), 0);
            const left = Math.ceil((new Date(s.end_date + 'T23:59:59') - new Date()) / 864e5);
            html += `<div class="pmo-sprintbar"><i class="ti ti-run text-primary"></i><b>${esc(s.name)}</b>
                <span class="text-secondary small">${PMO.fmt(s.start_date)} – ${PMO.fmt(s.end_date)}</span>
                <span class="badge ${left < 0 ? 'bg-danger' : 'bg-primary-lt'}">${left < 0 ? `${-left} dia(s) além do fim` : `${left} dia(s) restantes`}</span>
                ${s.goal ? `<span class="small"><i class="ti ti-target me-1"></i>${esc(s.goal)}</span>` : ''}
                <span class="pmo-spacer"></span>
                <span class="small">${done.length}/${all.length} cartões · ${size(done)}/${size(all)} ${data.config.estimation === 'hours' ? 'h' : 'pts'}</span>
                <a class="btn btn-sm btn-outline-secondary" href="${boot.urls.backlog}">Concluir sprint…</a></div>`;
        }

        const counts = {};
        all.forEach((c) => { counts[c.column] = (counts[c.column] || 0) + 1; });
        const grouped = lanes(cards);
        grouped.forEach((lane) => {
            if (lane.title) html += `<div class="pmo-lane"><div class="pmo-lane-title">${lane.user ? av(lane.user) : '<i class="ti ti-folder"></i>'} ${esc(lane.title)} <span class="badge bg-secondary-lt">${lane.cards.length}</span></div>`;
            html += `<div class="pmo-board">`;
            data.columns.forEach((col) => {
                const n = counts[col.id] || 0;
                const over = col.wip_limit && n > col.wip_limit;
                const list = lane.cards.filter((c) => c.column === col.id);
                html += `<div class="pmo-col ${over ? 'over' : ''}" data-col="${col.id}">
                    <div class="pmo-col-head" style="--c:${col.color}"><span class="dot"></span>${esc(col.name)}
                        <span class="cnt" title="${col.wip_limit ? 'Cartões / limite WIP' : 'Cartões'}">${n}${col.wip_limit ? ' / ' + col.wip_limit : ''}</span></div>
                    <div class="pmo-col-body" data-col="${col.id}" data-lane="${lane.key}">${list.map(cardHtml).join('')}</div>
                    ${boot.edit && lane === grouped[0] ? `<div class="pmo-col-foot"><button class="btn btn-sm btn-ghost-secondary" data-add="${col.id}"><i class="ti ti-plus me-1"></i>Criar</button></div>` : ''}
                </div>`;
            });
            html += `</div>` + (lane.title ? `</div>` : '');
        });
        if (!all.length) {
            html += `<div class="text-secondary small mt-2">${boot.sprints ? 'A sprint não tem itens. Arraste itens do backlog para ela.' : 'O projeto ainda não tem tarefas. Use “Criar” numa coluna.'}</div>`;
        }
        app.innerHTML = html;
        bind();
    }

    const colKind = (id) => data.columns.find((c) => c.id === id)?.kind;
    const ctx = () => ({users: data.users, types: data.types, sprints: data.sprints, config: data.config});

    function bind() {
        const q = app.querySelector('[data-q]');
        q?.addEventListener('input', () => {
            state.q = q.value.toLowerCase();
            const pos = q.selectionStart;
            render();
            const nq = app.querySelector('[data-q]');
            nq.focus();
            nq.setSelectionRange(pos, pos);
        });
        app.querySelectorAll('[data-user]').forEach((el) => el.addEventListener('click', () => {
            const id = +el.dataset.user;
            state.users.has(id) ? state.users.delete(id) : state.users.add(id);
            remember(); render();
        }));
        app.querySelector('[data-mine]')?.addEventListener('change', (e) => { state.mine = e.target.checked; remember(); render(); });
        app.querySelector('[data-type]')?.addEventListener('change', (e) => { state.type = +e.target.value; remember(); render(); });
        app.querySelector('[data-group]')?.addEventListener('change', (e) => { state.group = e.target.value; remember(); render(); });
        app.querySelector('[data-reload]')?.addEventListener('click', load);
        app.querySelectorAll('.pmo-card').forEach((el) => el.addEventListener('click', () => {
            const card = data.cards.find((c) => c.id === +el.dataset.id);
            editCard(card, ctx(), (updated) => {
                Object.assign(card, updated);
                render();
            });
        }));
        app.querySelectorAll('[data-add]').forEach((btn) => btn.addEventListener('click', () => quickAdd(btn)));
    }

    function quickAdd(btn) {
        const foot = btn.parentNode;
        const column = +btn.dataset.add;
        foot.innerHTML = `<div class="pmo-quick"><textarea class="form-control form-control-sm" rows="2" placeholder="O que precisa ser feito? (Enter cria)"></textarea>
            <div class="d-flex gap-1"><button class="btn btn-sm btn-primary">Criar</button><button class="btn btn-sm btn-ghost-secondary">Cancelar</button></div></div>`;
        const ta = foot.querySelector('textarea');
        const [ok, cancel] = foot.querySelectorAll('button');
        ta.focus();
        const submit = async () => {
            const name = ta.value.trim();
            if (!name) return;
            ok.disabled = true;
            try {
                const res = await api('create', {name, column, sprint: data.active?.id || 0, type: state.type || 0}, true);
                data.cards.push(res.card);
                render();
                app.querySelector(`[data-add="${column}"]`)?.click();
            } catch (e) { fail(e); ok.disabled = false; }
        };
        ok.onclick = submit;
        cancel.onclick = render;
        ta.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
            if (e.key === 'Escape') render();
        });
    }

    if (boot.edit) {
        sortable(app, '.pmo-col-body', '.pmo-card', async (el, list) => {
            const id = +el.dataset.id;
            const column = +list.dataset.col;
            const card = data.cards.find((c) => c.id === id);
            const order = [...list.querySelectorAll('.pmo-card')].map((c) => +c.dataset.id);
            const before = card.column;
            card.column = column;
            // mantém a ordem local igual à da tela
            const ranks = order.map((oid) => data.cards.find((c) => c.id === oid).rank).sort((a, b) => a - b);
            order.forEach((oid, i) => { data.cards.find((c) => c.id === oid).rank = ranks[i]; });
            data.cards.sort((a, b) => a.rank - b.rank);
            render();
            try {
                const res = await api('move', {task: id, column, order}, true);
                card.percent = res.percent;
                if (res.warning) toast(res.warning, 'warn');
                if (before !== column) render();
            } catch (e) {
                fail(e);
                load();
            }
        });
    }

    load();
})();
