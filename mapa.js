/* NOC 360 — mapa de rede: camadas, ícones, conexões animadas, pré-visualização ao passar o mouse e ficha do ativo ao clicar.
   Lê api.php?action=mapa (ativos, links, APs) e api.php?action=hostficha (ficha). Sem bibliotecas. */
(() => {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const nf = (v, d = 0) => new Intl.NumberFormat('pt-BR', { maximumFractionDigits: d }).format(v);
  const SEV = ['Não classificada', 'Informação', 'Atenção', 'Média', 'Alta', 'Desastre'];
  const sevColor = (s) => css(`--sev-${s}`) || '#fab219';
  const fmtDur = (s) => {
    s = Math.max(0, Math.round(s));
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : m ? `${m}m` : `${s}s`;
  };
  const ago = (ts) => fmtDur(Date.now() / 1000 - ts);
  const when = (ts) => new Date(ts * 1000).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const fmtBps = (v) => v == null ? '–' : v >= 1e9 ? nf(v / 1e9, v % 1e9 ? 1 : 0) + ' Gbps' : v >= 1e6 ? nf(v / 1e6, v >= 1e8 ? 0 : 1) + ' Mbps' : v >= 1e3 ? nf(v / 1e3, 0) + ' Kbps' : nf(v, 0) + ' bps';
  const getJson = async (qs) => {
    const r = await fetch(`api.php?${qs}`, { cache: 'no-store' }), j = await r.json();
    if (!r.ok || j.error) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
  };

  /* ---------- fabricantes e sistemas (selo colorido; sem imagens externas) */
  const VEND = { fortinet: ['FORTINET', '#EE3124'], huawei: ['HUAWEI', '#E60012'], dell: ['DELL', '#2E9BD6'], vmware: ['VMWARE', '#78BE20'], cisco: ['CISCO', '#1BA0D7'],
    hp: ['HPE', '#01A982'], ubiquiti: ['UBIQUITI', '#0559C9'], mikrotik: ['MIKROTIK', '#E4002B'], windows: ['WINDOWS', '#4aa3e8'], linux: ['LINUX', '#E9B000'] };
  const vendorOf = (n) => {
    const t = `${n.vendor} ${n.model} ${n.hw} ${n.os} ${n.name}`.toLowerCase();
    return /forti/.test(t) ? 'fortinet' : /huawei/.test(t) ? 'huawei' : /dell|idrac|poweredge/.test(t) ? 'dell' : /vmware|esxi/.test(t) ? 'vmware' : /cisco/.test(t) ? 'cisco'
      : /\bhp\b|hpe|proliant|ilo/.test(t) ? 'hp' : /ubiquiti|unifi/.test(t) ? 'ubiquiti' : /mikrotik/.test(t) ? 'mikrotik' : /windows/.test(t) ? 'windows' : /linux|ubuntu|debian|centos|red ?hat/.test(t) ? 'linux' : '';
  };
  const badge = (v) => v && VEND[v] ? `<span class="mp-vd" style="--v:${VEND[v][1]}">${VEND[v][0]}</span>` : '';

  /* ---------- ícones 56×44 (gradientes em #mpDefs) */
  function mapIcon(kind, c) {
    const rack = (y, h) => `<rect x="4" y="${y}" width="48" height="${h}" rx="4" fill="url(#mpMetal)" stroke="#5aa3a6" stroke-width=".8"/><rect x="4" y="${y}" width="48" height="2.2" rx="1" fill="#fff" opacity=".12"/>`;
    const led = (x, y, col = '#5fe3b8') => `<circle cx="${x}" cy="${y}" r="1.5" fill="${col}"/>`;
    const ports = (x, y, n) => Array.from({ length: n }, (_, i) => `<rect x="${x + i * 4.4}" y="${y}" width="3.4" height="3" rx=".5" fill="#07142b" stroke="#6fb3b6" stroke-width=".4"/>`).join('');
    const K = {
      fw: rack(10, 24) + Array.from({ length: 3 }, (_, r) => Array.from({ length: 4 }, (_, i) => `<rect x="${8 + i * 7 + (r % 2) * 3.5}" y="${14 + r * 5.6}" width="6" height="4.4" rx=".6" fill="#c0563f" opacity="${.55 + r * .12}"/>`).join('')).join('')
        + `<path d="M42 12l8 3v6c0 5-3.4 8-8 10-4.6-2-8-5-8-10v-6z" fill="${c}" stroke="#fff" stroke-width=".8"/><path d="M38.5 20.5l2.6 2.6 4.6-5" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>`,
      sw: rack(14, 16) + ports(8, 17.5, 8) + ports(8, 23.5, 8) + `<rect x="45" y="16.5" width="4" height="11" rx="1" fill="${c}"/>` + led(44, 18) + led(44, 22) + led(44, 26, '#f5c242'),
      server: rack(6, 32) + Array.from({ length: 4 }, (_, i) => `<rect x="8" y="${10 + i * 6.6}" width="26" height="5" rx="1" fill="#0f4f53" stroke="#6fb3b6" stroke-width=".4"/>${led(30.5, 12.5 + i * 6.6)}`).join('') + `<rect x="37" y="9" width="12" height="26" rx="2" fill="${c}" opacity=".9"/><path d="M39 13h8M39 16h8M39 19h8" stroke="#fff" stroke-width=".6" opacity=".6"/>`,
      hyper: rack(16, 22) + Array.from({ length: 4 }, (_, i) => led(10 + i * 4, 33)).join('') + `<g transform="translate(14 2)"><path d="M0 8l7-4 7 4-7 4z" fill="${c}"/><path d="M0 8v8l7 4v-8z" fill="${c}" opacity=".75"/><path d="M14 8v8l-7 4v-8z" fill="${c}" opacity=".5"/></g><g transform="translate(28 4)"><path d="M0 8l6-3.4 6 3.4-6 3.4z" fill="${c}" opacity=".9"/><path d="M0 8v7l6 3.4v-7z" fill="${c}" opacity=".65"/><path d="M12 8v7l-6 3.4v-7z" fill="${c}" opacity=".45"/></g>`,
      vm: `<rect x="6" y="6" width="44" height="30" rx="3.5" fill="url(#mpMetal)" stroke="#5aa3a6" stroke-width=".8"/><rect x="6" y="6" width="44" height="6" rx="3" fill="${c}"/>${led(10, 9, '#fff')}${led(14.5, 9, '#ffffffaa')}<rect x="10" y="16" width="20" height="2.6" rx="1.3" fill="#6fb3b6"/><rect x="10" y="21" width="28" height="2.6" rx="1.3" fill="#6fb3b6"/><rect x="10" y="26" width="16" height="2.6" rx="1.3" fill="#6fb3b6"/>`,
      ap: `<ellipse cx="28" cy="33" rx="20" ry="6.5" fill="#0a3a3d"/><ellipse cx="28" cy="30" rx="20" ry="6.5" fill="url(#mpMetal)" stroke="#6fb3b6" stroke-width=".8"/>${led(28, 30)}<path d="M17 18q11-10 22 0M21 21.5q7-6 14 0" fill="none" stroke="${c}" stroke-width="2.4" stroke-linecap="round"/><circle cx="28" cy="24.5" r="2" fill="${c}"/>`,
      web: `<circle cx="28" cy="22" r="15" fill="url(#mpGlobe)" stroke="${c}" stroke-width="1.4"/><ellipse cx="28" cy="22" rx="6.5" ry="15" fill="none" stroke="#e6fbfc" stroke-width="1" opacity=".7"/><path d="M13 22h30M15.5 14h25M15.5 30h25" stroke="#e6fbfc" stroke-width=".9" opacity=".7"/>`,
      link: `<circle cx="22" cy="22" r="14" fill="url(#mpGlobe)" stroke="${c}" stroke-width="1.4"/><ellipse cx="22" cy="22" rx="6" ry="14" fill="none" stroke="#e6fbfc" stroke-width="1" opacity=".7"/><path d="M8 22h28" stroke="#e6fbfc" stroke-width=".9" opacity=".7"/><path d="M41 14v16M41 14l-3 3.5M41 14l3 3.5M48 30V14M48 30l-3-3.5M48 30l3-3.5" stroke="${c}" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`,
      cloud: `<path d="M14 36h28a9 9 0 0 0 1-18 12 12 0 0 0-23-3 9 9 0 0 0-6 21z" fill="url(#mpGlobe)" stroke="${c}" stroke-width="1.4"/>`,
      sensor: `<rect x="23" y="4" width="10" height="26" rx="5" fill="url(#mpMetal)" stroke="#6fb3b6"/><circle cx="28" cy="33" r="7" fill="#e2675a"/><rect x="26.4" y="12" width="3.2" height="20" rx="1.6" fill="#e2675a"/>` + [10, 15, 20].map((y) => `<path d="M35 ${y}h5" stroke="#6fb3b6"/>`).join(''),
      printer: `<rect x="16" y="4" width="24" height="12" rx="1" fill="#e6f1f2"/><rect x="6" y="14" width="44" height="18" rx="4" fill="url(#mpMetal)" stroke="#6fb3b6"/><rect x="16" y="28" width="24" height="12" rx="1" fill="#e6f1f2"/><rect x="40" y="18" width="7" height="3" rx="1" fill="${c}"/>`,
      dvr: `<path d="M6 14h30l9 6v4l-9 6H6z" fill="url(#mpMetal)" stroke="#6fb3b6"/><circle cx="38" cy="22" r="5" fill="#07142b" stroke="${c}" stroke-width="2"/><rect x="16" y="30" width="4" height="7" fill="#5aa3a6"/><rect x="10" y="36" width="16" height="3" rx="1" fill="#5aa3a6"/>`,
    };
    return `<svg viewBox="0 0 56 44" class="mp-ic" aria-hidden="true">${K[kind] || K.server}</svg>`;
  }
  const DEFS = `<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
    <linearGradient id="mpMetal" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2f8a8e"/><stop offset="1" stop-color="#0a4a4e"/></linearGradient>
    <radialGradient id="mpGlobe" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="#35c2c8"/><stop offset="1" stop-color="#06383b"/></radialGradient></defs></svg>`;

  const kindOf = (n) => n.tier === 'firewall' ? 'fw' : n.tier === 'switch' ? 'sw' : n.tier === 'vm' ? 'vm' : n.tier === 'web' ? 'web' : n.tier === 'fisico' ? 'hyper'
    : /dvr|nvr|c[âa]mera/i.test(n.name) ? 'dvr' : /impressora|printer/i.test(n.name) ? 'printer' : /sensor|temperatura/i.test(n.name) ? 'sensor' : 'server';
  const mpState = (n) => n.state === 'down' ? 'down' : n.state === 'maintenance' ? 'mt' : n.problems ? (n.worst >= 4 ? 'crit' : 'warn') : 'ok';
  const short = (nm) => String(nm).replace(/^[A-Za-z]{2,4}\s*\|\s*/, '').replace(/\s+/g, ' ').trim();

  let D = null, BY = {}, last = null;

  function nodeHtml(n, sub, attrs, icon, col, extra) {
    return `<div class="mp-n ${mpState(n)}" ${attrs} title="${esc(n.name)}">${n.problems ? `<span class="mp-pb" style="--sv:${sevColor(n.worst)}">${n.problems}</span>` : ''}
      ${mapIcon(icon, col)}<div class="mp-b"><div class="mp-t">${extra || ''}<span>${esc(n.label || short(n.name))}</span></div><div class="mp-s"><i class="mp-dot"></i>${sub}</div></div></div>`;
  }

  function mapHtml(d, by) {
    const dev = (n) => {
      const v = vendorOf(n), [, vc] = VEND[v] || ['', css('--accent-text') || '#35c2c8'];
      const sub = n.state === 'down' ? `fora há ${ago(n.open_since || Date.now() / 1000)}`
        : n.tier === 'web' ? [n.avail != null ? `${nf(n.avail, n.avail >= 99.995 ? 0 : 2)}% disponível` : '', n.problems ? `${n.problems} ${n.problems === 1 ? 'alerta' : 'alertas'}` : 'sem alertas'].filter(Boolean).join(' · ')
        : `${esc(n.model || n.ip || '')}${n.uptime ? ` · ${fmtDur(n.uptime)}` : ''}`;
      return nodeHtml(n, sub, `id="mp-h${esc(n.id)}" data-h="${esc(n.id)}"`, kindOf(n), vc, badge(v));
    };
    const linkN = (l, i) => {
      const u = l.uso || {}, cap = u.capacidade || u.porta, pk = u.pico24, pct = pk != null && cap ? Math.round(100 * pk / cap) : null;
      const prov = (/\(([^)]+)\)/.exec(l.name) || [])[1] || `Link ${l.porta || l.name.replace(/\(.*$/, '')}`;
      return nodeHtml({ name: l.name, label: prov, state: l.up ? 'up' : 'down', problems: 0 }, `${u.capacidade ? fmtBps(u.capacidade) + ' · ' : ''}⬇ ${fmtBps(u.agora && u.agora.in)}${pct != null ? ` · pico ${pct}%` : ''}`,
        `id="mp-l${i}" data-ln="${i}"`, 'link', l.up ? '#5fe3b8' : '#ff7a7a');
    };
    const apN = (a, i) => nodeHtml({ name: a.name, state: a.up ? 'up' : 'down', problems: 0 }, `👥 ${a.clients ?? '–'} ${a.clients === 1 ? 'usuário' : 'usuários'}${a.cpu != null ? ` · CPU ${a.cpu}%` : ''}${a.mem != null ? ` · mem ${a.mem}%` : ''}${a.up && a.uptime ? ` · ${fmtDur(a.uptime)}` : ''}`,
      `id="mp-a${i}" data-ap="${i}"`, 'ap', VEND.fortinet[1], badge('fortinet'));
    const byName = (a, b) => a.name.localeCompare(b.name);
    const fws = (by.firewall || []).slice().sort((a, b) => /principal|main|prim/i.test(b.name) - /principal|main|prim/i.test(a.name) || byName(a, b));
    const sws = (by.switch || []).slice().sort(byName);
    // físicos agrupados pelo código do servidor (iDRAC, ESXi e host físico do mesmo equipamento viram um cartão)
    const srv = {};
    (by.fisico || []).forEach((n) => { const k = ((/[A-Za-z]+\d+/.exec(n.name.replace(/\.[a-z0-9.-]+$/i, '')) || [])[0] || n.name).toUpperCase(); (srv[k] = srv[k] || []).push(n); });
    const role = (n) => /idrac|ilo|ipmi/i.test(n.name) ? 'BMC' : /f[ií]sico/i.test(n.name) ? 'Host físico' : /esxi/i.test(n.name) ? 'ESXi' : 'Sistema';
    const worst = (ns) => ns.some((n) => n.state === 'down') ? 'down' : ns.some((n) => n.problems && n.worst >= 4) ? 'crit' : ns.some((n) => n.problems) ? 'warn' : 'ok';
    const srvCard = (k, ns) => `<div class="mp-srv ${worst(ns)}" id="mp-s${esc(k)}" data-first="${esc(ns[0].id)}" title="Clique para ver a ficha">${mapIcon('hyper', VEND.vmware[1])}<div class="mp-b"><div class="mp-t">${badge(vendorOf(ns[0]))}<span>${esc(k)}</span></div>
      <div class="mp-s">${esc(ns.map((n) => n.model).find(Boolean) || 'servidor físico')}</div><div class="mp-roles">${ns.slice().sort((a, b) => role(a).localeCompare(role(b))).map((n) => `<button type="button" class="mp-role ${mpState(n)}" data-h="${esc(n.id)}" title="${esc(n.name)}"><i class="mp-dot"></i>${role(n)}${n.problems ? ` <b>${n.problems}</b>` : ''}</button>`).join('')}</div></div></div>`;
    const vmChip = (n) => {
      const nm = short(n.name), m = /^(.+?)\s+-\s+(.+)$/.exec(nm), srvN = m ? m[1].trim() : nm, fn = m ? m[2].trim() : '';
      return `<button type="button" class="mp-vm ${mpState(n)}" data-h="${esc(n.id)}" title="${esc(n.name)}"><span class="mp-vtx"><b>${esc(srvN)}</b><small>${esc(fn || 'servidor virtual')}</small></span>${n.problems ? `<b class="chip">${n.problems}</b>` : ''}</button>`;
    };
    const vms = (by.vm || []).slice().sort((a, b) => (mpState(b) !== 'ok') - (mpState(a) !== 'ok') || byName(a, b));
    const servers = (by.servidor || []).slice().sort(byName);
    const apUsers = (d.aps || []).reduce((s, a) => s + (a.clients || 0), 0);
    const grp = (id, ic, title, sub, inner, cls = '') => `<section class="mp-g ${cls}" id="mp-g${id}"><h4>${ic} ${title}<small>${sub}</small></h4>${inner}</section>`;
    const dcInner = (Object.keys(srv).length ? `<div class="mp-srvs">${Object.entries(srv).map(([k, ns]) => srvCard(k, ns)).join('')}</div>` : '')
      + (servers.length ? `<div class="mp-virt"><div class="mp-vt">Servidores <small>${servers.length}</small></div><div class="mp-vms">${servers.map(vmChip).join('')}</div></div>` : '')
      + (vms.length ? `<div class="mp-virt" id="mp-virt"><div class="mp-vt">Virtualização <small>${vms.filter((n) => mpState(n) !== 'ok').length} com alerta</small></div><div class="mp-vms">${vms.map(vmChip).join('')}</div></div>` : '');
    return `${DEFS}<div class="mp" id="mpWrap"><svg class="mp-lines" id="mpLines" aria-hidden="true"></svg>
      <div class="mp-row"><div class="mp-inet" id="mp-inet">${mapIcon('cloud', '#6fd0d4')}<b>Internet</b><small>${d.links.length} ${d.links.length === 1 ? 'link' : 'links'}</small></div></div>
      ${d.links.length ? `<div class="mp-row">${d.links.map(linkN).join('')}</div>` : ''}
      ${fws.length ? `<div class="mp-row">${fws.map(dev).join('')}</div>` : ''}
      ${sws.length ? `<div class="mp-row">${sws.map(dev).join('')}</div>` : ''}
      <div class="mp-row mp-groups">
        <div class="mp-stack">${(d.aps || []).length ? grp('wifi', '📶', 'Wi-fi escritório', `${d.aps.length} APs · 👥 ${apUsers}`, `<div class="mp-col">${d.aps.map(apN).join('')}</div>`) : ''}
          ${(by.web || []).length ? grp('web', '🌍', 'Serviços web', `${by.web.length}`, `<div class="mp-col">${by.web.map(dev).join('')}</div>`) : ''}</div>
        ${dcInner ? grp('dc', '🗄️', 'Datacenter', `${Object.keys(srv).length} físicos · ${vms.length + servers.length} servidores`, dcInner, 'wide') : ''}
        ${(by.outros || []).length ? grp('out', '📟', 'Outros', `${by.outros.length}`, `<div class="mp-col">${by.outros.map(dev).join('')}</div>`) : ''}
      </div></div>`;
  }

  /* ---------- conexões animadas */
  function mapEdges(d, by) {
    const E = [], st = (id) => { const el = document.getElementById(id); return el ? (el.classList.contains('down') ? 'down' : el.classList.contains('crit') ? 'crit' : el.classList.contains('warn') ? 'warn' : 'ok') : 'ok'; };
    const fws = (by.firewall || []).slice().sort((a, b) => /principal|main|prim/i.test(b.name) - /principal|main|prim/i.test(a.name)), main = fws[0];
    const sws = by.switch || [], fsw = sws.filter((n) => /forti/i.test(n.name)), tor = sws.find((n) => !/forti/i.test(n.name)) || sws[0], top = tor || fsw[0] || main;
    d.links.forEach((l, i) => { E.push(['mp-inet', 'mp-l' + i]); const fw = l.fw && document.getElementById('mp-h' + l.fw) ? l.fw : main && main.id; if (fw) E.push(['mp-l' + i, 'mp-h' + fw]); });
    if (!d.links.length && main) E.push(['mp-inet', 'mp-h' + main.id]);
    if (main) sws.forEach((s) => E.push(['mp-h' + main.id, 'mp-h' + s.id]));
    const from = (sws.length ? (fsw[0] || tor) : main);
    if ((d.aps || []).length && from) E.push(['mp-h' + from.id, 'mp-gwifi']);
    if (top) { if (document.getElementById('mp-gdc')) E.push(['mp-h' + top.id, 'mp-gdc']); if ((by.web || []).length) E.push(['mp-h' + top.id, 'mp-gweb']); }
    if ((by.outros || []).length && (fsw[1] || fsw[0] || top)) E.push(['mp-h' + (fsw[1] || fsw[0] || top).id, 'mp-gout']);
    document.querySelectorAll('.mp-srv').forEach((s) => { if (document.getElementById('mp-virt')) E.push([s.id, 'mp-virt']); });
    return E.map(([a, b]) => ({ a, b, s: [st(a), st(b)].includes('down') ? 'down' : [st(a), st(b)].includes('crit') ? 'crit' : st(b) === 'warn' ? 'warn' : 'ok' }));
  }
  function mapLines(d, by) {
    if (d) last = { d, by }; if (!last) return;
    const wrap = $('#mpWrap'), svg = $('#mpLines'); if (!wrap || !svg) return;
    const k = +wrap.dataset.scale || 1, W = wrap.getBoundingClientRect(), ww = W.width / k, wh = W.height / k;
    svg.setAttribute('width', ww); svg.setAttribute('height', wh); svg.setAttribute('viewBox', `0 0 ${ww} ${wh}`);
    const pt = (id, top) => { const el = document.getElementById(id); if (!el) return null; const r = el.getBoundingClientRect(); return [(r.left - W.left + r.width / 2) / k, (top ? r.top - W.top : r.bottom - W.top) / k]; };
    let out = '';
    mapEdges(last.d, last.by).forEach((e, i) => {
      const p = pt(e.a, false), q = pt(e.b, true); if (!p || !q) return;
      const my = (p[1] + q[1]) / 2, path = `M${p[0].toFixed(1)} ${p[1].toFixed(1)} C${p[0].toFixed(1)} ${my.toFixed(1)} ${q[0].toFixed(1)} ${my.toFixed(1)} ${q[0].toFixed(1)} ${q[1].toFixed(1)}`;
      const dur = (2.2 + (i % 5) * 0.35).toFixed(2);
      out += `<path id="mpE${i}" class="mp-e ${e.s}" d="${path}"/><path class="mp-e2 ${e.s}" d="${path}" style="animation-duration:${(+dur * 0.6).toFixed(2)}s"/>`
        + (e.s === 'down' ? '' : `<circle r="3" class="mp-pk ${e.s}"><animateMotion dur="${dur}s" repeatCount="indefinite" begin="-${((i * 0.37) % +dur).toFixed(2)}s"><mpath href="#mpE${i}"/></animateMotion></circle>`);
    });
    svg.innerHTML = out;
  }
  /* cabe numa tela: reduz o mapa (mesma proporção) até a altura caber na área da topologia */
  function fitMap() {
    const wrap = $('#mpWrap'), body = $('#mpStage'); if (!wrap || !body) return;
    const cs = getComputedStyle(body), aw = body.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight), ah = body.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
    let k = 1;
    for (let i = 0; i < 4; i++) {
      wrap.style.width = aw / k + 'px'; wrap.style.transform = k < 1 ? `scale(${k})` : ''; wrap.style.marginBottom = '';
      const nk = Math.min(1, ah / wrap.scrollHeight);
      if (Math.abs(nk - k) < 0.01) { k = nk; break; }
      k = Math.max(0.5, nk);
    }
    wrap.style.width = aw / k + 'px'; wrap.style.transformOrigin = '0 0'; wrap.style.transform = k < 1 ? `scale(${k})` : '';
    wrap.style.marginBottom = k < 1 ? `${-wrap.scrollHeight * (1 - k)}px` : ''; wrap.dataset.scale = k;
    body.style.overflow = k < 1 ? 'hidden' : '';
  }

  /* ---------- pré-visualização ao passar o mouse */
  const tip = $('#mpTip');
  function tipFor(el) {
    const id = el.dataset.h, n = D && D.nodes.find((x) => x.id === id);
    if (n) {
      const lines = (n.probs || []).map((p) => `<li><i style="background:${sevColor(p.severity)}"></i><span>${esc(p.name)}</span></li>`).join('');
      return `<h5>${esc(short(n.name))}</h5><div><span class="k">${n.state === 'down' ? '<b class="r">fora do ar</b>' : n.state === 'maintenance' ? 'em manutenção' : '<b class="gd">no ar</b>'}${n.ip ? ` · ${esc(n.ip)}` : ''}</span></div>
        <div class="k">${n.avail != null ? `${nf(n.avail, 2)}% disponível (7 dias) · ` : ''}${n.uptime ? `ligado há ${fmtDur(n.uptime)}` : ''}</div>
        <div>${n.problems ? `<b class="y">${n.problems} ${n.problems === 1 ? 'alerta aberto' : 'alertas abertos'}</b>` : '<span class="gd">sem alertas</span>'}</div>${lines ? `<ul>${lines}</ul>` : ''}<div class="k" style="margin-top:6px">clique para ver a ficha</div>`;
    }
    const ln = el.closest('[data-ln]'), ap = el.closest('[data-ap]');
    if (ln) { const l = D.links[+ln.dataset.ln], u = l.uso || {}; return `<h5>${esc(l.name)}</h5><div class="k">${l.up ? '<b class="gd">no ar</b>' : '<b class="r">fora</b>'} · ${esc(l.porta || '')}</div><div class="k">⬇ ${fmtBps(u.agora && u.agora.in)} · ⬆ ${fmtBps(u.agora && u.agora.out)}${u.capacidade ? ` · contratado ${fmtBps(u.capacidade)}` : ''}</div>`; }
    if (ap) { const a = D.aps[+ap.dataset.ap]; return `<h5>${esc(a.name)}</h5><div class="k">${a.up ? '<b class="gd">online</b>' : `<b class="r">${esc(a.state_txt || 'fora')}</b>`} · 👥 ${a.clients ?? '–'} · CPU ${a.cpu ?? '–'}% · mem ${a.mem ?? '–'}%</div>`; }
    return '';
  }
  document.addEventListener('mousemove', (ev) => {
    const el = ev.target.closest && ev.target.closest('[data-h], [data-ln], [data-ap]');
    if (!el || !$('#mpStage').contains(el) || $('#mpModal').classList.contains('on')) { tip.hidden = true; return; }
    const html = tipFor(el); if (!html) { tip.hidden = true; return; }
    if (tip.dataset.k !== (el.id || el.dataset.h)) { tip.innerHTML = html; tip.dataset.k = el.id || el.dataset.h; }
    tip.hidden = false;
    const w = tip.offsetWidth, h = tip.offsetHeight;
    tip.style.left = Math.min(innerWidth - w - 8, ev.clientX + 16) + 'px';
    tip.style.top = (ev.clientY + 18 + h > innerHeight ? Math.max(8, ev.clientY - h - 14) : ev.clientY + 18) + 'px';
  });
  document.addEventListener('mouseleave', () => { tip.hidden = true; });

  /* ---------- ficha do ativo */
  const modal = $('#mpModal');
  const openModal = (hd, ct) => { $('#mpHd').innerHTML = hd; $('#mpCt').innerHTML = ct; modal.classList.add('on'); modal.setAttribute('aria-hidden', 'false'); tip.hidden = true; };
  const closeModal = () => { modal.classList.remove('on'); modal.setAttribute('aria-hidden', 'true'); };
  $('#mpBg').addEventListener('click', closeModal);
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closeModal(); });
  const head = (icon, col, title, sub, pill) => `${mapIcon(icon, col)}<div class="tt"><div>${pill || ''}</div><h3>${esc(title)}</h3><div class="sb">${sub}</div></div><button type="button" class="mp-x" id="mpX">✕ Fechar</button>`;
  document.addEventListener('click', (ev) => { if (ev.target.closest('#mpX')) closeModal(); });
  const pill = (txt, cls) => `<span class="mp-li" style="display:inline-flex;padding:1px 9px;border-radius:99px"><b class="${cls}">${txt}</b></span>`;
  const statePill = (n) => n.state === 'down' ? pill('fora do ar', 'r') : n.state === 'maintenance' ? pill('em manutenção', '') : pill('no ar', 'gd');

  async function openHost(id) {
    const n = D.nodes.find((x) => x.id === id); if (!n) return;
    const v = vendorOf(n), col = (VEND[v] || [0, '#35c2c8'])[1];
    openModal(head(kindOf(n), col, n.name, `${badge(v)} ${esc([n.model, n.os, n.ip].filter(Boolean).join(' · '))}`, statePill(n)), '<div class="mp-none">Buscando as informações no Zabbix…</div>');
    try {
      const f = await getJson(`action=hostficha&hostid=${encodeURIComponent(id)}`);
      const inv = f.inventory || {}, av = f.availability;
      const sys = [['Fabricante', inv.vendor], ['Modelo', inv.model || inv.hardware], ['Sistema', inv.os], ['Tipo', inv.type], ['Local', inv.location], ['IP', f.ip]].filter(([, x]) => x);
      const m = (lbl, val, cls = '') => `<div><span>${lbl}</span><b class="${cls}">${val}</b></div>`;
      const up = n.uptime ? fmtDur(n.uptime + Math.max(0, Date.now() / 1000 - (n.uptime_at || Date.now() / 1000))) : null;
      const ct = `<div class="two"><div class="mp-card"><h6>⏱ Uptime</h6><div class="big">${up || '–'}</div><div class="sm">${up ? 'medido pelo Zabbix' : 'sem item de uptime neste ativo'}</div></div>
        <div class="mp-card"><h6>Sistema / hardware</h6>${sys.length ? sys.map(([k, x]) => `<div class="sm"><b>${k}:</b> ${esc(x)}</div>`).join('') : '<div class="sm">sem inventário no Zabbix</div>'}${f.groups.length ? `<div class="sm"><b>Grupos:</b> ${esc(f.groups.join(', '))}</div>` : ''}</div></div>
        <div class="mp-card"><h6>Últimos ${f.days} dias</h6><div class="mp-kv">
          ${m('Disponibilidade', av != null ? nf(av, 3) + '%' : '–', av == null ? '' : av >= 99.9 ? 'gd' : av >= 99 ? 'y' : 'r')}${m('Tempo fora', f.downtime ? fmtDur(f.downtime) : '0')}${m('Quedas', nf(f.drops), f.drops ? 'y' : '')}${m('MTTR', f.mttr != null ? fmtDur(f.mttr) : '–')}
          ${m('CPU', f.cpu != null ? nf(f.cpu, 0) + '%' : '–')}${m('Memória', f.memory != null ? nf(f.memory, 0) + '%' : '–')}${m('Latência', f.latency_ms != null ? nf(f.latency_ms, 1) + ' ms' : '–')}${m('Perda', f.loss != null ? nf(f.loss, 0) + '%' : '–')}</div>
          <div class="sm" style="margin-top:8px">Quedas e MTTR consideram os alertas Alta e Desastre do período.</div>
          <div class="mp-acts"><a href="${esc(f.front)}" target="_blank" rel="noopener">Problemas no Zabbix ↗</a></div></div>
        <div class="mp-card"><h6>Problemas ativos agora</h6>${f.active.length ? `<div class="mp-list">${f.active.map((p) => `<div class="mp-li"><span class="sv" style="--c:${sevColor(p.severity)}">${SEV[p.severity]}</span><span class="nm">${esc(p.name)}</span><span class="wh">há ${ago(p.clock)}${p.acknowledged ? ' ✔' : ''}</span></div>`).join('')}</div>` : '<div class="mp-none">Nenhum problema aberto.</div>'}</div>
        <div class="mp-card"><h6>Histórico de alertas · 7 dias · ${f.history.length} ${f.history.length === 1 ? 'alerta' : 'alertas'}</h6>${f.history.length ? `<div class="mp-list">${f.history.map((e) => `<a class="mp-li" href="${esc(e.url)}" target="_blank" rel="noopener"><span class="sv" style="--c:${sevColor(e.severity)}">${SEV[e.severity]}</span><span class="nm">${esc(e.name)}</span><span class="wh">${when(e.clock)}${e.end ? ` → ${when(e.end)} (${fmtDur(e.end - e.clock)})` : ' · ativo'}</span></a>`).join('')}</div>` : '<div class="mp-none">Sem alertas nos últimos 7 dias.</div>'}</div>`;
      $('#mpCt').innerHTML = ct;
    } catch (e) { $('#mpCt').innerHTML = `<div class="mp-err">${esc(e.message)}</div>`; }
  }
  function openLink(i) {
    const l = D.links[i], u = l.uso || {}, cap = u.capacidade || u.porta;
    const m = (lbl, val) => `<div><span>${lbl}</span><b>${val}</b></div>`;
    openModal(head('link', l.up ? '#5fe3b8' : '#ff7a7a', l.name, esc(l.porta || ''), l.up ? pill('no ar', 'gd') : pill('fora', 'r')),
      `<div class="mp-card"><h6>Consumo</h6><div class="mp-kv">${m('Entrada agora', fmtBps(u.agora && u.agora.in))}${m('Saída agora', fmtBps(u.agora && u.agora.out))}${m('Contratado / porta', cap ? fmtBps(cap) : '–')}${m('Média 24 h (entrada)', fmtBps(u.media24 && u.media24.in))}${m('Pico 24 h', fmtBps(u.pico24))}${m('Pico sobre a capacidade', u.pico24 != null && cap ? Math.round(100 * u.pico24 / cap) + '%' : '–')}</div></div>`);
  }
  function openAp(i) {
    const a = D.aps[i], m = (lbl, val) => `<div><span>${lbl}</span><b>${val}</b></div>`;
    openModal(head('ap', VEND.fortinet[1], a.name, `${badge('fortinet')} ${esc(a.serial || '')} · ${esc(a.fw || '')}`, a.up ? pill('online', 'gd') : pill(esc(a.state_txt || 'fora do ar'), 'r')),
      `<div class="mp-card"><h6>Situação</h6><div class="mp-kv">${m('Usuários conectados', a.clients ?? '–')}${m('CPU', a.cpu != null ? a.cpu + '%' : '–')}${m('Memória', a.mem != null ? a.mem + '%' : '–')}${m('Conectado há', a.up && a.uptime ? fmtDur(a.uptime) : '–')}</div></div>`);
  }
  $('#mpStage').addEventListener('click', (ev) => {
    const h = ev.target.closest('[data-h]'); if (h) return openHost(h.dataset.h);
    const s = ev.target.closest('[data-first]'); if (s) return openHost(s.dataset.first);
    const l = ev.target.closest('[data-ln]'); if (l) return openLink(+l.dataset.ln);
    const a = ev.target.closest('[data-ap]'); if (a) openAp(+a.dataset.ap);
  });

  /* ---------- carga e atualização */
  async function load() {
    try {
      D = await getJson('action=mapa');
      BY = {}; D.nodes.forEach((n) => (BY[n.tier] = BY[n.tier] || []).push(n));
      const down = D.nodes.filter((n) => n.state === 'down').length, pb = D.nodes.filter((n) => n.problems).length, ld = D.links.filter((l) => !l.up).length;
      $('#mpSum').textContent = `${D.nodes.length} ativos · ${pb} com problema${down ? ` · ${down} fora do ar` : ''} · ${D.links.length - ld}/${D.links.length} links no ar · ${(D.aps || []).filter((a) => a.up).length}/${(D.aps || []).length} APs online · atualizado às ${new Date(D.generated * 1000).toLocaleTimeString('pt-BR')}`;
      $('#mpStage').innerHTML = mapHtml(D, BY);
      fitMap(); mapLines(D, BY); setTimeout(() => { fitMap(); mapLines(); }, 300);
    } catch (e) { $('#mpStage').innerHTML = `<div class="mp-err">${esc(e.message)}</div>`; $('#mpSum').textContent = 'sem dados'; }
  }
  let fitT = null;
  addEventListener('resize', () => { clearTimeout(fitT); fitT = setTimeout(() => { fitMap(); mapLines(); }, 120); });
  $('#reload').addEventListener('click', load);
  $('#fullscreen').addEventListener('click', () => { document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen().catch(() => {}); });
  const clock = () => { $('#clock').textContent = new Date().toLocaleTimeString('pt-BR'); }; clock(); setInterval(clock, 1000);
  load(); setInterval(() => { if (!modal.classList.contains('on')) load(); }, 30000);
})();
