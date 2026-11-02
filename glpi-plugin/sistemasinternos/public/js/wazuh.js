/**
 * Sistemas Internos 360 — telas do Wazuh: Vulnerabilidades (executiva, por ativo, operacional)
 * e Segurança (eventos, MITRE, conformidade, FIM, SCA, acesso, agentes e inventário).
 */
(() => {
    const root = document.getElementById('wz-root');
    if (!root) return;
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const page = root.dataset.wz;
    const API = root.dataset.api;
    const canTicket = root.dataset.ticket === '1';
    const csrf = () => (document.querySelector('meta[property="glpi:csrf_token"]') || {}).content || '';
    const SEV = {Critical: ['Crítica', '#ef4f4f'], High: ['Alta', '#f2783f'], Medium: ['Média', '#f7b84b'], Low: ['Baixa', '#5b8def'], '-': ['Sem classificação', '#7e8aa3']};
    const SEVK = Object.keys(SEV);
    const num = (n) => Number(n || 0).toLocaleString('pt-BR');
    const dec = (n) => Number(n || 0).toLocaleString('pt-BR', {maximumFractionDigits: 1});
    const when = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit'}) : '—');
    const ago = (iso) => {
        const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
        return s < 90 ? 'agora' : s < 5400 ? Math.round(s / 60) + ' min' : s < 129600 ? Math.round(s / 3600) + ' h' : Math.round(s / 86400) + ' d';
    };
    const lvlColor = (l) => (l >= 12 ? '#ef4f4f' : l >= 10 ? '#f2783f' : l >= 7 ? '#f7b84b' : '#5b8def');
    const sevPill = (k) => `<span class='si360-sev' style='--c:${(SEV[k] || SEV['-'])[1]}'>${esc((SEV[k] || SEV['-'])[0])}</span>`;
    const lvlPill = (l) => `<span class='si360-sev' style='--c:${lvlColor(l)}'>${l}</span>`;
    const cveLink = (c) => (/^CVE-/i.test(c) ? `<a href='https://nvd.nist.gov/vuln/detail/${esc(c)}' target='_blank' rel='noopener'>${esc(c)}</a>` : esc(c));
    const empty = (t, cols = 6) => `<tr><td colspan='${cols}' class='text-center text-secondary py-4'>${t}</td></tr>`;

    const api = async (p, params = {}) => {
        const u = new URL(API, location.origin);
        u.searchParams.set('p', p);
        Object.entries(params).forEach(([k, v]) => { if (v !== '' && v != null) u.searchParams.set(k, v); });
        const r = await fetch(u, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
        const j = await r.json().catch(() => ({erro: 'Resposta inválida do servidor.'}));
        if (!r.ok) throw new Error(j.erro || 'Erro ' + r.status);
        return j;
    };
    const post = async (p, body) => {
        const u = new URL(API, location.origin);
        u.searchParams.set('p', p);
        const r = await fetch(u, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-Glpi-Csrf-Token': csrf()}, body: JSON.stringify(body)});
        const j = await r.json().catch(() => ({erro: 'Resposta inválida do servidor.'}));
        if (!r.ok) throw new Error(j.erro || 'Erro ' + r.status);
        return j;
    };
    const loading = (el) => { el.innerHTML = `<div class='text-center text-secondary py-5'><span class='spinner-border spinner-border-sm me-2'></span>Consultando o Wazuh…</div>`; };
    const fail = (el, e) => { el.innerHTML = `<div class='alert alert-danger'><i class='ti ti-alert-triangle me-2'></i>${esc(e.message)}</div>`; };
    const card = (title, icon, body, cls = '', extra = '') => `<div class='${cls}'><div class='card si360-card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ${icon} me-2'></i>${title}</h3>${extra}</div>${body}</div></div>`;
    const kpi = (icon, label, value, sub, tone = '', attr = '') => `<div class='col-6 col-md-4 col-xl-3'><div class='si360-kpi ${tone}' ${attr}><span class='si360-kpi-ico'><i class='ti ${icon}'></i></span><div><div class='si360-kpi-label'>${label}</div><div class='si360-kpi-value'>${value}</div><div class='si360-kpi-sub'>${sub}</div></div></div></div>`;

    // ---------------------------------------------------------------- gráficos
    let charts = [];
    const theme = () => {
        const css = getComputedStyle(root.closest('.si360') || document.body);
        return {fg: css.getPropertyValue('--tblr-body-color').trim() || '#e7eefb', muted: css.getPropertyValue('--tblr-secondary-color').trim() || '#a9bee3', grid: css.getPropertyValue('--tblr-border-color').trim() || 'rgba(143,181,239,.2)'};
    };
    const mk = (id, opt, onClick) => {
        const el = document.getElementById(id);
        if (!el || !window.echarts) return null;
        const t = theme();
        const c = echarts.init(el, null, {renderer: 'svg'});
        c.setOption(Object.assign({textStyle: {color: t.fg, fontFamily: 'inherit'}, tooltip: Object.assign({trigger: 'item'}, ttip), animationDuration: 400, animationEasing: 'cubicOut'}, opt));
        if (onClick) c.on('click', onClick);
        charts.push(c);
        return c;
    };
    window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
    const dropCharts = (keepInPane, paneSel) => { // descarta os gráficos de um painel (ou de todos os outros) antes de redesenhar
        charts = charts.filter((c) => { const mine = !!c.getDom().closest(paneSel); if (mine === keepInPane) return true; c.dispose(); return false; });
    };
    const withCharts = (fn) => { if (window.echarts) fn(); else window.addEventListener('load', fn, {once: true}); };
    const resizeAll = () => setTimeout(() => charts.forEach((c) => { try { c.resize(); } catch (e) { /* */ } }), 30);
    // ---- estilo dos gráficos: limpo, grade tracejada, cores sólidas, tooltip escuro e ferramentas
    const FADE = 'rgba(143,181,239,.10)';
    const SEG = '#0c1e3d'; // separador entre segmentos empilhados
    const axis = (t) => ({splitLine: {lineStyle: {color: FADE, type: 'dashed'}}, axisLabel: {color: t.muted, fontSize: 11, formatter: (v) => (typeof v === 'number' ? num(v) : v)}, axisLine: {show: false}, axisTick: {show: false}});
    const AXV = (t, o = {}) => Object.assign({type: 'value'}, axis(t), o);
    const AXC = (t, data, o = {}) => Object.assign({type: 'category', data, axisTick: {show: false}, axisLine: {lineStyle: {color: 'rgba(143,181,239,.22)'}}, axisLabel: {color: t.muted, fontSize: 11}}, o);
    const AXY = (t, data, w) => ({type: 'category', inverse: true, data, axisTick: {show: false}, axisLine: {show: false}, axisLabel: {color: t.fg, fontSize: 12, width: w, overflow: 'truncate'}});
    const LEG = (t, o = {}) => Object.assign({top: 0, left: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, itemGap: 16, textStyle: {color: t.muted, fontSize: 11}, inactiveColor: '#4a5f88'}, o);
    const TB = (t, restore = false) => ({top: 0, right: 0, itemSize: 14, feature: Object.assign({saveAsImage: {title: 'Salvar imagem', name: 'grafico', pixelRatio: 2, backgroundColor: SEG}}, restore ? {restore: {title: 'Restaurar zoom'}} : {}),
        iconStyle: {borderColor: t.muted}, emphasis: {iconStyle: {borderColor: '#fff'}}});
    const POINTER = {type: 'shadow', shadowStyle: {color: 'rgba(143,181,239,.08)'}};
    const CROSS = {type: 'cross', lineStyle: {color: 'rgba(143,181,239,.45)', type: 'dashed'}, label: {backgroundColor: '#3769B2'}};
    const ZOOM = (t, on) => (on ? [{type: 'inside'}, {type: 'slider', height: 16, bottom: 4, borderColor: 'transparent', backgroundColor: 'rgba(143,181,239,.08)', fillerColor: 'rgba(111,155,224,.28)', textStyle: {color: t.muted, fontSize: 10}, handleStyle: {color: '#6f9be0'}, dataBackground: {lineStyle: {opacity: 0}, areaStyle: {opacity: 0}}}] : [{type: 'inside'}]);
    const donut = (id, data, onClick, center, legend = true) => {
        const t = theme();
        const total = data.reduce((a, d) => a + d.value, 0);
        return mk(id, {title: center === false ? undefined : {text: num(total), subtext: center || 'total', left: 'center', top: legend ? '34%' : '38%', textStyle: {color: t.fg, fontSize: 26, fontWeight: 700}, subtextStyle: {color: t.muted, fontSize: 11}},
            tooltip: Object.assign({trigger: 'item', formatter: (p) => `<b>${esc(p.name)}</b><br>${num(p.value)} · ${p.percent}%`}, ttip),
            legend: legend ? LEG(t, {top: 'auto', bottom: 0, left: 'center', type: 'scroll'}) : {show: false},
            series: [{type: 'pie', radius: ['58%', '80%'], center: ['50%', legend ? '44%' : '50%'], padAngle: 2, label: {show: false}, itemStyle: {borderRadius: 6, borderColor: SEG, borderWidth: 2},
                emphasis: {scaleSize: 7, itemStyle: {shadowBlur: 16, shadowColor: 'rgba(0,0,0,.45)'}}, data}]}, onClick);
    };
    const hbar = (id, rows, color, onClick, labelW = 150) => {
        const t = theme();
        const box = document.getElementById(id);
        if (box) box.style.height = Math.max(120, rows.length * 32 + 24) + 'px';
        return mk(id, {tooltip: Object.assign({trigger: 'axis', axisPointer: POINTER}, ttip), grid: {left: labelW, right: 46, top: 4, bottom: 4},
            xAxis: {type: 'value', show: false}, yAxis: AXY(t, rows.map((r) => r.label), labelW - 14),
            series: [{type: 'bar', barWidth: 12, showBackground: true, backgroundStyle: {color: 'rgba(143,181,239,.07)', borderRadius: 6}, emphasis: {focus: 'self'},
                data: rows.map((r) => ({value: r.value, itemStyle: {color: r.color || color || '#6f9be0', borderRadius: 6}})), label: {show: true, position: 'right', color: t.fg, fontSize: 11, formatter: (p) => num(p.value)}}]}, onClick);
    };
    const stackedBar = (id, rows, series, onClick, labelW = 150) => { // rows: [{label, v:{k:n}}], series: [[key, nome, cor]]
        const t = theme();
        const totals = rows.map((r) => series.reduce((a, [k]) => a + (r.v[k] || 0), 0));
        const box = document.getElementById(id);
        if (box) box.style.height = Math.max(170, rows.length * 34 + 84) + 'px'; // a altura acompanha o número de barras
        return mk(id, {tooltip: Object.assign({trigger: 'axis', axisPointer: POINTER, formatter: (ps) => { const its = ps.filter((x) => x.seriesName !== '_total' && x.value); return `<b>${esc(ps[0].name)}</b>` + its.map((x) => `<br>${x.marker} ${esc(x.seriesName)}: <b>${num(x.value)}</b>`).join('') + `<br><span style='opacity:.7'>Total: <b>${num(totals[ps[0].dataIndex])}</b></span>`; }}, ttip),
            legend: LEG(t, {data: series.map(([, name]) => name)}), toolbox: TB(t), grid: {left: labelW, right: 56, top: 38, bottom: 6},
            xAxis: AXV(t, {minInterval: 1}), yAxis: AXY(t, rows.map((r) => r.label), labelW - 14),
            series: series.map(([k, name, color]) => ({name, type: 'bar', stack: 's', barWidth: 16, itemStyle: {color, borderColor: SEG, borderWidth: 1}, emphasis: {focus: 'series'}, data: rows.map((r) => r.v[k] || 0)}))
                .concat([{name: '_total', type: 'bar', stack: 's', silent: true, tooltip: {show: false}, itemStyle: {color: 'transparent'}, data: rows.map(() => 0), label: {show: true, position: 'right', color: t.fg, fontSize: 11, fontWeight: 600, formatter: (p) => (totals[p.dataIndex] ? num(totals[p.dataIndex]) : '')}}])}, onClick);
    };
    const stackedCols = (id, cats, series, onClick, o = {}) => { // colunas empilhadas: cats [rótulos], series [[nome, cor, dados]]
        const t = theme();
        const zoom = cats.length > (o.zoomAbove || 18);
        return mk(id, {tooltip: Object.assign({trigger: 'axis', axisPointer: POINTER}, ttip), legend: LEG(t), toolbox: TB(t, zoom), grid: {left: 50, right: 12, top: 40, bottom: zoom ? 50 : 26},
            dataZoom: ZOOM(t, zoom), xAxis: AXC(t, cats, o.xAxis || {}), yAxis: AXV(t),
            series: series.map(([name, color, data]) => ({name, type: 'bar', stack: 'c', barMaxWidth: o.barMax || 42, itemStyle: {color, borderColor: SEG, borderWidth: 1}, emphasis: {focus: 'series'}, data}))}, onClick);
    };

    // ---------------------------------------------------------------- gaveta (lado direito)
    let drawer = null;
    const views = []; // pilha de visões da gaveta: lista → detalhe → voltar
    const closeDrawer = () => { views.length = 0; drawer && drawer.classList.remove('on'); };
    const openDrawer = (title, icon, bodyHtml, filtersHtml = '') => {
        if (!drawer) {
            drawer = document.createElement('div');
            drawer.className = 'si360-drill';
            document.body.appendChild(drawer);
            drawer.addEventListener('click', (e) => { if (e.target === drawer || e.target.closest('[data-close]')) closeDrawer(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });
        }
        drawer.innerHTML = `<div class='si360-drill-box' style='width:min(1040px,100%)'><div class='si360-drill-head'><b><i class='ti ${icon} me-2'></i>${esc(title)}</b><span data-n></span>`
            + `<button type='button' class='btn-close btn-close-white ms-auto' data-close aria-label='Fechar'></button></div>`
            + (filtersHtml ? `<div class='si360-drill-filters'>${filtersHtml}</div>` : '') + `<div class='si360-drill-body'>${bodyHtml}</div></div>`;
        requestAnimationFrame(() => drawer.classList.add('on'));
        return drawer;
    };
    const paintView = () => {
        const v = views[views.length - 1];
        if (!v) return;
        const box = openDrawer(v.title, v.icon, `<div class='text-center text-secondary py-5'><span class='spinner-border spinner-border-sm me-2'></span>Consultando…</div>`);
        if (views.length > 1) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm btn-ghost-light me-2';
            b.innerHTML = `<i class='ti ti-arrow-left me-1'></i>Voltar`;
            b.addEventListener('click', () => { views.pop(); paintView(); });
            $('.si360-drill-head', box).prepend(b);
        }
        v.render($('.si360-drill-body', box), box);
    };
    const openView = (v, reset = false) => { if (reset) views.length = 0; views.push(v); paintView(); };

    // gráficos profissionais: tooltip escuro, degradês e cantos arredondados
    const ttip = {backgroundColor: '#0b1b38', borderColor: '#3769B2', borderWidth: 1, padding: [8, 12], textStyle: {color: '#e7eefb', fontSize: 12}, extraCssText: 'border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.45)'};
    const mixHex = (c, w) => { const n = parseInt(c.slice(1), 16); const f = (v) => Math.round(v + (255 - v) * w); return '#' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(f).map((v) => v.toString(16).padStart(2, '0')).join(''); };
    const grad = (x1, y1, x2, y2, c) => (window.echarts ? new echarts.graphic.LinearGradient(x1, y1, x2, y2, [{offset: 0, color: mixHex(c, .28)}, {offset: 1, color: c}]) : c);
    const vGrad = (c) => c;
    const hGrad = (c) => c;
    const sevGrad = (k) => (SEV[k] || SEV['-'])[1];

    // chamado: botão que abre sem duplicar
    const tkBtn = (kind, agent, ref) => (canTicket ? `<button type='button' class='btn btn-sm btn-primary' data-tk data-kind='${kind}' data-agent='${esc(agent)}' data-ref='${esc(ref)}'><i class='ti ti-ticket me-1'></i>Abrir chamado</button>` : `<span class='text-secondary small'>sem permissão</span>`);
    const tkCell = (r, kind) => (r.ticket ? `<a class='si360-pill warn' href='${esc(r.ticket.url)}' target='_blank' rel='noopener' title='Chamado em aberto: não abre outro'><i class='ti ti-ticket me-1'></i>#${r.ticket.id}</a>`
        : (r.last_ticket ? `<a class='text-secondary small d-block mb-1' href='${esc(r.last_ticket.url)}' target='_blank' rel='noopener'>último: #${r.last_ticket.id}</a>` : '') + tkBtn(kind, r.agent, kind === 'vuln' ? r.package : r.rule));
    document.addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-tk]');
        if (!b) return;
        b.disabled = true;
        const old = b.innerHTML;
        b.innerHTML = `<span class='spinner-border spinner-border-sm me-1'></span>Abrindo…`;
        try {
            const r = await post('ticket', {kind: b.dataset.kind, agent: b.dataset.agent, ref: b.dataset.ref});
            const cell = b.parentElement;
            cell.innerHTML = `<a class='si360-pill ${r.duplicate ? 'warn' : 'ok'}' href='${esc(r.ticket.url)}' target='_blank' rel='noopener'><i class='ti ti-ticket me-1'></i>#${r.ticket.id}</a>`
                + `<div class='small text-secondary'>${esc(r.message)}</div>`;
        } catch (e) {
            b.disabled = false;
            b.innerHTML = old;
            alert(e.message);
        }
    });

    // abas
    const loaded = {};
    const tabs = $('[data-wz-tabs]');
    const showTab = (k) => {
        $$('[data-wz-tab]', tabs).forEach((a) => a.classList.toggle('active', a.dataset.wzTab === k));
        $$('.si360-wzpane', root).forEach((p) => { p.hidden = p.id !== 'wz-' + k; });
        if (!loaded[k]) { loaded[k] = true; tabLoaders[k]?.(); }
        resizeAll();
        try { history.replaceState(null, '', '#' + k); } catch (e) { /* */ }
    };
    $$('[data-wz-tab]', tabs).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); showTab(a.dataset.wzTab); }));
    const tabLoaders = {};

    // =================================================================== VULNERABILIDADES
    if (page === 'vuln') {
        const state = {entity: '', q: '', chip: '', ops: {sev: 'Medium', q: '', free: false, prio: ['P1', 'P2', 'P3', 'P4']}, hours: 24};
        const AGE = {'Até 7 dias': '0-7', '8 a 30 dias': '8-30', '31 a 90 dias': '31-90', 'Mais de 90 dias': '90+'};
        const sevRows = ['Critical', 'High', 'Medium', 'Low', '-'];
        const sevSeries = sevRows.map((k) => [k, SEV[k][0], SEV[k][1]]);
        const entitySelect = (entities, sel) => `<select class='form-select form-select-sm w-auto' data-entity><option value=''>Todas as entidades</option>${(entities || []).map((e) => `<option value='${esc(e.id)}'${String(sel) === String(e.id) ? ' selected' : ''}>${esc(e.name)} (${e.assets})</option>`).join('')}</select>`;
        const spin = `<div class='text-center text-secondary py-5'><span class='spinner-border spinner-border-sm me-2'></span>Consultando…</div>`;
        const mix = (sev, cls = '') => { // barra proporcional de severidades
            const t = sevRows.reduce((a, k) => a + (sev[k] || 0), 0) || 1;
            return `<div class='si-sevbar ${cls}'>${sevRows.filter((k) => sev[k]).map((k) => `<i style='width:${(100 * sev[k] / t).toFixed(2)}%;background:${SEV[k][1]}' title='${SEV[k][0]}: ${num(sev[k])}'></i>`).join('')}</div>`;
        };
        const chips = (sev) => ['Critical', 'High', 'Medium', 'Low'].map((k) => `<span class='si360-sev me-1' style='--c:${SEV[k][1]};${sev[k] ? '' : 'opacity:.3'}' title='${SEV[k][0]}'>${num(sev[k])}</span>`).join('');
        const respOf = (c) => (c ? [c.tech, c.tech_group, !c.tech && !c.tech_group ? c.owner : ''].filter(Boolean).join(' · ') : '');
        const ttl = (cve) => `<span class='si-cve'>${cveLink(cve)}</span>`;
        const fixTxt = (v) => (v.fix ? `<span class='text-success'>≥ ${esc(v.fix)}</span>` : v.nofix ? `<span class='text-secondary'>sem correção publicada</span>` : '—');
        const showList = (title, base) => openView(listView(title, base), true);
        // o PDF respeita a entidade escolhida nos filtros
        $$('[data-wz-pdf]').forEach((a) => a.addEventListener('click', () => { a.href = a.dataset.base + (state.entity ? '&entity=' + encodeURIComponent(state.entity) : ''); }));
        const nvd = (c) => `https://nvd.nist.gov/vuln/detail/${encodeURIComponent(c)}`;

        // ------------------------------------------------------------- gaveta: lista filtrada de vulnerabilidades
        function listView(title, base = {}) {
            return {title, icon: 'ti-bug', render: async (body, box) => {
                const f = {q: '', sev: base.sev || '', sort: base.sort || 'score', page: 0};
                body.innerHTML = `<div class='si360-drill-filters'><input class='form-control form-control-sm' data-f='q' placeholder='CVE, pacote ou ativo…'>`
                    + `<select class='form-select form-select-sm' data-f='sev'><option value=''>Todas as severidades</option>${['Critical', 'High', 'Medium', 'Low', '-'].map((k) => `<option value='${k}'${f.sev === k ? ' selected' : ''}>${SEV[k][0]}</option>`).join('')}</select>`
                    + `<select class='form-select form-select-sm' data-f='sort'><option value='score'>Maior CVSS</option><option value='old'>Mais antigas</option><option value='recent'>Mais recentes</option></select></div><div data-res>${spin}</div>`;
                $('[data-f=sort]', body).value = f.sort;
                const run = async () => {
                    const res = $('[data-res]', body);
                    res.innerHTML = spin;
                    try {
                        const d = await api('vuln/list', Object.assign({}, base, {sev: f.sev, q: f.q, sort: f.sort, page: f.page}));
                        $('[data-n]', box).textContent = `${num(d.total)} vulnerabilidade(s) · ${d.assets} ativo(s) · ${num(d.cves)} CVE(s)`;
                        res.innerHTML = `<div class='si-sevstrip'>${sevRows.map((k) => `<button type='button' class='si-sevchip${f.sev === k ? ' on' : ''}' data-sev='${k}' style='--c:${SEV[k][1]}'>${SEV[k][0]} <b>${num(d.sev[k])}</b></button>`).join('')}</div>`
                            + `<table class='table table-sm table-vcenter'><thead><tr><th>CVE</th><th>Severidade</th><th>CVSS</th><th>Ativo</th><th>Pacote</th><th>Correção</th><th>Detectada</th></tr></thead><tbody>`
                            + (d.items.map((v) => `<tr class='si360-click' data-cve='${esc(v.cve)}' title='${esc(v.description)}'><td class='text-nowrap'>${ttl(v.cve)}</td><td>${sevPill(v.severity)}</td><td>${v.score > 0 ? dec(v.score) : '—'}</td>`
                                + `<td class='small'>${esc(v.asset)}<div class='text-secondary'>${esc(v.entity)}</div></td><td class='small'>${esc(v.package)}<div class='text-secondary'>${esc(v.version)}</div></td><td class='small'>${fixTxt(v)}</td>`
                                + `<td class='small text-nowrap'>${v.detected ? new Date(v.detected).toLocaleDateString('pt-BR') : '—'}<div class='text-secondary'>há ${v.days ?? '—'} d</div></td></tr>`).join('') || empty('Nada com estes filtros.', 7)) + `</tbody></table>`
                            + `<div class='d-flex justify-content-between align-items-center p-3'><button class='btn btn-sm btn-ghost-secondary' data-prev ${f.page ? '' : 'disabled'}>‹ Anteriores</button>`
                            + `<span class='small text-secondary'>${d.total ? f.page * d.size + 1 : 0}–${Math.min((f.page + 1) * d.size, d.total)} de ${num(d.total)}</span><button class='btn btn-sm btn-ghost-secondary' data-next ${(f.page + 1) * d.size < d.total ? '' : 'disabled'}>Próximas ›</button></div>`;
                        $$('[data-sev]', res).forEach((b) => b.addEventListener('click', () => { f.sev = f.sev === b.dataset.sev ? '' : b.dataset.sev; $('[data-f=sev]', body).value = f.sev; f.page = 0; run(); }));
                        $$('[data-cve]', res).forEach((r) => r.addEventListener('click', (e) => { if (!e.target.closest('a')) openView(cveView(r.dataset.cve)); }));
                        $('[data-prev]', res)?.addEventListener('click', () => { f.page--; run(); });
                        $('[data-next]', res)?.addEventListener('click', () => { f.page++; run(); });
                    } catch (e) { res.innerHTML = `<div class='alert alert-danger m-3'>${esc(e.message)}</div>`; }
                };
                $$('[data-f]', body).forEach((c) => c.addEventListener(c.tagName === 'SELECT' ? 'change' : 'input', () => { f[c.dataset.f] = c.value; f.page = 0; clearTimeout(run._t); run._t = setTimeout(run, 300); }));
                run();
            }};
        }

        // ------------------------------------------------------------- gaveta: todos os detalhes de uma vulnerabilidade (CVE)
        function cveView(id) {
            return {title: id, icon: 'ti-bug', render: async (body, box) => {
                body.innerHTML = spin;
                try {
                    const d = await api('vuln/cve', {id});
                    const col = (SEV[d.severity] || SEV['-'])[1];
                    const fixes = [...new Set(d.rows.filter((r) => r.fix).map((r) => `${r.package} ≥ ${r.fix}`))];
                    const ring = d.score > 0 ? Math.round(d.score * 10) : 0;
                    $('[data-n]', box).textContent = `${d.total} ativo(s) afetado(s)`;
                    $('.si360-drill-head b', box).innerHTML = `<i class='ti ti-bug me-2'></i>${esc(d.cve)}`;
                    body.innerHTML = `<div class='si-hero'><div class='si-ring' style='--p:${ring};--c:${col}'><span>${d.score > 0 ? dec(d.score) : '—'}</span><small>CVSS${d.score_version ? ' ' + esc(d.score_version) : ''}</small></div>`
                        + `<div class='flex-grow-1'><div class='d-flex flex-wrap gap-2 align-items-center mb-2'><span class='h3 m-0'>${esc(d.cve)}</span>${sevPill(d.severity)}${d.under_evaluation ? `<span class='si360-pill warn'>em avaliação</span>` : ''}</div>`
                        + `<div class='si-meta'><span><i class='ti ti-calendar-event me-1'></i>Publicada em ${d.published ? new Date(d.published).toLocaleDateString('pt-BR') : '—'}</span><span><i class='ti ti-radar me-1'></i>Detectada há ${d.days ?? '—'} dia(s)</span>`
                        + `<span><i class='ti ti-server-2 me-1'></i>${d.total} ativo(s)</span>${d.category ? `<span><i class='ti ti-category me-1'></i>${esc(d.category)}</span>` : ''}${d.source ? `<span><i class='ti ti-database me-1'></i>${esc(d.source)}</span>` : ''}</div></div></div>`
                        + `<div class='si-sec'>Descrição</div><div class='px-3 pb-2' style='line-height:1.55'>${esc(d.description) || '—'}</div>`
                        + `<div class='si-sec'>Ação sugerida</div><div class='px-3 pb-3'>${fixes.length ? `<div class='si-note ok'><i class='ti ti-circle-check me-2'></i>Atualizar: ${fixes.map((x) => `<code>${esc(x)}</code>`).join(', ')}. Em Ubuntu/Debian: <code>apt update &amp;&amp; apt upgrade</code> e reiniciar se for o kernel.</div>`
                            : `<div class='si-note warn'><i class='ti ti-alert-triangle me-2'></i>O fornecedor ainda não publicou correção. Avalie mitigação (desativar o recurso afetado, restringir acesso) e acompanhe os avisos.</div>`}</div>`
                        + `<div class='si-sec'>Ativos afetados (${d.total})</div><table class='table table-sm table-vcenter'><thead><tr><th>Ativo</th><th>Responsável</th><th>Pacote instalado</th><th>Correção</th><th>Detectada</th><th>Chamado</th></tr></thead><tbody>`
                        + d.rows.map((r) => `<tr><td><a href='#' data-asset='${esc(r.agent)}'>${esc(r.asset)}</a><div class='small text-secondary'>${esc(r.entity)}</div></td><td class='small'>${esc(respOf(r.computer)) || '<span class="text-secondary">sem responsável</span>'}</td>`
                            + `<td class='small'>${esc(r.package)}<div class='text-secondary'>${esc(r.version)} · ${esc(r.arch)} ${esc(r.type)}</div></td><td class='small'>${fixTxt(r)}</td><td class='small text-nowrap'>${r.detected ? new Date(r.detected).toLocaleDateString('pt-BR') : '—'}</td><td data-tkc>${tkCell(r, 'vuln')}</td></tr>`).join('') + `</tbody></table>`
                        + `<div class='si-sec'>Referências</div><div class='px-3 pb-4 small'><div class='mb-2'><a class='btn btn-sm btn-primary me-2' href='${esc(nvd(d.cve))}' target='_blank' rel='noopener'><i class='ti ti-external-link me-1'></i>NVD</a>`
                        + `${d.cti ? `<a class='btn btn-sm btn-ghost-secondary me-2' href='${esc(d.cti)}' target='_blank' rel='noopener'><i class='ti ti-shield-search me-1'></i>Wazuh CTI</a>` : ''}</div>`
                        + `<ul class='si-refs'>${d.references.map((u) => `<li><a href='${esc(u)}' target='_blank' rel='noopener'>${esc(u.replace(/^https?:\/\//, ''))}</a></li>`).join('') || '<li class="text-secondary">Sem referências.</li>'}</ul></div>`;
                    $$('[data-asset]', body).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); openView(assetView(a.dataset.asset)); }));
                } catch (e) { body.innerHTML = `<div class='alert alert-danger m-3'>${esc(e.message)}</div>`; }
            }};
        }

        // ------------------------------------------------------------- gaveta: ativo
        function assetView(id) {
            return {title: 'Ativo', icon: 'ti-server-2', render: async (body, box) => {
                const f = {sev: '', q: '', page: 0, tab: 'pk'};
                body.innerHTML = spin;
                const load = async () => {
                    try {
                        const d = await api('vuln/asset', {id, sev: f.sev, q: f.q, page: f.page});
                        const a = d.asset, c = a.computer;
                        $('.si360-drill-head b', box).innerHTML = `<i class='ti ti-server-2 me-2'></i>${esc(a.name)}`;
                        $('[data-n]', box).textContent = `${num(d.total)} vulnerabilidade(s)`;
                        const tiles = ['Critical', 'High', 'Medium', 'Low'].map((k) => `<button type='button' class='si-tile${f.sev === k ? ' on' : ''}' data-sev='${k}' style='--c:${SEV[k][1]}'><b>${num(d.sev[k])}</b><span>${SEV[k][0]}</span></button>`).join('');
                        const info = `<div class='si-info'><div><span>Endereço</span>${esc(a.ip)}</div><div><span>Sistema</span>${esc(a.os)}<small> ${esc(a.kernel)}</small></div><div><span>Agente</span>${esc(a.version)} · ${esc(a.status || '—')}</div>`
                            + `<div><span>Entidade</span>${c ? esc(c.entity) : 'Sem cadastro no GLPI'}</div><div><span>Computador no GLPI</span>${c ? `<a href='${esc(c.url)}' target='_blank' rel='noopener'>${esc(c.name)}</a>` : '—'}</div><div><span>Responsável</span>${esc(respOf(c)) || '—'}</div></div>`;
                        const tabs = [['pk', `Pacotes (${d.packages.length})`], ['vl', `Vulnerabilidades (${num(d.total)})`], ['tk', `Chamados (${a.tickets.length})`]];
                        let pane = '';
                        if (f.tab === 'pk') {
                            pane = `<table class='table table-sm table-vcenter'><thead><tr><th>Pacote</th><th>CVEs</th><th>Máx. CVSS</th><th>Dias</th><th>Chamado</th></tr></thead><tbody>`
                                + (d.packages.map((p) => `<tr class='si360-click' data-pk='${esc(p.name)}'><td>${esc(p.name)}<div class='small text-secondary'>${esc(p.version)}</div></td><td>${chips(p.sev)}</td><td>${dec(p.score)}</td><td>${p.days ?? '—'}</td><td data-tkc>${tkCell({ticket: p.ticket, last_ticket: null, agent: id, package: p.name}, 'vuln')}</td></tr>`).join('') || empty('Nenhum pacote.', 5)) + `</tbody></table>`;
                        } else if (f.tab === 'vl') {
                            pane = `<div class='si360-drill-filters'><input class='form-control form-control-sm' data-f='q' placeholder='CVE ou pacote…' value='${esc(f.q)}'></div><table class='table table-sm table-vcenter'><thead><tr><th>CVE</th><th>Severidade</th><th>CVSS</th><th>Pacote</th><th>Correção</th><th>Detectada</th></tr></thead><tbody>`
                                + (d.items.map((v) => `<tr class='si360-click' data-cve='${esc(v.cve)}' title='${esc(v.description)}'><td class='text-nowrap'>${ttl(v.cve)}</td><td>${sevPill(v.severity)}</td><td>${v.score > 0 ? dec(v.score) : '—'}</td><td class='small'>${esc(v.package)}<div class='text-secondary'>${esc(v.version)}</div></td>`
                                    + `<td class='small'>${fixTxt(v)}</td><td class='small text-nowrap'>${v.detected ? new Date(v.detected).toLocaleDateString('pt-BR') : '—'}<div class='text-secondary'>${v.days ?? '—'} d</div></td></tr>`).join('') || empty('Nada com estes filtros.', 6)) + `</tbody></table>`
                                + `<div class='d-flex justify-content-between align-items-center p-3'><button class='btn btn-sm btn-ghost-secondary' data-prev ${f.page ? '' : 'disabled'}>‹ Anteriores</button><span class='small text-secondary'>${d.total ? f.page * d.size + 1 : 0}–${Math.min((f.page + 1) * d.size, d.total)} de ${num(d.total)}</span><button class='btn btn-sm btn-ghost-secondary' data-next ${(f.page + 1) * d.size < d.total ? '' : 'disabled'}>Próximas ›</button></div>`;
                        } else {
                            pane = `<div class='p-3'>${a.tickets.map((t) => `<a class='si-ticket' href='${esc(t.url)}' target='_blank' rel='noopener'><span class='si360-pill ${t.open ? 'warn' : 'ok'}'>#${t.id} ${t.open ? 'aberto' : 'fechado'}</span> ${esc(t.name)}</a>`).join('') || '<div class="text-secondary">Nenhum chamado relacionado a este ativo.</div>'}</div>`;
                        }
                        body.innerHTML = `<div class='si-tiles'>${tiles}</div>${mix(d.sev, 'lg')}${info}<div class='si-tabs'>${tabs.map(([k, l]) => `<button type='button' class='${f.tab === k ? 'on' : ''}' data-tab='${k}'>${l}</button>`).join('')}</div>${pane}`;
                        $$('[data-tab]', body).forEach((b) => b.addEventListener('click', () => { f.tab = b.dataset.tab; load(); }));
                        $$('.si-tile', body).forEach((b) => b.addEventListener('click', () => { f.sev = f.sev === b.dataset.sev ? '' : b.dataset.sev; f.tab = 'vl'; f.page = 0; load(); }));
                        $$('[data-cve]', body).forEach((r) => r.addEventListener('click', (e) => { if (!e.target.closest('a')) openView(cveView(r.dataset.cve)); }));
                        $$('[data-pk]', body).forEach((r) => r.addEventListener('click', (e) => { if (!e.target.closest('a,button')) showListFor(`${r.dataset.pk} · ${a.name}`, {agent: id, package: r.dataset.pk}); }));
                        const q = $('[data-f=q]', body);
                        q?.addEventListener('input', () => { f.q = q.value; f.page = 0; clearTimeout(load._t); load._t = setTimeout(async () => { await load(); const n = $('[data-f=q]', body); n?.focus(); n?.setSelectionRange(n.value.length, n.value.length); }, 350); });
                        $('[data-prev]', body)?.addEventListener('click', () => { f.page--; load(); });
                        $('[data-next]', body)?.addEventListener('click', () => { f.page++; load(); });
                    } catch (e) { body.innerHTML = `<div class='alert alert-danger m-3'>${esc(e.message)}</div>`; }
                };
                load();
            }};
        }
        const showListFor = (title, base) => openView(listView(title, base));
        document.addEventListener('click', (e) => { // KPIs e blocos que abrem uma lista
            const b = e.target.closest('[data-vl]');
            if (b && root.contains(b)) showList(b.dataset.vt || 'Vulnerabilidades', JSON.parse(b.dataset.vl));
            const o = e.target.closest('[data-open]');
            if (o) { e.stopPropagation(); openView(assetView(o.dataset.open), true); }
        });
        const vk = (icon, label, value, sub, tone, base, title) => kpi(icon, label, value, sub, tone, base ? `data-vl='${esc(JSON.stringify(base))}' data-vt='${esc(title || label)}' style='cursor:pointer'` : '');
        const openAsset = (id) => openView(assetView(id), true);

        // ------------------------------------------------------------- executiva
        tabLoaders.exec = async () => {
            const el = $('#wz-exec');
            loading(el);
            try {
                const d = await api('vuln/exec');
                const t = theme();
                const fixed = d.mttr.rows ? Object.values(d.mttr.rows) : [];
                const fixedN = fixed.reduce((a, r) => a + r.fixed, 0);
                const avg = fixedN ? fixed.reduce((a, r) => a + r.avg_days * r.fixed, 0) / fixedN : null;
                const ageOrder = Object.keys(AGE);
                d.age.sort((a, b) => ageOrder.indexOf(a.name) - ageOrder.indexOf(b.name));
                const pctCrit = d.assets ? Math.round(100 * d.crit_assets / d.assets) : 0;
                const sevTotal = sevRows.reduce((a, k) => a + d.sev[k], 0) || 1;
                el.innerHTML = `<div class='row g-3 mb-3'>`
                    + vk('ti-bug', 'Vulnerabilidades', num(d.total), `${num(d.cves)} CVEs distintos`, d.total ? 'warn' : 'ok', {}, 'Todas as vulnerabilidades')
                    + kpi('ti-server-2', 'Ativos vulneráveis', `${d.assets} <small>de ${d.agents_total}</small>`, `${d.agents_total ? Math.round(100 * d.assets / d.agents_total) : 0}% dos ativos monitorados`, d.assets ? 'warn' : 'ok', `onclick="document.querySelector('[data-wz-tab=assets]').click()" style='cursor:pointer'`)
                    + vk('ti-flame', 'Riscos críticos', num(d.sev.Critical), `em ${d.crit_assets} ativo(s)`, d.sev.Critical ? 'bad' : 'ok', {sev: 'Critical'}, 'Vulnerabilidades críticas')
                    + vk('ti-alert-octagon', 'Altas', num(d.sev.High), 'severidade alta', d.sev.High ? 'warn' : 'ok', {sev: 'High'}, 'Vulnerabilidades altas')
                    + vk('ti-hourglass-high', 'Críticas há mais de 30 dias', num(d.crit_old), 'sem correção', d.crit_old ? 'bad' : 'ok', {sev: 'Critical', sort: 'old'}, 'Críticas mais antigas')
                    + kpi('ti-clock', 'Tempo médio de correção', avg == null ? '—' : dec(avg) + ' <small>dias</small>', avg == null ? 'medido a partir da 1ª correção' : `${fixedN} corrigida(s) em 180 dias`, avg != null && avg > 30 ? 'warn' : '')
                    + kpi('ti-circle-check', 'Corrigidas em 30 dias', num(d.mttr.fixed30 || 0), 'sumiram do Wazuh após atualização', (d.mttr.fixed30 || 0) ? 'ok' : '')
                    + vk('ti-shield-half', 'Sem classificação', num(d.sev['-']), 'o Wazuh ainda não pontuou', '', {sev: '-'}, 'Sem classificação')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Exposição crítica', 'ti-gauge', `<div class='card-body'><div class='si360-chart' id='wzc-gauge' style='height:230px'></div><div class='text-center small text-secondary'>${d.crit_assets} de ${d.assets} ativo(s) com vulnerabilidade crítica</div></div>`, 'col-xl-3 col-md-6')
                    + card('Severidade', 'ti-chart-donut-3', `<div class='card-body'><div class='row g-0 align-items-center'><div class='col-7'><div class='si360-chart' id='wzc-sev' style='height:250px'></div></div><div class='col-5'><div class='si-legend'>`
                        + sevRows.map((k) => `<button type='button' class='si-lg' data-vl='${esc(JSON.stringify({sev: k}))}' data-vt='${esc(SEV[k][0])}' style='--c:${SEV[k][1]}'><i></i><span>${SEV[k][0]}</span><b>${num(d.sev[k])}</b><small>${(100 * d.sev[k] / sevTotal).toFixed(1).replace('.', ',')}%</small></button>`).join('') + `</div></div></div></div>`, 'col-xl-5 col-md-6')
                    + card('Distribuição por CVSS', 'ti-chart-histogram', `<div class='card-body'><div class='si360-chart' id='wzc-cvss' style='height:250px'></div></div>`, 'col-xl-4')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Evolução', 'ti-chart-area-line', d.snaps.length < 2
                        ? `<div class='card-body'><div class='si-snap'><div class='si-snap-top'><b>${num(d.total)}</b><span>vulnerabilidades hoje</span></div>${mix(d.sev, 'xl')}<div class='si-snap-grid'>`
                            + sevRows.map((k) => `<div style='--c:${SEV[k][1]}'><i></i><span>${SEV[k][0]}</span><b>${num(d.sev[k])}</b><small>${(100 * d.sev[k] / sevTotal).toFixed(1).replace('.', ',')}%</small></div>`).join('')
                            + `</div><div class='si-snap-note'><i class='ti ti-clock-hour-4 me-1'></i>O histórico começa agora: o GLPI guarda uma foto por dia e a evolução aparece aqui a partir da próxima.</div></div></div>`
                        : `<div class='card-body'><div class='si360-chart' id='wzc-evo' style='height:300px'></div></div>`, 'col-xl-7')
                    + card('Idade (tempo desde a detecção)', 'ti-calendar-time', `<div class='card-body'><div class='si360-chart' id='wzc-age' style='height:300px'></div></div>`, 'col-xl-5')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Ativos mais expostos', 'ti-server-bolt', `<div class='card-body'><div class='si360-chart' id='wzc-agents' style='height:340px'></div></div>`, 'col-xl-6')
                    + card('Pacotes mais afetados', 'ti-package', `<div class='card-body'><div class='si360-chart' id='wzc-pkgs' style='height:340px'></div></div>`, 'col-xl-6')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Detecções por dia', 'ti-timeline', `<div class='card-body'><div class='si360-chart' id='wzc-det' style='height:290px'></div></div>`, 'col-xl-8')
                    + card('Tempo de correção', 'ti-stopwatch', `<div class='card-body'><div class='si360-chart' id='wzc-mttr' style='height:200px'></div><div class='small text-secondary'>${fixedN ? `${fixedN} correção(ões) observada(s) em 180 dias.` : 'Cada vulnerabilidade que some do Wazuh conta como corrigida' + (d.mttr.since ? ` (medição desde ${new Date(d.mttr.since + 'T12:00').toLocaleDateString('pt-BR')}).` : '.')}</div></div>`, 'col-xl-4')
                    + `</div><div class='row g-3'>`
                    + card('Riscos críticos', 'ti-flame', `<div class='si360-scroll'><table class='table table-vcenter card-table table-sm'><thead><tr><th>CVE</th><th>CVSS</th><th>Pacote</th><th>Ativos</th><th>Dias</th></tr></thead><tbody>`
                        + (d.top_crit.map((c) => `<tr class='si360-click' data-cve='${esc(c.cve)}'><td>${ttl(c.cve)}</td><td><span class='si360-sev' style='--c:${SEV.Critical[1]}'>${dec(c.score)}</span></td><td>${esc(c.package)}</td><td>${c.assets}</td><td>${c.days ?? '—'}</td></tr>`).join('') || empty('Nenhuma vulnerabilidade crítica.', 5)) + `</tbody></table></div>`, 'col-xl-7', `<div class='card-actions small text-secondary'>clique para ver todos os detalhes</div>`)
                    + card('Sistemas operacionais', 'ti-brand-ubuntu', `<div class='card-body'><div class='si360-chart' id='wzc-os' style='height:230px'></div></div>`, 'col-xl-5')
                    + `</div>`;
                $$('[data-cve]', el).forEach((r) => r.addEventListener('click', (e) => { if (!e.target.closest('a')) openView(cveView(r.dataset.cve), true); }));
                charts = [];
                withCharts(() => {
                    const col = (k) => SEV[k][1];
                    // exposição crítica: medidor com faixas e ponteiro
                    const rc = pctCrit >= 60 ? '#ef4f4f' : pctCrit >= 30 ? '#f7b84b' : '#3ecf8e';
                    mk('wzc-gauge', {tooltip: {show: false}, series: [{type: 'gauge', startAngle: 200, endAngle: -20, min: 0, max: 100, splitNumber: 4, radius: '94%', center: ['50%', '58%'],
                        axisLine: {roundCap: true, lineStyle: {width: 12, color: [[0.3, '#3ecf8e'], [0.6, '#f7b84b'], [1, '#ef4f4f']]}},
                        axisTick: {show: false}, splitLine: {distance: -12, length: 12, lineStyle: {color: '#0c1e3d', width: 3}},
                        axisLabel: {distance: 16, color: t.muted, fontSize: 10, formatter: (v) => v + '%'},
                        pointer: {width: 4, length: '52%', itemStyle: {color: '#e7eefb'}}, anchor: {show: true, size: 12, itemStyle: {color: '#e7eefb', borderColor: '#0c1e3d', borderWidth: 4}},
                        title: {show: false}, detail: {valueAnimation: true, offsetCenter: [0, '62%'], fontSize: 30, fontWeight: 700, color: rc, formatter: '{value}%'},
                        data: [{value: pctCrit}]}]});
                    // severidade
                    donut('wzc-sev', sevRows.filter((k) => d.sev[k]).map((k) => ({name: SEV[k][0], value: d.sev[k], key: k, itemStyle: {color: col(k)}})), (ev) => showList(`Severidade: ${ev.name}`, {sev: ev.data.key}), 'vulnerabilidades', false);
                    // distribuição por CVSS
                    const band = (f) => (f >= 9 ? '#ef4f4f' : f >= 7 ? '#f2783f' : f >= 4 ? '#f7b84b' : '#5b8def');
                    mk('wzc-cvss', {tooltip: Object.assign({trigger: 'axis', axisPointer: POINTER, formatter: (ps) => `<b>CVSS ${esc(ps[0].name)}</b><br>${num(ps[0].value)} vulnerabilidade(s)`}, ttip), toolbox: TB(t), grid: {left: 46, right: 8, top: 26, bottom: 26},
                        xAxis: AXC(t, d.cvss.map((b) => (b.from === 10 ? '10' : `${b.from}–${b.from + 1}`))), yAxis: AXV(t),
                        series: [{type: 'bar', barWidth: '56%', data: d.cvss.map((b) => ({value: b.count, itemStyle: {color: band(b.from), borderRadius: [5, 5, 0, 0]}})), emphasis: {itemStyle: {shadowBlur: 12, shadowColor: 'rgba(0,0,0,.4)'}},
                            label: {show: true, position: 'top', color: t.muted, fontSize: 10, formatter: (p) => (p.value ? num(p.value) : '')}}]},
                        (ev) => { const b = d.cvss[ev.dataIndex]; showList(`CVSS ${b.from === 10 ? '10' : b.from + '–' + (b.from + 1)}`, b.from === 10 ? {min: 10} : {min: b.from, max: b.from + 1}); });
                    // evolução (fotos diárias do GLPI) ou, enquanto não há histórico, detecções por dia
                    if (d.snaps.length >= 2) {
                        const xs = d.snaps.map((s2) => new Date(s2.day + 'T12:00').toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit'}));
                        mk('wzc-evo', {tooltip: Object.assign({trigger: 'axis', axisPointer: CROSS}, ttip), legend: LEG(t), toolbox: TB(t, true), grid: {left: 50, right: 14, top: 40, bottom: d.snaps.length > 14 ? 52 : 28}, dataZoom: ZOOM(t, d.snaps.length > 14),
                            xAxis: AXC(t, xs, {boundaryGap: false}), yAxis: AXV(t),
                            series: ['Critical', 'High', 'Medium', 'Low'].map((k) => ({name: SEV[k][0], type: 'line', stack: 'e', smooth: 0.3, symbol: 'circle', symbolSize: 7, showSymbol: false, lineStyle: {width: 2.5, color: col(k)}, itemStyle: {color: col(k)},
                                areaStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: col(k) + '66'}, {offset: 1, color: col(k) + '08'}])}, emphasis: {focus: 'series'}, data: d.snaps.map((s2) => s2[k])}))});
                    }
                    // idade
                    stackedBar('wzc-age', d.age.map((a2) => ({label: a2.name, v: a2.sev})), sevSeries,
                        (ev) => showList(`Idade: ${d.age[ev.dataIndex].name}`, {age: AGE[d.age[ev.dataIndex].name], sev: sevRows[ev.seriesIndex] ?? ''}), 120);
                    // ativos mais expostos
                    stackedBar('wzc-agents', d.by_agent.map((a2) => ({label: a2.name, v: a2.sev})), sevSeries, (ev) => openAsset(d.by_agent[ev.dataIndex].id), 150);
                    // pacotes mais afetados
                    const pk = d.pkgs.slice(0, 10);
                    stackedBar('wzc-pkgs', pk.map((p2) => ({label: p2.name, v: p2.sev})), sevSeries, (ev) => showList(`Pacote: ${pk[ev.dataIndex].name}`, {package: pk[ev.dataIndex].name}), 190);
                    // detecções por dia
                    stackedCols('wzc-det', d.timeline.map((s2) => s2.day), sevRows.map((k) => [SEV[k][0], col(k), d.timeline.map((s2) => s2.sev[k])]),
                        (ev) => showList(`Detectadas em ${d.timeline[ev.dataIndex].day.split('-').reverse().join('/')}`, {day: d.timeline[ev.dataIndex].day, sev: sevRows[ev.seriesIndex] ?? ''}),
                        {xAxis: {axisLabel: {color: t.muted, fontSize: 11, formatter: (v) => new Date(v + 'T12:00').toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit'})}}, barMax: 60});
                    // tempo de correção
                    const mt = ['Critical', 'High', 'Medium', 'Low'].filter((k) => d.mttr.rows && d.mttr.rows[k]);
                    if (mt.length) hbar('wzc-mttr', mt.map((k) => ({label: SEV[k][0], value: d.mttr.rows[k].avg_days, color: col(k)})), null, null, 80);
                    else mk('wzc-mttr', {title: {text: '—', subtext: 'ainda sem correções observadas', left: 'center', top: '32%', textStyle: {color: t.fg, fontSize: 38, fontWeight: 700}, subtextStyle: {color: t.muted, fontSize: 12}}});
                    // sistemas operacionais
                    stackedBar('wzc-os', d.os.map((o2) => ({label: o2.name, v: o2.sev})), sevSeries, null, 190);
                });
            } catch (e) { fail(el, e); }
        };

        // ------------------------------------------------------------- por ativo (cartões)
        tabLoaders.assets = async () => {
            const el = $('#wz-assets');
            if (!$('[data-a-body]', el)) {
                el.innerHTML = `<div class='row g-3 mb-3' data-a-kpi></div><div class='card si360-card mb-3'><div class='card-body py-2 d-flex flex-wrap gap-2 align-items-center'>`
                    + `<div class='si-chips' data-chips>${[['', 'Todos'], ['crit', 'Com crítica'], ['noowner', 'Sem responsável'], ['notk', 'Sem chamado'], ['nocomp', 'Sem cadastro no GLPI']].map(([k, l]) => `<button type='button' class='si-chip${state.chip === k ? ' on' : ''}' data-chip='${k}'>${l}</button>`).join('')}</div>`
                    + `<div class='ms-auto d-flex flex-wrap gap-2'><span data-ent></span><select class='form-select form-select-sm w-auto' data-sort><option value='crit'>Ordenar: mais críticas</option><option value='total'>Ordenar: mais vulnerabilidades</option><option value='old'>Ordenar: mais antigas</option><option value='name'>Ordenar: nome</option></select>`
                    + `<input class='form-control form-control-sm' style='width:240px' data-q placeholder='Ativo, IP, responsável…' value='${esc(state.q)}'></div></div></div><div class='row g-3' data-a-body></div>`;
                $('[data-q]', el).addEventListener('input', (e) => { state.q = e.target.value; clearTimeout(tabLoaders._t); tabLoaders._t = setTimeout(draw, 250); });
                el.addEventListener('change', (e) => { if (e.target.matches('[data-entity]')) { state.entity = e.target.value; tabLoaders.assets(); } if (e.target.matches('[data-sort]')) draw(); });
                el.addEventListener('click', (e) => {
                    const c = e.target.closest('[data-chip]');
                    if (c) { state.chip = c.dataset.chip; $$('[data-chip]', el).forEach((x) => x.classList.toggle('on', x === c)); draw(); return; }
                    const r = e.target.closest('[data-asset]');
                    if (r && !e.target.closest('a')) openAsset(r.dataset.asset);
                });
            }
            let data = null;
            const draw = () => {
                if (!data) return;
                const sort = $('[data-sort]', el).value;
                const q = state.q.toLowerCase();
                let list = data.assets.filter((a) => (!q || [a.name, a.ip, a.os, a.computer?.name, a.computer?.tech, a.computer?.owner, a.computer?.tech_group].join(' ').toLowerCase().includes(q))
                    && (state.chip === '' || (state.chip === 'crit' && a.sev.Critical) || (state.chip === 'noowner' && !respOf(a.computer)) || (state.chip === 'notk' && !a.open_tickets) || (state.chip === 'nocomp' && !a.computer)));
                list = list.sort((a, b) => (sort === 'name' ? a.name.localeCompare(b.name) : sort === 'total' ? b.total - a.total : sort === 'old' ? (b.days ?? 0) - (a.days ?? 0) : (b.sev.Critical - a.sev.Critical || b.sev.High - a.sev.High)));
                $('[data-a-body]', el).innerHTML = list.map((a) => {
                    const c = a.computer;
                    const resp = respOf(c);
                    const pct = a.total ? Math.round(100 * (a.sev.Critical + a.sev.High) / a.total) : 0;
                    return `<div class='col-xl-4 col-md-6'><div class='si-asset si360-click' data-asset='${esc(a.id)}'><div class='si-asset-head'><span class='si-asset-ico'><i class='ti ti-server-2'></i></span>`
                        + `<div class='flex-grow-1' style='min-width:0'><b class='text-truncate d-block'>${esc(a.name)}</b><div class='small text-secondary text-truncate'>${esc(a.ip)} · ${esc(a.os)}</div></div>`
                        + `<span class='si360-pill ${a.status === 'active' ? 'ok' : 'bad'}'>${a.status === 'active' ? 'Ativo' : esc(a.status || '—')}</span></div>`
                        + `<div class='si-asset-num'><div><b style='color:${SEV.Critical[1]}'>${num(a.sev.Critical)}</b><span>Críticas</span></div><div><b style='color:${SEV.High[1]}'>${num(a.sev.High)}</b><span>Altas</span></div><div><b style='color:${SEV.Medium[1]}'>${num(a.sev.Medium)}</b><span>Médias</span></div><div><b style='color:${SEV.Low[1]}'>${num(a.sev.Low)}</b><span>Baixas</span></div></div>`
                        + `${mix(a.sev)}<div class='d-flex justify-content-between small text-secondary mb-2'><span>${num(a.total)} vulnerabilidade(s)</span><span>${pct}% crítica/alta</span></div>`
                        + `<dl class='si-dl'><dt>Entidade</dt><dd>${c ? esc(c.entity) : '<span class="si360-pill">Sem cadastro no GLPI</span>'}</dd><dt>Computador</dt><dd>${c ? `<a href='${esc(c.url)}' target='_blank' rel='noopener'>${esc(c.name)}</a>` : '—'}</dd>`
                        + `<dt>Responsável</dt><dd>${resp ? esc(resp) : '<span class="text-warning">sem responsável</span>'}</dd><dt>Pacotes</dt><dd>${a.packages} afetado(s) · mais antiga há ${a.days ?? '—'} d</dd>`
                        + `<dt>Chamados</dt><dd>${a.tickets.slice(0, 3).map((t) => `<a class='si360-pill ${t.open ? 'warn' : ''} me-1' href='${esc(t.url)}' target='_blank' rel='noopener' title='${esc(t.name)}'>#${t.id}</a>`).join('') || '<span class="text-secondary">nenhum</span>'}${a.open_tickets ? ` <small class='text-secondary'>${a.open_tickets} aberto(s)</small>` : ''}</dd></dl>`
                        + `<div class='d-flex gap-2'><button class='btn btn-sm btn-primary flex-grow-1' data-open='${esc(a.id)}'><i class='ti ti-list-details me-1'></i>Detalhar</button></div></div></div>`;
                }).join('') || `<div class='col-12'><div class='card si360-card'><div class='card-body text-center text-secondary py-5'>Nenhum ativo com estes filtros.</div></div></div>`;
            };
            const body = $('[data-a-body]', el);
            body.innerHTML = `<div class='col-12'>${spin}</div>`;
            try {
                data = await api('vuln/assets', {entity: state.entity});
                $('[data-ent]', el).innerHTML = entitySelect(data.entities, state.entity);
                const A = data.assets;
                $('[data-a-kpi]', el).innerHTML = kpi('ti-server-2', 'Ativos', A.length, `${A.filter((a) => a.total).length} com vulnerabilidade`, '') + kpi('ti-flame', 'Com vulnerabilidade crítica', A.filter((a) => a.sev.Critical).length, 'precisam de ação', A.some((a) => a.sev.Critical) ? 'bad' : 'ok')
                    + kpi('ti-user-question', 'Sem responsável', A.filter((a) => !respOf(a.computer)).length, 'defina técnico ou grupo no computador', A.some((a) => !respOf(a.computer)) ? 'warn' : 'ok') + kpi('ti-ticket', 'Com chamado aberto', A.filter((a) => a.open_tickets).length, `${A.filter((a) => a.sev.Critical && !a.open_tickets).length} crítico(s) sem chamado`, A.some((a) => a.sev.Critical && !a.open_tickets) ? 'warn' : 'ok');
                draw();
            } catch (e) { body.innerHTML = `<div class='col-12'><div class='alert alert-danger'>${esc(e.message)}</div></div>`; }
        };

        // ------------------------------------------------------------- operacional
        tabLoaders.ops = async () => {
            const el = $('#wz-ops');
            const s = state.ops;
            let rowsAll = [];
            if (!$('[data-ops-body]', el)) {
                el.innerHTML = `<div class='row g-3 mb-3' data-sum></div><div class='row g-3 mb-3'>`
                    + card('Fila por prioridade', 'ti-chart-donut-3', `<div class='card-body'><div class='si360-chart' id='wzc-oprio' style='height:230px'></div></div>`, 'col-xl-4')
                    + card('Fila por entidade', 'ti-building', `<div class='card-body'><div class='si360-chart' id='wzc-oent' style='height:230px'></div></div>`, 'col-xl-4')
                    + card('Pacotes com mais CVEs', 'ti-package', `<div class='card-body'><div class='si360-chart' id='wzc-opk' style='height:230px'></div></div>`, 'col-xl-4') + `</div>`
                    + `<div class='card si360-card mb-3'><div class='card-header d-flex flex-wrap gap-2 align-items-center'><h3 class='card-title'><i class='ti ti-list-check me-2'></i>Prioridades de correção</h3>`
                    + `<div class='ms-auto d-flex flex-wrap gap-2 align-items-center'><div class='si-chips' data-prio>${['P1', 'P2', 'P3', 'P4'].map((p) => `<button type='button' class='si-chip${s.prio.includes(p) ? ' on' : ''}' data-p='${p}'>${p}</button>`).join('')}</div><span data-ent></span><select class='form-select form-select-sm w-auto' data-sev>`
                    + [['Critical', 'Só críticas'], ['High', 'Altas ou mais'], ['Medium', 'Médias ou mais'], ['Low', 'Todas']].map(([k, l]) => `<option value='${k}'${s.sev === k ? ' selected' : ''}>${l}</option>`).join('')
                    + `</select><input class='form-control form-control-sm' style='width:200px' data-oq placeholder='Ativo ou pacote…' value='${esc(s.q)}'>`
                    + `<label class='form-check form-switch mb-0'><input class='form-check-input' type='checkbox' data-free${s.free ? ' checked' : ''}><span class='form-check-label small'>Só sem chamado</span></label></div></div>`
                    + `<div class='si-bulk' data-bulk hidden><span data-bulk-n></span><button type='button' class='btn btn-sm btn-primary' data-bulk-go><i class='ti ti-ticket me-1'></i>Abrir chamados dos selecionados</button><button type='button' class='btn btn-sm btn-ghost-secondary' data-bulk-clear>Limpar seleção</button></div>`
                    + `<div class='table-responsive si360-scroll' style='max-height:600px'><table class='table table-vcenter card-table table-sm'><thead><tr><th style='width:28px'></th><th>Prior.</th><th>Ativo</th><th>Pacote</th><th>Severidade</th><th>CVSS</th><th>Correção</th><th>Dias</th><th>Chamado</th></tr></thead><tbody data-ops-body></tbody></table></div></div>`
                    + `<div class='card si360-card'><div class='card-header d-flex flex-wrap gap-2 align-items-center'><h3 class='card-title'><i class='ti ti-bell-ringing me-2'></i>Alertas do Wazuh que podem virar chamado</h3>`
                    + `<div class='ms-auto d-flex gap-2 align-items-center'><select class='form-select form-select-sm w-auto' data-hours>${[[24, 'Últimas 24 h'], [72, 'Últimos 3 dias'], [168, 'Últimos 7 dias']].map(([h, l]) => `<option value='${h}'${state.hours === h ? ' selected' : ''}>${l}</option>`).join('')}</select><span class='small text-secondary'>nível ≥ ${esc(root.dataset.minlevel)}</span></div></div>`
                    + `<div class='table-responsive si360-scroll' style='max-height:460px'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Nível</th><th>Alerta</th><th>Ativo</th><th>Ocorrências</th><th>Último</th><th>Chamado</th></tr></thead><tbody data-al-body></tbody></table></div></div>`;
                el.addEventListener('change', (e) => {
                    if (e.target.matches('[data-entity]')) { state.entity = e.target.value; load(); loadAlerts(); }
                    if (e.target.matches('[data-sev]')) { s.sev = e.target.value; load(); }
                    if (e.target.matches('[data-free]')) { s.free = e.target.checked; load(); }
                    if (e.target.matches('[data-hours]')) { state.hours = +e.target.value; loadAlerts(); }
                    if (e.target.matches('[data-pick]')) bulk();
                });
                el.addEventListener('click', (e) => {
                    const p = e.target.closest('[data-p]');
                    if (p) { s.prio = s.prio.includes(p.dataset.p) ? s.prio.filter((x) => x !== p.dataset.p) : [...s.prio, p.dataset.p]; p.classList.toggle('on'); drawOps(); return; }
                    if (e.target.closest('[data-bulk-clear]')) { $$('[data-pick]', el).forEach((c) => { c.checked = false; }); bulk(); return; }
                    if (e.target.closest('[data-bulk-go]')) { bulkGo(); return; }
                    const al = e.target.closest('[data-al]');
                    if (al && !e.target.closest('a,button')) { openView({title: al.dataset.t, icon: 'ti-bolt', render: async (b, box) => {
                        b.innerHTML = spin;
                        try {
                            const d = await api('sec/alerts', {field: 'rule.id', value: al.dataset.al, agent: al.dataset.agent, period: state.hours > 24 ? '7d' : '24h'});
                            $('[data-n]', box).textContent = `${num(d.total)} evento(s)`;
                            b.innerHTML = `<table class='table table-sm table-vcenter'><thead><tr><th>Quando</th><th>Nível</th><th>Alerta</th><th>Detalhe</th></tr></thead><tbody>${d.items.map((a) => `<tr><td class='small text-nowrap'>${when(a.t)}</td><td>${lvlPill(a.level)}</td><td>${esc(a.description)}<div class='small text-secondary'>${a.srcip ? 'de ' + esc(a.srcip) : ''} ${esc(a.user)}</div></td><td class='small text-secondary' style='word-break:break-word'>${esc(a.log)}</td></tr>`).join('') || empty('Nenhum evento.', 4)}</tbody></table>`;
                        } catch (er) { b.innerHTML = `<div class='alert alert-danger m-3'>${esc(er.message)}</div>`; }
                    }}, true); return; }
                    const r = e.target.closest('[data-pkrow]');
                    if (r && !e.target.closest('a,button,input')) showList(`${r.dataset.pkrow} · ${r.dataset.an}`, {agent: r.dataset.ag, package: r.dataset.pkrow});
                });
                $('[data-oq]', el).addEventListener('input', (e) => { s.q = e.target.value; clearTimeout(load._t); load._t = setTimeout(load, 350); });
            } else {
                $('[data-sev]', el).value = s.sev;
            }
            const bulk = () => {
                const n = $$('[data-pick]:checked', el).length;
                $('[data-bulk]', el).hidden = !n;
                $('[data-bulk-n]', el).textContent = `${n} selecionado(s)`;
            };
            const bulkGo = async () => {
                const sel = $$('[data-pick]:checked', el).map((c) => ({agent: c.dataset.ag, ref: c.dataset.pk}));
                if (!sel.length || !confirm(`Abrir ${sel.length} chamado(s)? Pacotes que já têm chamado em aberto não são duplicados.`)) return;
                const btn = $('[data-bulk-go]', el);
                btn.disabled = true;
                let ok = 0, dup = 0, err = '';
                for (const [i, x] of sel.entries()) {
                    btn.innerHTML = `<span class='spinner-border spinner-border-sm me-1'></span>${i + 1} de ${sel.length}…`;
                    try { const r = await post('ticket', {kind: 'vuln', agent: x.agent, ref: x.ref}); if (r.duplicate) dup++; else ok++; } catch (e) { err = e.message; break; }
                }
                btn.disabled = false;
                btn.innerHTML = `<i class='ti ti-ticket me-1'></i>Abrir chamados dos selecionados`;
                alert(`${ok} chamado(s) aberto(s)` + (dup ? `, ${dup} já existia(m)` : '') + (err ? `. Parou com erro: ${err}` : '.'));
                load();
            };
            const lvl = {P1: ['#ef4f4f', 'Crítica'], P2: ['#f2783f', 'Alta'], P3: ['#f7b84b', 'Média'], P4: ['#5b8def', 'Baixa']};
            const drawOps = () => {
                const rows = rowsAll.filter((r) => s.prio.includes(r.level));
                $('[data-ops-body]', el).innerHTML = rows.map((r) => `<tr class='si360-click' data-pkrow='${esc(r.package)}' data-ag='${esc(r.agent)}' data-an='${esc(r.asset)}'><td>${r.ticket || !canTicket ? '' : `<input type='checkbox' class='form-check-input' data-pick data-ag='${esc(r.agent)}' data-pk='${esc(r.package)}'>`}</td>`
                    + `<td><span class='si360-sev' style='--c:${lvl[r.level][0]}' title='${lvl[r.level][1]}'>${r.level}</span></td><td><b>${esc(r.asset)}</b><div class='small text-secondary'>${esc(r.entity)}${r.computer ? '' : ' · sem cadastro no GLPI'}</div></td>`
                    + `<td>${esc(r.package)}<div class='small text-secondary'>${esc(r.version)} · ${num(r.cves)} CVE(s)</div></td><td style='min-width:130px'>${mix(r.sev)}<div class='mt-1'>${chips(r.sev)}</div></td><td>${dec(r.score)}</td>`
                    + `<td class='small'>${r.fix ? `<span class='text-success'>≥ ${esc(r.fix)}</span>` : '<span class="text-secondary">sem correção</span>'}</td><td>${r.days}</td><td data-tkc>${tkCell(r, 'vuln')}</td></tr>`).join('')
                    || empty('Nada na fila com estes filtros.', 9);
                bulk();
            };
            const load = async () => {
                $('[data-ops-body]', el).innerHTML = empty(`<span class='spinner-border spinner-border-sm me-2'></span>Consultando…`, 9);
                try {
                    const d = await api('vuln/ops', {entity: state.entity, sev: s.sev, q: s.q, free: s.free ? 1 : ''});
                    rowsAll = d.rows;
                    $('[data-ent]', el).innerHTML = entitySelect(d.entities, state.entity);
                    $('[data-sum]', el).innerHTML = kpi('ti-flame', 'P1 · críticas', d.summary.p1, 'corrigir primeiro', d.summary.p1 ? 'bad' : 'ok') + kpi('ti-alert-octagon', 'P2 · altas', d.summary.p2, 'em seguida', d.summary.p2 ? 'warn' : 'ok')
                        + kpi('ti-list', 'Itens na fila', d.summary.total, 'pacote × ativo', '') + kpi('ti-ticket', 'Já com chamado', d.summary.with_ticket, 'não abre duplicado', 'ok');
                    drawOps();
                    charts = [];
                    withCharts(() => {
                        const t = theme();
                        const cnt = ['P1', 'P2', 'P3', 'P4'].map((p) => rowsAll.filter((r) => r.level === p).length);
                        donut('wzc-oprio', ['P1', 'P2', 'P3', 'P4'].map((p, i) => ({name: `${p} · ${lvl[p][1]}`, value: cnt[i], key: p, itemStyle: {color: vGrad(lvl[p][0])}})).filter((x) => x.value), (ev) => { s.prio = [ev.data.key]; $$('[data-p]', el).forEach((x) => x.classList.toggle('on', x.dataset.p === ev.data.key)); drawOps(); }, 'itens');
                        const byEnt = {};
                        rowsAll.forEach((r) => { byEnt[r.entity] ??= {P1: 0, P2: 0, P3: 0, P4: 0}; byEnt[r.entity][r.level]++; });
                        const ents = Object.entries(byEnt).sort((a, b) => (b[1].P1 + b[1].P2) - (a[1].P1 + a[1].P2)).slice(0, 8);
                        stackedBar('wzc-oent', ents.map((e) => ({label: e[0].split(' > ').pop(), v: e[1]})), ['P1', 'P2', 'P3', 'P4'].map((p) => [p, `${p} · ${lvl[p][1]}`, lvl[p][0]]), null, 120);
                        const pk = [...rowsAll].sort((a, b) => b.cves - a.cves).slice(0, 7);
                        hbar('wzc-opk', pk.map((r) => ({label: r.package, value: r.cves, color: hGrad(lvl[r.level][0])})), null, (ev) => showList(`${pk[ev.dataIndex].package} · ${pk[ev.dataIndex].asset}`, {agent: pk[ev.dataIndex].agent, package: pk[ev.dataIndex].package}), 150);
                    });
                } catch (e) { $('[data-ops-body]', el).innerHTML = empty(esc(e.message), 9); }
            };
            const loadAlerts = async () => {
                const body = $('[data-al-body]', el);
                body.innerHTML = empty(`<span class='spinner-border spinner-border-sm me-2'></span>Consultando…`, 6);
                try {
                    const d = await api('vuln/alerts', {entity: state.entity, hours: state.hours});
                    body.innerHTML = d.rows.map((r) => `<tr class='si360-click' data-al='${esc(r.rule)}' data-agent='${esc(r.agent)}' data-t='${esc(r.description)}'><td>${lvlPill(r.level)}</td><td>${esc(r.description)}<div class='small text-secondary'>regra ${esc(r.rule)} · ${esc(r.groups.join(', '))}${r.mitre.length ? ' · ' + esc(r.mitre.join(', ')) : ''}${r.srcip ? ' · de ' + esc(r.srcip) : ''}</div></td>`
                        + `<td>${esc(r.asset)}<div class='small text-secondary'>${esc(r.entity)}</div></td><td>${num(r.count)}</td><td class='small text-nowrap'>${when(r.last)}<div class='text-secondary'>há ${ago(r.last)}</div></td><td data-tkc>${tkCell(r, 'alert')}</td></tr>`).join('')
                        || empty('Nenhum alerta de nível alto no período.', 6);
                } catch (e) { body.innerHTML = empty(esc(e.message), 6); }
            };
            load();
            loadAlerts();
        };
        $$('[data-wz-refresh]').forEach((b) => b.addEventListener('click', () => { Object.keys(loaded).forEach((k) => { loaded[k] = false; }); showTab($('[data-wz-tab].active', tabs).dataset.wzTab); }));
        // ------------------------------------------------------------- EPSS × CVE: da mais à menos provável de ser explorada
        const BAND_COL = ['#ef4f4f', '#f2783f', '#f7b84b', '#5b8def'];
        const bandOf = (v) => (v >= 0.5 ? 0 : v >= 0.1 ? 1 : v >= 0.01 ? 2 : 3);
        const pctTxt = (v) => ((v * 100) >= 10 ? (v * 100).toFixed(1) : (v * 100).toFixed(2)).replace('.', ',') + '%';
        tabLoaders.epss = async () => {
            const el = $('#wz-epss');
            const S = state.epss || (state.epss = {agent: '', top: 20, band: -1});
            if (!$('[data-ep-body]', el)) {
                el.innerHTML = `<div class='card si360-card mb-3'><div class='card-body py-2 d-flex flex-wrap gap-2 align-items-center'><span class='small text-secondary'>Ativo</span>`
                    + `<select class='form-select form-select-sm w-auto' data-ep-asset style='min-width:280px'><option value=''>Todos os ativos</option></select>`
                    + `<span class='small text-secondary ms-2'>Ranking mostra</span><select class='form-select form-select-sm w-auto' data-ep-top>${[10, 20, 30, 50].map((n) => `<option value='${n}'${S.top === n ? ' selected' : ''}>${n} CVEs</option>`).join('')}</select>`
                    + `<div class='ms-auto small text-secondary' data-ep-note></div></div></div><div data-ep-body></div>`;
                api('vuln/assets').then((d) => { const sel = $('[data-ep-asset]', el); d.assets.filter((a) => a.total).forEach((a) => sel.add(new Option(`${a.name} · ${num(a.total)} vulnerabilidades`, a.id))); sel.value = S.agent; }).catch(() => {});
                $('[data-ep-asset]', el).addEventListener('change', (e) => { S.agent = e.target.value; S.band = -1; run(); });
                $('[data-ep-top]', el).addEventListener('change', (e) => { S.top = +e.target.value; if (last) draw(last); });
            }
            let last = null;
            const body = $('[data-ep-body]', el);
            const draw = (d) => {
                last = d;
                const t = theme();
                if (!d.analyzed) {
                    body.innerHTML = `<div class='card si360-card'><div class='card-body text-center text-secondary py-5'><i class='ti ti-mood-empty me-1'></i>Nenhuma CVE deste ativo tem EPSS no FIRST ainda${d.missing ? ` (${num(d.missing)} sem pontuação)` : ''}.</div></div>`;
                    return;
                }
                const top = d.items.slice(0, S.top);
                const inBand = (it) => S.band < 0 || bandOf(it.epss) === S.band;
                const rows = d.items.filter(inBand).slice(0, 60);
                const bandN = (i) => d.bands[i].count;
                $('[data-ep-note]', el).innerHTML = `EPSS de ${d.date ? new Date(d.date + 'T12:00').toLocaleDateString('pt-BR') : '—'} · fonte FIRST.org`;
                body.innerHTML = `<div class='row g-3 mb-3'>`
                    + kpi('ti-target-arrow', 'CVEs analisadas', num(d.analyzed), d.distinct > d.total ? `as ${num(d.total)} de maior CVSS entre ${num(d.distinct)}` : `de ${num(d.total)}${d.missing ? ` · ${num(d.missing)} sem EPSS` : ''}`, '')
                    + kpi('ti-flame', 'Muito provável (≥ 50%)', num(bandN(0)), 'exploração quase certa', bandN(0) ? 'bad' : 'ok', `data-band='0' style='cursor:pointer'`)
                    + kpi('ti-alert-octagon', 'Provável (10 a 50%)', num(bandN(1)), 'exploração plausível', bandN(1) ? 'warn' : 'ok', `data-band='1' style='cursor:pointer'`)
                    + kpi('ti-gauge', 'Maior EPSS', pctTxt(d.max), esc(d.items[0].cve), d.max >= 0.5 ? 'bad' : d.max >= 0.1 ? 'warn' : 'ok')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Ranking: da mais à menos provável', 'ti-list-numbers', `<div class='card-body'><div class='si360-chart' id='wzc-ep-rank'></div></div>`, 'col-xl-6')
                    + card('Matriz EPSS × CVSS', 'ti-chart-dots', `<div class='card-body'><div class='si360-chart' id='wzc-ep-sc' style='height:${Math.max(320, top.length * 26 + 30)}px'></div><div class='small text-secondary'>Canto superior direito: pontuação alta <b>e</b> exploração provável. Rolagem do mouse dá zoom.</div></div>`, 'col-xl-6')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Curva EPSS de todas as CVEs', 'ti-chart-area-line', `<div class='card-body'><div class='si360-chart' id='wzc-ep-curve' style='height:300px'></div></div>`, 'col-xl-8')
                    + card('Por faixa de probabilidade', 'ti-chart-bar', `<div class='card-body'><div class='si360-chart' id='wzc-ep-band' style='height:300px'></div><div class='small text-secondary'>Clique numa faixa para filtrar a tabela.</div></div>`, 'col-xl-4')
                    + `</div><div class='card si360-card'><div class='card-header'><h3 class='card-title'><i class='ti ti-table me-2'></i>CVEs por probabilidade de exploração</h3>`
                    + `<div class='card-actions small text-secondary'>${S.band >= 0 ? `faixa: ${esc(d.bands[S.band].label)} · <a href='#' data-band-clear>limpar</a> · ` : ''}${num(rows.length)} exibida(s)</div></div>`
                    + `<div class='table-responsive si360-scroll' style='max-height:520px'><table class='table table-vcenter card-table table-sm'><thead><tr><th>#</th><th>CVE</th><th style='min-width:170px'>EPSS</th><th>Percentil</th><th>CVSS</th><th>Severidade</th><th>Pacote</th><th>Correção</th><th>Ativos</th></tr></thead><tbody>`
                    + (rows.map((it, i) => `<tr class='si360-click' data-cve='${esc(it.cve)}'><td class='text-secondary'>${i + 1}</td><td class='text-nowrap'>${cveLink(it.cve)}</td>`
                        + `<td><div class='d-flex align-items-center gap-2'><div class='si-ep-bar'><i style='width:${Math.max(2, 100 * it.epss / Math.max(d.max, 0.0001))}%;background:${BAND_COL[bandOf(it.epss)]}'></i></div><b>${pctTxt(it.epss)}</b></div></td>`
                        + `<td class='small'>${Math.round(it.pct * 100)}º</td><td>${it.cvss > 0 ? dec(it.cvss) : '—'}</td><td>${sevPill(it.severity)}</td><td class='small'>${esc(it.package)}</td>`
                        + `<td class='small'>${it.fix ? `<span class='text-success'>≥ ${esc(it.fix)}</span>` : '<span class="text-secondary">sem correção</span>'}</td><td>${it.assets}</td></tr>`).join('') || empty('Nenhuma CVE nesta faixa.', 9))
                    + `</tbody></table></div></div>`
                    + `<div class='si360-foot mt-3'><i class='ti ti-info-circle me-1'></i>EPSS (FIRST.org) é a probabilidade de a CVE ser explorada nos próximos 30 dias; o percentil compara com todas as CVEs. Use junto com o CVSS (gravidade): o que é grave <b>e</b> provável vem primeiro.</div>`;
                $$('[data-cve]', body).forEach((r) => r.addEventListener('click', (e) => { if (!e.target.closest('a')) openView(cveView(r.dataset.cve), true); }));
                $$('[data-band]', body).forEach((b) => b.addEventListener('click', () => { S.band = S.band === +b.dataset.band ? -1 : +b.dataset.band; draw(d); }));
                $('[data-band-clear]', body)?.addEventListener('click', (e) => { e.preventDefault(); S.band = -1; draw(d); });
                charts = [];
                withCharts(() => {
                    // ranking
                    const rk = $('#wzc-ep-rank');
                    rk.style.height = Math.max(320, top.length * 26 + 30) + 'px';
                    mk('wzc-ep-rank', {tooltip: Object.assign({trigger: 'axis', axisPointer: POINTER, formatter: (ps) => { const it = top[ps[0].dataIndex]; return `<b>${esc(it.cve)}</b><br>EPSS: <b>${pctTxt(it.epss)}</b> · percentil ${Math.round(it.pct * 100)}º<br>CVSS ${dec(it.cvss)} · ${esc(it.package)}`; }}, ttip),
                        grid: {left: 128, right: 60, top: 6, bottom: 6}, xAxis: {type: 'value', show: false, max: (v) => Math.min(100, Math.max(v.max * 1.08, 1))}, yAxis: AXY(t, top.map((i2) => i2.cve), 114),
                        series: [{type: 'bar', barWidth: 12, showBackground: true, backgroundStyle: {color: 'rgba(143,181,239,.07)', borderRadius: 6}, emphasis: {focus: 'self'},
                            data: top.map((i2) => ({value: i2.epss * 100, itemStyle: {color: BAND_COL[bandOf(i2.epss)], borderRadius: 6}})), label: {show: true, position: 'right', color: t.fg, fontSize: 11, fontWeight: 600, formatter: (p) => pctTxt(p.value / 100)}}]},
                        (ev) => openView(cveView(top[ev.dataIndex].cve), true));
                    // matriz EPSS x CVSS
                    const xMin = Math.max(0.0001, Math.pow(10, Math.floor(Math.log10(Math.max(0.0001, d.items[d.items.length - 1].epss)))));
                    const pts = d.items.filter((i2) => i2.cvss > 0).map((i2) => ({value: [Math.max(i2.epss, 0.0001), i2.cvss], cve: i2.cve, it: i2, itemStyle: {color: BAND_COL[bandOf(i2.epss)]}}));
                    mk('wzc-ep-sc', {tooltip: Object.assign({formatter: (p) => `<b>${esc(p.data.cve)}</b><br>EPSS: <b>${pctTxt(p.data.it.epss)}</b> · CVSS <b>${dec(p.data.it.cvss)}</b><br>${esc(p.data.it.package)}`}, ttip), toolbox: TB(t, true),
                        grid: {left: 44, right: 16, top: 30, bottom: 36}, dataZoom: [{type: 'inside', xAxisIndex: 0}, {type: 'inside', yAxisIndex: 0}],
                        xAxis: {type: 'log', min: xMin, max: 1, name: 'EPSS (probabilidade)', nameLocation: 'middle', nameGap: 24, nameTextStyle: {color: t.muted, fontSize: 11}, axisLine: {lineStyle: {color: 'rgba(143,181,239,.22)'}}, axisTick: {show: false}, splitLine: {lineStyle: {color: FADE, type: 'dashed'}}, axisLabel: {color: t.muted, fontSize: 10, formatter: (v) => ((v * 100) < 1 ? (v * 100).toFixed(2) : Math.round(v * 100)).toString().replace('.', ',') + '%'}},
                        yAxis: AXV(t, {min: 0, max: 10, name: 'CVSS', nameTextStyle: {color: t.muted, fontSize: 11}}),
                        series: [{type: 'scatter', symbolSize: 9, data: pts, emphasis: {scale: 1.6}, large: pts.length > 1500,
                            markArea: {silent: true, itemStyle: {color: 'rgba(239,79,79,.08)', borderColor: 'rgba(239,79,79,.45)', borderType: 'dashed', borderWidth: 1}, label: {show: true, position: 'insideTopRight', color: '#f2a0a0', fontSize: 11, formatter: 'Prioridade máxima'}, data: [[{xAxis: 0.1, yAxis: 7}, {xAxis: 1, yAxis: 10}]]}}]},
                        (ev) => openView(cveView(ev.data.cve), true));
                    // curva
                    const cv = d.curve;
                    const lowest = Math.max(0.01, Math.pow(10, Math.floor(Math.log10(Math.max(0.0001, cv[cv.length - 1][1]) * 100))));
                    mk('wzc-ep-curve', {tooltip: Object.assign({trigger: 'axis', axisPointer: CROSS, formatter: (ps) => { const c = cv[ps[0].dataIndex]; return `<b>${esc(c[0])}</b><br>Posição ${num(ps[0].dataIndex + 1)} de ${num(cv.length)}<br>EPSS: <b>${pctTxt(c[1])}</b>`; }}, ttip), toolbox: TB(t, true),
                        grid: {left: 54, right: 40, top: 30, bottom: 70}, dataZoom: ZOOM(t, true),
                        visualMap: {show: false, dimension: 1, seriesIndex: 0, pieces: [{gte: 50, color: BAND_COL[0]}, {gte: 10, lt: 50, color: BAND_COL[1]}, {gte: 1, lt: 10, color: BAND_COL[2]}, {lt: 1, color: BAND_COL[3]}]},
                        xAxis: AXC(t, cv.map((c, i2) => i2 + 1), {name: 'CVEs, da mais à menos provável', nameLocation: 'middle', nameGap: 40, nameTextStyle: {color: t.muted, fontSize: 11}, boundaryGap: false, axisLabel: {color: t.muted, fontSize: 10, hideOverlap: true}}),
                        yAxis: AXV(t, {type: 'log', min: lowest, max: 100, axisLabel: {color: t.muted, fontSize: 11, formatter: (v) => (v >= 1 ? Math.round(v) : v).toString().replace('.', ',') + '%'}}),
                        series: [{type: 'line', smooth: false, symbol: 'none', sampling: 'lttb', lineStyle: {width: 2.4}, areaStyle: {opacity: 0.14}, data: cv.map((c) => Math.max(+(c[1] * 100).toFixed(4), lowest)),
                            markLine: {silent: true, symbol: 'none', lineStyle: {type: 'dashed', color: 'rgba(143,181,239,.45)'}, label: {color: t.muted, fontSize: 10, formatter: '{c}%', position: 'insideEndTop'}, data: [{yAxis: 50}, {yAxis: 10}, {yAxis: 1}].filter((m) => m.yAxis > lowest)}}]},
                        (ev) => { const c = cv[ev.dataIndex]; if (c) openView(cveView(c[0]), true); });
                    // faixas
                    mk('wzc-ep-band', {tooltip: Object.assign({trigger: 'axis', axisPointer: POINTER}, ttip), grid: {left: 150, right: 44, top: 6, bottom: 6}, xAxis: {type: 'value', show: false}, yAxis: AXY(t, d.bands.map((b) => b.label), 138),
                        series: [{type: 'bar', barWidth: 16, showBackground: true, backgroundStyle: {color: 'rgba(143,181,239,.07)', borderRadius: 8}, data: d.bands.map((b, i2) => ({value: b.count, itemStyle: {color: b.color, borderRadius: 8, opacity: S.band < 0 || S.band === i2 ? 1 : 0.35}})),
                            label: {show: true, position: 'right', color: t.fg, fontSize: 11, formatter: (p) => num(p.value)}}]}, (ev) => { S.band = S.band === ev.dataIndex ? -1 : ev.dataIndex; draw(d); });
                });
            };
            const run = async () => {
                body.innerHTML = `<div class='card si360-card'><div class='card-body text-center text-secondary py-5'><span class='spinner-border spinner-border-sm me-2'></span>Consultando o EPSS no FIRST.org… (a primeira consulta de um ativo grande pode levar alguns segundos)</div></div>`;
                try { draw(await api('vuln/epss', {agent: S.agent})); } catch (e) { body.innerHTML = `<div class='alert alert-danger'><i class='ti ti-alert-triangle me-2'></i>${esc(e.message)}</div>`; }
            };
            run();
        };
        const first = (location.hash || '#exec').slice(1).replace(/^wz-/, '');
        showTab(first in tabLoaders ? first : 'exec');
    }

    // =================================================================== SEGURANÇA
    if (page === 'sec') {
        const state = {period: '24h', agent: ''};
        let data = null;
        const periodEl = $('[data-wz-period]'), agentEl = $('[data-wz-agent]'), stamp = $('[data-wz-stamp]');
        const cur = () => ({period: state.period, agent: state.agent});
        const alertsDrawer = (title, field, value, min = 0) => {
            const f = {q: '', min: min};
            const box = openDrawer(title, 'ti-bolt', `<table class='table table-sm table-vcenter'><thead><tr><th>Quando</th><th>Nível</th><th>Agente</th><th>Alerta</th><th>Detalhe</th></tr></thead><tbody data-body></tbody></table>`,
                `<input class='form-control form-control-sm' data-f='q' placeholder='Buscar no evento…'><select class='form-select form-select-sm' data-f='min'>${[[0, 'Todos os níveis'], [7, 'Nível 7+'], [10, 'Nível 10+'], [12, 'Nível 12+']].map(([v, l]) => `<option value='${v}'${min === v ? ' selected' : ''}>${l}</option>`).join('')}</select>`);
            const body = $('[data-body]', box);
            const load = async () => {
                body.innerHTML = empty(`<span class='spinner-border spinner-border-sm me-2'></span>Consultando…`, 5);
                try {
                    const d = await api('sec/alerts', Object.assign(cur(), {field, value, min: f.min, q: f.q}));
                    $('[data-n]', box).textContent = `${num(d.total)} evento(s)` + (d.total > d.items.length ? ` · mostrando ${d.items.length}` : '');
                    body.innerHTML = d.items.map((a) => `<tr><td class='small text-nowrap'>${when(a.t)}</td><td>${lvlPill(a.level)}</td><td class='small'>${esc(a.agent)}</td>`
                        + `<td>${esc(a.description)}<div class='small text-secondary'>regra ${esc(a.rule)}${a.mitre.length ? ' · ' + esc(a.mitre.join(', ')) : ''}${a.srcip ? ' · de ' + esc(a.srcip) : ''}${a.user ? ' · usuário ' + esc(a.user) : ''}</div></td>`
                        + `<td class='small' style='max-width:320px;word-break:break-word'>${a.sca ? `<b>${esc(a.sca.result)}</b> · ${esc(a.sca.title)}${a.sca.remediation ? `<div class='text-secondary'>${esc(a.sca.remediation)}</div>` : ''}` : a.fim ? esc(a.fim) : `<span class='text-secondary'>${esc(a.log)}</span>`}</td></tr>`).join('') || empty('Nenhum evento.', 5);
                } catch (e) { body.innerHTML = empty(esc(e.message), 5); }
            };
            $$('[data-f]', box).forEach((c) => c.addEventListener(c.tagName === 'SELECT' ? 'change' : 'input', () => { f[c.dataset.f] = c.tagName === 'SELECT' ? +c.value : c.value; clearTimeout(load._t); load._t = setTimeout(load, 300); }));
            load();
        };
        const pairsChart = (id, list, color, field, labelW = 160, mapLabel = (x) => x.name) => hbar(id, list.map((x) => ({label: mapLabel(x), value: x.count})), color, (ev) => alertsDrawer(`${field[1]}: ${list[ev.dataIndex].name}`, field[0], list[ev.dataIndex].name), labelW);
        const listTable = (list, field, cols = ['Valor', 'Eventos']) => `<div class='si360-scroll'><table class='table table-vcenter card-table table-sm'><thead><tr><th>${cols[0]}</th><th class='text-end'>${cols[1]}</th></tr></thead><tbody>`
            + (list.map((x) => `<tr class='si360-click' data-drill='${field[0]}' data-val='${esc(x.name)}' data-title='${esc(field[1])}: ${esc(x.name)}'><td>${esc(x.name)}</td><td class='text-end'>${num(x.count)}</td></tr>`).join('') || empty('Sem dados no período.', 2)) + `</tbody></table></div>`;
        document.addEventListener('click', (e) => {
            const r = e.target.closest('[data-drill]');
            if (r && root.contains(r)) alertsDrawer(r.dataset.title, r.dataset.drill, r.dataset.val);
        });
        const nodata = (msg) => `<div class='card-body text-secondary text-center py-4'><i class='ti ti-mood-smile me-1'></i>${msg}</div>`;

        const render = () => {
            const d = data;
            const t = theme();
            const k = d.kpi;
            dropCharts(true, '#wz-agentes'); // mantém só os do inventário; os demais painéis serão redesenhados
            const set = (id, html) => { $('#wz-' + id).innerHTML = html; };
            const recent = `<div class='si360-scroll'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Quando</th><th>Nível</th><th>Agente</th><th>Alerta</th></tr></thead><tbody>`
                + (d.recent.map((a) => `<tr class='si360-click' data-drill='rule.id' data-val='${esc(a.rule)}' data-title='Regra ${esc(a.rule)}'><td class='small text-nowrap'>${when(a.t)}</td><td>${lvlPill(a.level)}</td><td class='small'>${esc(a.agent)}</td><td>${esc(a.description)}<div class='small text-secondary'>${esc(a.groups.join(', '))}${a.srcip ? ' · de ' + esc(a.srcip) : ''}</div></td></tr>`).join('') || empty('Nenhum alerta de nível 7+ no período.', 4)) + `</tbody></table></div>`;
            set('geral', `<div class='row g-3 mb-3'>`
                + kpi('ti-bolt', 'Eventos de segurança', num(d.total), `nas últimas ${esc(periodEl.selectedOptions[0].text.replace('Últimas ', ''))}`, '', "data-drill='' data-val='' data-title='Todos os eventos'")
                + kpi('ti-flame', 'Críticos (12+)', num(k.critical), 'nível 12 ou mais', k.critical ? 'bad' : 'ok', "data-drill='class' data-val='Crítico (12+)' data-title='Eventos críticos'")
                + kpi('ti-alert-octagon', 'Altos (10–11)', num(k.high), 'nível 10 ou 11', k.high ? 'warn' : 'ok', "data-drill='class' data-val='Alto (10–11)' data-title='Eventos altos'")
                + kpi('ti-lock-x', 'Falhas de autenticação', num(k.auth_fail), `${num(k.auth_ok)} sucessos`, k.auth_fail ? 'warn' : 'ok', "data-drill='auth' data-val='' data-title='Falhas de autenticação'")
                + kpi('ti-file-search', 'Integridade (FIM)', num(k.fim), 'alterações em arquivos', '', "data-drill='fim' data-val='' data-title='Integridade de arquivos'")
                + kpi('ti-checklist', 'Conformidade SCA', k.sca_score == null ? '—' : dec(k.sca_score) + '%', `${num(k.sca_failed)} falhas · ${num(k.sca_passed)} aprovadas`, k.sca_score != null && k.sca_score < 70 ? 'warn' : 'ok')
                + kpi('ti-spy', 'Rootcheck', num(k.rootcheck), 'anomalias de host', k.rootcheck ? 'warn' : 'ok', "data-drill='location' data-val='rootcheck' data-title='Rootcheck'")
                + kpi('ti-bug', 'Alertas de vulnerabilidade', num(k.vuln_alerts), 'detector de vulnerabilidades', '')
                + `</div><div class='row g-3 mb-3'>`
                + card('Eventos ao longo do tempo', 'ti-chart-area-line', `<div class='card-body'><div class='si360-chart' id='wzc-tl' style='height:280px'></div></div>`, 'col-xl-8')
                + card('Por criticidade', 'ti-chart-donut-3', `<div class='card-body'><div class='si360-chart' id='wzc-class' style='height:280px'></div></div>`, 'col-xl-4')
                + `</div><div class='row g-3 mb-3'>`
                + card('Regras que mais disparam', 'ti-list-search', `<div class='card-body'><div class='si360-chart' id='wzc-rules' style='height:340px'></div></div>`, 'col-xl-6')
                + card('Agentes com mais eventos', 'ti-server-bolt', `<div class='card-body'><div class='si360-chart' id='wzc-agents' style='height:340px'></div></div>`, 'col-xl-6')
                + `</div><div class='row g-3'>` + card('Alertas recentes (nível 7+)', 'ti-alert-triangle', recent, 'col-12', `<div class='card-actions small text-secondary'>clique para ver a regra</div>`) + `</div>`);
            set('eventos', `<div class='row g-3 mb-3'>`
                + card('Níveis de regra', 'ti-gauge', `<div class='card-body'><div class='si360-chart' id='wzc-lv'></div></div>`, 'col-xl-4')
                + card('Grupos de regras', 'ti-category', `<div class='card-body'><div class='si360-chart' id='wzc-grp' style='height:340px'></div></div>`, 'col-xl-8')
                + `</div><div class='row g-3 mb-3'>`
                + card('Decodificadores', 'ti-code', `<div class='card-body'><div class='si360-chart' id='wzc-dec'></div></div>`, 'col-xl-4')
                + card('Origem dos eventos (log)', 'ti-file-text', `<div class='card-body'><div class='si360-chart' id='wzc-loc'></div></div>`, 'col-xl-4')
                + card('Principais IPs de origem', 'ti-world', listTable(d.srcip, ['srcip', 'IP']), 'col-xl-4')
                + `</div><div class='row g-3'>` + card('Acessos web suspeitos (URL)', 'ti-world-www', listTable(d.url, ['url', 'URL']), 'col-xl-6')
                + card('Outros módulos', 'ti-puzzle', `<div class='card-body'><div class='row g-2 small'>`
                    + [['Resposta ativa', k.active_response], ['Malware / VirusTotal / YARA', k.malware], ['Docker', k.docker], ['Nuvem (AWS, Azure, GCP, Office 365, GitHub)', k.cloud], ['Contas criadas ou alteradas', k.adduser], ['Ataques e aplicações web', k.web]]
                        .map(([n, v]) => `<div class='col-6'><div class='si360-pill ${v ? 'warn' : ''}'>${esc(n)}: <b>${num(v)}</b></div></div>`).join('') + `</div></div>`, 'col-xl-6') + `</div>`);
            set('mitre', `<div class='row g-3'>` + card('Táticas', 'ti-target-arrow', `<div class='card-body'><div class='si360-chart' id='wzc-tac' style='height:340px'></div></div>`, 'col-xl-5')
                + card('Técnicas', 'ti-crosshair', `<div class='si360-scroll'><table class='table table-vcenter card-table table-sm'><thead><tr><th>ID</th><th>Técnica</th><th class='text-end'>Eventos</th></tr></thead><tbody>`
                    + (d.technique.map((x) => `<tr class='si360-click' data-drill='mitre.technique' data-val='${esc(x.name)}' data-title='MITRE: ${esc(x.name)}'><td><a href='https://attack.mitre.org/techniques/${esc(x.id.replace('.', '/'))}/' target='_blank' rel='noopener'>${esc(x.id)}</a></td><td>${esc(x.name)}</td><td class='text-end'>${num(x.count)}</td></tr>`).join('') || empty('Nenhuma técnica mapeada no período.', 3)) + `</tbody></table></div>`, 'col-xl-7') + `</div>`);
            set('conformidade', `<div class='row g-3'>` + Object.keys(d.compliance).map((n) => card(n, 'ti-certificate', `<div class='card-body'><div class='si360-chart' id='wzc-c-${esc(d.compliance_keys[n])}' style='height:260px'></div></div>`, 'col-xl-6')).join('') + `</div>`);
            set('fim', `<div class='row g-3 mb-3'>` + kpi('ti-file-search', 'Eventos de integridade', num(d.fim.total), 'adicionados, modificados, excluídos', '') + `</div>`
                + (d.fim.total ? `<div class='row g-3'>` + card('Tipo de evento', 'ti-chart-donut-3', `<div class='card-body'><div class='si360-chart' id='wzc-fimev'></div></div>`, 'col-xl-4')
                    + card('Caminhos mais alterados', 'ti-folder-search', listTable(d.fim.path, ['fim.path', 'Caminho']), 'col-xl-8') + `</div>`
                    : `<div class='card si360-card'>${nodata('Nenhuma alteração de arquivo monitorada no período. Se o módulo FIM/syscheck não estiver configurado nos agentes, ative-o no ossec.conf (diretórios em &lt;syscheck&gt;).')}</div>`));
            set('sca', `<div class='row g-3 mb-3'>` + kpi('ti-checklist', 'Pontuação SCA', d.sca.score == null ? '—' : dec(d.sca.score) + '%', 'aprovadas ÷ (aprovadas + falhas)', d.sca.score != null && d.sca.score < 70 ? 'warn' : 'ok')
                + kpi('ti-circle-x', 'Verificações com falha', num(d.sca.result.failed || 0), 'eventos no período', d.sca.result.failed ? 'warn' : 'ok', "data-drill='sca.result' data-val='failed' data-title='Verificações SCA com falha'")
                + kpi('ti-circle-check', 'Aprovadas', num(d.sca.result.passed || 0), 'eventos no período', 'ok') + kpi('ti-circle-minus', 'Não aplicáveis', num(d.sca['result']['not applicable'] || 0), 'eventos no período', '') + `</div>`
                + (d.sca.policy.length ? `<div class='row g-3'>` + card('Por política', 'ti-clipboard-check', `<div class='card-body'><div class='si360-chart' id='wzc-scapol' style='height:280px'></div></div>`, 'col-xl-6')
                    + card('Por agente', 'ti-server-2', `<div class='card-body'><div class='si360-chart' id='wzc-scaag' style='height:280px'></div></div>`, 'col-xl-6')
                    + card('Verificações com falha e como corrigir', 'ti-tool', `<div class='si360-scroll' style='max-height:520px'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Verificação</th><th>Política</th><th>Como corrigir</th><th class='text-end'>Eventos</th></tr></thead><tbody>`
                        + d.sca.failed.map((x) => `<tr class='si360-click' data-drill='sca.result' data-val='failed' data-title='${esc(x.title)}'><td>${esc(x.title)}<div class='small text-secondary'>${esc(x.agent)}</div></td><td class='small'>${esc(x.policy)}</td><td class='small' style='max-width:420px'>${esc(x.remediation) || '—'}</td><td class='text-end'>${x.count}</td></tr>`).join('') + `</tbody></table></div>`, 'col-12') + `</div>`
                    : `<div class='card si360-card'>${nodata('Sem verificações SCA no período. Elas rodam por agendamento: amplie o período para ver o resultado do último ciclo.')}</div>`));
            set('acesso', `<div class='row g-3 mb-3'>` + kpi('ti-lock-x', 'Falhas de autenticação', num(k.auth_fail), 'senha errada, usuário inválido', k.auth_fail ? 'warn' : 'ok', "data-drill='auth' data-val='' data-title='Falhas de autenticação'")
                + kpi('ti-lock-check', 'Autenticações com sucesso', num(k.auth_ok), '', 'ok') + kpi('ti-shield-up', 'Uso de sudo', num(k.sudo), 'elevação de privilégio', '', "data-drill='group' data-val='sudo' data-title='Uso de sudo'")
                + kpi('ti-terminal-2', 'SSH', num(k.ssh), 'eventos do sshd', '', "data-drill='group' data-val='sshd' data-title='Eventos de SSH'")
                + kpi('ti-user-plus', 'Contas criadas ou alteradas', num(k.adduser), '', k.adduser ? 'warn' : 'ok', "data-drill='group' data-val='adduser' data-title='Contas criadas'") + `</div>`
                + `<div class='row g-3'>` + card('IPs de origem', 'ti-world', listTable(d.srcip, ['srcip', 'IP']), 'col-xl-4') + card('Usuários de origem', 'ti-user', listTable(d.srcuser, ['srcuser', 'Usuário']), 'col-xl-4') + card('Usuários de destino', 'ti-user-check', listTable(d.dstuser, ['dstuser', 'Usuário']), 'col-xl-4') + `</div>`);
            const compl = d.compliance_keys;
            withCharts(() => {
                mk('wzc-tl', {tooltip: Object.assign({trigger: 'axis', axisPointer: CROSS}, ttip), legend: LEG(t), toolbox: TB(t, true), grid: {left: 50, right: 14, top: 40, bottom: 52}, dataZoom: ZOOM(t, true),
                    xAxis: {type: 'time', axisLine: {lineStyle: {color: 'rgba(143,181,239,.22)'}}, axisTick: {show: false}, axisLabel: {color: t.muted, fontSize: 11}, splitLine: {show: false}}, yAxis: AXV(t),
                    series: [{name: 'Eventos', type: 'line', smooth: 0.25, symbol: 'circle', symbolSize: 6, showSymbol: false, lineStyle: {width: 2.5, color: '#6f9be0'}, itemStyle: {color: '#6f9be0'},
                        areaStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: '#6f9be099'}, {offset: 1, color: '#6f9be00a'}])}, emphasis: {focus: 'series'}, data: d.timeline.map((x) => [x.t, x.n])},
                        {name: 'Nível 10+', type: 'bar', barMaxWidth: 14, itemStyle: {color: '#f2783f', borderRadius: [3, 3, 0, 0]}, emphasis: {focus: 'series'}, data: d.timeline.map((x) => [x.t, x.hi])}]});
                const cls = Object.entries(d.class).map(([n, v]) => ({name: n, value: v, itemStyle: {color: n.startsWith('Crít') ? '#ef4f4f' : n.startsWith('Alto') ? '#f2783f' : n.startsWith('Méd') ? '#f7b84b' : '#5b8def'}}));
                donut('wzc-class', cls, (ev) => alertsDrawer('Eventos: ' + ev.name, 'class', ev.name), 'eventos');
                hbar('wzc-rules', d.rules.map((r) => ({label: `${r.id} · ${r.description}`, value: r.count, color: lvlColor(r.level)})), null, (ev) => alertsDrawer('Regra ' + d.rules[ev.dataIndex].id + ': ' + d.rules[ev.dataIndex].description, 'rule.id', d.rules[ev.dataIndex].id), 250);
                hbar('wzc-agents', d.agents.map((a) => ({label: a.name, value: a.count})), '#6f9be0', (ev) => alertsDrawer('Agente ' + d.agents[ev.dataIndex].name, 'agent.id', d.agents[ev.dataIndex].id), 170);
                donut('wzc-lv', d.levels.map((l) => ({name: 'Nível ' + l.name, value: l.count, itemStyle: {color: lvlColor(+l.name)}, lv: l.name})), (ev) => alertsDrawer('Nível ' + ev.data.lv, 'rule.level', ev.data.lv), 'eventos');
                pairsChart('wzc-grp', d.groups, '#6f9be0', ['group', 'Grupo'], 150);
                pairsChart('wzc-dec', d.decoders, '#8fb5ef', ['decoder', 'Decodificador'], 120);
                pairsChart('wzc-loc', d.locations, '#8fb5ef', ['location', 'Origem'], 190);
                pairsChart('wzc-tac', d.tactic, '#e97659', ['mitre.tactic', 'Tática'], 140);
                Object.keys(d.compliance).forEach((n) => pairsChart('wzc-c-' + compl[n], d.compliance[n], '#6f9be0', [compl[n], n], 110));
                if (d.fim.total) donut('wzc-fimev', d.fim.event.map((x) => ({name: x.name, value: x.count})), (ev) => alertsDrawer('FIM: ' + ev.name, 'fim.event', ev.name), 'eventos');
                if (d.sca.policy.length) {
                    stackedBar('wzc-scapol', d.sca.policy.map((p) => ({label: p.name, v: {passed: p.passed, failed: p.failed, na: p.na}})), [['passed', 'Aprovadas', '#22c55e'], ['failed', 'Falhas', '#ef4444'], ['na', 'N/A', '#97aab3']], (ev) => alertsDrawer('Política: ' + d.sca.policy[ev.dataIndex].name, 'sca.policy', d.sca.policy[ev.dataIndex].name), 230);
                    stackedBar('wzc-scaag', d.sca.agents.map((p) => ({label: p.name, v: {passed: p.passed, failed: p.failed}})), [['passed', 'Aprovadas', '#22c55e'], ['failed', 'Falhas', '#ef4444']], (ev) => alertsDrawer('Agente ' + d.sca.agents[ev.dataIndex].name, 'agent.id', d.sca.agents[ev.dataIndex].id), 170);
                }
            });
            stamp.textContent = 'atualizado às ' + new Date(d.at * 1000).toLocaleTimeString('pt-BR');
        };

        const load = async () => {
            $$('.si360-wzpane', root).filter((p) => !p.hidden).forEach(loading);
            try {
                data = await api('sec/overview', cur());
                if (agentEl.options.length <= 1) data.agent_list.forEach((a) => agentEl.add(new Option(a.name, a.id)));
                ['geral', 'eventos', 'mitre', 'conformidade', 'fim', 'sca', 'acesso'].forEach((k) => { loaded[k] = true; });
                render();
                resizeAll();
            } catch (e) { $$('.si360-wzpane', root).filter((p) => !p.hidden).forEach((p) => fail(p, e)); }
        };

        // ---------- agentes e inventário
        tabLoaders.agentes = async () => {
            const el = $('#wz-agentes');
            loading(el);
            try {
                const d = await api('sec/inventory', {agent: state.agent});
                const t = theme();
                const st = d.status || {};
                const c = d.counts;
                const stCls = {active: 'ok', disconnected: 'bad', never_connected: '', pending: 'warn'};
                el.innerHTML = `<div class='row g-3 mb-3'>`
                    + kpi('ti-server-cog', 'Agentes', d.agents.length, `${st.active || 0} ativos · ${st.disconnected || 0} desconectados`, (st.disconnected || 0) ? 'bad' : 'ok')
                    + kpi('ti-package', 'Pacotes instalados', num(c.packages), 'no inventário', '') + kpi('ti-plug-connected', 'Portas em escuta', num(c.ports), `${num(d.ports.exposed)} abertas para a rede`, d.ports.exposed ? 'warn' : '')
                    + kpi('ti-settings-automation', 'Serviços', num(c.services), `${(d.services.state.find((x) => x.name === 'active') || {count: 0}).count} ativos`, '')
                    + kpi('ti-users', 'Usuários', num(c.users), `${d.users.interactive.length} com shell`, '') + kpi('ti-cpu', 'Processos', num(c.processes), '', '')
                    + kpi('ti-network', 'Interfaces de rede', num(c.interfaces), '', '') + kpi('ti-heart-rate-monitor', 'Saúde do Indexer', esc(d.health.status || '—'), `${d.health.nodes || 0} nó(s) · ${d.health.shards || 0} shards`, d.health.status === 'green' ? 'ok' : d.health.status ? 'warn' : '')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Agentes', 'ti-server-2', `<div class='table-responsive'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Agente</th><th>Endereço</th><th>Sistema</th><th>Versão</th><th>Situação</th><th>Último contato</th><th>Computador no GLPI</th></tr></thead><tbody>`
                        + d.agents.map((a) => `<tr><td><b>${esc(a.name)}</b><div class='small text-secondary'>ID ${esc(a.id)} · ${esc(a.groups.join(', '))}</div></td><td>${esc(a.ip)}</td><td class='small'>${esc(a.os)}<div class='text-secondary'>${esc(a.kernel)}</div></td><td class='small'>${esc(a.version)}</td>`
                            + `<td><span class='si360-pill ${stCls[a.status] || ''}'>${esc(a.status_label)}</span></td><td class='small text-nowrap'>${when(a.last)}${a.last ? `<div class='text-secondary'>há ${ago(a.last)}</div>` : ''}</td>`
                            + `<td>${a.computer ? `<a href='${esc(a.computer.url)}' target='_blank' rel='noopener'>${esc(a.computer.name)}</a><div class='small text-secondary'>${esc(a.computer.entity)}</div>` : '<span class="text-secondary">sem cadastro</span>'}</td></tr>`).join('') + `</tbody></table></div>`, 'col-12')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Portas em escuta mais comuns', 'ti-plug-connected', `<div class='si360-scroll'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Porta</th><th>Processo</th><th>Agentes</th><th class='text-end'>Sockets</th></tr></thead><tbody>`
                        + (d.ports.top.map((p) => `<tr><td>${p.port}</td><td>${esc(p.process)}</td><td>${p.agents}</td><td class='text-end'>${p.count}</td></tr>`).join('') || empty('Sem dados.', 4)) + `</tbody></table></div>`, 'col-xl-4')
                    + card('Pacotes por agente', 'ti-package', `<div class='card-body'><div class='si360-chart' id='wzc-ipk'></div></div>`, 'col-xl-4')
                    + card('Processos por agente', 'ti-cpu', `<div class='card-body'><div class='si360-chart' id='wzc-ipr'></div></div>`, 'col-xl-4')
                    + `</div><div class='row g-3 mb-3'>`
                    + card('Serviços por estado', 'ti-settings-automation', `<div class='card-body'><div class='si360-chart' id='wzc-isv'></div></div>`, 'col-xl-4')
                    + card('Shells de usuários', 'ti-terminal-2', `<div class='card-body'><div class='si360-chart' id='wzc-ish'></div></div>`, 'col-xl-4')
                    + card('Usuários com shell interativo', 'ti-user-exclamation', `<div class='si360-scroll'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Usuário</th><th class='text-end'>Ativos</th></tr></thead><tbody>${d.users.interactive.map((u) => `<tr><td>${esc(u.name)}</td><td class='text-end'>${u.count}</td></tr>`).join('') || empty('Nenhum.', 2)}</tbody></table></div>`, 'col-xl-4')
                    + `</div><div class='row g-3'>`
                    + card('Hardware', 'ti-device-desktop', `<div class='table-responsive'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Agente</th><th>Processador</th><th>Núcleos</th><th>Memória</th><th>Uso</th></tr></thead><tbody>`
                        + (d.hardware.map((h) => `<tr><td>${esc(h.agent)}</td><td class='small'>${esc(h.cpu)}</td><td>${h.cores}</td><td>${dec(h.memory_gb)} GB</td><td>${dec(h.usage)}%</td></tr>`).join('') || empty('Sem dados.', 5)) + `</tbody></table></div>`, 'col-xl-7')
                    + card('Sistemas operacionais e kernels', 'ti-brand-ubuntu', `<div class='card-body small'>${d.os.map((o) => `<div class='mb-1'><b>${esc(o.name)}</b> ${esc(o.versions.join(', '))} <span class='si360-pill'>${o.count}</span></div>`).join('')}<hr>${d.kernel.map((k2) => `<div>${esc(k2.name)} <span class='si360-pill'>${k2.count}</span></div>`).join('')}</div>`, 'col-xl-5') + `</div>`;
                dropCharts(false, '#wz-agentes');
                withCharts(() => {
                    hbar('wzc-ipk', d.packages.by_agent.map((x) => ({label: x.name, value: x.count})), '#6f9be0', null, 140);
                    hbar('wzc-ipr', d.processes.by_agent.map((x) => ({label: x.name, value: x.count})), '#8fb5ef', null, 140);
                    donut('wzc-isv', d.services.state.map((x) => ({name: x.name, value: x.count})), null, 'serviços');
                    donut('wzc-ish', d.users.shell.map((x) => ({name: x.name, value: x.count})), null, 'usuários');
                });
            } catch (e) { fail(el, e); }
        };

        periodEl.addEventListener('change', () => { state.period = periodEl.value; load(); });
        agentEl.addEventListener('change', () => { state.agent = agentEl.value; loaded.agentes = false; load(); if (!$('#wz-agentes').hidden) { loaded.agentes = true; tabLoaders.agentes(); } });
        $$('[data-wz-refresh]').forEach((b) => b.addEventListener('click', () => { loaded.agentes = false; load(); if (!$('#wz-agentes').hidden) { loaded.agentes = true; tabLoaders.agentes(); } }));
        const first = (location.hash || '#geral').slice(1).replace(/^wz-/, '');
        loaded.geral = true;
        load();
        if (first !== 'geral' && $('#wz-' + first)) showTab(first);
        setInterval(() => { if (!document.hidden && !(drawer && drawer.classList.contains('on'))) load(); }, 120000);
    }
})();
