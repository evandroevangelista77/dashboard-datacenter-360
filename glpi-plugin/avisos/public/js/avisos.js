/**
 * Avisos — sino no topo da tela (não lidos e os últimos avisos) e pop-up dos
 * avisos novos. Procura avisos novos a cada N segundos e quando a aba volta a ficar visível.
 */
(() => {
    if (window.__avisos || !document.body || document.querySelector('.page-anonymous, form#login_form, .login-page')) return;
    window.__avisos = true;
    const root = (window.CFG_GLPI && CFG_GLPI.root_doc) || '';
    const api = `${root}/plugins/avisos/front/ajax.php`;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    let poll = 60, timer = null, page = `${root}/plugins/avisos/front/avisos.php`, stopped = false;

    // ---------------------------------------------------------------- sino
    const bell = document.createElement('div');
    bell.className = 'nav-item dropdown avisos-bell';
    bell.innerHTML = `<button type="button" class="nav-link px-2 border-0 bg-transparent" data-bs-toggle="dropdown" aria-label="Avisos" title="Avisos">
            <i class="ti ti-bell fs-2"></i><span class="avisos-count" hidden></span></button>
        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow avisos-menu">
            <div class="avisos-menu-head"><b>Avisos</b><button type="button" class="btn btn-sm btn-ghost-secondary ms-auto" data-read-all hidden><i class="ti ti-checks me-1"></i>Marcar todos como lidos</button></div>
            <div class="avisos-list" data-list><div class="avisos-empty">Carregando…</div></div>
            <a class="avisos-menu-foot" data-page href="${esc(page)}">Ver todos os avisos</a>
        </div>`;
    const header = document.querySelector('header .user-menu') || document.querySelector('header .navbar-nav.order-md-last');
    if (header) {
        header.parentNode.insertBefore(bell, header);
    } else {
        bell.classList.add('avisos-bell-floating');
        document.body.appendChild(bell);
    }
    const count = bell.querySelector('.avisos-count');
    const list = bell.querySelector('[data-list]');
    const readAll = bell.querySelector('[data-read-all]');

    const item = (a) => `<a class="avisos-item lvl-${esc(a.level)}${a.read ? '' : ' unread'}" href="${esc(a.url || page)}">
            <span class="avisos-ico" style="--c:${esc(a.color)}"><i class="ti ${esc(a.icon)}"></i></span>
            <span class="avisos-txt"><b>${esc(a.title)}</b>${a.message ? `<span>${esc(a.message)}</span>` : ''}<small>${esc(a.sourceName)} · ${esc(a.ago)}</small></span></a>`;

    function render(d) {
        count.hidden = !d.unread;
        count.textContent = d.unread > 99 ? '99+' : d.unread;
        readAll.hidden = !d.unread;
        list.innerHTML = d.latest.length ? d.latest.map(item).join('') : '<div class="avisos-empty"><i class="ti ti-bell-off me-1"></i>Nenhum aviso por enquanto.</div>';
        bell.querySelector('[data-page]').href = d.page || page;
        d.new.forEach(toast);
    }

    // ---------------------------------------------------------------- pop-up
    let stack = document.querySelector('.avisos-toasts');
    if (!stack) {
        stack = Object.assign(document.createElement('div'), {className: 'avisos-toasts'});
        document.body.appendChild(stack);
    }
    function toast(a) {
        const el = document.createElement('div');
        el.className = `avisos-toast lvl-${a.level}`;
        el.setAttribute('role', a.level === 'danger' || a.level === 'warning' ? 'alert' : 'status');
        el.innerHTML = `<span class="avisos-ico" style="--c:${esc(a.color)}"><i class="ti ${esc(a.icon)}"></i></span>
            <div class="avisos-txt"><small>${esc(a.sourceName)}</small><b>${esc(a.title)}</b>${a.message ? `<span>${esc(a.message)}</span>` : ''}
            ${a.url ? `<a class="btn btn-sm btn-primary mt-2" href="${esc(a.url)}">Abrir</a>` : ''}</div>
            <button type="button" class="btn-close" aria-label="Fechar"></button>`;
        const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 250); };
        el.querySelector('.btn-close').addEventListener('click', close);
        stack.appendChild(el);
        requestAnimationFrame(() => el.classList.add('in'));
        // pendências (aprovar, atraso) ficam até fechar; os demais somem sozinhos
        if (a.level === 'info' || a.level === 'success') setTimeout(close, 15000);
    }

    // ---------------------------------------------------------------- busca
    async function load() {
        if (stopped) return;
        try {
            const r = await fetch(`${api}?action=poll`, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (r.status === 401 || r.status === 403 || r.redirected) { stopped = true; return; }
            const d = await r.json();
            if (!d.ok) return;
            poll = d.poll || poll;
            page = d.page || page;
            render(d);
        } catch (e) { /* sem rede: tenta de novo no próximo ciclo */ }
        clearTimeout(timer);
        timer = setTimeout(load, poll * 1000);
    }
    async function post(body) {
        const r = await fetch(api, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify(body)});
        return r.json();
    }
    readAll.addEventListener('click', async (ev) => { ev.stopPropagation(); await post({action: 'read_all'}); load(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    load();
})();
