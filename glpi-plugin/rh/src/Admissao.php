<?php

namespace GlpiPlugin\Rh;

use CommonDBTM;
use Html;
use Log;
use RuntimeException;
use Session;
use Ticket;
use User;

/**
 * Admissão (onboarding) do contratado: checklist por área e chamado de TI
 * para preparar usuário, e-mail, equipamento e acessos.
 */
class Admissao extends CommonDBTM
{
    public static string $rightname = 'plugin_rh_recrutamento';
    public bool $dohistory = true;

    public const PROGRESS = 1;
    public const DONE     = 2;
    public const CANCELED = 3;

    public const STATUSES = [
        self::PROGRESS => ['Em andamento', '#3b82f6'],
        self::DONE     => ['Concluída', '#22c55e'],
        self::CANCELED => ['Cancelada', '#94a3b8'],
    ];

    public const AREAS = [
        'rh'         => ['RH', 'ti-id-badge-2', '#ec4899'],
        'ti'         => ['TI', 'ti-device-laptop', '#3b82f6'],
        'gestor'     => ['Gestor', 'ti-user-star', '#a855f7'],
        'facilities' => ['Facilities', 'ti-building', '#f59e0b'],
    ];

    /** Checklist padrão de toda admissão. */
    public const CHECKLIST = [
        ['rh', 'Coletar documentos admissionais'],
        ['rh', 'Exame admissional (ASO)'],
        ['rh', 'Registro no eSocial / carteira de trabalho'],
        ['rh', 'Assinatura do contrato e termos'],
        ['rh', 'Inclusão em benefícios (VR/VA, plano de saúde)'],
        ['ti', 'Criar usuário de rede (AD) e e-mail'],
        ['ti', 'Criar usuário no GLPI e demais sistemas'],
        ['ti', 'Preparar notebook / estação de trabalho'],
        ['ti', 'Liberar acessos (VPN, pastas, sistemas)'],
        ['ti', 'Telefone / ramal / celular corporativo'],
        ['gestor', 'Plano de integração da primeira semana'],
        ['gestor', 'Apresentar à equipe e definir padrinho'],
        ['gestor', 'Metas do período de experiência'],
        ['facilities', 'Crachá e acesso físico'],
        ['facilities', 'Mesa / posição de trabalho'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Admissões' : 'Admissão';
    }

    public static function getIcon()
    {
        return 'ti ti-user-plus';
    }

    public static function canView(): bool
    {
        return Vaga::canView();
    }

    public static function canCreate(): bool
    {
        return Vaga::isRH();
    }

    public static function canUpdate(): bool
    {
        return Vaga::canView();
    }

    public static function canPurge(): bool
    {
        return Vaga::isRH() && Session::haveRight(self::$rightname, PURGE);
    }

    public function canViewItem(): bool
    {
        return Session::haveRight(self::$rightname, Vaga::READALL) || (int) $this->fields['users_id_manager'] === (int) Session::getLoginUserID();
    }

    /** RH e o gestor marcam o checklist; os demais só veem. */
    public function canUpdateItem(): bool
    {
        return (int) $this->fields['status'] === self::PROGRESS && (Vaga::isRH() || (int) $this->fields['users_id_manager'] === (int) Session::getLoginUserID());
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Log::class, $ong, $options);
        return $ong;
    }

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Colaborador', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => Vaga::getTable(), 'field' => 'name', 'name' => 'Vaga', 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 4, 'table' => $t, 'field' => 'start_date', 'name' => 'Início', 'datatype' => 'date'],
            ['id' => 5, 'table' => $t, 'field' => 'status', 'name' => __('Status'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 6, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_manager', 'name' => 'Gestor', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 7, 'table' => 'glpi_tickets', 'field' => 'name', 'name' => 'Chamado de TI', 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'status') {
            [$l, $c] = self::STATUSES[(int) $values[$field]] ?? ['?', '#64748b'];
            return Menu::badge($l, $c);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'status') {
            return \Dropdown::showFromArray($name, array_map(fn ($s) => $s[0], self::STATUSES), ['value' => $values[$field] ?? '', 'display' => false] + $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function cleanDBonPurge()
    {
        global $DB;
        $DB->delete('glpi_plugin_rh_checklists', ['plugin_rh_admissaos_id' => $this->getID()]);
    }

    /** Cria a admissão do candidato contratado (uma só) com o checklist padrão. */
    public static function createFor(int $candidaturaId): int
    {
        global $DB;
        $existing = $DB->request(['SELECT' => 'id', 'FROM' => self::getTable(), 'WHERE' => ['plugin_rh_candidaturas_id' => $candidaturaId, 'is_deleted' => 0]])->current();
        if ($existing) {
            $DB->update(self::getTable(), ['status' => self::PROGRESS], ['id' => $existing['id']]);
            return (int) $existing['id'];
        }
        $c = $DB->request([
            'SELECT' => ['c.*', 'p.name', 'v.users_id_manager', 'v.entities_id'],
            'FROM' => Candidatura::getTable() . ' AS c',
            'INNER JOIN' => [
                Candidato::getTable() . ' AS p' => ['ON' => ['p' => 'id', 'c' => 'plugin_rh_candidatos_id']],
                Vaga::getTable() . ' AS v'      => ['ON' => ['v' => 'id', 'c' => 'plugin_rh_vagas_id']],
            ],
            'WHERE' => ['c.id' => $candidaturaId],
        ])->current();
        $adm = new self();
        $id = (int) $adm->add([
            'name' => $c['name'], 'plugin_rh_candidaturas_id' => $candidaturaId, 'plugin_rh_vagas_id' => $c['plugin_rh_vagas_id'],
            'start_date' => $c['start_date'], 'users_id_manager' => $c['users_id_manager'], 'status' => self::PROGRESS,
            'entities_id' => $c['entities_id'], 'is_recursive' => 1,
        ]);
        foreach (self::CHECKLIST as $i => [$area, $label]) {
            $DB->insert('glpi_plugin_rh_checklists', ['plugin_rh_admissaos_id' => $id, 'area' => $area, 'label' => $label, 'rank' => $i]);
        }
        $vaga = new Vaga();
        if ($vaga->getFromDB((int) $c['plugin_rh_vagas_id'])) {
            Avisos::send([
                'event' => 'admissao_criada', 'users' => [(int) $c['users_id_manager'], (int) $vaga->fields['users_id_recruiter']],
                'title' => 'Nova admissão: ' . $c['name'], 'level' => 'info', 'url' => self::getFormURLWithID($id), 'item' => [self::class, $id],
                'message' => 'Checklist de onboarding criado' . ($c['start_date'] ? ', início em ' . date('d/m/Y', strtotime($c['start_date'])) : '') . '.',
                'facts' => ['Vaga' => Avisos::code($vaga) . ' · ' . $vaga->fields['name'], 'Início' => $c['start_date'] ? date('d/m/Y', strtotime($c['start_date'])) : 'a definir'],
            ]);
        }
        return $id;
    }

    public function items(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => 'glpi_plugin_rh_checklists', 'WHERE' => ['plugin_rh_admissaos_id' => $this->getID()], 'ORDER' => ['rank', 'id']]), false);
    }

    public function toggle(int $itemId, bool $done): void
    {
        global $DB;
        if (!$this->canUpdateItem()) {
            throw new RuntimeException('Você não pode alterar esta admissão.');
        }
        $DB->update('glpi_plugin_rh_checklists', [
            'is_done' => $done ? 1 : 0, 'users_id' => $done ? (int) Session::getLoginUserID() : 0, 'date_done' => $done ? $_SESSION['glpi_currenttime'] : null,
        ], ['id' => $itemId, 'plugin_rh_admissaos_id' => $this->getID()]);
    }

    public function addItem(string $area, string $label): void
    {
        global $DB;
        if (!$this->canUpdateItem() || trim($label) === '' || !isset(self::AREAS[$area])) {
            throw new RuntimeException('Informe a área e a descrição do item.');
        }
        $DB->insert('glpi_plugin_rh_checklists', ['plugin_rh_admissaos_id' => $this->getID(), 'area' => $area, 'label' => mb_substr(trim($label), 0, 255), 'rank' => 999]);
    }

    /** Abre um chamado (requisição) para a TI com os itens de TI do checklist. */
    public function openTicket(): int
    {
        if (!$this->canUpdateItem()) {
            throw new RuntimeException('Você não pode alterar esta admissão.');
        }
        if ($this->fields['tickets_id']) {
            throw new RuntimeException('O chamado de TI já foi aberto.');
        }
        $items = array_filter($this->items(), fn ($i) => $i['area'] === 'ti');
        $vaga = new Vaga();
        $vaga->getFromDB((int) $this->fields['plugin_rh_vagas_id']);
        $start = $this->fields['start_date'] ? Html::convDate($this->fields['start_date']) : 'a definir';
        $content = "<p>Preparar a chegada de <strong>" . Menu::e($this->fields['name']) . "</strong>, que começa em <strong>$start</strong>"
            . " como <strong>" . Menu::e(\Dropdown::getDropdownName(Cargo::getTable(), (int) ($vaga->fields['plugin_rh_cargos_id'] ?? 0), false, true, false, '')) . "</strong>"
            . " (departamento " . Menu::e(\Dropdown::getDropdownName(Departamento::getTable(), (int) ($vaga->fields['plugin_rh_departamentos_id'] ?? 0), false, true, false, '')) . ").</p>"
            . "<p>Gestor: " . Menu::e(getUserName((int) $this->fields['users_id_manager'])) . "</p><p><strong>Itens de TI:</strong></p><ul>"
            . implode('', array_map(fn ($i) => '<li>' . Menu::e($i['label']) . '</li>', $items)) . "</ul>"
            . "<p>Admissão no GLPI: " . Menu::e(self::getFormURLWithID($this->getID())) . "</p>";
        $ticket = new Ticket();
        $id = (int) $ticket->add([
            'name'                => 'Admissão: ' . $this->fields['name'] . ' (início ' . $start . ')',
            'content'             => $content,
            'type'                => Ticket::DEMAND_TYPE,
            'entities_id'         => (int) $this->fields['entities_id'],
            '_users_id_requester' => (int) Session::getLoginUserID(),
            'date'                => $_SESSION['glpi_currenttime'],
        ]);
        if (!$id) {
            throw new RuntimeException('O GLPI não abriu o chamado.');
        }
        $this->update(['id' => $this->getID(), 'tickets_id' => $id]);
        return $id;
    }

    public function showForm($ID, array $options = [])
    {
        $f = $this->fields;
        $e = [Menu::class, 'e'];
        $items = $this->items();
        $done = count(array_filter($items, fn ($i) => $i['is_done']));
        $pct = $items ? (int) round(100 * $done / count($items)) : 0;
        $edit = $this->canUpdateItem();
        $a = $e(Menu::url('/front/admissao.form.php'));
        $id = (int) $this->getID();
        [$sl, $sc] = self::STATUSES[(int) $f['status']] ?? ['?', '#64748b'];

        echo "<div class='rh'><div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'>";
        echo "<div class='rh-avatar rh-avatar-lg'>" . $e(Menu::initials((string) $f['name'])) . "</div><div class='me-auto'><div class='rh-crumb'>Admissão</div><h2 class='m-0'>" . $e($f['name']) . "</h2>";
        echo "<div class='mt-1 d-flex flex-wrap gap-2 align-items-center'>" . Menu::badge($sl, $sc) . "<span class='small text-secondary'>Vaga: <a href='" . $e(Vaga::getFormURLWithID((int) $f['plugin_rh_vagas_id'])) . "'>"
            . $e(\Dropdown::getDropdownName(Vaga::getTable(), (int) $f['plugin_rh_vagas_id'], false, true, false, '')) . "</a> · Gestor: " . $e(getUserName((int) $f['users_id_manager'])) . "</span></div></div>";
        echo "<div style='min-width:240px'><div class='d-flex justify-content-between small'><span>Checklist</span><b>$done / " . count($items) . " ($pct%)</b></div><div class='progress mt-1' style='height:10px'><div class='progress-bar bg-success' style='width:$pct%'></div></div></div>";
        echo "</div></div>";

        echo "<div class='row g-3'><div class='col-xl-8'><div class='row g-3'>";
        foreach (self::AREAS as $area => [$label, $icon, $color]) {
            $list = array_filter($items, fn ($i) => $i['area'] === $area);
            $d = count(array_filter($list, fn ($i) => $i['is_done']));
            echo "<div class='col-md-6'><div class='card h-100'><div class='card-header' style='border-top:3px solid $color'><h3 class='card-title'><i class='ti $icon me-2' style='color:$color'></i>$label</h3><div class='card-actions small text-secondary'>$d/" . count($list) . "</div></div><div class='list-group list-group-flush'>";
            foreach ($list as $i) {
                $by = $i['is_done'] ? "<div class='small text-secondary'>" . $e(getUserName((int) $i['users_id'])) . ' · ' . Html::convDateTime($i['date_done']) . "</div>" : '';
                echo "<label class='list-group-item d-flex gap-2 align-items-start" . ($i['is_done'] ? ' rh-done' : '') . "'><input type='checkbox' class='form-check-input mt-1' data-rh-check='" . (int) $i['id'] . "'" . ($i['is_done'] ? ' checked' : '') . ($edit ? '' : ' disabled') . "><span>" . $e($i['label']) . $by . "</span></label>";
            }
            echo "</div></div></div>";
        }
        echo "</div>";
        if ($edit) {
            echo "<form method='post' action='$a' class='card mt-3'><div class='card-body d-flex flex-wrap gap-2 align-items-end'><input type='hidden' name='id' value='$id'>"
                . "<div><label class='form-label'>Área</label>" . \Dropdown::showFromArray('area', array_map(fn ($x) => $x[0], self::AREAS), ['display' => false]) . "</div>"
                . "<div class='flex-grow-1'><label class='form-label'>Novo item do checklist</label><input class='form-control' name='label' maxlength='255'></div>"
                . "<button class='btn btn-outline-primary' name='additem' value='1'><i class='ti ti-plus me-1'></i>Adicionar</button></div>" . Html::closeForm(false);
        }
        echo "</div><div class='col-xl-4'>";
        echo Docs::card($this, 'Documentos admissionais', 'RG, CPF, comprovante de residência, ASO, contrato assinado, ficha de registro…');
        echo "<form method='post' action='$a' class='card mb-3'><input type='hidden' name='id' value='$id'><div class='card-header'><h3 class='card-title'><i class='ti ti-calendar me-2'></i>Dados</h3></div><div class='card-body'>";
        echo "<label class='form-label'>Data de início</label><input type='date' class='form-control mb-2' name='start_date' value='" . $e($f['start_date']) . "'" . ($edit ? '' : ' disabled') . ">";
        echo "<label class='form-label'>Observações</label><textarea class='form-control' rows='3' name='comment'" . ($edit ? '' : ' disabled') . ">" . $e($f['comment']) . "</textarea></div>";
        if ($edit) {
            echo "<div class='card-footer'><button class='btn btn-primary' name='update' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div>";
        }
        echo Html::closeForm(false);

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-device-laptop me-2'></i>Chamado de TI</h3></div><div class='card-body'>";
        if ($f['tickets_id']) {
            $t = new Ticket();
            $t->getFromDB((int) $f['tickets_id']);
            echo "<a class='fw-bold' href='" . $e(Ticket::getFormURLWithID((int) $f['tickets_id'])) . "'>#" . (int) $f['tickets_id'] . ' ' . $e($t->fields['name'] ?? '') . "</a><div class='small text-secondary mt-1'>Status: " . $e(Ticket::getStatus((int) ($t->fields['status'] ?? 0))) . "</div>";
        } elseif ($edit) {
            echo "<p class='small text-secondary'>Abre uma requisição para a TI com os itens de TI deste checklist (usuário, e-mail, equipamento e acessos).</p>"
                . "<form method='post' action='$a'><input type='hidden' name='id' value='$id'><button class='btn btn-primary w-100' name='ticket' value='1'><i class='ti ti-ticket me-1'></i>Abrir chamado de TI</button>" . Html::closeForm(false);
        } else {
            echo "<span class='text-secondary small'>Nenhum chamado aberto.</span>";
        }
        echo "</div></div>";
        if ($edit) {
            echo "<form method='post' action='$a' class='d-flex gap-2'><input type='hidden' name='id' value='$id'>"
                . "<button class='btn btn-success flex-grow-1' name='finish' value='1'" . ($pct < 100 ? " onclick=\"return confirm('Ainda há itens pendentes no checklist. Concluir mesmo assim?')\"" : '') . "><i class='ti ti-circle-check me-1'></i>Concluir admissão</button>"
                . "<button class='btn btn-ghost-secondary' name='cancel' value='1' onclick=\"return confirm('Cancelar esta admissão?')\">Cancelar</button>" . Html::closeForm(false);
        }
        echo "</div></div></div>";
        echo "<script>document.querySelectorAll('[data-rh-check]').forEach((c)=>c.addEventListener('change',async()=>{c.disabled=true;try{const r=await fetch(" . json_encode(Menu::url('/front/ajax.php')) . ",{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check',admissao:$id,item:+c.dataset.rhCheck,done:c.checked})});const d=await r.json();if(!r.ok||d.ok===false)throw new Error(d.error||r.status);location.reload();}catch(e){alert(e.message);c.checked=!c.checked;c.disabled=false;}}));</script>";
        return true;
    }
}
