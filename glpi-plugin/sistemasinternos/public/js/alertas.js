/**
 * Sistemas Internos 360 — alertas em todas as telas do GLPI:
 * sinalizador no topo (contador do que pede atenção agora, piscando quando chega algo),
 * som (gerado no navegador), voz (pt-BR) e pop-up no rodapé da página.
 * Cada tipo de alerta liga ou desliga cada canal em Sistemas Internos → Configurações → Alertas.
 */
(() => {
    if (window.SI360 || !document.body || document.querySelector('.page-anonymous, form#login_form, .login-page')) return;
    const root = (window.CFG_GLPI && CFG_GLPI.root_doc) || '';
    const api = `${root}/plugins/sistemasinternos/front/ajax.php`;
    const onPluginPage = location.pathname.includes('/plugins/sistemasinternos/');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const csrf = () => (document.querySelector('meta[property="glpi:csrf_token"]') || {}).content || '';
    let cfg = {poll: 30, popup: {seconds: 20, max: 5, position: 'right'}, volume: 0.7, rate: 1.05, quiet: false, muted: false, muteMinutes: 60};
    let timer = null, stopped = false, built = false;

    // ---------------------------------------------------------------- som
    let ctx = null, unlocked = false;
    const unlock = () => {
        try {
            ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
            if (ctx.state === 'suspended') ctx.resume();
            unlocked = true;
            flagEl && flagEl.classList.remove('si360-flag-locked');
        } catch (e) { /* sem áudio */ }
    };
    ['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, unlock, {once: false, passive: true}));

    // [frequência inicial, início (s), duração (s), forma, frequência final]
    const TONES = {
        bip: [[880, 0, 0.18, 'square']],
        duplo: [[880, 0, 0.13, 'square'], [880, 0.2, 0.13, 'square']],
        sino: [[1318, 0, 0.9, 'sine'], [1975, 0.02, 0.7, 'sine']],
        sirene: [[620, 0, 0.45, 'sawtooth', 1240], [1240, 0.45, 0.45, 'sawtooth', 620], [620, 0.9, 0.45, 'sawtooth', 1240]],
        suave: [[660, 0, 0.3, 'sine'], [880, 0.16, 0.35, 'sine']],
    };
    function play(name) {
        if (!unlocked || !ctx) { flagEl && flagEl.classList.add('si360-flag-locked'); return; }
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
    let voice = null;
    const pickVoice = () => {
        const all = (window.speechSynthesis && speechSynthesis.getVoices()) || [];
        voice = all.find((v) => /^pt[-_]BR/i.test(v.lang)) || all.find((v) => /^pt/i.test(v.lang)) || null;
    };
    if (window.speechSynthesis) { pickVoice(); speechSynthesis.onvoiceschanged = pickVoice; }
    function say(text) {
        if (!window.speechSynthesis || !text) return;
        const u = new SpeechSynthesisUtterance(text);
        u.lang = 'pt-BR';
        if (voice) u.voice = voice;
        u.rate = cfg.rate;
        u.volume = Math.max(0.1, cfg.volume);
        speechSynthesis.speak(u);
    }

    // ---------------------------------------------------------------- sinalizador
    let flagEl, countEl, listEl, muteBtn, unseen = false;
    function buildFlag() {
        if (built) return;
        built = true;
        flagEl = document.createElement('div');
        flagEl.className = 'nav-item dropdown si360-flag';
        flagEl.innerHTML = `<button type="button" class="nav-link px-2 border-0 bg-transparent" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Alertas do Sistemas Internos" title="Alertas · Sistemas Internos 360">
                <i class="ti ti-alert-hexagon fs-2"></i><span class="si360-flag-count" hidden></span></button>
            <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow si360-flag-menu">
                <div class="si360-flag-head"><b><i class="ti ti-building-community me-1"></i>Sistemas Internos 360</b><span class="si360-flag-state" data-state></span></div>
                <div class="si360-flag-list" data-list><div class="si360-flag-empty">Carregando…</div></div>
                <div class="si360-flag-foot"><button type="button" class="btn btn-sm btn-ghost-secondary" data-mute></button><a class="btn btn-sm btn-primary" data-painel href="#"><i class="ti ti-chart-donut-3 me-1"></i>Painel 360</a></div>
            </div>`;
        const header = document.querySelector('header .avisos-bell') || document.querySelector('header .user-menu') || document.querySelector('header .navbar-nav.order-md-last');
        if (header) header.parentNode.insertBefore(flagEl, header);
        else { flagEl.classList.add('si360-flag-floating'); document.body.appendChild(flagEl); }
        countEl = flagEl.querySelector('.si360-flag-count');
        listEl = flagEl.querySelector('[data-list]');
        muteBtn = flagEl.querySelector('[data-mute]');
        flagEl.querySelector('button.nav-link').addEventListener('click', () => { unseen = false; flagEl.classList.remove('si360-flag-pulse'); unlock(); });
        muteBtn.addEventListener('click', async (ev) => {
            ev.stopPropagation();
            await fetch(api, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-Glpi-Csrf-Token': csrf()},
                body: JSON.stringify({action: 'mute', minutes: cfg.muted ? 0 : cfg.muteMinutes})}).catch(() => {});
            load();
        });
    }
    function renderFlag(d) {
        buildFlag();
        const f = d.flag || {count: 0, items: []};
        countEl.hidden = !f.count;
        countEl.textContent = f.count > 99 ? '99+' : f.count;
        countEl.style.background = f.color || '';
        flagEl.style.setProperty('--si-flag', f.color || '#3769B2');
        flagEl.classList.toggle('si360-flag-active', f.count > 0);
        listEl.innerHTML = f.items.length ? f.items.map((it) => `<a class="si360-flag-item" href="${esc(it.url)}"${/^https?:/.test(it.url) ? ' target="_blank" rel="noopener"' : ''}>
                <span class="si360-ico" style="--c:${esc(it.color)}"><i class="ti ${esc(it.icon)}"></i></span>
                <span class="si360-txt"><b>${esc(it.title)}</b><small>${esc(it.sub)}</small></span></a>`).join('')
            + (f.count > f.items.length ? `<div class="si360-flag-empty">e mais ${f.count - f.items.length}…</div>` : '')
            : '<div class="si360-flag-empty"><i class="ti ti-mood-happy me-1"></i>Nada pedindo atenção agora.</div>';
        const state = flagEl.querySelector('[data-state]');
        state.innerHTML = cfg.muted ? `<i class="ti ti-volume-off me-1"></i>som e voz silenciados até ${esc(d.mutedUntil)}` : (cfg.quiet ? '<i class="ti ti-moon me-1"></i>horário de silêncio' : '');
        muteBtn.innerHTML = cfg.muted ? '<i class="ti ti-volume me-1"></i>Religar som e voz' : `<i class="ti ti-volume-off me-1"></i>Silenciar ${cfg.muteMinutes} min`;
        flagEl.querySelector('[data-painel]').href = d.painel || '#';
        (d.errors || []).forEach((m) => console.warn('Sistemas Internos 360:', m));
    }

    // ---------------------------------------------------------------- pop-up no rodapé
    let stack = null;
    function toast(a) {
        if (!stack) {
            stack = Object.assign(document.createElement('div'), {className: 'si360-toasts'});
            document.body.appendChild(stack);
        }
        stack.classList.toggle('left', cfg.popup.position === 'left');
        while (stack.children.length >= cfg.popup.max) stack.firstElementChild.remove();
        const el = document.createElement('div');
        el.className = 'si360-toast';
        el.style.setProperty('--c', a.color);
        el.setAttribute('role', a.sticky ? 'alert' : 'status');
        el.innerHTML = `<span class="si360-ico" style="--c:${esc(a.color)}"><i class="ti ${esc(a.icon)}"></i></span>
            <div class="si360-txt"><small>${esc(a.group)} · ${esc(a.label)}</small><b>${esc(a.title)}</b>${a.message ? `<span>${esc(a.message)}</span>` : ''}
            ${a.url ? `<a class="btn btn-sm btn-primary mt-2" href="${esc(a.url)}"${/^https?:/.test(a.url) ? ' target="_blank" rel="noopener"' : ''}>Abrir</a>` : ''}</div>
            <button type="button" class="btn-close" aria-label="Fechar"></button>`;
        const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 250); };
        el.querySelector('.btn-close').addEventListener('click', close);
        stack.appendChild(el);
        requestAnimationFrame(() => el.classList.add('in'));
        if (!a.sticky) setTimeout(close, cfg.popup.seconds * 1000);
    }

    // um alerta: cada canal conforme a configuração do tipo
    function show(a, test = false) {
        const silent = !test && (cfg.muted || cfg.quiet);
        if (a.popup) toast(a);
        if (a.flag && !test) { unseen = true; flagEl && flagEl.classList.add('si360-flag-pulse'); }
        if (a.sound && !silent) play(a.tone);
        if (a.voice && !silent) setTimeout(() => say(a.speech), a.sound ? 900 : 0);
    }

    // ---------------------------------------------------------------- busca
    async function load() {
        if (stopped) return;
        try {
            const r = await fetch(`${api}?action=poll`, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (r.status === 401 || r.status === 403 || r.redirected) { stopped = true; return; }
            const d = await r.json();
            if (!d.ok) { if (d.disabled) stopped = true; return; }
            Object.assign(cfg, {poll: d.poll, popup: d.popup, volume: d.volume, rate: d.rate, quiet: d.quiet, muted: d.muted, muteMinutes: d.muteMinutes});
            if (!d.everywhere && !onPluginPage) { stopped = true; return; }
            renderFlag(d);
            if (unseen) flagEl.classList.add('si360-flag-pulse');
            (d.items || []).forEach((a, i) => setTimeout(() => show(a), i * 1600));
        } catch (e) { /* sem rede: tenta de novo no próximo ciclo */ }
        clearTimeout(timer);
        timer = setTimeout(load, (cfg.poll || 30) * 1000);
    }

    // botão "Testar" da configuração (com os canais marcados na tela, mesmo antes de salvar)
    window.SI360 = {
        async test(key, overrides = {}) {
            unlock();
            const r = await fetch(`${api}?action=test&key=${encodeURIComponent(key)}`, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            const d = await r.json();
            if (d.ok && d.item) show(Object.assign(d.item, overrides, {popup: true}), true);
        },
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    load();
})();
