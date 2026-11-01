/**
 * Pesquisa de Satisfação — painel: gráficos (ECharts do GLPI) com detalhes ao passar o
 * mouse e o painel lateral com as pesquisas de cada cartão, barra ou fatia.
 */
(() => {
    const el = document.getElementById('sf-data');
    if (!el) return;
    const d = JSON.parse(el.textContent);
    const charts = [];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = (v) => String(v).replace('.', ',');
    const dateBr = (s) => (s ? s.split('-').reverse().join('/') : '');
    const MOOD_KEYS = ['positive', 'neutral', 'negative', 'none'];
    const faceOf = (r) => (r.r != null ? d.faces[Math.max(1, Math.min(5, Math.round(r.r)))] : d.moods[r.m][1]);

    // ---------------------------------------------------------------- filtros das listas
    /** key: all | answered | rated | nps | mood:<humor> | score:<1-5> | month:<aaaa-mm>[:<humor>] | origin:<nome>[:<humor>] | cat:<nome> | ent:<nome> */
    function pick(key) {
        const [kind, a, b] = key.split(':');
        const byMood = (r) => !b || r.m === b;
        switch (kind) {
            case 'all': return {title: 'Pesquisas enviadas', emoji: '📨', color: '#3b82f6', rows: d.rows};
            case 'answered': return {title: 'Pesquisas respondidas', emoji: '💬', color: '#6366f1', rows: d.rows.filter((r) => r.a)};
            case 'comments': return {title: 'Pesquisas com comentário', emoji: '💬', color: '#14b8a6', rows: d.rows.filter((r) => r.c)};
            case 'rated': return {title: 'Avaliações por estrelas', emoji: '⭐', color: '#14b8a6', rows: d.rows.filter((r) => r.r != null)};
            case 'nps': return {title: 'Respostas de recomendação (NPS)', emoji: '📈', color: '#a855f7', rows: d.rows.filter((r) => r.p != null)};
            case 'mood': return {title: `Pesquisas ${d.moods[a][0].toLowerCase()}`, emoji: d.moods[a][1], color: d.moods[a][2], rows: d.rows.filter((r) => r.m === a)};
            case 'score': return {title: `Nota ${a} ${d.faces[a]} ${d.names[a]}`, emoji: d.faces[a], color: '#14b8a6', rows: d.rows.filter((r) => r.r != null && Math.round(r.r) === Number(a))};
            case 'month': return {title: `Enviadas em ${a.split('-').reverse().join('/')}${b ? ` · ${d.moods[b][0].toLowerCase()}` : ''}`, emoji: '📅', color: '#3b82f6', rows: d.rows.filter((r) => r.s.startsWith(a) && byMood(r))};
            case 'origin': return {title: `${a}${b ? ` · ${d.moods[b][0].toLowerCase()}` : ''}`, emoji: '🧭', color: '#3b82f6', rows: d.rows.filter((r) => r.o === a && byMood(r))};
            case 'cat': return {title: `Categoria ${a}`, emoji: '🏷️', color: '#3b82f6', rows: d.rows.filter((r) => r.k === a)};
            case 'ent': return {title: `Entidade ${a}`, emoji: '🏢', color: '#3b82f6', rows: d.rows.filter((r) => r.e === a)};
            default: return {title: 'Pesquisas', emoji: '📋', color: '#3b82f6', rows: d.rows};
        }
    }

    // ---------------------------------------------------------------- painel lateral
    const drawer = document.querySelector('.sf-drawer');
    const backdrop = document.querySelector('.sf-drawer-backdrop');
    function openHtml({title, emoji, color, html}) {
        if (!drawer) return;
        drawer.style.setProperty('--c', color);
        drawer.querySelector('h3').textContent = title;
        drawer.querySelector('.sf-drawer-icon').textContent = emoji;
        const body = drawer.querySelector('.sf-drawer-body');
        body.innerHTML = html;
        body.scrollTop = 0;
        backdrop.hidden = false;
        requestAnimationFrame(() => { backdrop.classList.add('on'); drawer.classList.add('on'); });
        drawer.setAttribute('aria-hidden', 'false');
        drawer.querySelector('.btn-close').focus();
    }
    function closeDrawer() {
        drawer.classList.remove('on');
        backdrop.classList.remove('on');
        drawer.setAttribute('aria-hidden', 'true');
        setTimeout(() => { backdrop.hidden = true; }, 250);
    }
    const item = (r) => {
        const pills = [
            r.r != null ? `<span class="sf-pill">⭐ ${fmt(r.r)}</span>` : '',
            r.p != null ? `<span class="sf-pill">📈 ${r.p}</span>` : '',
            r.y != null ? `<span class="sf-pill">${r.y ? '👍 resolvido' : '👎 não resolvido'}</span>` : '',
        ].join('');
        const meta = [r.o, `enviada em ${dateBr(r.s)}`, r.a ? `respondida em ${dateBr(r.a)}` : '<b>sem resposta</b>', r.k, r.e].filter(Boolean).join(' · ');
        return `<div class="sf-dr-item ${r.m}"><div class="sf-dr-row"><span class="sf-dr-face">${faceOf(r)}</span>`
            + `${r.u ? `<a href="${esc(r.u)}" class="sf-dr-name">${esc(r.n)}</a>` : `<b class="sf-dr-name">${esc(r.n)}</b>`}<span class="sf-dr-pills">${pills}</span></div>`
            + `<div class="sf-dr-sub">${meta}</div>${r.c ? `<div class="sf-dr-note">${esc(r.c)}</div>` : ''}</div>`;
    };
    function openList(key) {
        const p = pick(key);
        const count = (m) => p.rows.filter((r) => r.m === m).length;
        const stats = MOOD_KEYS.map((m) => `<div class="${m === 'negative' ? 'bad' : ''}" style="--c:${d.moods[m][2]}"><small>${d.moods[m][1]} ${d.moods[m][0]}</small><b>${count(m)}</b></div>`).join('');
        const rows = [...p.rows].sort((a, b) => (b.a || b.s).localeCompare(a.a || a.s));
        openHtml({title: p.title, emoji: p.emoji, color: p.color,
            html: `<p class="sf-dr-lead">${rows.length} pesquisa(s), da mais recente para a mais antiga.</p><div class="sf-dr-stats">${stats}</div>`
                + `<div class="sf-dr-list">${rows.slice(0, 200).map(item).join('') || '<div class="sf-dr-empty">Nenhuma pesquisa.</div>'}</div>`
                + (rows.length > 200 ? `<p class="sf-dr-lead mt-3">Mostrando as 200 mais recentes.</p>` : '')});
    }
    document.querySelectorAll('[data-list]').forEach((b) => b.addEventListener('click', () => openList(b.dataset.list)));
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
        const hint = `<div style="margin-top:8px;padding-top:6px;border-top:1px solid ${border};color:${muted};font-size:11.5px">Clique para ver as pesquisas</div>`;
        const axis = {axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 12}, splitLine: {lineStyle: {color: border, opacity: .3, type: 'dashed'}}};
        const legend = {top: 0, left: 0, itemWidth: 12, itemHeight: 8, itemGap: 12, icon: 'roundRect', textStyle: {color: muted, fontSize: 12}};
        const mk = (id, option, has, empty) => {
            const node = document.getElementById(id);
            if (!node) return null;
            if (!has) { node.innerHTML = `<div class="sf-empty"><span class="sf-emoji-xl">📊</span>${empty}</div>`; return null; }
            const c = echarts.init(node);
            c.setOption({backgroundColor: 'transparent', textStyle: {color: text, fontFamily: 'inherit'}, animationDuration: 700, animationEasing: 'cubicOut', ...option});
            charts.push(c);
            return c;
        };
        const color = (m) => d.moods[m][2];

        // 1) distribuição das notas: uma coluna por carinha
        const total = Object.values(d.dist).reduce((a, b) => a + b, 0);
        const dist = mk('sf-c-dist', {
            grid: {left: 6, right: 6, top: 22, bottom: 30},
            tooltip: {...tip, trigger: 'item', formatter: (p) => head(`${d.faces[p.dataIndex + 1]} ${d.names[p.dataIndex + 1]}`) + row(p.color, 'Avaliações', p.value) + row('transparent', 'Do total', `${total ? fmt((100 * p.value / total).toFixed(1)) : 0}%`) + hint},
            xAxis: {type: 'category', data: [1, 2, 3, 4, 5].map((n) => d.faces[n]), axisLine: {show: false}, axisTick: {show: false}, axisLabel: {fontSize: 22, margin: 8}},
            yAxis: {type: 'value', show: false, max: (v) => Math.max(1, v.max * 1.2)},
            series: [{type: 'bar', barWidth: '46%', cursor: 'pointer', data: [1, 2, 3, 4, 5].map((n) => ({value: d.dist[n] || 0, itemStyle: {color: ['#ef4444', '#f97316', '#f59e0b', '#84cc16', '#22c55e'][n - 1], borderRadius: [6, 6, 0, 0]}})),
                label: {show: true, position: 'top', color: text, fontWeight: 700, fontSize: 13, formatter: (p) => (p.value ? p.value : '')}}],
        }, total > 0, 'As notas aparecem quando chegarem as primeiras avaliações por estrelas.');
        dist?.on('click', (p) => openList(`score:${p.dataIndex + 1}`));

        // 2) evolução por mês: barras empilhadas por humor e a nota média em linha
        const m = d.months;
        const monthChart = mk('sf-c-month', {
            grid: {left: 8, right: 8, top: 44, bottom: 4, containLabel: true},
            legend,
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.06)'}}, formatter: (ps) => {
                const i = ps[0].dataIndex;
                const x = m[i];
                return head(x.label) + MOOD_KEYS.map((k) => row(color(k), `${d.moods[k][1]} ${d.moods[k][0]}`, x[k])).join('')
                    + (x.avg != null ? row('#14b8a6', 'Nota média', `${fmt(x.avg)} / 5`) : '') + hint;
            }},
            xAxis: {type: 'category', data: m.map((x) => x.label), axisLine: {lineStyle: {color: border}}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 12}},
            yAxis: [{type: 'value', minInterval: 1, ...axis}, {type: 'value', min: 0, max: 5, show: false}],
            series: [
                ...MOOD_KEYS.map((k, idx) => ({name: `${d.moods[k][1]} ${d.moods[k][0]}`, type: 'bar', stack: 'h', barWidth: 30, cursor: 'pointer', _mood: k,
                    data: m.map((x) => x[k] || null), itemStyle: {color: color(k), borderRadius: idx === 3 ? [6, 6, 0, 0] : 0}})),
                {name: '⭐ Nota média', type: 'line', yAxisIndex: 1, data: m.map((x) => x.avg), connectNulls: true, smooth: .3, symbol: 'circle', symbolSize: 9, z: 6,
                    lineStyle: {color: '#14b8a6', width: 3}, itemStyle: {color: '#14b8a6', borderColor: surface, borderWidth: 2},
                    label: {show: true, position: 'top', distance: 8, color: text, fontWeight: 700, fontSize: 12, formatter: (x) => (x.value != null ? fmt(x.value) : '')}},
            ],
        }, m.length > 0, 'A evolução aparece quando houver pesquisas no período.');
        monthChart?.on('click', (p) => {
            const mood = p.seriesType === 'bar' ? MOOD_KEYS[p.seriesIndex] : '';
            openList(`month:${m[p.dataIndex].key}${mood ? `:${mood}` : ''}`);
        });

        // 3) origem: barras horizontais empilhadas por humor
        const o = d.origins;
        const originChart = mk('sf-c-origin', {
            grid: {left: 8, right: 20, top: 44, bottom: 4, containLabel: true},
            legend,
            tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.06)'}}, formatter: (ps) => {
                const x = o[ps[0].dataIndex];
                return head(x.label) + MOOD_KEYS.map((k) => row(color(k), `${d.moods[k][1]} ${d.moods[k][0]}`, x[k])).join('') + hint;
            }},
            xAxis: {type: 'value', minInterval: 1, ...axis},
            yAxis: {type: 'category', inverse: true, data: o.map((x) => x.label), axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: text, fontSize: 13}},
            series: MOOD_KEYS.map((k, idx) => ({name: `${d.moods[k][1]} ${d.moods[k][0]}`, type: 'bar', stack: 'o', barWidth: 30, cursor: 'pointer',
                data: o.map((x) => x[k] || null), itemStyle: {color: color(k), borderRadius: idx === 3 ? [0, 6, 6, 0] : 0}})),
        }, o.length > 0, 'A origem aparece quando houver pesquisas no período.');
        originChart?.on('click', (p) => openList(`origin:${o[p.dataIndex].label}:${MOOD_KEYS[p.seriesIndex]}`));

        // 4) categoria e entidade: nota média de cada uma, com a quantidade de pesquisas
        const ranking = (id, list, kind, empty) => {
            const chart = mk(id, {
                grid: {left: 8, right: 70, top: 6, bottom: 22, containLabel: true},
                tooltip: {...tip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: 'rgba(148,163,184,.06)'}}, formatter: (ps) => {
                    const x = list[ps[0].dataIndex];
                    return head(x.label) + row('#14b8a6', 'Nota média', x.avg != null ? `${d.faces[Math.max(1, Math.min(5, Math.round(x.avg)))]} ${fmt(x.avg)} / 5` : 'sem estrelas') + row('#3b82f6', 'Pesquisas', x.n) + hint;
                }},
                xAxis: {type: 'value', min: 0, max: 5, ...axis},
                yAxis: {type: 'category', inverse: true, data: list.map((x) => x.label), axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: text, fontSize: 13, width: 190, overflow: 'truncate'}},
                series: [{type: 'bar', barWidth: 16, cursor: 'pointer', showBackground: true, backgroundStyle: {color: 'rgba(148,163,184,.12)', borderRadius: 8},
                    data: list.map((x) => ({value: x.avg, itemStyle: {color: x.avg == null ? '#64748b' : (x.avg >= 4 ? '#22c55e' : (x.avg >= 3 ? '#f59e0b' : '#ef4444')), borderRadius: 8}})),
                    label: {show: true, position: 'right', color: text, fontWeight: 700, fontSize: 12, formatter: (p) => (p.value != null ? `${fmt(p.value)} · ${list[p.dataIndex].n}` : `— · ${list[p.dataIndex].n}`)}}],
            }, list.length > 0, empty);
            chart?.on('click', (p) => openList(`${kind}:${list[p.dataIndex].label}`));
        };
        ranking('sf-c-cat', d.cats, 'cat', 'As categorias aparecem para pesquisas de chamados com categoria.');
        ranking('sf-c-ent', d.ents, 'ent', 'As entidades aparecem quando houver pesquisas no período.');
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
})();
