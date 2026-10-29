/**
 * Sistemas Internos 360 — telas do plugin: gráficos do painel, filtros da lista de
 * hosts, formulário do host (interface, SNMP, tags e macros) e abas da configuração.
 */
(() => {
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));

    // ---------------------------------------------------------------- gráficos do painel
    // o ECharts do GLPI (Html::requireJs) chega no fim da página, depois deste arquivo
    const data = $('#si-dash');
    const drawCharts = () => {
        if (!data || !window.echarts) return;
        const d = JSON.parse(data.textContent);
        const css = getComputedStyle(data.closest('.si360') || document.body); // cores do cartão escuro do plugin
        const fg = css.getPropertyValue('--tblr-body-color').trim() || '#1f2937';
        const muted = css.getPropertyValue('--tblr-secondary-color').trim() || '#6b7280';
        const grid = css.getPropertyValue('--tblr-border-color').trim() || '#e5e7eb';
        const charts = [];
        const mk = (id, opt) => {
            const el = document.getElementById(id);
            if (!el) return;
            const c = echarts.init(el, null, {renderer: 'svg'});
            c.setOption(Object.assign({textStyle: {color: fg, fontFamily: 'inherit'}, tooltip: {trigger: 'item'}}, opt));
            charts.push(c);
        };
        const total = d.hosts.ok + d.hosts.down + d.hosts.off;
        mk('si-c-hosts', {
            title: {text: String(total), subtext: 'hosts', left: 'center', top: '36%', textStyle: {color: fg, fontSize: 26, fontWeight: 700}, subtextStyle: {color: muted}},
            legend: {bottom: 0, textStyle: {color: muted}},
            series: [{type: 'pie', radius: ['55%', '78%'], center: ['50%', '45%'], label: {show: false}, itemStyle: {borderColor: 'transparent', borderWidth: 2, borderRadius: 4},
                data: [{name: 'Disponíveis', value: d.hosts.ok, itemStyle: {color: '#22c55e'}}, {name: 'Indisponíveis', value: d.hosts.down, itemStyle: {color: '#ef4444'}},
                    {name: 'Desativados', value: d.hosts.off, itemStyle: {color: '#94a3b8'}}]}],
        });
        mk('si-c-sev', {
            tooltip: {trigger: 'axis', axisPointer: {type: 'shadow'}},
            grid: {left: 110, right: 30, top: 8, bottom: 8},
            xAxis: {type: 'value', minInterval: 1, splitLine: {lineStyle: {color: grid}}, axisLabel: {color: muted}},
            yAxis: {type: 'category', inverse: true, data: d.sev.map((s) => s.name), axisLabel: {color: fg}, axisLine: {show: false}, axisTick: {show: false}},
            series: [{type: 'bar', barWidth: 14, data: d.sev.map((s) => ({value: s.value, itemStyle: {color: s.color, borderRadius: 7}})), label: {show: true, position: 'right', color: fg}}],
        });
        mk('si-c-days', {
            tooltip: {trigger: 'axis'},
            legend: {bottom: 0, textStyle: {color: muted}},
            grid: {left: 32, right: 12, top: 12, bottom: 40},
            xAxis: {type: 'category', data: d.days.labels, axisLabel: {color: muted}, axisLine: {lineStyle: {color: grid}}},
            yAxis: {type: 'value', minInterval: 1, splitLine: {lineStyle: {color: grid}}, axisLabel: {color: muted}},
            series: [
                {name: 'Abertos', type: 'line', smooth: true, symbolSize: 6, data: d.days.opened, itemStyle: {color: '#8fb5ef'}, areaStyle: {color: 'rgba(143,181,239,.2)'}},
                {name: 'Solucionados', type: 'line', smooth: true, symbolSize: 6, data: d.days.solved, itemStyle: {color: '#22c55e'}},
            ],
        });
        window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
        // clique: lista do que está por trás da fatia, barra ou ponto, com filtros e o link de cada item
        const [cHosts, cSev, cDays] = ['si-c-hosts', 'si-c-sev', 'si-c-days'].map((id) => charts.find((c) => c.getDom().id === id));
        cHosts?.on('click', (ev) => drill('hosts', {state: ['ok', 'down', 'off'][ev.dataIndex]}));
        cSev?.on('click', (ev) => drill('problems', {sev: String(d.sev[ev.dataIndex] ? [5, 4, 3, 2, 1, 0][ev.dataIndex] : '')}));
        cDays?.getZr().on('click', (ev) => { // qualquer ponto da coluna do dia (as linhas são finas)
            const i = cDays.convertFromPixel({seriesIndex: 0}, [ev.offsetX, ev.offsetY])?.[0];
            if (i == null || i < 0 || i >= d.days.labels.length) return;
            const day = d.dayKeys[Math.round(i)];
            drill('tickets', {from: day, to: day});
        });
        drillData = d;
    };

    // ---------------------------------------------------------------- lista com filtros (clique nos gráficos)
    let drillData = null;
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const dur = (s) => { s = Math.max(0, s); const dd = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60); return dd ? `${dd}d ${h}h` : h ? `${h}h ${m}min` : `${m}min`; };
    const ymd = (ts) => { const x = new Date(ts * 1000); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`; };
    const br = (day) => day ? day.split('-').reverse().join('/') : '';
    const dt = (ts) => new Date(ts * 1000).toLocaleString('pt-BR', {dateStyle: 'short', timeStyle: 'short'});
    const KIND = {
        problems: {title: 'Alertas do Zabbix', icon: 'ti-alert-triangle'},
        hosts: {title: 'Hosts do Zabbix', icon: 'ti-server'},
        tickets: {title: 'Chamados do GLPI', icon: 'ti-ticket'},
    };
    function drill(kind, preset) {
        const d = drillData;
        if (!d) return;
        let box = $('#si360-drill');
        if (!box) {
            box = document.createElement('div');
            box.id = 'si360-drill';
            box.className = 'si360-drill';
            document.body.appendChild(box);
            box.addEventListener('click', (e) => { if (e.target === box || e.target.closest('[data-close]')) box.classList.remove('on'); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') box.classList.remove('on'); });
        }
        const sel = (name, opts, v) => `<select class='form-select form-select-sm' data-f='${name}'>${opts.map(([k, l]) => `<option value='${k}'${String(v ?? '') === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
        const f = {q: '', from: preset.from || '', to: preset.to || '', sev: preset.sev ?? '', state: preset.state || '', ack: '', tk: ''};
        let filters = `<input class='form-control form-control-sm' data-f='q' placeholder='${kind === 'tickets' ? 'Chamado, título…' : 'Host, problema, IP…'}'>`;
        if (kind !== 'hosts') {
            filters += `<label>De <input type='date' class='form-control form-control-sm' data-f='from' value='${f.from}'></label><label>Até <input type='date' class='form-control form-control-sm' data-f='to' value='${f.to}'></label>`;
        }
        if (kind === 'problems') {
            filters += sel('sev', [['', 'Todas as severidades'], ['5', 'Desastre'], ['4', 'Alta'], ['3', 'Média'], ['2', 'Atenção'], ['1', 'Informação'], ['0', 'Não classificada'], ['45', 'Alta e Desastre']], f.sev)
                + sel('ack', [['', 'Reconhecidos ou não'], ['0', 'Sem reconhecimento'], ['1', 'Reconhecidos']], '');
        }
        if (kind === 'hosts') {
            filters += sel('state', [['', 'Todos os estados'], ['ok', 'Disponíveis'], ['down', 'Indisponíveis'], ['off', 'Desativados']], f.state);
        }
        if (kind === 'tickets') {
            filters += sel('tk', [['', 'Abertos e solucionados'], ['aberto', 'Abertos no dia'], ['solucionado', 'Solucionados no dia']], '');
        }
        box.innerHTML = `<div class='si360-drill-box'><div class='si360-drill-head'><b><i class='ti ${KIND[kind].icon} me-2'></i>${KIND[kind].title}</b><span data-n></span>`
            + `<button type='button' class='btn-close btn-close-white ms-auto' data-close aria-label='Fechar'></button></div>`
            + `<div class='si360-drill-filters'>${filters}<button type='button' class='btn btn-sm btn-ghost-secondary' data-clear><i class='ti ti-filter-off me-1'></i>Limpar</button></div>`
            + `<div class='si360-drill-body'><table class='table table-sm table-vcenter'><thead></thead><tbody></tbody></table></div></div>`;
        const thead = $('thead', box), tbody = $('tbody', box);
        const now = d.detail.now;
        const inDay = (day) => (!f.from || day >= f.from) && (!f.to || day <= f.to);
        const has = (...v) => !f.q || v.join(' ').toLowerCase().includes(f.q.toLowerCase());
        const render = () => {
            let rows = [];
            if (kind === 'problems') {
                thead.innerHTML = '<tr><th>Severidade</th><th>Host</th><th>Problema</th><th>Início</th><th>Duração</th><th></th></tr>';
                rows = d.detail.problems.filter((p) => (f.sev === '' || (f.sev === '45' ? p.sev >= 4 : String(p.sev) === f.sev)) && (f.ack === '' || String(+p.ack) === f.ack)
                    && inDay(ymd(p.clock)) && has(p.host, p.name)).sort((a, b) => b.sev - a.sev || b.clock - a.clock)
                    .map((p) => `<tr><td><span class='si360-sev' style='--c:${p.color}'>${esc(p.sevName)}</span></td><td class='text-nowrap'>${esc(p.host)}</td>`
                        + `<td>${esc(p.name)}${p.ack ? " <i class='ti ti-check text-success' title='Reconhecido'></i>" : ''}</td><td class='text-nowrap'>${dt(p.clock)}</td>`
                        + `<td class='text-nowrap'>${dur(now - p.clock)}</td><td>${p.url ? `<a href='${esc(p.url)}' target='_blank' rel='noopener' class='btn btn-sm btn-primary'><i class='ti ti-external-link me-1'></i>Alerta</a>` : ''}</td></tr>`);
            } else if (kind === 'hosts') {
                const st = {ok: ['Disponível', 'ok'], down: ['Indisponível', 'bad'], off: ['Desativado', '']};
                thead.innerHTML = '<tr><th>Host</th><th>Endereço</th><th>Estado</th><th></th></tr>';
                rows = d.detail.hosts.filter((h) => (!f.state || h.state === f.state) && has(h.name, h.ip))
                    .map((h) => `<tr><td>${esc(h.name)}</td><td class='text-secondary'>${esc(h.ip)}</td><td><span class='si360-pill ${st[h.state][1]}'>${st[h.state][0]}</span></td>`
                        + `<td>${h.url ? `<a href='${esc(h.url)}' target='_blank' rel='noopener' class='btn btn-sm btn-primary'><i class='ti ti-external-link me-1'></i>Alertas do host</a>` : ''}</td></tr>`);
            } else {
                thead.innerHTML = '<tr><th>Chamado</th><th>Título</th><th>No dia</th><th>Data</th><th></th></tr>';
                rows = d.detail.days.flat().filter((t) => (!f.tk || t.kind === f.tk) && inDay(String(t.kind === 'aberto' ? t.date : t.solvedate).slice(0, 10)) && has('#' + t.id, t.name))
                    .sort((a, b) => String(b.kind === 'aberto' ? b.date : b.solvedate).localeCompare(String(a.kind === 'aberto' ? a.date : a.solvedate)))
                    .map((t) => `<tr><td><a href='${esc(t.url)}'>#${t.id}</a></td><td>${esc(t.name)}</td><td><span class='si360-pill ${t.kind === 'aberto' ? '' : 'ok'}'>${t.kind}</span></td>`
                        + `<td class='text-nowrap'>${esc(br(String(t.kind === 'aberto' ? t.date : t.solvedate).slice(0, 10)) + ' ' + String(t.kind === 'aberto' ? t.date : t.solvedate).slice(11, 16))}</td>`
                        + `<td><a href='${esc(t.url)}' class='btn btn-sm btn-primary'><i class='ti ti-external-link me-1'></i>Chamado</a></td></tr>`);
            }
            tbody.innerHTML = rows.join('') || `<tr><td colspan='6' class='text-center text-secondary py-4'>Nada com estes filtros.</td></tr>`;
            $('[data-n]', box).textContent = `${rows.length} ${rows.length === 1 ? 'item' : 'itens'}` + (f.from || f.to ? ` · ${f.from === f.to ? br(f.from) : (br(f.from) || '…') + ' a ' + (br(f.to) || '…')}` : '');
        };
        $$('[data-f]', box).forEach((el) => el.addEventListener('input', () => { f[el.dataset.f] = el.value; render(); }));
        $('[data-clear]', box).addEventListener('click', () => { Object.keys(f).forEach((k) => { f[k] = ''; }); $$('[data-f]', box).forEach((el) => { el.value = ''; }); render(); });
        render();
        requestAnimationFrame(() => box.classList.add('on')); // a gaveta desliza da direita
        $('[data-f=q]', box).focus();
    }
    if (data) {
        if (window.echarts) drawCharts();
        else window.addEventListener('load', drawCharts);
        setTimeout(() => location.reload(), 120000);
    }
    $$('[data-si-refresh]').forEach((b) => b.addEventListener('click', () => location.reload()));

    // ---------------------------------------------------------------- lista de hosts
    const filter = $('[data-si-filter]');
    const status = $('[data-si-status]');
    const applyFilter = () => {
        const table = $(filter.dataset.siFilter);
        const q = filter.value.trim().toLowerCase();
        const st = status ? status.value : '';
        $$('tbody tr[data-status]', table).forEach((tr) => {
            const okText = !q || tr.textContent.toLowerCase().includes(q) || (tr.querySelector('td[title]') || {}).title?.toLowerCase().includes(q);
            const okStatus = !st || (st === 'on' || st === 'off' ? tr.dataset.status === st : (tr.dataset.flags || '').split(' ').includes(st));
            tr.hidden = !(okText && okStatus);
        });
    };
    if (filter) {
        filter.addEventListener('input', applyFilter);
        status && status.addEventListener('change', applyFilter);
    }

    // ---------------------------------------------------------------- formulário do host
    const ifType = $('[data-si-iftype]');
    if (ifType) {
        const ports = JSON.parse(($('#si-ports') || {}).textContent || '{}');
        const port = $('[data-si-port]');
        const snmp = $('[data-si-snmp]');
        const ver = $('[data-si-snmpver]');
        const sync = () => {
            snmp.hidden = ifType.value !== '2';
            port.placeholder = ports[ifType.value] || '';
            const v3 = ver.value === '3';
            $$('[data-si-v3]').forEach((el) => { el.hidden = !v3; });
            $$('[data-si-v12]').forEach((el) => { el.hidden = v3; });
        };
        ifType.addEventListener('change', () => {
            // porta ainda no padrão do tipo anterior: troca pela do novo tipo
            if (!port.value || Object.values(ports).map(String).includes(port.value)) port.value = ports[ifType.value] || '';
            sync();
        });
        ver.addEventListener('change', sync);
        sync();
    }
    document.addEventListener('click', (ev) => {
        const add = ev.target.closest('[data-si-kv-add]');
        if (add) {
            const box = $(`[data-si-kv="${add.dataset.siKvAdd}"]`);
            const row = box.querySelector('.si360-kv').cloneNode(true);
            $$('input', row).forEach((i) => { i.value = ''; i.readOnly = false; });
            row.removeAttribute('title');
            box.appendChild(row);
            row.querySelector('input').focus();
        }
        const del = ev.target.closest('[data-si-kv-del]');
        if (del) {
            const row = del.closest('.si360-kv');
            const box = row.parentNode;
            if (box.querySelectorAll('.si360-kv').length > 1) row.remove();
            else $$('input', row).forEach((i) => { if (!i.readOnly) i.value = ''; });
        }
    });

    // ---------------------------------------------------------------- abas da configuração
    const tabs = $('[data-si-tabs]');
    if (tabs) {
        const input = $('[data-si-tabinput]');
        tabs.addEventListener('click', (ev) => {
            const a = ev.target.closest('[data-si-tab]');
            if (!a) return;
            ev.preventDefault();
            $$('[data-si-tab]', tabs).forEach((x) => x.classList.toggle('active', x === a));
            $$('.si360-tab').forEach((p) => { p.hidden = p.id !== `si-tab-${a.dataset.siTab}`; });
            input.value = a.dataset.siTab;
            history.replaceState(null, '', `?tab=${a.dataset.siTab}`);
        });
    }
    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-si-test]');
        if (!b || !window.SI360) return;
        const key = b.dataset.siTest;
        const tr = b.closest('tr');
        const on = (ch) => !!(tr.querySelector(`[name="alert[${key}][${ch}]"]`) || {}).checked;
        const tone = (tr.querySelector(`[name="tone[${key}]"]`) || {}).value;
        window.SI360.test(key, {sound: on('sound'), voice: on('voice'), sticky: on('sticky'), flag: on('flag'), tone: tone || 'bip'});
    });
})();
