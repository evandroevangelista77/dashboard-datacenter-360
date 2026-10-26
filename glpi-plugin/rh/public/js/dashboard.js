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
            return c;
        };

        // ---------------------------------------------------------- contratações por mês
        const h = d.hires;
        const hiresChart = mk('rh-c-hires', {
            legend: {top: 0, right: 0, textStyle: {color: muted}, icon: 'roundRect', itemWidth: 12, itemHeight: 8},
            grid: {left: 8, right: 8, top: 36, bottom: 6, containLabel: true},
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.08)'}},
                formatter: (ps) => {
                    const i = ps[0].dataIndex;
                    return head(h.titles[i].replace(/^./, (c) => c.toUpperCase()))
                        + row('#22c55e', 'Contratações', h.hires[i])
                        + row('#38bdf8', 'Novos candidatos', h.entries[i])
                        + (h.entries[i] ? row('transparent', 'Taxa (contratações / novos)', Math.round(100 * h.hires[i] / h.entries[i]) + '%') : '')
                        + list(h.names[i]) + `<div style="margin-top:6px;color:${muted};font-size:11.5px">Clique para ver os detalhes</div>`;
                }},
            xAxis: {type: 'category', data: h.labels, ...axis, splitLine: {show: false}},
            yAxis: [{type: 'value', minInterval: 1, name: 'contratações', nameTextStyle: {color: muted, fontSize: 10}, ...axis},
                {type: 'value', minInterval: 1, name: 'candidatos', nameTextStyle: {color: muted, fontSize: 10}, ...axis, splitLine: {show: false}}],
            series: [
                {name: 'Contratações', type: 'bar', data: h.hires, barMaxWidth: 34, cursor: 'pointer',
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

        // ---------------------------------------------------------- vagas por situação e por departamento: barras do modelo comum (oakbars.js)
        const st = d.status.filter((s) => s.value);
        OakBars.render(document.getElementById('rh-c-status'), {
            items: st, labelWidth: 210, right: 90, minInterval: 1, empty: 'Sem vagas ainda',
            tip: (s) => OakBars.tipHtml(s.name, [[s.color, 'Vagas', s.value], ['', 'Do total', s.pct + '%']]),
            onClick: (s) => vagas(s.name, s.color, `${plural(s.value, 'vaga', 'vagas')} nesta situação.`, s.vagas.map((v) => ({...v, val: plural(v.positions, 'posição', 'posições'), sub: v.dept}))),
        });
        const dp = d.dept;
        OakBars.render(document.getElementById('rh-c-dept'), {
            items: dp.map((x) => ({...x, value: x.hired, track: x.positions + x.hired, color: 'green'})),
            labelWidth: 260, right: 220, minInterval: 1, empty: 'Nenhuma vaga aberta',
            legend: [{name: 'Contratados', color: '#22c55e'}, {name: 'Posições da vaga', color: 'rgba(148,163,184,.3)'}],
            label: (x) => `{v|${x.hired}}{m| / ${x.track} contratado(s) · ${x.active} em processo}`,
            tip: (x) => OakBars.tipHtml(x.name, [['#22c55e', 'Contratados', x.hired], ['rgba(148,163,184,.5)', 'Posições em aberto', x.positions], ['#a855f7', 'Candidatos em processo', x.active],
                ['', 'Vagas abertas', x.vagas.length]]),
            onClick: (x) => vagas(x.name, '#22c55e', `${plural(x.vagas.length, 'vaga aberta', 'vagas abertas')} · ${plural(x.positions, 'posição', 'posições')} a preencher.`,
                x.vagas.map((v) => ({...v, val: `${v.hired}/${v.positions}`, sub: `${plural(v.active, 'candidato', 'candidatos')} em processo · ${plural(v.hired, 'contratado', 'contratados')} de ${plural(v.positions, 'posição', 'posições')}`,
                    pct: v.positions ? Math.round(100 * v.hired / v.positions) : 0}))),
        });
        hiresChart?.on('click', (p) => {
            const i = p.dataIndex;
            const names = h.names[i] || [];
            window.OakDrawer?.open({title: h.titles[i].replace(/^./, (c) => c.toUpperCase()), icon: 'ti-user-plus', color: '#22c55e',
                html: `<div class="oak-dr-stats"><div style="--sc:#22c55e"><small>Contratações</small><b>${h.hires[i]}</b></div><div style="--sc:#38bdf8"><small>Novos candidatos</small><b>${h.entries[i]}</b></div></div>`
                    + `<div class="oak-dr-list">${names.map((n) => `<div class="oak-dr-item good"><div class="oak-dr-row"><span class="oak-dr-name">${esc(n)}</span></div></div>`).join('') || '<div class="oak-dr-empty">Nenhuma contratação no mês.</div>'}</div>`});
        });
    }

    // ---------------------------------------------------------- lista de vagas no painel lateral
    function vagas(title, color, lead, list) {
        window.OakDrawer?.open({title, icon: 'ti-briefcase-2', color,
            html: `<p class="oak-dr-lead">${esc(lead)}</p><div class="oak-dr-list">` + (list.map((v) => `<div class="oak-dr-item" style="--oc:${color}"><div class="oak-dr-row">`
                + `<a href="${esc(v.url)}"><b>${esc(v.code)}</b></a><span class="oak-dr-name">${esc(v.name)}</span><span class="oak-dr-val">${esc(v.val)}</span></div>`
                + `<div class="oak-dr-sub">${esc(v.sub)}</div>${v.pct !== undefined ? `<div class="oak-dr-bar"><i style="width:${v.pct}%"></i></div>` : ''}</div>`).join('') || '<div class="oak-dr-empty">Nenhuma vaga.</div>') + '</div>'});
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
})();
