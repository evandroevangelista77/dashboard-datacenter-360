/* Sistemas Internos · visão 360
   Roda por cima do motor do painel do Zabbix (index.html da pasta NOC): fixa a página nos hostgroups do
   departamento e acrescenta o atendimento do GLPI, a linha do tempo, os patches dos servidores Windows e a topologia. */
(function () {
  "use strict";
  const G = window.SI.groups, HASH = "#/g/" + G;
  const CFG = {title: window.SI.title || "Sistemas Internos"};
  document.body.classList.add("si");
  if (location.hash !== HASH) history.replaceState(null, "", HASH);
  addEventListener("hashchange", () => { if (location.hash !== HASH) location.hash = HASH; }, true);  // página de uma rota só
  const brand = document.querySelector(".tvbrand b"); if (brand) brand.textContent = `${CFG.title} 360°`;
  // sem botões de tema e de modo TV nesta página: fica sempre no tema escuro e fora do modo TV
  ["themeBtn", "tvBtn"].forEach(id => { const b = document.getElementById(id); if (b) b.remove(); });
  try { applyTheme("dark"); document.body.classList.remove("tv"); } catch (e) {}
  // frase ao clicar em "Ativar som": o que a voz desta página acompanha
  window.VOZ_ATIVADA = `Alertas de voz ativados. Vou monitorar os alertas do Zabbix dos ativos de ${CFG.title} e os chamados do GLPI, avisando quando o SLA de solução estiver para vencer e quando vencer.`;

  const SIs = {glpi: null, inc: null, ia: null, tlAll: false, glpiKnown: null, slaLate: null, slaSoon: null};
  /* logotipos das fontes (dos próprios servidores Zabbix e GLPI) */
  const SRC = {z: `<span class="si-src z" title="Dados do Zabbix"><img src="/si/marcas/zabbix.svg" alt="Zabbix"></span>`,
               g: `<span class="si-src g" title="Dados do GLPI"><img src="/si/marcas/glpi-100.png" alt="GLPI"></span>`};
  const fmtN = n => n == null ? "–" : Number(n).toLocaleString("pt-BR");
  const pct = v => v == null ? "–" : nf(v, 1) + "%";
  const short = (s, n) => (s = String(s || "")).length > n ? s.slice(0, n - 1) + "…" : s;
  const tkLink = (id, url) => `<a href="${esc(url)}/front/ticket.form.php?id=${id}" target="_blank" rel="noopener">#${id}</a>`;

  /* ---------- página: monta as seções do departamento depois do conteúdo do Zabbix ---------- */
  const viewGroupZabbix = viewGroup;
  viewGroup = async function (gid) {  // eslint-disable-line no-global-assign
    const siP = gid === G ? siFetch(S.ov ? S.ov.period : null) : null;  // busca o GLPI/IA em paralelo com o Zabbix
    await viewGroupZabbix(gid);
    if (gid !== G) return;
    $("#title").innerHTML = `<span class="tl"><span>${esc(CFG.title)} · visão 360</span></span>`;
    const sub = $("#subtitle").textContent;
    $("#subtitle").textContent = sub.replace(/^\d+ hosts/, m => m.replace("hosts", "ativos no Zabbix")) + " · chamados do GLPI";
    // sem pulos: monta na hora com os últimos dados conhecidos e troca quando os novos chegam
    if (SIs.cache) siSections(SIs.cache);
    const fresh = await siP;
    SIs.cache = fresh;
    if (location.hash === HASH) siSections(fresh);
  };

  async function siFetch(p) {
    const now = Date.now() / 1000, from = p ? p.start : now - 30 * 86400, to = p ? Math.min(p.end, now) : now;
    const bucket = S.ov && S.ov.bucket_kind === "hour" ? "hour" : "day";
    const [gl, inc, ia, zg, topo] = await Promise.all([
      api(`/api/si/glpi?from=${from}&to=${to}&bucket=${bucket}`).catch(e => ({erro: e.message})),
      api(`/api/group/${G}/incidents?period=${S.period}&limit=20000`).catch(() => ({incidents: []})),
      Promise.resolve(null),
      api(`/api/group/${G}?period=${S.period}`).catch(() => null),
      api(`/api/topology?group=${encodeURIComponent(G)}`).catch(() => null),
    ]);
    return {gl, inc, ia, zg, topo};
  }

  /* insere as seções do departamento (substitui as anteriores, se já existirem) */
  function siSections(d) {
    const view = $("#view"); if (!view) return;
    const {gl, inc, ia, zg, topo} = d;
    SIs.glpi = gl; SIs.inc = (inc && inc.incidents) || []; SIs.ia = ia;
    view.querySelectorAll(".si-blk").forEach(e => e.remove());
    const blk = html => `<div class="si-blk" style="display:contents">${html}</div>`;
    // 1) situação geral, no topo
    view.insertAdjacentHTML("afterbegin", blk(statusHtml(gl, zg && zg.summary) + `<div class="si-head">${SRC.z}Infraestrutura monitorada<span class="hint">Applications · Sistemas Internos · ha-datacenter · Zabbix servers</span></div>`));
    // 2) atendimento GLPI: antes do mapa de disponibilidade
    const heat = $("#cHeat") && $("#cHeat").closest(".card");
    const glHtml = blk(glpiHtml(gl) + `<div class="si-head">${SRC.z}Ativos em detalhe</div>`);
    if (heat) heat.insertAdjacentHTML("beforebegin", glHtml); else view.insertAdjacentHTML("beforeend", glHtml);
    // 3) linha do tempo: antes da tendência
    const trend = $("#cTrend") && $("#cTrend").closest(".card");
    const tlIa = blk(timelineHtml());
    if (trend) trend.insertAdjacentHTML("beforebegin", tlIa); else view.insertAdjacentHTML("beforeend", tlIa);
    // linha "Problemas ativos por severidade · Estado dos hosts agora · Top 10" sai da página
    // (fica oculta, não removida: os gráficos do painel do Zabbix ainda desenham nela)
    const sevCard = [...view.querySelectorAll(".card h2")].find(h => /Problemas ativos por severidade/i.test(h.textContent));
    if (sevCard) { const row = sevCard.closest(".grid") || sevCard.closest(".card"); if (row) row.style.display = "none"; }
    // 4) "Saúde da infraestrutura" sai; no lugar dela fica o card dos APs do escritório
    const hh = [...view.querySelectorAll(".card h2")].find(h => /Saúde da infraestrutura/i.test(h.textContent));
    const apCard = view.querySelector("[data-si-ap]") || (hh && hh.closest(".card"));
    if (apCard) {
      apCard.dataset.siAp = "1";
      if (topo && (topo.aps || []).length) { apCard.style.display = ""; apCard.innerHTML = apsHealthHtml(topo); }
      else if (!SIs.cache || !SIs.cache.topo) apCard.style.display = "none";
    }
    bindSections();
    if (!S.draw.includes(drawGlpiSeries)) S.draw.push(drawGlpiSeries);
    drawGlpiSeries();
  }

  /* ---------- access points do escritório (wi-fi), com todos os detalhes ---------- */
  function apsHealthHtml(topo) {
    const aps = topo.aps, fw = id => ((topo.nodes || []).find(n => n.id === id) || {}).name || "";
    const on = aps.filter(a => a.up).length, users = aps.reduce((n, a) => n + (a.clients || 0), 0);
    const bar = (v, warn, crit) => v == null ? "" : `<div class="ap-bar"><i style="width:${Math.max(2, Math.min(100, v))}%;background:${v >= crit ? "var(--crit)" : v >= warn ? "var(--warn)" : "var(--good)"}"></i></div>`;
    const model = a => "FortiAP " + ((/^FP(\d{3}[A-Z])/.exec(a.serial) || [])[1] || "");
    return `<div class="ap-health"><div class="ap-hd">📶 <b>Access points do escritório</b><span>${on}/${aps.length} online · 👥 ${users} ${users === 1 ? "usuário conectado" : "usuários conectados"} no wi-fi</span></div>
      <div class="ap-grid">${aps.map(a => `<div class="ap-card${a.up ? "" : " down"}">
        <div class="ap-top"><span class="ap-sig ${a.up ? "up" : "dn"}"></span><b>${esc(a.name)}</b><span class="tag ${a.up ? "good" : "crit"}">${a.up ? "online" : esc(a.state_txt || "fora do ar")}</span></div>
        <div class="ap-users">👥 <b>${a.clients != null ? a.clients : "–"}</b> ${a.clients === 1 ? "usuário conectado" : "usuários conectados"}</div>
        <div class="ap-m"><span>CPU</span><b>${a.cpu != null ? a.cpu + "%" : "–"}</b>${bar(a.cpu, 60, 85)}</div>
        <div class="ap-m"><span>Memória</span><b>${a.mem != null ? a.mem + "%" : "–"}</b>${bar(a.mem, 70, 90)}</div>
        <dl><dt>Conectado há</dt><dd>${a.up && a.uptime ? `${fmtDur(a.uptime)} <small>(desde ${fmtDT(Date.now() / 1000 - a.uptime)})</small>` : "–"}</dd>
          <dt>Modelo</dt><dd>${esc(model(a))}</dd><dt>Número de série</dt><dd>${esc(a.serial)}</dd>
          <dt>Firewall</dt><dd title="${esc(a.fw || fw(a.fwid))}">${esc((a.fw || fw(a.fwid)).replace(/^FW\s*\|\s*/, ""))}</dd>
          <dt>Alerta de queda</dt><dd>${a.down ? '<span class="si-badge late">disparado</span>' : '<span class="si-badge ok">normal</span>'}</dd></dl></div>`).join("")}</div></div>`;
  }

  /* ---------- situação geral: as duas fontes juntas ---------- */
  function statusHtml(gl, sm) {
    const k = gl && gl.kpis ? gl.kpis : {}, sla = k.sla_solucao ? k.sla_solucao.pct : null;
    sm = sm || {states: {}};
    const fora = sm.states.down || 0, probs = sm.problems_total || 0, crit = (sm.problems || {})[4] + (sm.problems || {})[5] || 0;
    let lvl = "good", msg = "Ambiente estável e atendimento em dia";
    if (fora > 0 || (k.sla_atrasados_agora || 0) > 0) {
      lvl = "crit";
      msg = [fora ? `${fora} ${fora === 1 ? "ativo fora do ar" : "ativos fora do ar"}` : "", k.sla_atrasados_agora ? `${k.sla_atrasados_agora} ${k.sla_atrasados_agora === 1 ? "chamado com SLA atrasado" : "chamados com SLA atrasado"}` : ""].filter(Boolean).join(" · ");
    } else if (probs > 0 || (k.parados || 0) > 0) {
      lvl = "warn"; msg = `${probs} ${probs === 1 ? "problema ativo" : "problemas ativos"} no Zabbix · ${k.parados || 0} ${k.parados === 1 ? "chamado parado" : "chamados parados"} no GLPI`;
    }
    const slo = S.cfg ? S.cfg.slo : 99.9, av = sm.avail;
    const tile = (src, l, v, s, cls, dd) => `<div class="kpi"${dd ? ddAttr(dd) : ""}><span class="src">${src === "GLPI" ? SRC.g : SRC.z}</span><div class="l">${l}</div><div class="v ${cls || ""}">${v}</div><div class="s">${s || "&nbsp;"}</div></div>`;
    return `<div class="si-status"><span class="dot ${lvl}"></span><b>${lvl === "good" ? "Tudo certo" : lvl === "warn" ? "Atenção" : "Ação necessária"}</b><span>${esc(msg)}</span></div>
      <div class="si-strip">
        ${tile("ZABBIX", "Disponibilidade", av == null ? "–" : nf(av, 3) + "%", `meta ${nf(slo, 2)}% · ${fora ? `<b style="color:var(--crit-text)">${fora} fora agora</b>` : "nenhum fora agora"}`, av == null ? "" : av >= slo ? "good" : av >= 99 ? "warn" : "crit", {kind: "hosts", state: "monitored", title: "Disponibilidade por ativo", sub: "piores primeiro"})}
        ${tile("ZABBIX", "Ativos monitorados", fmtN(sm.monitored), `de ${fmtN(sm.hosts)} nos 4 hostgroups`, "", {kind: "hosts", state: "monitored", title: "Ativos monitorados"})}
        ${tile("ZABBIX", "Problemas ativos", fmtN(probs), crit ? `${crit} Alta ou Desastre` : "nenhum Alta/Desastre", crit ? "crit" : probs ? "warn" : "good", {kind: "problems", title: "Problemas ativos agora"})}
        ${tile("GLPI", "Chamados abertos", fmtN(k.abertos_agora), `${fmtN(k.novos)} novos · ${fmtN(k.pendentes)} pendentes`, "", {kind: "glpi", list: "em_aberto", title: "Chamados em aberto"})}
        ${tile("GLPI", "SLA de solução", pct(sla), k.sla_solucao ? `${k.sla_solucao.violados} violados em ${k.sla_solucao.n}` : "", sla == null ? "" : sla >= 95 ? "good" : sla >= 85 ? "warn" : "crit", {kind: "glpi", list: "sla_violado", title: "SLA de solução violado", sub: "chamados abertos no período"})}
        ${tile("GLPI", "Parados ≥ 3 dias", fmtN(k.parados), `${fmtN(k.sem_responsavel)} sem responsável`, k.parados ? "warn" : "good", {kind: "glpi", list: "parados", title: "Chamados parados há 3 dias ou mais", sort: "parado"})}
      </div>`;
  }

  /* ---------- atendimento (GLPI) ---------- */
  function glpiHtml(gl) {
    const head = `<div class="si-head">${SRC.g}Atendimento do departamento<span class="hint">entidade ${esc(gl && gl.entidade || "")}</span></div>`;
    if (!gl || gl.erro) return head + `<div class="card"><div class="empty">${esc(gl && gl.erro || "Sem dados do GLPI.")}</div></div>`;
    const k = gl.kpis, kk = (l, v, s, c, list, extra) => `<div class="kpi"${list ? ddAttr(Object.assign({kind: "glpi", list, title: l}, extra || {})) : ""}><div class="l">${l}</div><div class="v ${c || ""}">${v}</div><div class="s">${s || "&nbsp;"}</div></div>`;
    const sla = k.sla_solucao, slo = k.sla_atendimento;
    const rows = (arr, c, list, title) => arr.map(([l, v]) => ({l, v, c, dd: list ? {kind: "glpi", list, v: l, title: title.replace("{}", l)} : null}));
    const late = gl.abertos.filter(t => t.atrasado).length;
    return head + `<div class="kpis">
        ${kk("Abertos no período", fmtN(k.abertos_periodo), "chamados novos para o departamento", "", "abertos_periodo")}
        ${kk("Solucionados no período", fmtN(k.solucionados_periodo), k.lead_mediano_h != null ? `metade resolvida em até ${fmtDur(k.lead_mediano_h * 3600)}` : "", "", "solucionados_periodo")}
        ${kk("Em aberto agora", fmtN(k.abertos_agora), `${fmtN(k.novos)} novos · ${fmtN(k.pendentes)} pendentes`, "", "em_aberto")}
        ${kk("SLA de solução", pct(sla.pct), `${sla.violados} violados em ${sla.n} · sem prestadores`, sla.pct == null ? "" : sla.pct >= 95 ? "good" : sla.pct >= 85 ? "warn" : "crit", "sla_violado", {title: "SLA de solução violado", sub: "sem os chamados de prestadores"})}
        ${kk("SLA de atendimento", pct(slo.pct), `${slo.violados} violados em ${slo.n} · sem prestadores`, slo.pct == null ? "" : slo.pct >= 95 ? "good" : slo.pct >= 85 ? "warn" : "crit", "sla_tto_violado", {title: "SLA de atendimento violado", sub: "sem os chamados de prestadores"})}
        ${kk("SLA atrasado agora", fmtN(k.sla_atrasados_agora), "prazo vencido · sem prestadores", k.sla_atrasados_agora ? "crit" : "good", "sla_atrasado", {sort: "sla"})}
        ${kk("Parados ≥ 3 dias", fmtN(k.parados), `${fmtN(k.sem_responsavel)} sem responsável`, k.parados ? "warn" : "good", "parados", {sort: "parado"})}
        ${prestKpi(gl.prestadores)}
      </div>
      ${prestHtml(gl.prestadores)}
      <div class="grid g2"><div class="card"><h2>Abertos × solucionados<span class="hint">no período</span></h2><div class="chart" id="siGSeries"></div><div class="legend"><span><i style="background:var(--s2)"></i>Abertos</span><span><i style="background:var(--s3)"></i>Solucionados</span></div></div>
        <div class="card"><h2>Backlog por técnico<span class="hint">chamados em aberto</span></h2>${hbars(rows(gl.por_tecnico, "s1", "tecnico", "Chamados em aberto · {}"), v => v, "Nenhum chamado em aberto 🎉")}
          <h2 style="margin-top:14px">Por status</h2>${hbars(rows(gl.por_status.filter(x => x[1]), "s3", "status", "Chamados · {}"), v => v, "Nenhum chamado em aberto")}</div></div>
      <div class="grid g2"><div class="card"><h2>Categorias mais abertas<span class="hint">no período</span></h2>${hbars(rows(gl.categorias, "s4", "categoria", "Categoria · {}"), v => v, "Nenhum chamado no período")}</div>
        <div class="card"><h2>Backlog por grupo<span class="hint">chamados em aberto</span></h2>${hbars(rows(gl.por_grupo, "s2", "grupo", "Grupo · {}"), v => v, "Nenhum chamado em aberto")}</div></div>
      <div class="card" style="margin-top:14px"><h2>Chamados em aberto<span class="hint">${gl.abertos.length} ${gl.abertos.length === 1 ? "chamado" : "chamados"}${late ? ` · ${late} com SLA atrasado` : ""}${gl.abertos.some(t => t.prest) ? ` (${gl.abertos.filter(t => t.prest).length} de prestadores)` : ""} · mais parados primeiro</span></h2>
        <div class="tablewrap" style="max-height:420px"><table class="si-tk"><thead><tr><th class="nosort">Chamado</th><th class="nosort">Título</th><th class="nosort">Status</th><th class="nosort">Técnico</th><th class="nosort">Categoria</th><th class="nosort num">Parado</th><th class="nosort">SLA</th></tr></thead><tbody>
        ${gl.abertos.map(t => `<tr${ddAttr({kind: "glpi", list: "ticket", v: String(t.id), title: `Chamado #${t.id}`, expand: true})} title="Ver todos os detalhes do chamado"><td>${tkLink(t.id, gl.glpi_url)}</td><td class="t">${esc(t.n)}</td><td>${esc(t.status)}</td><td>${esc(t.au.join(", ") || "—")}${t.prest ? ' <span class="tag mut" title="prestador de serviço: fora do SLA do departamento">prestador</span>' : ""}</td><td>${esc(t.cat)}</td>
          <td class="num ${t.parado > 30 ? "av-crit" : t.parado >= 3 ? "av-warn" : ""}">${nf(t.parado, 0)} d</td>
          <td>${t.TTR ? (t.atrasado ? '<span class="si-badge late">atrasado</span>' : `<span class="si-badge ok">vence ${fmtDT(t.TTR)}</span>`) : "–"}</td></tr>`).join("") ||
          '<tr><td colspan="7" class="empty">Nenhum chamado em aberto 🎉</td></tr>'}</tbody></table></div></div>`;
  }
  /* cartão-resumo dos prestadores na linha de indicadores: quantidade · SLA · backlog */
  function prestKpi(pr) {
    if (!pr || !pr.total) return "";
    const t = pr.total, sla = t.sla_solucao.pct, scls = sla == null ? "" : sla >= 95 ? "good" : sla >= 85 ? "warn" : "crit";
    const dd = (list, title) => ddAttr({kind: "glpi", list, title, sub: "prestadores de serviço · fora do SLA do departamento"});
    return `<div class="kpi prest-kpi" title="${esc(pr.nomes.join(", "))}"><div class="l">🔧 Prestadores de serviço</div>
      <div class="pk">
        <div${dd("prest_periodo", "Prestadores · chamados no período")}><b>${fmtN(t.abertos_periodo)}</b><span>chamados</span></div>
        <div${dd("prest_periodo", "Prestadores · SLA de solução")}><b class="${scls}">${sla == null ? "–" : nf(sla, 1) + "%"}</b><span>SLA (${t.sla_solucao.violados}/${t.sla_solucao.n})</span></div>
        <div${dd("prest_em_aberto", "Prestadores · backlog em aberto")}><b class="${t.sla_atrasado_agora ? "crit" : t.em_aberto ? "warn" : "good"}">${fmtN(t.em_aberto)}</b><span>backlog${t.sla_atrasado_agora ? ` · ${t.sla_atrasado_agora} atrasado${t.sla_atrasado_agora > 1 ? "s" : ""}` : ""}</span></div>
      </div><div class="s">${esc(pr.nomes.map(n => n.split(" ")[0]).join(" e "))} · fora do SLA do departamento</div></div>`;
  }
  /* prestadores de serviço: fora do SLA do departamento, acompanhados à parte */
  function prestHtml(pr) {
    if (!pr || !pr.por_prestador || !pr.por_prestador.length) return "";
    const cell = (v, list, nome, title, cls) => `<td class="num ${cls || ""}"${v ? ddAttr({kind: "glpi", list, v: nome || "", title, sub: "prestador de serviço · fora do SLA do departamento"}) : ""}>${v}</td>`;
    const slaTxt = x => x.pct == null ? "–" : `<span class="${x.pct >= 95 ? "av-good" : x.pct >= 85 ? "av-warn" : "av-crit"}">${nf(x.pct, 1)}%</span> <small>(${x.violados}/${x.n})</small>`;
    const row = (x, nome, lab) => `<tr${nome ? "" : ' class="tot"'}><td>${lab}</td>
      ${cell(x.em_aberto, "prest_em_aberto", nome, `Prestador · ${lab} · em aberto`)}${cell(x.abertos_periodo, "prest_periodo", nome, `Prestador · ${lab} · abertos no período`)}
      ${cell(x.solucionados_periodo, "prest_solucionados", nome, `Prestador · ${lab} · solucionados no período`)}<td class="num">${slaTxt(x.sla_solucao)}</td>
      ${cell(x.sla_atrasado_agora, "prest_sla_atrasado", nome, `Prestador · ${lab} · SLA atrasado`, x.sla_atrasado_agora ? "av-crit" : "")}
      <td class="num ${x.parados ? "av-warn" : ""}">${x.parados}</td><td class="num">${x.em_aberto ? nf(x.mais_antigo_dias, 0) + " d" : "–"}</td></tr>`;
    return `<div class="card prest" style="margin-top:14px"><h2>🔧 Prestadores de serviço<span class="hint">chamados fora do SLA do departamento · ${esc(pr.nomes.join(", "))}</span></h2>
      <div class="tablewrap" style="max-height:none"><table><thead><tr><th class="nosort">Prestador</th><th class="nosort num">Em aberto</th><th class="nosort num">Abertos no período</th><th class="nosort num">Solucionados</th>
      <th class="nosort num">SLA de solução (deles)</th><th class="nosort num">SLA atrasado agora</th><th class="nosort num">Parados ≥ 3 d</th><th class="nosort num">Mais antigo</th></tr></thead><tbody>
      ${pr.por_prestador.map(x => row(x, x.nome, esc(x.nome))).join("")}${pr.por_prestador.length > 1 ? row(pr.total, "", "Total dos prestadores") : ""}</tbody></table></div></div>`;
  }
  function drawGlpiSeries() {
    const box = $("#siGSeries"), gl = SIs.glpi; if (!box || !gl || !gl.series) return;
    const s = gl.series, xl = s.map(b => gl.bucket === "hour" ? fmtHour(b.from) : fmtDay(b.from));
    lineChart(box, {x: s, xl, h: 230, ml: 40, fmtY: v => nf(v, 0), ymin: 0, aria: "Chamados abertos e solucionados",
      series: [{name: "Abertos", color: "s2", vals: s.map(b => b.abertos), points: true}, {name: "Solucionados", color: "s3", vals: s.map(b => b.solucionados), points: true}],
      tip: i => `<b>${esc(gl.bucket === "hour" ? fmtDT(s[i].from) : fmtDayFull(s[i].from))}</b><div class="r"><span>Abertos</span><b>${s[i].abertos}</b></div><div class="r"><span>Solucionados</span><b>${s[i].solucionados}</b></div><div class="r"><span></span><small>clique para ver os chamados</small></div>`,
      dd: i => ({kind: "glpi", list: "janela", from: s[i].from, to: s[i].to, title: `Chamados · ${gl.bucket === "hour" ? fmtDT(s[i].from) : fmtDayFull(s[i].from)}`, sub: "abertos ou solucionados nesta janela"})});
  }

  /* ---------- linha do tempo: alertas do Zabbix e chamados do GLPI juntos, em colunas ---------- */
  const SIt = {f: "todos"};
  function timelineEvents() {
    const gl = SIs.glpi, ev = [], now = Date.now() / 1000;
    (SIs.inc || []).filter(i => i.sev >= 2).forEach(i => ev.push({t: i.start, src: "z", sev: i.sev, what: i.name, who: i.host, open: i.end == null, inc: i,
      resp: i.ack_by || "", dur: (i.end || now) - i.start, ackIn: i.ack_at ? i.ack_at - i.start : null}));
    if (gl && gl.eventos) gl.eventos.forEach(e => ev.push({t: e.t, src: "g", what: e.n, who: e.cat, id: e.id, tipo: e.tipo === "aberto" ? "Chamado aberto" : "Chamado solucionado", ev: e,
      resp: (e.au || []).join(", "), open: e.SD == null, dur: (e.SD || now) - e.D}));
    return ev.sort((a, b) => b.t - a.t);
  }
  function tlFiltered() {
    const f = SIt.f; return timelineEvents().filter(e => f === "todos" || (f === "z" && e.src === "z") || (f === "g" && e.src === "g") || (f === "aberto" && e.open) || (f === "semresp" && !e.resp));
  }
  function timelineHtml() {
    return `<div class="si-head">Linha do tempo<span class="hint">alertas do Zabbix (Atenção ou mais) e chamados do GLPI, do mais recente para o mais antigo</span></div>
      <div class="card" id="siTlCard">${timelineInner()}</div>`;
  }
  function timelineInner() {
    const all = timelineEvents(), ev = tlFiltered(), MAX = SIs.tlAll ? 200 : 25, avg = a => a.length ? a.reduce((x, y) => x + y, 0) / a.length : null;
    // TMA: tempo médio até resolver (Zabbix) / até solucionar (GLPI); e tempo médio até alguém assumir
    const zr = all.filter(e => e.src === "z" && !e.open), za = all.filter(e => e.src === "z" && e.ackIn != null);
    const gs = all.filter(e => e.src === "g" && e.ev.tipo === "solucionado" && e.ev.SD && e.ev.D), gk = all.filter(e => e.src === "g" && e.ev.tipo === "aberto" && e.ev.TK && e.ev.D);
    const stat = (l, v, s) => `<div class="tl-st"><span>${l}</span><b>${v == null ? "–" : fmtDur(v)}</b><small>${s}</small></div>`;
    const chips = [["todos", "Todos", all.length], ["z", "Zabbix", all.filter(e => e.src === "z").length], ["g", "GLPI", all.filter(e => e.src === "g").length],
      ["aberto", "Em aberto", all.filter(e => e.open).length], ["semresp", "Sem responsável", all.filter(e => !e.resp).length]];
    const sla = e => { if (e.src === "z") return e.ackIn != null ? `<span class="si-badge ok">reconhecido em ${fmtDur(e.ackIn)}</span>` : e.open ? '<span class="si-badge late">não reconhecido</span>' : '<span class="tag mut">sem reconhecimento</span>';
      const t = e.ev; if (!t.TTR) return '<span class="tag mut">sem SLA</span>';
      const ok = t.SD ? t.SD <= t.TTR : Date.now() / 1000 <= t.TTR;
      return `<span class="si-badge ${ok ? "ok" : "late"}">${t.SD ? (ok ? "SLA cumprido" : "SLA violado") : (ok ? "no prazo" : "SLA atrasado")}</span>${t.prest ? ' <span class="tag mut">prestador</span>' : ""}`; };
    const row = (e, i) => `<tr data-i="${i}" title="Clique para ver os detalhes">
        <td class="when">${fmtDT(e.t)}</td><td>${SRC[e.src]}</td>
        <td>${e.src === "z" ? `<span class="chip sev${e.sev}">${SEVN[e.sev]}</span>` : `<span class="tag ${e.ev.p >= 4 ? "crit" : e.ev.p === 3 ? "warn" : "mut"}">${esc(e.ev.prioridade || "—")}</span><div class="muted">${esc(e.ev.tipo_ch || "")}</div>`}</td>
        <td class="what">${e.src === "g" ? `<span class="tag ${e.ev.tipo === "aberto" ? "warn" : "good"}">${e.ev.tipo === "aberto" ? "aberto" : "solucionado"}</span> <b>#${e.id}</b> ` : ""}${esc(e.what)}</td>
        <td class="who">${esc(short(e.who, 42))}</td>
        <td>${e.resp ? esc(short(e.resp, 30)) : `<span class="muted">${e.src === "z" ? "ninguém reconheceu" : "sem responsável"}</span>`}</td>
        <td>${e.src === "z" ? (e.open ? '<span class="open">em aberto</span>' : "resolvido") : esc(e.ev.status)}</td>
        <td class="num">${e.open ? `<span class="open">há ${fmtDur(e.dur)}</span>` : fmtDur(e.dur)}</td>
        <td>${sla(e)}</td></tr>`;
    SIt.rows = ev;
    return `<div class="tl-sum">${stat("TMA Zabbix", avg(zr.map(e => e.dur)), `tempo médio até resolver · ${zr.length} alertas`)}
        ${stat("Tempo até reconhecer", avg(za.map(e => e.ackIn)), `média no Zabbix · ${za.length} reconhecidos`)}
        ${stat("TMA GLPI", avg(gs.map(e => e.ev.SD - e.ev.D)), `tempo médio até solucionar · ${gs.length} chamados`)}
        ${stat("1º atendimento GLPI", avg(gk.map(e => e.ev.TK - e.ev.D)), `média até assumir · ${gk.length} chamados`)}</div>
      <div class="iachips tl-f">${chips.map(([k, l, n]) => `<button type="button" data-f="${k}" class="${SIt.f === k ? "on" : ""}">${l} (${n})</button>`).join("")}</div>
      <div class="tablewrap tl-tab"><table><thead><tr><th class="nosort">Quando</th><th class="nosort">Origem</th><th class="nosort">Severidade / prioridade</th><th class="nosort">Ocorrência</th>
        <th class="nosort">Ativo / categoria</th><th class="nosort">Responsável</th><th class="nosort">Status</th><th class="nosort num">TMA (duração)</th><th class="nosort">SLA / reconhecimento</th></tr></thead>
        <tbody id="siTl">${ev.slice(0, MAX).map(row).join("") || '<tr><td colspan="9" class="empty">Nada neste filtro.</td></tr>'}</tbody></table></div>
      ${ev.length > MAX ? `<button class="btn more" id="siTlMore" type="button" style="margin-top:8px">Ver mais (${ev.length - MAX})</button>` : ""}`;
  }

  /* ---------- Vulnerabilidades (paliativo pelo Zabbix): atraso das atualizações mensais dos servidores Windows ---------- */
  const SIv = {d: null, f: "", tab: "patch", inv: null, q: "", view: "zx", kind: "srv", open: new Set(), swOpen: new Set()};
  /* ---------- softwares instalados por servidor (inventário do GLPI) ---------- */
  let invTimer = null;
  async function invLoad(force) {
    try { SIv.inv = await api("/api/si/inventario" + (force ? "?atualizar=1" : "")); } catch (e) { SIv.inv = {erro: e.message}; }
    if (!SIv.topo || force) { try { SIv.topo = await api(`/api/topology?group=${encodeURIComponent(G)}`); } catch (e) { SIv.topo = {nodes: []}; } }
    clearTimeout(invTimer);
    if (SIv.inv && (SIv.inv.carregando || SIv.inv.atualizando)) invTimer = setTimeout(() => invLoad(false), 5000);
    if ($("#siVu").classList.contains("on") && SIv.tab === "sw") vuDraw();
    swpRefresh();
  }
  // servidor = sistema de servidor/Linux ou nome de servidor; o resto (notebooks, Macs) são estações de trabalho
  const isSrv = c => /server|linux|ubuntu|debian|centos|red ?hat|rocky|alma/i.test(c.so.nome || "") || /SERVER|SVR|SRV|LNX|PABX|PIVOT|VM\d/i.test(c.nome);
  const invComps = () => (SIv.inv.computadores || []).filter(c => SIv.kind === "est" ? !isSrv(c) : isSrv(c));
  const hKey = n => { const m = /ADVSERVER0*(\d+)/i.exec(n || ""); if (m) return "ADVSERVER" + m[1];
    return String(n || "").replace(/^[A-Za-z]{2,4}\s*\|\s*/, "").split(/\s+-\s+/)[0].replace(/\.advanta\.com\.br$/i, "").trim().toUpperCase(); };
  function zxRows() {
    const nodes = (SIv.topo && SIv.topo.nodes || []).filter(n => ["vm", "fisico", "outros"].includes(n.tier) && !/SENSOR|SDTC|DVR|IMPRESSORA|BROTHER/i.test(n.name));
    const glpi = new Map(); (SIv.inv.computadores || []).forEach(c => { const k = hKey(c.nome), o = glpi.get(k); if (!o || c.softwares.length > o.softwares.length) glpi.set(k, c); });
    const z = new Map(); nodes.forEach(n => { const k = hKey(n.name); if (!z.has(k)) z.set(k, []); z.get(k).push(n); });
    const rows = [...z].map(([k, ns]) => ({k, ns, c: glpi.get(k) || null}));
    const zk = new Set(z.keys()), sem = (SIv.inv.computadores || []).filter(c => isSrv(c) && !zk.has(hKey(c.nome)));
    const so = r => r.c ? (r.c.softwares.length ? 2 : 1) : 0;
    rows.sort((a, b) => so(b) - so(a) || a.k.localeCompare(b.k, "pt-BR", {numeric: true}));
    return {rows, sem};
  }
  const invAge = c => c.atualizado ? (Date.now() - new Date(String(c.atualizado).replace(" ", "T")).getTime()) / 86400000 : null;
  const swMatch = (sw, q) => !q || sw.n.toLowerCase().includes(q) || (sw.f || "").toLowerCase().includes(q) || (sw.v || "").toLowerCase().includes(q);
  function swCatalog(comps) {
    const m = new Map();
    comps.forEach(c => c.softwares.forEach(sw => { const k = sw.n.toLowerCase(); if (!m.has(k)) m.set(k, {n: sw.n, f: sw.f, v: new Map()});
      const e = m.get(k); if (!e.f && sw.f) e.f = sw.f; const vv = sw.v || "–"; if (!e.v.has(vv)) e.v.set(vv, []); e.v.get(vv).push(c.nome); }));
    return [...m.values()].map(e => ({...e, srv: [...e.v.values()].reduce((a, l) => a + l.length, 0)}));
  }
  function swHtml() {
    const inv = SIv.inv;
    if (!inv) { invLoad(false); return '<div class="loading">Buscando o inventário no GLPI…</div>'; }
    if (inv.carregando) return `<div class="loading">Montando o inventário no GLPI: são 2 consultas por computador, então a primeira vez leva até 1 minuto…${inv.erro ? `<div class="err">${esc(inv.erro)}</div>` : ""}</div>`;
    if (!inv.computadores) return `<div class="err">${esc(inv.erro || "Não foi possível ler o inventário do GLPI.")}</div>`;
    const all = inv.computadores, nSrv = all.filter(isSrv).length, cs = invComps(), cat = swCatalog(cs), old = cs.filter(c => (invAge(c) || 0) > 30), multi = cat.filter(e => e.v.size > 1);
    return `<div class="sia-kpi vu-kpi">
        <div><span>${SIv.kind === "est" ? "Estações de trabalho" : "Servidores"} no GLPI</span><b>${cs.length}</b><small>de ${all.length} computadores da entidade</small></div>
        <div><span>Softwares instalados</span><b>${nf(cs.reduce((n, c) => n + c.softwares.length, 0), 0)}</b><small>somando ${SIv.kind === "est" ? "todas as estações" : "todos os servidores"}</small></div>
        <div><span>Softwares diferentes</span><b>${nf(cat.length, 0)}</b></div>
        <div class="atencao"><span>Em mais de uma versão</span><b>${multi.length}</b><small>versões diferentes entre servidores</small></div>
        <div class="${old.length ? "risco" : ""}"><span>Cadastro sem mudança há +30 dias</span><b>${old.length}</b><small>confira se o agente do GLPI está enviando</small></div>
        <div><span>Sem agente de inventário</span><b>${cs.filter(c => !c.agente).length}</b><small>cadastro manual</small></div></div>
      <div class="vu-swbar"><div class="iachips">${[["srv", "🖥 Servidores", nSrv], ["est", "💻 Estações de trabalho", all.length - nSrv]].map(([k, l, n]) => `<button type="button" data-kind="${k}" class="${SIv.kind === k ? "on" : ""}">${l} (${n})</button>`).join("")}</div>
        <input type="search" id="siVuQ" placeholder="Procurar software, fabricante ou versão (ex.: java, 7-zip, openssl)…" value="${esc(SIv.q)}" aria-label="Procurar software">
        <div class="iachips">${[["zx", "Zabbix × GLPI"], ["srv", "Por computador do GLPI"], ["sw", "Por software"]].map(([k, l]) => `<button type="button" data-view="${k}" class="${SIv.view === k ? "on" : ""}">${l}</button>`).join("")}</div>
        <button class="btn" type="button" id="siVuPdf" title="PDF com a ficha completa de cada servidor: Zabbix, GLPI, patches e softwares">📄 Gerar PDF</button>
        <button class="btn" type="button" id="siVuCsv" title="Planilha com todos os softwares de todos os servidores">⬇ Exportar planilha</button>
        <button class="btn" type="button" id="siVuInvR" title="Ler o inventário de novo no GLPI">↻ Reler GLPI</button></div>
      <div class="vu-note" style="margin:0 0 10px">Inventário enviado pelo agente do GLPI de cada servidor. Use a busca para achar em quais servidores está um software com vulnerabilidade anunciada e em qual versão.
        ${inv.gerado ? `Lido do GLPI às ${fmtHour(inv.gerado)}${inv.atualizando ? " · atualizando…" : ""}.` : ""}</div>
      <div id="vuSwList"></div>`;
  }
  function swList() {
    const box = $("#vuSwList"), inv = SIv.inv; if (!box || !inv || !inv.computadores) return;
    const q = SIv.q.trim().toLowerCase(), day = t => t ? new Date(String(t).replace(" ", "T")).toLocaleDateString("pt-BR") : "–";
    if (SIv.view === "zx") {
      const {rows, sem} = zxRows(), ok = rows.filter(r => r.c && r.c.softwares.length).length, noSw = rows.filter(r => r.c && !r.c.softwares.length).length, none = rows.filter(r => !r.c).length;
      const st = n => n.state === "down" ? '<span class="si-badge late">fora do ar</span>' : n.problems ? `<span class="chip sev${n.worst}">${n.problems}</span>` : '<span class="si-badge ok">ok</span>';
      const vis = rows.map(r => ({...r, sw: r.c ? r.c.softwares.filter(x => swMatch(x, q)) : []})).filter(r => !q || r.sw.length);
      box.innerHTML = `<div class="vu-zx-sum"><span><b>${rows.length}</b> servidores no Zabbix</span><span class="ok">✓ <b>${ok}</b> com softwares no GLPI</span>
          <span class="wa">⚠ <b>${noSw}</b> no GLPI sem softwares (sem agente)</span><span class="ba">✕ <b>${none}</b> sem cadastro no GLPI</span><span class="mu"><b>${sem.length}</b> servidores no GLPI que o Zabbix não monitora</span></div>
        ${vis.map(r => { const op = q || SIv.open.has("z:" + r.k), c = r.c;
          return `<section class="vu-srv${op ? " on" : ""}${c ? "" : " miss"}"><button type="button" class="vu-srvh vu-zxh" data-zk="${esc(r.k)}" aria-expanded="${!!op}">
              <span class="nm"><b>${SRC.z} ${esc(mpShort(r.ns[0].name).replace(/^VM\s*\|?\s*/i, "").split(/\s+-\s+/)[0].replace(/\.advanta\.com\.br$/i, ""))}</b><small>${r.ns.map(n => esc(mpShort(n.name))).join(" · ")}</small></span>
              <span>${r.ns.map(st).join(" ")}</span>
              <span class="nm">${c ? `<b>${SRC.g} ${esc(c.nome)}</b><small>${esc([c.so.nome, c.so.versao].filter(Boolean).join(" · ") || "sistema não informado no GLPI")}</small>` : '<span class="si-badge late">não encontrado no GLPI</span>'}</span>
              <span class="ct">${c ? (c.softwares.length ? `<b>${q ? `${r.sw.length} de ${c.softwares.length}` : c.softwares.length}</b> softwares` : '<span class="tag warn">sem softwares</span>') : "–"}</span>
              <span class="chev">${c && c.softwares.length ? (op ? "▾" : "▸") : ""}</span></button>
            ${op && c && c.softwares.length ? `<div class="vu-srvb"><table><thead><tr><th class="nosort">Software</th><th class="nosort">Versão</th><th class="nosort">Fabricante</th></tr></thead><tbody>${r.sw.map(x => `<tr><td>${esc(x.n)}</td><td>${esc(x.v || "–")}</td><td class="muted">${esc(x.f || "–")}</td></tr>`).join("")}</tbody></table>
              <a class="btn" href="${esc(c.url)}" target="_blank" rel="noopener">Abrir no GLPI ↗</a></div>` : ""}</section>`; }).join("") || '<div class="empty">Nenhum servidor do Zabbix tem software com esse nome.</div>'}
        ${!q && sem.length ? `<h4 class="vu-h4">Servidores no inventário do GLPI que não estão no monitoramento do Zabbix</h4><div class="vu-chips">${sem.map(c => `<a class="cz" href="${esc(c.url)}" target="_blank" rel="noopener" title="${esc([c.so.nome, c.softwares.length + " softwares"].filter(Boolean).join(" · "))}">${esc(c.nome)}</a>`).join("")}</div>` : ""}`;
      return;
    }
    if (SIv.view === "sw") {
      const rows = swCatalog(invComps()).filter(e => swMatch({n: e.n, f: e.f, v: [...e.v.keys()].join(" ")}, q)).sort((a, b) => b.srv - a.srv || a.n.localeCompare(b.n));
      const MAX = q ? 2000 : 300;
      box.innerHTML = `<div class="tablewrap vu-tab"><table><thead><tr><th class="nosort">Software</th><th class="nosort">Fabricante</th><th class="nosort num">Servidores</th><th class="nosort">Versões instaladas</th></tr></thead><tbody>
        ${rows.slice(0, MAX).map(e => { const k = e.n.toLowerCase(), op = SIv.swOpen.has(k);
          return `<tr class="vu-swr" data-sw="${esc(k)}"><td><b>${esc(e.n)}</b></td><td class="muted">${esc(e.f || "–")}</td><td class="num">${e.srv}</td>
            <td>${[...e.v.keys()].slice(0, 6).map(v => `<span class="tag ${e.v.size > 1 ? "warn" : "mut"}">${esc(v)}</span>`).join(" ")}${e.v.size > 6 ? ` <small class="muted">+${e.v.size - 6}</small>` : ""}</td></tr>
            ${op ? `<tr class="vu-swd"><td colspan="4">${[...e.v.entries()].map(([v, l]) => `<div><span class="tag ${e.v.size > 1 ? "warn" : "mut"}">${esc(v)}</span> ${l.map(esc).join(", ")}</div>`).join("")}</td></tr>` : ""}`; }).join("")
          || '<tr><td colspan="4" class="empty">Nenhum software encontrado.</td></tr>'}</tbody></table></div>${rows.length > MAX ? `<p class="muted">Mostrando ${MAX} de ${rows.length}. Use a busca para filtrar.</p>` : ""}`;
      return;
    }
    const cards = invComps().map(c => ({c, sw: c.softwares.filter(sw => swMatch(sw, q))})).filter(x => !q || x.sw.length);
    box.innerHTML = cards.map(({c, sw}) => { const age = invAge(c), op = q || SIv.open.has(c.id);
      return `<section class="vu-srv${op ? " on" : ""}" data-cid="${c.id}"><button type="button" class="vu-srvh" data-cid="${c.id}" aria-expanded="${!!op}">
          <span class="nm"><b>${esc(c.nome)}</b><small>${esc([c.so.nome, c.so.versao].filter(Boolean).join(" · ") || "sistema não informado")}</small></span>
          <span class="md">${esc([c.fabricante, c.modelo].filter(Boolean).join(" · "))}</span>
          <span class="ct"><b>${q ? `${sw.length} de ${c.softwares.length}` : c.softwares.length}</b> softwares</span>
          <span>${age != null && age > 30 ? `<span class="si-badge late">inventário de ${day(c.atualizado)}</span>` : `<span class="muted">inventário ${day(c.atualizado)}</span>`}${c.agente ? "" : ' <span class="tag mut">sem agente</span>'}</span>
          <span class="chev">${op ? "▾" : "▸"}</span></button>
        ${op ? `<div class="vu-srvb"><table><thead><tr><th class="nosort">Software</th><th class="nosort">Versão</th><th class="nosort">Fabricante</th></tr></thead><tbody>${sw.map(x => `<tr><td>${esc(x.n)}</td><td>${esc(x.v || "–")}</td><td class="muted">${esc(x.f || "–")}</td></tr>`).join("") || '<tr><td colspan="3" class="empty">Sem softwares no inventário.</td></tr>'}</tbody></table>
          <a class="btn" href="${esc(c.url)}" target="_blank" rel="noopener">Abrir no GLPI ↗</a></div>` : ""}</section>`; }).join("") || '<div class="empty">Nenhum servidor tem software com esse nome.</div>';
  }
  function swCsv() {
    const inv = SIv.inv; if (!inv || !inv.computadores) return;
    if (SIv.view === "zx") {
      const q = v => `"${String(v ?? "").replace(/"/g, '""')}"`, L = [["Servidor (Zabbix)", "Hosts no Zabbix", "Computador no GLPI", "Sistema operacional", "Versão do sistema", "Software", "Versão", "Fabricante"].map(q).join(";")];
      zxRows().rows.forEach(r => { const c = r.c, zs = r.ns.map(n => n.name).join(" / ");
        (c && c.softwares.length ? c.softwares : [{}]).forEach(sw => L.push([mpShort(r.ns[0].name).split(/\s+-\s+/)[0], zs, c ? c.nome : "não encontrado no GLPI", c && c.so.nome, c && c.so.versao, sw.n, sw.v, sw.f].map(q).join(";"))); });
      const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob(["\ufeff" + L.join("\r\n")], {type: "text/csv;charset=utf-8"}));
      a.download = `servidores-zabbix-softwares-glpi-${new Date().toISOString().slice(0, 10)}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000); return;
    }
    const q = v => `"${String(v ?? "").replace(/"/g, '""')}"`, lines = [["Servidor", "Sistema operacional", "Versão do sistema", "Modelo", "Inventário em", "Software", "Versão", "Fabricante"].map(q).join(";")];
    invComps().forEach(c => (c.softwares.length ? c.softwares : [{}]).forEach(sw => lines.push([c.nome, c.so.nome, c.so.versao, c.modelo, c.atualizado, sw.n, sw.v, sw.f].map(q).join(";"))));
    const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob(["\ufeff" + lines.join("\r\n")], {type: "text/csv;charset=utf-8"}));
    a.download = `softwares-${SIv.kind === "est" ? "estacoes" : "servidores"}-${new Date().toISOString().slice(0, 10)}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  }
  const VU_SIT = {atrasado: ["late", "Atrasado"], janela: ["warn", "Patch do mês pendente"], em_dia: ["ok", "Em dia"], sem_dados: ["mut", "Sem dados"]};
  async function vuLoad(show) {
    try { SIv.d = await api(`/api/patches?group=${encodeURIComponent(G)}`); } catch (e) { if (show) $("#siVuC").innerHTML = `<div class="err">${esc(e.message)}</div>`; return; }
    const hs = SIv.d.hosts || [], bad = hs.filter(h => h.situacao === "atrasado" || h.sem_suporte).length, b = $("#siVuBtn .n");
    if (b) { b.textContent = bad || "✓"; b.className = "n" + (bad ? " risco" : hs.some(h => h.situacao === "janela") ? " atencao" : " ok"); }
    $("#siVuBtn").title = `Patches dos servidores Windows · ${bad} com risco (atrasados ou fora de suporte)`;
    if ($("#siVu").classList.contains("on")) vuDraw();
    swpRefresh();
  }
  const vuTabs = () => `<div class="vu-tabs" role="tablist">${[["patch", "🛡️ Patches · Zabbix"], ["sw", "📦 Softwares instalados · GLPI"]].map(([k, l]) => `<button type="button" role="tab" data-tab="${k}" aria-selected="${SIv.tab === k}" class="${SIv.tab === k ? "on" : ""}">${l}</button>`).join("")}</div>`;
  function vuDraw() {
    if (SIv.tab === "sw") { $("#siVuC").innerHTML = vuTabs() + swHtml(); swList(); return; }
    const d = SIv.d; if (!d) { $("#siVuC").innerHTML = vuTabs() + '<div class="loading">Consultando o Zabbix…</div>'; return; }
    const hs = d.hosts || [], N = hs.length, c = k => hs.filter(h => h.situacao === k).length, pct = (a, b) => b ? nf(100 * a / b, 0) + "%" : "–";
    const eol = hs.filter(h => h.sem_suporte), alvo = hs.filter(h => h.alvo).length, conf = c("em_dia") + c("janela");
    const f = SIv.f, rows = hs.filter(h => !f || (f === "eol" ? h.sem_suporte : f === "versao" ? !h.alvo : h.situacao === f));
    const day = t => t ? new Date(t * 1000).toLocaleDateString("pt-BR", {day: "2-digit", month: "2-digit", year: "numeric"}) : "–";
    const now = Date.now() / 1000;
    $("#siVuMeta").innerHTML = `Patch Tuesday mais recente: <b>${day(d.ultimo_pt)}</b> · próximo: <b>${day(d.proximo)}</b> · prazo para aplicar: ${d.carencia_dias} dias`;
    const row = h => { const [cl, tx] = VU_SIT[h.situacao] || VU_SIT.sem_dados, al = (SIs.inc || []).filter(i => i.end == null && i.host === h.name), w = al.reduce((m, i) => Math.max(m, i.sev), -1);
      const last = h.fonte === "kb" ? `<b>${esc(h.kb || "")}</b> em ${day(h.kb_em)}` : h.boot ? `<span title="Estimado: a atualização mensal do Windows exige reinício">reinício em ${day(h.boot)} <small class="muted">(há ${fmtDur(now - h.boot)}, estimado)</small></span>` : "–";
      return `<tr data-host="${esc(h.name)}" title="Clique para ver softwares, patches e atualizações do servidor">
        <td><b>${esc(mpShort(h.name).replace(/^VM\s*\|?\s*/i, ""))}</b></td>
        <td>${logoEl("windows", 14)} ${esc(h.versao || "–")}<div class="muted">build ${h.build || "?"}${h.ubr ? "." + esc(h.ubr) : ""}</div></td>
        <td>${h.sem_suporte ? `<span class="si-badge late">fora de suporte</span><div class="muted">desde ${day(h.fim_suporte)}</div>` : h.fim_suporte ? `<span class="muted">até ${day(h.fim_suporte)}</span>` : "–"}${h.alvo ? "" : ' <span class="tag warn">abaixo da versão alvo</span>'}</td>
        <td>${last}</td>
        <td>${h.reinicio_pendente == null ? '<span class="muted">não coletado</span>' : h.reinicio_pendente ? '<span class="si-badge late">pendente</span>' : '<span class="si-badge ok">não</span>'}</td>
        <td><span class="si-badge ${cl}">${tx}${h.situacao === "atrasado" ? ` · ${h.ciclos} ${h.ciclos === 1 ? "mês" : "meses"}` : ""}</span>${h.wu_desativado ? '<div><span class="tag crit">Windows Update desativado</span></div>' : ""}</td>
        <td>${al.length ? `<span class="chip sev${w}">${al.length}</span>` : '<span class="muted">0</span>'}</td></tr>`; };
    const chips = [["", "Todos", N], ["atrasado", "🔴 Atrasados", c("atrasado")], ["janela", "🟡 Patch do mês pendente", c("janela")], ["em_dia", "🟢 Em dia", c("em_dia")], ["eol", "⛔ Fora de suporte", eol.length], ["versao", "Abaixo da versão alvo", N - alvo]].filter(x => x[0] === "" || x[2]);
    $("#siVuC").innerHTML = vuTabs() + `
      <div class="sia-sum ${c("atrasado") || eol.length ? "critico" : c("janela") ? "atencao" : ""}"><span style="font-size:22px">🛡️</span><div><b>${c("atrasado") || eol.length ? [c("atrasado") ? `${c("atrasado")} servidor${c("atrasado") === 1 ? "" : "es"} com patch atrasado` : "", eol.length ? `${eol.length} servidor${eol.length === 1 ? "" : "es"} fora do suporte da Microsoft (${eol.map(h => esc(mpShort(h.name).replace(/^VM\s*\|?\s*/i, "")) + " · " + esc(h.versao)).join(", ")})` : ""].filter(Boolean).join(" e ") : c("janela") ? `${c("janela")} servidor${c("janela") === 1 ? "" : "es"} ainda sem o patch de ${new Date(d.ultimo_pt * 1000).toLocaleDateString("pt-BR", {month: "long"})}, dentro do prazo` : "Todos os servidores Windows estão com o patch do mês aplicado"}</b>
        <p>Paliativo, sem lista de CVEs: mede o atraso das atualizações mensais da Microsoft (Patch Tuesday, 2ª terça-feira). Servidor fora de suporte não recebe mais correções de segurança.</p></div></div>
      ${d.coleta_detalhada ? "" : `<div class="vu-note"><b>Coleta detalhada ainda não ativada no Zabbix.</b> Por enquanto a data do patch é <b>estimada pelo último reinício</b>, já que a atualização mensal do Windows exige reiniciar. Com 4 itens a mais nos servidores, o painel passa a mostrar o KB e a data do último patch, o build exato, o reinício pendente e se o Windows Update está desativado.
        <details><summary>Itens que serão criados no Zabbix</summary><ul>${Object.entries(d.chaves || {}).map(([k, v]) => `<li><code>${esc(v)}</code></li>`).join("")}</ul></details></div>`}
      <div class="sia-kpi vu-kpi">
        <div><span>Servidores Windows</span><b>${N}</b></div>
        <div class="melhora"><span>🟢 Patch do mês aplicado</span><b>${c("em_dia")}</b><small>${pct(c("em_dia"), N)}</small></div>
        <div class="atencao"><span>🟡 Pendente, no prazo</span><b>${c("janela")}</b></div>
        <div class="risco"><span>🔴 Atrasados</span><b>${c("atrasado")}</b></div>
        <div class="risco"><span>⛔ Fora de suporte</span><b>${eol.length}</b></div>
        <div><span>Versão alvo (${esc(d.alvo_txt || "")}+)</span><b>${alvo}/${N}</b><small>${pct(alvo, N)}</small></div></div>
      <div class="vu-book">📊 Para o Resumo operacional: <b>Atualização de Softwares e Sistemas</b> = ${pct(conf, N)} <small>(em dia + no prazo)</small> · <b>Atualização de versão dos Servidores</b> = ${pct(alvo, N)}</div>
      <div class="iachips" style="margin:6px 0 10px">${chips.map(([k, l, n]) => `<button type="button" data-f="${k}" class="${f === k ? "on" : ""}">${l} (${n})</button>`).join("")}</div>
      <div class="tablewrap vu-tab"><table><thead><tr><th class="nosort">Servidor</th><th class="nosort">Sistema</th><th class="nosort">Suporte Microsoft</th><th class="nosort">Último patch</th><th class="nosort">Reinício pendente</th><th class="nosort">Situação</th><th class="nosort">Alertas</th></tr></thead>
        <tbody>${rows.map(row).join("") || '<tr><td colspan="7" class="empty">Nenhum servidor neste filtro.</td></tr>'}</tbody></table></div>`;
  }
  {
    const m = document.createElement("div"); m.id = "siVu"; m.className = "sia"; m.setAttribute("role", "dialog"); m.setAttribute("aria-modal", "true"); m.setAttribute("aria-label", "Vulnerabilidades");
    m.innerHTML = `<div class="bg" id="siVuBg"></div><div class="box"><div class="hd"><span class="vu-ic" aria-hidden="true">🛡️</span><div class="tt"><h2>Vulnerabilidades · patches dos servidores Windows</h2><div class="sub" id="siVuMeta"></div></div>
      <button class="btn" id="siVuR" type="button" title="Consultar o Zabbix de novo">↻ Atualizar</button><button class="btn" id="siVuX" type="button" title="Fechar (Esc)">✕ Fechar</button></div><div class="ct" id="siVuC"></div></div>`;
    document.body.appendChild(m);
    const bt = document.createElement("button"); bt.className = "btn si-iabtn si-vubtn"; bt.id = "siVuBtn"; bt.type = "button";
    bt.innerHTML = `<span class="vu-ic" aria-hidden="true">🛡️</span>Vulnerabilidades<span class="n">–</span>`;
    const ctr = document.querySelector(".topbar .controls"); if (ctr) ctr.insertBefore(bt, ctr.firstChild);
    const close = () => m.classList.remove("on");
    bt.onclick = () => { m.classList.add("on"); vuDraw(); vuLoad(true); };
    $("#siVuX").onclick = close; $("#siVuBg").onclick = close; $("#siVuR").onclick = () => { $("#siVuC").innerHTML = '<div class="loading">Consultando o Zabbix…</div>'; vuLoad(true); };
    addEventListener("keydown", e => { if (e.key === "Escape" && m.classList.contains("on") && !document.querySelector("#drawer.on")) close(); });
    $("#siVuC").addEventListener("input", e => { if (e.target.id === "siVuQ") { SIv.q = e.target.value; swList(); } });
    $("#siVuC").addEventListener("click", e => {
      const tb = e.target.closest("[data-tab]"); if (tb) { SIv.tab = tb.dataset.tab; return vuDraw(); }
      const kd = e.target.closest("[data-kind]"); if (kd) { SIv.kind = kd.dataset.kind; SIv.open.clear(); SIv.swOpen.clear(); return vuDraw(); }
      const vw = e.target.closest("[data-view]"); if (vw) { SIv.view = vw.dataset.view; vuDraw(); return; }
      if (e.target.closest("#siVuCsv")) return swCsv();
      if (e.target.closest("#siVuPdf")) return swPdf();
      if (e.target.closest("#siVuInvR")) { SIv.inv = {carregando: true}; vuDraw(); return invLoad(true); }
      const zh = e.target.closest(".vu-zxh"); if (zh) { const id = "z:" + zh.dataset.zk; SIv.open.has(id) ? SIv.open.delete(id) : SIv.open.add(id); return swList(); }
      const sh = e.target.closest(".vu-srvh"); if (sh) { const id = +sh.dataset.cid; SIv.open.has(id) ? SIv.open.delete(id) : SIv.open.add(id); return swList(); }
      const sr = e.target.closest(".vu-swr"); if (sr) { const k = sr.dataset.sw; SIv.swOpen.has(k) ? SIv.swOpen.delete(k) : SIv.swOpen.add(k); return swList(); }
      if (e.target.closest("a")) return;
      const fb = e.target.closest("[data-f]"); if (fb) { SIv.f = fb.dataset.f; return vuDraw(); }
      if (e.target.closest("details")) return;
      const r = e.target.closest("tr[data-host]"); if (r) openServerSw(r.dataset.host);
    });
    setTimeout(() => vuLoad(false), 2500); setInterval(() => vuLoad(false), 10 * 60 * 1000);
  }

  /* ---------- visão do servidor: softwares, patches e atualizações (inventário do GLPI) + situação do patch (Zabbix) ----------
     Entra no topo de toda ficha de host (lista de hosts, mapa da topologia) e na janela aberta pela tabela de patches. */
  const SWP = {tab: {}, q: {}};
  const isPatch = n => /\bKB\d{6,8}\b|Security Update|Update for|Atualiza[çc][ãa]o|Hotfix|Cumulative|Service Pack|Servicing Stack/i.test(n);
  const kbNum = n => +((/KB(\d{6,8})/i.exec(n) || [])[1] || 0);
  const looksSrv = n => /ADVSERVER|^(VM|SRV)\s*\|/i.test(n || "");
  function swPanel(host) {
    const key = hKey(host), inv = SIv.inv, day = t => t ? new Date(typeof t === "number" ? t * 1000 : String(t).replace(" ", "T")).toLocaleDateString("pt-BR") : "–";
    if (!inv || inv.carregando || !inv.computadores) return `<div class="swp-h">📦 Softwares e patches do servidor</div><div class="loading">Lendo o inventário do GLPI…</div>`;
    let c = null; inv.computadores.forEach(x => { if (hKey(x.nome) === key && (!c || x.softwares.length > c.softwares.length)) c = x; });
    const pz = ((SIv.d && SIv.d.hosts) || []).find(h => h.name === host || hKey(h.name) === key);
    const [pcl, ptx] = pz ? (VU_SIT[pz.situacao] || VU_SIT.sem_dados) : [];
    const zb = pz ? `<div class="swp-z">${SRC.z}<span>Patch do mês: <span class="si-badge ${pcl}">${ptx}${pz.situacao === "atrasado" ? ` · ${pz.ciclos} ${pz.ciclos === 1 ? "mês" : "meses"}` : ""}</span></span>
        <span>${esc(pz.versao || "")}${pz.build ? ` · build ${pz.build}${pz.ubr ? "." + esc(pz.ubr) : ""}` : ""}</span>
        <span>${pz.sem_suporte ? '<span class="si-badge late">fora do suporte da Microsoft</span>' : pz.fim_suporte ? `suporte até ${day(pz.fim_suporte)}` : ""}</span>
        <span>${pz.fonte === "kb" ? `último KB: <b>${esc(pz.kb)}</b> em ${day(pz.kb_em)}` : pz.boot ? `último reinício: ${day(pz.boot)} <small class="muted">(estimativa do patch)</small>` : ""}</span>
        ${pz.reinicio_pendente ? '<span class="si-badge late">reinício pendente</span>' : ""}</div>` : "";
    if (!c) return `<div class="swp-h">📦 Softwares e patches do servidor</div>${zb}<div class="vu-note" style="margin:6px 0 0">Este servidor <b>não foi encontrado no inventário do GLPI</b> (nome ${esc(key)}). Sem o agente do GLPI não há lista de softwares.</div>`;
    const all = c.softwares, pt = all.filter(x => isPatch(x.n)).sort((a, b) => kbNum(b.n) - kbNum(a.n) || a.n.localeCompare(b.n)), sw = all.filter(x => !isPatch(x.n));
    const tab = SWP.tab[key] || "sw", q = (SWP.q[key] || "").trim().toLowerCase(), list = (tab === "pt" ? pt : sw).filter(x => swMatch(x, q));
    const lastKb = pt.find(x => kbNum(x.n));
    return `<div class="swp-h">📦 Softwares e patches do servidor <button class="btn swp-pdf" type="button" data-pdf="${esc(host)}" title="PDF com a ficha completa deste servidor: Zabbix, GLPI, patches e softwares">📄 PDF</button><a class="btn" href="${esc(c.url)}" target="_blank" rel="noopener">Abrir no GLPI ↗</a></div>
      <div class="swp-g">${SRC.g}<b>${esc(c.nome)}</b><span>${esc([c.so.nome, c.so.versao].filter(Boolean).join(" · ") || "sistema não informado")}</span><span class="muted">${esc([c.fabricante, c.modelo].filter(Boolean).join(" · "))}</span><span class="muted">cadastro atualizado em ${day(c.atualizado)}</span></div>
      ${zb}
      ${all.length ? `<div class="swp-k"><div><span>Softwares</span><b>${sw.length}</b></div><div><span>Patches e atualizações</span><b>${pt.length}</b></div><div><span>KB mais recente instalado</span><b>${lastKb ? "KB" + kbNum(lastKb.n) : "–"}</b></div></div>
      <div class="swp-bar"><div class="iachips">${[["sw", `Softwares (${sw.length})`], ["pt", `Patches e atualizações (${pt.length})`]].map(([k, l]) => `<button type="button" data-swt="${k}" data-swk="${esc(key)}" class="${tab === k ? "on" : ""}">${l}</button>`).join("")}</div>
        <input type="search" class="swp-q" data-swk="${esc(key)}" placeholder="Filtrar por nome, versão ou fabricante…" value="${esc(SWP.q[key] || "")}" aria-label="Filtrar softwares"></div>
      <div class="swp-t"><table><thead><tr><th class="nosort">${tab === "pt" ? "Patch / atualização" : "Software"}</th><th class="nosort">Versão</th><th class="nosort">Fabricante</th></tr></thead><tbody>
        ${list.map(x => `<tr><td>${esc(x.n)}</td><td>${esc(x.v && x.v !== "Unavailable" ? x.v : "–")}</td><td class="muted">${esc(x.f || "–")}</td></tr>`).join("") || '<tr><td colspan="3" class="empty">Nada com esse filtro.</td></tr>'}</tbody></table></div>`
      : '<div class="vu-note" style="margin:6px 0 0">O servidor está no GLPI, mas <b>sem nenhum software no inventário</b>: o agente do GLPI não está instalado ou não está enviando dados.</div>'}`;
  }
  const swpFill = el => { el.innerHTML = swPanel(el.dataset.host); el.dataset.done = "1"; };
  function swpRefresh() { document.querySelectorAll(".si-swp").forEach(swpFill); }
  // toda ficha de host ganha o painel no topo (o painel do Zabbix desenha a ficha com hostSheet)
  {
    const orig = hostSheet;
    hostSheet = h => (h && looksSrv(h.name || h.host) ? `<section class="si-swp card" data-host="${esc(h.name || h.host)}"></section>` : "") + orig(h);
    new MutationObserver(() => { const els = document.querySelectorAll(".si-swp:not([data-done])"); if (!els.length) return;
      if (!SIv.inv || SIv.inv.carregando) invLoad(false); if (!SIv.d) vuLoad(false); els.forEach(swpFill); }).observe(document.body, {childList: true, subtree: true});
    document.addEventListener("click", e => {
      const pf = e.target.closest("[data-pdf]"); if (pf) return swPdf(pf.dataset.pdf);
      const t = e.target.closest("[data-swt]"); if (t) { SWP.tab[t.dataset.swk] = t.dataset.swt; const p = t.closest(".si-swp"); if (p) swpFill(p); }
    });
    document.addEventListener("input", e => {
      const i = e.target.closest(".swp-q"); if (!i) return; SWP.q[i.dataset.swk] = i.value;
      const p = i.closest(".si-swp"), tb = p && p.querySelector(".swp-t"); if (!tb) return;
      const tmp = document.createElement("div"); tmp.innerHTML = swPanel(p.dataset.host); const nt = tmp.querySelector(".swp-t"); if (nt) tb.replaceWith(nt);
    });
    // janela própria (aberta pela tabela de patches)
    const m = document.createElement("div"); m.id = "siSw"; m.className = "sia"; m.setAttribute("role", "dialog"); m.setAttribute("aria-modal", "true");
    m.innerHTML = `<div class="bg" id="siSwBg"></div><div class="box"><div class="hd"><span class="vu-ic" aria-hidden="true">🖥️</span><div class="tt"><h2 id="siSwT"></h2><div class="sub">softwares, patches e atualizações · inventário do GLPI e Zabbix</div></div>
      <button class="btn" id="siSwA" type="button">Alertas do Zabbix</button><button class="btn" id="siSwX" type="button" title="Fechar (Esc)">✕ Fechar</button></div><div class="ct"><section class="si-swp" id="siSwP"></section></div></div>`;
    document.body.appendChild(m);
    const close = () => m.classList.remove("on");
    $("#siSwX").onclick = close; $("#siSwBg").onclick = close;
    $("#siSwA").onclick = () => { const h = m.dataset.host; close(); openDrill({kind: "events", host: h, group: "", title: `Alertas · ${h}`}); };
    addEventListener("keydown", e => { if (e.key === "Escape" && m.classList.contains("on") && !document.querySelector("#drawer.on")) { e.stopImmediatePropagation(); close(); } }, true);
    window.openServerSw = host => { m.dataset.host = host; $("#siSwT").textContent = mpShort(host); const p = $("#siSwP"); p.dataset.host = host; delete p.dataset.done; swpFill(p); m.classList.add("on");
      if (!SIv.inv || SIv.inv.carregando) invLoad(false); };
  }

  /* ---------- PDF dos softwares dos servidores (jsPDF, carregado só quando pedido) ---------- */
  const JS = {};
  const loadJs = src => JS[src] || (JS[src] = new Promise((ok, err) => { const sc = document.createElement("script"); sc.src = src; sc.onload = ok;
    sc.onerror = () => { delete JS[src]; sc.remove(); err(new Error("não foi possível carregar o gerador de PDF (sem internet?)")); }; document.head.appendChild(sc); }));
  async function swPdf(only) {
    if (!SIv.inv || !SIv.inv.computadores) { toast("O inventário do GLPI ainda está carregando. Tente de novo em instantes.", "", 5000); return; }
    toast("Gerando o PDF: buscando a ficha de cada servidor no Zabbix…", "", 6000);
    try {
      await loadJs("https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js");
      await loadJs("https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js");
    } catch (e) { toast(esc(e.message), "", 6000); return; }
    if (!SIv.topo) { try { SIv.topo = await api(`/api/topology?group=${encodeURIComponent(G)}`); } catch (e) { SIv.topo = {nodes: []}; } }
    const {jsPDF} = window.jspdf, doc = new jsPDF({orientation: "landscape", unit: "pt", format: "a4"}), W = doc.internal.pageSize.getWidth();
    const day = t => t ? new Date(typeof t === "number" ? t * 1000 : String(t).replace(" ", "T")).toLocaleDateString("pt-BR") : "–";
    const GREEN = [31, 122, 82], DARK = [11, 26, 18], BLUE = [57, 135, 229], RED = [208, 59, 59];
    const pzOf = (host, key) => ((SIv.d && SIv.d.hosts) || []).find(h => h.name === host || hKey(h.name) === key);
    const label = n => mpShort(n).replace(/^VM\s*\|?\s*/i, "").split(/\s+-\s+/)[0].replace(/\.advanta\.com\.br$/i, "");
    let rows = zxRows().rows.map(r => ({label: label(r.ns[0].name), ns: r.ns, c: r.c, pz: pzOf(r.ns[0].name, r.k), k: r.k}));
    if (only) rows = rows.filter(r => r.k === hKey(only));
    if (!rows.length && only) { const c = (SIv.inv.computadores || []).filter(x => hKey(x.nome) === hKey(only)).sort((a, b) => b.softwares.length - a.softwares.length)[0];
      rows = [{label: label(only), ns: (SIv.topo.nodes || []).filter(n => n.name === only), c: c || null, pz: pzOf(only, hKey(only)), k: hKey(only)}]; }
    // ficha completa de cada host no Zabbix (4 consultas por vez)
    const H = {}, ids = rows.flatMap(r => r.ns.map(n => n.id));
    for (let i = 0; i < ids.length; i += 4) await Promise.all(ids.slice(i, i + 4).map(id => api(`/api/host/${encodeURIComponent(id)}?period=30d`).then(h => { H[id] = h; }).catch(() => {})));
    const sit = pz => pz ? (VU_SIT[pz.situacao] || VU_SIT.sem_dados)[1] + (pz.situacao === "atrasado" ? ` (${pz.ciclos} ${pz.ciclos === 1 ? "mês" : "meses"})` : "") + (pz.sem_suporte ? " · fora de suporte" : "") : "–";
    const lastKb = c => { const k = (c ? c.softwares : []).map(x => kbNum(x.n)).filter(Boolean).sort((a, b) => b - a)[0]; return k ? "KB" + k : "–"; };
    const head = (t, sub) => { doc.setFillColor(...DARK); doc.rect(0, 0, W, 54, "F"); doc.setTextColor(255); doc.setFont("helvetica", "bold"); doc.setFontSize(15); doc.text(t, 30, 26);
      doc.setFont("helvetica", "normal"); doc.setFontSize(9.5); doc.setTextColor(190, 214, 200); doc.text(doc.splitTextToSize(sub, W - 60)[0], 30, 42); doc.setTextColor(20); };
    const y = () => doc.lastAutoTable ? doc.lastAutoTable.finalY + 14 : 70;
    const sec = (t, color = GREEN) => { let yy = y(); if (yy > doc.internal.pageSize.getHeight() - 70) { doc.addPage(); yy = 40; } doc.setFont("helvetica", "bold"); doc.setFontSize(11); doc.setTextColor(...color); doc.text(t, 30, yy + 4); doc.setTextColor(20); doc.setFont("helvetica", "normal"); return yy + 12; };
    const kv = (title, pairs, color) => { pairs = pairs.filter(p => p[1] != null && p[1] !== "" && p[1] !== "–"); if (!pairs.length) return; const st = sec(title, color);
      const half = Math.ceil(pairs.length / 2), body = Array.from({length: half}, (_, i) => [pairs[i][0], String(pairs[i][1]), pairs[i + half] ? pairs[i + half][0] : "", pairs[i + half] ? String(pairs[i + half][1]) : ""]);
      doc.autoTable({startY: st, margin: {left: 30, right: 30}, theme: "grid", styles: {fontSize: 8.5, cellPadding: 3.5, lineColor: [220, 228, 223]}, body,
        columnStyles: {0: {fontStyle: "bold", cellWidth: 130, fillColor: [242, 247, 244]}, 1: {cellWidth: (W - 60) / 2 - 130}, 2: {fontStyle: "bold", cellWidth: 130, fillColor: [242, 247, 244]}}}); };
    const tbl = (title, h, body, color = GREEN, cs) => { if (!body.length) return; const st = sec(title, color);
      doc.autoTable({startY: st, margin: {left: 30, right: 30}, styles: {fontSize: 8, cellPadding: 3}, headStyles: {fillColor: color}, columnStyles: cs || {}, head: [h], body}); };
    const ifc = i => `${{1: "Agente", 2: "SNMP", 3: "IPMI", 4: "JMX"}[i.type] || "Interface"} ${i.ip || i.dns || i.address || ""}${i.port ? ":" + i.port : ""} · ${{1: "disponível", 2: "indisponível"}[String(i.available)] || "desconhecido"}`;
    const gen = new Date().toLocaleString("pt-BR");
    head(`${CFG.title} · ${only ? "ficha do servidor" : "fichas dos servidores"}`, `Zabbix (últimos 30 dias) + inventário do GLPI${SIv.inv.gerado ? " lido às " + fmtHour(SIv.inv.gerado) : ""} · gerado em ${gen}`);
    doc.setFontSize(10.5);
    if (!only) doc.text(`${rows.length} servidores no Zabbix · ${rows.filter(r => r.c && r.c.softwares.length).length} com softwares no GLPI · ${rows.filter(r => r.c && !r.c.softwares.length).length} no GLPI sem softwares (sem agente) · ${rows.filter(r => !r.c).length} sem cadastro no GLPI`, 30, 74);
    doc.autoTable({startY: only ? 66 : 84, margin: {left: 30, right: 30}, styles: {fontSize: 8.5, cellPadding: 4}, headStyles: {fillColor: GREEN},
      head: [["Servidor", "Situação (Zabbix)", "Disponib. 30d", "Problemas ativos", "Sistema operacional", "Softwares", "Patches", "KB mais recente", "Patch do mês", "Computador no GLPI"]],
      body: rows.map(r => { const c = r.c, n = c ? c.softwares.filter(x => isPatch(x.n)).length : 0, hs = r.ns.map(x => H[x.id]).filter(Boolean);
        const down = r.ns.some(x => x.state === "down"), pr = hs.reduce((a, h) => a + (h.problems || []).filter(p => p.end == null).length, 0), av = hs.map(h => (h.stats || {}).avail).filter(v => v != null);
        return [r.label, down ? "fora do ar" : "online", av.length ? nf(Math.min(...av), 2) + "%" : "–", String(pr), c ? [c.so.nome, c.so.versao].filter(Boolean).join(" ") || "–" : (r.pz && r.pz.versao) || "–",
          c ? String(c.softwares.length - n) : "–", c ? String(n) : "–", lastKb(c), sit(r.pz), c ? c.nome : "não encontrado"]; })});
    for (const r of rows) {
      const c = r.c, pz = r.pz;
      doc.addPage(); doc.lastAutoTable = null;
      head(`${r.label}${c && c.so.nome ? " · " + c.so.nome : ""}`, `${r.ns.map(n => n.name).join(" · ")}${c ? " · GLPI: " + c.nome : ""}`);
      // Zabbix: uma ficha por host (o servidor físico tem iDRAC, ESXi, host físico e sistema)
      for (const n of r.ns) {
        const h = H[n.id] || {}, st = h.stats || {}, upt = n.uptime ? fmtDur(n.uptime + Math.max(0, Date.now() / 1000 - (n.uptime_at || Date.now() / 1000))) : "";
        kv(`Zabbix · ${n.name}`, [
          ["Situação", {up: "online", down: "fora do ar", maintenance: "em manutenção"}[h.state || n.state] || h.state || n.state], ["Monitoramento", h.status], ["Nome técnico", h.technical], ["Descrição", h.description],
          ["Grupos", (h.groups || []).join(", ")], ["Proxy", h.proxy], ["Interfaces", (h.interfaces || []).map(ifc).join(" | ")], ["Ligado há", upt],
          ["Sistema", n.descr || ""], ["Modelo / fabricante", [n.vendor, n.model].filter(Boolean).join(" ")],
          ["Disponibilidade 30d", st.avail != null ? nf(st.avail, 3) + "%" : ""], ["Quedas 30d", st.outages != null ? `${st.outages} · ${fmtDur(st.downtime || 0)} fora` : ""],
          ["Maior queda / MTTR", st.longest ? `${fmtDur(st.longest)} / ${fmtDur(st.mttr || 0)}` : ""], ["CPU / memória", st.cpu != null ? `${nf(st.cpu, 1)}% / ${nf(st.mem, 1)}%` : ""],
          ["Latência / perda", st.ping != null ? `${nf(st.ping, 1)} ms / ${nf(st.loss || 0, 1)}%` : ""], ["Problemas no período", st.problems_total],
          ["Tags", (h.tags || []).map(t => t.value ? `${t.tag}: ${t.value}` : t.tag).join(", ")], ...(h.info || []).map(i => [i.name, `${i.value}${i.units ? " " + i.units : ""}`].map(String)),
          ["Inventário Zabbix", Object.entries(h.inventory || {}).filter(([k]) => k !== "name").map(([k, v]) => `${k}: ${v}`).join(", ")]]);
        const ab = (h.problems || []).filter(p => p.end == null);
        tbl(`Problemas ativos · ${label(n.name)} (${ab.length})`, ["Severidade", "Problema", "Desde", "Há", "Reconhecido"], ab.map(p => [SEVN[p.sev] || p.sev, p.name, fmtDT(p.start), fmtDur(Date.now() / 1000 - p.start), p.ack ? "sim" : "não"]), RED, {1: {cellWidth: 420}});
        const hist = (h.history || []).slice().sort((a, b) => b.start - a.start).slice(0, 40);
        tbl(`Alertas nos últimos 30 dias · ${label(n.name)} (${(h.history || []).length}${(h.history || []).length > 40 ? ", os 40 mais recentes" : ""})`, ["Severidade", "Alerta", "Início", "Fim", "Duração"],
          hist.map(p => [SEVN[p.sev] || p.sev, p.name, fmtDT(p.start), p.end ? fmtDT(p.end) : "em aberto", fmtDur((p.end || Date.now() / 1000) - p.start)]), [110, 125, 118], {1: {cellWidth: 400}});
      }
      // patches do Windows (Zabbix)
      if (pz) kv("Patches do Windows (Zabbix)", [["Patch do mês", sit(pz)], ["Versão", `${pz.versao || ""}${pz.build ? " · build " + pz.build + (pz.ubr ? "." + pz.ubr : "") : ""}`],
        ["Versão alvo", pz.alvo ? "sim" : "não (abaixo do Windows Server 2022)"], ["Fim do suporte Microsoft", pz.fim_suporte ? day(pz.fim_suporte) + (pz.sem_suporte ? " (já encerrado)" : "") : ""],
        ["Último patch", pz.fonte === "kb" ? `${pz.kb} em ${day(pz.kb_em)}` : pz.boot ? `estimado pelo reinício de ${day(pz.boot)}` : ""],
        ["Reinício pendente", pz.reinicio_pendente == null ? "não coletado" : pz.reinicio_pendente ? "sim" : "não"]], BLUE);
      // GLPI: hardware, sistema e inventário
      if (c) {
        kv("GLPI · computador", [["Nome no GLPI", c.nome], ["Entidade", c.entidade], ["Fabricante", c.fabricante], ["Modelo", c.modelo], ["Tipo", c.tipo], ["Número de série", c.serial],
          ["Sistema operacional", c.so.nome], ["Versão do sistema", c.so.versao], ["Arquitetura", c.so.arquitetura], ["Edição", c.so.edicao],
          ["Agente de inventário", c.agente ? "sim" : "não (cadastro manual)"], ["Cadastro atualizado em", day(c.atualizado)], ["Endereço no GLPI", c.url]], BLUE);
        const pt = c.softwares.filter(x => isPatch(x.n)).sort((a, b) => kbNum(b.n) - kbNum(a.n)), sw = c.softwares.filter(x => !isPatch(x.n)), val = v => v && v !== "Unavailable" ? v : "–";
        if (!c.softwares.length) { const st = sec("Softwares"); doc.setFontSize(9); doc.text("O computador está no GLPI, mas sem nenhum software no inventário (o agente do GLPI não está enviando dados).", 30, st + 4); doc.lastAutoTable = {finalY: st + 10}; }
        tbl(`Softwares instalados (${sw.length})`, ["Software", "Versão", "Fabricante"], sw.map(x => [x.n, val(x.v), x.f || "–"]), GREEN, {0: {cellWidth: 380}});
        tbl(`Patches e atualizações instalados (${pt.length}) · KB mais recente: ${lastKb(c)}`, ["Patch / atualização", "Versão", "Fabricante"], pt.map(x => [x.n, val(x.v), x.f || "–"]), BLUE, {0: {cellWidth: 380}});
      } else { const st = sec("GLPI", BLUE); doc.setFontSize(9); doc.text(`Servidor não encontrado no inventário do GLPI (nome ${r.k}): sem dados de hardware nem lista de softwares.`, 30, st + 4); doc.lastAutoTable = {finalY: st + 10}; }
    }
    const n = doc.getNumberOfPages();
    for (let i = 1; i <= n; i++) { doc.setPage(i); doc.setFontSize(8); doc.setTextColor(120); doc.text(`Oakmont · ${CFG.title} · página ${i} de ${n}`, W - 30, doc.internal.pageSize.getHeight() - 16, {align: "right"}); }
    doc.save(only ? `ficha-${(rows[0] ? rows[0].label : hKey(only)).toLowerCase().replace(/[^a-z0-9]+/g, "-")}-${new Date().toISOString().slice(0, 10)}.pdf` : `fichas-servidores-${new Date().toISOString().slice(0, 10)}.pdf`);
    toast("PDF gerado.", "", 3000);
  }

  /* ---------- hardware do servidor físico: processador, memória, discos, energia (iDRAC + VMware no Zabbix) ---------- */
  const fB = (v, base = 1000) => { if (v == null) return "–"; const u = base === 1024 ? ["B", "KB", "MB", "GB", "TB"] : ["B", "KB", "MB", "GB", "TB"]; let i = 0; while (v >= base && i < 4) { v /= base; i++; } return nf(v, v >= 100 ? 0 : 1) + " " + u[i]; };
  const stB = t => !t ? "–" : /^(ok|online|pronta|verde|normal)/i.test(t) ? `<span class="si-badge ok">${esc(t)}</span>` : /crít|falh|irrecup|vermelh|alerta|degrad|ausente/i.test(t) ? `<span class="si-badge late">${esc(t)}</span>` : `<span class="si-badge warn">${esc(t)}</span>`;
  const bar = (p, w = 120) => p == null ? "" : `<span class="hw-bar" style="width:${w}px"><i style="width:${Math.min(100, Math.max(1, p))}%;background:${p >= 90 ? "var(--crit)" : p >= 75 ? "var(--warn)" : "var(--good)"}"></i></span>`;
  async function openHw(name, ids) {
    const m = $("#siHw"); m.classList.add("on"); $("#siHwT").textContent = `${name} · hardware`; $("#siHwS").textContent = "consultando o Zabbix…"; $("#siHwC").innerHTML = '<div class="loading">Buscando os dados do iDRAC e do VMware…</div>';
    let d; try { d = await api(`/api/hw?hosts=${encodeURIComponent(ids)}`); } catch (e) { $("#siHwC").innerHTML = `<div class="err">${esc(e.message)}</div>`; return; }
    if (!m.classList.contains("on")) return;
    const id = d.identificacao || {}, cpu = d.processador || {}, mem = d.memoria || {}, dk = d.discos || {}, en = d.energia || {};
    const dsData = (dk.datastores || []).slice().sort((a, b) => (b.total || 0) - (a.total || 0))[0];
    $("#siHwS").textContent = [id.fabricante, id.modelo, id.service_tag ? "service tag " + id.service_tag : "", id.esxi, id.vms != null ? `${id.vms} VMs` : "", id.ligado_s ? "ligado há " + fmtDur(id.ligado_s) : ""].filter(Boolean).join(" · ");
    const k = (l, v, sub, cls = "") => `<div class="${cls}"><span>${l}</span><b>${v}</b>${sub ? `<small>${sub}</small>` : ""}</div>`;
    const kvT = rows => `<table class="hw-kv">${rows.filter(r => r[1] != null && r[1] !== "" && r[1] !== "–").map(([a, b]) => `<tr><td>${a}</td><td>${b}</td></tr>`).join("")}</table>`;
    const pc = v => v == null ? "–" : nf(v, 1) + "%", cl = v => v == null ? "" : v >= 90 ? "risco" : v >= 75 ? "atencao" : "melhora";
    const maxT = (cpu.temperaturas || []).reduce((a, t) => Math.max(a, t.valor || 0), 0);
    $("#siHwC").innerHTML = `
      <div class="sia-kpi hw-kpi">
        ${k("Processador · uso", pc(cpu.uso_pct), cpu.uso_hz ? `${nf(cpu.uso_hz / 1e9, 1)} GHz em uso` : "", cl(cpu.uso_pct))}
        ${k("Memória · uso", pc(mem.uso_pct), mem.total ? `${fB(mem.usada, 1024)} de ${fB(mem.total, 1024)}` : "", cl(mem.uso_pct))}
        ${dsData ? k(`Datastore ${esc(dsData.nome)} · uso`, pc(dsData.livre_pct != null ? 100 - dsData.livre_pct : null), `${fB(dsData.usado)} de ${fB(dsData.total)}`, cl(dsData.livre_pct != null ? 100 - dsData.livre_pct : null)) : ""}
        ${k("Temperatura da CPU", maxT ? nf(maxT, 0) + " °C" : "–", "", maxT >= 85 ? "risco" : maxT >= 75 ? "atencao" : "")}
        ${k("Saúde do hardware", esc(id.saude_geral || "–"), `VMware: ${esc(id.status_vmware || "–")}`, id.saude_geral && id.saude_geral !== "ok" ? "risco" : "melhora")}
        ${k("Consumo de energia", id.energia_w != null ? nf(id.energia_w, 0) + " W" : "–", "agora")}</div>
      <div class="hw-grid">
        <section class="card"><h3>🧠 Processador</h3>${kvT([["Modelo", esc(cpu.modelo || "")], ["Núcleos / threads", cpu.nucleos != null ? `${nf(cpu.nucleos, 0)} núcleos · ${nf(cpu.threads, 0)} threads` : ""],
            ["Frequência", cpu.frequencia_hz ? nf(cpu.frequencia_hz / 1e9, 2) + " GHz" : ""], ["Uso agora", cpu.uso_pct != null ? `${pc(cpu.uso_pct)} ${bar(cpu.uso_pct)}` : ""],
            ["Utilização (média do host)", pc(cpu.utilizacao_pct)], ...(cpu.temperaturas || []).map(t => [esc(t.nome), `${nf(t.valor, 0)} °C ${stB(t.status)}`])])}
          <div class="chart" id="hwCpu"></div></section>
        <section class="card"><h3>🧩 Memória</h3>${kvT([["Total instalada", fB(mem.total, 1024)], ["Em uso", mem.usada != null ? `${fB(mem.usada, 1024)} · ${pc(mem.uso_pct)} ${bar(mem.uso_pct)}` : ""],
            ["Livre", mem.total && mem.usada != null ? fB(mem.total - mem.usada, 1024) : ""], ["Memória em balloon (VMs pressionadas)", mem.balloon != null ? fB(mem.balloon, 1024) : ""], ["VMs no host", id.vms != null ? nf(id.vms, 0) : ""]])}
          <div class="chart" id="hwMem"></div></section>
      </div>
      <section class="card hw-sec"><h3>💽 Discos e armazenamento</h3>
        ${kvT([["Controladora RAID", `${esc(dk.controladora || "–")} ${stB(dk.controladora_status)}`], ["Bateria do cache da controladora", stB(dk.bateria_cache)]])}
        ${(dk.datastores || []).length ? `<h4>Datastores (VMware)</h4><div class="tablewrap"><table><thead><tr><th class="nosort">Datastore</th><th class="nosort num">Total</th><th class="nosort num">Usado</th><th class="nosort num">Livre</th><th class="nosort">Ocupação</th><th class="nosort num">Latência leitura / escrita</th></tr></thead><tbody>
          ${dk.datastores.map(x => { const u = x.livre_pct != null ? 100 - x.livre_pct : null; return `<tr><td><b>${esc(x.nome)}</b></td><td class="num">${fB(x.total)}</td><td class="num">${fB(x.usado)}</td><td class="num">${x.total != null && x.usado != null ? fB(x.total - x.usado) : "–"} · ${pc(x.livre_pct)}</td><td>${bar(u, 160)} ${pc(u)}</td><td class="num">${x.lat_leitura != null ? nf(x.lat_leitura, 0) : "–"} / ${x.lat_escrita != null ? nf(x.lat_escrita, 0) : "–"} ms</td></tr>`; }).join("")}</tbody></table></div>` : ""}
        ${(dk.virtuais || []).length ? `<h4>Discos virtuais (RAID)</h4><div class="tablewrap"><table><thead><tr><th class="nosort">Volume</th><th class="nosort">RAID</th><th class="nosort num">Capacidade</th><th class="nosort">Status</th><th class="nosort">Estado</th><th class="nosort">Leitura</th><th class="nosort">Escrita</th></tr></thead><tbody>
          ${dk.virtuais.map(x => `<tr><td><b>${esc(x.nome)}</b></td><td>${esc(x.raid || "–")}</td><td class="num">${fB(x.tamanho)}</td><td>${stB(x.status)}</td><td>${esc(x.estado || "–")}</td><td>${esc(x.leitura || "–")}</td><td>${esc(x.escrita || "–")}</td></tr>`).join("")}</tbody></table></div>` : ""}
        ${(dk.fisicos || []).length ? `<h4>Discos físicos (${dk.fisicos.length})</h4><div class="tablewrap"><table><thead><tr><th class="nosort">Disco</th><th class="nosort">Tipo</th><th class="nosort">Modelo</th><th class="nosort num">Capacidade</th><th class="nosort">Status</th><th class="nosort">S.M.A.R.T.</th><th class="nosort">Nº de série</th><th class="nosort">Part number</th></tr></thead><tbody>
          ${dk.fisicos.map(x => `<tr><td><b>${esc(x.nome)}</b></td><td>${esc(x.tipo || "–")}</td><td>${esc(x.modelo || "–")}</td><td class="num">${fB(x.tamanho)}</td><td>${stB(x.status)}</td><td>${stB(x.smart)}</td><td class="muted">${esc(x.serie || "–")}</td><td class="muted">${esc(x.part || "–")}</td></tr>`).join("")}</tbody></table></div>` : ""}</section>
      <div class="hw-grid">
        <section class="card"><h3>⚡ Energia e refrigeração</h3>${kvT([...(en.fontes || []).map(f => [esc(f.nome.replace(/ Status$/, "")), stB(f.status)]), ["Consumo agora", id.energia_w != null ? nf(id.energia_w, 0) + " W" : ""],
            ...(en.ventoinhas || []).map(f => [esc(f.nome.replace(/^System Board /, "Ventoinha ")), `${nf(f.rpm, 0)} rpm ${stB(f.status)}`]), ...(en.temperaturas || []).map(t => [esc(t.nome), `${nf(t.valor, 0)} °C ${stB(t.status)}`])])}</section>
        <section class="card"><h3>🏷️ Identificação</h3>${kvT([["Fabricante / modelo", esc([id.fabricante, id.modelo].filter(Boolean).join(" "))], ["Service tag (nº de série Dell)", esc(id.service_tag || "")], ["Firmware do iDRAC", esc(id.firmware_idrac || "")],
            ["Hypervisor", esc(id.esxi || "")], ["BIOS UUID", esc(id.bios_uuid || "")], ["Saúde geral (iDRAC)", stB(id.saude_geral)], ["Status no VMware", stB(id.status_vmware)], ["Ligado há", id.ligado_s ? fmtDur(id.ligado_s) : ""],
            ["Hosts no Zabbix", (d.hosts || []).map(h => esc(h.nome)).join("<br>")]])}</section>
      </div>`;
    const draw = (el, tr, name, color) => { const box = $(el); if (!box) return; if (!tr || !tr.length) { box.innerHTML = '<div class="empty">Sem histórico.</div>'; return; }
      lineChart(box, {x: tr, xl: tr.map(p => fmtDT(p.t)), h: 200, ml: 46, ymin: 0, ymax: 100, fmtY: v => nf(v, 0) + "%", aria: name,
        series: [{name: "Média", color, vals: tr.map(p => p.avg), area: true}, {name: "Pico", color: "s2", vals: tr.map(p => p.max)}],
        tip: i => `<b>${fmtDT(tr[i].t)}</b><div class="r"><span>Média</span><b>${nf(tr[i].avg, 1)}%</b></div><div class="r"><span>Pico</span><b>${nf(tr[i].max, 1)}%</b></div>`}); };
    draw("#hwCpu", cpu.tendencia, "Uso de CPU em 7 dias", "s1"); draw("#hwMem", mem.tendencia, "Uso de memória em 7 dias", "s3");
  }
  {
    const m = document.createElement("div"); m.id = "siHw"; m.className = "sia"; m.setAttribute("role", "dialog"); m.setAttribute("aria-modal", "true");
    m.innerHTML = `<div class="bg" id="siHwBg"></div><div class="box"><div class="hd"><span class="vu-ic" aria-hidden="true">🖥️</span><div class="tt"><h2 id="siHwT"></h2><div class="sub" id="siHwS"></div></div>
      <button class="btn" id="siHwX" type="button" title="Fechar (Esc)">✕ Fechar</button></div><div class="ct" id="siHwC"></div></div>`;
    document.body.appendChild(m);
    const close = () => m.classList.remove("on");
    $("#siHwX").onclick = close; $("#siHwBg").onclick = close;
    addEventListener("keydown", e => { if (e.key === "Escape" && m.classList.contains("on")) { e.stopImmediatePropagation(); close(); } }, true);
  }

  /* ---------- bancos de dados (SQL Server, MySQL, PostgreSQL): visão no mapa e na ficha do host ---------- */
  const SIdb = {d: null};
  const DB_COR = {"SQL Server": "#CC2927", "MySQL": "#00758F", "PostgreSQL": "#336791"};
  const dbTag = e => `<span class="db-eng" style="--c:${DB_COR[e] || "#7e9a8b"}">🛢 ${esc(e)}</span>`;
  const dbOf = name => ((SIdb.d && SIdb.d.hosts) || []).find(h => h.name === name || hKey(h.name) === hKey(name) && looksSrv(name));
  async function dbLoad() {
    try { SIdb.d = await api(`/api/db?group=${encodeURIComponent(G)}`); } catch (e) { return; }
    document.querySelectorAll(".si-dbp").forEach(dbFill);
    if (document.querySelector("#siTopo.on") && typeof drawTopo === "function" && !document.querySelector("#mpDb")) drawTopo();
  }
  const pct1 = v => v == null ? "–" : nf(v, 1) + "%";
  const lvl = v => v == null ? "" : v >= 90 ? "risco" : v >= 75 ? "atencao" : "melhora";
  function dbPanel(name) {
    const h = dbOf(name); if (!h) return "";
    const okS = h.servicos.filter(x => x.ok).length, maxD = h.discos.reduce((m, d) => Math.max(m, d.pused || 0), 0), lat = h.desempenho_disco.reduce((m, d) => Math.max(m, d.leitura_ms || 0, d.escrita_ms || 0), 0);
    const svcT = (rows, t) => rows.length ? `<h4>${t}</h4><table class="hw-kv">${rows.map(x => `<tr><td><b>${esc(x.nome)}</b>${x.descricao ? ` <small class="muted">${esc(x.descricao)}</small>` : ""}</td><td>${x.ok ? `<span class="si-badge ok">${esc(x.estado)}</span>` : `<span class="si-badge late">${esc(x.estado)}</span>`}</td></tr>`).join("")}</table>` : "";
    const fBy = v => v == null ? "–" : v >= 1e12 ? nf(v / 1e12, 2) + " TB" : nf(v / 1e9, v >= 1e11 ? 0 : 1) + " GB";
    const my = h.mysql;
    const tip = h.motores.includes("SQL Server") ? "tamanho de cada base, conexões ativas, bloqueios, consultas lentas, jobs do SQL Agent e último backup (template <b>MSSQL by Zabbix agent 2</b> ou <b>MSSQL by ODBC</b>, com um usuário só de leitura)"
      : h.motores.includes("PostgreSQL") ? "conexões, tamanho das bases, bloqueios, replicação e cache (template <b>PostgreSQL by Zabbix agent 2</b>, com um usuário de leitura)"
      : "consultas por segundo, conexões, buffer pool, bloqueios e consultas lentas (template <b>MySQL by Zabbix agent 2</b> no próprio servidor do banco)";
    return `<div class="swp-h">🛢 Banco de dados ${h.motores.map(dbTag).join(" ")}</div>
      <div class="sia-kpi hw-kpi">
        <div class="${!h.servicos.length ? "atencao" : okS < h.servicos.length ? "risco" : "melhora"}"><span>Serviços do banco</span><b>${h.servicos.length ? `${okS}/${h.servicos.length}` : "–"}</b><small>${h.servicos.length ? "rodando" : "nenhum monitorado"}</small></div>
        <div class="${lvl(h.cpu)}"><span>CPU</span><b>${pct1(h.cpu)}</b></div>
        <div class="${lvl(h.memoria)}"><span>Memória</span><b>${pct1(h.memoria)}</b><small>${h.memoria_total ? "de " + fBy(h.memoria_total) : ""}</small></div>
        <div class="${lvl(maxD)}"><span>Disco mais cheio</span><b>${pct1(maxD)}</b><small>${esc((h.discos.find(d => d.pused === maxD) || {}).volume || "")}</small></div>
        <div class="${lat >= 20 ? "risco" : lat >= 10 ? "atencao" : "melhora"}"><span>Latência de disco (pior)</span><b>${h.desempenho_disco.length ? nf(lat, 1) + " ms" : "–"}</b><small>leitura/escrita</small></div>
        <div class="${h.swap >= 50 ? "risco" : h.swap >= 25 ? "atencao" : ""}"><span>Swap (paginação)</span><b>${pct1(h.swap)}</b></div></div>
      ${svcT(h.servicos, "Serviços do banco de dados")}${svcT(h.servicos_app, "Serviços da aplicação no mesmo servidor")}
      ${h.discos.length ? `<h4>Volumes</h4><div class="tablewrap"><table><thead><tr><th class="nosort">Volume</th><th class="nosort num">Total</th><th class="nosort num">Usado</th><th class="nosort num">Livre</th><th class="nosort">Ocupação</th></tr></thead><tbody>
        ${h.discos.map(d => `<tr><td><b>${esc(d.nome || d.volume)}</b></td><td class="num">${fBy(d.total)}</td><td class="num">${fBy(d.used)}</td><td class="num">${d.total != null && d.used != null ? fBy(d.total - d.used) : "–"}</td><td>${bar(d.pused, 160)} ${pct1(d.pused)}</td></tr>`).join("")}</tbody></table></div>` : ""}
      ${h.desempenho_disco.length ? `<h4>Desempenho dos discos</h4><div class="tablewrap"><table><thead><tr><th class="nosort">Disco</th><th class="nosort num">Leitura (ms)</th><th class="nosort num">Escrita (ms)</th><th class="nosort num">Fila</th><th class="nosort num">Utilização</th></tr></thead><tbody>
        ${h.desempenho_disco.map(d => `<tr><td><b>${esc(d.disco)}</b></td><td class="num">${nf(d.leitura_ms, 1)}</td><td class="num">${nf(d.escrita_ms, 1)}</td><td class="num">${nf(d.fila, 2)}</td><td class="num">${pct1(d.utilizacao)}</td></tr>`).join("")}</tbody></table></div>` : ""}
      ${my ? (my.coleta_ok ? `<h4>MySQL por dentro</h4><table class="hw-kv">${my.metricas.map(x => `<tr><td>${esc(x.nome)}</td><td>${esc(x.valor)} ${esc(x.unidade || "")}</td></tr>`).join("")}</table>`
        : `<div class="vu-note" style="margin-top:10px"><b>O monitoramento do MySQL deste host não está coletando.</b> ${h.agente2_falta ? "O template do MySQL precisa do <b>Zabbix agent 2</b>, e este host usa o agente comum (“Unsupported item key”)." : esc(my.erro || "")} Além disso, o banco do GLPI fica no ADVSERVER026: o template deveria estar lá.</div>`) : ""}
      <h4>Últimos 7 dias</h4><div class="chart db-chart" data-dbc="${esc(h.name)}"></div>
      <div class="vu-note" style="margin-top:10px">Hoje o Zabbix vê o banco <b>por fora</b> (serviços, processo e recursos do servidor). Para ver <b>por dentro</b>: ${tip}.</div>`;
  }
  function dbChart(el) {
    const h = dbOf(el.dataset.dbc); if (!h || !h.tendencia) return;
    const ks = Object.keys(h.tendencia).filter(k => h.tendencia[k].length), base = ks.length ? h.tendencia[ks[0]] : [];
    if (!base.length) { el.innerHTML = '<div class="empty">Sem histórico.</div>'; return; }
    const nm = {cpu: "CPU", memoria: "Memória"}, cols = ["s1", "s3", "s2", "s4", "s1", "s3", "s2", "s4"];
    lineChart(el, {x: base, xl: base.map(p => fmtDT(p.t)), h: 220, ml: 46, ymin: 0, ymax: 100, fmtY: v => nf(v, 0) + "%", aria: "Uso em 7 dias",
      series: ks.map((k, i) => ({name: nm[k] || k.replace("disco ", "Disco "), color: cols[i], vals: base.map(p => { const q = h.tendencia[k].find(x => x.t === p.t); return q ? q.avg : null; })})),
      tip: i => `<b>${fmtDT(base[i].t)}</b>` + ks.map(k => { const q = h.tendencia[k].find(x => x.t === base[i].t); return `<div class="r"><span>${esc(nm[k] || k)}</span><b>${q ? nf(q.avg, 1) + "%" : "–"}</b></div>`; }).join("")});
    el.insertAdjacentHTML("afterend", `<div class="legend">${ks.map((k, i) => `<span><i style="background:var(--${cols[i]})"></i>${esc(nm[k] || k.replace("disco ", "Disco "))}</span>`).join("")}</div>`);
  }
  const dbFill = el => { const html = dbPanel(el.dataset.host); el.innerHTML = html; el.style.display = html ? "" : "none"; el.dataset.done = "1"; el.querySelectorAll(".db-chart").forEach(dbChart); };
  {
    const orig = hostSheet;
    hostSheet = h => (h && (h.name || h.host) ? `<section class="si-dbp card" data-host="${esc(h.name || h.host)}"></section>` : "") + orig(h);
    new MutationObserver(() => { document.querySelectorAll(".si-dbp:not([data-done])").forEach(dbFill); }).observe(document.body, {childList: true, subtree: true});
    const m = document.createElement("div"); m.id = "siDb"; m.className = "sia"; m.setAttribute("role", "dialog"); m.setAttribute("aria-modal", "true");
    m.innerHTML = `<div class="bg" id="siDbBg"></div><div class="box"><div class="hd"><span class="vu-ic" aria-hidden="true">🛢️</span><div class="tt"><h2 id="siDbT"></h2><div class="sub">banco de dados · Zabbix</div></div>
      <button class="btn" id="siDbF" type="button">Ficha completa do host</button><button class="btn" id="siDbX" type="button" title="Fechar (Esc)">✕ Fechar</button></div><div class="ct"><section class="si-dbp" id="siDbP"></section></div></div>`;
    document.body.appendChild(m);
    const close = () => m.classList.remove("on");
    $("#siDbX").onclick = close; $("#siDbBg").onclick = close;
    $("#siDbF").onclick = () => { const h = dbOf(m.dataset.host); close(); if (h && typeof openInfo === "function" && document.querySelector("#siTopo.on")) openInfo(h.id); else if (h) openDrill({kind: "hostcard", hostid: h.id, title: h.name}); };
    addEventListener("keydown", e => { if (e.key === "Escape" && m.classList.contains("on")) { e.stopImmediatePropagation(); close(); } }, true);
    window.openDb = name => { m.dataset.host = name; $("#siDbT").textContent = mpShort(name); const p = $("#siDbP"); p.dataset.host = name; delete p.dataset.done; dbFill(p); m.classList.add("on"); };
    setTimeout(dbLoad, 1500); setInterval(dbLoad, 120000);
  }

  /* interações do chamado (descrição, acompanhamentos, tarefas e solução), lidas no GLPI quando o quadro é aberto */
  const TLc = {}, TLI = {abertura: ["📝", "Abertura"], acompanhamento: ["💬", "Acompanhamento"], tarefa: ["🛠", "Tarefa"], solucao: ["✅", "Solução"]};
  const tlDt = s => s ? new Date(String(s).replace(" ", "T")).toLocaleString("pt-BR", {day: "2-digit", month: "2-digit", year: "2-digit", hour: "2-digit", minute: "2-digit"}) : "–";
  function tlHtml(d) {
    const it = (d.itens || []).slice().reverse(), n = it.filter(x => x.tipo !== "abertura").length, nt = it.filter(x => x.tipo === "tarefa").length;
    return `<div class="tlh"><span>${n} interaç${n === 1 ? "ão" : "ões"}${nt ? ` · ${nt} tarefa${nt > 1 ? "s" : ""}` : ""} · mais recentes primeiro</span><span class="muted">lido do GLPI às ${new Date(d.lido * 1000).toLocaleTimeString("pt-BR", {hour: "2-digit", minute: "2-digit"})}</span></div>` +
      it.map(x => { const [ic, nm] = TLI[x.tipo] || ["•", x.tipo], long = (x.texto || "").length > 700;
        return `<div class="tli ${x.tipo}"><div class="tlm"><span class="ic">${ic}</span><b>${nm}</b><span>${esc(x.autor)}</span><span class="muted">${tlDt(x.quando)}</span>
            ${x.privado ? '<span class="si-badge warn">privado</span>' : ""}${x.estado ? `<span class="tag mut">${esc(x.estado)}</span>` : ""}${x.duracao ? `<span class="tag mut">${fmtDur(x.duracao)}</span>` : ""}${x.aprovacao ? `<span class="si-badge ${x.aprovacao === "recusada" ? "late" : "ok"}">${esc(x.aprovacao)}</span>` : ""}</div>
          <div class="tlt${long ? " cut" : ""}">${esc(x.texto || "(sem texto)")}</div>${long ? '<button type="button" class="tlmore">ver tudo</button>' : ""}</div>`; }).join("");
  }
  document.addEventListener("toggle", async e => {
    const el = e.target; if (!el.matches || !el.matches("details.tl") || !el.open) return;
    const id = +el.dataset.tl, box = el.querySelector(".tlb");
    if (TLc[id] && Date.now() - TLc[id].at < 60000) { box.innerHTML = tlHtml(TLc[id].d); return; }
    box.innerHTML = '<div class="loading">Buscando as interações no GLPI…</div>';
    try { const d = await api(`/api/si/timeline/${id}`); if (d.erro) throw new Error(d.erro);
      TLc[id] = {d, at: Date.now()}; box.innerHTML = tlHtml(d); el.querySelector(".tlc").textContent = `(${d.itens.filter(x => x.tipo !== "abertura").length})`;
    } catch (err) { box.innerHTML = `<div class="err">Não foi possível ler as interações: ${esc(err.message)}</div>`; }
  }, true);
  document.addEventListener("click", e => { const b = e.target.closest(".tlmore"); if (!b) return; const t = b.previousElementSibling; t.classList.toggle("cut"); b.textContent = t.classList.contains("cut") ? "ver tudo" : "ver menos"; });

  function bindSections() {
    const tlc = $("#siTlCard");
    if (tlc) tlc.onclick = e => {
      const fb = e.target.closest(".tl-f button"); if (fb) { SIt.f = fb.dataset.f; tlc.innerHTML = timelineInner(); return; }
      if (e.target.id === "siTlMore") { SIs.tlAll = true; tlc.innerHTML = timelineInner(); return; }
      const r = e.target.closest("#siTl tr[data-i]"); if (!r) return; const ev = (SIt.rows || [])[+r.dataset.i]; if (!ev) return;
      if (ev.src === "z") openDrill({kind: "events", title: ev.inc.name, sub: ev.inc.host, expand: true, items: [Object.assign({}, ev.inc, {dur: (ev.inc.end || Date.now() / 1000) - ev.inc.start})]});
      else openDrill({kind: "glpi", list: "ticket", v: String(ev.id), title: `Chamado #${ev.id}`, expand: true});
    };
  }

  /* ---------- gaveta de detalhes: GLPI e Zabbix, com o logotipo da fonte no topo ---------- */
  const dh = document.querySelector("#drawer .dh"); const srcImg = document.createElement("img"); srcImg.id = "ddSrc"; srcImg.alt = "";
  if (dh) dh.insertBefore(srcImg, document.getElementById("ddTitle"));
  const setSrc = k => { srcImg.src = k === "glpi" ? "/si/marcas/glpi-100.png" : "/si/marcas/zabbix.svg"; srcImg.className = k; srcImg.alt = k === "glpi" ? "GLPI" : "Zabbix"; };
  const SORT_G = [["recent", "Mais recentes"], ["old", "Mais antigos"], ["parado", "Mais tempo parado"], ["sla", "SLA mais crítico"], ["prio", "Maior prioridade"], ["tecnico", "Técnico (A–Z)"]];
  const openDrillZabbix = openDrill, drawDrillZabbix = drawDrill, exportDrillZabbix = exportDrill;
  openDrill = function (o, keep) {  // eslint-disable-line no-global-assign
    if (o && o.kind === "glpi") return openGlpi(o);
    setSrc("zabbix"); return openDrillZabbix(o, keep);
  };
  drawDrill = function () { return DD.opts && DD.opts.kind === "glpi" ? drawGlpi() : drawDrillZabbix(); };  // eslint-disable-line no-global-assign
  exportDrill = function () { return DD.opts && DD.opts.kind === "glpi" ? exportGlpi() : exportDrillZabbix(); };  // eslint-disable-line no-global-assign
  $("#ddCsv").onclick = () => exportDrill();  // o botão guardava a função antiga
  async function openGlpi(o) {
    DD.stack = []; DD.opts = o; DD.n = 100; DD.open = true; const my = ++DD.seq; setSrc("glpi");
    $("#drawer").classList.remove("sheet"); $("#ddBack").hidden = true;
    $("#ddTitle").textContent = o.title || "Chamados"; $("#ddSub").textContent = "Carregando…"; $("#ddQ").value = "";
    $("#ddSort").innerHTML = SORT_G.map(([v, l]) => `<option value="${v}">${l}</option>`).join(""); $("#ddSort").value = o.sort || "recent";
    $("#ddList").innerHTML = '<div class="loading">Buscando os chamados…</div>';
    $("#drawer").classList.add("on"); $("#ddScrim").classList.add("on"); $("#drawer").setAttribute("aria-hidden", "false"); hideTip();
    const p = S.ov ? S.ov.period : null, now = Date.now() / 1000;
    const q = new URLSearchParams({list: o.list, from: o.from || (p ? p.start : now - 30 * 86400), to: o.to || (p ? Math.min(p.end, now) : now)}); if (o.v != null) q.set("v", o.v);
    try {
      const r = await api("/api/si/glpi/list?" + q); if (my !== DD.seq) return;
      DD.items = r.items || []; DD.total = r.total; DD.glpiUrl = r.glpi_url; drawGlpi();
      if (o.expand && DD.items.length === 1) { const d = $("#ddList details"); if (d) d.open = true; }
    } catch (e) { if (my === DD.seq) { $("#ddSub").textContent = ""; $("#ddList").innerHTML = `<div class="err">${esc(e.message)}</div>`; } }
  }
  function glpiRows() {
    const q = $("#ddQ").value.trim().toLowerCase(), k = $("#ddSort").value;
    const a = DD.items.filter(t => !q || [t.id, t.n, t.au.join(" "), t.ag.join(" "), t.cat_full, t.status, t.tipo].join(" ").toLowerCase().includes(q));
    const late = t => t.sla_ttr.cls === "late" ? 0 : t.TTR ? 1 : 2;
    const by = {recent: (x, y) => y.D - x.D, old: (x, y) => x.D - y.D, parado: (x, y) => (y.parado ?? -1) - (x.parado ?? -1), prio: (x, y) => (y.p || 0) - (x.p || 0) || y.D - x.D,
                sla: (x, y) => late(x) - late(y) || (x.TTR || 9e12) - (y.TTR || 9e12), tecnico: (x, y) => (x.au[0] || "~").localeCompare(y.au[0] || "~")}[k] || ((x, y) => y.D - x.D);
    return a.sort(by);
  }
  const STC = {1: "#6fa8dc", 2: "#e3b448", 3: "#9bc76a", 4: "#e98a4f", 5: "#4cc59a", 6: "#7e9a8b"};
  function glpiCard(t) {
    const url = `${DD.glpiUrl}/front/ticket.form.php?id=${t.id}`, sla = x => x.cls ? `<span class="si-badge ${x.cls}">${esc(x.txt)}</span>` : esc(x.txt);
    const when = ts => ts ? `${fmtDayFull(ts)} ${fmtHour(ts)}` : "—";
    return `<div class="ddc gl" style="--c:${t.sla_ttr.cls === "late" ? "var(--crit)" : STC[t.s] || "var(--border)"}">
      <div class="t1"><img class="gl-logo" src="/si/marcas/glpi-100.png" alt="GLPI"><span class="tag mut">#${t.id}</span><span class="tag" style="background:${STC[t.s]}33;color:${STC[t.s]}">${esc(t.status)}</span>
        <span class="tag mut">${esc(t.tipo)}</span><span class="tag ${t.p >= 4 ? "crit" : t.p === 3 ? "warn" : "mut"}">Prioridade ${esc(t.prioridade)}</span>${t.sla_ttr.cls === "late" ? '<span class="tag crit">SLA atrasado</span>' : ""}
        <a class="go" href="${esc(url)}" target="_blank" rel="noopener">Abrir no GLPI ↗</a></div>
      <div class="tt">${esc(t.n)}</div>
      <div class="hs"><b>${esc(t.au.join(", ") || "sem responsável")}</b>${t.ag.length ? " · " + esc(t.ag.join(", ")) : ""}</div>
      <dl><div><dt>Abertura</dt><dd>${when(t.D)}</dd></div>
        <div><dt>${t.aberto ? "Parado há" : "Solução"}</dt><dd>${t.aberto ? `<span class="${t.parado > 30 ? "open" : ""}">${nf(t.parado, 1)} dias</span>` : when(t.SD)}</dd></div>
        <div><dt>${t.aberto ? "Última atualização" : "Tempo de solução"}</dt><dd>${t.aberto ? when(t.M) : t.lead_h != null ? fmtDur(t.lead_h * 3600) : "—"}</dd></div></dl>
      <details class="tl" data-tl="${t.id}"><summary>💬 Interações do chamado <span class="tlc"></span></summary><div class="tlb"><div class="loading">Abra para carregar do GLPI…</div></div></details>
      <details><summary>Todos os detalhes</summary><div class="ddx"><div><table>
        <tr><td>Chamado</td><td><a href="${esc(url)}" target="_blank" rel="noopener">#${t.id}</a> · ${esc(t.tipo)}</td></tr>
        <tr><td>Título</td><td>${esc(t.n)}</td></tr>
        <tr><td>Status</td><td>${esc(t.status)}</td></tr>
        <tr><td>Prioridade</td><td>${esc(t.prioridade)}</td></tr>
        <tr><td>Categoria</td><td>${esc(t.cat_full)}</td></tr>
        <tr><td>Entidade</td><td>${esc(t.ent)}</td></tr>
        <tr><td>Técnico(s)</td><td>${esc(t.au.join(", ") || "—")}</td></tr>
        <tr><td>Grupo(s)</td><td>${esc(t.ag.join(", ") || "—")}</td></tr>
        <tr><td>Abertura</td><td>${when(t.D)}</td></tr>
        <tr><td>Primeiro atendimento</td><td>${when(t.TK)}</td></tr>
        <tr><td>Última atualização</td><td>${when(t.M)}</td></tr>
        <tr><td>Solução</td><td>${t.SD ? when(t.SD) : '<span class="open">ainda em aberto</span>'}</td></tr>
        <tr><td>Prazo de atendimento (TTO)</td><td>${when(t.TTO)} ${sla(t.sla_tto)}</td></tr>
        <tr><td>Prazo de solução (TTR)</td><td>${when(t.TTR)} ${sla(t.sla_ttr)}</td></tr>
        ${t.lead_h != null ? `<tr><td>Tempo até a solução</td><td>${fmtDur(t.lead_h * 3600)}</td></tr>` : ""}
        ${t.aberto ? `<tr><td>Sem movimentação há</td><td>${nf(t.parado, 1)} dias</td></tr>` : ""}
      </table></div><div class="acts"><a class="btn" href="${esc(url)}" target="_blank" rel="noopener">Abrir o chamado no GLPI ↗</a></div></div></details></div>`;
  }
  function drawGlpi() {
    const r = glpiRows(), o = DD.opts, n = r.length;
    $("#ddSub").textContent = `${n}${n !== DD.items.length ? " de " + DD.items.length : ""} ${n === 1 ? "chamado" : "chamados"}${o.sub ? " · " + o.sub : ""}`;
    $("#ddList").innerHTML = n ? r.slice(0, DD.n).map(glpiCard).join("") + (n > DD.n ? `<button class="btn ddmore" id="ddMore" type="button">Mostrar mais ${Math.min(100, n - DD.n)} (${n - DD.n} restantes)</button>` : "")
      : '<div class="empty">Nenhum chamado nesta seleção 🎉</div>';
    const mb = $("#ddMore"); if (mb) mb.onclick = () => { DD.n += 100; drawGlpi(); };
  }
  function exportGlpi() {
    const qv = v => `"${String(v ?? "").replace(/"/g, '""')}"`, d = ts => ts ? `${fmtDayFull(ts)} ${fmtHour(ts)}` : "";
    const cols = [["Chamado", t => t.id], ["Título", t => t.n], ["Status", t => t.status], ["Tipo", t => t.tipo], ["Prioridade", t => t.prioridade], ["Categoria", t => t.cat_full],
      ["Técnico", t => t.au.join(", ")], ["Grupo", t => t.ag.join(", ")], ["Abertura", t => d(t.D)], ["Solução", t => d(t.SD)], ["Prazo de solução", t => d(t.TTR)], ["SLA de solução", t => t.sla_ttr.txt],
      ["Parado (dias)", t => t.parado == null ? "" : String(t.parado).replace(".", ",")], ["Link", t => `${DD.glpiUrl}/front/ticket.form.php?id=${t.id}`]];
    const csv = "﻿" + [cols.map(c => qv(c[0])).join(";"), ...glpiRows().map(t => cols.map(c => qv(c[1](t))).join(";"))].join("\r\n");
    const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob([csv], {type: "text/csv;charset=utf-8"})); a.download = `SI_${slug(DD.opts.title || "chamados")}.csv`; a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 8000);
  }

  /* ---------- chamados novos do departamento: só aviso na tela (a voz fica para Zabbix e SLA) ---------- */
  async function pollGlpiNew() {
    try {
      const r = await api("/api/si/glpi/latest"), ts = r.tickets || [];
      if (SIs.glpiKnown === null) { SIs.glpiKnown = new Set(ts.map(t => t.id)); return; }
      const nv = ts.filter(t => !SIs.glpiKnown.has(t.id)); nv.forEach(t => SIs.glpiKnown.add(t.id));
      if (!nv.length) return;
      const url = SIs.glpi && SIs.glpi.glpi_url;
      nv.slice(0, 4).forEach(t => { const el = toast(`<b>${SRC.g}Novo chamado #${t.id}</b>${esc(t.n)}`, "", 60000, () => openDrill({kind: "glpi", list: "ticket", v: String(t.id), title: `Chamado #${t.id}`, expand: true})); el.style.borderLeftColor = "var(--s3)"; });
      setTimeout(() => render(true), 3000);
    } catch (e) { /* tenta de novo na próxima rodada */ }
  }
  setInterval(pollGlpiNew, 30000); setTimeout(pollGlpiNew, 4000);

  /* ---------- SLA de solução dos chamados do departamento (sem prestadores): aviso e voz ----------
     Fala quando um prazo vai vencer em até 1 hora e quando vence. Na primeira leitura só memoriza o que já estava assim. */
  const SLA_ANTES = 3600;
  async function pollGlpiSla() {
    try {
      const r = await api("/api/si/glpi/list?list=em_aberto"), now = Date.now() / 1000;
      const ts = (r.items || []).filter(t => t.TTR && !t.prest);
      const late = ts.filter(t => t.TTR <= now), soon = ts.filter(t => t.TTR > now && t.TTR <= now + SLA_ANTES);
      if (SIs.slaLate === null) { SIs.slaLate = new Set(late.map(t => t.id)); SIs.slaSoon = new Set(soon.map(t => t.id)); return; }
      const novosLate = late.filter(t => !SIs.slaLate.has(t.id)), novosSoon = soon.filter(t => !SIs.slaSoon.has(t.id));
      SIs.slaLate = new Set(late.map(t => t.id)); soon.forEach(t => SIs.slaSoon.add(t.id));
      if (!novosLate.length && !novosSoon.length) return;
      const tec = t => t.au && t.au.length ? ` Técnico: ${t.au[0]}.` : " Sem técnico atribuído.";
      const min = t => Math.max(1, Math.round((t.TTR - now) / 60));
      const card = (t, txt, cor) => { const el = toast(`<b>${SRC.g}${txt} · chamado #${t.id}</b>${esc(t.n)}<br><small>${esc((t.au || []).join(", ") || "sem técnico")}</small>`, "", 120000,
        () => openDrill({kind: "glpi", list: "ticket", v: String(t.id), title: `Chamado #${t.id}`, expand: true})); el.style.borderLeftColor = cor; };
      novosLate.slice(0, 4).forEach(t => card(t, "SLA vencido", "var(--crit)"));
      novosSoon.slice(0, 4).forEach(t => card(t, `SLA vence em ${min(t)} min`, "var(--warn)"));
      const partes = [];
      if (novosLate.length === 1) { const t = novosLate[0]; partes.push(`SLA vencido no GLPI. Chamado ${t.id}: ${t.n}.${tec(t)}`); }
      else if (novosLate.length) partes.push(`${novosLate.length} chamados de Sistemas Internos estouraram o SLA de solução. ` +
        novosLate.slice(0, 3).map(t => `Chamado ${t.id}: ${t.n}.`).join(" ") + (novosLate.length > 3 ? ` E mais ${novosLate.length - 3}.` : ""));
      if (novosSoon.length === 1) { const t = novosSoon[0]; partes.push(`Atenção ao SLA. O chamado ${t.id}, ${t.n}, vence em ${min(t)} minutos.${tec(t)}`); }
      else if (novosSoon.length) partes.push(`${novosSoon.length} chamados vencem o SLA na próxima hora. ` +
        novosSoon.slice(0, 3).map(t => `Chamado ${t.id}, em ${min(t)} minutos.`).join(" "));
      say(partes.join(" "), true, true);
      setTimeout(() => render(true), 3000);
    } catch (e) { /* tenta de novo na próxima rodada */ }
  }
  setInterval(pollGlpiSla, 60000); setTimeout(pollGlpiSla, 6000);

  /* ---------- equipamentos: ilustração + fabricante (fotos/logos próprios em si/img/ substituem os desenhos) ---------- */
  const VEND = {fortinet: ["FORTINET", "#EE3124"], huawei: ["HUAWEI", "#E60012"], dell: ["DELL", "#2E9BD6"], vmware: ["VMWARE", "#78BE20"],
    microsoft: ["WINDOWS", "#00A4EF"], redhat: ["RHEL LINUX", "#EE4B4B"], ubuntu: ["UBUNTU", "#E95420"], linux: ["LINUX", "#F5C211"],
    brother: ["BROTHER", "#2F6BD8"], zabbix: ["ZABBIX", "#D40000"], web: ["WEB", "#4cc59a"], generic: ["", "#7e9a8b"]};
  function devInfo(n) {
    const nm = n.name.toUpperCase(), d = `${n.descr || ""} ${n.os || ""}`, du = d.toUpperCase();
    const win = /WINDOWS/.test(du), lin = /LINUX/.test(du);
    const linV = /\.EL\d+|RHEL|ROCKY|ALMA/.test(du) ? "redhat" : /-GENERIC|UBUNTU/.test(du) ? "ubuntu" : "linux";
    if (n.tier === "firewall") return {kind: "fw", v: "fortinet", model: "FortiGate · FortiOS " + ((/v(\d+\.\d+)/.exec(d) || [])[1] || "")};
    if (/FORTISW/.test(nm) || /FORTISWITCH/.test(du)) return {kind: "sw", v: "fortinet", model: "FortiSwitch " + (((/FortiSwitch-(\w+)/i.exec(d) || [])[1]) || "")};
    if (/HUAWEI/.test(du + nm)) return {kind: "sw", v: "huawei", model: ((/^(S\d{4}[\w-]*)/.exec(d.trim()) || [])[1] || "Switch").replace(/-PWR-EI-AC$/, "")};
    if (n.tier === "switch") return {kind: "sw", v: "generic", model: "Switch"};
    if (/IDRAC/.test(nm)) return {kind: "mgmt", v: "dell", model: (n.model || "PowerEdge") + " · iDRAC"};
    if (/ESXI/.test(nm)) return {kind: "hyper", v: "vmware", model: "ESXi" + (/FISICO/.test(nm.normalize("NFD").replace(/[̀-ͯ]/g, "")) ? " · host físico" : "")};
    if (n.tier === "fisico") return {kind: "server", v: "dell", model: "PowerEdge R550"};
    if (/ZABBIX SERVER/.test(nm)) return {kind: "cloud", v: "zabbix", os: lin ? "linux" : win ? "windows" : "", model: "Zabbix server" + (/AZURE/.test(du) ? " · Azure" : "")};
    if (/BROTHER|IMPRESSORA/.test(nm + du)) return {kind: "printer", v: "brother", model: ((/Brother (\S+)/i.exec(d) || [])[1]) || "Impressora"};
    if (/DVR/.test(nm)) return {kind: "dvr", v: "generic", model: "DVR · CFTV"};
    if (/SENSOR|SDTC/.test(nm)) return {kind: "sensor", v: "generic", model: "Sensor de temperatura"};
    if (n.tier === "web") return {kind: "web", v: "web", model: "Serviço web"};
    if (n.tier === "vm" || win || lin) return {kind: "vm", v: win ? "microsoft" : lin ? linV : "generic", os: win ? "windows" : lin ? "linux" : "", model: win ? "Windows Server" : lin ? "Servidor Linux" : "Servidor virtual"};
    return {kind: "server", v: "generic", model: "Servidor"};
  }
  /* desenhos dos equipamentos (64×44), com a cor do fabricante como destaque */
  function devSvg(kind, c) {
    const box = (y, h) => `<rect x="2" y="${y}" width="60" height="${h}" rx="3" fill="#24332e" stroke="#4a5f57"/>`;
    const led = (x, y, col) => `<circle cx="${x}" cy="${y}" r="1.6" fill="${col}"/>`;
    const ports = (x, y, n, w = 4, gap = 1.5) => Array.from({length: n}, (_, i) => `<rect x="${x + i * (w + gap)}" y="${y}" width="${w}" height="3.4" rx=".6" fill="#0d1613" stroke="#55705f" stroke-width=".5"/>`).join("");
    const k = {
      fw: box(12, 20) + `<rect x="2" y="12" width="9" height="20" rx="3" fill="${c}"/>` + ports(16, 17, 8) + ports(16, 24, 8) + led(57, 17, "#4cc59a") + led(57, 24, "#4cc59a"),
      sw: box(13, 18) + ports(6, 16, 10, 4, 1.2) + ports(6, 23, 10, 4, 1.2) + `<rect x="59" y="13" width="3" height="18" fill="${c}"/>` + led(5, 29, "#4cc59a"),
      server: box(6, 32) + Array.from({length: 4}, (_, i) => `<rect x="6" y="${10 + i * 6.5}" width="34" height="5" rx="1" fill="#33463f" stroke="#55705f" stroke-width=".5"/>`).join("") + `<rect x="44" y="9" width="14" height="26" rx="2" fill="${c}" opacity=".85"/>` + led(51, 31, "#4cc59a"),
      hyper: box(6, 32) + `<rect x="8" y="11" width="16" height="11" rx="2" fill="${c}"/><rect x="26" y="11" width="16" height="11" rx="2" fill="${c}" opacity=".75"/><rect x="8" y="24" width="16" height="11" rx="2" fill="${c}" opacity=".6"/><rect x="26" y="24" width="16" height="11" rx="2" fill="${c}" opacity=".45"/>` + led(54, 14, "#4cc59a") + led(54, 20, "#4cc59a"),
      mgmt: box(10, 24) + `<rect x="6" y="14" width="30" height="16" rx="2" fill="#33463f"/><path d="M44 22l6-6 4 4-6 6z" fill="${c}"/><circle cx="46" cy="24" r="4" fill="none" stroke="${c}" stroke-width="2"/>` + led(10, 18, "#4cc59a"),
      vm: `<rect x="6" y="5" width="52" height="34" rx="3" fill="#24332e" stroke="#4a5f57"/><rect x="6" y="5" width="52" height="7" rx="3" fill="${c}"/>` + led(11, 8.5, "#fff") + `<rect x="12" y="17" width="40" height="3" rx="1.5" fill="#55705f"/><rect x="12" y="23" width="30" height="3" rx="1.5" fill="#55705f"/><rect x="12" y="29" width="34" height="3" rx="1.5" fill="#55705f"/>`,
      web: `<rect x="5" y="5" width="54" height="34" rx="3" fill="#24332e" stroke="#4a5f57"/><rect x="5" y="5" width="54" height="7" rx="3" fill="#33463f"/>${led(10, 8.5, "#e2675a")}${led(15, 8.5, "#e3b448")}${led(20, 8.5, "#4cc59a")}<circle cx="32" cy="26" r="9" fill="none" stroke="${c}" stroke-width="2"/><ellipse cx="32" cy="26" rx="4" ry="9" fill="none" stroke="${c}" stroke-width="1.5"/><path d="M23 26h18" stroke="${c}" stroke-width="1.5"/>`,
      printer: `<rect x="16" y="4" width="32" height="12" rx="1" fill="#e8f2ec"/><rect x="6" y="14" width="52" height="18" rx="4" fill="#24332e" stroke="#4a5f57"/><rect x="16" y="28" width="32" height="12" rx="1" fill="#e8f2ec"/><rect x="44" y="18" width="10" height="3" rx="1" fill="${c}"/>`,
      dvr: `<path d="M8 14h34l8 6v4l-8 6H8z" fill="#33463f" stroke="#4a5f57"/><circle cx="44" cy="22" r="5" fill="#0d1613" stroke="${c}" stroke-width="2"/><rect x="18" y="30" width="4" height="8" fill="#4a5f57"/><rect x="12" y="37" width="16" height="3" rx="1" fill="#4a5f57"/>`,
      sensor: `<rect x="26" y="4" width="12" height="28" rx="6" fill="#24332e" stroke="#4a5f57"/><circle cx="32" cy="34" r="7" fill="#e2675a"/><rect x="30" y="14" width="4" height="20" rx="2" fill="#e2675a"/>` + [10, 16, 22].map(y => `<path d="M40 ${y}h5" stroke="#55705f"/>`).join(""),
      cloud: `<path d="M18 34h28a8 8 0 0 0 0-16 11 11 0 0 0-21-3 8 8 0 0 0-7 19z" fill="#24332e" stroke="${c}" stroke-width="2"/><rect x="24" y="22" width="16" height="9" rx="1.5" fill="${c}" opacity=".85"/>`,
      ap: `<ellipse cx="32" cy="30" rx="22" ry="7" fill="#24332e" stroke="#4a5f57"/><ellipse cx="32" cy="27" rx="22" ry="7" fill="#33463f" stroke="#55705f"/>${led(32, 27, "#4cc59a")}
        <path d="M22 16q10-9 20 0" fill="none" stroke="${c}" stroke-width="2.2" stroke-linecap="round"/><path d="M26 19.5q6-5 12 0" fill="none" stroke="${c}" stroke-width="2.2" stroke-linecap="round"/><circle cx="32" cy="22" r="1.8" fill="${c}"/>`,
      link: `<circle cx="22" cy="22" r="13" fill="none" stroke="${c}" stroke-width="2"/><ellipse cx="22" cy="22" rx="6" ry="13" fill="none" stroke="${c}" stroke-width="1.5"/><path d="M9 22h26M11 15h22M11 29h22" stroke="${c}" stroke-width="1.2"/><path d="M37 22h10" stroke="#8fb0a3" stroke-width="2"/><rect x="47" y="17" width="12" height="10" rx="2" fill="#33463f" stroke="#55705f"/>`,
    }[kind] || box(12, 20);
    return `<svg viewBox="0 0 64 44" width="64" height="44" aria-hidden="true">${k}</svg>`;
  }
  /* Linux = pinguim Tux · Windows = símbolo da Microsoft (desenhados aqui; arquivos linux.png / microsoft.png em si/img/ têm prioridade) */
  const OS_SVG = {
    linux: `<svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="13" rx="7" ry="9.5" fill="#1b1b1b"/><ellipse cx="12" cy="15" rx="4.6" ry="6.6" fill="#f5f5f0"/>
      <ellipse cx="9.8" cy="7.6" rx="1.5" ry="1.9" fill="#fff"/><ellipse cx="14.2" cy="7.6" rx="1.5" ry="1.9" fill="#fff"/><circle cx="10.1" cy="8" r=".75" fill="#111"/><circle cx="13.9" cy="8" r=".75" fill="#111"/>
      <path d="M9.6 10.2Q12 8.9 14.4 10.2 12 12.6 9.6 10.2z" fill="#f2a900"/><path d="M5.6 21.4q1.4-2.6 4.3-1.4.6 1.6-1.8 2.2-1.8.4-2.5-.8zM18.4 21.4q-1.4-2.6-4.3-1.4-.6 1.6 1.8 2.2 1.8.4 2.5-.8z" fill="#f2a900"/></svg>`,
    windows: `<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="9.5" height="9.5" fill="#F25022"/><rect x="12.5" y="2" width="9.5" height="9.5" fill="#7FBA00"/>
      <rect x="2" y="12.5" width="9.5" height="9.5" fill="#00A4EF"/><rect x="12.5" y="12.5" width="9.5" height="9.5" fill="#FFB900"/></svg>`,
  };
  let IMGS = new Set();
  api("/api/si/img").then(r => { IMGS = new Set(r.files || []); }).catch(() => {});
  const ownImg = (...keys) => { for (const k of keys) for (const ext of ["png", "jpg", "jpeg", "webp", "svg"]) if (IMGS.has(`${k}.${ext}`)) return `/si/img/${k}.${ext}`; return null; };
  function devPic(n, di) {
    const photo = ownImg(slug(n.name), di.kind + "-" + di.v, di.kind);
    const osFile = di.os ? ownImg(di.os === "windows" ? "microsoft" : "linux") : null;
    const logo = osFile || (di.os ? null : ownImg(di.v)), osSvg = !osFile && di.os ? OS_SVG[di.os] : null;
    const [vname, vcol] = VEND[di.v] || VEND.generic;
    const mark = cls => osSvg ? `<span class="${cls}">${osSvg}</span>` : logo ? `<img class="${cls}" src="${logo}" alt="">` : "";
    return {pic: (photo ? `<img src="${photo}" alt="">` : devSvg(di.kind, vcol)) + (photo ? "" : mark("plogo")),
            badge: vname ? `<span class="vd" style="--v:${vcol}">${mark("vlogo")}${esc(vname)}</span>` : ""};
  }

  const fmtBps = v => v == null ? "–" : v >= 1e9 ? nf(v / 1e9, v % 1e9 ? 1 : 0) + " Gbps" : v >= 1e6 ? nf(v / 1e6, v >= 1e8 ? 0 : 1) + " Mbps" : v >= 1e3 ? nf(v / 1e3, 0) + " Kbps" : nf(v, 0) + " bps";
  /* uptime do ativo: o medido pelo Zabbix; sem ele, há quanto tempo está no ar desde a última queda */
  const upTxt = n => n.state === "down" ? "" : n.uptime ? `⏱ ligado há <b>${fmtDur(n.uptime + Math.max(0, Date.now() / 1000 - (n.uptime_at || Date.now() / 1000)))}</b>`
    : n.since ? `⏱ no ar há <b>${ago(n.since)}</b> <small>(desde a última queda)</small>` : `⏱ <small>sem quedas registradas nos últimos 60 dias</small>`;

  /* ---------- topologia em mapa de rede (teste): ícones desenhados, logotipos na cor da marca e conexões animadas ---------- */
  const TPV = {map: (() => { try { return localStorage.getItem("si.topoView") !== "cards"; } catch (e) { return true; } })()};
  const LOGO = {fortinet: "#EE3124", huawei: "#E60012", dell: "#2E9BD6", vmware: "#78BE20", ubuntu: "#E95420", redhat: "#EE0000"};
  const logoEl = (v, sz) => LOGO[v] ? `<i class="mp-logo" style="--m:url(/si/img/${v}.svg);--c:${LOGO[v]};--s:${sz || 16}px" aria-hidden="true"></i>`
    : v === "windows" || v === "linux" ? `<span class="mp-os" style="--s:${sz || 16}px">${OS_SVG[v]}</span>` : "";
  // ícones 56×44 com volume (gradientes em #mpDefs); c = cor de destaque da marca
  function mapIcon(kind, c) {
    const rack = (y, h) => `<rect x="4" y="${y}" width="48" height="${h}" rx="4" fill="url(#mpMetal)" stroke="#5d7a6e" stroke-width=".8"/><rect x="4" y="${y}" width="48" height="2.2" rx="1" fill="#ffffff" opacity=".12"/>`;
    const led = (x, y, col = "#5fe39b") => `<circle cx="${x}" cy="${y}" r="1.5" fill="${col}"/>`;
    const ports = (x, y, n) => Array.from({length: n}, (_, i) => `<rect x="${x + i * 4.4}" y="${y}" width="3.4" height="3" rx=".5" fill="#0b1310" stroke="#6c8f80" stroke-width=".4"/>`).join("");
    const K = {
      fw: rack(10, 24) + Array.from({length: 3}, (_, r) => Array.from({length: 4}, (_, i) => `<rect x="${8 + i * 7 + (r % 2) * 3.5}" y="${14 + r * 5.6}" width="6" height="4.4" rx=".6" fill="#c0563f" opacity="${.55 + r * .12}"/>`).join("")).join("")
        + `<path d="M42 12l8 3v6c0 5-3.4 8-8 10-4.6-2-8-5-8-10v-6z" fill="${c}" stroke="#fff" stroke-width=".8"/><path d="M38.5 20.5l2.6 2.6 4.6-5" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>`,
      sw: rack(14, 16) + ports(8, 17.5, 8) + ports(8, 23.5, 8) + `<rect x="45" y="16.5" width="4" height="11" rx="1" fill="${c}"/>` + led(44, 18) + led(44, 22) + led(44, 26, "#f5c242"),
      server: rack(6, 32) + Array.from({length: 4}, (_, i) => `<rect x="8" y="${10 + i * 6.6}" width="26" height="5" rx="1" fill="#2c3d37" stroke="#6c8f80" stroke-width=".4"/>${led(30.5, 12.5 + i * 6.6)}`).join("") + `<rect x="37" y="9" width="12" height="26" rx="2" fill="${c}" opacity=".9"/><path d="M39 13h8M39 16h8M39 19h8" stroke="#fff" stroke-width=".6" opacity=".6"/>`,
      mgmt: rack(12, 20) + `<rect x="8" y="16" width="22" height="12" rx="1.5" fill="#132019"/><path d="M10 25l4-4 3 3 4-6 4 5" fill="none" stroke="#5fe39b" stroke-width="1.2"/><circle cx="41" cy="22" r="6" fill="none" stroke="${c}" stroke-width="2"/><path d="M41 18.5v3.5l2.5 1.5" stroke="${c}" stroke-width="1.6" fill="none" stroke-linecap="round"/>`,
      hyper: rack(16, 22) + Array.from({length: 4}, (_, i) => led(10 + i * 4, 33)).join("") + `<g transform="translate(14 2)"><path d="M0 8l7-4 7 4-7 4z" fill="${c}"/><path d="M0 8v8l7 4v-8z" fill="${c}" opacity=".75"/><path d="M14 8v8l-7 4v-8z" fill="${c}" opacity=".5"/></g><g transform="translate(28 4)"><path d="M0 8l6-3.4 6 3.4-6 3.4z" fill="${c}" opacity=".9"/><path d="M0 8v7l6 3.4v-7z" fill="${c}" opacity=".65"/><path d="M12 8v7l-6 3.4v-7z" fill="${c}" opacity=".4"/></g>`,
      vm: `<rect x="6" y="6" width="44" height="30" rx="3.5" fill="url(#mpMetal)" stroke="#5d7a6e" stroke-width=".8"/><rect x="6" y="6" width="44" height="6" rx="3" fill="${c}"/>${led(10, 9, "#fff")}${led(14.5, 9, "#ffffffaa")}<rect x="10" y="16" width="20" height="2.6" rx="1.3" fill="#6c8f80"/><rect x="10" y="21" width="28" height="2.6" rx="1.3" fill="#6c8f80"/><rect x="10" y="26" width="16" height="2.6" rx="1.3" fill="#6c8f80"/><path d="M22 36h12l2 4H20z" fill="#3c524a"/>`,
      ap: `<ellipse cx="28" cy="33" rx="20" ry="6.5" fill="#1d2a25"/><ellipse cx="28" cy="30" rx="20" ry="6.5" fill="url(#mpMetal)" stroke="#6c8f80" stroke-width=".8"/>${led(28, 30, "#5fe39b")}<path d="M17 18q11-10 22 0M21 21.5q7-6 14 0" fill="none" stroke="${c}" stroke-width="2.4" stroke-linecap="round"/><circle cx="28" cy="24.5" r="2" fill="${c}"/>`,
      web: `<circle cx="28" cy="22" r="15" fill="url(#mpGlobe)" stroke="${c}" stroke-width="1.4"/><ellipse cx="28" cy="22" rx="6.5" ry="15" fill="none" stroke="#e8fff3" stroke-width="1" opacity=".7"/><path d="M13 22h30M15.5 14h25M15.5 30h25" stroke="#e8fff3" stroke-width=".9" opacity=".7"/>`,
      link: `<circle cx="22" cy="22" r="14" fill="url(#mpGlobe)" stroke="${c}" stroke-width="1.4"/><ellipse cx="22" cy="22" rx="6" ry="14" fill="none" stroke="#e8fff3" stroke-width="1" opacity=".7"/><path d="M8 22h28" stroke="#e8fff3" stroke-width=".9" opacity=".7"/><path d="M41 14v16M41 14l-3 3.5M41 14l3 3.5M48 30V14M48 30l-3-3.5M48 30l3-3.5" stroke="${c}" stroke-width="1.8" fill="none" stroke-linecap="round"/>`,
      cloud: `<path d="M14 36h28a9 9 0 0 0 1-18 12 12 0 0 0-23-3 9 9 0 0 0-6 21z" fill="url(#mpGlobe)" stroke="${c}" stroke-width="1.4"/>`,
      sensor: `<rect x="23" y="4" width="10" height="26" rx="5" fill="url(#mpMetal)" stroke="#6c8f80"/><circle cx="28" cy="33" r="7" fill="#e2675a"/><rect x="26.4" y="12" width="3.2" height="20" rx="1.6" fill="#e2675a"/>` + [10, 15, 20].map(y => `<path d="M35 ${y}h5" stroke="#6c8f80"/>`).join(""),
      printer: `<rect x="16" y="4" width="24" height="12" rx="1" fill="#eef6f1"/><rect x="6" y="14" width="44" height="18" rx="4" fill="url(#mpMetal)" stroke="#6c8f80"/><rect x="16" y="28" width="24" height="12" rx="1" fill="#eef6f1"/><rect x="40" y="18" width="7" height="3" rx="1" fill="${c}"/>`,
      dvr: `<path d="M6 14h30l9 6v4l-9 6H6z" fill="url(#mpMetal)" stroke="#6c8f80"/><circle cx="38" cy="22" r="5" fill="#0b1310" stroke="${c}" stroke-width="2"/><rect x="16" y="30" width="4" height="7" fill="#5d7a6e"/><rect x="10" y="36" width="16" height="3" rx="1" fill="#5d7a6e"/>`,
    };
    return `<svg viewBox="0 0 56 44" class="mp-ic" aria-hidden="true">${K[kind] || K.server}</svg>`;
  }
  const mpState = n => n.state === "down" ? "down" : n.state === "maintenance" ? "mt" : n.problems ? (n.worst >= 4 ? "crit" : "warn") : "ok";
  const mpShort = nm => nm.replace(/^[A-Za-z]{2,4}\s*\|\s*/, "").replace(/\s+/g, " ").trim();
  function dbCards() {
    const hs = (SIdb.d && SIdb.d.hosts) || []; if (!hs.length) return "";
    return `<div class="mp-dbs" id="mpDb"><div class="mp-vt">🛢 Bancos de dados <small>${hs.length} servidores</small></div><div class="mp-dbg">${hs.map(h => { const ok = h.servicos.filter(x => x.ok).length, bad = ok < h.servicos.length, maxD = h.discos.reduce((m, d) => Math.max(m, d.pused || 0), 0);
      return `<button type="button" class="mp-db${bad ? " crit" : maxD >= 90 ? " warn" : ""}" data-db="${esc(h.name)}" title="${esc(h.name)} · clique para ver o banco">
        <span class="t">${h.motores.map(dbTag).join(" ")}</span><b>${esc(mpShort(h.name).replace(/^VM\s*\|?\s*/i, ""))}</b>
        <span class="s"><i class="mp-dot" style="${bad ? "background:var(--crit);animation:none" : ""}"></i>${h.servicos.length ? `${ok}/${h.servicos.length} serviços` : "serviço não monitorado"} · CPU ${pct1(h.cpu)} · mem ${pct1(h.memoria)} · disco ${pct1(maxD)}${h.mysql && !h.mysql.coleta_ok ? " · ⚠ coleta MySQL" : ""}</span></button>`; }).join("")}</div></div>`;
  }
  function mapHtml(d, by) {
    const fwName = id => mpShort((d.nodes.find(n => n.id === id) || {}).name || "");
    const node = (n, sub, attrs, iconK, col, logo) => `<div class="mp-n ${mpState(n)}" ${attrs} title="${esc(n.name)}">${n.problems ? `<span class="mp-pb chip sev${n.worst}">${n.problems}</span>` : ""}
      ${mapIcon(iconK, col)}<div class="mp-b"><div class="mp-t">${logo || ""}<span>${esc(n.label || mpShort(n.name))}</span></div><div class="mp-s"><i class="mp-dot"></i>${sub}</div></div></div>`;
    const dev = n => { const di = devInfo(n), [, vc] = VEND[di.v] || VEND.generic, os = di.os || "";
      const alr = n.problems ? `${n.problems} ${n.problems === 1 ? "alerta" : "alertas"}` : "sem alertas", av = n.avail != null ? `${nf(n.avail, n.avail >= 99.995 ? 0 : 2)}% disponível` : "";
      const sub = n.state === "down" ? `fora há ${ago(n.open_since || Date.now() / 1000)}`
        : n.tier === "web" ? [av, alr].filter(Boolean).join(" · ")
        : `${esc(di.model)}${n.uptime ? ` · ${fmtDur(n.uptime)}` : ""}`;
      return node(n, sub, `id="mp-h${esc(n.id)}" data-h="${esc(n.id)}"`, di.kind === "mgmt" ? "mgmt" : di.kind, vc, logoEl(os || di.v)); };
    const linkN = (l, i) => { const u = l.uso || {}, c = u.comercial || {}, cap = u.capacidade || u.porta, pk = c.in && c.in.pico, pct = pk != null && cap ? Math.round(100 * pk / cap) : null;
      const prov = (/\(([^)]+)\)/.exec(l.name) || [])[1] || `Link ${l.porta || l.name.replace(/\(.*$/, "")} · ${fwName(l.fw).replace(/\s+-\s+.*$/, "")}`;
      return node({name: l.name, label: prov, state: l.up ? "up" : "down", problems: 0}, `${u.capacidade ? fmtBps(u.capacidade) + " · " : ""}⬇ ${fmtBps(u.agora && u.agora.in)}${pct != null ? ` · pico ${pct}%` : ""}`, `id="mp-l${i}" data-ln="${i}"`, "link", l.up ? "#5fe39b" : "#e2675a"); };
    const apN = (a, i) => node({name: a.name, state: a.up ? "up" : "down", problems: 0}, `👥 ${a.clients ?? "–"} ${a.clients === 1 ? "usuário" : "usuários"}${a.cpu != null ? ` · CPU ${a.cpu}%` : ""}${a.mem != null ? ` · mem ${a.mem}%` : ""}${a.up && a.uptime ? ` · ${fmtDur(a.uptime)}` : ""}`, `id="mp-a${i}" data-ap="${i}"`, "ap", LOGO.fortinet, logoEl("fortinet"));
    const fws = (by.firewall || []).slice().sort((a, b) => /PRINCIPAL/i.test(b.name) - /PRINCIPAL/i.test(a.name) || a.name.localeCompare(b.name));
    const sws = (by.switch || []).slice().sort((a, b) => a.name.localeCompare(b.name));
    // físicos agrupados por servidor (iDRAC, ESXi, host físico e SO do mesmo ADVSERVERxxx viram um cartão)
    const srv = {}; (by.fisico || []).forEach(n => { const k = ((/ADVSERVER0*(\d+)/i.exec(n.name) || [])[1]) || n.name; (srv[k] = srv[k] || []).push(n); });
    const role = n => /IDRAC/i.test(n.name) ? "iDRAC" : /F[IÍ]SICO/i.test(n.name) ? "Host físico" : /ESXI/i.test(n.name) ? "ESXi" : "Sistema";
    const worst = ns => ns.some(n => n.state === "down") ? "down" : ns.some(n => n.problems && n.worst >= 4) ? "crit" : ns.some(n => n.problems) ? "warn" : "ok";
    const srvCard = (k, ns) => `<div class="mp-srv ${worst(ns)}" id="mp-s${esc(k)}" data-hw="${esc(ns.map(n => n.id).join(","))}" data-hwn="ADVSERVER${esc(k.padStart(3, "0"))}" title="Clique para ver processador, memória e discos">${mapIcon("hyper", LOGO.vmware)}<div class="mp-b"><div class="mp-t">${logoEl("dell")}<span>ADVSERVER${esc(k.padStart(3, "0"))}</span></div>
      <div class="mp-s">PowerEdge R550 · VMware ESXi</div><div class="mp-roles">${ns.sort((a, b) => role(a).localeCompare(role(b))).map(n => `<button type="button" class="mp-role ${mpState(n)}" data-h="${esc(n.id)}" title="${esc(n.name)}"><i class="mp-dot"></i>${role(n)}${n.problems ? ` <b>${n.problems}</b>` : ""}</button>`).join("")}<button type="button" class="mp-role hw" data-hwbtn="1">🔧 Hardware</button></div></div></div>`;
    const vmChip = n => { const di = devInfo(n); return `<button type="button" class="mp-vm ${mpState(n)}" data-h="${esc(n.id)}" title="${esc(n.name)}">${dbOf(n.name) ? '<span class="mp-dbi" title="banco de dados">🛢</span>' : ""}${logoEl(di.os || (di.v === "redhat" || di.v === "ubuntu" ? di.v : ""), 14)}${(() => { const nm = mpShort(n.name).replace(/^VM\s*\|?\s*/i, ""), m = /^ADVSERVER0*(\d+)\s*-?\s*(.*)$/i.exec(nm);
        // nome do servidor em cima (como está no Zabbix) e a função embaixo
        const srvN = m ? nm.split(/\s+-\s+/)[0].trim() : nm, fn = m ? (m[2] || "").trim() : "";
        return `<span class="mp-vtx"><b>${esc(srvN)}</b><small>${esc(fn || "servidor virtual")}</small></span>`; })()}${n.problems ? `<b class="chip sev${n.worst}">${n.problems}</b>` : ""}</button>`; };
    const vms = (by.vm || []).slice().sort((a, b) => (mpState(b) !== "ok") - (mpState(a) !== "ok") || a.name.localeCompare(b.name));
    const apUsers = (d.aps || []).reduce((s, a) => s + (a.clients || 0), 0);
    const grp = (id, ic, title, sub, inner, cls = "") => `<section class="mp-g ${cls}" id="mp-g${id}"><h4>${ic} ${title}<small>${sub}</small></h4>${inner}</section>`;
    return `<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
        <linearGradient id="mpMetal" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#40574e"/><stop offset="1" stop-color="#1c2925"/></linearGradient>
        <radialGradient id="mpGlobe" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="#3f8f6c"/><stop offset="1" stop-color="#123326"/></radialGradient></defs></svg>
      <div class="mp" id="mpWrap"><svg class="mp-lines" id="mpLines" aria-hidden="true"></svg>
        <div class="mp-row"><div class="mp-inet" id="mp-inet">${mapIcon("cloud", "#7fd3a8")}<b>Internet</b><small>${d.links.length} links</small></div></div>
        <div class="mp-row">${d.links.map(linkN).join("")}</div>
        <div class="mp-row">${fws.map(dev).join("")}</div>
        <div class="mp-row">${sws.map(dev).join("")}</div>
        <div class="mp-row mp-groups">
          <div class="mp-stack">${(d.aps || []).length ? grp("wifi", "📶", "Wi-fi escritório", `${d.aps.length} APs · 👥 ${apUsers}`, `<div class="mp-col">${d.aps.map(apN).join("")}</div>`) : ""}
          ${(by.web || []).length ? grp("web", "🌍", "Serviços web", `${by.web.length}`, `<div class="mp-col">${by.web.map(dev).join("")}</div>`) : ""}</div>
          ${grp("dc", "🗄️", "Datacenter", `${Object.keys(srv).length} servidores · ${vms.length} VMs`, `<div class="mp-srvs">${Object.entries(srv).map(([k, ns]) => srvCard(k, ns)).join("")}</div>
             ${dbCards()}
             <div class="mp-virt" id="mp-virt"><div class="mp-vt">${logoEl("vmware", 14)} Virtualização <small>${vms.filter(n => mpState(n) !== "ok").length} com alerta</small></div><div class="mp-vms">${vms.map(vmChip).join("")}</div></div>`, "wide")}
          ${(by.outros || []).length ? grp("out", "📟", "Outros", `${by.outros.length}`, `<div class="mp-col">${by.outros.map(dev).join("")}</div>`) : ""}
        </div></div>`;
  }
  // conexões: quem liga em quem (links→firewall real; demais são a ligação lógica do datacenter)
  function mapEdges(d, by) {
    const E = [], st = id => { const el = document.getElementById(id); return el ? (el.classList.contains("down") ? "down" : el.classList.contains("crit") ? "crit" : el.classList.contains("warn") ? "warn" : "ok") : "ok"; };
    const fws = (by.firewall || []).slice().sort((a, b) => /PRINCIPAL/i.test(b.name) - /PRINCIPAL/i.test(a.name)), main = fws[0];
    const sws = by.switch || [], fsw = sws.filter(n => /FORTI/i.test(n.name)), tor = sws.find(n => /TOR|HUAWEI/i.test(n.name)) || sws[0];
    d.links.forEach((l, i) => { E.push(["mp-inet", "mp-l" + i]); if (l.fw) E.push(["mp-l" + i, "mp-h" + l.fw]); });
    if (main) sws.forEach(s => E.push(["mp-h" + main.id, "mp-h" + s.id]));
    if ((d.aps || []).length && (fsw[0] || tor)) E.push(["mp-h" + (fsw[0] || tor).id, "mp-gwifi"]);
    if (tor) { E.push(["mp-h" + tor.id, "mp-gdc"]); if ((by.web || []).length) E.push(["mp-h" + tor.id, "mp-gweb"]); }
    if ((by.outros || []).length && (fsw[1] || fsw[0] || tor)) E.push(["mp-h" + (fsw[1] || fsw[0] || tor).id, "mp-gout"]);
    document.querySelectorAll(".mp-srv").forEach(s => E.push([s.id, "mp-virt", true]));
    return E.map(([a, b, inner]) => ({a, b, inner, s: [st(a), st(b)].includes("down") ? "down" : [st(a), st(b)].includes("crit") ? "crit" : st(b) === "warn" ? "warn" : "ok"}));
  }
  let mpLast = null;
  function mapLines(d, by) {
    if (d) mpLast = {d, by}; if (!mpLast) return;
    const wrap = $("#mpWrap"), svg = $("#mpLines"); if (!wrap || !svg) return;
    // o mapa pode estar reduzido (fitMap): as medidas da tela são divididas pela escala para cair no desenho
    const k = +wrap.dataset.scale || 1, W = wrap.getBoundingClientRect(), ww = W.width / k, wh = W.height / k;
    svg.setAttribute("width", ww); svg.setAttribute("height", wh); svg.setAttribute("viewBox", `0 0 ${ww} ${wh}`);
    const pt = (id, top) => { const el = document.getElementById(id); if (!el) return null; const r = el.getBoundingClientRect(); return [(r.left - W.left + r.width / 2) / k, (top ? r.top - W.top : r.bottom - W.top) / k]; };
    let out = "";
    mapEdges(mpLast.d, mpLast.by).forEach((e, i) => {
      const p = pt(e.a, false), q = pt(e.b, true); if (!p || !q) return;
      const my = (p[1] + q[1]) / 2, path = `M${p[0].toFixed(1)} ${p[1].toFixed(1)} C${p[0].toFixed(1)} ${my.toFixed(1)} ${q[0].toFixed(1)} ${my.toFixed(1)} ${q[0].toFixed(1)} ${q[1].toFixed(1)}`;
      const dur = (2.2 + (i % 5) * .35).toFixed(2);
      out += `<path id="mpE${i}" class="mp-e ${e.s}" d="${path}"/><path class="mp-e2 ${e.s}" d="${path}" style="animation-duration:${(+dur * .6).toFixed(2)}s"/>`
        + (e.s === "down" ? "" : `<circle r="3" class="mp-pk ${e.s}"><animateMotion dur="${dur}s" repeatCount="indefinite" begin="-${(i * .37 % +dur).toFixed(2)}s"><mpath href="#mpE${i}"/></animateMotion></circle>`);
    });
    svg.innerHTML = out;
  }
  /* cabe numa tela: reduz o mapa inteiro (mesma proporção) até a altura caber na área da topologia, usando toda a largura */
  function fitMap() {
    const wrap = $("#mpWrap"), body = $("#tpBody"); if (!wrap || !body) return;
    const cs = getComputedStyle(body), aw = body.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight), ah = body.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
    let k = 1;
    for (let i = 0; i < 4; i++) {  // a altura muda com a largura (itens quebram menos), então ajusta algumas vezes
      wrap.style.width = aw / k + "px"; wrap.style.transform = k < 1 ? `scale(${k})` : ""; wrap.style.marginBottom = "";
      const h = wrap.scrollHeight, nk = Math.min(1, ah / h);
      if (Math.abs(nk - k) < 0.01) { k = nk; break; } k = Math.max(0.45, nk);
    }
    wrap.style.width = aw / k + "px"; wrap.style.transformOrigin = "0 0"; wrap.style.transform = k < 1 ? `scale(${k})` : "";
    wrap.style.marginBottom = k < 1 ? `${-wrap.scrollHeight * (1 - k)}px` : ""; wrap.dataset.scale = k;
    body.style.overflow = k < 1 ? "hidden" : "";
  }
  let fitT = null;
  addEventListener("resize", () => { if (!TPV.map || !document.getElementById("mpWrap")) return; clearTimeout(fitT); fitT = setTimeout(() => { fitMap(); mapLines(); }, 120); });
  /* pré-visualização ao passar o mouse: usa o que o mapa já tem (sem ir ao Zabbix), e os alertas abertos do host */
  function mpPreview(el) {
    const row = (l, v) => v == null || v === "" ? "" : `<div class="r"><span>${l}</span><b>${v}</b></div>`;
    const stTxt = s => ({down: '<span class="open">fora do ar</span>', mt: "em manutenção", crit: "problema grave", warn: "com alerta", ok: "online"}[s] || s);
    if (el.dataset.h) {
      const n = TP.nodes.find(x => String(x.id) === el.dataset.h); if (!n) return "";
      const di = devInfo(n), [vn] = VEND[di.v] || VEND.generic, open = (SIs.inc || []).filter(i => i.end == null && i.host === n.name).sort((a, b) => b.sev - a.sev);
      const up = n.uptime ? fmtDur(n.uptime + Math.max(0, Date.now() / 1000 - (n.uptime_at || Date.now() / 1000))) : n.since ? `${ago(n.since)} (desde a última queda)` : "";
      return `<div class="h">${logoEl(di.os || di.v, 18)}<b>${esc(n.name)}</b></div>
        ${row("Situação", stTxt(mpState(n)))}${row("Equipamento", esc([vn, di.model].filter(Boolean).join(" · ")))}${row("Disponibilidade", nf(n.avail, 2) + "%")}
        ${row(n.state === "down" ? "Fora há" : "Ligado há", n.state === "down" ? ago(n.open_since || Date.now() / 1000) : up)}${n.descr ? row("Sistema", esc(short(n.descr, 70))) : ""}
        ${open.length ? `<div class="al"><span>${open.length} ${open.length === 1 ? "alerta aberto" : "alertas abertos"}</span>${open.slice(0, 4).map(i => `<div><span class="chip sev${i.sev}">${SEVN[i.sev]}</span> ${esc(short(i.name, 60))} <small>há ${ago(i.start)}</small></div>`).join("")}${open.length > 4 ? `<small>+${open.length - 4} outros</small>` : ""}</div>` : '<div class="ok">✓ Nenhum alerta aberto</div>'}
        <div class="ft">Clique para a ficha completa</div>`;
    }
    if (el.dataset.ap) {
      const a = TP.aps[+el.dataset.ap]; if (!a) return "";
      return `<div class="h">${logoEl("fortinet", 18)}<b>${esc(a.name)}</b></div>${row("Situação", a.up ? "online" : '<span class="open">fora do ar</span>')}
        ${row("Usuários no wi-fi", a.clients)}${row("CPU", a.cpu != null ? a.cpu + "%" : "")}${row("Memória", a.mem != null ? a.mem + "%" : "")}
        ${row("Conectado há", a.uptime ? fmtDur(a.uptime) : "")}${row("Série", esc(a.serial || ""))}${row("Firewall", esc(mpShort(a.fw || "")))}<div class="ft">Clique para a ficha completa</div>`;
    }
    if (el.dataset.ln) {
      const l = TP.links[+el.dataset.ln]; if (!l) return "";
      const u = l.uso || {}, c = u.comercial || {}, cap = u.capacidade || u.porta, pct = v => v != null && cap ? ` (${Math.round(100 * v / cap)}%)` : "";
      return `<div class="h"><b>${esc((/\(([^)]+)\)/.exec(l.name) || [])[1] || l.name)}</b></div>${row("Situação", l.up ? "no ar" : '<span class="open">fora</span>')}
        ${row("Capacidade", cap ? `${fmtBps(cap)} ${u.capacidade_txt === "contratada" ? "contratados" : "(porta)"}` : "")}${row("Agora", u.agora ? `⬇ ${fmtBps(u.agora.in)} · ⬆ ${fmtBps(u.agora.out)}` : "")}
        ${c.in ? row(c.hoje ? "Comercial hoje" : "Último dia útil", `média ⬇ ${fmtBps(c.in.media)} · pico ${fmtBps(c.in.pico)}${pct(c.in.pico)}`) : ""}
        ${row("Firewall", esc(mpShort((TP.nodes.find(n => n.id === l.fw) || {}).name || "")))}<div class="ft">Clique para o gráfico do consumo</div>`;
    }
    return "";
  }
  function mpTipInit() {
    const tip = document.createElement("div"); tip.id = "mpTip"; tip.setAttribute("role", "tooltip"); topo.appendChild(tip);
    let cur = null, tmr = null;
    const place = e => { const r = tip.getBoundingClientRect(), pad = 16; let x = e.clientX + pad, y = e.clientY + pad;
      if (x + r.width > innerWidth - 8) x = e.clientX - r.width - pad; if (y + r.height > innerHeight - 8) y = Math.max(8, innerHeight - r.height - 8);
      tip.style.left = x + "px"; tip.style.top = y + "px"; };
    const hide = () => { clearTimeout(tmr); cur = null; tip.classList.remove("on"); };
    $("#tpBody").addEventListener("mousemove", e => {
      if (!TPV.map) return hide();
      const el = e.target.closest("[data-h],[data-ap],[data-ln]");
      if (!el) return hide();
      if (el !== cur) { cur = el; clearTimeout(tmr); tip.classList.remove("on");
        tmr = setTimeout(() => { if (cur !== el) return; const h = mpPreview(el); if (!h) return; tip.innerHTML = h; tip.classList.add("on"); place(e); }, 220); }
      else if (tip.classList.contains("on")) place(e);
    });
    $("#tpBody").addEventListener("mouseleave", hide);
    $("#tpBody").addEventListener("click", hide, true);
  }

  /* ---------- topologia: tela para deixar fixa na TV ---------- */
  const TIER_ICON = {link: "🌐", firewall: "🛡", switch: "🔀", ap: "📶", fisico: "🖥", vm: "💠", web: "🌍", outros: "📟"};
  const topo = document.createElement("div"); topo.id = "siTopo"; topo.setAttribute("role", "dialog"); topo.setAttribute("aria-label", "Topologia dos ativos");
  topo.innerHTML = `<div class="tb"><h2>Topologia · ${esc(CFG.title)}</h2><span class="sub" id="tpSub">carregando…</span>
      <span class="tp-lg"><span><i style="background:var(--good)"></i>no ar</span><span><i style="background:var(--warn)"></i>com problema</span><span><i style="background:var(--crit)"></i>fora do ar</span><span><i style="background:var(--info)"></i>manutenção</span></span>
      <button class="btn" id="tpView" type="button" title="Alterna entre o mapa de rede e os cartões detalhados">▦ Ver em cartões</button><button class="btn" id="tpFull" type="button">⛶ Tela cheia</button><button class="btn" id="tpClose" type="button" title="Voltar ao painel (Esc)">✕ Fechar</button></div>
    <div class="body" id="tpBody"></div>`;
  document.body.appendChild(topo);
  const info = document.createElement("div"); info.id = "tpInfo"; info.className = "tp-info"; info.setAttribute("role", "dialog"); info.setAttribute("aria-modal", "true");
  info.innerHTML = `<div class="bg" id="tpInfoBg"></div><div class="box"><div class="hd"><div id="tpInfoT"></div><button class="btn" id="tpInfoX" type="button" title="Fechar (Esc)">✕ Fechar</button></div><div class="ct" id="tpInfoC"></div></div>`;
  topo.appendChild(info);
  mpTipInit();
  let TP = {nodes: []};
  async function openInfo(id) {
    const n = TP.nodes.find(x => x.id === id) || {id, name: ""}, di = devInfo(n), dp = devPic(n, di);
    info.classList.add("on"); hideTip();
    $("#tpInfoT").innerHTML = `<div class="tp-n${n.state === "down" ? " down" : ""}" style="--c:transparent;border:0;background:none;padding:0;cursor:default"><div class="pic">${dp.pic}</div>
      <div class="bd"><div class="vrow">${dp.badge}<span class="md">${esc(di.model)}</span></div><div class="nm" style="font-size:1.35em"><span class="sig ${n.state === "down" ? "dn" : n.state === "maintenance" ? "mt" : "up"}"></span>${esc(n.name)}</div>
      <div class="st">${n.state === "down" ? '<span class="open">fora do ar</span>' : n.state === "maintenance" ? "em manutenção" : "online"} · ${upTxt(n)}</div></div></div>`;
    $("#tpInfoC").innerHTML = '<div class="loading">Buscando as informações no Zabbix…</div>';
    try {
      const h = await api(`/api/host/${encodeURIComponent(id)}?period=${S.period}`);
      if (!info.classList.contains("on")) return;
      const up = n.uptime ? `<div class="tp-upbox"><span>⏱ Uptime</span><b>${fmtDur(n.uptime + Math.max(0, Date.now() / 1000 - (n.uptime_at || Date.now() / 1000)))}</b><small>ligado desde ${fmtDT(Date.now() / 1000 - n.uptime)}</small></div>` : "";
      const det = n.descr ? `<div class="tp-upbox"><span>Sistema / firmware</span><b style="font-size:1em;font-weight:600">${esc(n.descr)}</b></div>` : "";
      $("#tpInfoC").innerHTML = `<div class="tp-ups">${up}${det}</div>` + hostSheet(h);
    } catch (e) { $("#tpInfoC").innerHTML = `<div class="err">${esc(e.message)}</div>`; }
  }
  const closeInfo = () => info.classList.remove("on");
  $("#tpInfoX").onclick = closeInfo; $("#tpInfoBg").onclick = closeInfo;
  $("#tpInfoC").addEventListener("click", e => {  // botões da ficha ("Quedas no período", "Todos os alertas") abrem a lista na gaveta
    const b = e.target.closest("[data-sub]"); if (!b) return;
    const k = b.dataset.sub === "host" ? "events" : b.dataset.sub, h = b.dataset.h, titles = {outages: "Quedas", events: "Alertas", problems: "Problemas ativos"};
    closeInfo(); openDrill({kind: k, host: h, group: "", title: `${titles[k]} · ${h}`});
  });
  const btn = document.createElement("button"); btn.className = "btn"; btn.id = "topoBtn"; btn.type = "button"; btn.title = "Mapa da topologia dos ativos"; btn.textContent = "🗺 Topologia";
  const ctr = document.querySelector(".topbar .controls"); if (ctr) ctr.insertBefore(btn, document.getElementById("sndBtn"));
  // Resumo operacional: página onde os números do book mensal são lançados à mão e viram o slide
  const rb = document.createElement("a"); rb.className = "btn"; rb.href = "/resumo"; rb.title = "Lançar os números do mês e gerar o slide do Resumo operacional"; rb.textContent = "📊 Resumo operacional";
  rb.style.cssText = "display:inline-flex;align-items:center;text-decoration:none;color:inherit"; if (ctr) ctr.insertBefore(rb, btn);
  let tpTimer = null;
  async function drawTopo() {
    let d; try { d = await api(`/api/topology?group=${encodeURIComponent(G)}`); } catch (e) { $("#tpBody").innerHTML = `<div class="err">${esc(e.message)}</div>`; return; }
    TP = d;
    const by = {}; d.nodes.forEach(n => (by[n.tier] = by[n.tier] || []).push(n));
    const fwName = id => (d.nodes.find(n => n.id === id) || {}).name || "";
    const col = n => n.state === "down" ? "var(--crit)" : n.state === "maintenance" ? "var(--info)" : n.problems ? (n.worst >= 4 ? "var(--serious)" : "var(--warn)") : "var(--good)";
    const nodeHtml = n => { const di = devInfo(n), dp = devPic(n, di);
      return `<div class="tp-n${n.state === "down" ? " down" : n.state === "maintenance" ? "" : " ok"}" style="--c:${col(n)}" data-h="${esc(n.id)}" data-n="${esc(n.name)}" title="${esc(n.name)}${n.descr ? "\n" + esc(n.descr.slice(0, 120)) : ""}">
        ${n.problems ? `<span class="pb chip sev${n.worst}">${n.problems}</span>` : ""}<div class="pic">${dp.pic}</div>
        <div class="bd"><div class="vrow">${dp.badge}<span class="md">${esc(di.model)}</span></div><div class="nm"><span class="sig ${n.state === "down" ? "dn" : n.state === "maintenance" ? "mt" : "up"}" title="${n.state === "down" ? "fora do ar" : n.state === "maintenance" ? "em manutenção" : "online"}"></span>${esc(n.name.replace(/^[A-Za-z]{2,4}\s*\|\s*/, "").replace(/\s+/g, " "))}</div>
        <div class="st">${n.state === "down" ? `<span class="open">fora há ${ago(n.open_since || Date.now() / 1000)}</span>` : n.state === "maintenance" ? "em manutenção" : "no ar"} · ${nf(n.avail, 2)}%</div>
        <div class="upt">${upTxt(n)}</div></div></div>`; };
    const linkHtml = (l, ix) => { const ifc = l.porta || l.name.replace(/\(.*$/, ""), prov = (/\(([^)]+)\)/.exec(l.name) || [])[1] || `Link ${ifc} · ${short(fwName(l.fw).replace(/^FW\s*\|\s*/, "").replace(/\s+-\s+.*$/, ""), 20)}`;
      const u = l.uso || {}, c = u.comercial || {}, cap = u.capacidade || u.porta, pk = c.in && c.in.pico, pct = pk != null && cap ? Math.round(100 * pk / cap) : null;
      return `<div class="tp-n link${l.up ? "" : " down"}" style="--c:${l.up ? "var(--good)" : "var(--crit)"}" data-ln="${ix}" title="${esc(l.name)} · ${esc(fwName(l.fw))}">
        <div class="pic">${devSvg("link", l.up ? "#4cc59a" : "#e2675a")}</div><div class="bd"><div class="vrow"><span class="vd" style="--v:#6fa8dc">LINK</span><span class="md">${esc(ifc)}${u.capacidade ? ` · ${fmtBps(u.capacidade)}` : ""}</span></div>
        <div class="nm"><span class="sig ${l.up ? "up" : "dn"}" title="${l.up ? "link no ar" : "link fora"}"></span>${esc(prov || ifc)}</div>
        ${u.agora ? `<div class="lk-now">⬇ <b>${fmtBps(u.agora.in)}</b> ⬆ <b>${fmtBps(u.agora.out)}</b> <small>agora</small></div>` : ""}
        ${c.in ? `<div class="lk-biz" title="horário comercial ${esc(u.horario || "")}"><span>🕘 ${c.hoje ? "Comercial hoje" : "Último dia útil"}: média ⬇ ${fmtBps(c.in.media)} · pico ⬇ ${fmtBps(c.in.pico)}</span>
          ${pct != null ? `<div class="lk-bar" title="pico = ${pct}% de ${fmtBps(cap)} ${u.capacidade_txt === "contratada" ? "contratados" : "(velocidade da porta)"}"><i style="width:${Math.min(100, Math.max(2, pct))}%;background:${pct >= 85 ? "var(--crit)" : pct >= 60 ? "var(--warn)" : "var(--good)"}"></i></div><small>pico = ${pct}% ${u.capacidade_txt === "contratada" ? "do contratado" : "da velocidade da porta"}</small>` : ""}</div>` : ""}
        <div class="st">${l.up ? "no ar" : '<span class="open">fora</span>'} · ${esc(short(fwName(l.fw).replace(/^FW\s*\|\s*/, ""), 24))}</div></div></div>`; };
    const apModel = a => "FortiAP " + ((/^FP(\d{3}[A-Z])/.exec(a.serial) || [])[1] || "");
    const apHtml = (a, i) => { const logo = ownImg("fortinet"), vcol = VEND.fortinet[1];
      return `<div class="tp-n${a.up ? "" : " down"}" style="--c:${a.up ? "var(--good)" : "var(--crit)"}" data-ap="${i}" title="${esc(a.name)} · ${esc(a.serial)}">
        <div class="pic">${devSvg("ap", vcol)}${logo ? `<img class="plogo" src="${logo}" alt="Fortinet">` : ""}</div>
        <div class="bd"><div class="vrow"><span class="vd" style="--v:${vcol}">${logo ? `<img class="vlogo" src="${logo}" alt="">` : ""}FORTINET</span><span class="md">${esc(apModel(a))}</span></div>
        <div class="nm"><span class="sig ${a.up ? "up" : "dn"}" title="${a.up ? "online" : "fora do ar"}"></span>${esc(a.name)}</div>
        ${a.clients != null ? `<div class="apu" title="usuários conectados no wi-fi deste AP">👥 <b>${a.clients}</b> ${a.clients === 1 ? "usuário conectado" : "usuários conectados"}</div>` : ""}
        <div class="st">${a.up ? "online" : `<span class="open">${esc(a.state_txt || "fora do ar")}</span>`}${a.cpu != null ? ` · CPU ${a.cpu}%` : ""}${a.mem != null ? ` · memória ${a.mem}%` : ""}</div>
        <div class="upt">${a.up && a.uptime ? `⏱ conectado há <b>${fmtDur(a.uptime)}</b>` : ""}</div></div></div>`; };
    const tierRow = (id, label, inner, extra) => `<div class="tp-tier"><div class="tl">${TIER_ICON[id] || ""} ${esc(label)}${extra ? `<small>${extra}</small>` : ""}</div><div class="tp-row">${inner}</div></div>`;
    const down = d.nodes.filter(n => n.state === "down").length, pb = d.nodes.filter(n => n.problems).length, ldown = d.links.filter(l => !l.up).length;
    let h = tierRow("link", "Internet", `<span class="tp-cloud">☁ Internet · ${d.links.length} ${d.links.length === 1 ? "link" : "links"}${ldown ? ` · <span class="open">${ldown} fora</span>` : ""}</span>`);
    if (d.links.length) h += tierRow("link", "Links", d.links.map((l, i) => linkHtml(l, i)).join(""));
    d.tiers.filter(t => t.id !== "link" && by[t.id]).forEach(t => {
      const ns = by[t.id].sort((a, b) => (b.state === "down") - (a.state === "down") || b.problems - a.problems || a.name.localeCompare(b.name));
      h += tierRow(t.id, t.label, ns.map(nodeHtml).join(""), `${ns.length}`);
      const apUsers = (d.aps || []).reduce((n, a) => n + (a.clients || 0), 0);
      if (t.id === "switch" && (d.aps || []).length) h += tierRow("ap", "AP's Escritório", d.aps.map(apHtml).join(""), `${d.aps.length} · 👥 ${apUsers}`);  // wi-fi do escritório, logo abaixo dos switches
    });
    $("#tpBody").innerHTML = TPV.map ? mapHtml(d, by) : h;
    if (TPV.map) { fitMap(); mapLines(d, by); setTimeout(() => { fitMap(); mapLines(); }, 300); } else $("#tpBody").style.overflow = "";
    $("#tpView").textContent = TPV.map ? "▦ Ver em cartões" : "⌗ Ver em mapa";
    const apd = (d.aps || []).filter(a => !a.up).length;
    const usu = (d.aps || []).reduce((n, a) => n + (a.clients || 0), 0);
    $("#tpSub").textContent = `${d.nodes.length} ativos · ${down ? down + " fora do ar · " : ""}${pb} com problema · ${d.links.length - ldown}/${d.links.length} links no ar${(d.aps || []).length ? ` · ${d.aps.length - apd}/${d.aps.length} APs online · ${usu} ${usu === 1 ? "usuário" : "usuários"} no wi-fi` : ""} · atualizado às ${fmtHour(d.generated)}`;
  }
  function openTopo() { topo.classList.add("on"); document.body.classList.add("tp-open"); drawTopo(); clearInterval(tpTimer); tpTimer = setInterval(drawTopo, 30000); try { history.replaceState(null, "", "?topologia=1" + HASH); } catch (e) {} }
  // embed=1: o mapa está dentro de outro painel (ex.: Engenharia); "Fechar" devolve para ele
  const EMBED = new URLSearchParams(location.search).get("embed") === "1";
  if (EMBED) { document.body.classList.add("embed"); try { AL.on = false; say = () => {}; } catch (e) {} }  // sem voz nem som dentro de outro painel
  function closeTopo() { if (EMBED) { try { parent.postMessage("si:topo-close", "*"); } catch (e) {} return; } topo.classList.remove("on"); document.body.classList.remove("tp-open"); clearInterval(tpTimer); if (document.fullscreenElement) document.exitFullscreen().catch(() => {}); try { history.replaceState(null, "", location.pathname + "?p=/" + HASH); } catch (e) {} }
  btn.onclick = openTopo;
  $("#tpClose").onclick = closeTopo;
  $("#tpView").onclick = () => { TPV.map = !TPV.map; try { localStorage.setItem("si.topoView", TPV.map ? "map" : "cards"); } catch (e) {} drawTopo(); };
  $("#tpFull").onclick = () => { if (document.fullscreenElement) document.exitFullscreen(); else topo.requestFullscreen().catch(() => {}); };
  $("#tpBody").addEventListener("click", e => {
    const db = e.target.closest("[data-db]"); if (db) return openDb(db.dataset.db);
    const hw = e.target.closest("[data-hwbtn]") || (!e.target.closest("[data-h]") && e.target.closest("[data-hw]"));
    if (hw) { const c = hw.closest("[data-hw]"); return openHw(c.dataset.hwn, c.dataset.hw); }
    const n = e.target.closest("[data-h]"); if (n) return openInfo(n.dataset.h);
    const a = e.target.closest("[data-ap]"); if (a) return openApInfo(TP.aps[+a.dataset.ap]);
    const ln = e.target.closest("[data-ln]"); if (ln) openLinkInfo(TP.links[+ln.dataset.ln]);
  });
  function openLinkInfo(l) {
    if (!l) return; info.classList.add("on"); hideTip();
    const u = l.uso || {}, c = u.comercial || {}, w = u.semana || {}, cap = u.capacidade || u.porta, fw = (TP.nodes.find(n => n.id === l.fw) || {}).name || "";
    const prov = (/\(([^)]+)\)/.exec(l.name) || [])[1] || l.name, pctOf = v => v != null && cap ? Math.round(100 * v / cap) + "%" : "–";
    $("#tpInfoT").innerHTML = `<div class="tp-n${l.up ? "" : " down"}" style="--c:transparent;border:0;background:none;padding:0;cursor:default"><div class="pic">${devSvg("link", l.up ? "#4cc59a" : "#e2675a")}</div>
      <div class="bd"><div class="vrow"><span class="vd" style="--v:#6fa8dc">LINK</span><span class="md">${esc(l.porta || l.name.replace(/\(.*$/, ""))} · ${esc(fw)}</span></div>
      <div class="nm" style="font-size:1.35em"><span class="sig ${l.up ? "up" : "dn"}"></span>${esc(prov)}</div><div class="st">${l.up ? "no ar" : '<span class="open">fora</span>'} · capacidade ${fmtBps(cap)} ${u.capacidade_txt === "contratada" ? "contratada" : "(velocidade da porta)"}</div></div></div>`;
    const box = (t, v, sm) => `<div class="tp-upbox"><span>${t}</span><b>${v}</b>${sm ? `<small>${sm}</small>` : ""}</div>`;
    const janela = c.de ? `${c.hoje ? "hoje" : fmtDayFull(c.de)} · ${fmtHour(c.de)}–${fmtHour(c.ate)}` : "";
    $("#tpInfoC").innerHTML = `<div class="tp-ups">
        ${box("Agora", `⬇ ${fmtBps(u.agora && u.agora.in)} · ⬆ ${fmtBps(u.agora && u.agora.out)}`, "download · upload")}
        ${c.in ? box(`🕘 Horário comercial · ${esc(janela)}`, `média ⬇ ${fmtBps(c.in.media)} · ⬆ ${fmtBps(c.out && c.out.media)}`, `pico ⬇ ${fmtBps(c.in.pico)} às ${fmtHour(c.in.pico_em)} (${pctOf(c.in.pico)}) · pico ⬆ ${fmtBps(c.out && c.out.pico)} (${pctOf(c.out && c.out.pico)})`) : ""}
        ${w.in ? box(`Últimos ${w.dias} dias úteis · ${esc(u.horario || "")}`, `média ⬇ ${fmtBps(w.in.media)} · ⬆ ${fmtBps(w.out && w.out.media)}`, `pico ⬇ ${fmtBps(w.in.pico)} em ${fmtDT(w.in.pico_em)} (${pctOf(w.in.pico)})`) : ""}
      </div>
      <div class="card"><h2>Consumo no horário comercial<span class="hint">${esc(janela)} · média a cada 15 min</span></h2><div class="chart" id="tpLinkChart"></div>
        <div class="legend"><span><i style="background:var(--s1)"></i>Download</span><span><i style="background:var(--s2)"></i>Upload</span>${cap ? `<span><i style="background:var(--ink2)"></i>capacidade ${fmtBps(cap)}</span>` : ""}</div></div>`;
    const sr = c.serie || [], box2 = $("#tpLinkChart");
    if (sr.length && box2) lineChart(box2, {x: sr, xl: sr.map(p => fmtHour(p.t)), h: 260, ml: 70, ymin: 0, fmtY: v => fmtBps(v), aria: "Consumo do link",
      ref: u.capacidade ? {y: u.capacidade, label: "contratado"} : null,
      series: [{name: "Download", color: "s1", vals: sr.map(p => p.in), area: true}, {name: "Upload", color: "s2", vals: sr.map(p => p.out)}],
      tip: i => `<b>${fmtHour(sr[i].t)}</b><div class="r"><span>Download</span><b>${fmtBps(sr[i].in)}</b></div><div class="r"><span>Upload</span><b>${fmtBps(sr[i].out)}</b></div>`});
    else if (box2) box2.innerHTML = '<div class="empty">Sem dados no horário comercial.</div>';
  }
  function openApInfo(a) {
    if (!a) return; info.classList.add("on"); hideTip();
    const logo = ownImg("fortinet"), vcol = VEND.fortinet[1], model = "FortiAP " + ((/^FP(\d{3}[A-Z])/.exec(a.serial) || [])[1] || "");
    $("#tpInfoT").innerHTML = `<div class="tp-n${a.up ? "" : " down"}" style="--c:transparent;border:0;background:none;padding:0;cursor:default"><div class="pic">${devSvg("ap", vcol)}${logo ? `<img class="plogo" src="${logo}" alt="">` : ""}</div>
      <div class="bd"><div class="vrow"><span class="vd" style="--v:${vcol}">FORTINET</span><span class="md">${esc(model)} · access point</span></div>
      <div class="nm" style="font-size:1.35em"><span class="sig ${a.up ? "up" : "dn"}"></span>${esc(a.name)}</div><div class="st">${a.up ? "online" : '<span class="open">fora do ar</span>'} · AP's Escritório</div></div></div>`;
    const row = (l, v) => `<tr><td>${l}</td><td>${v}</td></tr>`;
    $("#tpInfoC").innerHTML = `<div class="tp-ups">
        <div class="tp-upbox"><span>Situação</span><b style="color:${a.up ? "var(--good-text)" : "var(--crit-text)"}">${a.up ? "Online" : "Fora do ar"}</b><small>${esc(a.state_txt || "")}</small></div>
        ${a.uptime ? `<div class="tp-upbox"><span>⏱ Conectado ao firewall há</span><b>${fmtDur(a.uptime)}</b><small>desde ${fmtDT(Date.now() / 1000 - a.uptime)}</small></div>` : ""}
        <div class="tp-upbox"><span>👥 Usuários conectados</span><b>${a.clients != null ? a.clients : "–"}</b><small>clientes wi-fi neste AP agora</small></div>
        <div class="tp-upbox"><span>CPU</span><b>${a.cpu != null ? a.cpu + "%" : "–"}</b></div><div class="tp-upbox"><span>Memória</span><b>${a.mem != null ? a.mem + "%" : "–"}</b></div></div>
      <div class="hsheet"><div class="ddx"><div><h4>Access point</h4><table>${row("Nome", esc(a.name))}${row("Modelo", esc(model))}${row("Número de série", esc(a.serial))}
        ${row("Gerenciado pelo firewall", esc(a.fw))}${row("Monitoramento", "pelo firewall (SNMP do FortiGate)")}${row("Alerta de queda", `"FortiAP ${esc(a.name)} … is DOWN" ${a.down ? '<span class="si-badge late">disparado</span>' : '<span class="si-badge ok">normal</span>'}`)}</table>
        ${a.fwid ? `<div class="acts" style="margin-top:10px"><button class="btn" type="button" id="tpApFw">Ver o firewall que gerencia o AP</button></div>` : ""}</div></div></div>`;
    const b = $("#tpApFw"); if (b) b.onclick = () => openInfo(a.fwid);
  }
  addEventListener("keydown", e => {
    if (e.key !== "Escape" || !topo.classList.contains("on") || document.querySelector("#drawer.on") || document.querySelector("#iaAsk.on")) return;
    if (info.classList.contains("on")) closeInfo(); else closeTopo();
  });
  if (new URLSearchParams(location.search).get("topologia")) setTimeout(openTopo, 300);
})();
