/**
 * Reembolso — gráficos do dashboard (ECharts do GLPI), no mesmo estilo dos dashboards
 * do RH, PMO e Ciclo: degradê, trilho de fundo e dicas detalhadas ao passar o mouse.
 */
(() => {
    const el = document.getElementById('rb-dash');
    if (!el) return;
    const d = JSON.parse(el.textContent);
    const charts = [];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
    const short = (v) => (v >= 1000 ? 'R$ ' + (v / 1000).toLocaleString('pt-BR', {maximumFractionDigits: 1}) + ' mil' : brl(v));

    function draw() {
        if (!window.echarts) return;
        const cs = getComputedStyle(document.body);
        const text = cs.getPropertyValue('--tblr-body-color').trim() || '#cbd5e1';
        const muted = cs.getPropertyValue('--tblr-secondary-color').trim() || '#94a3b8';
        const border = cs.getPropertyValue('--tblr-border-color').trim() || '#334155';
        const surface = cs.getPropertyValue('--tblr-bg-surface').trim() || '#0f172a';
        // dica: cartão escuro com sombra, título e linhas com bolinha colorida
        const tip = {
            backgroundColor: surface, borderColor: border, borderWidth: 1, padding: [10, 14], textStyle: {color: text, fontSize: 12},
            extraCssText: 'border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.45);max-width:360px;white-space:normal;',
        };
        const dot = (c) => `<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${c};margin-right:6px"></span>`;
        const row = (c, label, value) => `<div style="display:flex;gap:18px;justify-content:space-between;align-items:center;margin:2px 0">${dot(c)}<span style="flex:1">${label}</span><b>${value}</b></div>`;
        const head = (s) => `<div style="font-weight:700;font-size:13px;margin-bottom:6px">${s}</div>`;
        const foot = (s) => `<div style="margin-top:8px;padding-top:6px;border-top:1px solid ${border};color:${muted};font-size:11.5px">${s}</div>`;
        const axis = {axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 12, margin: 12}, splitLine: {lineStyle: {color: border, opacity: .4, type: 'dashed'}}};
        const grad = (c1, c2, horizontal = false) => new echarts.graphic.LinearGradient(0, horizontal ? 0 : 1, horizontal ? 1 : 0, 0, [{offset: 0, color: c2}, {offset: 1, color: c1}]);
        const mk = (id, option, has, node = document.getElementById(id)) => {
            if (!node) return null;
            if (!has) { node.innerHTML = '<div class="rb-empty"><i class="ti ti-chart-bar-off"></i>Sem dados no período</div>'; return null; }
            const c = echarts.init(node);
            c.setOption({backgroundColor: 'transparent', textStyle: {color: text, fontFamily: 'inherit', fontSize: 12}, animationDuration: 700, ...option});
            charts.push(c);
            return c;
        };
        const sum = (list) => list.reduce((a, x) => a + x.value, 0);
        const pct = (v, total) => (total ? Math.round(100 * v / total) : 0);

        // ---------------------------------------------------------- evolução mensal por status (colunas empilhadas)
        const totals = d.months.map((_, i) => d.series.reduce((a, s) => a + s.data[i], 0));
        // a média só faz sentido com dois meses ou mais com lançamentos
        const active = totals.filter((v) => v > 0);
        const avg = active.length > 1 ? active.reduce((a, v) => a + v, 0) / active.length : 0;
        const monthChart = mk('rb-c-month', {
            legend: {top: 0, left: 0, textStyle: {color: muted, fontSize: 12}, icon: 'circle', itemWidth: 9, itemHeight: 9, itemGap: 18},
            grid: {left: 4, right: 12, top: 44, bottom: 4, containLabel: true},
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.08)'}},
                formatter: (ps) => {
                    const i = ps[0].dataIndex;
                    return head(esc(d.months[i])) + d.series.map((s) => row(s.color, esc(s.name), brl(s.data[i]))).join('') + foot(`Total do mês <b style="color:${text}">${brl(totals[i])}</b><br>Clique para ver as despesas`);
                }},
            xAxis: {type: 'category', data: d.months, ...axis, splitLine: {show: false}},
            yAxis: {type: 'value', ...axis, axisLabel: {color: muted, fontSize: 11, formatter: short}},
            series: d.series.map((s, k) => ({name: s.name, type: 'bar', stack: 'v', data: s.data, cursor: 'pointer', barMaxWidth: 64, barCategoryGap: '38%',
                itemStyle: {color: grad(s.color, s.color + 'aa'), borderRadius: k === d.series.length - 1 ? [6, 6, 0, 0] : 0},
                ...(k === d.series.length - 1 ? {
                    label: {show: true, position: 'top', distance: 8, color: text, fontWeight: 700, fontSize: 13, formatter: (p) => (totals[p.dataIndex] ? short(totals[p.dataIndex]) : '')},
                    markLine: avg ? {symbol: 'none', silent: true, lineStyle: {color: '#f59e0b', type: 'dashed', width: 1.5},
                        label: {color: '#f59e0b', fontSize: 11.5, formatter: `média ${short(avg)}/mês`, position: 'insideStartTop'}, data: [{yAxis: avg}]} : undefined,
                } : {}),
            })),
        }, totals.some((v) => v > 0));
        monthChart?.on('click', (p) => {
            const key = d.keys?.[p.dataIndex];
            const list = d.exp.filter((x) => x.date.slice(0, 7) === key);
            detail(d.months[p.dataIndex], `${list.length} despesa(s) lançada(s) no mês`, list, '#22d3ee');
        });

        // ---------------------------------------------------------- por tipo, técnico e centro de custo: barras do modelo comum (oakbars.js)
        const colors = {type: 'cyan', user: 'blue', centro: 'teal'};
        const by = {type: (x) => x.tipo, user: (x) => x.tec, centro: (x) => x.centro};
        const what = {type: 'tipo', user: 'técnico', centro: 'centro de custo'};
        [['rb-c-type', 'type', 170], ['rb-c-user', 'user', 200], ['rb-c-centro', 'centro', 240]].forEach(([id, key, w]) => {
            const list = d[key];
            const total = sum(list);
            OakBars.render(document.getElementById(id), {
                items: list.slice(0, 15).map((x) => ({...x, color: colors[key]})),
                labelWidth: w, right: 120, format: short, axisFormat: short,
                tip: (x, i) => OakBars.tipHtml(x.name, [[OakBars.pair(colors[key])[1], 'Valor', brl(x.value)], ['', 'Do total', pct(x.value, total) + '%'],
                    ['', 'Despesas', d.exp.filter((e) => by[key](e) === x.name).length], ['', 'Posição', `${i + 1}º de ${list.length}`]]),
                onClick: (x) => detail(x.name, `${what[key][0].toUpperCase()}${what[key].slice(1)} · ${pct(x.value, total)}% do total`, d.exp.filter((e) => by[key](e) === x.name), OakBars.pair(colors[key])[1]),
            });
        });
    }

    // ---------------------------------------------------------- detalhe de uma barra no painel lateral
    const date = (s) => (s ? s.split('-').reverse().join('/') : '—');
    function detail(title, sub, list, color) {
        if (!window.OakDrawer) return;
        const total = list.reduce((a, x) => a + x.value, 0);
        const reqs = new Map();
        list.forEach((x) => { const r = reqs.get(x.id) || {code: x.code, url: x.url, tec: x.tec, st: x.st, value: 0, n: 0}; r.value += x.value; r.n++; reqs.set(x.id, r); });
        const st = (s) => d.statuses[s] || {name: '', color: '#94a3b8'};
        const stats = `<div class="oak-dr-stats"><div style="--sc:${color}"><small>Total</small><b>${brl(total)}</b></div><div><small>Despesas</small><b>${list.length}</b></div>`
            + `<div><small>Solicitações</small><b>${reqs.size}</b></div><div style="--sc:#f97316"><small>Acima do limite</small><b>${list.filter((x) => x.over).length}</b></div></div>`;
        const items = [...list].sort((a, b) => b.value - a.value).map((x) => `<div class="oak-dr-item ${x.over ? 'warn' : ''}" style="--oc:${st(x.st).color}"><div class="oak-dr-row">`
            + `<a href="${esc(x.url)}"><b>${esc(x.code)}</b></a><span class="oak-dr-name">${esc(x.desc || x.tipo)}</span><span class="oak-dr-val">${brl(x.value)}</span></div>`
            + `<div class="oak-dr-sub">${esc(date(x.date))} · ${esc(x.tec)} · ${esc(x.tipo)} · ${esc(x.centro)} · <span style="color:${st(x.st).color}">${esc(st(x.st).name)}</span>${x.over ? ' · <b class="text-warning">acima do limite</b>' : ''}</div></div>`).join('');
        OakDrawer.open({title, icon: 'ti-receipt-2', color,
            html: `<p class="oak-dr-lead">${esc(sub)}</p>${stats}<div class="oak-dr-list">${items || '<div class="oak-dr-empty">Nenhuma despesa.</div>'}</div>`});
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
})();
