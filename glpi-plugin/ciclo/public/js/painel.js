/**
 * Ciclo Financeiro — painel: gráficos (ECharts do GLPI) com detalhes ao passar o
 * mouse e o painel lateral com o resumo de cada cartão.
 */
(() => {
    const el = document.getElementById('cf-data');
    if (!el) return;
    const d = JSON.parse(el.textContent);
    const charts = [];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = (v) => String(v).replace('.', ',');

    // ---------------------------------------------------------------- painel lateral
    const drawer = document.querySelector('.cf-drawer');
    const backdrop = document.querySelector('.cf-drawer-backdrop');
    function openDrawer(key) {
        const tpl = document.getElementById(`cf-dr-${key}`);
        if (!tpl || !drawer) return;
        drawer.style.setProperty('--c', tpl.dataset.color);
        drawer.querySelector('h3').textContent = tpl.dataset.title;
        drawer.querySelector('.cf-drawer-icon i').className = `ti ${tpl.dataset.icon}`;
        const body = drawer.querySelector('.cf-drawer-body');
        body.innerHTML = '';
        body.appendChild(tpl.content.cloneNode(true));
        body.scrollTop = 0;
        backdrop.hidden = false;
        requestAnimationFrame(() => { backdrop.classList.add('on'); drawer.classList.add('on'); });
        drawer.setAttribute('aria-hidden', 'false');
        drawer.querySelector('.btn-close').focus();
    }
    /** Abre o painel com um conteúdo montado aqui (detalhe de uma barra do gráfico). */
    function openHtml({title, icon, color, html}) {
        if (!drawer) return;
        drawer.style.setProperty('--c', color);
        drawer.querySelector('h3').textContent = title;
        drawer.querySelector('.cf-drawer-icon i').className = `ti ${icon}`;
        const body = drawer.querySelector('.cf-drawer-body');
        body.innerHTML = html;
        body.scrollTop = 0;
        backdrop.hidden = false;
        requestAnimationFrame(() => { backdrop.classList.add('on'); drawer.classList.add('on'); });
        drawer.setAttribute('aria-hidden', 'false');
        drawer.querySelector('.btn-close').focus();
    }
    const ciclos = (list, val, sub) => `<div class="cf-dr-list">${list.map((c) => `<div class="cf-dr-item ${c.cls}"><div class="cf-dr-row"><a href="${esc(c.url || '#')}"><b>${esc(c.code)}</b></a>`
        + `<span class="cf-dr-name">${esc(c.name)}</span><span class="cf-dr-val">${val(c)}</span></div>${sub(c) ? `<div class="cf-dr-sub">${sub(c)}</div>` : ''}</div>`).join('') || '<div class="cf-dr-empty">Nenhum Ciclo.</div>'}</div>`;
    function closeDrawer() {
        drawer.classList.remove('on');
        backdrop.classList.remove('on');
        drawer.setAttribute('aria-hidden', 'true');
        setTimeout(() => { backdrop.hidden = true; }, 250);
    }
    document.querySelectorAll('[data-drawer]').forEach((b) => b.addEventListener('click', () => openDrawer(b.dataset.drawer)));
    drawer?.querySelector('.btn-close').addEventListener('click', closeDrawer);
    backdrop?.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && drawer?.classList.contains('on')) closeDrawer(); });

    // ---------------------------------------------------------------- gráficos
    function draw() {
        if (!window.echarts) return;
        const cs = getComputedStyle(document.body);
        const text = cs.getPropertyValue('--tblr-body-color').trim() || '#cbd5e1';
        const muted = cs.getPropertyValue('--tblr-secondary-color').trim() || '#94a3b8';
        const border = cs.getPropertyValue('--tblr-border-color').trim() || '#334155';
        const surface = cs.getPropertyValue('--tblr-bg-surface').trim() || '#0f172a';
        const tip = {backgroundColor: surface, borderColor: border, borderWidth: 1, padding: [10, 14], textStyle: {color: text, fontSize: 13},
            extraCssText: 'border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.45);max-width:340px;white-space:normal;'};
        const dot = (c) => `<span style="display:inline-block;width:9px;height:9px;border-radius:3px;background:${c};margin-right:7px"></span>`;
        const row = (c, l, v) => `<div style="display:flex;gap:18px;justify-content:space-between;align-items:center;margin:2px 0">${dot(c)}<span style="flex:1;color:${muted}">${l}</span><b>${v}</b></div>`;
        const head = (t) => `<div style="font-weight:700;font-size:13px;margin-bottom:6px">${esc(t)}</div>`;
        const axis = {axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 13}, splitLine: {lineStyle: {color: border, opacity: .3, type: 'dashed'}}};
        // nomes das fases alinhados à esquerda, numa coluna de largura fixa
        const LABEL_W = 250;
        const catAxis = {type: 'category', inverse: true, axisLine: {show: false}, axisTick: {show: false},
            axisLabel: {color: text, fontSize: 14, align: 'left', margin: LABEL_W, width: LABEL_W - 16, overflow: 'truncate'}};
        const legend = {top: 0, right: 0, itemWidth: 16, itemHeight: 10, itemGap: 18, textStyle: {color: muted, fontSize: 13}};
        const mk = (id, option, has, empty) => {
            const node = document.getElementById(id);
            if (!node) return;
            if (!has) { node.innerHTML = `<div class="cf-empty"><i class="ti ti-chart-dots-3"></i>${empty}</div>`; return; }
            const c = echarts.init(node);
            c.setOption({backgroundColor: 'transparent', textStyle: {color: text, fontFamily: 'inherit'}, animationDuration: 700, animationEasing: 'cubicOut', ...option});
            charts.push(c);
            return c;
        };
        const grad = (a, b) => new echarts.graphic.LinearGradient(0, 0, 1, 0, [{offset: 0, color: a}, {offset: 1, color: b}]);

        // 1) tempo médio × SLO: faixa clara = SLO; barra por cima = tempo médio (verde no prazo, vermelho acima)
        const p = d.phase;
        const phaseChart = mk('cf-c-phase', {
            grid: {left: LABEL_W, right: 96, top: 42, bottom: 28},
            legend: {...legend,
                data: [{name: 'No prazo', itemStyle: {color: '#22c55e'}}, {name: 'Acima do SLO', itemStyle: {color: '#ef4444'}}, {name: 'SLO (prazo)', itemStyle: {color: 'rgba(148,163,184,.25)'}}]},
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.06)'}}, formatter: (ps) => {
                const i = ps[0].dataIndex;
                const over = p.slo[i] && p.avg[i] > p.slo[i];
                return head(p.names[i]) + row(over ? '#ef4444' : '#22c55e', 'Tempo médio', p.count[i] ? `${fmt(p.avg[i])} dia(s)` : 'sem fases concluídas')
                    + row('rgba(148,163,184,.5)', 'SLO', p.slo[i] ? `${p.slo[i]} dia(s)` : 'sem SLO')
                    + row('transparent', 'Fases medidas', p.count[i]) + (p.hit[i] != null ? row('transparent', 'Concluídas no SLO', `${p.hit[i]}%`) : '')
                    + (over ? `<div style="margin-top:6px;color:#ef4444;font-weight:700">${fmt((p.avg[i] - p.slo[i]).toFixed(1))} dia(s) acima do prazo</div>` : '')
                    + `<div style="margin-top:8px;padding-top:6px;border-top:1px solid ${border};color:${muted};font-size:11.5px">Clique para ver os Ciclos</div>`;
            }},
            xAxis: {type: 'value', ...axis, name: 'dias', nameLocation: 'middle', nameGap: 24, nameTextStyle: {color: muted, fontSize: 12}},
            yAxis: {...catAxis, data: p.names, triggerEvent: true},
            // as três barras com a mesma espessura e sobrepostas (barGap -100%): a faixa do SLO fica atrás e aparece no que falta até o prazo
            series: [
                {name: 'SLO (prazo)', type: 'bar', barWidth: 16, barGap: '-100%', silent: true, z: 1, data: p.slo.map((v) => v || null),
                    itemStyle: {color: 'rgba(148,163,184,.16)', borderColor: 'rgba(148,163,184,.4)', borderWidth: 1, borderRadius: 8}},
                {name: 'No prazo', type: 'bar', barWidth: 16, barGap: '-100%', z: 3, cursor: 'pointer', data: p.avg.map((v, i) => (p.slo[i] && v > p.slo[i] ? null : (v || null))),
                    itemStyle: {color: grad('#16a34a', '#4ade80'), borderRadius: 8}},
                {name: 'Acima do SLO', type: 'bar', barWidth: 16, barGap: '-100%', z: 3, cursor: 'pointer', data: p.avg.map((v, i) => (p.slo[i] && v > p.slo[i] ? v : null)),
                    itemStyle: {color: grad('#dc2626', '#f87171'), borderRadius: 8}},
                {type: 'scatter', symbolSize: 0, silent: true, z: 4, data: p.avg.map((v, i) => [Math.max(v, p.slo[i] || 0), i]),
                    label: {show: true, position: 'right', distance: 10, fontSize: 13, formatter: (x) => {
                        const i = x.dataIndex;
                        if (!p.count[i]) return '{m|sem dados}';
                        const over = p.slo[i] && p.avg[i] > p.slo[i];
                        return `{${over ? 'r' : 'g'}|${fmt(p.avg[i])}d}{m| / ${p.slo[i] || '—'}}`;
                    }, rich: {g: {color: '#22c55e', fontWeight: 700, fontSize: 13}, r: {color: '#ef4444', fontWeight: 700, fontSize: 13}, m: {color: muted, fontSize: 12}}}},
            ],
        }, p.count.some((v) => v), 'O tempo de cada fase aparece quando os primeiros Ciclos concluírem fases.');
        phaseChart?.on('click', (x) => {
            const i = x.componentType === 'yAxis' ? p.names.indexOf(x.value) : x.dataIndex;
            if (i < 0) return;
            const list = [...(p.list[i] || [])].sort((a, b) => b.days - a.days).map((c) => ({...c, cls: c.late ? 'bad' : 'good'}));
            const over = p.slo[i] && p.avg[i] > p.slo[i];
            openHtml({title: p.names[i], icon: 'ti-hourglass', color: over ? '#ef4444' : '#22c55e',
                html: `<div class="cf-dr-stats"><div><small>Tempo médio</small><b>${fmt(p.avg[i])} d</b></div><div><small>SLO</small><b>${p.slo[i] || '—'} d</b></div>`
                    + `<div class="${p.hit[i] !== null && p.hit[i] < 80 ? 'bad' : ''}"><small>No SLO</small><b>${p.hit[i] ?? '—'}${p.hit[i] !== null ? '%' : ''}</b></div></div>`
                    + `<p class="cf-dr-lead">${list.length} fase(s) concluída(s) no período, da mais demorada para a mais rápida.</p>`
                    + ciclos(list, (c) => `${fmt(c.days)}d${c.slo ? ` <small class="text-secondary">/ ${c.slo}</small>` : ''}`,
                        (c) => `concluída em ${esc(c.end.split('-').reverse().join('/'))}${c.late ? ' · <b class="text-danger">além do SLO</b>' : ''}`)});
        });

        // 3) ciclo médio por mês × meta: linha com área, barras com a quantidade e a meta tracejada
        const m = d.month;
        const monthChart = mk('cf-c-month', {
            grid: {left: 8, right: 16, top: 46, bottom: 4, containLabel: true},
            legend,
            tooltip: {...tip, trigger: 'axis', formatter: (ps) => {
                const i = ps[0].dataIndex;
                const avg = m.avg[i];
                return head(m.labels[i]) + row('#14b8a6', 'Ciclo médio', avg != null ? `${avg} dias` : '—') + row('#f59e0b', 'Meta', `${m.meta} dias`)
                    + row('rgba(100,116,139,.8)', 'Ciclos concluídos', m.count[i])
                    + (m.list[i].length ? `<div style="margin-top:6px;padding-top:6px;border-top:1px solid ${border}">` + m.list[i].map((c) => `<div style="display:flex;justify-content:space-between;gap:10px"><span><b>${esc(c.code)}</b> <span style="color:${muted}">${esc(c.name)}</span></span><b style="color:${c.days > m.meta ? '#f97316' : '#22c55e'}">${c.days}d</b></div>`).join('') + '</div>' : '');
            }},
            xAxis: {type: 'category', data: m.labels, axisLine: {lineStyle: {color: border}}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 13}},
            yAxis: [
                {type: 'value', name: 'dias', nameTextStyle: {color: muted, fontSize: 12}, ...axis, max: (v) => Math.ceil(Math.max(v.max, m.meta) * 1.15 / 10) * 10},
                {type: 'value', minInterval: 1, max: (v) => Math.max(3, v.max + 1), ...axis, splitLine: {show: false}, axisLabel: {show: false}},
            ],
            series: [
                {name: 'Ciclos concluídos', type: 'bar', yAxisIndex: 1, data: m.count.map((v) => v || null), barWidth: 22, itemStyle: {color: 'rgba(100,116,139,.35)', borderRadius: [6, 6, 0, 0]},
                    label: {show: true, position: 'insideBottom', color: muted, fontSize: 12, formatter: (x) => (x.value ? `${x.value} ciclo${x.value > 1 ? 's' : ''}` : '')}},
                {name: 'Ciclo médio (dias)', type: 'line', data: m.avg, connectNulls: true, smooth: .3, symbol: 'circle', symbolSize: 10, z: 5,
                    lineStyle: {color: '#14b8a6', width: 3}, itemStyle: {color: '#14b8a6', borderColor: surface, borderWidth: 2},
                    areaStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: 'rgba(20,184,166,.28)'}, {offset: 1, color: 'rgba(20,184,166,0)'}])},
                    label: {show: true, position: 'top', distance: 10, color: text, fontWeight: 700, fontSize: 14, formatter: (x) => (x.value != null ? `${x.value}d` : '')},
                    markLine: {symbol: 'none', silent: true, lineStyle: {color: '#f59e0b', type: 'dashed', width: 1.5},
                        label: {color: '#f59e0b', formatter: `meta ${m.meta} dias`, position: 'insideEndTop', fontWeight: 700, fontSize: 13}, data: [{yAxis: m.meta}]}},
            ],
        }, m.count.some((v) => v), 'O ciclo médio aparece quando o primeiro Ciclo for concluído.');
        monthChart?.on('click', (x) => {
            const i = x.dataIndex;
            const list = (m.list[i] || []).map((c) => ({...c, cls: c.days > m.meta ? 'warn' : 'good'}));
            openHtml({title: `Ciclos concluídos em ${m.labels[i]}`, icon: 'ti-calendar-check', color: '#14b8a6',
                html: `<div class="cf-dr-stats"><div><small>Concluídos</small><b>${m.count[i]}</b></div><div><small>Ciclo médio</small><b>${m.avg[i] ?? '—'} d</b></div><div><small>Meta</small><b>${m.meta} d</b></div></div>`
                    + ciclos(list, (c) => `${c.days}d`, (c) => (c.days > m.meta ? `${c.days - m.meta} dia(s) acima da meta` : 'dentro da meta'))});
        });
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
})();
