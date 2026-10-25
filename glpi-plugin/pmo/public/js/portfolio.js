/**
 * PMO — portfólio: semáforo de saúde, progresso real × planejado, prazos, riscos
 * e os campos do bloco "Dados do projeto" (plugin Fields), com filtros e CSV.
 */
(() => {
    const {boot, esc, api, fmt, chartTheme} = PMO;
    const app = document.getElementById('pmo-app');
    const HEALTH = {red: ['Atrasado', 0], yellow: ['Atenção', 1], green: ['No prazo', 2], done: ['Concluído', 3]};
    const HEALTH_COLORS = {red: '#ef4444', yellow: '#f59e0b', green: '#22c55e', done: '#64748b'};
    const state = {q: '', health: '', stateName: '', client: '', closed: false, sort: 'health', dir: 1};
    let rows = [];
    const charts = [];

    async function load() {
        try {
            rows = (await api('portfolio', {closed: state.closed ? 1 : 0})).rows;
            render();
        } catch (e) {
            app.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    const field = (r, label) => r.fields[label] || '';
    const filtered = () => rows.filter((r) => {
        if (state.q && !`${r.name} ${r.code} ${r.manager} ${Object.values(r.fields).join(' ')}`.toLowerCase().includes(state.q)) return false;
        if (state.health && r.health.code !== state.health) return false;
        if (state.stateName && (r.state?.name || '') !== state.stateName) return false;
        if (state.client && field(r, 'Cliente') !== state.client) return false;
        return true;
    });
    const sorters = {
        health: (r) => HEALTH[r.health.code][1] * 1000 - r.overdue,
        name: (r) => r.name.toLowerCase(),
        client: (r) => field(r, 'Cliente').toLowerCase(),
        state: (r) => r.state?.name || '',
        actual: (r) => r.actual,
        end: (r) => r.end || '9999',
        overdue: (r) => -r.overdue,
        risks: (r) => -r.risks,
    };

    const progress = (r) => `<div class="pmo-progress" title="Real ${r.actual}%${r.planned !== null ? ` · planejado ${r.planned}%` : ''}"><div class="trk"><i style="width:${Math.min(100, r.actual)}%"></i>${r.planned !== null ? `<b style="left:${Math.min(100, r.planned)}%"></b>` : ''}</div><span>${Math.round(r.actual)}%</span></div>`;

    function render() {
        charts.splice(0).forEach((c) => c.dispose());
        const list = filtered();
        const key = sorters[state.sort];
        list.sort((a, b) => (key(a) < key(b) ? -1 : key(a) > key(b) ? 1 : 0) * state.dir);
        const count = (code) => rows.filter((r) => r.health.code === code).length;
        const uniq = (fn) => [...new Set(rows.map(fn).filter(Boolean))].sort();
        const opt = (vals, cur) => vals.map((v) => `<option ${v === cur ? 'selected' : ''}>${esc(v)}</option>`).join('');
        const hasFields = rows.some((r) => Object.keys(r.fields).length);
        const avg = rows.length ? Math.round(rows.reduce((a, r) => a + r.actual, 0) / rows.length) : 0;

        const kpi = (label, value, sub, key, color) => `<div class="col-6 col-md-4 col-xl-2"><div class="pmo-kpi oak-kpi-click" role="button" tabindex="0" data-dr="${key}" style="--c:${color}"><div class="pmo-kpi-label">${label}</div><div class="pmo-kpi-value">${value}</div><div class="pmo-kpi-sub">${sub}</div></div></div>`;
        let html = `<div class="row g-2 mb-3">
            ${kpi(state.closed ? 'Projetos' : 'Projetos ativos', rows.length, `% médio concluído: ${avg}%`, 'all', '#3b82f6')}
            ${kpi('<span class="pmo-health pmo-health-green"></span>No prazo', count('green'), state.health === 'green' ? 'filtrando a tabela' : 'real em dia com o planejado', 'green', '#22c55e')}
            ${kpi('<span class="pmo-health pmo-health-yellow"></span>Atenção', count('yellow'), 'desvio 5–15 p.p. ou tarefas atrasadas', 'yellow', '#f59e0b')}
            ${kpi('<span class="pmo-health pmo-health-red"></span>Atrasados', count('red'), 'desvio > 15 p.p. ou prazo vencido', 'red', '#ef4444')}
            ${kpi('Tarefas atrasadas', rows.reduce((a, r) => a + r.overdue, 0), 'em todos os projetos', 'overdue', '#ef4444')}
            ${kpi('Riscos abertos', rows.reduce((a, r) => a + r.risks, 0), 'registro de riscos do PMO', 'risks', '#f97316')}
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="card h-100"><div class="card-header"><h3 class="card-title">Por estado</h3></div><div class="card-body"><div class="pmo-chart" style="height:220px" id="p-state"></div></div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-header"><h3 class="card-title">Saúde</h3></div><div class="card-body"><div class="pmo-chart" style="height:220px" id="p-health"></div></div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-header"><h3 class="card-title">${hasFields ? 'Por cliente' : 'Por metodologia'}</h3></div><div class="card-body"><div class="pmo-chart" style="height:220px" id="p-client"></div></div></div></div>
        </div>
        <div class="card"><div class="card-header flex-wrap gap-2">
            <div class="input-icon"><span class="input-icon-addon"><i class="ti ti-search"></i></span><input class="form-control form-control-sm" placeholder="Buscar projeto, cliente, gerente…" value="${esc(state.q)}" data-q style="width:240px"></div>
            <select class="form-select form-select-sm w-auto" data-f="health"><option value="">Toda saúde</option>${Object.entries(HEALTH).map(([k, [l]]) => `<option value="${k}" ${k === state.health ? 'selected' : ''}>${l}</option>`).join('')}</select>
            <select class="form-select form-select-sm w-auto" data-f="stateName"><option value="">Todos os estados</option>${opt(uniq((r) => r.state?.name), state.stateName)}</select>
            ${hasFields ? `<select class="form-select form-select-sm w-auto" data-f="client"><option value="">Todos os clientes</option>${opt(uniq((r) => field(r, 'Cliente')), state.client)}</select>` : ''}
            <label class="form-check form-check-inline mb-0 small"><input class="form-check-input" type="checkbox" data-closed ${state.closed ? 'checked' : ''}><span class="form-check-label">Incluir encerrados</span></label>
            <span class="ms-auto small text-secondary">${list.length} projeto(s)</span>
            <button class="btn btn-sm btn-outline-secondary" data-csv><i class="ti ti-file-spreadsheet me-1"></i>CSV</button>
        </div>
        <div class="table-responsive"><table class="table table-vcenter card-table table-hover"><thead><tr>
            ${th('health', 'Saúde')}${th('name', 'Projeto')}${hasFields ? th('client', 'Cliente') : ''}${th('state', 'Estado')}<th>Gerente${hasFields ? ' / líder técnico' : ''}</th>
            ${th('actual', 'Progresso (real | planejado)')}${th('end', 'Fim planejado')}${th('overdue', 'Atrasadas')}${th('risks', 'Riscos')}${hasFields ? '<th>Ponto de atenção</th>' : ''}
        </tr></thead><tbody>`;
        const today = new Date().toISOString().slice(0, 10);
        html += list.map((r) => `<tr>
            <td class="text-nowrap"><span class="pmo-health pmo-health-${r.health.code}"></span><span class="small">${esc(r.health.label)}</span></td>
            <td><a href="${r.pmo}" class="fw-bold">${r.code ? `<span class="text-secondary">${esc(r.code)}</span> ` : ''}${esc(r.name)}</a>
                <div class="small text-secondary">${esc([r.methodology || 'PMO não ativado', r.type, field(r, 'Classificação')].filter(Boolean).join(' · '))} · <a href="${r.url}" class="text-secondary">GLPI</a></div></td>
            ${hasFields ? `<td>${esc(field(r, 'Cliente'))}</td>` : ''}
            <td>${r.state ? `<span class="badge pmo-badge" style="--c:${esc(r.state.color)}">${esc(r.state.name)}</span>` : ''}</td>
            <td class="small">${esc(r.manager)}${field(r, 'Líder Técnico') ? `<div class="text-secondary">${esc(field(r, 'Líder Técnico'))}</div>` : ''}</td>
            <td>${progress(r)}</td>
            <td class="text-nowrap ${r.end && r.end < today && r.health.code !== 'done' ? 'text-danger' : ''}">${fmt(r.end)}</td>
            <td class="${r.overdue ? 'text-danger fw-bold' : 'text-secondary'}">${r.overdue}/${r.tasks}</td>
            <td class="${r.risks ? 'text-warning fw-bold' : 'text-secondary'}">${r.risks}</td>
            ${hasFields ? `<td class="small" style="max-width:260px">${esc(field(r, 'Ponto de Atenção'))}${field(r, 'Status Atual do Projeto') ? `<div class="text-secondary text-truncate" title="${esc(field(r, 'Status Atual do Projeto'))}">${esc(field(r, 'Status Atual do Projeto'))}</div>` : ''}</td>` : ''}
        </tr>`).join('') || `<tr><td colspan="10" class="text-secondary">Nenhum projeto.</td></tr>`;
        html += `</tbody></table></div><div class="card-footer small text-secondary">Saúde: compara o % real com o % planejado para hoje (pelas datas das tarefas) e o prazo do projeto. A marca na barra de progresso é o planejado.</div></div>`;
        app.innerHTML = html;
        bind(list);
        draw();
    }

    // resumo de um cartão no painel lateral (drawer.js)
    function summary(key) {
        if (!window.OakDrawer) return;
        const today = new Date().toISOString().slice(0, 10);
        const health = (r) => `<span class="pmo-health pmo-health-${r.health.code}"></span>`;
        const item = (r, val, sub, cls = '') => `<div class="oak-dr-item ${cls}" style="--oc:${HEALTH_COLORS[r.health.code]}"><div class="oak-dr-row">${health(r)}<a href="${r.pmo}"><b>${esc(r.code || '')}</b></a><span class="oak-dr-name">${esc(r.name)}</span><span class="oak-dr-val">${val}</span></div><div class="oak-dr-sub">${sub}</div>
            <div class="oak-dr-bar"><i style="width:${Math.min(100, r.actual)}%"></i>${r.planned !== null ? `<em style="left:${Math.min(100, r.planned)}%"></em>` : ''}</div></div>`;
        const base = (r) => `${esc(r.health.label)} · ${esc(r.manager || 'sem gerente')} · real ${Math.round(r.actual)}%${r.planned !== null ? ` / planejado ${Math.round(r.planned)}%` : ''} · fim ${fmt(r.end)}${r.end && r.end < today && r.health.code !== 'done' ? ' <b class="text-danger">vencido</b>' : ''}`;
        const filterBtn = (code) => `<div class="oak-dr-foot"><button type="button" class="btn btn-outline-primary" data-apply="${code}"><i class="ti ti-filter me-1"></i>${state.health === code ? 'Tirar o filtro da tabela' : 'Filtrar a tabela por esta saúde'}</button></div>`;
        const lead = (t) => `<p class="oak-dr-lead">${t}</p>`;
        const empty = '<div class="oak-dr-empty">Nenhum projeto.</div>';
        let title, icon, color, html;
        if (['green', 'yellow', 'red'].includes(key)) {
            const list = rows.filter((r) => r.health.code === key).sort((a, b) => (a.actual - (a.planned ?? a.actual)) - (b.actual - (b.planned ?? b.actual)));
            [title, icon, color] = [{green: 'No prazo', yellow: 'Atenção', red: 'Atrasados'}[key], {green: 'ti-circle-check', yellow: 'ti-alert-triangle', red: 'ti-alarm'}[key], HEALTH_COLORS[key]];
            html = lead(`${list.length} projeto(s). A barra é o % real; o traço amarelo é o planejado para hoje.`) + `<div class="oak-dr-list">${list.map((r) => item(r, `${r.overdue}/${r.tasks}`, base(r) + (r.risks ? ` · ${r.risks} risco(s)` : ''))).join('') || empty}</div>` + filterBtn(key);
        } else if (key === 'overdue') {
            const list = rows.filter((r) => r.overdue).sort((a, b) => b.overdue - a.overdue);
            [title, icon, color] = ['Tarefas atrasadas', 'ti-clock-exclamation', '#ef4444'];
            html = lead(`${list.reduce((a, r) => a + r.overdue, 0)} tarefa(s) atrasada(s) em ${list.length} projeto(s), do que tem mais para o que tem menos.`)
                + `<div class="oak-dr-list">${list.map((r) => item(r, `${r.overdue} de ${r.tasks}`, base(r), 'bad')).join('') || empty}</div>`;
        } else if (key === 'risks') {
            const list = rows.filter((r) => r.risks).sort((a, b) => b.risks - a.risks);
            [title, icon, color] = ['Riscos abertos', 'ti-alert-triangle', '#f97316'];
            html = lead(`${list.reduce((a, r) => a + r.risks, 0)} risco(s) aberto(s) em ${list.length} projeto(s). Clique no projeto e abra a aba Riscos.`)
                + `<div class="oak-dr-list">${list.map((r) => item(r, `${r.risks}`, base(r), 'warn')).join('') || empty}</div>`;
        } else {
            const list = [...rows].sort((a, b) => HEALTH[a.health.code][1] - HEALTH[b.health.code][1]);
            const by = (c) => rows.filter((r) => r.health.code === c).length;
            [title, icon, color] = [state.closed ? 'Projetos' : 'Projetos ativos', 'ti-briefcase', '#3b82f6'];
            html = `<div class="oak-dr-stats"><div style="--sc:#22c55e"><small>No prazo</small><b>${by('green')}</b></div><div style="--sc:#f59e0b"><small>Atenção</small><b>${by('yellow')}</b></div><div style="--sc:#ef4444"><small>Atrasados</small><b>${by('red')}</b></div><div style="--sc:#64748b"><small>Concluídos</small><b>${by('done')}</b></div></div>`
                + lead('Do mais crítico para o mais tranquilo.') + `<div class="oak-dr-list">${list.map((r) => item(r, `${Math.round(r.actual)}%`, base(r))).join('') || empty}</div>`;
        }
        const wrap = document.createElement('div');
        wrap.innerHTML = html;
        wrap.querySelector('[data-apply]')?.addEventListener('click', (ev) => {
            const code = ev.currentTarget.dataset.apply;
            state.health = state.health === code ? '' : code;
            OakDrawer.close();
            render();
        });
        OakDrawer.open({title, icon, color, node: wrap});
    }

    const th = (k, label) => `<th style="cursor:pointer" data-sort="${k}">${label}${state.sort === k ? ` <i class="ti ti-arrow-${state.dir > 0 ? 'down' : 'up'}"></i>` : ''}</th>`;

    function bind(list) {
        const q = app.querySelector('[data-q]');
        q.addEventListener('input', () => {
            state.q = q.value.toLowerCase();
            render();
            const n = app.querySelector('[data-q]'); n.focus(); n.setSelectionRange(n.value.length, n.value.length);
        });
        app.querySelectorAll('[data-f]').forEach((s) => s.addEventListener('change', () => { state[s.dataset.f] = s.value; render(); }));
        app.querySelectorAll('[data-dr]').forEach((k) => {
            k.addEventListener('click', () => summary(k.dataset.dr));
            k.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); summary(k.dataset.dr); } });
        });
        app.querySelector('[data-closed]').addEventListener('change', (e) => { state.closed = e.target.checked; load(); });
        app.querySelectorAll('[data-sort]').forEach((h) => h.addEventListener('click', () => {
            state.dir = state.sort === h.dataset.sort ? -state.dir : 1;
            state.sort = h.dataset.sort;
            render();
        }));
        app.querySelector('[data-csv]').addEventListener('click', () => csv(list));
    }

    function csv(list) {
        const cols = ['Projeto', 'Código', 'Saúde', 'Estado', 'Metodologia', 'Gerente', '% real', '% planejado', 'Início', 'Fim', 'Tarefas', 'Atrasadas', 'Riscos abertos', ...boot.fields];
        const q = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
        const lines = [cols.map(q).join(';')].concat(list.map((r) => [r.name, r.code, r.health.label, r.state?.name, r.methodology, r.manager,
            String(r.actual).replace('.', ','), r.planned === null ? '' : String(r.planned).replace('.', ','), fmt(r.start), fmt(r.end), r.tasks, r.overdue, r.risks,
            ...boot.fields.map((f) => field(r, f))].map(q).join(';')));
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], {type: 'text/csv;charset=utf-8'}));
        a.download = `portfolio-pmo-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    }

    function draw() {
        if (!window.echarts) return;
        const t = chartTheme();
        const donut = (id, data) => {
            const c = echarts.init(document.getElementById(id));
            c.setOption({
                ...t.base, tooltip: {trigger: 'item', ...t.base.tooltip}, legend: {...t.base.legend, type: 'scroll', top: 'bottom'},
                series: [{type: 'pie', radius: ['48%', '72%'], center: ['50%', '44%'], itemStyle: {borderColor: 'transparent', borderWidth: 2, borderRadius: 4},
                    label: {show: true, color: t.text, formatter: '{c}'}, data}],
            });
            charts.push(c);
        };
        const group = (fn, color) => {
            const m = new Map();
            rows.forEach((r) => { const k = fn(r) || '—'; m.set(k, (m.get(k) || {value: 0, color: color(r)})); m.get(k).value++; });
            return [...m.entries()].map(([name, v]) => ({name, value: v.value, itemStyle: v.color ? {color: v.color} : undefined}));
        };
        donut('p-state', group((r) => r.state?.name || 'Sem estado', (r) => r.state?.color));
        donut('p-health', Object.keys(HEALTH).map((k) => ({name: HEALTH[k][0], value: rows.filter((r) => r.health.code === k).length, itemStyle: {color: HEALTH_COLORS[k]}})).filter((d) => d.value));
        const hasFields = rows.some((r) => Object.keys(r.fields).length);
        const data = group((r) => (hasFields ? field(r, 'Cliente') : r.methodology) || '—', () => null).sort((a, b) => b.value - a.value).slice(0, 10);
        const c = echarts.init(document.getElementById('p-client'));
        c.setOption({
            ...t.base, tooltip: {trigger: 'item', ...t.base.tooltip}, grid: {left: 10, right: 30, top: 5, bottom: 5, containLabel: true},
            xAxis: {type: 'value', minInterval: 1, ...t.axis}, yAxis: {type: 'category', inverse: true, data: data.map((d) => d.name), ...t.axis},
            series: [{type: 'bar', data: data.map((d) => d.value), itemStyle: {color: '#22d3ee', borderRadius: [0, 4, 4, 0]}, label: {show: true, position: 'right', color: t.text}}],
        });
        charts.push(c);
    }

    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
    load();
})();
