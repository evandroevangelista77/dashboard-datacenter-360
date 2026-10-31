/**
 * Controle de Equipamentos — painel no padrão do Ciclo Financeiro: gráficos do ECharts do GLPI,
 * detalhes ao passar o mouse e um painel lateral com o resumo de cada cartão ou barra.
 */
(() => {
    const el = document.getElementById('cf-data');
    if (!el) return;
    const d = JSON.parse(el.textContent);
    const charts = [];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = (v) => String(v).replace('.', ',');

    // ------------------------------------------------------------ painel lateral
    const drawer = document.querySelector('.cf-drawer');
    const backdrop = document.querySelector('.cf-drawer-backdrop');
    function show(title, icon, color, node) {
        if (!drawer) return;
        drawer.style.setProperty('--c', color);
        drawer.querySelector('h3').textContent = title;
        drawer.querySelector('.cf-drawer-icon i').className = `ti ${icon}`;
        const body = drawer.querySelector('.cf-drawer-body');
        body.innerHTML = '';
        body.appendChild(node);
        body.scrollTop = 0;
        backdrop.hidden = false;
        requestAnimationFrame(() => { backdrop.classList.add('on'); drawer.classList.add('on'); });
        drawer.setAttribute('aria-hidden', 'false');
        drawer.querySelector('.btn-close').focus();
    }
    function openDrawer(key) {
        const tpl = document.getElementById(`cf-dr-${key}`);
        if (tpl) show(tpl.dataset.title, tpl.dataset.icon, tpl.dataset.color, tpl.content.cloneNode(true));
    }
    function openHtml(title, icon, color, html) {
        const box = document.createElement('div');
        box.innerHTML = html;
        show(title, icon, color, box);
    }
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

    // ------------------------------------------------------------ gráficos
    function draw() {
        if (!window.echarts) return;
        const cs = getComputedStyle(document.body);
        const text = cs.getPropertyValue('--tblr-body-color').trim() || '#cbd5e1';
        const muted = cs.getPropertyValue('--tblr-secondary-color').trim() || '#94a3b8';
        const border = cs.getPropertyValue('--tblr-border-color').trim() || '#334155';
        const surface = cs.getPropertyValue('--tblr-bg-surface').trim() || '#0f172a';
        const tip = {backgroundColor: surface, borderColor: border, borderWidth: 1, padding: [10, 14], textStyle: {color: text, fontSize: 13},
            extraCssText: 'border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.45);max-width:340px;white-space:normal;'};
        const row = (c, l, v) => `<div style="display:flex;gap:18px;justify-content:space-between;align-items:center;margin:2px 0"><span style="display:inline-block;width:9px;height:9px;border-radius:3px;background:${c};margin-right:7px"></span><span style="flex:1;color:${muted}">${l}</span><b>${v}</b></div>`;
        const head = (t) => `<div style="font-weight:700;font-size:13px;margin-bottom:6px">${esc(t)}</div>`;
        const axis = {axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 14}, splitLine: {lineStyle: {color: border, opacity: .3, type: 'dashed'}}};
        const LABEL_W = 280;
        const grad = (a, b) => new echarts.graphic.LinearGradient(0, 0, 1, 0, [{offset: 0, color: a}, {offset: 1, color: b}]);
        const mk = (id, option, has, vazio) => {
            const node = document.getElementById(id);
            if (!node) return null;
            if (!has) { node.innerHTML = `<div class="cf-empty"><i class="ti ti-chart-dots-3"></i>${vazio}</div>`; return null; }
            const c = echarts.init(node);
            c.setOption({backgroundColor: 'transparent', textStyle: {color: text, fontFamily: 'inherit', fontSize: 14}, animationDuration: 700, animationEasing: 'cubicOut', ...option});
            charts.push(c);
            return c;
        };

        // 1) tempo médio por etapa × prazo
        const et = d.etapas.filter((x) => x.n > 0 || x.slo > 0);
        const etapa = mk('cf-c-etapa', {
            grid: {left: LABEL_W, right: 90, top: 24, bottom: 28},
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.06)'}}, formatter: (ps) => {
                const x = et[ps[0].dataIndex];
                const over = x.slo && x.media > x.slo;
                return head(`${x.step}. ${x.nome}`) + row(over ? '#ef4444' : '#22c55e', 'Média', x.media != null ? `${fmt(x.media)} dia(s)` : 'sem dados')
                    + row('rgba(148,163,184,.5)', 'Prazo', x.slo ? `${x.slo} dia(s)` : 'sem prazo')
                    + row('transparent', 'Etapas concluídas', x.n)
                    + (x.atrasadas ? `<div style="margin-top:6px;color:#ef4444;font-weight:700">${x.atrasadas} atrasada(s) agora</div>` : '')
                    + `<div style="margin-top:8px;padding-top:6px;border-top:1px solid ${border};color:${muted};font-size:11.5px">Clique para ver as passagens</div>`;
            }},
            xAxis: {type: 'value', ...axis, name: 'dias', nameLocation: 'middle', nameGap: 24, nameTextStyle: {color: muted, fontSize: 12}},
            yAxis: {type: 'category', inverse: true, data: et.map((x) => `${x.step}. ${x.nome}`), axisLine: {show: false}, axisTick: {show: false},
                axisLabel: {color: text, fontSize: 14, align: 'left', margin: LABEL_W, width: LABEL_W - 16, overflow: 'truncate'}, triggerEvent: true},
            series: [
                {name: 'Prazo', type: 'bar', barWidth: 18, barGap: '-100%', silent: true, z: 1, data: et.map((x) => x.slo || null),
                    itemStyle: {color: 'rgba(148,163,184,.14)', borderColor: 'rgba(148,163,184,.45)', borderWidth: 1, borderRadius: 9}},
                {name: 'Média', type: 'bar', barWidth: 18, barGap: '-100%', z: 3, cursor: 'pointer', data: et.map((x) => (x.media == null ? null : {value: x.media,
                    itemStyle: {color: x.slo && x.media > x.slo ? grad('#dc2626', '#f87171') : grad('#16a34a', '#4ade80'), borderRadius: 9}})),
                    label: {show: true, position: 'right', distance: 10, color: text, fontWeight: 700, fontSize: 14, formatter: (x) => {
                        const e = et[x.dataIndex];
                        return `${fmt(x.value)} d` + (e.slo ? `  ·  prazo ${e.slo} d` : '');
                    }}},
            ],
        }, et.some((x) => x.n > 0), 'O tempo de cada etapa aparece quando as primeiras etapas forem concluídas.');
        etapa?.on('click', (x) => {
            const item = x.componentType === 'yAxis' ? et.findIndex((e) => `${e.step}. ${e.nome}` === x.value) : x.dataIndex;
            if (item < 0) return;
            openDrawer(`etapa-${et[item].step}`);
        });

        // 2) concluídos por mês e ciclo médio
        const m = d.meses;
        const mes = mk('cf-c-mes', {
            grid: {left: 8, right: 16, top: 46, bottom: 4, containLabel: true},
            legend: {top: 0, right: 0, itemWidth: 16, itemHeight: 10, textStyle: {color: muted, fontSize: 13}},
            tooltip: {...tip, trigger: 'axis', formatter: (ps) => {
                const i = ps[0].dataIndex;
                return head(m.labels[i]) + row('rgba(100,116,139,.8)', 'Concluídos', m.count[i])
                    + row('#14b8a6', 'Ciclo médio', m.avg[i] != null ? `${fmt(m.avg[i])} dias` : '—') + row('#f59e0b', 'Meta', `${m.meta} dias`)
                    + `<div style="margin-top:8px;padding-top:6px;border-top:1px solid ${border};color:${muted};font-size:11.5px">Clique para ver os ciclos</div>`;
            }},
            xAxis: {type: 'category', data: m.labels, axisLine: {lineStyle: {color: border}}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 13}},
            yAxis: [
                {type: 'value', name: 'dias', nameTextStyle: {color: muted, fontSize: 12}, ...axis, max: (v) => Math.ceil(Math.max(v.max, m.meta) * 1.15 / 10) * 10},
                {type: 'value', minInterval: 1, max: (v) => Math.max(3, v.max + 1), ...axis, splitLine: {show: false}, axisLabel: {show: false}},
            ],
            series: [
                {name: 'Concluídos', type: 'bar', yAxisIndex: 1, data: m.count.map((v) => v || null), barWidth: 22, itemStyle: {color: 'rgba(100,116,139,.35)', borderRadius: [6, 6, 0, 0]},
                    label: {show: true, position: 'insideBottom', color: muted, fontSize: 12, formatter: (x) => (x.value ? `${x.value}` : '')}},
                {name: 'Ciclo médio (dias)', type: 'line', data: m.avg, connectNulls: true, smooth: .3, symbol: 'circle', symbolSize: 10, z: 5,
                    lineStyle: {color: '#14b8a6', width: 3}, itemStyle: {color: '#14b8a6', borderColor: surface, borderWidth: 2},
                    areaStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: 'rgba(20,184,166,.28)'}, {offset: 1, color: 'rgba(20,184,166,0)'}])},
                    label: {show: true, position: 'top', distance: 10, color: text, fontWeight: 700, fontSize: 14, formatter: (x) => (x.value != null ? `${fmt(x.value)}d` : '')},
                    markLine: {symbol: 'none', silent: true, lineStyle: {color: '#f59e0b', type: 'dashed', width: 1.5},
                        label: {color: '#f59e0b', formatter: `meta ${m.meta} dias`, position: 'insideEndTop', fontWeight: 700, fontSize: 13}, data: [{yAxis: m.meta}]}},
            ],
        }, m.count.some((v) => v), 'O ciclo médio aparece quando o primeiro ciclo for concluído.');
        mes?.on('click', (x) => openDrawer(`mes-${m.keys[x.dataIndex]}`));

        // 3) devoluções por tipo de serviço (só na devolução)
        if (d.tipos && d.tipos.length) {
            const tipos = mk('cf-c-tipo', {
                grid: {left: 150, right: 60, top: 8, bottom: 8},
                tooltip: {...tip, trigger: 'item', formatter: (p) => head(p.name) + row(p.color, 'Devoluções', p.value)},
                xAxis: {type: 'value', minInterval: 1, ...axis, splitLine: {lineStyle: {color: border, opacity: .3, type: 'dashed'}}},
                yAxis: {type: 'category', inverse: true, data: d.tipos.map((t) => t.nome), axisLine: {show: false}, axisTick: {show: false},
                    axisLabel: {color: text, fontSize: 13, align: 'left', margin: 140}},
                series: [{type: 'bar', barWidth: 16, data: d.tipos.map((t) => ({value: t.n, itemStyle: {color: grad('#2563eb', '#60a5fa'), borderRadius: 8}})),
                    label: {show: true, position: 'right', color: text, fontWeight: 700, fontSize: 13}}],
            }, d.tipos.some((t) => t.n), 'As devoluções por tipo aparecem quando houver registros.');
        }

        // 4) prazo cumprido por área: percentual de etapas no prazo, com a quantidade de etapas
        const slo = (d.slo || []).filter((x) => x.n > 0);
        mk('cf-c-slo', {
            grid: {left: 180, right: 150, top: 10, bottom: 10},
            tooltip: {...tip, trigger: 'item', formatter: (p) => head(p.name) + row(p.color, 'No prazo', `${p.value}%`) + row('transparent', 'Etapas medidas', slo[p.dataIndex].n)},
            xAxis: {type: 'value', min: 0, max: 100, ...axis, axisLabel: {color: muted, fontSize: 12, formatter: '{value}%'}},
            yAxis: {type: 'category', inverse: true, data: slo.map((x) => x.nome), axisLine: {show: false}, axisTick: {show: false},
                axisLabel: {color: text, fontSize: 14, align: 'left', margin: 170, width: 160, overflow: 'truncate'}},
            series: [
                {type: 'bar', barWidth: 20, barGap: '-100%', silent: true, z: 1, data: slo.map(() => 100),
                    itemStyle: {color: 'rgba(148,163,184,.14)', borderRadius: 10}},
                {type: 'bar', barWidth: 20, barGap: '-100%', z: 3, data: slo.map((x) => ({value: x.pct, itemStyle: {borderRadius: 10,
                    color: x.pct >= 90 ? grad('#16a34a', '#4ade80') : (x.pct >= 70 ? grad('#d97706', '#fbbf24') : grad('#dc2626', '#f87171'))}})),
                    label: {show: true, position: 'right', distance: 10, color: text, fontWeight: 700, fontSize: 14, formatter: (x) => `${x.value}%  (${slo[x.dataIndex].n} etapas)`}}],
        }, slo.length > 0, 'O percentual aparece quando houver etapas com prazo concluídas.');

        // 5) ofensores, no painel lateral: clique em "Etapas no prazo" ou no cartão de entregas/itens
        const of = d.ofensores || {};
        const barras = (el, itens, cor, vazio, sufixo, onClick) => {
            if (!itens.length) { el.innerHTML = `<div class="cf-empty"><i class="ti ti-circle-check"></i>${vazio}</div>`; return; }
            el.style.height = `${Math.max(150, itens.length * 40 + 30)}px`;
            const c = echarts.init(el);
            c.setOption({backgroundColor: 'transparent', textStyle: {color: text, fontFamily: 'inherit', fontSize: 13}, animationDuration: 600,
                grid: {left: 8, right: 70, top: 6, bottom: 6, containLabel: true},
                tooltip: {...tip, trigger: 'item', formatter: (p) => head(p.name) + row(cor[0], sufixo, p.value) + (itens[p.dataIndex].extra ? row('transparent', itens[p.dataIndex].extra[0], itens[p.dataIndex].extra[1]) : '')},
                xAxis: {type: 'value', minInterval: 1, ...axis, axisLabel: {color: muted, fontSize: 12}},
                yAxis: {type: 'category', inverse: true, data: itens.map((i) => i.nome), axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: text, fontSize: 13, width: 190, overflow: 'truncate'}},
                series: [{type: 'bar', barWidth: 16, cursor: onClick ? 'pointer' : 'default', data: itens.map((i) => ({value: i.valor, itemStyle: {color: i.cor ? grad(i.cor[0], i.cor[1]) : grad(cor[0], cor[1]), borderRadius: 8}})),
                    label: {show: true, position: 'right', color: text, fontWeight: 700, fontSize: 13, formatter: (x) => `${fmt(x.value)}${itens[x.dataIndex].rotulo || ''}`}}]});
            if (onClick) c.on('click', (x) => onClick(itens[x.dataIndex]));
            charts.push(c);
            const ro = new ResizeObserver(() => c.resize());
            ro.observe(el);
        };
        const secao = (titulo, sub, id) => `<h4 class="cf-of-title">${titulo}</h4><div class="small text-secondary mb-2">${sub}</div><div id="${id}" class="mb-4"></div>`;
        const prazo = of.prazo || {};
        const foraDe = (l) => l.map((x) => ({nome: x.nome, valor: x.fora, extra: ['Etapas medidas', x.n]}));
        document.querySelector('[data-ofensores="prazo"]')?.addEventListener('click', () => {
            openHtml('Ofensores do prazo', 'ti-target-arrow', '#a855f7',
                `<p class="cf-dr-lead">Quantidade de etapas que passaram do prazo (concluídas fora do prazo e atrasadas agora). Quanto maior a barra, maior o ofensor.</p>`
                + secao('Por etapa', 'Onde mais se perde prazo', 'cf-of-etapa') + secao('Por grupo', 'Grupos com mais etapas fora do prazo', 'cf-of-grupo') + secao('Por responsável', 'Responsáveis com mais etapas fora do prazo', 'cf-of-usuario'));
            barras(document.getElementById('cf-of-etapa'), foraDe(prazo.etapas || []), ['#dc2626', '#f87171'], 'Nenhuma etapa fora do prazo.', 'Fora do prazo');
            barras(document.getElementById('cf-of-grupo'), foraDe(prazo.grupos || []), ['#d97706', '#fbbf24'], 'Nenhum grupo fora do prazo.', 'Fora do prazo');
            barras(document.getElementById('cf-of-usuario'), foraDe(prazo.usuarios || []), ['#7c3aed', '#a78bfa'], 'Nenhum responsável fora do prazo.', 'Fora do prazo');
        });
        const per = of.periodo || {ciclos: [], meta: 0, total: 0};
        document.querySelector('[data-ofensores="desligados"], [data-ofensores="itens"]')?.addEventListener('click', () => {
            openHtml('Ofensores do período', 'ti-flame', '#f59e0b',
                `<p class="cf-dr-lead">Os ${per.ciclos.length} ciclos que mais demoraram, entre ${per.total} no período (concluídos e em andamento). A meta é de ${per.meta} dias. Clique numa barra para abrir o ciclo.</p>`
                + secao('Tempo do ciclo, em dias', 'Laranja: acima da meta · azul: em andamento · verde: dentro da meta', 'cf-of-ciclos'));
            barras(document.getElementById('cf-of-ciclos'), per.ciclos.map((c) => ({nome: c.nome, valor: c.dias, rotulo: ' d', url: c.url, cor: c.aberto ? ['#2563eb', '#60a5fa'] : (per.meta && c.dias > per.meta ? ['#d97706', '#fbbf24'] : ['#16a34a', '#4ade80']),
                extra: ['Situação', (c.aberto ? 'em andamento' : 'concluído') + (per.meta && c.dias > per.meta ? ` · ${fmt((c.dias - per.meta).toFixed(1))} d acima da meta` : '')]})),
                ['#d97706', '#fbbf24'], 'Nenhum ciclo no período.', 'Dias', (i) => { window.location.href = i.url; });
        });

        window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
})();
