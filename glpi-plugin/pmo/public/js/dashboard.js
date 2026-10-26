/**
 * PMO — painéis do projeto: indicadores, burndown da sprint, burnup, curva S,
 * fluxo cumulativo, velocidade, distribuição por coluna, carga da equipe e prazos.
 */
(() => {
    const {boot, esc, api, fmt, fmtShort, chartTheme} = PMO;
    const app = document.getElementById('pmo-app');
    const charts = [];
    let sprintId = null;

    async function load() {
        try {
            render(await api('dashboard', {sprint: sprintId}));
        } catch (e) {
            app.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    const kpi = (label, value, sub = '', extra = '') => `<div class="col-6 col-md-4 col-xl-2"><div class="pmo-kpi" ${extra}><div class="pmo-kpi-label">${label}</div><div class="pmo-kpi-value">${value}</div><div class="pmo-kpi-sub">${sub}</div></div></div>`;
    const card = (id, title, sub = '', cls = 'col-xl-6', tools = '') => `<div class="col-12 ${cls}"><div class="card h-100"><div class="card-header"><h3 class="card-title">${title}</h3>${tools ? `<div class="card-actions">${tools}</div>` : ''}</div>
        <div class="card-body">${sub ? `<div class="small text-secondary mb-1">${sub}</div>` : ''}<div class="pmo-chart" id="${id}"></div></div></div></div>`;

    function render(d) {
        charts.splice(0).forEach((c) => c.dispose());
        const k = d.kpi;
        const u = d.unit;
        const spiCls = k.spi === null ? '' : k.spi >= 0.95 ? 'text-success' : k.spi >= 0.85 ? 'text-warning' : 'text-danger';
        let html = `<div class="row g-2 mb-3">
            ${kpi('Saúde', `<span class="pmo-health pmo-health-${k.health.code}"></span><span style="font-size:1.05rem">${esc(k.health.label)}</span>`)}
            ${kpi('% concluído (real)', `${k.actual}%`, k.planned !== null ? `planejado para hoje: ${k.planned}%` : 'sem cronograma datado')}
            ${kpi('Índice de prazo (SPI)', k.spi === null ? '—' : `<span class="${spiCls}">${k.spi.toFixed(2)}</span>`, 'real ÷ planejado (≥ 1 = no prazo)')}
            ${kpi('Tarefas', `${k.done}/${k.total}`, `${k.doing} em andamento · ${k.todo} a fazer`)}
            ${kpi('Atrasadas', `<span class="${k.overdue ? 'text-danger' : ''}">${k.overdue}</span>`, `${k.soon} vencem em 7 dias`)}
            ${kpi('Prazo final', k.plan_end ? fmt(k.plan_end) : '—', k.days_left === null ? 'sem data de fim no projeto' : k.days_left >= 0 ? `${k.days_left} dia(s) restantes` : `<span class="text-danger">${-k.days_left} dia(s) vencido</span>`)}
            ${kpi('Escopo entregue', `${k.size_done}/${k.size_total}`, u)}
            ${kpi('Riscos abertos', `<span class="${k.risks ? 'text-warning' : ''}">${k.risks}</span>`, '<a href="?id=' + boot.project + '&view=risks">ver registro</a>')}
            ${d.velocity.average !== null ? kpi('Velocidade média', d.velocity.average, `${u} / sprint (últimas 3)`) : ''}
            ${d.burnup.forecast ? kpi('Previsão de término', d.burnup.forecast === 'concluído' ? 'concluído' : fmt(d.burnup.forecast), `ritmo: ${d.burnup.rate} ${d.burnup.unit}/semana`) : ''}
        </div><div class="row g-3">`;

        if (boot.sprints) {
            const opts = d.sprints.filter((s) => s.state !== 'future').map((s) => `<option value="${s.id}" ${d.sprint && s.id === d.sprint.id ? 'selected' : ''}>${esc(s.name)}${s.state === 'active' ? ' (atual)' : ''}</option>`).join('');
            html += d.burndown
                ? card('c-burndown', '<i class="ti ti-trending-down me-2"></i>Burndown da sprint', d.sprint.goal ? `Meta: ${esc(d.sprint.goal)}` : `Restante em ${d.burndown.unit} × linha ideal`, 'col-xl-6', `<select class="form-select form-select-sm" data-sprint>${opts}</select>`)
                : `<div class="col-xl-6"><div class="card h-100"><div class="card-body text-secondary"><h3 class="card-title">Burndown da sprint</h3>Nenhuma sprint iniciada ainda.</div></div></div>`;
        }
        html += card('c-burnup', '<i class="ti ti-trending-up me-2"></i>Burnup do projeto', `Escopo × concluído (${d.burnup.unit})`);
        if (d.scurve) html += card('c-scurve', '<i class="ti ti-chart-line me-2"></i>Curva S — planejado × real', d.scurve.since ? `% real registrado diariamente desde ${fmt(d.scurve.since)}` : 'O % real passa a ser registrado a partir de hoje');
        html += card('c-cfd', '<i class="ti ti-chart-area-line me-2"></i>Fluxo cumulativo (CFD)', d.cfd.labels.length < 2 ? 'Precisa de alguns dias de histórico (uma fotografia por dia).' : 'Cartões por coluna ao longo do tempo');
        if (boot.sprints) html += card('c-velocity', '<i class="ti ti-gauge me-2"></i>Velocidade', `Comprometido × entregue por sprint (${u})`);
        html += card('c-columns', '<i class="ti ti-layout-kanban me-2"></i>Cartões por coluna', '', boot.sprints ? 'col-xl-6' : 'col-xl-6');

        // carga da equipe
        const w = d.workload;
        html += `<div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-users me-2"></i>Carga da equipe (horas planejadas por semana)</h3></div>`;
        if (!w.users.length) {
            html += `<div class="card-body text-secondary small">Sem tarefas abertas com responsável e datas planejadas.</div>`;
        } else {
            const color = (h) => {
                const r = h / w.capacity;
                if (!h) return '';
                const c = r > 1 ? '239,68,68' : r > 0.8 ? '245,158,11' : '34,197,94';
                return `background:rgba(${c},${Math.min(0.85, 0.18 + r * 0.5)})`;
            };
            html += `<div class="table-responsive"><table class="table table-sm card-table pmo-heat mb-0"><thead><tr><th>Pessoa</th>${w.weeks.map((x, i) => `<th class="${i === w.current ? 'cur' : ''}">${x}</th>`).join('')}<th>Total</th></tr></thead><tbody>`
                + w.users.map((p) => `<tr><td>${esc(p.name)}</td>${p.hours.map((h, i) => `<td class="${i === w.current ? 'cur' : ''}" style="${color(h)}" title="${h} h de ${w.capacity} h">${h ? Math.round(h) : ''}</td>`).join('')}<td><b>${Math.round(p.hours.reduce((a, b) => a + b, 0))}</b></td></tr>`).join('')
                + `</tbody></table></div><div class="card-footer small text-secondary">Capacidade de referência: ${w.capacity} h/semana. Verde até 80%, amarelo até 100%, vermelho acima. Considera o que falta das tarefas abertas (duração planejada × % restante), dividido entre os responsáveis.</div>`;
        }
        html += `</div></div>`;

        const table = (title, icon, rows, empty, late) => `<div class="col-xl-6"><div class="card h-100"><div class="card-header"><h3 class="card-title"><i class="ti ${icon} me-2"></i>${title}</h3></div>`
            + (rows.length ? `<div class="table-responsive"><table class="table table-sm card-table mb-0"><thead><tr><th>Tarefa</th><th>Fim</th><th>%</th><th>Responsáveis</th></tr></thead><tbody>`
                + rows.map((r) => `<tr><td><a href="${boot.urls.task}?id=${r.id}">${esc(r.name)}</a></td><td class="${late ? 'text-danger' : ''} text-nowrap">${fmt(r.end)}${late ? ` <small>(${r.days} d)</small>` : ''}</td><td>${r.percent}%</td><td class="small">${esc(r.users.join(', '))}</td></tr>`).join('')
                + `</tbody></table></div>` : `<div class="card-body text-secondary small">${empty}</div>`) + `</div></div>`;
        html += table('Tarefas atrasadas', 'ti-alarm', d.overdue, 'Nenhuma tarefa atrasada. 👏', true);
        html += table('Vencem nos próximos 7 dias', 'ti-calendar-due', d.soon, 'Nada vencendo nos próximos 7 dias.', false);
        html += `</div>`;
        app.innerHTML = html;
        app.querySelector('[data-sprint]')?.addEventListener('change', (e) => { sprintId = +e.target.value; load(); });
        draw(d);
    }

    function draw(d) {
        if (!window.echarts) {
            app.insertAdjacentHTML('afterbegin', '<div class="alert alert-warning">A biblioteca de gráficos do GLPI não carregou.</div>');
            return;
        }
        const t = chartTheme();
        const mk = (id, option) => {
            const el = document.getElementById(id);
            if (!el) return;
            const c = echarts.init(el, null, {renderer: 'canvas'});
            c.setOption({...t.base, ...option});
            charts.push(c);
        };
        const cat = (labels) => ({type: 'category', data: labels.map(fmtShort), boundaryGap: false, ...t.axis});
        const val = (extra = {}) => ({type: 'value', ...t.axis, ...extra});
        const line = (name, data, color, extra = {}) => ({name, type: 'line', data, showSymbol: data.length < 40, symbolSize: 5, smooth: false, lineStyle: {width: 2.5, color}, itemStyle: {color}, ...extra});

        if (d.burndown) {
            mk('c-burndown', {
                xAxis: cat(d.burndown.labels), yAxis: val({name: d.burndown.unit, nameTextStyle: {color: t.muted}}),
                series: [line('Ideal', d.burndown.ideal, t.muted, {lineStyle: {type: 'dashed', width: 1.5, color: t.muted}, showSymbol: false}),
                    line('Restante', d.burndown.remaining, '#3b82f6', {areaStyle: {opacity: .12}})],
            });
        }
        mk('c-burnup', {
            xAxis: cat(d.burnup.labels), yAxis: val({name: d.burnup.unit, nameTextStyle: {color: t.muted}}),
            series: [line('Escopo', d.burnup.scope, '#a855f7', {step: 'end'}), line('Concluído', d.burnup.done, '#22c55e', {areaStyle: {opacity: .15}})],
        });
        if (d.scurve) {
            const today = new Date().toISOString().slice(0, 10);
            const idx = d.scurve.labels.findIndex((l) => l >= today);
            mk('c-scurve', {
                xAxis: cat(d.scurve.labels), yAxis: val({max: 100, axisLabel: {color: t.muted, formatter: '{value}%'}}),
                tooltip: {...t.base.tooltip, valueFormatter: (v) => (v === null || v === undefined ? '—' : v + '%')},
                series: [line('Planejado', d.scurve.planned, '#94a3b8', {lineStyle: {width: 2, type: 'dashed', color: '#94a3b8'}, showSymbol: false,
                    markLine: idx >= 0 ? {symbol: 'none', silent: true, lineStyle: {color: '#ef4444'}, label: {formatter: 'hoje', color: '#ef4444'}, data: [{xAxis: idx}]} : undefined}),
                    line('Real', d.scurve.actual, '#22c55e', {connectNulls: false})],
            });
        }
        mk('c-cfd', {
            xAxis: cat(d.cfd.labels), yAxis: val({minInterval: 1}),
            series: d.cfd.series.map((s) => ({name: s.name, type: 'line', stack: 'cfd', data: s.data, showSymbol: false, lineStyle: {width: 1, color: s.color}, itemStyle: {color: s.color}, areaStyle: {color: s.color, opacity: .55}})),
        });
        if (boot.sprints) {
            const v = d.velocity.sprints;
            mk('c-velocity', {
                xAxis: {type: 'category', data: v.map((s) => s.name), ...t.axis}, yAxis: val(),
                series: [{name: 'Comprometido', type: 'bar', data: v.map((s) => s.committed), itemStyle: {color: '#64748b', borderRadius: [3, 3, 0, 0]}},
                    {name: 'Entregue', type: 'bar', data: v.map((s) => s.completed), itemStyle: {color: '#22c55e', borderRadius: [3, 3, 0, 0]},
                        markLine: d.velocity.average !== null ? {symbol: 'none', lineStyle: {color: '#3b82f6', type: 'dashed'}, label: {formatter: 'média {c}', color: '#3b82f6'}, data: [{yAxis: d.velocity.average}]} : undefined}],
                graphic: v.length ? undefined : {type: 'text', left: 'center', top: 'middle', style: {text: 'Sem sprints iniciadas', fill: t.muted}},
            });
        }
        // cartões por coluna: barras do modelo comum (oakbars.js); clicar lista os cartões no painel lateral
        const today = new Date().toISOString().slice(0, 10);
        window.OakBars?.render(document.getElementById('c-columns'), {
            items: d.columns.map((c) => ({name: c.name, value: c.count, color: /^#[0-9a-f]{6}$/i.test(c.color || '') ? c.color : 'blue', tasks: c.tasks || []})),
            labelWidth: 200, right: 80, minInterval: 1, empty: 'Sem colunas',
            onClick: (c) => window.OakDrawer?.open({title: c.name, icon: 'ti-layout-kanban', color: OakBars.pair(c.color)[1],
                html: `<p class="oak-dr-lead">${c.tasks.length} cartão(ões) nesta coluna.</p><div class="oak-dr-list">` + (c.tasks.map((x) => {
                    const late = x.end && x.end < today && x.percent < 100;
                    return `<div class="oak-dr-item ${late ? 'bad' : ''}"><div class="oak-dr-row"><span class="oak-dr-name">${esc(x.name)}</span><span class="oak-dr-val">${x.percent}%</span></div>`
                        + `<div class="oak-dr-sub">${esc(x.users.join(', ') || 'sem responsável')} · fim ${x.end ? fmt(x.end) : '—'}${late ? ' · <b class="text-danger">atrasada</b>' : ''}</div>`
                        + `<div class="oak-dr-bar"><i style="width:${Math.min(100, x.percent)}%"></i></div></div>`;
                }).join('') || '<div class="oak-dr-empty">Nenhum cartão.</div>') + '</div>'}),
        });
    }

    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
    load();
})();
