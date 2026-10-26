/**
 * PMO — portfólio: semáforo de saúde, progresso real × planejado, prazos, riscos
 * e os campos do bloco "Dados do projeto" (plugin Fields), com filtros e CSV.
 */
(() => {
    const {boot, esc, api, fmt} = PMO;
    const app = document.getElementById('pmo-app');
    const HEALTH = {red: ['Atrasado', 0], yellow: ['Atenção', 1], green: ['No prazo', 2], done: ['Concluído', 3]};
    const HEALTH_COLORS = {red: '#ef4444', yellow: '#f59e0b', green: '#22c55e', done: '#64748b'};
    const state = {q: '', health: '', stateName: '', client: '', closed: false, sort: 'health', dir: 1};
    let rows = [];

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

    const chartCard = (id, icon, title, sub) => `<div class="col-md-4"><div class="card h-100"><div class="card-header"><h3 class="card-title"><i class="ti ${icon} me-2"></i>${title}</h3>`
        + `<div class="card-actions small text-secondary">${sub}</div></div><div class="card-body"><div class="pmo-chart" style="height:240px" id="${id}"></div></div></div></div>`;

    // "Onde os projetos estão agora": uma marca por estado (na ordem do cadastro de estados do GLPI) e os projetos
    // agrupados, com o % real contra o planejado para hoje; vermelho = saúde atrasada (mesmo modelo do Esteira.php)
    function pipeline() {
        const open = rows.filter((r) => r.health.code !== 'done' && !r.state?.finished);
        const steps = new Map();
        [...open].sort((a, b) => (a.state?.id ?? 1e9) - (b.state?.id ?? 1e9)).forEach((r) => {
            const k = r.state?.name || 'Sem estado';
            if (!steps.has(k)) steps.set(k, []);
            steps.get(k).push(r);
        });
        const late = open.filter((r) => r.health.code === 'red').length;
        let h = `<div class="card oak-pipe-card"><div class="card-header"><div><h3 class="card-title"><i class="ti ti-route me-2"></i>Onde os projetos estão agora</h3>`
            + `<div class="small text-secondary">${open.length} em andamento · ${late ? `<span class="text-danger fw-bold">${late} atrasado(s)</span>` : 'nenhum atrasado'}</div></div></div><div class="card-body">`;
        if (!open.length) return h + '<div class="oak-bars-empty"><i class="ti ti-route"></i>Nenhum projeto em andamento.</div></div></div>';
        const list = [...steps.entries()];
        h += '<div class="oak-pipe"><div class="oak-pipe-track">' + list.map(([name, rs], i) => `<div class="oak-pipe-step ${rs.some((r) => r.health.code === 'red') ? 'bad' : 'ok'}" title="${esc(`${i + 1}. ${name} · ${rs.length}`)}" style="left:${list.length > 1 ? (100 * i / (list.length - 1)).toFixed(2) : 0}%"><b>${rs.length}</b><i></i></div>`).join('')
            + `</div><div class="oak-pipe-ends"><span>${esc(list[0][0])}</span><span></span><span>${list.length > 1 ? esc(list[list.length - 1][0]) : ''}</span></div></div><div class="oak-pipe-list">`;
        list.forEach(([name, rs], i) => {
            rs.sort((a, b) => (a.actual - (a.planned ?? a.actual)) - (b.actual - (b.planned ?? b.actual)));
            h += `<div class="oak-pipe-group"><div class="oak-pipe-phase"><span class="oak-pipe-num">${i + 1}</span><b>${esc(name)}</b><span class="text-secondary">${rs.length} projeto(s)</span></div>`;
            h += rs.map((r) => {
                const bad = r.health.code === 'red';
                const gap = r.planned !== null ? Math.round(r.actual - r.planned) : null;
                const note = gap === null ? 'sem cronograma datado' : gap >= 0 ? 'em dia com o planejado' : `${-gap} p.p. abaixo do planejado`;
                return `<a class="oak-pipe-item ${bad ? 'bad' : ''}" href="${r.pmo}"><span class="oak-pipe-code">${esc(r.code || 'PRJ')}</span>`
                    + `<span class="oak-pipe-name">${esc(r.name)}<small>${esc([r.manager || 'sem gerente', field(r, 'Cliente'), `fim ${fmt(r.end)}`].filter(Boolean).join(' · '))}</small></span>`
                    + `<span class="oak-pipe-time"><b>${Math.round(r.actual)}%</b>${r.planned !== null ? ` / ${Math.round(r.planned)}%` : ''}<small>${note}</small></span>`
                    + `<span class="oak-pipe-bar"><i style="width:${Math.min(100, r.actual)}%"></i></span></a>`;
            }).join('') + '</div>';
        });
        return h + '</div></div></div>';
    }

    const progress = (r) => `<div class="pmo-progress" title="Real ${r.actual}%${r.planned !== null ? ` · planejado ${r.planned}%` : ''}"><div class="trk"><i style="width:${Math.min(100, r.actual)}%"></i>${r.planned !== null ? `<b style="left:${Math.min(100, r.planned)}%"></b>` : ''}</div><span>${Math.round(r.actual)}%</span></div>`;

    function render() {
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
            ${chartCard('p-state', 'ti-progress', 'Por estado', 'clique para ver os projetos')}
            ${chartCard('p-health', 'ti-heartbeat', 'Saúde', 'clique para ver os projetos')}
            ${chartCard('p-client', hasFields ? 'ti-building' : 'ti-route', hasFields ? 'Por cliente' : 'Por metodologia', hasFields ? 'os 10 com mais projetos' : 'clique para ver os projetos')}
        </div>
        <div class="mb-3">${pipeline()}</div>
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
    function summary(key, group = null) {
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
        if (group) {
            const list = [...group.list].sort((a, b) => HEALTH[a.health.code][1] - HEALTH[b.health.code][1]);
            const by = (c) => list.filter((r) => r.health.code === c).length;
            [title, icon, color] = [group.title, group.icon, group.color];
            html = `<div class="oak-dr-stats"><div style="--sc:#22c55e"><small>No prazo</small><b>${by('green')}</b></div><div style="--sc:#f59e0b"><small>Atenção</small><b>${by('yellow')}</b></div><div style="--sc:#ef4444"><small>Atrasados</small><b>${by('red')}</b></div><div style="--sc:#64748b"><small>Concluídos</small><b>${by('done')}</b></div></div>`
                + lead(`${list.length} projeto(s) ${esc(group.lead)}, do mais crítico para o mais tranquilo.`) + `<div class="oak-dr-list">${list.map((r) => item(r, `${Math.round(r.actual)}%`, base(r))).join('') || empty}</div>`;
        } else if (['green', 'yellow', 'red'].includes(key)) {
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

    // gráficos: barras do modelo comum (oakbars.js); clicar abre os projetos no painel lateral
    function draw() {
        if (!window.OakBars) return;
        const group = (fn, color) => {
            const m = new Map();
            rows.forEach((r) => {
                const k = fn(r) || '—';
                if (!m.has(k)) m.set(k, {name: k, color: color(r), list: []});
                m.get(k).list.push(r);
            });
            return [...m.values()].sort((a, b) => b.list.length - a.list.length).map((g) => ({...g, value: g.list.length}));
        };
        const pct = (n) => (rows.length ? Math.round(100 * n / rows.length) : 0);
        const tip = (g) => OakBars.tipHtml(g.name, [[OakBars.pair(g.color)[1], 'Projetos', g.list.length], ['', 'Do total', pct(g.list.length) + '%'],
            ['#ef4444', 'Atrasados', g.list.filter((r) => r.health.code === 'red').length], ['', 'Tarefas atrasadas', g.list.reduce((a, r) => a + r.overdue, 0)]]);
        const base = {labelWidth: 140, right: 80, minInterval: 1, empty: 'Sem projetos', tip};

        const palette = ['blue', 'purple', 'cyan', 'amber', 'pink', 'teal', 'orange'];
        const states = group((r) => r.state?.name || 'Sem estado', (r) => r.state?.color || '');
        states.forEach((g, i) => { if (!/^#[0-9a-f]{6}$/i.test(g.color)) g.color = g.name === 'Sem estado' ? 'slate' : palette[i % palette.length]; });
        OakBars.render(document.getElementById('p-state'), {...base, items: states,
            onClick: (g) => summary('group', {title: g.name, icon: 'ti-progress', color: OakBars.pair(g.color)[1], list: g.list, lead: 'neste estado'})});

        const hColor = {green: 'green', yellow: 'amber', red: 'red', done: 'slate'};
        const health = Object.keys(HEALTH).map((k) => ({code: k, name: HEALTH[k][0], color: hColor[k], list: rows.filter((r) => r.health.code === k)}))
            .filter((h) => h.list.length).map((h) => ({...h, value: h.list.length}));
        OakBars.render(document.getElementById('p-health'), {...base, items: health,
            onClick: (h) => (h.code === 'done' ? summary('group', {title: h.name, icon: 'ti-circle-check', color: '#64748b', list: h.list, lead: 'concluídos'}) : summary(h.code))});

        const hasFields = rows.some((r) => Object.keys(r.fields).length);
        const clients = group((r) => (hasFields ? field(r, 'Cliente') : r.methodology) || 'Não informado', () => 'cyan').slice(0, 10);
        OakBars.render(document.getElementById('p-client'), {...base, items: clients,
            onClick: (g) => summary('group', {title: g.name, icon: hasFields ? 'ti-building' : 'ti-route', color: '#22d3ee', list: g.list, lead: hasFields ? 'deste cliente' : 'com esta metodologia'})});
    }

    load();
})();
