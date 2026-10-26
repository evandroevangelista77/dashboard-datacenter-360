/**
 * Painel lateral (o mesmo nos plugins RH, Reembolso, PMO e Ciclo): um cartão com
 * data-drawer="chave" abre o conteúdo do <template id="dr-chave"> (data-title,
 * data-icon e data-color) num painel que entra pela direita. Também pode ser
 * aberto pelo código: OakDrawer.open({title, icon, color, html}).
 */
(() => {
    if (window.OakDrawer) return;
    let drawer = null;
    let backdrop = null;
    let lastFocus = null;

    function build() {
        if (drawer) return;
        backdrop = Object.assign(document.createElement('div'), {className: 'oak-drawer-backdrop', hidden: true});
        drawer = document.createElement('aside');
        drawer.className = 'oak-drawer';
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.innerHTML = '<div class="oak-drawer-head"><span class="oak-drawer-icon"><i class="ti"></i></span><h3></h3><button type="button" class="btn-close" aria-label="Fechar"></button></div><div class="oak-drawer-body"></div>';
        document.body.append(backdrop, drawer);
        backdrop.addEventListener('click', close);
        drawer.querySelector('.btn-close').addEventListener('click', close);
        document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && drawer.classList.contains('on')) close(); });
    }

    function open({title = '', icon = 'ti-list', color = '#3b82f6', html = '', node = null}) {
        build();
        lastFocus = document.activeElement;
        drawer.style.setProperty('--c', color);
        drawer.querySelector('h3').textContent = title;
        drawer.querySelector('.oak-drawer-icon i').className = `ti ${icon}`;
        const body = drawer.querySelector('.oak-drawer-body');
        body.innerHTML = '';
        if (node) body.appendChild(node); else body.innerHTML = html;
        body.scrollTop = 0;
        backdrop.hidden = false;
        requestAnimationFrame(() => { backdrop.classList.add('on'); drawer.classList.add('on'); });
        drawer.setAttribute('aria-hidden', 'false');
        drawer.querySelector('.btn-close').focus();
    }

    function close() {
        if (!drawer) return;
        drawer.classList.remove('on');
        backdrop.classList.remove('on');
        drawer.setAttribute('aria-hidden', 'true');
        setTimeout(() => { backdrop.hidden = true; }, 250);
        lastFocus?.focus?.();
    }

    function fromTemplate(key) {
        const tpl = document.getElementById(`dr-${key}`);
        if (!tpl) return false;
        open({title: tpl.dataset.title, icon: tpl.dataset.icon, color: tpl.dataset.color, node: tpl.content.cloneNode(true)});
        return true;
    }

    // cartões com data-drawer (inclusive os que o JavaScript desenha depois)
    document.addEventListener('click', (ev) => {
        const card = ev.target.closest('[data-drawer]');
        if (card && !ev.target.closest('a') && fromTemplate(card.dataset.drawer)) ev.preventDefault();
    });
    document.addEventListener('keydown', (ev) => {
        const card = ev.target.closest?.('[data-drawer]');
        if (card && (ev.key === 'Enter' || ev.key === ' ') && fromTemplate(card.dataset.drawer)) ev.preventDefault();
    });

    window.OakDrawer = {open, close, fromTemplate};
})();
