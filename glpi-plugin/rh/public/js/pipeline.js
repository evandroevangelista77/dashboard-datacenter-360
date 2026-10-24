/**
 * RH — funil de seleção da vaga (Kanban): arrastar candidatos entre etapas,
 * ficha com nota, parecer, entrevista e proposta, histórico, e adicionar
 * candidatos do banco ou novos.
 */
(() => {
    const boot = JSON.parse(document.getElementById('rh-boot').textContent);
    const app = document.getElementById('rh-pipeline');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
    const hue = (s) => [...String(s)].reduce((a, c) => (a * 31 + c.charCodeAt(0)) % 360, 7);
    let data = null;
    let showClosed = false;

    async function api(action, payload = {}, post = true) {
        const opts = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
        let url = boot.ajax;
        if (post) {
            opts.method = 'POST';
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({action, ...payload});
        } else {
            url += '?' + new URLSearchParams({action, ...payload});
        }
        const res = await fetch(url, opts);
        let out;
        try { out = await res.json(); } catch (e) { throw new Error(`Resposta inválida do servidor (HTTP ${res.status}).`); }
        if (!res.ok || out.ok === false) throw new Error(out.error || `HTTP ${res.status}`);
        return out;
    }

    let toastBox;
    const toast = (msg, kind = '') => {
        toastBox ??= document.body.appendChild(Object.assign(document.createElement('div'), {className: 'rh-toast'}));
        const el = toastBox.appendChild(Object.assign(document.createElement('div'), {className: kind, textContent: msg}));
        setTimeout(() => el.remove(), kind === 'err' ? 7000 : 4000);
    };

    function modal(title, body, buttons) {
        const back = document.createElement('div');
        back.className = 'rh-modal-back';
        back.innerHTML = `<div class="rh-modal"><div class="rh-modal-h"><h3>${title}</h3><button type="button" class="btn-close" data-x></button></div><div class="rh-modal-b">${body}</div><div class="rh-modal-f"></div></div>`;
        const close = () => { back.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => e.key === 'Escape' && close();
        document.addEventListener('keydown', onKey);
        back.querySelector('[data-x]').onclick = close;
        back.addEventListener('mousedown', (e) => e.target === back && close());
        const foot = back.querySelector('.rh-modal-f');
        buttons.forEach((b) => {
            const btn = document.createElement(b.href ? 'a' : 'button');
            btn.className = 'btn ' + (b.cls || 'btn-secondary');
            btn.innerHTML = b.label;
            if (b.href) btn.href = b.href;
            if (b.right) btn.style.marginLeft = 'auto';
            if (!b.href) btn.type = 'button';
            btn.onclick = async () => {
                if (!b.run) return close();
                btn.disabled = true;
                try { if (await b.run(back) !== false) close(); } catch (e) { toast(e.message, 'err'); } finally { btn.disabled = false; }
            };
            foot.appendChild(btn);
        });
        document.body.appendChild(back);
        return back;
    }

    async function load() {
        try {
            data = await api('pipeline', {vaga: boot.vaga}, false);
            render();
        } catch (e) {
            app.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    const avatar = (c, size) => (c.photo
        ? `<img class="rh-avatar rh-avatar-${size} rh-photo" src="${esc(c.photo)}" alt="">`
        : `<span class="rh-avatar rh-avatar-${size}" style="--h:${hue(c.name)}">${esc(c.initials)}</span>`);
    const stars = (n) => `<span class="rh-stars">${'★'.repeat(n)}<span>${'★'.repeat(5 - n)}</span></span>`;

    function card(c) {
        const closed = c.status === 'rejected' || c.status === 'withdrew';
        const interview = c.interview ? `<span class="rh-chip"><i class="ti ti-calendar-event"></i> ${new Date(c.interview).toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'})}</span>` : '';
        return `<div class="rh-card ${closed ? 'closed' : ''}" draggable="${data.edit && !closed}" data-id="${c.id}">
            <div class="d-flex align-items-center gap-2">${avatar(c, 'md')}
                <div class="flex-grow-1 text-truncate"><div class="fw-bold text-truncate">${esc(c.name)}</div><div class="small text-secondary text-truncate">${esc(c.city || c.email || '')}</div></div></div>
            <div class="rh-card-meta">${stars(c.rating)}${c.source ? `<span class="rh-chip">${esc(c.source)}</span>` : ''}${interview}
                ${closed ? `<span class="rh-chip rh-chip-red">${c.status === 'rejected' ? 'Reprovado' : 'Desistiu'}</span>` : `<span class="ms-auto small text-secondary" title="Dias nesta etapa">${c.days}d</span>`}</div>
        </div>`;
    }

    function render() {
        const v = data.vaga;
        const active = data.cards.filter((c) => c.status === 'active' || c.status === 'hired');
        const closed = data.cards.filter((c) => c.status === 'rejected' || c.status === 'withdrew');
        let html = `<div class="rh-title"><div><div class="rh-crumb"><a href="${esc(boot.vagaUrl)}"><i class="ti ti-arrow-left me-1"></i>Vaga</a> · funil de seleção</div><h2>${esc(v.name)}</h2>
            <div class="small text-secondary">${esc(v.statusName)} · ${v.hired} de ${v.positions} contratado(s)${v.salary[1] ? ` · faixa ${brl(v.salary[0])} a ${brl(v.salary[1])}` : ''}</div></div>
            <div class="d-flex gap-2 align-items-center">
                <div class="progress" style="width:160px;height:10px" title="${v.hired}/${v.positions} posições"><div class="progress-bar bg-success" style="width:${Math.min(100, 100 * v.hired / v.positions)}%"></div></div>
                <label class="form-check mb-0 small"><input type="checkbox" class="form-check-input" data-closed ${showClosed ? 'checked' : ''}><span class="form-check-label">Mostrar reprovados/desistentes (${closed.length})</span></label>
                ${data.edit ? '<button type="button" class="btn btn-primary" data-add><i class="ti ti-user-plus me-1"></i>Adicionar candidato</button>' : ''}
            </div></div>`;
        if (!data.edit) {
            html += `<div class="alert alert-info py-2">${v.status === 4 ? 'Você pode acompanhar este funil; quem conduz a seleção é o RH, o recrutador ou o gestor da vaga.' : 'A vaga não está aberta: o funil fica só para consulta.'}</div>`;
        }
        html += `<div class="rh-board">`;
        data.stages.forEach((s) => {
            const list = (showClosed ? data.cards : active).filter((c) => c.stage === s.key);
            html += `<div class="rh-col"><div class="rh-col-head" style="--c:${s.color}"><i class="ti ${s.icon}"></i>${esc(s.name)}<span class="cnt">${list.filter((c) => c.status === 'active' || c.status === 'hired').length}</span></div>
                <div class="rh-col-body" data-stage="${s.key}">${list.map(card).join('') || '<div class="rh-col-empty">—</div>'}</div></div>`;
        });
        html += `</div>`;
        if (!data.cards.length) {
            html += `<div class="text-secondary mt-2">Nenhum candidato ainda. ${data.edit ? 'Clique em <b>Adicionar candidato</b>.' : ''}</div>`;
        }
        app.className = '';
        app.innerHTML = html;
        app.querySelector('[data-closed]').onchange = (e) => { showClosed = e.target.checked; render(); };
        app.querySelector('[data-add]')?.addEventListener('click', addCandidate);
        app.querySelectorAll('.rh-card').forEach((el) => el.addEventListener('click', () => details(data.cards.find((c) => c.id === +el.dataset.id))));
    }

    // arrastar e soltar entre etapas
    let dragged = null;
    const ph = Object.assign(document.createElement('div'), {className: 'rh-placeholder'});
    app.addEventListener('dragstart', (e) => {
        const it = e.target.closest('.rh-card');
        if (!it) return;
        dragged = it;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', it.dataset.id);
        ph.style.height = it.offsetHeight + 'px';
        requestAnimationFrame(() => it.classList.add('dragging'));
    });
    app.addEventListener('dragend', () => { dragged?.classList.remove('dragging'); ph.remove(); dragged = null; app.querySelectorAll('.over').forEach((x) => x.classList.remove('over')); });
    app.addEventListener('dragover', (e) => {
        const list = e.target.closest('.rh-col-body');
        if (!dragged || !list) return;
        e.preventDefault();
        app.querySelectorAll('.over').forEach((x) => x !== list && x.classList.remove('over'));
        list.classList.add('over');
        list.querySelector('.rh-col-empty')?.remove();
        const after = [...list.querySelectorAll('.rh-card:not(.dragging)')].find((el) => e.clientY < el.getBoundingClientRect().top + el.offsetHeight / 2);
        after ? list.insertBefore(ph, after) : list.appendChild(ph);
    });
    app.addEventListener('drop', async (e) => {
        const list = e.target.closest('.rh-col-body');
        if (!dragged || !list) return;
        e.preventDefault();
        list.insertBefore(dragged, ph);
        ph.remove();
        const id = +dragged.dataset.id;
        const stage = list.dataset.stage;
        const order = [...list.querySelectorAll('.rh-card')].map((x) => +x.dataset.id);
        const c = data.cards.find((x) => x.id === id);
        if (stage === 'contratado' && c.stage !== 'contratado' && !confirm(`Confirmar a contratação de ${c.name}? Será criada a admissão com o checklist de onboarding.`)) {
            render();
            return;
        }
        try {
            const r = await api('move', {id, stage, order});
            if (r.admissao) toast(`${c.name} contratado(a)! Admissão criada (Admissões).`);
            if (r.filled) toast('Todas as posições foram preenchidas: a vaga foi encerrada como preenchida.');
        } catch (err) {
            toast(err.message, 'err');
        }
        load();
    });

    function details(c) {
        const ro = data.edit ? '' : 'disabled';
        const closed = c.status === 'rejected' || c.status === 'withdrew';
        const hist = c.history.map((h) => `<li><b>${esc(h.to)}</b>${h.from && h.from !== h.to ? ` <span class="text-secondary">(de ${esc(h.from)})</span>` : ''}${h.status && h.from === h.to ? ` · ${esc(h.status)}` : ''}
            <div class="small text-secondary">${esc(h.user)} · ${esc(h.date)}${h.comment ? ` · “${esc(h.comment)}”` : ''}</div></li>`).join('');
        const body = `<div class="d-flex gap-3 align-items-center mb-3">${avatar(c, 'xl')}<div>
                <div class="fs-3 fw-bold">${esc(c.name)}</div><div class="small text-secondary">${[c.email, c.phone, c.city].filter(Boolean).map(esc).join(' · ')}</div>
                <div class="small">${c.linkedin ? `<a href="${esc(c.linkedin)}" target="_blank" rel="noopener"><i class="ti ti-brand-linkedin"></i> LinkedIn</a> · ` : ''}${c.source ? esc(c.source) + ' · ' : ''}pretensão ${c.expect ? brl(c.expect) : 'não informada'}</div></div></div>
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label">Nota</label><select class="form-select" name="rating" ${ro}>${[0, 1, 2, 3, 4, 5].map((n) => `<option value="${n}" ${n === c.rating ? 'selected' : ''}>${n ? '★'.repeat(n) : 'sem nota'}</option>`).join('')}</select></div>
                <div class="col-md-8"><label class="form-label">Entrevista agendada</label><input type="datetime-local" class="form-control" name="interview" value="${esc(c.interview)}" ${ro}></div>
                <div class="col-12"><label class="form-label">Parecer / anotações</label><textarea class="form-control" rows="4" name="notes" ${ro}>${esc(c.notes)}</textarea></div>
                <div class="col-md-6"><label class="form-label">Salário da proposta (R$)</label><input class="form-control" name="offer" value="${c.offer ? c.offer.toLocaleString('pt-BR', {minimumFractionDigits: 2}) : ''}" ${ro}></div>
                <div class="col-md-6"><label class="form-label">Início previsto</label><input type="date" class="form-control" name="start" value="${esc(c.start)}" ${ro}></div>
            </div>
            <h4 class="mt-3 mb-1">Histórico</h4><ul class="rh-hist">${hist || '<li class="text-secondary">—</li>'}</ul>`;
        const buttons = [{label: '<i class="ti ti-user me-1"></i>Ficha do candidato', cls: 'btn-ghost-secondary', href: c.url}];
        if (data.edit) {
            if (closed) {
                buttons.push({label: 'Reativar', cls: 'btn-outline-primary', run: async () => { await api('status', {id: c.id, status: 'active'}); load(); }});
            } else if (c.status !== 'hired') {
                const closeWith = (status, label) => async () => {
                    const reason = prompt(`Motivo (${label}):`);
                    if (!reason) return false;
                    await api('status', {id: c.id, status, comment: reason});
                    toast(`${c.name}: ${label.toLowerCase()}.`);
                    load();
                };
                buttons.push({label: '<i class="ti ti-x me-1"></i>Reprovar', cls: 'btn-outline-danger', run: closeWith('rejected', 'Reprovado')});
                buttons.push({label: 'Desistiu', cls: 'btn-ghost-secondary', run: closeWith('withdrew', 'Desistiu')});
            }
            buttons.push({label: '<i class="ti ti-device-floppy me-1"></i>Salvar', cls: 'btn-primary', right: true, run: async (m) => {
                const f = (n) => m.querySelector(`[name="${n}"]`).value;
                await api('details', {id: c.id, data: {rating: +f('rating'), interview: f('interview'), notes: f('notes'), offer: f('offer'), start: f('start')}});
                toast('Ficha salva.');
                load();
            }});
        } else {
            buttons.push({label: 'Fechar', right: true});
        }
        modal(`${esc(data.stages.find((s) => s.key === c.stage)?.name || '')} · candidato`, body, buttons);
    }

    function addCandidate() {
        const sources = Object.entries(data.sources).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
        const body = `<ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link active" href="#" data-tab="bank">Do banco de candidatos</a></li>
            ${boot.rh ? '<li class="nav-item"><a class="nav-link" href="#" data-tab="new">Novo candidato</a></li>' : ''}</ul>
            <div data-pane="bank"><input class="form-control mb-2" placeholder="Buscar por nome, e-mail ou cidade" data-q><div class="list-group rh-pick" data-list><div class="text-secondary small p-2">Carregando…</div></div></div>
            <div data-pane="new" class="d-none row g-2">
                <div class="col-md-6"><label class="form-label required">Nome</label><input class="form-control" name="name"></div>
                <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" class="form-control" name="email"></div>
                <div class="col-md-4"><label class="form-label">Telefone</label><input class="form-control" name="phone"></div>
                <div class="col-md-4"><label class="form-label">Cidade</label><input class="form-control" name="city"></div>
                <div class="col-md-4"><label class="form-label">Origem</label><select class="form-select" name="source">${sources}</select></div>
                <div class="col-md-8"><label class="form-label">LinkedIn</label><input class="form-control" name="linkedin"></div>
                <div class="col-md-4"><label class="form-label">Pretensão (R$)</label><input class="form-control" name="expect"></div>
                <div class="col-12 small text-secondary">O currículo é anexado depois, na ficha do candidato (aba Documentos).</div>
            </div>`;
        let picked = 0;
        const m = modal('<i class="ti ti-user-plus me-1"></i>Adicionar candidato à vaga', body, [
            {label: 'Cancelar', cls: 'btn-ghost-secondary'},
            {label: '<i class="ti ti-check me-1"></i>Adicionar à triagem', cls: 'btn-primary', right: true, run: async (mm) => {
                const isNew = !mm.querySelector('[data-pane="new"]').classList.contains('d-none');
                let payload;
                if (isNew) {
                    const f = (n) => mm.querySelector(`[name="${n}"]`).value;
                    if (!f('name').trim()) throw new Error('Informe o nome.');
                    payload = {name: f('name'), email: f('email'), phone: f('phone'), city: f('city'), source: f('source'), linkedin: f('linkedin'), expect: f('expect')};
                } else {
                    if (!picked) throw new Error('Escolha um candidato da lista.');
                    payload = {candidato: picked};
                }
                await api('add', {vaga: boot.vaga, data: payload});
                toast('Candidato adicionado à triagem.');
                load();
            }},
        ]);
        m.querySelectorAll('[data-tab]').forEach((t) => t.addEventListener('click', (e) => {
            e.preventDefault();
            m.querySelectorAll('[data-tab]').forEach((x) => x.classList.toggle('active', x === t));
            m.querySelectorAll('[data-pane]').forEach((p) => p.classList.toggle('d-none', p.dataset.pane !== t.dataset.tab));
        }));
        const listEl = m.querySelector('[data-list]');
        const search = async (q = '') => {
            try {
                const r = await api('available', {vaga: boot.vaga, q}, false);
                listEl.innerHTML = r.list.map((x) => `<button type="button" class="list-group-item list-group-item-action" data-pick="${x.id}"><b>${esc(x.name)}</b><div class="small text-secondary">${[x.email, x.city].filter(Boolean).map(esc).join(' · ')}</div></button>`).join('')
                    || `<div class="text-secondary small p-2">Nenhum candidato disponível no banco.${boot.rh ? ' Use a aba <b>Novo candidato</b>.' : ''}</div>`;
                listEl.querySelectorAll('[data-pick]').forEach((b) => b.addEventListener('click', () => {
                    picked = +b.dataset.pick;
                    listEl.querySelectorAll('.active').forEach((x) => x.classList.remove('active'));
                    b.classList.add('active');
                }));
            } catch (e) {
                listEl.innerHTML = `<div class="text-danger small p-2">${esc(e.message)}</div>`;
            }
        };
        let t;
        m.querySelector('[data-q]').addEventListener('input', (e) => { clearTimeout(t); t = setTimeout(() => search(e.target.value), 250); });
        search();
    }

    load();
})();
