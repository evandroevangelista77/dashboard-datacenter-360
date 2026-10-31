/**
 * Gestão de Eventos — avisos de contagem regressiva em qualquer tela do GLPI: pop-up para cada responsável,
 * som (gerado no próprio navegador, sem arquivos), dispensar e adiar. O servidor diz quem recebe e o que está
 * pendente (front/alertas.php); aqui só se mostra, se toca e se confirma.
 */
(() => {
    if (window.GEAvisos) return;
    const me = document.currentScript || document.querySelector('script[src*="gestaoeventos/js/avisos.js"]');
    if (!me) return;
    const base = me.src.split('/js/avisos.js')[0];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const store = {
        get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* sem armazenamento */ } },
    };

    // ------------------------------------------------------------ som (Web Audio)
    const Sound = {
        ctx: null,
        pending: null,
        ensure() {
            if (!this.ctx) { const C = window.AudioContext || window.webkitAudioContext; if (C) this.ctx = new C(); }
            return this.ctx;
        },
        unlocked() { return !!this.ctx && this.ctx.state === 'running'; },
        tone(freq, start, dur, type, vol, decay = true) {
            const c = this.ctx; const o = c.createOscillator(); const g = c.createGain();
            o.type = type; o.frequency.setValueAtTime(freq, start);
            g.gain.setValueAtTime(0.0001, start); g.gain.exponentialRampToValueAtTime(Math.max(0.0002, vol), start + 0.015);
            if (decay) g.gain.exponentialRampToValueAtTime(0.0001, start + dur); else g.gain.setValueAtTime(vol, start + dur - 0.03), g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
            o.connect(g); g.connect(c.destination); o.start(start); o.stop(start + dur + 0.05);
        },
        play(kind, volume) {
            if (kind === 'none' || store.get('ge-mute') === '1') return true;
            const c = this.ensure(); if (!c) return false;
            if (c.state !== 'running') { c.resume?.().catch(() => {}); if (c.state !== 'running') { this.pending = [kind, volume]; return false; } }
            const v = Math.max(0.03, Math.min(1, (Number(volume) || 70) / 100)) * 0.5; const t = c.currentTime + 0.02;
            if (kind === 'beep') { [0, 0.22].forEach((d) => this.tone(880, t + d, 0.16, 'sine', v)); }
            else if (kind === 'bell') { [1, 2.76, 5.4].forEach((m, i) => this.tone(520 * m, t, 1.6 / (i + 1), 'sine', v / (i + 1))); }
            else if (kind === 'alarm') { for (let i = 0; i < 6; i++) this.tone(i % 2 ? 640 : 880, t + i * 0.22, 0.2, 'square', v * 0.55, false); }
            else { [523.25, 659.25, 783.99].forEach((f, i) => this.tone(f, t + i * 0.16, 0.9, 'sine', v)); }
            return true;
        },
    };
    const unlock = () => {
        Sound.ensure()?.resume?.().then(() => {
            if (Sound.pending) { const [k, v] = Sound.pending; Sound.pending = null; Sound.play(k, v); document.querySelectorAll('.ge-toast-hint').forEach((n) => n.remove()); }
        }).catch(() => {});
    };
    ['click', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, unlock, {passive: true}));

    // ------------------------------------------------------------ pop-ups
    let stack = null;
    let offsetMs = 0; // relógio do servidor − relógio do navegador
    const shown = new Map();
    const fmt = (sec) => {
        if (sec <= 0) return 'começou';
        const d = Math.floor(sec / 86400); const h = Math.floor(sec % 86400 / 3600); const m = Math.floor(sec % 3600 / 60); const s = Math.floor(sec % 60);
        const p = (n) => String(n).padStart(2, '0');
        return (d ? `${d}d ` : '') + `${p(h)}:${p(m)}:${p(s)}`;
    };
    const nowSec = () => (Date.now() + offsetMs) / 1000;
    const ensureStack = () => {
        if (stack) return stack;
        stack = Object.assign(document.createElement('div'), {className: 'ge-toasts'});
        document.body.appendChild(stack);
        return stack;
    };
    const urgency = (left) => (left <= 0 ? 'now' : left <= 172800 ? 'hot' : left <= 604800 ? 'warm' : 'cool');

    function ack(a, mode, minutes) {
        fetch(`${base}/front/alertas.php?a=ack&event=${a.id}&mode=${mode}&minutes=${minutes || 60}`, {credentials: 'same-origin'}).catch(() => {});
    }

    function show(a, opts = {}) {
        const key = `${a.id}:${a.offset}`;
        if (!opts.test && shown.has(key)) return;
        const el = Object.assign(document.createElement('div'), {className: `ge-toast ${urgency(a.start - nowSec())}`});
        el.innerHTML = `<div class="ge-toast-head"><i class="ti ti-bell-ringing"></i><b>${esc(opts.test ? 'Teste de aviso' : a.label.charAt(0).toUpperCase() + a.label.slice(1))}</b>`
            + `<button type="button" class="btn-close" aria-label="Fechar" data-x="seen"></button></div>`
            + `<a class="ge-toast-name" href="${esc(a.url)}">${esc(a.name)}</a><div class="ge-toast-sub">${esc(a.code)}${a.city ? ` · ${esc(a.city)}` : ''}${a.phase ? ` · etapa ${esc(a.phase)}` : ''}${a.late ? ' · <b class="text-danger">atrasada</b>' : ''}</div>`
            + `<div class="ge-toast-count"><span class="ge-cd">${fmt(a.start - nowSec())}</span><small>para o início</small></div>`
            + (a.pending ? `<div class="ge-toast-chip"><i class="ti ti-signature"></i>${a.pending} aprovação(ões) pendente(s)</div>` : '')
            + `<div class="ge-toast-actions"><a class="btn btn-sm btn-primary" href="${esc(a.url)}">Abrir</a>`
            + `<select class="form-select form-select-sm ge-snooze" title="Adiar"><option value="">Adiar…</option><option value="15">15 min</option><option value="60">1 hora</option><option value="240">4 horas</option><option value="1440">Amanhã</option></select>`
            + `<button type="button" class="btn btn-sm btn-outline-secondary" data-x="seen">Dispensar</button></div>`;
        ensureStack().appendChild(el);
        const rec = {el, a, timer: null, tick: null};
        if (!opts.test) shown.set(key, rec);
        const close = () => { clearInterval(rec.tick); clearInterval(rec.timer); el.classList.add('out'); setTimeout(() => el.remove(), 250); if (!opts.test) shown.delete(key); };
        el.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', () => { if (!opts.test) ack(a, 'seen'); close(); }));
        el.querySelector('.ge-snooze').addEventListener('change', (ev) => { const m = Number(ev.target.value); if (!m) return; if (!opts.test) ack(a, 'snooze', m); close(); });
        rec.tick = setInterval(() => { el.querySelector('.ge-cd').textContent = fmt(a.start - nowSec()); el.className = `ge-toast ${urgency(a.start - nowSec())}`; }, 1000);
        // som: uma vez por aviso e por navegador (outras abas abertas não repetem)
        const lock = `ge-snd-${key}`;
        const last = Number(store.get(lock) || 0);
        if (opts.test || Date.now() - last > 20000) {
            store.set(lock, String(Date.now()));
            if (!Sound.play(a.sound, a.volume) && a.sound !== 'none' && store.get('ge-mute') !== '1') {
                el.insertAdjacentHTML('beforeend', '<div class="ge-toast-hint"><i class="ti ti-volume-off"></i> Clique em qualquer lugar da página para ativar o som.</div>');
            }
        }
        if (a.repeat > 0 && a.sound !== 'none') {
            rec.timer = setInterval(() => { Sound.play(a.sound, a.volume); }, a.repeat * 1000);
        }
        return rec;
    }

    // ------------------------------------------------------------ consulta ao servidor
    let stopped = false;
    let timer = null;
    async function poll() {
        if (stopped || document.hidden) return schedule(30);
        try {
            const r = await fetch(`${base}/front/alertas.php?a=poll`, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (r.status === 401 || r.status === 403 || r.status === 404) { stopped = true; return; }
            const d = await r.json();
            offsetMs = d.now * 1000 - Date.now();
            (d.alerts || []).forEach((a) => show(a));
            schedule(d.poll || 60);
        } catch (e) { schedule(120); }
    }
    function schedule(sec) { clearTimeout(timer); timer = setTimeout(poll, sec * 1000); }
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !stopped) { clearTimeout(timer); timer = setTimeout(poll, 800); } });

    window.GEAvisos = {
        Sound, fmt, nowSec,
        setOffset: (ms) => { offsetMs = ms; },
        testSound: (kind, vol) => { unlock(); Sound.ensure(); Sound.play(kind || 'chime', vol); },
        testPopup: (kind, vol) => {
            unlock();
            show({id: 0, offset: 0, code: 'EV-TESTE', name: 'Evento de teste', url: '#', city: 'São Paulo', phase: 'Execução', late: false, start: nowSec() + 90061, sound: kind || 'chime', volume: Number(vol) || 70, repeat: 0, pending: 1, label: '1 dia antes'}, {test: true});
        },
    };
    setTimeout(poll, 2500);
})();
