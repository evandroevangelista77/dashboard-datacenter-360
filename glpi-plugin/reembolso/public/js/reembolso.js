/**
 * Reembolso — janela de lançamento de despesa: campos conforme o tipo,
 * quilometragem pela rota (origem → destino, ida e volta) ou pelo odômetro,
 * prévia do valor e gravação na API (o servidor recalcula e valida).
 */
(() => {
    const bootEl = document.getElementById('rb-boot');
    if (!bootEl) return;
    const boot = JSON.parse(bootEl.textContent);
    const types = new Map(boot.types.map((t) => [t.id, t]));
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
    const num = (v) => {
        if (v === null || v === undefined) return NaN;
        let s = String(v).trim().replace(/R\$|\s/g, '');
        if (s === '') return NaN;
        if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
        return parseFloat(s);
    };
    const fmt = (v, d = 1) => (Number.isFinite(v) ? v.toLocaleString('pt-BR', {minimumFractionDigits: 0, maximumFractionDigits: d}) : '');
    const today = new Date().toISOString().slice(0, 10);

    async function api(action, payload) {
        const res = await fetch(boot.ajax, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({action, reembolso: boot.reembolso, ...payload}),
        });
        let data;
        try { data = await res.json(); } catch (e) { throw new Error(`Resposta inválida do servidor (HTTP ${res.status}).`); }
        if (!res.ok || data.ok === false) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    function open(expense) {
        const x = expense || {type: 0, date: today, description: '', origin: '', destination: '', round_trip: false, km_start: null, km_end: null, distance: 0, km_rate: boot.config.km_rate, amount: 0};
        const rate = expense && expense.km_rate ? expense.km_rate : boot.config.km_rate;
        const opts = '<option value="">Selecione o tipo de despesa…</option>' + boot.types.map((t) =>
            `<option value="${t.id}" ${t.id === x.type ? 'selected' : ''}>${esc(t.name)}${t.mileage ? ' (por KM)' : ''}</option>`).join('');
        const back = document.createElement('div');
        back.className = 'rb-modal-back';
        back.innerHTML = `<div class="rb-modal" role="dialog" aria-modal="true">
            <div class="rb-modal-h"><i class="ti ti-receipt-2"></i><h3>${expense ? 'Editar despesa' : 'Lançar despesa'}</h3><button type="button" class="btn-close" data-close aria-label="Fechar"></button></div>
            <div class="rb-modal-b">
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label required">Tipo de despesa</label><select class="form-select" name="type">${opts}</select><div class="form-hint" data-hint></div></div>
                    <div class="col-md-4"><label class="form-label required">Data</label><input type="date" class="form-control" name="date" max="${today}" value="${esc(x.date)}"></div>
                    <div class="col-12"><label class="form-label">Descrição</label><input class="form-control" name="description" maxlength="255" placeholder="Ex.: almoço durante atendimento no cliente X" value="${esc(x.description)}"></div>
                </div>
                <div data-km class="rb-km mt-3">
                    <div class="rb-km-title"><i class="ti ti-route me-1"></i>Quilometragem</div>
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Origem</label><input class="form-control" name="origin" maxlength="255" placeholder="Endereço, cidade e estado" value="${esc(x.origin)}"></div>
                        <div class="col-md-6"><label class="form-label">Destino</label><input class="form-control" name="destination" maxlength="255" placeholder="Endereço, cidade e estado" value="${esc(x.destination)}"></div>
                        <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                            ${boot.config.route_enabled ? '<button type="button" class="btn btn-outline-primary btn-sm" data-route><i class="ti ti-map-search me-1"></i>Calcular KM pela rota</button>' : ''}
                            <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="round_trip" ${x.round_trip ? 'checked' : ''}><span class="form-check-label">Ida e volta</span></label>
                            <span class="small text-secondary" data-route-msg></span>
                        </div>
                        <div class="col-12 small text-secondary rb-or">ou pelo odômetro do veículo</div>
                        <div class="col-6 col-md-3"><label class="form-label">KM inicial</label><input class="form-control" name="km_start" inputmode="decimal" value="${x.km_start ?? ''}"></div>
                        <div class="col-6 col-md-3"><label class="form-label">KM final</label><input class="form-control" name="km_end" inputmode="decimal" value="${x.km_end ?? ''}"></div>
                        <div class="col-6 col-md-3"><label class="form-label required">KM rodado</label><input class="form-control fw-bold" name="distance" inputmode="decimal" value="${x.distance ? fmt(x.distance) : ''}"></div>
                        <div class="col-6 col-md-3"><label class="form-label">Valor por KM</label><input class="form-control" name="km_rate" inputmode="decimal" value="${fmt(rate, 4)}" ${boot.approver ? '' : 'readonly title="Definido na configuração do plugin"'}></div>
                    </div>
                </div>
                <div data-value class="row g-3 mt-1">
                    <div class="col-md-5"><label class="form-label required">Valor (R$)</label><div class="input-group"><span class="input-group-text">R$</span><input class="form-control fw-bold" name="amount" inputmode="decimal" placeholder="0,00" value="${x.amount && !(types.get(x.type) || {}).mileage ? fmt(x.amount, 2) : ''}"></div></div>
                </div>
                <div class="rb-preview mt-3"><span>Valor da despesa</span><b data-total>${brl(x.amount)}</b></div>
                <div class="alert alert-warning py-2 mt-2 mb-0 d-none" data-warn></div>
                <div class="alert alert-danger py-2 mt-2 mb-0 d-none" data-err></div>
            </div>
            <div class="rb-modal-f"><button type="button" class="btn btn-ghost-secondary" data-close>Cancelar</button>
                <button type="button" class="btn btn-primary ms-auto" data-save><i class="ti ti-device-floppy me-1"></i>Salvar despesa</button></div>
        </div>`;
        document.body.appendChild(back);
        const q = (s) => back.querySelector(s);
        const f = (n) => back.querySelector(`[name="${n}"]`);
        const close = () => { back.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => e.key === 'Escape' && close();
        document.addEventListener('keydown', onKey);
        back.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
        back.addEventListener('mousedown', (e) => e.target === back && close());

        // distância da rota só de ida (para alternar "ida e volta" sem recalcular)
        let routeOneWay = null;

        const current = () => types.get(parseInt(f('type').value, 10));
        const compute = () => {
            const t = current();
            const warn = q('[data-warn]');
            warn.classList.add('d-none');
            if (!t) { q('[data-total]').textContent = brl(0); return 0; }
            let amount;
            if (t.mileage) {
                let d = num(f('distance').value);
                const s = num(f('km_start').value), e = num(f('km_end').value);
                if (!Number.isFinite(d) && Number.isFinite(s) && Number.isFinite(e) && e >= s) {
                    d = e - s;
                }
                amount = Number.isFinite(d) ? Math.round(d * (num(f('km_rate').value) || 0) * 100) / 100 : 0;
            } else {
                amount = num(f('amount').value) || 0;
            }
            q('[data-total]').textContent = brl(amount);
            const msgs = [];
            if (t.max > 0 && amount > t.max) msgs.push(`Acima do limite deste tipo (${brl(t.max)}): o aprovador verá o alerta.`);
            const date = f('date').value;
            if (date && boot.period[0] && (date < boot.period[0] || date > boot.period[1])) msgs.push('A data está fora do período da solicitação.');
            if (msgs.length) { warn.innerHTML = msgs.map(esc).join('<br>'); warn.classList.remove('d-none'); }
            return amount;
        };
        const toggle = () => {
            const t = current();
            q('[data-km]').style.display = t && t.mileage ? '' : 'none';
            q('[data-value]').style.display = t && !t.mileage ? '' : 'none';
            const hint = [];
            if (t && t.mileage) hint.push(`KM rodado × ${brl(num(f('km_rate').value) || boot.config.km_rate)} por KM`);
            if (t && t.max > 0) hint.push(`limite por lançamento: ${brl(t.max)}`);
            if (t && t.receipt) hint.push('exige comprovante (anexe na aba Documentos)');
            q('[data-hint]').textContent = hint.join(' · ');
            compute();
        };
        // odômetro preenche o KM rodado
        const fromOdometer = () => {
            const s = num(f('km_start').value), e = num(f('km_end').value);
            if (Number.isFinite(s) && Number.isFinite(e) && e >= s) {
                f('distance').value = fmt(e - s);
                routeOneWay = null;
            }
            compute();
        };
        f('type').addEventListener('change', toggle);
        ['amount', 'distance', 'km_rate', 'date'].forEach((n) => f(n).addEventListener('input', compute));
        f('distance').addEventListener('input', () => { routeOneWay = null; });
        f('km_start').addEventListener('input', fromOdometer);
        f('km_end').addEventListener('input', fromOdometer);
        f('round_trip').addEventListener('change', () => {
            if (routeOneWay !== null) {
                f('distance').value = fmt(routeOneWay * (f('round_trip').checked ? 2 : 1));
                compute();
            }
        });
        q('[data-route]')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const msg = q('[data-route-msg]');
            btn.disabled = true;
            msg.textContent = 'Calculando a rota…';
            try {
                const r = await api('route', {origin: f('origin').value, destination: f('destination').value});
                routeOneWay = r.km;
                f('distance').value = fmt(r.km * (f('round_trip').checked ? 2 : 1));
                msg.innerHTML = `<i class="ti ti-check text-success"></i> ${fmt(r.km)} km por rota de carro (só ida)`;
                msg.title = `${r.origin} → ${r.destination}`;
                compute();
            } catch (e) {
                msg.innerHTML = `<span class="text-danger">${esc(e.message)}</span>`;
            } finally {
                btn.disabled = false;
            }
        });
        q('[data-save]').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const err = q('[data-err]');
            err.classList.add('d-none');
            const t = current();
            const data = {
                id: expense ? expense.id : 0,
                type: t ? t.id : 0, date: f('date').value, description: f('description').value,
            };
            if (t && t.mileage) {
                Object.assign(data, {
                    origin: f('origin').value, destination: f('destination').value, round_trip: f('round_trip').checked,
                    km_start: f('km_start').value, km_end: f('km_end').value, distance: f('distance').value, km_rate: f('km_rate').value,
                });
            } else {
                data.amount = f('amount').value;
            }
            btn.disabled = true;
            try {
                await api('save', {data});
                location.reload();
            } catch (e) {
                err.textContent = e.message;
                err.classList.remove('d-none');
                btn.disabled = false;
            }
        });
        toggle();
        f('type').focus();
    }

    document.querySelector('[data-rb-add]')?.addEventListener('click', () => open(null));
    document.querySelectorAll('[data-rb-edit]').forEach((b) => b.addEventListener('click', () => {
        open(boot.expenses.find((x) => x.id === parseInt(b.dataset.rbEdit, 10)));
    }));
    document.querySelectorAll('[data-rb-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm('Excluir esta despesa?')) return;
        try {
            await api('delete', {id: parseInt(b.dataset.rbDel, 10)});
            location.reload();
        } catch (e) {
            alert(e.message);
        }
    }));
})();
