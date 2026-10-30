/* NOC 360 — frontend (lê api.php; nada de credenciais no navegador) */
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
  const SHOWN = [5, 4, 2]; // Desastre, Alta e Atenção (Warning)

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
  const bps = (v) => {
    if (v == null) return '—';
    if (v >= 1e9) return `${nf1.format(v / 1e9)} Gbps`;
    if (v >= 1e6) return `${nf1.format(v / 1e6)} Mbps`;
    if (v >= 1e3) return `${nf.format(Math.round(v / 1e3))} Kbps`;
    return `${nf.format(Math.round(v))} bps`;
  };
  const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const when = (ts) => new Date(ts * 1000).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const sevTag = (s) => `<span class="sev"><i style="background:${C.sev[s]}"></i>${SEV[s]}</span>`;
  const load3 = (p) => (p == null ? 'unknown' : p >= 90 ? 'critical' : p >= 70 ? 'warning' : 'good');
  const loadColor = (p) => ({ critical: C.critical, warning: C.warning, good: C.good, unknown: C.muted }[load3(p)]);

  /* ---------------- estado: filtros do servidor (URL) + filtros locais (clique) */
  const state = { from: '', to: '', hostid: '', ticket: '', client: '', preset: '7' };
  const local = { sev: null, noack: false, ticketView: 'all' };
  let data = null, office = null, si = null, timer = null, nextAt = 0, clientsLoaded = false;

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
    state.client = q.get('client') || '';
  }
  function writeUrl() {
    const q = new URLSearchParams();
    if (state.preset) q.set('preset', state.preset); else { q.set('from', state.from); q.set('to', state.to); }
    if (state.hostid) q.set('hostid', state.hostid);
    if (state.ticket) q.set('ticket', state.ticket);
    if (state.client) q.set('client', state.client);
    history.replaceState(null, '', `${location.pathname}?${q}`);
  }
  function syncForm() {
    $('#from').value = state.from; $('#to').value = state.to;
    $('#client').value = state.client; $('#ticket').value = state.ticket;
    $$('.presets button').forEach((b) => b.classList.toggle('on', b.dataset.preset === state.preset));
  }
  function setHost(id) { closeDrawer(); state.hostid = id; syncForm(); load(); }
  function setClient(name) { closeDrawer(); state.client = state.client === name ? '' : name; state.hostid = ''; syncForm(); load(); }

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
  function setKpi(name, value, sub, fmt, tone = '') {
    const el = $(`[data-kpi="${name}"]`), b = el.querySelector('b');
    el.dataset.tone = tone;
    if (typeof value === 'number' && fmt) countTo(b, value, fmt);
    else { b.textContent = value; b.dataset.v = 0; }
    el.querySelector('small').innerHTML = sub;
  }

  /* ---------------- render */
  function render(d) {
    const k = d.kpi, int = (x) => nf.format(Math.round(x));
    SHOWN.forEach((sv) => {
      const n = k.alerts_by_sev[sv] ?? 0, a = k.active_by_sev[sv] ?? 0;
      setKpi(`sev${sv}`, n, `${nf.format(a)} ${a === 1 ? 'ativo' : 'ativos'} agora`, int, sv === 5 && n ? 'bad' : '');
    });
    setKpi('unacked', k.unacked, `de ${nf.format(k.active)} ativos`, int, k.unacked ? 'warn' : 'ok');
    setKpi('tickets', k.tickets, `${nf.format(k.backlog)} em aberto agora`, int);
    setKpi('pending', k.pending, k.backlog ? `de ${nf.format(k.backlog)} em aberto` : 'nenhum em aberto', int, k.pending ? 'warn' : 'ok');
    setKpi('overdue', k.overdue, 'prazo do GLPI vencido', int, k.overdue ? 'bad' : 'ok');
    setKpi('mttd_zbx', dur(k.mttd_zbx), k.mttd_zbx_n ? `${nf.format(k.mttd_zbx_n)} alertas · até o ACK` : 'sem reconhecimentos');
    setKpi('mttd_glpi', dur(k.mttd_glpi), k.mttd_glpi_n ? `${nf.format(k.mttd_glpi_n)} chamados · até assumir` : 'sem chamados');
    setKpi('mttr_zbx', dur(k.mttr_zbx), k.mttr_zbx_n ? `${nf.format(k.mttr_zbx_n)} alertas · até recuperar` : 'sem recuperações');
    setKpi('mttr_glpi', dur(k.mttr_glpi), k.mttr_glpi_n ? `${nf.format(k.mttr_glpi_n)} chamados · até solucionar` : 'sem chamados');

    fillClients(d.clients);
    renderSources(d);
    renderTicker(d.active);
    renderClientCharts(d.clients);
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

  /** Lista de clientes (entidades do GLPI abaixo de NOC); os que só existem no Zabbix ficam num grupo à parte. */
  function fillClients(clients) {
    const sel = $('#client'), cur = state.client;
    const opt = (c) => `<option value="${esc(c.name)}">${esc(c.name)}${c.tickets + c.open + c.alerts ? ` · ${nf.format(c.tickets)} ch. · ${nf.format(c.alerts)} al.` : ''}</option>`;
    const g = clients.filter((c) => c.kind === 'glpi'), z = clients.filter((c) => c.kind !== 'glpi');
    const html = `<option value="">Todos os clientes</option>${g.map(opt).join('')}${z.length ? `<optgroup label="Só no Zabbix">${z.map(opt).join('')}</optgroup>` : ''}`;
    if (sel.dataset.sig !== html) { sel.innerHTML = html; sel.dataset.sig = html; }
    sel.value = cur;
  }

  /** Cada item da linha leva direto ao alerta no Zabbix (nova aba). */
  function renderTicker(active) {
    const move = $('#tickerMove');
    if (!active.length) {
      move.innerHTML = '<span class="ticker-item"><i style="background:var(--good)"></i><b>Nenhum alerta ativo</b> — ambiente estável</span>';
    } else {
      const now = Date.now() / 1000;
      move.innerHTML = active.map((p) => `
        <a class="ticker-item" href="${esc(p.url)}" target="_blank" rel="noopener" title="Abrir este alerta no Zabbix">
          <i style="background:${C.sev[p.severity]}"></i><b>${esc(p.host)}</b> ${esc(p.name)} <em>${SEV[p.severity]} · há ${dur(now - p.clock)}${p.acknowledged ? '' : ' · sem ACK'}</em>
        </a>`).join('');
    }
    // velocidade constante independente da quantidade de itens
    requestAnimationFrame(() => move.style.setProperty('--ticker-time', `${Math.max(25, move.scrollWidth / 70)}s`));
  }

  /* ---------------- links e APs do escritório */
  const bar = (v, extra = '') => `<span class="cell-bar" title="${v == null ? 'sem dados' : nf1.format(v) + '%'}"><span class="bar"><span style="width:${Math.min(100, Math.max(0, v ?? 0))}%;background:${loadColor(v)}"></span></span><b>${v == null ? '—' : Math.round(v) + '%'}</b>${extra}</span>`;
  const st = (ok, on = 'no ar', off = 'fora do ar') => `<span class="state ${ok ? 'good' : 'critical'}">${ok ? on : off}</span>`;
  /* ---------------- por cliente */
  /* ---------------- chamados por status (cliente, grupo, responsável, idade) */
  const STAT = [
    { k: 'Novo', ids: [1], color: '#5598e7' },
    { k: 'Em atendimento', ids: [2, 3], color: '#22d3ee' },
    { k: 'Pendente', ids: [4], color: '#fab219' },
    { k: 'Solucionado', ids: [5], color: '#34d399' },
    { k: 'Fechado', ids: [6], color: '#7b8798' },
  ];
  const statOf = (t) => STAT.find((s) => s.ids.includes(t.status_id)) || STAT[0];
  const cleanName = (s) => String(s || '').replace(/\s+/g, ' ').replace(/\s*-\s*Técnico$/i, '').trim().split(', ')[0];
  const inPeriod = (t) => String(t.date).slice(0, 10) >= state.from && String(t.date).slice(0, 10) <= state.to;
  const lblAxis = { color: '#a9b6c8', fontSize: 11.5, width: 170, overflow: 'truncate' };

  /** Quadro de posições (estilo sala de controle): nome · barra segmentada proporcional ao total · total · indicadores. */
  function board(id, rows, { segs, cols, selected = null, onName = null, onSeg = null, limit = 10, empty = 'Sem dados no período' }) {
    const el = document.getElementById(id);
    const list = rows.slice(0, limit), max = Math.max(1, ...list.map((r) => r.total));
    if (!list.length) { el.innerHTML = `<div class="bd-empty">${empty}</div>`; return; }
    el.innerHTML = list.map((r, i) => `
      <div class="brow ${selected && selected === r.name ? 'on' : ''}" data-name="${esc(r.name)}">
        <span class="rk">${String(i + 1).padStart(2, '0')}</span>
        <button type="button" class="nm" data-act="name" title="${esc(r.name)}">${esc(r.name)}</button>
        <div class="bt"><div class="bw" style="width:${Math.max(4, (r.total / max) * 100)}%">
          ${segs.filter((s) => r.segs[s.k]).map((s) => `<i class="sg" data-s="${esc(s.k)}" title="${esc(s.k)}: ${r.segs[s.k]}" style="flex:${r.segs[s.k]};--c:${s.color}"></i>`).join('')}
        </div></div>
        <b class="tot">${nf.format(r.total)}</b>
        ${cols.map((c) => { const v = c.fn(r); return `<span class="bx ${v.tone || ''}" title="${esc(c.h)}"><small>${esc(c.h)}</small>${v.text}</span>`; }).join('')}
      </div>`).join('');
    el.onclick = (ev) => {
      const row = ev.target.closest('.brow'); if (!row) return;
      const seg = ev.target.closest('.sg');
      if (seg && onSeg) onSeg(row.dataset.name, seg.dataset.s); else if (onName) onName(row.dataset.name);
    };
  }
  const lgSel = { st: new Set(), al: new Set() };
  const legend = (id, segs, counts, g) => {
    const sel = lgSel[g];
    document.getElementById(id).innerHTML = segs.map((s) => `<button type="button" class="lg ${sel.size ? (sel.has(s.k) ? 'on' : 'off') : ''}" data-g="${g}" data-k="${esc(s.k)}" title="Clique para filtrar os gráficos por este status">
      <i style="background:${s.color}"></i>${esc(s.k)}${counts ? ` <b>${nf.format(counts[s.k] || 0)}</b>` : ''}</button>`).join('');
  };
  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('.lg'); if (!b || !data) return;
    const sel = lgSel[b.dataset.g]; sel.has(b.dataset.k) ? sel.delete(b.dataset.k) : sel.add(b.dataset.k);
    renderClientCharts(data.clients); renderBreakdowns();
  });
  const nz = (n, tone) => ({ text: n ? `<em>${nf.format(n)}</em>` : '<em class="z">0</em>', tone: n ? tone : '' });

  /** Agrupa chamados por uma chave e monta as linhas do quadro. */
  function ticketRows(tickets, keyOf) {
    const m = new Map(), now = Date.now() / 1000;
    tickets.forEach((t) => { const k = keyOf(t); if (!m.has(k)) m.set(k, []); m.get(k).push(t); });
    return [...m.entries()].map(([name, arr]) => {
      const segs = {}; arr.forEach((t) => { const k = statOf(t).k; segs[k] = (segs[k] || 0) + 1; });
      const open = arr.filter((t) => t.open);
      return { name, arr, segs, total: arr.length, open: open.length, late: arr.filter((t) => t.late).length,
        oldest: open.length ? Math.max(...open.map((t) => now - Date.parse(String(t.date).replace(' ', 'T')) / 1000)) : null };
    }).sort((a, b) => b.total - a.total);
  }
  const tkCols = [
    { h: 'Abertos', fn: (r) => nz(r.open, '') },
    { h: 'Atrasados', fn: (r) => nz(r.late, 'bad') },
    { h: 'Mais antigo', fn: (r) => ({ text: r.oldest ? `<em>${dur(r.oldest)}</em>` : '<em class="z">—</em>', tone: '' }) },
  ];

  function renderStatusCharts() {
    const every = data.tickets_list || [], fs = lgSel.st;
    const all = fs.size ? every.filter((t) => fs.has(statOf(t).k)) : every, per = all.filter(inPeriod);
    const tot = (arr) => { const o = {}; arr.forEach((t) => { const k = statOf(t).k; o[k] = (o[k] || 0) + 1; }); return o; };
    const lst = (what) => (name, series, rows) => {
      const r = rows.find((x) => x.name === name); if (!r) return;
      showTicketList(`${what}: ${name}`, series ? `Status ${series}` : 'Todos os status', series ? r.arr.filter((t) => statOf(t).k === series) : r.arr);
    };
    const every_ = (arr) => (arr === per ? every.filter(inPeriod) : every);
    const mk = (id, lg, arr, keyOf, what, opts = {}) => {
      const rows = ticketRows(arr, keyOf);
      legend(lg, STAT, tot(every_(arr)), 'st');
      board(id, rows, { segs: STAT, cols: tkCols, limit: opts.limit || 10, selected: opts.selected,
        onName: opts.onName || ((n) => lst(what)(n, null, rows)), onSeg: (n, s) => lst(what)(n, s, rows), empty: 'Sem chamados' });
    };
    mk('bdTkClient', 'lgTkClient', per, (t) => t.client || 'Sem cliente', 'Cliente', { selected: state.client || null, onName: (n) => setClient(n) });
    mk('bdStGroup', 'lgStGroup', all, (t) => t.group || 'Sem grupo', 'Grupo');
    mk('bdStTech', 'lgStTech', all, (t) => cleanName(t.tech) || 'Sem responsável', 'Responsável', { limit: 12 });

    // tempo em aberto: faixas de idade, da mais nova (verde) à mais antiga (vermelho)
    const open = all.filter((t) => t.open), now = Date.now() / 1000, D = 86400;
    const bands = [['< 1 dia', 0, D, '#34d399'], ['1–3 dias', D, 3 * D, '#a3e635'], ['3–7 dias', 3 * D, 7 * D, '#fab219'], ['7–15 dias', 7 * D, 15 * D, '#ec835a'], ['15–30 dias', 15 * D, 30 * D, '#e5604d'], ['> 30 dias', 30 * D, 1e12, '#d03b3b']];
    const ageOf = (t) => now - Date.parse(String(t.date).replace(' ', 'T')) / 1000;
    legend('lgStAge', STAT.filter((s) => s.ids[0] <= 4), tot(every.filter((t) => t.open)), 'st');
    const el = $('#bdStAge'), maxN = Math.max(1, ...bands.map((b) => open.filter((t) => ageOf(t) >= b[1] && ageOf(t) < b[2]).length));
    const BI = [
      '<circle cx="12" cy="12" r="9.5"/><path d="M12 7v5l3 2"/>',
      '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
      '<path d="M6 3h12M6 21h12M7 3c0 5 5 6 5 9s-5 4-5 9M17 3c0 5-5 6-5 9s5 4 5 9"/>',
      '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4M12 13v3M12 18v.01"/>',
      '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17v.01"/>',
      '<path d="M12 3c1 3 4 4.5 4 8.5a4 4 0 0 1-8 0c0-1.5.6-2.6 1.4-3.4C9.5 9.6 10 10.4 11 10.5 11 7.5 11 5.5 12 3zM8 17.5a5 5 0 0 0 8 0"/>',
    ];
    const BD = ['Abertos nas últimas 24 horas.', 'Em andamento, atenção ao prazo.', 'Perto de uma semana em aberto.', 'Passou de uma semana: priorizar.', 'Risco alto de estourar o SLA.', 'Crônicos: pedem escalonamento.'];
    el.innerHTML = bands.map((b, i) => {
      const arr = open.filter((t) => ageOf(t) >= b[1] && ageOf(t) < b[2]), n = arr.length, late = arr.filter((t) => t.late).length;
      const segs = STAT.map((s) => [s, arr.filter((t) => statOf(t) === s).length]).filter(([, v]) => v);
      return `<button type="button" class="age ${n ? '' : 'zero'}" data-i="${i}" style="--c:${b[3]}">
        <span class="atop"><span class="al">${b[0]}</span><svg class="ic" viewBox="0 0 24 24" aria-hidden="true">${BI[i]}</svg></span><b>${nf.format(n)}</b>
        <span class="ab2"><span class="abw" style="width:${n ? Math.max(8, (n / maxN) * 100) : 0}%">${segs.map(([s, v]) => `<i style="flex:${v};--c:${s.color}" title="${s.k}: ${v}"></i>`).join('')}</span></span>
        <small>${n ? (late ? `<span class="bad">${late} atrasado(s)</span>` : 'dentro do prazo') : 'nenhum'}</small>
        <em class="desc">${BD[i]}</em></button>`;
    }).join('');
    el.onclick = (ev) => {
      const b = ev.target.closest('.age'); if (!b) return;
      const band = bands[Number(b.dataset.i)];
      showTicketList(`Abertos há ${band[0]}`, 'Chamados em aberto agora', open.filter((t) => ageOf(t) >= band[1] && ageOf(t) < band[2]));
    };
  }

  function renderClientCharts(clients) {
    renderStatusCharts();
    const SV = SHOWN.map((s) => ({ k: SEV[s], color: C.sev[s], id: s }));
    const fa = lgSel.al;
    const base = clients.filter((c) => c.alerts).map((c) => ({
      name: c.name, active: c.active, unacked: c.unacked, segs: Object.fromEntries(SV.map((s) => [s.k, c.sev[s.id] || 0])) }));
    const sum = {}; SV.forEach((s) => { sum[s.k] = base.reduce((a, r) => a + r.segs[s.k], 0); });
    legend('lgAlClient', SV, sum, 'al');
    const rows = base.map((r) => ({ ...r, segs: Object.fromEntries(SV.map((s) => [s.k, !fa.size || fa.has(s.k) ? r.segs[s.k] : 0])) }))
      .map((r) => ({ ...r, total: SV.reduce((a, s) => a + r.segs[s.k], 0) })).filter((r) => r.total).sort((a, b) => b.total - a.total);
    board('bdAlClient', rows, { segs: SV, selected: state.client || null, onName: (n) => setClient(n), onSeg: (n) => setClient(n), limit: 10, empty: 'Sem alertas no período',
      cols: [{ h: 'Ativos', fn: (r) => nz(r.active, '') }, { h: 'Sem ACK', fn: (r) => nz(r.unacked, 'warn') }] });
  }

  function renderBreakdowns() {
    const d = data, SV = SHOWN.map((s) => ({ k: SEV[s], color: C.sev[s], id: s }));
    // hosts com mais alertas (Zabbix), empilhados por severidade
    const fa = lgSel.al;
    const base = d.top_hosts.map((h) => ({ name: h.host, hostid: h.hostid, active: h.active, unacked: h.unacked,
      segs: Object.fromEntries(SV.map((s) => [s.k, h.sev[s.id] || 0])) }));
    const sum = {}; SV.forEach((s) => { sum[s.k] = base.reduce((a, r) => a + r.segs[s.k], 0); });
    legend('lgTopHosts', SV, sum, 'al');
    const rows = base.map((r) => ({ ...r, segs: Object.fromEntries(SV.map((s) => [s.k, !fa.size || fa.has(s.k) ? r.segs[s.k] : 0])) }))
      .map((r) => ({ ...r, total: SV.reduce((a, s) => a + r.segs[s.k], 0) })).filter((r) => r.total).sort((a, b) => b.total - a.total);
    const byName = Object.fromEntries(rows.map((r) => [r.name, r.hostid]));
    board('bdTopHosts', rows, { segs: SV, selected: d.top_hosts.find((h) => h.hostid === state.hostid)?.host || null, limit: 10, empty: 'Sem alertas no período',
      onName: (n) => setHost(state.hostid === byName[n] ? '' : byName[n]), onSeg: (n) => setHost(state.hostid === byName[n] ? '' : byName[n]),
      cols: [{ h: 'Ativos', fn: (r) => nz(r.active, '') }, { h: 'Sem ACK', fn: (r) => nz(r.unacked, 'warn') }] });
    // chamados por categoria (GLPI), empilhados por status
    const all = (d.tickets_list || []).filter(inPeriod), fs = lgSel.st;
    const shown = fs.size ? all.filter((t) => fs.has(statOf(t).k)) : all, tot = {};
    all.forEach((t) => { const k = statOf(t).k; tot[k] = (tot[k] || 0) + 1; });
    legend('lgCategory', STAT, tot, 'st');
    const crow = ticketRows(shown, (t) => t.category || 'Sem categoria');
    const lst = (n, s) => { const r = crow.find((x) => x.name === n); if (r) showTicketList(`Categoria: ${n}`, s ? `Status ${s}` : 'Todos os status', s ? r.arr.filter((t) => statOf(t).k === s) : r.arr); };
    board('bdCategory', crow, { segs: STAT, cols: tkCols, limit: 10, onName: (n) => lst(n), onSeg: lst, empty: 'Sem chamados' });
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
    const counts = {}; SHOWN.forEach((s) => (counts[s] = 0));
    all.forEach((p) => { counts[p.severity] = (counts[p.severity] || 0) + 1; });
    const noack = all.filter((p) => !p.acknowledged).length;
    $('#sevChips').innerHTML = `<button type="button" class="chip ${local.sev == null && !local.noack ? 'on' : ''}" data-sev="">Todos <b>${all.length}</b></button>` +
      SHOWN.map((s) => `<button type="button" class="chip ${local.sev === s ? 'on' : ''}" data-sev="${s}" ${counts[s] ? '' : 'disabled'}>
        <i style="background:${C.sev[s]}"></i>${SEV[s]} <b>${counts[s]}</b></button>`).join('') +
      `<button type="button" class="chip ${local.noack ? 'on' : ''}" data-noack="1">Sem ACK <b>${noack}</b></button>`;
    const rows = sortRows($('#tblActive'), all.filter((p) => (local.sev == null || p.severity === local.sev) && (!local.noack || !p.acknowledged)));
    $('#activeHint').textContent = `${rows.length} de ${all.length} · clique para abrir o alerta no Zabbix`;
    $('#tblActive tbody').innerHTML = rows.length ? rows.map((p) => `
      <tr class="clickable" data-url="${esc(p.url)}" title="Abrir este alerta no Zabbix">
      <td>${sevTag(p.severity)}</td><td>${esc(p.host)}</td><td class="wrap">${esc(p.name)}</td>
      <td class="num">${dur(now - p.clock)}</td><td>${p.acknowledged ? '<span class="pill">sim</span>' : '<span class="pill warn">não</span>'}</td></tr>`).join('')
      : '<tr><td colspan="5" class="empty">Nenhum alerta para este filtro</td></tr>';
  }

  function renderTickets() {
    const all = data.tickets_list, links = data.links;
    const views = {
      all: ['Todos', () => true],
      open: ['Em aberto', (t) => t.open],
      pending: ['Pendentes', (t) => t.pending],
      late: ['Atrasados', (t) => t.late],
      done: ['Solucionados', (t) => !t.open],
      miss: ['Fora do SLA', (t) => t.sla_ok === false],
    };
    $('#ticketChips').innerHTML = Object.entries(views).map(([k, [label, fn]]) =>
      `<button type="button" class="chip ${local.ticketView === k ? 'on' : ''}" data-view="${k}">${label} <b>${all.filter(fn).length}</b></button>`).join('');
    const rows = sortRows($('#tblTickets'), all.filter(views[local.ticketView][1]));
    $('#ticketsHint').textContent = `${rows.length} exibido(s) · ${data.kpi.tickets} no período · ${data.kpi.backlog} em aberto${state.client ? ` · ${state.client}` : ''}`;
    const prColor = { 6: C.critical, 5: C.critical, 4: C.serious, 3: C.warning, 2: C.sev[1], 1: C.muted };
    const slaCell = (t) => t.late ? '<span class="state critical">atrasado</span>'
      : t.open ? (t.pending ? '<span class="state warning">pendente</span>' : '<span class="state unknown">no prazo</span>')
      : t.sla_ok === false ? '<span class="state critical">estourado</span>' : '<span class="state good">no prazo</span>';
    $('#tblTickets tbody').innerHTML = rows.length ? rows.map((t) => `
      <tr class="clickable" data-ticket="${t.id}" title="Ver detalhes do chamado #${t.id}">
        <td class="num"><a href="${links.glpi}/front/ticket.form.php?id=${t.id}" target="_blank" rel="noopener" title="Abrir no GLPI">${t.id}</a></td>
        <td>${esc(t.client)}</td>
        <td class="wrap">${esc(t.name)}</td>
        <td><span class="pill">${esc(t.status)}</span></td>
        <td><span class="sev"><i style="background:${prColor[t.priority_id]}"></i>${esc(t.priority)}</span></td>
        <td class="num">${t.resolve_seconds != null ? dur(t.resolve_seconds) : '—'}</td>
        <td>${slaCell(t)}</td>
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
      ['Cliente', esc(t.client)], ['Entidade', esc(t.entity)], ['Categoria', esc(t.category || '—')], ['Prioridade', esc(t.priority)],
      ['Aberto em', esc(t.date)], ['Solucionado em', esc(t.solvedate || '—')],
      ['Prazo de solução', esc(t.due || t.sla_target)], ['Resultado', t.late ? 'atrasado' : t.sla_ok == null ? 'em aberto' : t.sla_ok ? 'no prazo' : 'estourado'],
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

  const getJson = async (qs) => {
    const r = await fetch(`api.php?${qs}`, { cache: 'no-store' });
    const j = await r.json();
    if (!r.ok || j.error) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
  };

  async function load() {
    writeUrl();
    const q = new URLSearchParams({ action: 'data', from: state.from, to: state.to });
    if (state.client) q.set('client', state.client);
    if (state.hostid) q.set('hostid', state.hostid);
    if (state.ticket) q.set('ticket', state.ticket);
    $('#reload').classList.add('spin'); document.body.classList.add('loading');
    $('#updated').textContent = 'Carregando os dados…';
    getJson('action=updates').then(renderUpdates).catch(() => {});
    try {
      const j = await getJson(q);
      data = j;
      render(j);
      $('#updated').textContent = `Atualizado às ${new Date(j.generated_at * 1000).toLocaleTimeString('pt-BR')}`;
    } catch (err) {
      $$('.source [data-led]').forEach((l) => (l.className = 'led err'));
      toast(`Não foi possível carregar os dados: ${err.message}`);
    } finally {
      $('#reload').classList.remove('spin'); document.body.classList.remove('loading');
      schedule();
    }
  }

  function renderUpdates(u) {
    const el = $('#updLine'); if (!el || !u) return;
    const one = (n, x) => !x || !x.current ? `<span class="na">${n}: sem verificação</span>`
      : x.patch ? `<span class="new" title="Versão atual ${esc(x.current)}">${n} ${esc(x.patch)} disponível</span>`
      : x.major ? `<span class="ok" title="Versão atual ${esc(x.current)}">${n} atualizado · nova série ${esc(x.major)}</span>`
      : `<span class="ok">${n} atualizado</span>`;
    el.innerHTML = one('Zabbix', u.zabbix) + one('GLPI', u.glpi);
    el.hidden = false;
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
    Object.assign(state, { from: f, to: t, client: $('#client').value, ticket: $('#ticket').value.trim() });
    syncForm(); load();
  });
  $('#client').addEventListener('change', () => { state.client = $('#client').value; state.hostid = ''; load(); });
  $('#clear').addEventListener('click', () => {
    closeDrawer();
    applyPreset('7'); state.hostid = ''; state.ticket = ''; state.client = '';
    Object.assign(local, { sev: null, noack: false, ticketView: 'all' });
    syncForm(); load();
  });
  $('#tblActive').addEventListener('click', (ev) => {
    const tr = ev.target.closest('tr[data-url]'); if (tr) window.open(tr.dataset.url, '_blank', 'noopener');
  });
  $('#tblTickets').addEventListener('click', (ev) => {
    if (ev.target.closest('a')) return;
    const tr = ev.target.closest('tr[data-ticket]'); if (!tr) return;
    state.ticket = tr.dataset.ticket; syncForm(); load();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  $('#sevChips').addEventListener('click', (ev) => {
    const c = ev.target.closest('.chip'); if (!c || c.disabled) return;
    if (c.dataset.noack) { local.noack = !local.noack; local.sev = null; }
    else { local.sev = c.dataset.sev === '' ? null : Number(c.dataset.sev); local.noack = false; }
    renderActive();
  });
  $('#ticketChips').addEventListener('click', (ev) => {
    const c = ev.target.closest('.chip'); if (!c) return;
    local.ticketView = c.dataset.view; renderTickets();
  });
  /* ---------------- gaveta lateral: lista do que está por trás de cada número */
  const IC = {
    sev5: '<path d="M8.3 2h7.4L21 7.3v9.4L15.7 22H8.3L3 16.7V7.3z"/><path d="M12 8v5M12 16.5v.01"/>',
    sev4: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17v.01"/>',
    sev2: '<circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v5M12 16v.01"/>',
    unacked: '<path d="M18.6 14.5c.9-1.2 1.4-2.7 1.4-4.5a8 8 0 0 0-12-6.9M6.3 6.3A8 8 0 0 0 4 10c0 5-2 6.5-2 6.5h14M10 20a2 2 0 0 0 4 0M2 2l20 20"/>',
    tickets: '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/>',
    pending: '<circle cx="12" cy="12" r="9.5"/><path d="M10 9v6M14 9v6"/>',
    overdue: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M5 3 2 6M22 6l-3-3"/>',
    time: '<circle cx="12" cy="12" r="9.5"/><path d="M12 7v5l3 2"/>',
  };
  const drawer = { items: [], render: null, tiles: [], flt: null };
  function openDrawer({ title, sub, icon, color, items, render, tiles = [] }) {
    $('#dT').textContent = title; $('#dS').innerHTML = sub;
    const ico = $('#dIco'); ico.style.setProperty('--c', color || 'var(--accent)');
    ico.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${IC[icon] || IC.time}</svg>`;
    Object.assign(drawer, { items, render, tiles, flt: null }); $('#dQ').value = '';
    fillDrawer();
    $('#drawer').classList.add('on'); $('#scrim').classList.add('on'); $('#drawer').setAttribute('aria-hidden', 'false');
    $('#dL').scrollTop = 0;
  }
  function closeDrawer() { $('#drawer').classList.remove('on'); $('#scrim').classList.remove('on'); $('#drawer').setAttribute('aria-hidden', 'true'); }
  function fillDrawer() {
    const q = $('#dQ').value.trim().toLowerCase(), LIM = 120;
    $('#dSum').innerHTML = drawer.tiles.map((t, i) => `<button type="button" class="st ${drawer.flt === i ? 'on' : ''}" data-t="${i}" style="--c:${t.color}">
      <span>${t.label}</span><b>${nf.format(drawer.items.filter(t.fn).length)}</b></button>`).join('');
    $('#dSum').hidden = !drawer.tiles.length;
    $('#dTools').hidden = drawer.items.length < 12;
    const f = drawer.flt != null ? drawer.tiles[drawer.flt].fn : () => true;
    const rows = drawer.items.filter((x) => f(x) && (!q || x._q.includes(q)));
    $('#dL').innerHTML = rows.length
      ? rows.slice(0, LIM).map(drawer.render).join('') + (rows.length > LIM ? `<div class="more">Mostrando ${LIM} de ${rows.length}. Use o filtro para refinar.</div>` : '')
      : '<div class="none">Nada para mostrar aqui.</div>';
  }
  const pct = (v) => Math.max(0, Math.min(100, Math.round(v * 100)));
  const alertItem = (p) => {
    const now = Date.now() / 1000, age = now - p.clock, col = C.sev[p.severity];
    return `<div class="it" style="--c:${col}" data-hostid="${esc(p.hostid)}">
      <div class="r1"><i class="dot"></i><span class="key">${esc(p.host)}</span><span class="nm">${esc(p.name)}</span><span class="r">${dur(age)}</span></div>
      <div class="sl">${SEV[p.severity]} · ${when(p.clock)} · <span class="${p.acknowledged ? 'g' : 'w'}">${p.acknowledged ? 'reconhecido' : 'sem ACK'}</span></div>
      <div class="bar"><i style="width:${pct(age / 86400)}%"></i></div>
      <div class="acts"><a class="ab" href="${esc(p.url)}" target="_blank" rel="noopener">Abrir alerta ↗</a>
        <button type="button" class="ab" data-act="host" data-hostid="${esc(p.hostid)}">Histórico do host</button></div>
      <div class="pn" hidden></div></div>`;
  };
  const ticketItem = (t, mode) => {
    const now = Date.now() / 1000, o = Date.parse(String(t.date).replace(' ', 'T')) / 1000, d = Date.parse(String(t.sla_target).replace(' ', 'T')) / 1000;
    const prog = t.open ? (d > o ? (now - o) / (d - o) : 0) : 1;
    const col = t.late ? C.critical : t.pending ? C.warning : t.open ? '#3fcf6f' : C.muted;
    const state = t.late ? '<span class="r2">prazo vencido</span>' : t.pending ? '<span class="w">pendente</span>' : t.open ? '<span class="g">no prazo</span>' : (t.sla_ok === false ? '<span class="r2">solucionado fora do prazo</span>' : '<span class="g">solucionado</span>');
    const extra = mode === 'mttd' ? ` · 1º atendimento em ${t.take_seconds ? dur(t.take_seconds) : '—'}` : mode === 'mttr' ? ` · solução em ${t.resolve_seconds != null ? dur(t.resolve_seconds) : '—'}` : '';
    const link = `${data.links.glpi}/front/ticket.form.php?id=${t.id}`;
    return `<div class="it" style="--c:${col}" data-tid="${t.id}">
      <div class="r1"><i class="dot"></i><span class="key">${t.id}</span><span class="nm">${esc(t.name)}</span><span class="r">${t.open ? dur(now - o) : (t.resolve_seconds != null ? dur(t.resolve_seconds) : '')}</span></div>
      <div class="sl">${state} · ${esc(t.client)} · ${esc(t.status)} · ${esc(t.priority)}${extra}</div>
      <div class="bar"><i style="width:${pct(prog)}%"></i></div>
      <div class="acts"><a class="ab" href="${link}" target="_blank" rel="noopener">Abrir chamado ↗</a>
        <button type="button" class="ab" data-act="tk" data-tid="${t.id}">Interações</button></div>
      <div class="pn" hidden></div></div>`;
  };
  const dateBr = (s) => { const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? esc(s) : d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }); };

  async function togglePanel(btn, kind) {
    const it = btn.closest('.it'), pn = it.querySelector('.pn');
    if (!pn.hidden && pn.dataset.kind === kind) { pn.hidden = true; btn.classList.remove('on'); return; }
    it.querySelectorAll('.ab.on').forEach((b) => b.classList.remove('on')); btn.classList.add('on');
    pn.dataset.kind = kind; pn.hidden = false; pn.innerHTML = '<div class="ld">Carregando…</div>';
    try {
      if (kind === 'host') {
        const r = await getJson(`action=hostlog&hostid=${btn.dataset.hostid}`);
        pn.innerHTML = `<div class="ph">Histórico de ${esc(r.host)} · últimos ${r.days} dias · ${r.events.length} alerta(s)</div>` +
          (r.events.length ? r.events.map((e) => `<a class="ev" href="${esc(e.url)}" target="_blank" rel="noopener" style="--c:${C.sev[e.severity]}">
            <i class="dot"></i><span class="en">${esc(e.name)}</span>
            <span class="em">${SEV[e.severity]} · ${when(e.clock)} · ${e.end ? `durou ${dur(e.end - e.clock)}` : '<b class="w">ainda ativo</b>'} · ${e.acknowledged ? 'reconhecido' : 'sem ACK'}</span></a>`).join('')
            : '<div class="none">Sem alertas nesse período.</div>') + `<a class="ab" href="${esc(r.url)}" target="_blank" rel="noopener">Ver todos no Zabbix ↗</a>`;
      } else {
        const r = await getJson(`action=ticketlog&id=${btn.dataset.tid}&from=${state.from}&to=${state.to}`);
        pn.innerHTML = `<div class="ph">${r.items.length} registro(s) no chamado</div>` + r.items.map((x) => `<div class="tl ${x.kind === 'solução' ? 'sol' : ''}">
          <div class="th"><b>${esc(x.kind)}</b>${x.private ? ' <em>privado</em>' : ''}<span>${esc(x.author || '—')} · ${dateBr(x.date)}</span></div>
          <div class="tx">${esc(x.text || '(sem texto)')}</div></div>`).join('');
      }
    } catch (err) {
      pn.innerHTML = `<div class="none">Não foi possível carregar: ${esc(err.message)}</div>`;
    }
  }
  $('#dL').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-act]'); if (b) togglePanel(b, b.dataset.act);
  });
  $('#dSum').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-t]'); if (!b) return;
    const i = Number(b.dataset.t); drawer.flt = drawer.flt === i ? null : i; fillDrawer();
  });

  function showTicketList(title, sub, arr) {
    const mkT = (a) => a.map((t) => ({ ...t, _q: `${t.id} ${t.client} ${t.name} ${t.status} ${t.tech}`.toLowerCase() }));
    const rank = (t) => (t.late ? 0 : t.pending ? 1 : t.open ? 2 : 3);
    const tiles = [{ label: 'No prazo', color: '#3fcf6f', fn: (t) => t.open && !t.late && !t.pending }, { label: 'Pendentes', color: C.warning, fn: (t) => t.pending },
      { label: 'Atrasados', color: C.critical, fn: (t) => t.late }, { label: 'Solucionados', color: C.muted, fn: (t) => !t.open }];
    openDrawer({ title, icon: 'tickets', color: 'var(--series-2)', items: mkT([...arr].sort((a, b) => rank(a) - rank(b) || String(a.date).localeCompare(String(b.date)))),
      render: (t) => ticketItem(t), tiles, sub: `${sub} · ${arr.length} chamado(s) · do mais crítico para o mais tranquilo` });
  }

  function showKpi(kind) {
    if (!data) return;
    const k = data.kpi, zbx = data.active || [], tk = data.tickets_list || [];
    const mkA = (arr) => arr.map((p) => ({ ...p, _q: `${p.host} ${p.name}`.toLowerCase() }));
    const mkT = (arr) => arr.map((t) => ({ ...t, _q: `${t.id} ${t.client} ${t.name} ${t.status}`.toLowerCase() }));
    const scope = state.client ? ` · ${esc(state.client)}` : '';
    const aTiles = [...SHOWN.map((s) => ({ label: SEV[s], color: C.sev[s], fn: (p) => p.severity === s })),
      { label: 'Sem ACK', color: 'var(--accent)', fn: (p) => !p.acknowledged }];
    const tTiles = [{ label: 'No prazo', color: '#3fcf6f', fn: (t) => t.open && !t.late && !t.pending },
      { label: 'Pendentes', color: C.warning, fn: (t) => t.pending },
      { label: 'Atrasados', color: C.critical, fn: (t) => t.late },
      { label: 'Solucionados', color: C.muted, fn: (t) => !t.open }];
    const bySev = (a, b) => b.severity - a.severity || a.clock - b.clock;
    if (/^sev\d$/.test(kind)) {
      const sv = Number(kind.slice(3)), arr = zbx.filter((p) => p.severity === sv).sort((a, b) => a.clock - b.clock);
      openDrawer({ title: `Alertas ${SEV[sv]}`, icon: kind, color: C.sev[sv], items: mkA(arr), render: alertItem, tiles: aTiles,
        sub: `Ativos agora, do mais antigo ao mais recente · ${nf.format(k.alerts_by_sev[sv] || 0)} no período${scope}` });
    } else if (kind === 'unacked') {
      const arr = zbx.filter((p) => !p.acknowledged).sort(bySev);
      openDrawer({ title: 'Alertas sem ACK', icon: kind, color: 'var(--accent)', items: mkA(arr), render: alertItem, tiles: aTiles,
        sub: `Do mais crítico para o mais tranquilo · ninguém reconheceu no Zabbix${scope}` });
    } else if (kind === 'tickets' || kind === 'pending' || kind === 'overdue') {
      const fn = { tickets: () => true, pending: (t) => t.pending, overdue: (t) => t.late }[kind];
      const rank = (t) => (t.late ? 0 : t.pending ? 1 : t.open ? 2 : 3);
      const arr = tk.filter(fn).sort((a, b) => rank(a) - rank(b) || String(a.date).localeCompare(String(b.date)));
      openDrawer({ title: { tickets: 'Chamados do período', pending: 'Chamados pendentes', overdue: 'Chamados atrasados' }[kind], icon: kind,
        color: { tickets: 'var(--series-2)', pending: C.warning, overdue: C.critical }[kind], items: mkT(arr), render: (t) => ticketItem(t), tiles: tTiles,
        sub: `Do mais crítico para o mais tranquilo · entidade NOC para baixo${scope}` });
    } else if (kind === 'mttd_glpi' || kind === 'mttr_glpi') {
      const m = kind === 'mttd_glpi' ? 'mttd' : 'mttr';
      const arr = tk.filter((t) => (m === 'mttd' ? t.take_seconds > 0 : t.resolve_seconds != null))
        .sort((a, b) => (m === 'mttd' ? b.take_seconds - a.take_seconds : b.resolve_seconds - a.resolve_seconds));
      openDrawer({ title: m === 'mttd' ? 'MTTD · GLPI' : 'MTTR · GLPI', icon: 'time', color: 'var(--series-2)', items: mkT(arr), render: (t) => ticketItem(t, m), tiles: tTiles,
        sub: `${m === 'mttd' ? 'Da abertura ao 1º atendimento' : 'Da abertura à solução'} · do mais lento ao mais rápido${scope}` });
    } else {
      const m = kind === 'mttd_zbx' ? 'mttd' : 'mttr';
      openDrawer({ title: m === 'mttd' ? 'MTTD · Zabbix' : 'MTTR · Zabbix', icon: 'time', color: C.sev[4], items: mkA([...zbx].sort((a, b) => a.clock - b.clock)), render: alertItem, tiles: aTiles,
        sub: `Alertas ativos, do mais antigo ao mais recente · a média é de todo o período${scope}` });
    }
  }
  $$('[data-kpi][data-go]').forEach((k) => k.addEventListener('click', () => showKpi(k.dataset.kpi)));
  $('#dX').addEventListener('click', closeDrawer);
  $('#scrim').addEventListener('click', closeDrawer);
  $('#dQ').addEventListener('input', fillDrawer);
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closeDrawer(); });
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
  syncForm();
  load();
})();
