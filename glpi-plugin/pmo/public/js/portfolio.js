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

        const kpi = (label, value, sub, code) => `<div class="col-6 col-md-4 col-xl-2"><div class="pmo-kpi" ${code ? `style="cursor:pointer" data-hf="${code}"` : ''}><div class="pmo-kpi-label">${label}</div><div class="pmo-kpi-value">${value}</div><div class="pmo-kpi-sub">${sub}</div></div></div>`;
        let html = `<div class="row g-2 mb-3">
            ${kpi(state.closed ? 'Projetos' : 'Projetos ativos', rows.length, `% médio concluído: ${avg}%`)}
            ${kpi('<span class="pmo-health pmo-health-green"></span>No prazo', count('green'), 'clique para filtrar', 'green')}
            ${kpi('<span class="pmo-health pmo-health-yellow"></span>Atenção', count('yellow'), 'desvio 5–15 p.p. ou tarefas atrasadas', 'yellow')}
            ${kpi('<span class="pmo-health pmo-health-red"></span>Atrasados', count('red'), 'desvio > 15 p.p. ou prazo vencido', 'red')}
            ${kpi('Tarefas atrasadas', rows.reduce((a, r) => a + r.overdue, 0), 'em todos os projetos')}
            ${kpi('Riscos abertos', rows.reduce((a, r) => a + r.risks, 0), 'registro de riscos do PMO')}
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

    const th = (k, label) => `<th style="cursor:pointer" data-sort="${k}">${label}${state.sort === k ? ` <i class="ti ti-arrow-${state.dir > 0 ? 'down' : 'up'}"></i>` : ''}</th>`;

    function bind(list) {
        const q = app.querySelector('[data-q]');
        q.addEventListener('input', () => {
            state.q = q.value.toLowerCase();
            render();
            const n = app.querySelector('[data-q]'); n.focus(); n.setSelectionRange(n.value.length, n.value.length);
        });
        app.querySelectorAll('[data-f]').forEach((s) => s.addEventListener('change', () => { state[s.dataset.f] = s.value; render(); }));
        app.querySelectorAll('[data-hf]').forEach((k) => k.addEventListener('click', () => { state.health = state.health === k.dataset.hf ? '' : k.dataset.hf; render(); }));
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
