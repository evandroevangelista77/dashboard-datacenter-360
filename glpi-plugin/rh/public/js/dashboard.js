/**
 * RH — gráficos do dashboard (ECharts do GLPI), com dicas detalhadas ao passar o mouse.
 */
(() => {
    const el = document.getElementById('rh-dash');
    if (!el) return;
    const d = JSON.parse(el.textContent);
    const charts = [];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

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
        const head = (t) => `<div style="font-weight:700;font-size:13px;margin-bottom:6px">${t}</div>`;
        const list = (items, max = 8) => (items.length ? `<div style="margin-top:8px;padding-top:6px;border-top:1px solid ${border};color:${muted};font-size:11.5px;line-height:1.5">`
            + items.slice(0, max).map((i) => `• ${esc(i)}`).join('<br>') + (items.length > max ? `<br>… e mais ${items.length - max}` : '') + '</div>' : '');
        const axis = {axisLine: {lineStyle: {color: border}}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 11}, splitLine: {lineStyle: {color: border, opacity: .35, type: 'dashed'}}};
        const grad = (c1, c2, horizontal = false) => new echarts.graphic.LinearGradient(0, horizontal ? 0 : 1, horizontal ? 1 : 0, 0, [{offset: 0, color: c2}, {offset: 1, color: c1}]);
        const mk = (id, option, has) => {
            const node = document.getElementById(id);
            if (!node) return;
            if (!has) { node.innerHTML = '<div class="rh-empty">Sem dados ainda</div>'; return; }
            const c = echarts.init(node);
            c.setOption({backgroundColor: 'transparent', textStyle: {color: text, fontFamily: 'inherit'}, animationDuration: 700, ...option});
            charts.push(c);
        };

        // ---------------------------------------------------------- contratações por mês
        const h = d.hires;
        mk('rh-c-hires', {
            legend: {top: 0, right: 0, textStyle: {color: muted}, icon: 'roundRect', itemWidth: 12, itemHeight: 8},
            grid: {left: 8, right: 8, top: 36, bottom: 6, containLabel: true},
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.08)'}},
                formatter: (ps) => {
                    const i = ps[0].dataIndex;
                    return head(h.titles[i].replace(/^./, (c) => c.toUpperCase()))
                        + row('#22c55e', 'Contratações', h.hires[i])
                        + row('#38bdf8', 'Novos candidatos', h.entries[i])
                        + (h.entries[i] ? row('transparent', 'Taxa (contratações / novos)', Math.round(100 * h.hires[i] / h.entries[i]) + '%') : '')
                        + list(h.names[i]);
                }},
            xAxis: {type: 'category', data: h.labels, ...axis, splitLine: {show: false}},
            yAxis: [{type: 'value', minInterval: 1, name: 'contratações', nameTextStyle: {color: muted, fontSize: 10}, ...axis},
                {type: 'value', minInterval: 1, name: 'candidatos', nameTextStyle: {color: muted, fontSize: 10}, ...axis, splitLine: {show: false}}],
            series: [
                {name: 'Contratações', type: 'bar', data: h.hires, barMaxWidth: 34,
                    itemStyle: {color: grad('#4ade80', '#15803d'), borderRadius: [6, 6, 0, 0]},
                    emphasis: {itemStyle: {color: grad('#86efac', '#16a34a')}},
                    label: {show: true, position: 'top', color: text, fontWeight: 700, formatter: (p) => (p.value ? p.value : '')},
                    markLine: {symbol: 'none', silent: true, lineStyle: {color: '#f59e0b', type: 'dashed', width: 1.5},
                        label: {color: '#f59e0b', formatter: `média ${String(h.avg).replace('.', ',')}/mês`, position: 'insideEndTop'}, data: [{yAxis: h.avg}]}},
                {name: 'Novos candidatos', type: 'line', yAxisIndex: 1, data: h.entries, smooth: true, symbol: 'circle', symbolSize: 7,
                    lineStyle: {color: '#38bdf8', width: 2.5}, itemStyle: {color: '#38bdf8', borderColor: surface, borderWidth: 2},
                    areaStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: 'rgba(56,189,248,.25)'}, {offset: 1, color: 'rgba(56,189,248,0)'}])}},
            ],
        }, h.hires.some((v) => v) || h.entries.some((v) => v));

        // ---------------------------------------------------------- vagas por situação
        const st = d.status;
        mk('rh-c-status', {
            grid: {left: 8, right: 70, top: 6, bottom: 6, containLabel: true},
            tooltip: {...tip, trigger: 'item', formatter: (p) => {
                const s = st[p.dataIndex];
                return head(`${dot(s.color)}${esc(s.name)}`) + row(s.color, 'Vagas', s.value) + row('transparent', 'Do total', s.pct + '%') + list(s.vagas);
            }},
            xAxis: {type: 'value', minInterval: 1, ...axis},
            yAxis: {type: 'category', inverse: true, data: st.map((s) => s.name), ...axis, axisLabel: {color: text, fontSize: 11.5}},
            series: [{type: 'bar', barMaxWidth: 18, showBackground: true, backgroundStyle: {color: 'rgba(148,163,184,.07)', borderRadius: 6},
                data: st.map((s) => ({value: s.value, itemStyle: {color: grad(s.color, s.color + '99', true), borderRadius: [0, 6, 6, 0]}})),
                label: {show: true, position: 'right', color: text, formatter: (p) => `{b|${p.value}}  {s|${st[p.dataIndex].pct}%}`,
                    rich: {b: {fontWeight: 700, color: text}, s: {color: muted, fontSize: 10.5}}}}],
        }, st.some((s) => s.value));

        // ---------------------------------------------------------- vagas abertas por departamento
        const dp = d.dept;
        const series = [['Posições em aberto', 'positions', '#3b82f6', '#1d4ed8'], ['Candidatos em processo', 'active', '#a855f7', '#6d28d9'], ['Contratados', 'hired', '#22c55e', '#15803d']];
        mk('rh-c-dept', {
            legend: {top: 0, right: 0, textStyle: {color: muted}, icon: 'roundRect', itemWidth: 12, itemHeight: 8},
            grid: {left: 8, right: 40, top: 34, bottom: 6, containLabel: true},
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.08)'}},
                formatter: (ps) => {
                    const x = dp[ps[0].dataIndex];
                    return head(esc(x.name)) + series.map(([n, k, c]) => row(c, n, x[k])).join('')
                        + list(x.vagas.map((v) => `${v.name}: ${plural(v.positions, 'posição', 'posições')}, ${v.active} em processo, ${plural(v.hired, 'contratado', 'contratados')}`), 6);
                }},
            xAxis: {type: 'value', minInterval: 1, ...axis},
            yAxis: {type: 'category', inverse: true, data: dp.map((x) => x.name), ...axis, axisLabel: {color: text, fontSize: 12, fontWeight: 600, width: 180, overflow: 'truncate'}},
            series: series.map(([n, k, c1, c2]) => ({name: n, type: 'bar', barMaxWidth: 14, barGap: '25%', data: dp.map((x) => x[k]),
                itemStyle: {color: grad(c1, c2, true), borderRadius: [0, 5, 5, 0]},
                label: {show: true, position: 'right', color: muted, fontSize: 10.5, formatter: (p) => (p.value ? p.value : '')}})),
        }, dp.length);
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
})();
