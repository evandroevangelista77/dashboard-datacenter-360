/**
 * RH — requisição de vaga: ao escolher o cargo, preenche "Descrição e atividades" e
 * "Requisitos" com o job description dele. Texto já editado só é trocado com confirmação.
 */
(() => {
    const box = document.getElementById('rh-jd-pull');
    const desc = document.getElementById('rh-f-description');
    const req = document.getElementById('rh-f-requirements');
    const sel = document.querySelector('select[name="plugin_rh_cargos_id"]');
    if (!box || !desc || !req || !sel) return;
    const msg = document.getElementById('rh-jd-msg');
    const btn = document.getElementById('rh-jd-btn');
    // o que foi preenchido automaticamente da última vez (para saber se o usuário mexeu)
    let auto = {description: null, requirements: null};
    const touched = (el, key) => el.value.trim() !== '' && el.value !== auto[key];

    async function pull(manual) {
        const id = parseInt(sel.value, 10) || 0;
        if (!id) {
            if (manual) msg.textContent = 'Escolha o cargo primeiro.';
            return;
        }
        btn.disabled = true;
        try {
            const r = await fetch(`${box.dataset.url}?action=jobdesc&cargo=${id}`, {headers: {Accept: 'application/json'}});
            const d = await r.json();
            if (!d.ok) throw new Error(d.error || 'Falha ao buscar o job description.');
            if (!d.description && !d.requirements) {
                msg.textContent = `O cargo "${d.name}" ainda não tem job description. Preencha no Cadastro de Cargos ou escreva aqui.`;
                return;
            }
            if ((touched(desc, 'description') || touched(req, 'requirements'))
                && !confirm(`Substituir a descrição e os requisitos pelo job description de "${d.name}"?\nO que foi escrito nesses campos será trocado.`)) {
                return;
            }
            desc.value = auto.description = d.description;
            req.value = auto.requirements = d.requirements;
            [desc, req].forEach((el) => { el.classList.add('rh-jd-filled'); setTimeout(() => el.classList.remove('rh-jd-filled'), 1600); });
            msg.innerHTML = '';
            msg.append(`Preenchido com o job description de "${d.name}". Edite e acrescente o que for específico desta vaga.`);
        } catch (e) {
            msg.textContent = e.message;
        } finally {
            btn.disabled = false;
        }
    }

    btn.addEventListener('click', () => pull(true));
    // o select do GLPI é select2: a troca chega pelo evento do jQuery
    (window.jQuery ? window.jQuery(sel) : null)?.on('change', () => pull(false));
    if (!window.jQuery) sel.addEventListener('change', () => pull(false));
    // vaga nova já com cargo e campos vazios: preenche de cara
    if (sel.value && sel.value !== '0' && !desc.value.trim() && !req.value.trim()) pull(false);
})();
