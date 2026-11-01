/** Pesquisa de Satisfação — painel do eNPS: distribuição das notas, evolução por campanha e eNPS por grupo (ECharts do GLPI). */
(() => {
    const el = document.getElementById('sf-enps-data');
    if (!el) return;
    const d = JSON.parse(el.textContent);
    const charts = [];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const sign = (v) => (v == null ? '—' : (v > 0 ? `+${v}` : String(v)));

    function draw() {
        if (!window.echarts) return;
        const cs = getComputedStyle(document.body);
        const text = cs.getPropertyValue('--tblr-body-color').trim() || '#cbd5e1';
        const muted = cs.getPropertyValue('--tblr-secondary-color').trim() || '#94a3b8';
        const border = cs.getPropertyValue('--tblr-border-color').trim() || '#334155';
        const surface = cs.getPropertyValue('--tblr-bg-surface').trim() || '#0f172a';
        const tip = {backgroundColor: surface, borderColor: border, borderWidth: 1, padding: [10, 14], textStyle: {color: text, fontSize: 13},
            extraCssText: 'border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.45);max-width:340px;white-space:normal;'};
        const row = (c, l, v) => `<div style="display:flex;gap:18px;justify-content:space-between;margin:2px 0"><span style="color:${muted}"><span style="display:inline-block;width:9px;height:9px;border-radius:3px;background:${c};margin-right:7px"></span>${l}</span><b>${v}</b></div>`;
        const head = (t) => `<div style="font-weight:700;font-size:13px;margin-bottom:6px">${esc(t)}</div>`;
        const axis = {axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 12}, splitLine: {lineStyle: {color: border, opacity: .3, type: 'dashed'}}};
        const mk = (id, option, has) => {
            const node = document.getElementById(id);
            if (!node || !has) return null;
            const c = echarts.init(node);
            c.setOption({backgroundColor: 'transparent', textStyle: {color: text, fontFamily: 'inherit'}, animationDuration: 700, animationEasing: 'cubicOut', ...option});
            charts.push(c);
            return c;
        };
        const kindColor = (s) => (s >= 9 ? '#22c55e' : (s >= 7 ? '#f59e0b' : '#ef4444'));
        const total = d.dist.reduce((a, b) => a + b, 0);

        mk('sf-e-dist', {
            grid: {left: 6, right: 6, top: 22, bottom: 24},
            tooltip: {...tip, trigger: 'item', formatter: (p) => head(`Nota ${p.dataIndex}`) + row(p.color, 'Respostas', p.value) + row('transparent', 'Do total', `${total ? (100 * p.value / total).toFixed(1).replace('.', ',') : 0}%`)},
            xAxis: {type: 'category', data: d.dist.map((_, i) => i), axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 13}},
            yAxis: {type: 'value', show: false, max: (v) => Math.max(1, v.max * 1.2)},
            series: [{type: 'bar', barWidth: '62%', data: d.dist.map((v, i) => ({value: v, itemStyle: {color: kindColor(i), borderRadius: [5, 5, 0, 0]}})),
                label: {show: true, position: 'top', color: text, fontWeight: 700, fontSize: 12, formatter: (p) => (p.value ? p.value : '')}}],
        }, total > 0);

        const c = d.campaigns;
        mk('sf-e-evo', {
            grid: {left: 8, right: 8, top: 40, bottom: 4, containLabel: true},
            legend: {top: 0, left: 0, itemWidth: 12, itemHeight: 8, icon: 'roundRect', textStyle: {color: muted, fontSize: 12}},
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.06)'}}, formatter: (ps) => {
                const x = c[ps[0].dataIndex];
                return head(x.name) + row('#22c55e', 'Promotores', `${x.promoter} (${String(x.pPromoter).replace('.', ',')}%)`) + row('#f59e0b', 'Neutros', `${x.passive} (${String(x.pPassive).replace('.', ',')}%)`)
                    + row('#ef4444', 'Detratores', `${x.detractor} (${String(x.pDetractor).replace('.', ',')}%)`) + row('#6366f1', 'eNPS', sign(x.enps)) + row('transparent', 'Participação', `${x.answered}/${x.sent}`);
            }},
            xAxis: {type: 'category', data: c.map((x) => x.name), axisLine: {lineStyle: {color: border}}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 12, width: 110, overflow: 'truncate'}},
            yAxis: [{type: 'value', minInterval: 1, ...axis}, {type: 'value', min: -100, max: 100, show: false}],
            series: [
                {name: 'Promotores', type: 'bar', stack: 'e', barWidth: 28, data: c.map((x) => x.promoter || null), itemStyle: {color: '#22c55e'}},
                {name: 'Neutros', type: 'bar', stack: 'e', data: c.map((x) => x.passive || null), itemStyle: {color: '#f59e0b'}},
                {name: 'Detratores', type: 'bar', stack: 'e', data: c.map((x) => x.detractor || null), itemStyle: {color: '#ef4444', borderRadius: [5, 5, 0, 0]}},
                {name: 'eNPS', type: 'line', yAxisIndex: 1, data: c.map((x) => x.enps), smooth: .3, symbol: 'circle', symbolSize: 10, z: 6, lineStyle: {color: '#6366f1', width: 3},
                    itemStyle: {color: '#6366f1', borderColor: surface, borderWidth: 2},
                    label: {show: true, position: 'top', distance: 8, color: text, fontWeight: 700, fontSize: 12, formatter: (p) => sign(p.value)}},
            ],
        }, c.length > 0);

        const g = d.groups;
        mk('sf-e-grp', {
            grid: {left: 8, right: 60, top: 6, bottom: 22, containLabel: true},
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.06)'}}, formatter: (ps) => {
                const x = g[ps[0].dataIndex];
                return head(x.label) + row('#6366f1', 'eNPS', sign(x.enps)) + row('transparent', 'Respostas', x.n) + row('#22c55e', 'Promotores', `${String(x.pPromoter).replace('.', ',')}%`)
                    + row('#ef4444', 'Detratores', `${String(x.pDetractor).replace('.', ',')}%`);
            }},
            xAxis: {type: 'value', min: -100, max: 100, ...axis},
            yAxis: {type: 'category', inverse: true, data: g.map((x) => x.label), axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: text, fontSize: 13, width: 180, overflow: 'truncate'}},
            series: [{type: 'bar', barWidth: 16, data: g.map((x) => ({value: x.enps, itemStyle: {color: x.enps >= 50 ? '#22c55e' : (x.enps >= 0 ? '#f59e0b' : '#ef4444'), borderRadius: 8}})),
                label: {show: true, position: 'right', color: text, fontWeight: 700, fontSize: 12, formatter: (p) => `${sign(p.value)} · ${g[p.dataIndex].n}`}}],
        }, g.length > 0);
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
    window.addEventListener('resize', () => charts.forEach((x) => x.resize()));
})();
