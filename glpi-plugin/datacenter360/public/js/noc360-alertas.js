/**
 * NOC 360 — alertas em todas as telas do GLPI, no mesmo esquema dos Sistemas Internos:
 * sinalizador no topo (contador do que pede atenção agora), som, VOZ (pt-BR) e pop-up no rodapé,
 * para alertas do Zabbix (Desastre, Alta, Atenção, resolvido), chamados novos e SLA (vencendo e vencido).
 * Os canais de cada tipo ficam em /etc/datacenter360/config.php → 'alertas'. O que já foi avisado é
 * guardado no navegador (localStorage), então nada dispara em dobro nem ao trocar de página.
 */
(() => {
    if (window.NOC360 || !document.body || document.querySelector('.page-anonymous, form#login_form, .login-page')) return;
    const root = (window.CFG_GLPI && CFG_GLPI.root_doc) || '';
    const api = `${root}/datacenter360/api.php?action=alertas`;
    const KEY = 'noc360.alertas.v1';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const store = {
        get() { try { return JSON.parse(localStorage.getItem(KEY)) || null; } catch (e) { return null; } },
        set(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* sem armazenamento */ } },
    };
    // fora do GLPI (painel aberto direto, ou dentro do quadro do plugin) não há fonte de ícones nem Bootstrap
    const standalone = !window.CFG_GLPI;
    if (standalone) document.documentElement.classList.add('noc360-standalone');
    const EMO = {'ti-flame': '🔥', 'ti-alert-octagon': '⛔', 'ti-alert-triangle': '⚠️', 'ti-alert-circle': '❗', 'ti-info-circle': 'ℹ️', 'ti-help-circle': '❔', 'ti-circle-check': '✅',
        'ti-ticket': '🎫', 'ti-hourglass-high': '⏳', 'ti-hourglass-empty': '⌛', 'ti-volume': '🔊', 'ti-volume-off': '🔇', 'ti-moon': '🌙', 'ti-speakerphone': '📣',
        'ti-chart-donut-3': '📊', 'ti-mood-happy': '🙂', 'ti-radar-2': '📡'};
    const fixIcons = (el) => {
        if (!standalone || !el) return;
        el.querySelectorAll('i.ti').forEach((i) => {
            const n = [...i.classList].find((c) => c.startsWith('ti-') && c !== 'ti');
            i.replaceWith(Object.assign(document.createElement('span'), {className: 'noc360-emo', textContent: EMO[n] || '•'}));
        });
    };
    let cfg = {poll: 20, volume: 0.8, rate: 1.05, popup_seconds: 20, popup_max: 5, mute_minutes: 60, quiet: null, sla_warn: 60};
    let timer = null, stopped = false, built = false, unseen = false;

    // ---------------------------------------------------------------- som
    let ctx = null, unlocked = false;
    const unlock = () => {
        try {
            ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
            if (ctx.state === 'suspended') ctx.resume();
            const first = !unlocked;
            unlocked = true;
            flagEl && flagEl.classList.remove('noc360-flag-locked');
            if (first) announce();
        } catch (e) { /* sem áudio */ }
    };
    ['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, unlock, {passive: true}));
    const TONES = {
        bip: [[880, 0, 0.18, 'square']],
        duplo: [[880, 0, 0.13, 'square'], [880, 0.2, 0.13, 'square']],
        sino: [[1318, 0, 0.9, 'sine'], [1975, 0.02, 0.7, 'sine']],
        sirene: [[620, 0, 0.45, 'sawtooth', 1240], [1240, 0.45, 0.45, 'sawtooth', 620], [620, 0.9, 0.45, 'sawtooth', 1240]],
        suave: [[660, 0, 0.3, 'sine'], [880, 0.16, 0.35, 'sine']],
    };
    function play(name) {
        if (!unlocked || !ctx) { flagEl && flagEl.classList.add('noc360-flag-locked'); return; }
        const t0 = ctx.currentTime + 0.02;
        (TONES[name] || TONES.bip).forEach(([f, start, dur, type, f2]) => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.type = type || 'sine';
            o.frequency.setValueAtTime(f, t0 + start);
            if (f2) o.frequency.linearRampToValueAtTime(f2, t0 + start + dur);
            const peak = Math.max(0.0001, cfg.volume * (type === 'square' || type === 'sawtooth' ? 0.18 : 0.4));
            g.gain.setValueAtTime(0.0001, t0 + start);
            g.gain.exponentialRampToValueAtTime(peak, t0 + start + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t0 + start + dur);
            o.connect(g).connect(ctx.destination);
            o.start(t0 + start);
            o.stop(t0 + start + dur + 0.05);
        });
    }

    // ---------------------------------------------------------------- voz
    // Voz o mais humana que o navegador tiver em pt-BR, frases separadas e siglas pronunciadas por extenso. Funciona em Chrome, Edge, Safari e Firefox.
    // voz humana: neural/natural primeiro (Edge: Francisca, Antonio), depois as de alta qualidade (Google, Luciana, Joana),
    // e por último as de brinquedo do macOS (Reed, Eddy, Flo...) e as "compact", que soam robóticas
    const vozNota = (v) => {
        const n = v.name;
        let sc = /^pt[-_]BR/i.test(v.lang) ? 20 : 0;
        if (/natural|neural/i.test(n)) sc += 120;
        if (/premium/i.test(n)) sc += 90;
        if (/enhanced|aprimorad/i.test(n)) sc += 80;
        if (/online/i.test(n)) sc += 30;
        if (/google/i.test(n)) sc += 70;
        if (/francisca|antonio|thalita|luciana|joana|fernanda|felipe|daniel/i.test(n)) sc += 40;
        if (/compact|eloquence|espeak|^(eddy|flo|grandma|grandpa|reed|rocko|sandy|shelley|bad news|bells|boing|bubbles|cellos|good news|jester|organ|superstar|trinoids|whisper|zarvox)\b/i.test(n)) sc -= 100;
        return sc;
    };
    // as vozes carregam depois da página (Chrome, Safari): escolhe de novo a cada fala
    const vozNavegador = () => {
        const all = (window.speechSynthesis && speechSynthesis.getVoices()) || [];
        return all.filter((v) => /^pt[-_]BR/i.test(v.lang) || /^pt$/i.test(v.lang)).sort((a, b) => vozNota(b) - vozNota(a))[0] || null;
    };
    if (window.speechSynthesis) { speechSynthesis.getVoices(); speechSynthesis.onvoiceschanged = () => speechSynthesis.getVoices(); }
    const vozTexto = (t) => String(t)
        .replace(/\*\*/g, '').replace(/#(\d{3,9})\b/g, 'evento $1')
        .replace(/\bSLA\b/g, 'ésse éle á').replace(/\bSLO\b/g, 'ésse éle ó').replace(/\bMTTR\b/g, 'tempo médio de recuperação').replace(/\bICMP\b/g, 'í cê ême pê')
        .replace(/(\d+(?:,\d+)?)\s?h\b/g, '$1 horas').replace(/(\d+)\s?d\b/g, '$1 dias').replace(/(\d+)\s?min\b/g, '$1 minutos').replace(/(\d+(?:,\d+)?)\s?ms\b/g, '$1 milissegundos')
        .replace(/\s\|\s/g, ', ').replace(/\s?>=\s?/g, ' maior ou igual a ').replace(/\s?<=\s?/g, ' menor ou igual a ').replace(/\s?>\s?/g, ' maior que ').replace(/\s?<\s?/g, ' menor que ')
        .replace(/\s+/g, ' ').trim();
    let keepAlive = null;
    function say(text) {
        if (!window.speechSynthesis || !text) return;
        const v = vozNavegador();
        const frases = (vozTexto(text).match(/[^.!?]+[.!?]*/g) || []).map((f) => f.trim()).filter(Boolean);
        frases.forEach((f) => {
            const u = new SpeechSynthesisUtterance(f);
            u.lang = v ? v.lang : 'pt-BR';
            if (v) u.voice = v;
            u.rate = Math.max(0.8, Math.min(1.2, cfg.rate * 0.95));
            u.pitch = 1;
            u.volume = Math.max(0.1, cfg.volume);
            speechSynthesis.speak(u);
        });
        // Chrome para de falar sozinho em falas longas: um resume periódico mantém a fila andando
        clearInterval(keepAlive);
        keepAlive = setInterval(() => {
            if (!speechSynthesis.speaking && !speechSynthesis.pending) { clearInterval(keepAlive); return; }
            if (speechSynthesis.paused) speechSynthesis.resume();
        }, 5000);
    }

    // ao ativar o som (primeiro clique da sessão do navegador) avisa o que será monitorado
    const AVISO = 'Som ativado. O NOC 360 vai monitorar os alertas do Zabbix, os novos chamados e os SLAs.';
    function announce(force = false) {
        try {
            if (!force && (sessionStorage.getItem('noc360.avisou') || isMuted() || isQuiet())) return;
            sessionStorage.setItem('noc360.avisou', '1');
        } catch (e) { /* sem sessionStorage: avisa uma vez por página */ }
        setTimeout(() => say(AVISO), 150);
    }

    // ---------------------------------------------------------------- silêncio (por navegador)
    const mutedUntil = () => (store.get() || {}).muted || 0;
    const isMuted = () => mutedUntil() > Date.now();
    function isQuiet() {
        const q = cfg.quiet;
        if (!q || !q.from || !q.to) return false;
        const d = new Date(), m = d.getHours() * 60 + d.getMinutes();
        const t = (s) => { const [h, mi] = String(s).split(':').map(Number); return h * 60 + (mi || 0); };
        const a = t(q.from), b = t(q.to);
        return a <= b ? m >= a && m < b : m >= a || m < b;
    }

    // ---------------------------------------------------------------- sinalizador
    let flagEl, countEl, listEl, muteBtn, stateEl;
    function buildFlag() {
        if (built) return;
        built = true;
        flagEl = document.createElement('div');
        flagEl.className = 'nav-item dropdown noc360-flag';
        flagEl.innerHTML = `<button type="button" class="nav-link px-2 border-0 bg-transparent" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Alertas do NOC 360" title="Alertas · NOC 360">
                <i class="ti ti-radar-2 fs-2"></i><span class="noc360-flag-count" hidden></span></button>
            <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow noc360-flag-menu">
                <div class="noc360-flag-head"><b><i class="ti ti-radar-2 me-1"></i>NOC 360 · alertas</b><span class="noc360-flag-state" data-state></span></div>
                <div class="noc360-flag-list" data-list><div class="noc360-flag-empty">Carregando…</div></div>
                <div class="noc360-flag-foot"><span><button type="button" class="btn btn-sm btn-ghost-secondary" data-mute></button><button type="button" class="btn btn-sm btn-ghost-secondary" data-voz title="Testar a voz"><i class="ti ti-speakerphone"></i></button></span></div>
            </div>`;
        if (standalone) {
            const bar = document.querySelector('.topbar-right');
            if (bar) { flagEl.classList.add('noc360-flag-bar'); bar.insertBefore(flagEl, bar.querySelector('#reload')); }
            else { flagEl.classList.add('noc360-flag-floating'); document.body.appendChild(flagEl); }
            flagEl.querySelector('button.nav-link').classList.add('icon');
            flagEl.querySelector('button.nav-link').addEventListener('click', (ev) => { ev.stopPropagation(); flagEl.classList.toggle('show'); flagEl.classList.toggle('noc360-flag-left', flagEl.getBoundingClientRect().right < 440); });
            document.addEventListener('click', (ev) => { if (!flagEl.contains(ev.target)) flagEl.classList.remove('show'); });
        }
        const header = standalone ? null : document.querySelector('header .avisos-bell') || document.querySelector('header .user-menu') || document.querySelector('header .navbar-nav.order-md-last');
        if (header) header.parentNode.insertBefore(flagEl, header);
        else if (!flagEl.isConnected) { flagEl.classList.add('noc360-flag-floating'); document.body.appendChild(flagEl); }
        fixIcons(flagEl);
        countEl = flagEl.querySelector('.noc360-flag-count');
        listEl = flagEl.querySelector('[data-list]');
        muteBtn = flagEl.querySelector('[data-mute]');
        stateEl = flagEl.querySelector('[data-state]');
        flagEl.querySelector('button.nav-link').addEventListener('click', () => { unseen = false; flagEl.classList.remove('noc360-flag-pulse'); unlock(); });
        muteBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            const s = store.get() || {};
            s.muted = isMuted() ? 0 : Date.now() + cfg.mute_minutes * 60000;
            store.set(s);
            refreshState();
        });
        flagEl.querySelector('[data-voz]').addEventListener('click', (ev) => { ev.stopPropagation(); unlock(); play('sino'); setTimeout(() => announce(true), 900); });
    }
    function refreshState() {
        if (!flagEl) return;
        const m = isMuted();
        stateEl.innerHTML = m ? `<i class="ti ti-volume-off me-1"></i>som e voz silenciados até ${new Date(mutedUntil()).toTimeString().slice(0, 5)}` : (isQuiet() ? '<i class="ti ti-moon me-1"></i>horário de silêncio' : '');
        muteBtn.innerHTML = m ? '<i class="ti ti-volume me-1"></i>Religar som e voz' : `<i class="ti ti-volume-off me-1"></i>Silenciar ${cfg.mute_minutes} min`;
        fixIcons(stateEl); fixIcons(muteBtn);
    }
    function renderFlag(items) {
        buildFlag();
        countEl.hidden = !items.length;
        countEl.textContent = items.length > 99 ? '99+' : items.length;
        countEl.style.background = items[0] ? items[0].color : '';
        flagEl.style.setProperty('--n-flag', items[0] ? items[0].color : '#087E83');
        flagEl.classList.toggle('noc360-flag-active', items.length > 0);
        const show = items.slice(0, 12);
        listEl.innerHTML = show.length ? show.map((it) => `<a class="noc360-flag-item" href="${esc(it.url)}" target="_blank" rel="noopener">
                <span class="noc360-ico" style="--c:${esc(it.color)}"><i class="ti ${esc(it.icon)}"></i></span>
                <span class="noc360-txt"><b>${esc(it.title)}</b><small>${esc(it.sub)}</small></span></a>`).join('')
            + (items.length > show.length ? `<div class="noc360-flag-empty">e mais ${items.length - show.length}…</div>` : '')
            : '<div class="noc360-flag-empty"><i class="ti ti-mood-happy me-1"></i>Nada pedindo atenção agora.</div>';
        fixIcons(listEl);
        refreshState();
    }

    // ---------------------------------------------------------------- pop-up no rodapé
    let stack = null;
    function toast(a) {
        if (!stack) {
            stack = Object.assign(document.createElement('div'), {className: 'noc360-toasts'});
            document.body.appendChild(stack);
        }
        while (stack.children.length >= cfg.popup_max) stack.firstElementChild.remove();
        const el = document.createElement('div');
        el.className = 'noc360-toast';
        el.style.setProperty('--c', a.color);
        el.setAttribute('role', a.sticky ? 'alert' : 'status');
        el.innerHTML = `<span class="noc360-ico" style="--c:${esc(a.color)}"><i class="ti ${esc(a.icon)}"></i></span>
            <div class="noc360-txt"><small>${esc(a.group)} · ${esc(a.label)}</small><b>${esc(a.title)}</b>${a.message ? `<span>${esc(a.message)}</span>` : ''}
            ${a.url ? `<a class="btn btn-sm btn-primary mt-2" href="${esc(a.url)}" target="_blank" rel="noopener">Abrir</a>` : ''}</div>
            <button type="button" class="btn-close" aria-label="Fechar"></button>`;
        const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 250); };
        el.querySelector('.btn-close').addEventListener('click', close);
        fixIcons(el);
        stack.appendChild(el);
        requestAnimationFrame(() => el.classList.add('in'));
        if (!a.sticky) setTimeout(close, cfg.popup_seconds * 1000);
    }

    // um alerta: cada canal conforme a configuração do tipo
    function show(a, test = false) {
        const silent = !test && (isMuted() || isQuiet());
        if (a.popup) toast(a);
        if (a.flag && !test) { unseen = true; flagEl && flagEl.classList.add('noc360-flag-pulse'); }
        if (a.sound && !silent) play(a.tone);
        if (a.voice && !silent) setTimeout(() => say(a.speech), a.sound ? 900 : 0);
    }

    // ---------------------------------------------------------------- o que é novo
    const dur = (s) => { s = Math.max(0, Math.round(s)); const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60); return d ? `${d} d ${h} h` : h ? `${h} h ${m} min` : `${m} min`; };
    const num = (n) => String(n).split('').join(' ');
    function process(d) {
        const T = d.tipos, now = d.now, links = d.links;
        const mk = (key, title, message, url, speech, extra = {}) => {
            const t = T[key], has = (c) => t.channels.includes(c);
            return Object.assign({key, group: t.group, label: t.label, icon: t.icon, color: t.color, tone: t.tone, title, message, url, speech,
                popup: has('popup'), sound: has('sound'), voice: has('voice'), sticky: has('sticky'), flag: has('flag')}, extra);
        };
        const pUrl = (p) => p.hostid ? `${links.zabbix}/zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=${encodeURIComponent(p.hostid)}` : links.zabbix;
        const tUrl = (id) => `${links.glpi}/front/ticket.form.php?id=${id}`;
        const st = store.get();
        const first = !st || !st.ev && st.ev !== 0;
        const next = Object.assign({ev: 0, rec: 0, tk: 0, sla: {vence: [], vencido: []}, muted: 0}, st || {});
        const items = [], flag = [];

        d.problems.forEach((p) => {
            const key = 'zbx_' + p.severity, t = T[key];
            if (!t) return;
            const resolved = p.r_eventid > 0;
            if (!resolved && t.channels.includes('flag')) flag.push({title: p.name, sub: `${p.host} · há ${dur(now - p.clock)}${p.ack ? ' · reconhecido' : ''}`, color: t.color, sev: p.severity, url: pUrl(p), icon: t.icon});
            if (!first && !resolved && p.eventid > next.ev) items.push(mk(key, `${t.label} · ${p.host}`, p.name, pUrl(p), `Novo alerta no Zabbix. Severidade ${t.label}. Host ${p.host}. Alerta: ${p.name}.`));
            if (!first && resolved && p.r_eventid > next.rec && T.zbx_ok) items.push(mk('zbx_ok', `Resolvido · ${p.host}`, `${p.name} (durou ${dur(p.r_clock - p.clock)})`, pUrl(p), `Problema resolvido em ${p.host}. ${p.name}.`));
        });
        const maxEv = d.problems.reduce((m, p) => Math.max(m, p.eventid), next.ev);
        const maxRec = d.problems.reduce((m, p) => Math.max(m, p.r_eventid), next.rec);

        let maxTk = next.tk;
        d.tickets.forEach((t) => {
            if (!first && t.id > next.tk) items.push(mk('glpi_new', `Chamado novo #${t.id}${t.client ? ' · ' + t.client : ''}`, t.name, tUrl(t.id), `Chamado novo, número ${t.id}. ${t.name}.`));
            maxTk = Math.max(maxTk, t.id);
        });
        const keep = {vence: [], vencido: []};
        d.tickets.forEach((t) => {
            if (!t.due || t.pending) return;
            const late = t.due < now, left = t.due - now;
            if (!late && left > d.prefs.sla_warn * 60) return;
            const kind = late ? 'vencido' : 'vence', key = late ? 'glpi_slalate' : 'glpi_sla1h', tp = T[key];
            if (tp.channels.includes('flag')) flag.push({title: `#${t.id} ${t.name}`, sub: late ? `SLA vencido há ${dur(now - t.due)}` : `SLA vence em ${dur(left)}`, color: tp.color, sev: late ? 5 : 3, url: tUrl(t.id), icon: tp.icon});
            keep[kind].push(t.id);
            if (!first && !(next.sla[kind] || []).includes(t.id)) {
                items.push(mk(key, `${late ? 'SLA vencido' : 'SLA vence em ' + dur(left)} · chamado #${t.id}`, t.name, tUrl(t.id), `${late ? 'SLA vencido' : 'SLA vencendo'}, chamado número ${t.id}.`));
            }
        });
        // grava logo o que já foi avisado, para outra aba não repetir
        store.set(Object.assign(next, {ev: maxEv, rec: maxRec, tk: maxTk, sla: keep}));
        flag.sort((a, b) => b.sev - a.sev);
        return {items, flag};
    }

    // ---------------------------------------------------------------- busca
    async function load() {
        if (stopped) return;
        try {
            const r = await fetch(api, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if (r.status === 401 || r.status === 403 || r.status === 404) { stopped = true; return; }
            const d = await r.json();
            if (!d.ok) return;
            Object.assign(cfg, d.prefs);
            const {items, flag} = process(d);
            renderFlag(flag);
            if (unseen) flagEl.classList.add('noc360-flag-pulse');
            items.forEach((a, i) => setTimeout(() => show(a), i * 1600));
        } catch (e) { /* sem rede: tenta de novo no próximo ciclo */ }
        clearTimeout(timer);
        timer = setTimeout(load, (cfg.poll || 20) * 1000);
    }

    // teste manual: NOC360.test('zbx_5')
    window.NOC360 = {
        async test(key = 'zbx_5') {
            unlock();
            const d = await (await fetch(api, {credentials: 'same-origin'})).json();
            const t = d.tipos[key];
            if (t) show({group: t.group, label: t.label, icon: t.icon, color: t.color, tone: t.tone, title: `Teste · ${t.label}`, message: 'Assim chega este tipo de alerta.', url: '', speech: `Teste de alerta: ${t.label}.`,
                popup: true, sound: t.channels.includes('sound'), voice: t.channels.includes('voice'), sticky: false, flag: false}, true);
        },
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    load();
})();
