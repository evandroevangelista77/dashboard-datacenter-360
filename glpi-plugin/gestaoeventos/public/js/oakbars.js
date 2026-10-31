/**
 * Barras horizontais (o mesmo modelo nos plugins RH, Reembolso, PMO e Ciclo, vindo do
 * "Tempo médio por fase × SLO"): nomes alinhados à esquerda numa coluna fixa, barra
 * arredondada em degradê, trilho de fundo opcional (meta, SLO, total), valor colorido
 * à direita e dica ao passar o mouse. Clicar na barra ou no nome chama onClick(i),
 * que normalmente abre o detalhe no painel lateral (drawer.js).
 *
 * OakBars.render(node, {
 *   items: [{name, value, track?, color?}],   // color: 'green', 'red', 'blue'… ou #hex
 *   format: (v) => texto,                      // valor no rótulo e na dica
 *   label: (it, i) => texto rich do ECharts,   // opcional; padrão "valor / trilho" ou "valor  %"
 *   tip: (it, i) => html,                      // opcional
 *   legend: [{name, color}],                   // opcional
 *   labelWidth: 220, axisName: 'dias', onClick: (it, i) => {}
 * }) → instância do ECharts (ou null quando não há itens)
 */
(() => {
    if (window.OakBars) return;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const PAIRS = {
        green: ['#16a34a', '#4ade80'], red: ['#dc2626', '#f87171'], blue: ['#2563eb', '#60a5fa'], cyan: ['#0891b2', '#22d3ee'],
        amber: ['#d97706', '#fbbf24'], orange: ['#ea580c', '#fb923c'], purple: ['#7c3aed', '#a78bfa'], pink: ['#db2777', '#f472b6'],
        teal: ['#0d9488', '#2dd4bf'], slate: ['#475569', '#94a3b8'],
    };
    const pair = (c) => PAIRS[c] || (/^#[0-9a-f]{6}$/i.test(c || '') ? [c + 'b3', c] : PAIRS.blue);
    const instances = new Set();

    function theme() {
        const cs = getComputedStyle(document.body);
        return {
            text: cs.getPropertyValue('--tblr-body-color').trim() || '#cbd5e1',
            muted: cs.getPropertyValue('--tblr-secondary-color').trim() || '#94a3b8',
            border: cs.getPropertyValue('--tblr-border-color').trim() || '#334155',
            surface: cs.getPropertyValue('--tblr-bg-surface').trim() || '#0f172a',
        };
    }

    /** Dica: título, linhas com marcador colorido e um rodapé opcional. */
    function tipHtml(title, rows, foot = '') {
        const t = theme();
        return `<div style="font-weight:700;font-size:13px;margin-bottom:6px">${esc(title)}</div>`
            + rows.map(([c, l, v]) => `<div style="display:flex;gap:18px;justify-content:space-between;align-items:center;margin:2px 0"><span style="display:inline-block;width:9px;height:9px;border-radius:3px;background:${c || 'transparent'};margin-right:7px"></span><span style="flex:1;color:${t.muted}">${l}</span><b>${v}</b></div>`).join('')
            + (foot ? `<div style="margin-top:6px">${foot}</div>` : '');
    }

    function render(node, cfg) {
        if (!node || !window.echarts) return null;
        const items = cfg.items || [];
        echarts.getInstanceByDom(node)?.dispose();
        if (!items.length) {
            node.style.height = '';
            node.innerHTML = `<div class="oak-bars-empty"><i class="ti ti-chart-bar-off"></i>${esc(cfg.empty || 'Sem dados no período')}</div>`;
            return null;
        }
        const t = theme();
        const fmt = cfg.format || ((v) => String(v).replace('.', ','));
        const hasTrack = items.some((x) => x.track);
        const total = items.reduce((a, x) => a + (Number(x.value) || 0), 0);
        const W = cfg.labelWidth || 220;
        const top = cfg.legend ? 42 : 8;
        node.style.height = `${items.length * 39 + top + (cfg.axisName ? 34 : 26)}px`;
        node.innerHTML = '';
        const grad = (c) => new echarts.graphic.LinearGradient(0, 0, 1, 0, [{offset: 0, color: pair(c)[0]}, {offset: 1, color: pair(c)[1]}]);
        const label = cfg.label || ((x) => (hasTrack ? `{v|${fmt(x.value)}}{m| / ${x.track ? fmt(x.track) : '—'}}` : `{v|${fmt(x.value)}}{m|  ${total ? Math.round(100 * x.value / total) : 0}%}`));
        const rich = {m: {color: t.muted, fontSize: 12}};
        items.forEach((x, i) => { rich[`v${i}`] = {color: pair(x.color)[1], fontWeight: 700, fontSize: 13}; });

        const c = echarts.init(node);
        c.setOption({
            backgroundColor: 'transparent', textStyle: {color: t.text, fontFamily: 'inherit'}, animationDuration: 700, animationEasing: 'cubicOut',
            grid: {left: W, right: cfg.right || 110, top, bottom: cfg.axisName ? 30 : 22},
            legend: cfg.legend ? {top: 0, right: 0, itemWidth: 16, itemHeight: 10, itemGap: 18, textStyle: {color: t.muted, fontSize: 13}, selectedMode: false,
                data: cfg.legend.map((l) => ({name: l.name, itemStyle: {color: l.color}}))} : undefined,
            tooltip: {trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.06)'}},
                backgroundColor: t.surface, borderColor: t.border, borderWidth: 1, padding: [10, 14], textStyle: {color: t.text, fontSize: 13},
                extraCssText: 'border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.45);max-width:340px;white-space:normal;',
                formatter: (ps) => {
                    const i = ps[0].dataIndex;
                    const x = items[i];
                    const body = cfg.tip ? cfg.tip(x, i) : tipHtml(x.name, [[pair(x.color)[1], 'Valor', fmt(x.value)], ...(x.track ? [['rgba(148,163,184,.5)', 'Referência', fmt(x.track)]] : []),
                        ...(!hasTrack && total ? [['', 'Do total', Math.round(100 * x.value / total) + '%']] : [])]);
                    return body + (cfg.onClick ? `<div style="margin-top:8px;padding-top:6px;border-top:1px solid ${t.border};color:${t.muted};font-size:11.5px">Clique para ver os detalhes</div>` : '');
                }},
            xAxis: {type: 'value', minInterval: cfg.minInterval, axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: t.muted, fontSize: 12, formatter: cfg.axisFormat, hideOverlap: true}, splitNumber: cfg.splitNumber || 4,
                splitLine: {lineStyle: {color: t.border, opacity: .3, type: 'dashed'}},
                ...(cfg.axisName ? {name: cfg.axisName, nameLocation: 'middle', nameGap: 24, nameTextStyle: {color: t.muted, fontSize: 12}} : {})},
            yAxis: {type: 'category', inverse: true, data: items.map((x) => x.name), axisLine: {show: false}, axisTick: {show: false}, triggerEvent: !!cfg.onClick,
                axisLabel: {color: t.text, fontSize: 14, align: 'left', margin: W, width: W - 16, overflow: 'truncate'}},
            // trilho e barra com a mesma espessura e sobrepostos (barGap -100%): o trilho aparece no que falta
            series: [
                ...(hasTrack ? [{type: 'bar', barWidth: 16, barGap: '-100%', silent: true, z: 1, data: items.map((x) => x.track || null),
                    itemStyle: {color: 'rgba(148,163,184,.16)', borderColor: 'rgba(148,163,184,.4)', borderWidth: 1, borderRadius: 8}}] : []),
                {type: 'bar', barWidth: 16, barGap: '-100%', z: 3, cursor: cfg.onClick ? 'pointer' : 'default',
                    showBackground: !hasTrack, backgroundStyle: {color: 'rgba(148,163,184,.07)', borderRadius: 8},
                    data: items.map((x) => ({value: x.value || null, itemStyle: {color: grad(x.color), borderRadius: 8}}))},
                {type: 'scatter', symbolSize: 0, silent: true, z: 4, data: items.map((x, i) => [Math.max(Number(x.value) || 0, Number(x.track) || 0), i]),
                    label: {show: true, position: 'right', distance: 10, fontSize: 13, formatter: (p) => label(items[p.dataIndex], p.dataIndex).replace(/\{v\|/g, `{v${p.dataIndex}|`), rich}},
            ],
        });
        if (cfg.onClick) {
            c.on('click', (p) => {
                const i = p.componentType === 'yAxis' ? items.findIndex((x) => x.name === p.value) : p.dataIndex;
                if (i >= 0) cfg.onClick(items[i], i);
            });
        }
        instances.add(c);
        return c;
    }

    window.addEventListener('resize', () => instances.forEach((c) => (c.isDisposed() ? instances.delete(c) : c.resize())));
    window.OakBars = {render, tipHtml, pair, esc};
})();
