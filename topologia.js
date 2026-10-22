/* DataCenter 360 — topologia animada da rede monitorada (lê api.php?action=topology) */
(() => {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const NS = 'http://www.w3.org/2000/svg';
  const W = 1600;
  const REFRESH = 30;
  const SEV = ['Não classificada', 'Informação', 'Atenção', 'Média', 'Alta', 'Desastre'];
  const SEV_COLOR = ['#7b8798', '#5598e7', '#fab219', '#ec835a', '#d03b3b', '#d55181'];
  const STATUS = { online: 'Online', degradado: 'Degradado', offline: 'Offline', desconhecido: 'Sem dados' };
  const ROLE = { internet: 'Internet', gateway: 'Gateway / roteador', switch: 'Switch núcleo', zabbix: 'Servidor Zabbix',
    proxy: 'Zabbix Proxy', server: 'Servidor', workstation: 'Estação de trabalho', external: 'Serviço externo' };

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const el = (tag, attrs = {}, parent) => {
    const e = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
    if (parent) parent.appendChild(e);
    return e;
  };
  const dur = (sec) => {
    sec = Math.max(0, Math.round(sec));
    const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
    return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
  };

  /* ---------------- ícones (desenhados em caixa 48×48 centrada em 0,0) */
  const ICONS = {
    internet: '<path d="M-14 8h28a9 9 0 0 0 0-18 13 13 0 0 0-25-3A9 9 0 0 0-14 8z"/><path d="M-6 14v4M2 14v4M10 14v4"/>',
    gateway: '<circle r="13"/><path d="M-8 0h16M4-4l4 4-4 4M0-8v16M-4 4l4 4 4-4M-4-4l4-4 4 4"/>',
    switch: '<rect x="-20" y="-9" width="40" height="18" rx="3"/><path d="M-14-3v6M-8-3v6M-2-3v6M4-3v6M10-3v6"/><circle class="fill" cx="15" cy="-4" r="1.6"/>',
    server: '<rect x="-12" y="-15" width="24" height="9" rx="2"/><rect x="-12" y="-4" width="24" height="9" rx="2"/><rect x="-12" y="7" width="24" height="9" rx="2"/><circle class="fill" cx="7" cy="-10.5" r="1.4"/><circle class="fill" cx="7" cy="0.5" r="1.4"/><circle class="fill" cx="7" cy="11.5" r="1.4"/><path d="M-8-10.5h8M-8 0.5h8M-8 11.5h8"/>',
    zabbix: '<rect x="-14" y="-14" width="28" height="28" rx="4"/><path d="M-7-7h14l-14 14h14"/>',
    proxy: '<rect x="-12" y="-13" width="24" height="9" rx="2"/><rect x="-12" y="4" width="24" height="9" rx="2"/><path d="M-18-1h36M12-5l6 4-6 4M-12 3l-6-4 6-4"/>',
    workstation: '<rect x="-15" y="-12" width="30" height="20" rx="2"/><path d="M-6 14h12M0 8v6"/>',
    external: '<circle r="13"/><path d="M-13 0h26M0-13c-6 7-6 19 0 26M0-13c6 7 6 19 0 26"/>',
  };

  /* raio de cada tipo de ativo (o switch é um retângulo de 260×72) */
  const RADIUS = { internet: 54, external: 40, gateway: 46, zabbix: 46, proxy: 46, server: 46, workstation: 46 };
  const SW_H = 36;

  /* logotipo de cada ativo: pelo papel, nome, grupos e templates */
  function logoFor(n) {
    const txt = `${n.name} ${(n.groups || []).join(' ')} ${(n.templates || []).join(' ')}`.toLowerCase();
    if (n.role === 'zabbix' || n.role === 'proxy') return 'zabbix';
    if (txt.includes('glpi')) return 'glpi';
    for (const k of ['grafana', 'prometheus', 'ubuntu', 'centos', 'google']) if (txt.includes(k)) return k;
    if (txt.includes('vault') || txt.includes('hashicorp')) return 'vault';
    if (txt.includes('macos') || txt.includes('imac')) return 'apple';
    if (n.role === 'server' && txt.includes('linux')) return 'linux';
    return null;
  }

  /* ---------------- estado */
  let topo = null, layout = null, hoverId = null, selectedId = null, nextAt = 0, timer = null;
  const view = { x: 0, y: 0, w: W, h: 900, base: null };
  const svg = $('#map'), vp = $('#viewport');

  /* ---------------- layout em camadas: internet → gateway → switch → LAN */
  function computeLayout(nodes) {
    const byRole = (r) => nodes.filter((n) => n.role === r);
    const order = { zabbix: 0, proxy: 1, server: 2, workstation: 3 };
    const statusOrder = { offline: 0, degradado: 1, desconhecido: 2, online: 3 };
    const lan = nodes.filter((n) => n.role in order)
      .sort((a, b) => order[a.role] - order[b.role] || statusOrder[a.status] - statusOrder[b.status] || a.name.localeCompare(b.name));
    const externals = byRole('external');
    const gateways = byRole('gateway');

    const pos = {};
    const cx = W / 2;
    // Internet e serviços externos
    pos.internet = { x: cx, y: 125 };
    externals.forEach((n, i) => {
      const side = i % 2 === 0 ? -1 : 1, k = Math.floor(i / 2);
      pos[n.id] = { x: cx + side * (320 + k * 210), y: 125 };
    });
    // Gateway (o primeiro; demais ao lado)
    gateways.forEach((n, i) => { pos[n.id] = { x: cx + i * 220, y: 330 }; });
    if (!gateways.length) pos.gateway = { x: cx, y: 330 };
    // Switch núcleo
    pos.switch = { x: cx, y: 510 };
    // LAN em fileiras de até 10 (uma fileira evita links cruzando por trás de outros ativos)
    const perRow = Math.min(10, Math.max(1, lan.length));
    const rows = Math.ceil(lan.length / perRow) || 1;
    const gap = Math.min(210, (W - 200) / Math.max(1, perRow - 1));
    lan.forEach((n, i) => {
      const row = Math.floor(i / perRow), inRow = Math.min(perRow, lan.length - row * perRow), col = i % perRow;
      const span = gap * (inRow - 1);
      pos[n.id] = { x: cx - span / 2 + col * gap, y: 750 + row * 250 };
    });
    const height = 750 + rows * 250 + 40;
    return { pos, lan, externals, gateways, height };
  }

  /* ---------------- desenho */
  function linkPath(a, b, kind) {
    if (kind === 'side') { // serviço externo ↔ nuvem (horizontal suave)
      const mx = (a.x + b.x) / 2;
      return `M${a.x},${a.y} C${mx},${a.y} ${mx},${b.y} ${b.x},${b.y}`;
    }
    const my = (a.y + b.y) / 2;
    return `M${a.x},${a.y} C${a.x},${my} ${b.x},${my} ${b.x},${b.y}`;
  }

  function drawLink(layer, id, from, to, status, opts = {}) {
    const g = el('g', { class: `link ${status}${opts.core ? ' core' : ''}`, 'data-id': id, 'data-ends': (opts.ends || []).join(' ') }, layer);
    const d = linkPath(from, to, opts.kind);
    const pid = `p-${id}`;
    el('path', { class: 'base', d, id: pid }, g);
    el('path', { class: 'flow', d }, g);
    // pacotes viajando nos dois sentidos; mais rápidos em links de baixa latência
    const speed = opts.latency != null ? Math.min(4, Math.max(1.6, 1.6 + opts.latency / 20)) : 2.6;
    [[0, '0;1'], [speed / 2, '1;0']].forEach(([begin, kp]) => {
      const c = el('circle', { class: 'packet', r: opts.core ? 3.6 : 3 }, g);
      const am = el('animateMotion', { dur: `${speed}s`, begin: `${begin}s`, repeatCount: 'indefinite', keyPoints: kp, keyTimes: '0;1', calcMode: 'linear' }, c);
      el('mpath', { href: `#${pid}` }, am);
    });
    // marcador de link rompido / rótulo de latência no meio do caminho
    requestAnimationFrame(() => {
      const p = g.querySelector('.base');
      const mid = p.getPointAtLength(p.getTotalLength() * (opts.core ? 0.72 : 0.5));
      if (status === 'offline') {
        el('text', { class: 'break', x: mid.x, y: mid.y }, g).textContent = '✕';
      } else if (opts.latency != null) {
        const t = el('g', { class: 'lat', transform: `translate(${mid.x},${mid.y})` }, g);
        const label = `${opts.latency < 10 ? opts.latency.toFixed(1) : Math.round(opts.latency)} ms`;
        el('rect', { x: -26, y: -9, width: 52, height: 18 }, t);
        el('text', {}, t).textContent = label;
      }
    });
    return g;
  }

  function drawLogo(parent, n) {
    const key = logoFor(n);
    const LOGOS = window.DC360_LOGOS || {};
    if (key === 'zabbix') {                       // marca Zabbix: Z branco no quadrado vermelho
      el('rect', { x: -21, y: -21, width: 42, height: 42, rx: 7, fill: '#D40000' }, parent);
      el('path', { d: 'M-10-10h20l-20 20h20', fill: 'none', stroke: '#fff', 'stroke-width': 5, 'stroke-linejoin': 'round' }, parent);
    } else if (key === 'glpi') {
      el('image', { href: 'logo-glpi.svg', x: -30, y: -13, width: 60, height: 26 }, parent);
    } else if (key && LOGOS[key]) {
      const s = 2.1;                               // caixa 24×24 → ~50 px
      el('path', { d: LOGOS[key].d, fill: LOGOS[key].color, transform: `scale(${s}) translate(-12,-12)` }, parent);
    } else {                                       // sem marca: ícone de linha do papel
      const icon = el('g', { class: 'icon', transform: n.role === 'switch' ? 'scale(2)' : 'scale(1.55)' }, parent);
      icon.innerHTML = ICONS[n.role] || ICONS.server;
      return icon;
    }
    return null;
  }

  function drawNode(layer, n, p) {
    const sw = n.role === 'switch';
    const r = RADIUS[n.role] || 46;
    const g = el('g', { class: `node ${n.status} role-${n.role}`, transform: `translate(${p.x},${p.y})`, 'data-id': n.id, tabindex: 0, role: 'button',
      'aria-label': `${n.name}, ${ROLE[n.role]}, ${STATUS[n.status]}` }, layer);
    const live = n.status !== 'desconhecido';
    const t = n.status === 'offline' ? 1.2 : n.status === 'degradado' ? 1.8 : 2.8;

    // halo pulsante
    const halo = el('circle', { class: 'halo', r: sw ? 0 : r, opacity: 0 }, g);
    if (live && !sw) {
      el('animate', { attributeName: 'r', values: `${r};${r + 20}`, dur: `${t}s`, repeatCount: 'indefinite' }, halo);
      el('animate', { attributeName: 'opacity', values: '0.7;0', dur: `${t}s`, repeatCount: 'indefinite' }, halo);
    }
    // placa e anel em órbita (gira mais rápido quanto pior o estado)
    if (sw) el('rect', { class: 'ring', x: -130, y: -SW_H, width: 260, height: SW_H * 2, rx: 16 }, g);
    else {
      el('circle', { class: 'ring', r }, g);
      const orbit = el('circle', { class: 'orbit', r: r + 7, 'stroke-dasharray': `${Math.round(r * 0.9)} ${Math.round(r * 0.5)}` }, g);
      if (live) el('animateTransform', { attributeName: 'transform', type: 'rotate', from: '0', to: '360', dur: `${t * 3.2}s`, repeatCount: 'indefinite' }, orbit);
    }
    // logotipo "respirando"; offline fica apagado e parado
    const logo = el('g', { class: 'logo' }, g);
    const icon = drawLogo(logo, n);
    if (n.status === 'online' || n.status === 'degradado') {
      el('animateTransform', { attributeName: 'transform', type: 'scale', values: '1;1.08;1', dur: n.status === 'online' ? '3.2s' : '1.6s', repeatCount: 'indefinite' }, logo);
    }
    if (icon && n.role === 'gateway' && live) {  // setas do roteador girando
      el('animateTransform', { attributeName: 'transform', type: 'rotate', from: '0', to: '360', dur: '12s', repeatCount: 'indefinite', additive: 'sum' }, icon);
    }

    const ty = sw ? SW_H + 22 : r + 24;
    const max = sw ? 40 : 22;
    el('text', { class: 'name', y: ty }, g).textContent = n.name.length > max ? n.name.slice(0, max - 1) + '…' : n.name;
    if (n.ip) el('text', { class: 'ip', y: ty + 17 }, g).textContent = n.ip;
    el('text', { class: 'st', y: ty + (n.ip ? 33 : 17) }, g).textContent = STATUS[n.status];
    if (n.problem_count) {
      const b = el('g', { class: 'badge', transform: `translate(${sw ? 124 : r * 0.74},${-(sw ? 30 : r * 0.74)})` }, g);
      el('circle', { r: 13, fill: SEV_COLOR[n.max_severity ?? 0] }, b);
      el('text', {}, b).textContent = n.problem_count > 9 ? '9+' : n.problem_count;
      el('title', {}, b).textContent = `${n.problem_count} problema(s) · pior: ${SEV[n.max_severity]}`;
    }
    return g;
  }

  function render() {
    const nodes = topo.nodes;
    layout = computeLayout(nodes);
    const { pos, lan, externals, gateways } = layout;
    const zones = $('#zones'), links = $('#linksLayer'), nodesL = $('#nodesLayer');
    zones.innerHTML = ''; links.innerHTML = ''; nodesL.innerHTML = '';

    // zonas
    const zone = (x, y, w, h, label) => {
      const g = el('g', { class: 'zone' }, zones);
      el('rect', { x, y, width: w, height: h }, g);
      el('text', { x: x + 18, y: y + 26 }, g).textContent = label;
    };
    const extSpan = externals.length ? Math.max(...externals.map((n) => Math.abs(pos[n.id].x - W / 2))) + 130 : 200;
    zone(W / 2 - extSpan, 30, extSpan * 2, 215, 'Internet · serviços externos');
    zone(W / 2 - 320, 265, 640, 340, 'Borda · roteamento');
    const lanXs = lan.map((n) => pos[n.id].x);
    const lanL = Math.min(...lanXs, W / 2 - 200) - 130, lanR = Math.max(...lanXs, W / 2 + 200) + 130;
    zone(lanL, 630, lanR - lanL, layout.height - 640, `LAN ${topo.lan}`);

    // nós sintéticos: internet e switch
    const anyExtUp = externals.some((n) => n.status === 'online');
    const gw = gateways[0];
    const gwPos = gw ? pos[gw.id] : pos.gateway;
    const gwStatus = gw ? gw.status : 'desconhecido';
    const internet = { id: 'internet', name: 'Internet', role: 'internet', status: gwStatus === 'online' && (anyExtUp || !externals.length) ? 'online' : gwStatus === 'offline' ? 'offline' : 'degradado', ip: '', problem_count: 0 };
    const sw = { id: 'switch', name: `Switch núcleo · ${topo.lan}`, role: 'switch', status: lan.some((n) => n.status === 'online') ? 'online' : 'desconhecido', ip: '', problem_count: 0 };

    // links: serviços externos ↔ internet ↔ gateway ↔ switch ↔ LAN
    externals.forEach((n) => {
      const p = pos[n.id], side = p.x < W / 2 ? 1 : -1;
      drawLink(links, `l-${n.id}`, { x: p.x + side * RADIUS.external, y: p.y }, { x: pos.internet.x - side * RADIUS.internet, y: pos.internet.y }, n.status, { kind: 'side', ends: [n.id, 'internet'] });
    });
    drawLink(links, 'l-wan', { x: pos.internet.x, y: pos.internet.y + RADIUS.internet }, { x: gwPos.x, y: gwPos.y - RADIUS.gateway }, internet.status, { core: true, ends: ['internet', gw ? gw.id : 'gateway'] });
    drawLink(links, 'l-core', { x: gwPos.x, y: gwPos.y + RADIUS.gateway }, { x: pos.switch.x, y: pos.switch.y - SW_H }, gwStatus, { core: true, latency: gw?.latency_ms, ends: [gw ? gw.id : 'gateway', 'switch'] });
    lan.forEach((n, i) => {
      const p = pos[n.id];
      const port = pos.switch.x - 110 + (220 * (i + 0.5)) / lan.length;
      drawLink(links, `l-${n.id}`, { x: port, y: pos.switch.y + SW_H }, { x: p.x, y: p.y - RADIUS[n.role] }, n.status, { latency: n.latency_ms, ends: ['switch', n.id] });
    });

    // nós
    drawNode(nodesL, internet, pos.internet);
    externals.forEach((n) => drawNode(nodesL, n, pos[n.id]));
    if (gw) gateways.forEach((n) => drawNode(nodesL, n, pos[n.id]));
    else drawNode(nodesL, { id: 'gateway', name: 'Gateway', role: 'gateway', status: 'desconhecido', ip: '', problem_count: 0 }, pos.gateway);
    drawNode(nodesL, sw, pos.switch);
    lan.forEach((n) => drawNode(nodesL, n, pos[n.id]));

    renderMonitor();
    renderSummary();
    if (!view.base) fit();
    else applyView();
    if (selectedId) openDrawer(selectedId);
    applyFind();
  }

  /* fluxo de coleta: do servidor Zabbix (ou proxy) até cada ativo monitorado */
  function renderMonitor() {
    const layer = $('#monitorLayer');
    layer.innerHTML = '';
    if (!$('#monitorFlow').checked || !layout) return;
    const z = topo.nodes.find((n) => n.role === 'zabbix');
    if (!z) return;
    const zp = layout.pos[z.id];
    topo.nodes.forEach((n, i) => {
      if (n.id === z.id || !layout.pos[n.id]) return;
      const src = n.proxyid ? topo.nodes.find((x) => x.role === 'proxy') : z;
      const sp = layout.pos[src?.id] || zp;
      const p = layout.pos[n.id];
      if (src && src.id === n.id) return;
      const g = el('g', { class: 'mon' }, layer);
      const lift = Math.max(120, Math.abs(p.x - sp.x) * 0.35);
      const rr = RADIUS[n.role] || 46;
      const d = `M${sp.x},${sp.y - 46} C${sp.x},${sp.y - lift} ${p.x},${p.y + (p.y < sp.y ? lift : -lift)} ${p.x},${p.y + (p.y < sp.y ? rr : -rr)}`;
      el('path', { d, id: `m-${n.id}` }, g);
      const c = el('circle', { r: 2.6 }, g);
      const am = el('animateMotion', { dur: '3.2s', begin: `${(i % 6) * 0.5}s`, repeatCount: 'indefinite' }, c);
      el('mpath', { href: `#m-${n.id}` }, am);
    });
  }

  function renderSummary() {
    const real = topo.nodes;
    const c = { online: 0, degradado: 0, offline: 0, desconhecido: 0 };
    real.forEach((n) => c[n.status]++);
    const lats = real.map((n) => n.latency_ms).filter((v) => v != null && v > 0);
    const avg = lats.length ? lats.reduce((a, b) => a + b, 0) / lats.length : null;
    const chip = (color, label, v) => `<span class="chip"><i style="background:${color}"></i>${label} <b>${v}</b></span>`;
    $('#summary').innerHTML =
      `<span class="chip">Ativos <b>${real.length}</b></span>` +
      chip('var(--good)', 'Online', c.online) + chip('var(--warning)', 'Degradados', c.degradado) +
      chip('var(--critical)', 'Offline', c.offline) + chip('var(--muted)', 'Sem dados', c.desconhecido) +
      (avg != null ? `<span class="chip">Latência média <b>${avg.toFixed(1)} ms</b></span>` : '');
  }

  /* ---------------- foco no caminho até a internet */
  function pathTo(id) {
    const n = topo.nodes.find((x) => x.id === id);
    const set = new Set([id]);
    const gw = layout.gateways[0]?.id || 'gateway';
    if (!n) { // nó sintético
      if (id === 'switch') ['switch', gw, 'internet'].forEach((x) => set.add(x));
      if (id === 'internet') set.add('internet');
      return set;
    }
    if (n.role === 'external') set.add('internet');
    else if (n.role === 'gateway') ['internet'].forEach((x) => set.add(x));
    else ['switch', gw, 'internet'].forEach((x) => set.add(x));
    return set;
  }
  function highlight(id) {
    hoverId = id;
    svg.classList.toggle('focus', !!id);
    document.querySelectorAll('.node, .link').forEach((e) => e.classList.remove('hl'));
    if (!id) return;
    const set = pathTo(id);
    document.querySelectorAll('.node').forEach((e) => { if (set.has(e.dataset.id)) e.classList.add('hl'); });
    document.querySelectorAll('.link').forEach((e) => {
      const ends = e.dataset.ends.split(' ');
      if (ends.every((x) => set.has(x))) e.classList.add('hl');
    });
  }

  /* ---------------- painel de detalhes */
  function openDrawer(id) {
    selectedId = id;
    document.querySelectorAll('.node').forEach((e) => e.classList.toggle('sel', e.dataset.id === id));
    const n = topo.nodes.find((x) => x.id === id);
    const body = $('#drawerBody');
    if (!n) {
      const info = id === 'internet'
        ? 'Nó lógico que representa a saída para a internet. Fica online quando o gateway responde e ao menos um serviço externo está saudável.'
        : `Switch lógico da LAN ${esc(topo.lan)}: agrega os ativos da rede local. O Zabbix não coleta dados dele diretamente.`;
      body.innerHTML = `<h2>${id === 'internet' ? 'Internet' : 'Switch núcleo'}</h2><p class="hint">${info}</p>`;
      $('#drawer').hidden = false;
      return;
    }
    const now = Date.now() / 1000;
    const cells = [
      ['Função', ROLE[n.role]], ['Status', STATUS[n.status]],
      ['Latência ICMP', n.latency_ms != null ? `${n.latency_ms} ms` : '—'], ['Perda de pacotes', n.loss != null ? `${n.loss}%` : '—'],
      ['Monitorado por', n.via_proxy ? `proxy ${esc(n.via_proxy)}` : 'Zabbix server'], ['Problemas', n.problem_count],
    ];
    body.innerHTML = `
      <h2>${esc(n.name)}</h2>
      <div class="sub">${esc(n.ip || n.host)}</div>
      <span class="state ${n.status === 'online' ? 'good' : n.status === 'offline' ? 'critical' : n.status === 'degradado' ? 'warning' : 'unknown'}">${STATUS[n.status]}</span>
      <dl>${cells.map(([a, b]) => `<div><dt>${a}</dt><dd>${b}</dd></div>`).join('')}</dl>
      <h3>Problemas ativos</h3>
      ${n.problems.length ? `<ul class="probs">${n.problems.map((p) => `<li><span class="sev"><i style="background:${SEV_COLOR[p.severity]}"></i>${SEV[p.severity]}</span> · há ${dur(now - p.clock)}<br>${esc(p.name)}</li>`).join('')}</ul>` : '<p class="hint">Nenhum problema aberto.</p>'}
      <h3>Grupos</h3><div class="tags">${n.groups.map((g) => `<span class="pill">${esc(g)}</span>`).join('')}</div>
      <h3>Templates</h3><div class="tags">${n.templates.map((t) => `<span class="pill">${esc(t)}</span>`).join('') || '<span class="hint">—</span>'}</div>
      <div class="actions">
        <a href="index.html?hostid=${n.id}">Ver no painel</a>
        <a href="${topo.links.zabbix}/zabbix.php?action=problem.view&hostids%5B%5D=${n.id}" target="_blank" rel="noopener">Problemas no Zabbix ↗</a>
        <a href="${topo.links.zabbix}/zabbix.php?action=latest.view&hostids%5B%5D=${n.id}" target="_blank" rel="noopener">Últimos dados ↗</a>
      </div>`;
    $('#drawer').hidden = false;
  }
  function closeDrawer() {
    selectedId = null;
    $('#drawer').hidden = true;
    document.querySelectorAll('.node.sel').forEach((e) => e.classList.remove('sel'));
  }

  /* ---------------- zoom e arraste (viewBox) */
  function applyView() { svg.setAttribute('viewBox', `${view.x} ${view.y} ${view.w} ${view.h}`); }
  function fit() {
    const h = layout.height + 190; // espaço livre embaixo para a legenda não cobrir a LAN
    const r = svg.clientWidth / Math.max(1, svg.clientHeight);
    let w = W, hh = h;
    if (w / hh > r) hh = w / r; else w = hh * r;
    Object.assign(view, { w, h: hh, x: (W - w) / 2, y: Math.min(0, (h - hh) / 2) });
    view.base = { ...view };
    applyView();
  }
  function zoom(f, cx = view.x + view.w / 2, cy = view.y + view.h / 2) {
    const nw = Math.min(view.base.w * 2.5, Math.max(view.base.w / 4, view.w * f));
    const k = nw / view.w;
    view.x = cx - (cx - view.x) * k; view.y = cy - (cy - view.y) * k;
    view.w = nw; view.h = view.h * k;
    applyView();
  }
  const toSvg = (ev) => ({ x: view.x + (ev.offsetX / svg.clientWidth) * view.w, y: view.y + (ev.offsetY / svg.clientHeight) * view.h });
  svg.addEventListener('wheel', (ev) => { ev.preventDefault(); const p = toSvg(ev); zoom(ev.deltaY > 0 ? 1.12 : 1 / 1.12, p.x, p.y); }, { passive: false });
  let drag = null;
  svg.addEventListener('pointerdown', (ev) => {
    if (ev.target.closest('.node')) return;
    drag = { x: ev.clientX, y: ev.clientY, vx: view.x, vy: view.y, moved: false };
    svg.setPointerCapture(ev.pointerId); svg.classList.add('dragging');
  });
  svg.addEventListener('pointermove', (ev) => {
    if (!drag) return;
    const dx = ev.clientX - drag.x, dy = ev.clientY - drag.y;
    if (Math.abs(dx) + Math.abs(dy) > 3) drag.moved = true;
    view.x = drag.vx - dx * (view.w / svg.clientWidth); view.y = drag.vy - dy * (view.h / svg.clientHeight);
    applyView();
  });
  svg.addEventListener('pointerup', (ev) => {
    if (drag && !drag.moved && !ev.target.closest('.node')) closeDrawer();
    drag = null; svg.classList.remove('dragging');
  });
  $('#zoomIn').addEventListener('click', () => zoom(1 / 1.25));
  $('#zoomOut').addEventListener('click', () => zoom(1.25));
  $('#zoomFit').addEventListener('click', fit);
  window.addEventListener('resize', () => { if (layout) fit(); });

  /* ---------------- interação com os nós */
  $('#nodesLayer').addEventListener('pointerover', (ev) => { const n = ev.target.closest('.node'); if (n && n.dataset.id !== hoverId) highlight(n.dataset.id); });
  $('#nodesLayer').addEventListener('pointerout', (ev) => { if (!ev.relatedTarget || !ev.relatedTarget.closest?.('.node')) highlight(null); });
  $('#nodesLayer').addEventListener('click', (ev) => { const n = ev.target.closest('.node'); if (n) openDrawer(n.dataset.id); });
  $('#nodesLayer').addEventListener('keydown', (ev) => {
    const n = ev.target.closest('.node');
    if (n && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); openDrawer(n.dataset.id); }
  });
  $('#closeDrawer').addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closeDrawer(); });

  /* ---------------- busca */
  function applyFind() {
    const q = $('#find').value.trim().toLowerCase();
    document.querySelectorAll('.node').forEach((e) => e.classList.remove('found'));
    if (!q || !topo) return;
    const hit = topo.nodes.find((n) => n.name.toLowerCase().includes(q) || (n.ip || '').includes(q));
    if (!hit) return;
    const g = document.querySelector(`.node[data-id="${hit.id}"]`);
    g?.classList.add('found');
    return hit;
  }
  $('#find').addEventListener('input', applyFind);
  $('#find').addEventListener('keydown', (ev) => {
    if (ev.key !== 'Enter') return;
    const hit = applyFind();
    if (!hit) return;
    const p = layout.pos[hit.id];
    view.w = view.base.w / 1.8; view.h = view.base.h / 1.8;
    view.x = p.x - view.w / 2; view.y = p.y - view.h / 2;
    applyView(); openDrawer(hit.id);
  });

  $('#monitorFlow').addEventListener('change', () => {
    renderMonitor();
    try { localStorage.setItem('dc360.monitorFlow', $('#monitorFlow').checked ? '1' : '0'); } catch { /* sem storage */ }
  });
  $('#showLatency').addEventListener('change', () => svg.classList.toggle('no-latency', !$('#showLatency').checked));

  /* ---------------- dados */
  function toast(msg) {
    const t = $('#toast'); t.textContent = msg; t.hidden = false;
    clearTimeout(toast.h); toast.h = setTimeout(() => (t.hidden = true), 8000);
  }
  async function load() {
    $('#reload').classList.add('spin');
    try {
      const r = await fetch('api.php?action=topology', { cache: 'no-store' });
      const j = await r.json();
      if (!r.ok || j.error) throw new Error(j.error || `HTTP ${r.status}`);
      topo = j;
      render();
    } catch (err) {
      toast(`Não foi possível carregar a topologia: ${err.message}`);
    } finally {
      $('#reload').classList.remove('spin');
      clearTimeout(timer);
      nextAt = Date.now() + REFRESH * 1000;
      timer = setTimeout(load, REFRESH * 1000);
    }
  }
  $('#reload').addEventListener('click', load);
  $('#fullscreen').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.();
  });
  setInterval(() => {
    $('#clock').textContent = new Date().toLocaleTimeString('pt-BR');
    $('#countdown').style.width = nextAt ? `${100 - Math.max(0, (nextAt - Date.now()) / (REFRESH * 10))}%` : '0';
  }, 250);

  try { $('#monitorFlow').checked = localStorage.getItem('dc360.monitorFlow') === '1'; } catch { /* sem storage */ }
  load();
})();
