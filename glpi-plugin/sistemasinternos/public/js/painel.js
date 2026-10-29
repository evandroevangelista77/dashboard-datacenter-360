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
        const css = getComputedStyle(document.body);
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
                {name: 'Abertos', type: 'line', smooth: true, symbolSize: 6, data: d.days.opened, itemStyle: {color: '#3769B2'}, areaStyle: {color: 'rgba(55,105,178,.18)'}},
                {name: 'Solucionados', type: 'line', smooth: true, symbolSize: 6, data: d.days.solved, itemStyle: {color: '#22c55e'}},
            ],
        });
        window.addEventListener('resize', () => charts.forEach((c) => c.resize()));
    };
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
