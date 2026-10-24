/**
 * PMO — Gantt das tarefas do projeto: EAP em árvore (fases recolhíveis), barras
 * com % concluído, marcos, dependências (as mesmas do cronograma importado do
 * MS Project), linha de hoje, zoom dia/semana/mês e arrastar para mudar datas.
 */
(() => {
  const mount = (app) => {
    if (app.dataset.mounted) return;
    app.dataset.mounted = '1';
    const {esc, toast, fail, modal, avs, fmt} = PMO;
    // cada Gantt tem seus dados (a página do projeto e a aba podem ter um cada)
    const boot = app.dataset.boot ? JSON.parse(app.dataset.boot) : PMO.boot;
    const api = (action, params, post) => PMO.api(action, params, post, boot);
    const ROW = 36;
    const ZOOMS = {day: ['Dia', 36], week: ['Semana', 20], month: ['Mês', 6]};
    const LINK_TYPES = ['Término → Início', 'Início → Início', 'Término → Término', 'Início → Término'];
    const KEY = `pmo-gantt-${boot.project}`;
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { /* sem armazenamento local */ }
    const state = {zoom: saved.zoom || 'week', collapsed: new Set(saved.collapsed || []), links: saved.links !== false, gridW: saved.gridW || 0};
    const remember = () => { try { localStorage.setItem(KEY, JSON.stringify({zoom: state.zoom, collapsed: [...state.collapsed], links: state.links, gridW: state.gridW})); } catch (e) { /* ignora */ } };
    let data, byId, kids, range, ppd, rows;

    const parse = (s) => (s ? new Date(s.replace(' ', 'T')) : null);
    const day0 = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const x = (d) => ((d - range.start) / 864e5) * ppd;
    const short = (d) => `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getFullYear()).slice(2)}`;
    const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    const STATUS = {done: 'concluída', prog: 'em andamento', todo: 'não iniciada', late: 'atrasada'};
    const status = (t, now) => (t.done || t.percent >= 100 ? 'done' : t.e < now ? 'late' : t.percent > 0 ? 'prog' : 'todo');

    async function load() {
        try {
            data = await api('gantt');
            prepare();
            render();
        } catch (e) {
            app.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    function prepare() {
        byId = new Map(data.tasks.map((t) => [t.id, t]));
        kids = new Map();
        data.tasks.forEach((t) => {
            t.s = parse(t.start);
            t.e = parse(t.end);
            const p = byId.has(t.parent) ? t.parent : 0;
            if (!kids.has(p)) kids.set(p, []);
            kids.get(p).push(t);
        });
        // fases sem datas próprias: período dos filhos
        const span = (t) => {
            (kids.get(t.id) || []).forEach(span);
            if (t.summary && (!t.s || !t.e)) {
                const ch = (kids.get(t.id) || []).filter((c) => c.s && c.e);
                if (ch.length) {
                    t.s = new Date(Math.min(...ch.map((c) => c.s)));
                    t.e = new Date(Math.max(...ch.map((c) => c.e)));
                    t.derived = true;
                }
            }
        };
        (kids.get(0) || []).forEach(span);
        const sortKids = (list) => list.sort((a, b) => (a.s ? a.s - (b.s || Infinity) : 1) || a.id - b.id);
        kids.forEach(sortKids);
        // numeração da EAP (1, 1.1, 1.2 …)
        const wbs = (list, prefix) => list.forEach((t, i) => { t.wbs = prefix + (i + 1); wbs(kids.get(t.id) || [], t.wbs + '.'); });
        wbs(kids.get(0) || [], '');

        const dated = data.tasks.filter((t) => t.s && t.e);
        const today = day0(new Date());
        let min = dated.length ? new Date(Math.min(...dated.map((t) => t.s))) : today;
        let max = dated.length ? new Date(Math.max(...dated.map((t) => t.e))) : addDays(today, 30);
        if (today < min) min = today;
        if (today > max) max = today;
        range = {start: addDays(day0(min), -7), end: addDays(day0(max), 21)};
    }

    function visibleRows() {
        const out = [];
        const walk = (list, depth) => list.forEach((t) => {
            out.push({t, depth});
            if (!state.collapsed.has(t.id)) walk(kids.get(t.id) || [], depth + 1);
        });
        walk(kids.get(0) || [], 0);
        return out;
    }

    function header(width) {
        let top = '', bottom = '', bg = '';
        const d0 = range.start;
        const days = Math.round((range.end - d0) / 864e5);
        for (let i = 0; i <= days; i++) {
            const d = addDays(d0, i);
            const left = x(d);
            if (d.getDate() === 1 || i === 0) {
                const label = state.zoom === 'month' ? (d.getMonth() === 0 || i === 0 ? d.getFullYear() : '') : `${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
                if (state.zoom !== 'month' || label) top += `<div class="pmo-g-scale top" style="left:${left}px">${label}</div>`;
                if (state.zoom !== 'month' && i > 0) bg += `<div class="pmo-g-bg mo" style="left:${left}px"></div>`;
            }
            if (state.zoom === 'day') {
                bottom += `<div class="pmo-g-scale" style="left:${left}px;top:26px;width:${ppd}px;text-align:center;padding:0">${d.getDate()}</div>`;
                if (d.getDay() === 0 || d.getDay() === 6) bg += `<div class="pmo-g-bg we" style="left:${left}px;width:${ppd}px"></div>`;
            } else if (state.zoom === 'week' && (d.getDay() === 0 || d.getDay() === 6)) {
                bg += `<div class="pmo-g-bg we" style="left:${left}px;width:${ppd}px"></div>`;
            }
            if (state.zoom === 'week' && d.getDay() === 1) {
                bottom += `<div class="pmo-g-scale" style="left:${left}px;top:26px">${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}</div>`;
                bg += `<div class="pmo-g-bg ln" style="left:${left}px"></div>`;
            } else if (state.zoom === 'month' && d.getDate() === 1) {
                bottom += `<div class="pmo-g-scale" style="left:${left}px;top:26px">${MONTHS[d.getMonth()]}</div>`;
                bg += `<div class="pmo-g-bg ln" style="left:${left}px"></div>`;
            }
        }
        return {head: `<div class="pmo-g-head" style="width:${width}px">${top}${bottom}</div>`, bg};
    }

    function render() {
        ppd = ZOOMS[state.zoom][1];
        rows = visibleRows();
        const width = Math.max(600, x(range.end));
        const height = rows.length * ROW;
        const now = new Date();
        const {head, bg} = header(width);

        let grid = '', bars = '', stripes = '';
        rows.forEach(({t, depth}, i) => {
            const y = i * ROW;
            const tg = t.summary ? `<i class="ti ti-chevron-${state.collapsed.has(t.id) ? 'right' : 'down'} tg" data-toggle="${t.id}"></i>` : '<span class="tg"></span>';
            const icon = t.milestone ? '<i class="ti ti-diamond text-warning"></i>' : t.summary ? '<i class="ti ti-folder text-secondary"></i>' : '';
            grid += `<div class="pmo-g-r ${t.summary ? 'sum' : ''}" data-row="${t.id}"><div class="pmo-g-c-name" style="padding-left:${6 + depth * 16}px" data-open="${t.id}" title="${esc(t.name)}">`
                + `${tg}<span class="text-secondary small me-1">${t.wbs}</span>${icon}<span class="text-truncate">${esc(t.name)}</span></div>`
                + `<div class="pmo-g-c-d">${t.s ? short(t.s) : ''}</div><div class="pmo-g-c-d">${t.e ? short(t.e) : ''}</div><div class="pmo-g-c-p">${t.percent}%</div></div>`;
            stripes += `<div class="pmo-g-stripe ${i % 2 ? 'odd' : ''}" data-srow="${t.id}" style="top:${y}px;height:${ROW}px"></div>`;
            if (!t.s || !t.e) return;
            const left = x(t.s);
            const w = Math.max(t.summary ? 6 : 10, x(t.e) - left);
            const st = status(t, now);
            const tip = `${t.wbs} ${t.name}\n${fmt(iso(t.s))} – ${fmt(iso(t.e))} · ${t.percent}% · ${STATUS[st]}${t.hours ? ` · ${t.hours} h` : ''}${t.users.length ? '\n' + t.users.map((u) => u.name).join(', ') : ''}`;
            const label = t.name.length > 46 ? t.name.slice(0, 44) + '…' : t.name;
            if (t.milestone) {
                bars += `<div class="pmo-g-bar ms ${st}" data-bar="${t.id}" style="left:${left - 8}px;top:${y + 10}px" data-tip="${esc(tip)}"></div>`
                    + `<div class="pmo-g-label ms" style="left:${left + 14}px;top:${y}px;line-height:${ROW}px"><b>${esc(label)}</b> <span>${fmt(iso(t.s))}</span></div>`;
                return;
            }
            if (t.summary) {
                bars += `<div class="pmo-g-bar sum d${Math.min(depth, 3)}" data-bar="${t.id}" style="left:${left}px;top:${y + 13}px;width:${w}px" data-tip="${esc(tip)}"><div class="pg" style="width:${t.percent}%"></div></div>`
                    + `<div class="pmo-g-label sum" style="left:${left + w + 10}px;top:${y}px;line-height:${ROW}px"><b>${esc(label)}</b> <span class="pc">${t.percent}%</span></div>`;
                return;
            }
            const inside = w >= 44 ? `<span class="in">${t.percent}%</span>` : '';
            bars += `<div class="pmo-g-bar ${st}" data-bar="${t.id}" style="left:${left}px;top:${y + 8}px;width:${w}px" data-tip="${esc(tip)}">`
                + `<div class="pg" style="width:${t.percent}%"></div>${inside}${boot.edit ? '<div class="rs"></div>' : ''}</div>`
                + `<div class="pmo-g-label" style="left:${left + w + 14}px;top:${y}px;line-height:${ROW}px">${esc(label)}`
                + `${inside ? '' : ` <span class="pc">${t.percent}%</span>`}${st === 'late' ? ' <span class="lt">atrasada</span>' : ''}`
                + `${t.users.length ? ` <span class="us">${t.users.map((u) => esc(u.initials)).join(' ')}</span>` : ''}</div>`;
        });

        // dependências
        const pos = new Map(rows.map(({t}, i) => [t.id, i]));
        let paths = '';
        data.links.forEach((l) => {
            const a = byId.get(l.source), b = byId.get(l.target);
            if (!a || !b || !pos.has(a.id) || !pos.has(b.id) || !a.s || !b.s) return;
            const fromEnd = l.type === 0 || l.type === 2, toEnd = l.type === 2 || l.type === 3;
            const x1 = x(fromEnd ? a.e : a.s), y1 = pos.get(a.id) * ROW + ROW / 2;
            const x2 = x(toEnd ? b.e : b.s), y2 = pos.get(b.id) * ROW + ROW / 2;
            const out = fromEnd ? 6 : -6;
            const inn = toEnd ? 8 : -8;
            let d;
            if ((!toEnd && x2 - 14 >= x1 + out) || (toEnd && x2 + 14 <= x1 + out)) {
                d = `M${x1},${y1} H${x1 + out} V${y2} H${x2}`;
            } else {
                const ym = y1 + (y2 > y1 ? ROW / 2 : -ROW / 2);
                d = `M${x1},${y1} H${x1 + out} V${ym} H${x2 + inn} V${y2} H${x2}`;
            }
            paths += `<path d="${d}" data-src="${a.id}" data-dst="${b.id}" marker-end="url(#pmo-arrow)"/>`;
        });
        const todayX = x(now);

        app.innerHTML = `<div class="pmo-toolbar">
                <div class="btn-group">${Object.entries(ZOOMS).map(([k, [l]]) => `<button type="button" class="btn btn-sm ${k === state.zoom ? 'btn-primary' : 'btn-outline-secondary'}" data-zoom="${k}">${l}</button>`).join('')}</div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-today><i class="ti ti-calendar-pin me-1"></i>Hoje</button>
                <button type="button" class="btn btn-sm btn-ghost-secondary" data-expand><i class="ti ti-arrows-vertical me-1"></i>Expandir</button>
                <button type="button" class="btn btn-sm btn-ghost-secondary" data-collapse><i class="ti ti-fold me-1"></i>Recolher fases</button>
                <button type="button" class="btn btn-sm ${state.links ? 'btn-outline-primary' : 'btn-ghost-secondary'}" data-links><i class="ti ti-arrows-right me-1"></i>Dependências</button>
                <span class="pmo-spacer"></span>
                <span class="pmo-g-legend"><i class="done"></i>Concluída<i class="prog"></i>Em andamento<i class="todo"></i>Não iniciada<i class="late"></i>Atrasada<i class="ms"></i>Marco</span>
                ${boot.edit ? `<span class="small text-secondary ms-2" title="Arraste a barra para mover; a borda direita muda o fim. Duplo clique abre os detalhes."><i class="ti ti-info-circle"></i></span>
                <a class="btn btn-sm btn-outline-primary" href="${boot.urls.newtask}"><i class="ti ti-plus me-1"></i>Nova tarefa</a>` : ''}
            </div>
            ${data.tasks.length ? '' : '<div class="alert alert-info">O projeto não tem tarefas. Crie tarefas no GLPI ou importe o cronograma do MS Project (aba “Cronograma (MS Project)” do projeto).</div>'}
            <div class="pmo-gantt">
                <div class="pmo-g-grid" ${state.gridW ? `style="width:${state.gridW}px"` : ''}><div class="pmo-g-head"><div class="pmo-g-c-name" style="padding-left:28px">Tarefa</div><div class="pmo-g-c-d">Início</div><div class="pmo-g-c-d">Fim</div><div class="pmo-g-c-p">%</div></div>
                    <div class="pmo-g-rows"><div style="height:${height}px">${grid}</div></div></div>
                <div class="pmo-g-split" title="Arraste para alargar a lista de tarefas · duplo clique ajusta ao nome mais longo"><span></span></div>
                <div class="pmo-g-time">${head}
                    <div class="pmo-g-canvas" style="width:${width}px;height:${Math.max(height, 200)}px">${stripes}${bg}
                        <div class="pmo-g-today" style="left:${todayX}px"><span>hoje</span></div>
                        <svg class="pmo-g-links ${state.links ? '' : 'd-none'}" width="${width}" height="${height}"><defs><marker id="pmo-arrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><path d="M0,0 L8,4 L0,8 z" style="fill:#8b98ab;stroke:none"/></marker></defs>${paths}</svg>
                        ${bars}</div></div>
            </div>`;
        bind();
    }

    let scrollLeft = null;
    function bind() {
        const time = app.querySelector('.pmo-g-time');
        const gridRows = app.querySelector('.pmo-g-rows');
        time.addEventListener('scroll', () => { gridRows.scrollTop = time.scrollTop; scrollLeft = time.scrollLeft; });
        gridRows.addEventListener('wheel', (e) => { time.scrollTop += e.deltaY; e.preventDefault(); }, {passive: false});
        // realce das dependências da tarefa sob o mouse
        const hl = (id, on) => {
            app.querySelectorAll(`.pmo-g-links path[data-src="${id}"], .pmo-g-links path[data-dst="${id}"]`).forEach((p) => p.classList.toggle('on', on));
            app.querySelector(`[data-srow="${id}"]`)?.classList.toggle('hl', on);
        };
        app.querySelectorAll('[data-row]').forEach((r) => {
            r.addEventListener('mouseenter', () => hl(r.dataset.row, true));
            r.addEventListener('mouseleave', () => hl(r.dataset.row, false));
        });
        app.querySelectorAll('[data-bar]').forEach((b) => {
            b.addEventListener('mouseenter', () => hl(b.dataset.bar, true));
            b.addEventListener('mouseleave', () => hl(b.dataset.bar, false));
        });
        splitter();
        app.querySelector('[data-links]').onclick = () => { state.links = !state.links; remember(); render(); };
        if (scrollLeft === null) scrollLeft = Math.max(0, x(new Date()) - 200);
        time.scrollLeft = scrollLeft;

        app.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => {
            const center = (time.scrollLeft + time.clientWidth / 2) / ppd;
            state.zoom = b.dataset.zoom; remember();
            scrollLeft = Math.max(0, center * ZOOMS[state.zoom][1] - time.clientWidth / 2);
            render();
        }));
        app.querySelector('[data-today]').onclick = () => { time.scrollLeft = Math.max(0, x(new Date()) - time.clientWidth / 3); };
        app.querySelector('[data-expand]').onclick = () => { state.collapsed.clear(); remember(); render(); };
        app.querySelector('[data-collapse]').onclick = () => { data.tasks.filter((t) => t.summary).forEach((t) => state.collapsed.add(t.id)); remember(); render(); };
        app.querySelectorAll('[data-toggle]').forEach((el) => el.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = +el.dataset.toggle;
            state.collapsed.has(id) ? state.collapsed.delete(id) : state.collapsed.add(id);
            remember(); render();
        }));
        app.querySelectorAll('[data-open]').forEach((el) => el.addEventListener('click', () => details(byId.get(+el.dataset.open))));

        // realce da linha e dica
        let tip;
        app.querySelectorAll('[data-bar]').forEach((el) => {
            el.addEventListener('mouseenter', () => {
                app.querySelector(`[data-row="${el.dataset.bar}"]`)?.classList.add('hl');
                tip = document.body.appendChild(Object.assign(document.createElement('div'), {className: 'pmo-g-tip'}));
                tip.innerText = el.dataset.tip;
            });
            el.addEventListener('mousemove', (e) => { if (tip) { tip.style.left = e.clientX + 14 + 'px'; tip.style.top = e.clientY + 14 + 'px'; } });
            el.addEventListener('mouseleave', () => { app.querySelector(`[data-row="${el.dataset.bar}"]`)?.classList.remove('hl'); tip?.remove(); tip = null; });
            el.addEventListener('dblclick', () => details(byId.get(+el.dataset.bar)));
            if (boot.edit && !el.classList.contains('sum')) el.addEventListener('mousedown', (e) => drag(e, el));
        });
    }

    /** Divisória entre a lista e o gráfico: arrastar muda a largura da lista. */
    function splitter() {
        const split = app.querySelector('.pmo-g-split');
        const grid = app.querySelector('.pmo-g-grid');
        const box = app.querySelector('.pmo-gantt');
        const clamp = (w) => Math.round(Math.max(220, Math.min(box.clientWidth - 160, w)));
        split.addEventListener('mousedown', (e) => {
            if (e.button !== 0) return;
            e.preventDefault();
            const x0 = e.clientX, w0 = grid.offsetWidth;
            split.classList.add('on');
            document.body.style.cursor = 'col-resize';
            const move = (ev) => { grid.style.width = clamp(w0 + ev.clientX - x0) + 'px'; };
            const up = () => {
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
                split.classList.remove('on');
                document.body.style.cursor = '';
                state.gridW = grid.offsetWidth;
                remember();
            };
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
        // duplo clique: largura que mostra o nome mais longo inteiro
        split.addEventListener('dblclick', () => {
            let need = 0;
            grid.querySelectorAll('.pmo-g-c-name').forEach((c) => {
                const txt = c.querySelector('.text-truncate');
                if (!txt) return;
                const extra = c.scrollWidth - c.clientWidth;
                need = Math.max(need, txt.scrollWidth - txt.clientWidth + Math.max(0, extra));
            });
            grid.style.width = clamp(grid.offsetWidth + need + 12) + 'px';
            state.gridW = grid.offsetWidth;
            remember();
        });
    }

    function drag(e, el) {
        if (e.button !== 0) return;
        e.preventDefault();
        const t = byId.get(+el.dataset.bar);
        const resize = e.target.classList.contains('rs');
        const x0 = e.clientX;
        const left0 = parseFloat(el.style.left), w0 = parseFloat(el.style.width);
        let days = 0;
        const move = (ev) => {
            days = Math.round((ev.clientX - x0) / ppd);
            if (resize) el.style.width = Math.max(4, w0 + days * ppd) + 'px';
            else el.style.left = left0 + days * ppd + 'px';
        };
        const up = async () => {
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
            if (!days) return;
            try {
                const res = await api('shift', {task: t.id, start: resize ? 0 : days, end: days}, true);
                t.start = res.start; t.end = res.end;
                toast(`${t.name}: ${fmt(res.start)} – ${fmt(res.end)}`);
                prepare(); render();
            } catch (err) { fail(err); render(); }
        };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    }

    /** Detalhes da tarefa e edição das dependências (predecessoras). */
    function details(t) {
        const preds = data.links.filter((l) => l.target === t.id);
        const succs = data.links.filter((l) => l.source === t.id);
        const name = (id) => { const o = byId.get(id); return o ? `${o.wbs} ${esc(o.name)}` : `#${id}`; };
        const list = (arr, other) => arr.length ? arr.map((l) => `<li class="d-flex align-items-center gap-2">${name(l[other])} <span class="badge bg-secondary-lt">${LINK_TYPES[l.type]}${l.lag ? ` ${l.lag > 0 ? '+' : ''}${l.lag}` : ''}</span>`
            + (boot.edit ? `<button type="button" class="btn btn-sm btn-ghost-danger ms-auto py-0" data-unlink="${l.id}" title="Remover"><i class="ti ti-x"></i></button>` : '') + `</li>`).join('') : '<li class="text-secondary">nenhuma</li>';
        const options = data.tasks.filter((o) => o.id !== t.id).map((o) => `<option value="${o.id}">${o.wbs} ${esc(o.name)}</option>`).join('');
        const m = modal({
            title: `${t.milestone ? '<i class="ti ti-diamond text-warning"></i>' : ''} ${t.wbs} ${esc(t.name)}`,
            body: `<div class="row g-2 mb-3">
                    <div class="col-6"><div class="small text-secondary">Planejado</div>${t.s ? `${fmt(iso(t.s))} – ${fmt(iso(t.e))}${t.derived ? ' <span class="small text-secondary">(pelas subtarefas)</span>' : ''}` : 'sem datas'}</div>
                    <div class="col-6"><div class="small text-secondary">Real</div>${t.rstart ? fmt(t.rstart) : '—'} – ${t.rend ? fmt(t.rend) : '—'}</div>
                    <div class="col-6"><div class="small text-secondary">Concluído</div>${t.percent}%${t.hours ? ` · ${t.hours} h planejadas` : ''}</div>
                    <div class="col-6"><div class="small text-secondary">Equipe</div>${avs(t.users, 6) || '—'}</div></div>
                <h4 class="mb-1">Predecessoras</h4><ul class="list-unstyled mb-3">${list(preds, 'source')}</ul>
                <h4 class="mb-1">Sucessoras</h4><ul class="list-unstyled mb-3">${list(succs, 'target')}</ul>
                ${boot.edit ? `<h4 class="mb-1">Adicionar predecessora</h4><div class="row g-2"><div class="col-12"><select class="form-select form-select-sm" name="pred">${options}</select></div>
                    <div class="col-8"><select class="form-select form-select-sm" name="ltype">${LINK_TYPES.map((l, i) => `<option value="${i}">${l}</option>`).join('')}</select></div>
                    <div class="col-4"><button type="button" class="btn btn-sm btn-primary w-100" data-link><i class="ti ti-link me-1"></i>Ligar</button></div></div>` : ''}`,
            buttons: [{label: '<i class="ti ti-external-link me-1"></i>Abrir tarefa no GLPI', cls: 'btn-outline-primary', href: t.url}, {label: 'Fechar', cls: 'btn-ghost-secondary', right: true}],
        });
        m.el.querySelector('[data-link]')?.addEventListener('click', async () => {
            try {
                await api('link_add', {source: +m.el.querySelector('[name=pred]').value, target: t.id, type: +m.el.querySelector('[name=ltype]').value}, true);
                toast('Dependência criada.');
                m.close(); await load(); details(byId.get(t.id));
            } catch (e) { fail(e); }
        });
        m.el.querySelectorAll('[data-unlink]').forEach((b) => b.addEventListener('click', async () => {
            try {
                await api('link_delete', {link: +b.dataset.unlink}, true);
                m.close(); await load(); details(byId.get(t.id));
            } catch (e) { fail(e); }
        }));
    }

    load();
  };
  // o script também roda quando a aba do projeto chega por AJAX
  document.querySelectorAll('.pmo-gantt-app').forEach(mount);
})();
