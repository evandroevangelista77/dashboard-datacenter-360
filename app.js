/* DataCenter 360 — frontend (lê api.php; nada de credenciais no navegador) */
(() => {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

  const C = {
    text: css('--text'), text2: css('--text-2'), muted: css('--muted'), grid: css('--grid'), axis: css('--axis'),
    s1: css('--series-1'), s2: css('--series-2'),
    good: css('--good'), warning: css('--warning'), serious: css('--serious'), critical: css('--critical'),
    sev: [0, 1, 2, 3, 4, 5].map((i) => css(`--sev-${i}`)),
  };
  const SEV = ['Não classificada', 'Informação', 'Atenção', 'Média', 'Alta', 'Desastre'];

  /* ---------------- formatação */
  const nf = new Intl.NumberFormat('pt-BR');
  const nf1 = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
  const nf2 = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 });
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  function dur(sec) {
    if (sec == null || Number.isNaN(sec)) return '—';
    sec = Math.max(0, Math.round(sec));
    const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    if (d) return `${d}d ${h}h`;
    if (h) return `${h}h ${String(m).padStart(2, '0')}m`;
    if (m) return `${m}m ${String(s).padStart(2, '0')}s`;
    return `${s}s`;
  }
  const bytes = (b) => {
    if (b == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return `${nf1.format(b)} ${u[i]}`;
  };
  const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const when = (ts) => new Date(ts * 1000).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const sevTag = (s) => `<span class="sev"><i style="background:${C.sev[s]}"></i>${SEV[s]}</span>`;
  const stateCls = (v) => (v === 'OK' || v === true ? 'good' : v == null ? 'unknown' : 'critical');

  /* ---------------- estado: filtros do servidor (URL) + filtros locais (clique) */
  const state = { from: '', to: '', hostid: '', ticket: '', preset: '7' };
  const local = { sev: null, ticketView: 'all', category: null, entity: null };
  let data = null, timer = null, nextAt = 0, hostNames = {};

  function applyPreset(p) {
    const now = new Date(); let from = new Date(now);
    if (p === 'today') from = now;
    else if (p === 'month') from = new Date(now.getFullYear(), now.getMonth(), 1);
    else from.setDate(now.getDate() - (parseInt(p, 10) - 1));
    state.from = ymd(from); state.to = ymd(now); state.preset = p;
  }
  function readUrl() {
    const q = new URLSearchParams(location.search);
    applyPreset(q.get('preset') || '7');
    if (q.get('from') && q.get('to')) { state.from = q.get('from'); state.to = q.get('to'); state.preset = q.get('preset') || ''; }
    state.hostid = q.get('hostid') || '';
    state.ticket = q.get('ticket') || '';
  }
  function writeUrl() {
    const q = new URLSearchParams();
    if (state.preset) q.set('preset', state.preset); else { q.set('from', state.from); q.set('to', state.to); }
    if (state.hostid) q.set('hostid', state.hostid);
    if (state.ticket) q.set('ticket', state.ticket);
    history.replaceState(null, '', `${location.pathname}?${q}`);
  }
  function syncForm() {
    $('#from').value = state.from; $('#to').value = state.to;
    $('#host').value = state.hostid; $('#ticket').value = state.ticket;
    $$('.presets button').forEach((b) => b.classList.toggle('on', b.dataset.preset === state.preset));
  }
  function setHost(id) { state.hostid = id; syncForm(); load(); }

  /* ---------------- gráficos (ECharts) */
  const charts = {};
  const chart = (id) => (charts[id] ||= echarts.init(document.getElementById(id), null, { renderer: 'canvas' }));
  window.addEventListener('resize', () => Object.values(charts).forEach((c) => c.resize()));

  const tooltip = {
    backgroundColor: '#0b1322', borderColor: '#22d3ee55', borderWidth: 1, padding: [8, 10],
    textStyle: { color: C.text, fontSize: 12 }, extraCssText: 'box-shadow:0 8px 24px rgba(0,0,0,.45);border-radius:8px;',
  };

  /** Barras horizontais clicáveis; a selecionada fica em destaque e as demais esmaecem. */
  function hbar(id, rows, color, unit, { selected = null, onPick = null } = {}) {
    const c = chart(id);
    c.off('click');
    if (!rows.length) {
      c.setOption({ title: { text: 'Sem dados no período', left: 'center', top: 'middle', textStyle: { color: C.muted, fontSize: 13, fontWeight: 400 } } }, true);
      return;
    }
    const r = rows.slice(0, 8).reverse();
    c.setOption({
      animationDuration: 500,
      grid: { left: 8, right: 44, top: 6, bottom: 6, containLabel: true },
      tooltip: { ...tooltip, trigger: 'item', formatter: (p) => `${esc(p.name)}<br><b>${nf.format(p.value)}</b> ${unit}${onPick ? '<br><span style="color:#7b8798">clique para filtrar</span>' : ''}` },
      xAxis: { type: 'value', show: false, minInterval: 1 },
      yAxis: { type: 'category', data: r.map((x) => x.label), axisLine: { show: false }, axisTick: { show: false },
        axisLabel: { color: C.text2, fontSize: 11, width: 150, overflow: 'truncate' } },
      series: [{
        type: 'bar', barMaxWidth: 16, cursor: onPick ? 'pointer' : 'default',
        data: r.map((x) => ({ value: x.value, key: x.key ?? x.label,
          itemStyle: { color: x.color || color, opacity: selected == null || selected === (x.key ?? x.label) ? 1 : 0.3, borderRadius: [0, 4, 4, 0] } })),
        label: { show: true, position: 'right', color: C.text, fontSize: 11 },
        emphasis: { itemStyle: { shadowBlur: 12, shadowColor: 'rgba(34,211,238,0.45)' } },
      }],
    }, true);
    if (onPick) c.on('click', (p) => onPick(p.data.key));
  }

  function gauge(id, { title, value, max = 100, unit = '%', bands, digits = 1, sub = '' }) {
    const v = value == null ? 0 : value;
    const color = value == null ? C.muted : (bands.find((b) => v <= b[0]) || bands[bands.length - 1])[1];
    chart(id).setOption({
      tooltip: { ...tooltip, formatter: () => `${esc(title)}<br><b>${value == null ? 'sem dados' : nf2.format(value) + (unit ? ' ' + unit : '')}</b>${sub ? '<br>' + esc(sub) : ''}` },
      series: [{
        type: 'gauge', startAngle: 215, endAngle: -35, min: 0, max, radius: '80%', center: ['50%', '50%'],
        progress: { show: true, width: 12, roundCap: true, itemStyle: { color, shadowColor: color, shadowBlur: 10 } },
        axisLine: { roundCap: true, lineStyle: { width: 12, color: [[1, C.grid]] } },
        pointer: { show: false }, anchor: { show: false },
        axisTick: { distance: -20, length: 4, lineStyle: { color: C.axis, width: 1 } },
        splitLine: { distance: -22, length: 8, lineStyle: { color: C.axis, width: 1 } },
        axisLabel: { distance: -14, color: C.muted, fontSize: 9, formatter: (x) => (x === 0 || x === max ? nf.format(x) : '') },
        title: { offsetCenter: [0, '92%'], color: C.text2, fontSize: 11, lineHeight: 15 },
        detail: { offsetCenter: [0, '0%'], valueAnimation: true, color: C.text, fontSize: 22, fontWeight: 700,
          formatter: () => (value == null ? '—' : `${nf2.format(Number(value.toFixed(digits)))}${unit ? ' ' + unit : ''}`) },
        data: [{ value: v, name: sub ? `${title}\n${sub}` : title }],
      }],
    }, true);
  }

  /* ---------------- KPIs com contagem animada */
  function countTo(el, target, fmt) {
    const from = Number(el.dataset.v || 0);
    el.dataset.v = target;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || from === target) { el.textContent = fmt(target); return; }
    const t0 = performance.now(), ms = 700;
    const step = (t) => {
      const k = Math.min(1, (t - t0) / ms), e = 1 - Math.pow(1 - k, 3);
      el.textContent = fmt(from + (target - from) * e);
      if (k < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  }
  function setKpi(name, value, sub, fmt) {
    const el = $(`.kpi[data-kpi="${name}"]`), b = el.querySelector('b');
    if (typeof value === 'number' && fmt) countTo(b, value, fmt);
    else { b.textContent = value; b.dataset.v = 0; }
    el.querySelector('small').innerHTML = sub;
  }

  /* ---------------- render */
  function render(d) {
    const k = d.kpi;
    const activeSev = d.active_by_severity || {};
    const worst = [5, 4, 3, 2, 1, 0].find((s) => activeSev[s]);
    const int = (x) => nf.format(Math.round(x));
    setKpi('alerts', k.alerts, 'eventos de problema no período', int);
    setKpi('active', k.active_problems, worst != null ? `pior: ${sevTag(worst)}` : 'nenhum problema aberto', int);
    setKpi('tickets', k.tickets, `${nf.format(k.backlog)} em aberto agora`, int);
    setKpi('hosts', k.hosts,
      `<span class="tag"><i style="background:${C.good}"></i>${k.hosts_online} on</span>` +
      `<span class="tag"><i style="background:${C.critical}"></i>${k.hosts_offline} off</span>` +
      (k.availability != null ? `<span>· disp. ${nf2.format(k.availability)}%</span>` : ''), int);
    if (k.sla_pct == null) setKpi('sla', '—', 'nenhum chamado solucionado');
    else setKpi('sla', k.sla_pct, `${k.sla_met} de ${k.sla_total} dentro da meta`, (x) => `${nf1.format(x)}%`);
    setKpi('tma', dur(k.tma), k.tme != null ? `espera média: ${dur(k.tme)}` : 'abertura → solução');
    setKpi('mttd', dur(k.mttd), k.mttd_samples ? `${k.mttd_samples} reconhecimento(s)` : 'sem reconhecimentos no período');
    setKpi('mttr', dur(k.mttr), k.mttr_samples ? `${k.mttr_samples} problema(s) resolvido(s)` : 'sem recuperações no período');

    renderSources(d);
    renderTicker(d.active);
    renderDb(d.database);
    renderHealth(d.database.app, d.slas);
    renderHosts(d.hosts);
    renderBreakdowns();
    renderActive();
    renderTickets();
    renderDetail(d.detail, d.links);
  }

  function renderSources(d) {
    const set = (id, href, ver) => {
      const a = $(id); a.href = href;
      a.querySelector('[data-ver]').textContent = ver || '—';
      a.querySelector('[data-led]').className = `led ${ver ? 'ok' : 'err'}`;
      a.title = ver ? `Abrir (versão ${ver})` : 'Sem resposta';
    };
    set('#srcZabbix', d.links.zabbix, d.versions?.zabbix);
    set('#srcGlpi', d.links.glpi, d.versions?.glpi);
  }

  function renderTicker(active) {
    const move = $('#tickerMove');
    if (!active.length) {
      move.innerHTML = '<span class="ticker-item"><i style="background:var(--good)"></i><b>Nenhum problema ativo</b> — ambiente estável</span>';
    } else {
      const now = Date.now() / 1000;
      move.innerHTML = active.map((p) => `
        <button type="button" class="ticker-item" data-hostid="${p.hostid ?? ''}" title="Filtrar por ${esc(p.host)}">
          <i style="background:${C.sev[p.severity]}"></i><b>${esc(p.host)}</b> ${esc(p.name)} <em>${SEV[p.severity]} · há ${dur(now - p.clock)}</em>
        </button>`).join('');
    }
    // velocidade constante independente da quantidade de itens
    requestAnimationFrame(() => move.style.setProperty('--ticker-time', `${Math.max(25, move.scrollWidth / 70)}s`));
  }

  function renderDb(db) {
    $('#dbHost').textContent = db.host ? `${db.version || 'MySQL'} @ ${db.host}` : 'sem host de banco configurado';
    gauge('gEff', { title: 'Eficiência do buffer pool', value: db.buffer_efficiency, bands: [[90, C.critical], [97, C.warning], [100, C.good]] });
    gauge('gUtil', { title: 'Uso do buffer pool', value: db.buffer_utilization, bands: [[85, C.good], [95, C.warning], [100, C.critical]] });
    gauge('gConn', { title: 'Conexões em uso', value: db.connections_pct,
      sub: db.connections != null ? `${db.connections} de ${db.max_connections}` : '', bands: [[70, C.good], [90, C.warning], [100, C.critical]] });
    const hist = db.qps_history.map((p) => p[1]);
    const qMax = Math.max(10, Math.ceil(Math.max(db.qps || 0, ...hist) * 1.5 / 10) * 10);
    gauge('gQps', { title: 'Consultas por segundo', value: db.qps, max: qMax, unit: '', digits: 2, bands: [[Infinity, C.good]],
      sub: `máx. 1h: ${nf2.format(Math.max(0, ...hist))}` });
    const stats = [
      ['Status', db.online ? '<span class="state good">online</span>' : '<span class="state critical">offline</span>'],
      ['Threads em execução', db.threads_running ?? '—'],
      ['Consultas lentas/s', db.slow_qps != null ? nf2.format(db.slow_qps) : '—'],
      ['Tamanho glpidb', bytes(db.glpidb_size)],
      ['Uptime', dur(db.uptime)],
    ];
    $('#dbStats').innerHTML = stats.map(([t, v]) => `<div><dt>${t}</dt><dd>${v}</dd></div>`).join('');
  }

  function renderHealth(app, slas) {
    const items = [
      ['Aplicação GLPI', app.glpi], ['Banco de dados', app.database], ['Ações automáticas', app.crontasks],
      ['Sistema de arquivos', app.filesystem],
      ['Página de login', app.login_ok == null ? null : app.login_ok ? 'OK' : 'FALHA', app.login_time != null ? `${nf.format(Math.round(app.login_time * 1000))} ms` : ''],
    ];
    $('#health').innerHTML = items.map(([n, v, extra]) =>
      `<li><span>${n}${extra ? ` <small class="hint">${extra}</small>` : ''}</span><span class="state ${stateCls(v)}">${esc(v ?? 'sem dados')}</span></li>`).join('');
    $('#slas').innerHTML = slas.length ? slas.map((s) => {
      const ok = s.sli == null ? 'unknown' : s.sli >= s.slo ? 'good' : 'critical';
      const label = s.sli == null ? (s.services ? 'sem dados' : 'sem serviços') : `${nf2.format(s.sli)}%`;
      return `<li><span>${esc(s.name)} <small>· meta ${nf2.format(s.slo)}%</small></span>
        <span class="bar" aria-hidden="true"><span style="width:${s.sli ?? 0}%"></span></span>
        <span class="state ${ok}">${label}</span></li>`;
    }).join('') : '<li class="empty">Nenhum SLA cadastrado no Zabbix</li>';
  }

  function renderHosts(hosts) {
    const order = { offline: 0, parcial: 1, desconhecido: 2, online: 3 };
    const sorted = [...hosts].sort((a, b) => (order[a.status] - order[b.status]) || ((b.max_severity ?? -1) - (a.max_severity ?? -1)) || a.name.localeCompare(b.name));
    const cls = { online: 'good', offline: 'critical', parcial: 'warning', desconhecido: 'unknown' };
    $('#hostsHint').textContent = state.hostid ? 'clique de novo para remover o filtro' : 'clique para filtrar · disponibilidade = ICMP no período';
    $('#hostGrid').innerHTML = sorted.map((h) => `
      <button type="button" class="host ${h.hostid === state.hostid ? 'sel' : ''}" data-hostid="${h.hostid}" title="${esc(h.groups.join(', '))}">
        <span class="top"><span class="hn">${esc(h.name)}</span><span class="state ${cls[h.status]}">${h.status}</span></span>
        <span class="meta"><span>${h.problems ? `${h.problems} problema(s) · ${SEV[h.max_severity]}` : 'sem problemas'}</span>
          <span>${h.availability != null ? nf2.format(h.availability) + '%' : 's/ ICMP'}</span></span>
        <span class="avail" aria-hidden="true"><span style="width:${h.availability ?? 0}%"></span></span>
      </button>`).join('');
  }

  function renderBreakdowns() {
    const d = data;
    hbar('chTopHosts', d.top_hosts.map((x) => ({ label: x.host, value: x.alerts, key: hostNames[x.host] || null })), C.s1, 'alertas', {
      selected: state.hostid || null,
      onPick: (id) => { if (id) setHost(state.hostid === id ? '' : id); },
    });
    hbar('chCategory', Object.entries(d.tickets_by_category).map(([l, v]) => ({ label: l, value: v })), C.s2, 'chamados', {
      selected: local.category,
      onPick: (k) => { local.category = local.category === k ? null : k; renderBreakdowns(); renderTickets(); flash('secTickets'); },
    });
    hbar('chEntity', Object.entries(d.tickets_by_entity).map(([l, v]) => ({ label: l, value: v })), C.s2, 'chamados', {
      selected: local.entity,
      onPick: (k) => { local.entity = local.entity === k ? null : k; renderBreakdowns(); renderTickets(); flash('secTickets'); },
    });
  }

  /* ---------------- tabelas ordenáveis */
  function sortRows(table, rows) {
    const key = table.dataset.sort, dir = table.dataset.dir === 'asc' ? 1 : -1;
    $$('th[data-key]', table).forEach((th) => {
      th.classList.remove('asc', 'desc');
      if (th.dataset.key === key) th.classList.add(table.dataset.dir);
    });
    return [...rows].sort((a, b) => {
      const x = a[key], y = b[key];
      if (x == null && y == null) return 0;
      if (x == null) return 1;
      if (y == null) return -1;
      return (typeof x === 'string' ? x.localeCompare(y, 'pt-BR') : x - y) * dir;
    });
  }
  $$('table[data-sort]').forEach((table) => table.querySelector('thead').addEventListener('click', (ev) => {
    const th = ev.target.closest('th[data-key]'); if (!th) return;
    table.dataset.dir = table.dataset.sort === th.dataset.key && table.dataset.dir === 'desc' ? 'asc' : 'desc';
    table.dataset.sort = th.dataset.key;
    table.id === 'tblActive' ? renderActive() : renderTickets();
  }));

  function renderActive() {
    const all = data.active, now = Date.now() / 1000;
    const counts = [0, 0, 0, 0, 0, 0];
    all.forEach((p) => counts[p.severity]++);
    $('#sevChips').innerHTML = `<button type="button" class="chip ${local.sev == null ? 'on' : ''}" data-sev="">Todas <b>${all.length}</b></button>` +
      [5, 4, 3, 2, 1, 0].map((s) => `<button type="button" class="chip ${local.sev === s ? 'on' : ''}" data-sev="${s}" ${counts[s] ? '' : 'disabled'}>
        <i style="background:${C.sev[s]}"></i>${SEV[s]} <b>${counts[s]}</b></button>`).join('');
    const rows = sortRows($('#tblActive'), all.filter((p) => local.sev == null || p.severity === local.sev));
    $('#activeHint').textContent = `${rows.length} de ${all.length}`;
    $('#tblActive tbody').innerHTML = rows.length ? rows.map((p) => `
      <tr class="clickable" data-hostid="${p.hostid ?? ''}" title="Filtrar por ${esc(p.host)}">
      <td>${sevTag(p.severity)}</td><td>${esc(p.host)}</td><td class="wrap">${esc(p.name)}</td>
      <td class="num">${dur(now - p.clock)}</td><td>${p.acknowledged ? '<span class="pill">sim</span>' : '—'}</td></tr>`).join('')
      : '<tr><td colspan="5" class="empty">Nenhum problema para este filtro</td></tr>';
  }

  function renderTickets() {
    const all = data.tickets_list, links = data.links;
    const views = {
      all: ['Todos', () => true],
      open: ['Em aberto', (t) => t.status_id < 5],
      done: ['Solucionados', (t) => t.status_id >= 5],
      late: ['Fora do SLA', (t) => t.sla_ok === false],
    };
    const base = all.filter((t) => (!local.category || (t.category || 'Sem categoria') === local.category) && (!local.entity || t.entity === local.entity));
    $('#ticketChips').innerHTML = Object.entries(views).map(([k, [label, fn]]) =>
      `<button type="button" class="chip ${local.ticketView === k ? 'on' : ''}" data-view="${k}">${label} <b>${base.filter(fn).length}</b></button>`).join('') +
      (local.category ? `<button type="button" class="chip clear" data-unset="category">Categoria: ${esc(local.category)} ✕</button>` : '') +
      (local.entity ? `<button type="button" class="chip clear" data-unset="entity">Entidade: ${esc(local.entity)} ✕</button>` : '');
    const rows = sortRows($('#tblTickets'), base.filter(views[local.ticketView][1]));
    const total = data.kpi.tickets;
    $('#ticketsHint').textContent = `${rows.length} exibido(s) · ${total} no período`;
    const prColor = { 6: C.critical, 5: C.critical, 4: C.serious, 3: C.warning, 2: C.sev[1], 1: C.muted };
    $('#tblTickets tbody').innerHTML = rows.length ? rows.map((t) => `
      <tr class="clickable" data-ticket="${t.id}" title="Ver detalhes do chamado #${t.id}">
        <td class="num"><a href="${links.glpi}/front/ticket.form.php?id=${t.id}" target="_blank" rel="noopener" title="Abrir no GLPI">${t.id}</a></td>
        <td class="wrap">${esc(t.name)}</td>
        <td><span class="pill">${esc(t.status)}</span></td>
        <td><span class="sev"><i style="background:${prColor[t.priority_id]}"></i>${esc(t.priority)}</span></td>
        <td>${esc(t.category || '—')}</td>
        <td class="num">${t.resolve_seconds != null ? dur(t.resolve_seconds) : '—'}</td>
        <td>${t.sla_ok == null ? '<span class="state unknown">aberto</span>' : t.sla_ok ? '<span class="state good">no prazo</span>' : '<span class="state critical">estourado</span>'}</td>
      </tr>`).join('')
      : '<tr><td colspan="7" class="empty">Nenhum chamado para este filtro</td></tr>';
  }

  function renderDetail(det, links) {
    const panel = $('#detailPanel');
    if (!det) { panel.hidden = true; return; }
    panel.hidden = false;
    const t = det.ticket, e = det.event;
    const cells = [
      ['Chamado', `<a href="${links.glpi}/front/ticket.form.php?id=${t.id}" target="_blank" rel="noopener">#${t.id}</a> · ${esc(t.status)}`],
      ['Entidade', esc(t.entity)], ['Categoria', esc(t.category || '—')], ['Prioridade', esc(t.priority)],
      ['Aberto em', esc(t.date)], ['Solucionado em', esc(t.solvedate || '—')],
      ['Meta de solução', esc(t.sla_target)], ['Resultado', t.sla_ok == null ? 'em aberto' : t.sla_ok ? 'no prazo' : 'estourado'],
    ];
    let html = `<p style="margin:0 0 10px;font-weight:600">${esc(t.name)}</p><dl class="detail-grid">${cells.map(([a, b]) => `<div><dt>${a}</dt><dd>${b}</dd></div>`).join('')}</dl>`;
    if (e) {
      const steps = [[e.start, `Problema detectado no Zabbix — ${sevTag(e.severity)} em <b>${esc(e.host)}</b>`],
        ...e.acks.map((a) => [a.clock, `Reconhecido por <b>${esc(a.user || 'operador')}</b>${a.message ? `: “${esc(a.message)}”` : ''}`]),
        ...(e.end ? [[e.end, `Recuperado — duração <b>${dur(e.end - e.start)}</b>`]] : [])].sort((a, b) => a[0] - b[0]);
      html += `<ul class="timeline-list">${steps.map(([ts, txt]) => `<li><b>${when(ts)}</b> · ${txt}</li>`).join('')}</ul>
        <p style="margin:10px 0 0"><a href="${e.url}" target="_blank" rel="noopener">Abrir evento ${e.eventid} no Zabbix ↗</a></p>`;
    } else {
      html += '<p class="hint" style="margin-top:10px">Chamado sem evento do Zabbix vinculado.</p>';
    }
    $('#detail').innerHTML = html;
  }

  /* ---------------- dados */
  function toast(msg) {
    const t = $('#toast'); t.textContent = msg; t.hidden = false;
    clearTimeout(toast.h); toast.h = setTimeout(() => (t.hidden = true), 8000);
  }
  function flash(id) {
    const el = document.getElementById(id); if (!el) return;
    el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash');
  }

  async function load() {
    writeUrl();
    const q = new URLSearchParams({ action: 'data', from: state.from, to: state.to });
    if (state.hostid) q.set('hostid', state.hostid);
    if (state.ticket) q.set('ticket', state.ticket);
    $('#reload').classList.add('spin');
    try {
      const r = await fetch(`api.php?${q}`, { cache: 'no-store' });
      const j = await r.json();
      if (!r.ok || j.error) throw new Error(j.error || `HTTP ${r.status}`);
      data = j;
      render(j);
      $('#updated').textContent = `Atualizado às ${new Date(j.generated_at * 1000).toLocaleTimeString('pt-BR')}`;
    } catch (err) {
      $$('.source [data-led]').forEach((l) => (l.className = 'led err'));
      toast(`Não foi possível carregar os dados: ${err.message}`);
    } finally {
      $('#reload').classList.remove('spin');
      schedule();
    }
  }

  async function loadHosts() {
    try {
      const hosts = await (await fetch('api.php?action=hosts', { cache: 'no-store' })).json();
      hosts.forEach((h) => (hostNames[h.name] = h.hostid));
      $('#host').insertAdjacentHTML('beforeend', hosts.map((h) => `<option value="${h.hostid}">${esc(h.name)}</option>`).join(''));
    } catch { /* o select segue só com "Todos" */ }
  }

  function schedule() {
    clearTimeout(timer);
    const s = parseInt($('#refresh').value, 10);
    nextAt = s ? Date.now() + s * 1000 : 0;
    if (s) timer = setTimeout(load, s * 1000);
  }

  /* ---------------- eventos */
  $$('.presets button').forEach((b) => b.addEventListener('click', () => { applyPreset(b.dataset.preset); syncForm(); load(); }));
  $('#filters').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const f = $('#from').value, t = $('#to').value;
    if (f > t) { toast('A data inicial é maior que a final.'); return; }
    if (f !== state.from || t !== state.to) state.preset = '';
    Object.assign(state, { from: f, to: t, hostid: $('#host').value, ticket: $('#ticket').value.trim() });
    syncForm(); load();
  });
  $('#host').addEventListener('change', () => setHost($('#host').value));
  $('#clear').addEventListener('click', () => {
    applyPreset('7'); state.hostid = ''; state.ticket = '';
    Object.assign(local, { sev: null, ticketView: 'all', category: null, entity: null });
    syncForm(); load();
  });
  $('#hostGrid').addEventListener('click', (ev) => {
    const b = ev.target.closest('.host'); if (b) setHost(state.hostid === b.dataset.hostid ? '' : b.dataset.hostid);
  });
  $('#tickerMove').addEventListener('click', (ev) => {
    const b = ev.target.closest('.ticker-item[data-hostid]'); if (b && b.dataset.hostid) { setHost(b.dataset.hostid); flash('secActive'); }
  });
  $('#tblActive').addEventListener('click', (ev) => {
    const tr = ev.target.closest('tr[data-hostid]'); if (tr && tr.dataset.hostid) setHost(state.hostid === tr.dataset.hostid ? '' : tr.dataset.hostid);
  });
  $('#tblTickets').addEventListener('click', (ev) => {
    if (ev.target.closest('a')) return;
    const tr = ev.target.closest('tr[data-ticket]'); if (!tr) return;
    state.ticket = tr.dataset.ticket; syncForm(); load();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  $('#sevChips').addEventListener('click', (ev) => {
    const c = ev.target.closest('.chip'); if (!c || c.disabled) return;
    local.sev = c.dataset.sev === '' ? null : Number(c.dataset.sev); renderActive();
  });
  $('#ticketChips').addEventListener('click', (ev) => {
    const c = ev.target.closest('.chip'); if (!c) return;
    if (c.dataset.unset) local[c.dataset.unset] = null; else local.ticketView = c.dataset.view;
    renderBreakdowns(); renderTickets();
  });
  $$('.kpi[data-go]').forEach((k) => k.addEventListener('click', () => {
    const target = document.getElementById(k.dataset.go);
    if (k.dataset.kpi === 'sla') local.ticketView = 'late';
    if (k.dataset.kpi === 'sla' && data) renderTickets();
    target.scrollIntoView({ behavior: 'smooth', block: 'start' }); flash(k.dataset.go);
  }));
  $('#closeDetail').addEventListener('click', () => { state.ticket = ''; syncForm(); load(); });
  $('#refresh').addEventListener('change', () => {
    try { localStorage.setItem('dc360.refresh', $('#refresh').value); } catch { /* sem storage */ }
    schedule();
  });
  $('#reload').addEventListener('click', load);
  $('#fullscreen').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.();
  });

  setInterval(() => {
    $('#clock').textContent = new Date().toLocaleTimeString('pt-BR');
    const s = parseInt($('#refresh').value, 10);
    $('#countdown').style.width = nextAt && s ? `${100 - Math.max(0, (nextAt - Date.now()) / (s * 10))}%` : '0';
  }, 250);

  /* ---------------- início */
  try { const r = localStorage.getItem('dc360.refresh'); if (r !== null) $('#refresh').value = r; } catch { /* sem storage */ }
  readUrl();
  loadHosts().then(() => { syncForm(); load(); });
})();
