/**
 * Reembolso — gráficos do dashboard (ECharts, que já vem com o GLPI).
 */
(() => {
    const el = document.getElementById('rb-dash');
    if (!el) return;
    const d = JSON.parse(el.textContent);
    const charts = [];
    const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
    const short = (v) => (v >= 1000 ? 'R$ ' + (v / 1000).toLocaleString('pt-BR', {maximumFractionDigits: 1}) + ' mil' : brl(v));

    function draw() {
        if (!window.echarts) return;
        const cs = getComputedStyle(document.body);
        const text = cs.getPropertyValue('--tblr-body-color').trim() || '#cbd5e1';
        const muted = cs.getPropertyValue('--tblr-secondary-color').trim() || '#94a3b8';
        const border = cs.getPropertyValue('--tblr-border-color').trim() || '#334155';
        const surface = cs.getPropertyValue('--tblr-bg-surface').trim() || '#0f172a';
        const base = {
            backgroundColor: 'transparent', textStyle: {color: text, fontFamily: 'inherit'},
            tooltip: {backgroundColor: surface, borderColor: border, textStyle: {color: text}, valueFormatter: brl},
            legend: {top: 0, textStyle: {color: muted}, icon: 'roundRect', itemWidth: 12, itemHeight: 8},
        };
        const axis = {axisLine: {lineStyle: {color: border}}, axisLabel: {color: muted}, splitLine: {lineStyle: {color: border, opacity: .5}}};
        const empty = (id) => {
            document.getElementById(id).innerHTML = '<div class="rb-empty">Sem dados no período</div>';
        };
        const mk = (id, option) => {
            const node = document.getElementById(id);
            if (!node) return;
            const c = echarts.init(node);
            c.setOption({...base, ...option});
            charts.push(c);
        };
        const palette = ['#22d3ee', '#3b82f6', '#a855f7', '#22c55e', '#f59e0b', '#ef4444', '#14b8a6', '#eab308', '#ec4899', '#64748b'];

        if (d.series.some((s) => s.data.some((v) => v > 0))) {
            mk('rb-c-month', {
                tooltip: {...base.tooltip, trigger: 'axis', axisPointer: {type: 'shadow'}},
                grid: {left: 10, right: 10, top: 36, bottom: 10, containLabel: true},
                xAxis: {type: 'category', data: d.months, ...axis},
                yAxis: {type: 'value', ...axis, axisLabel: {color: muted, formatter: short}},
                series: d.series.map((s) => ({name: s.name, type: 'bar', stack: 'v', data: s.data, itemStyle: {color: s.color}, barMaxWidth: 46})),
            });
        } else {
            empty('rb-c-month');
        }
        const top = (list, n) => {
            if (list.length <= n) return list;
            const rest = list.slice(n).reduce((a, x) => a + x.value, 0);
            return [...list.slice(0, n), {name: 'Outros', value: Math.round(rest * 100) / 100}];
        };
        if (d.type.length) {
            mk('rb-c-type', {
                tooltip: {...base.tooltip, trigger: 'item', formatter: (p) => `${p.name}<br><b>${brl(p.value)}</b> (${p.percent}%)`},
                legend: {...base.legend, type: 'scroll', top: 'bottom'},
                series: [{type: 'pie', radius: ['45%', '70%'], center: ['50%', '42%'], data: top(d.type, 8),
                    itemStyle: {borderColor: surface, borderWidth: 2, borderRadius: 4}, label: {show: false}, color: palette}],
            });
        } else {
            empty('rb-c-type');
        }
        const bars = (id, list, color) => {
            if (!list.length) { empty(id); return; }
            const data = top(list, 12);
            mk(id, {
                tooltip: {...base.tooltip, trigger: 'item'},
                grid: {left: 10, right: 70, top: 6, bottom: 6, containLabel: true},
                xAxis: {type: 'value', ...axis, axisLabel: {color: muted, formatter: short}},
                yAxis: {type: 'category', inverse: true, data: data.map((x) => x.name), ...axis, axisLabel: {color: muted, width: 170, overflow: 'truncate'}},
                series: [{type: 'bar', data: data.map((x) => x.value), itemStyle: {color, borderRadius: [0, 4, 4, 0]}, barMaxWidth: 22,
                    label: {show: true, position: 'right', color: text, formatter: (p) => short(p.value)}}],
            });
        };
        bars('rb-c-user', d.user, '#3b82f6');
        bars('rb-c-centro', d.centro, '#22d3ee');
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
})();
