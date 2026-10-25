<?php

namespace GlpiPlugin\Reembolso;

use CommonDBTM;
use Document_Item;
use Dropdown;
use Html;
use Location;
use Log;
use RuntimeException;
use Session;
use Ticket;
use User;

/**
 * Solicitação de reembolso: técnico, centro de custo, localização, chamado e
 * período, com as despesas lançadas e o fluxo rascunho → enviado → aprovado
 * (ou recusado) → pago.
 */
class Reembolso extends CommonDBTM
{
    public static string $rightname = 'plugin_reembolso';
    public bool $dohistory = true;

    /** Direitos além dos padrões do GLPI. */
    public const READALL = 1024;
    public const APPROVE = 2048;

    public const DRAFT     = 1;
    public const SUBMITTED = 2;
    public const APPROVED  = 3;
    public const REFUSED   = 4;
    public const PAID      = 5;

    public const STATUSES = [
        self::DRAFT     => ['Rascunho', '#64748b', 'ti-pencil'],
        self::SUBMITTED => ['Aguardando aprovação', '#f59e0b', 'ti-hourglass'],
        self::APPROVED  => ['Aprovado (a pagar)', '#3b82f6', 'ti-circle-check'],
        self::REFUSED   => ['Recusado', '#ef4444', 'ti-circle-x'],
        self::PAID      => ['Pago', '#22c55e', 'ti-cash'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Reembolsos' : 'Reembolso';
    }

    public static function getIcon()
    {
        return 'ti ti-receipt-2';
    }

    // ------------------------------------------------------------------ direitos

    public static function canView(): bool
    {
        return Session::haveRightsOr(self::$rightname, [READ, self::READALL]);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [UPDATE, self::APPROVE]);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, PURGE);
    }

    public static function isApprover(): bool
    {
        return Session::haveRight(self::$rightname, self::APPROVE);
    }

    public function isOwner(): bool
    {
        $me = (int) Session::getLoginUserID();
        return (int) ($this->fields['users_id'] ?? 0) === $me || (int) ($this->fields['users_id_recipient'] ?? 0) === $me;
    }

    public function canViewItem(): bool
    {
        return Session::haveRight(self::$rightname, self::READALL) || $this->isOwner() || $this->isNewItem();
    }

    /** Cabeçalho e despesas podem ser mexidos? (rascunho/recusado pelo dono; enviado só pelo aprovador) */
    public function isEditable(): bool
    {
        if ($this->isNewItem()) {
            return self::canCreate();
        }
        $status = (int) $this->fields['status'];
        if (in_array($status, [self::DRAFT, self::REFUSED], true)) {
            return self::isApprover() || ($this->isOwner() && Session::haveRight(self::$rightname, UPDATE));
        }
        return $status === self::SUBMITTED && self::isApprover();
    }

    public function canUpdateItem(): bool
    {
        return $this->isEditable();
    }

    public function canPurgeItem(): bool
    {
        return self::canPurge() && (self::isApprover() || ((int) $this->fields['status'] === self::DRAFT && $this->isOwner()));
    }

    public function getRights($interface = 'central')
    {
        return [
            READ          => 'Ver os próprios',
            CREATE        => __('Create'),
            UPDATE        => __('Update'),
            PURGE         => 'Excluir',
            self::READALL => 'Ver todos',
            self::APPROVE => 'Aprovar e registrar pagamento',
        ];
    }

    // ------------------------------------------------------------------ GLPI

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Document_Item::class, $ong, $options);
        $this->addStandardTab(Log::class, $ong, $options);
        return $ong;
    }

    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        if ($menu) {
            $menu['title'] = 'Reembolsos';
            $menu['icon'] = self::getIcon();
            $menu['links']['<i class="ti ti-chart-bar" title="Dashboard"></i>'] = Dashboard::getSearchURL(false);
        }
        return $menu;
    }

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Solicitação', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'name' => 'Técnico', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 4, 'table' => Centrocusto::getTable(), 'field' => 'name', 'name' => 'Centro de custo', 'datatype' => 'dropdown'],
            ['id' => 5, 'table' => 'glpi_locations', 'field' => 'completename', 'name' => __('Location'), 'datatype' => 'dropdown'],
            ['id' => 6, 'table' => 'glpi_tickets', 'field' => 'name', 'name' => 'Chamado', 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'status', 'name' => __('Status'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'total', 'name' => 'Total (R$)', 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => 9, 'table' => $t, 'field' => 'date_begin', 'name' => 'Período: início', 'datatype' => 'date'],
            ['id' => 10, 'table' => $t, 'field' => 'date_end', 'name' => 'Período: fim', 'datatype' => 'date'],
            ['id' => 11, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_approver', 'name' => 'Aprovado por', 'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false],
            ['id' => 12, 'table' => $t, 'field' => 'date_approval', 'name' => 'Data da aprovação', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 13, 'table' => $t, 'field' => 'date_payment', 'name' => 'Data do pagamento', 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 14, 'table' => $t, 'field' => 'address', 'name' => 'Local do atendimento', 'datatype' => 'string'],
            ['id' => 15, 'table' => $t, 'field' => 'date_submit', 'name' => 'Enviado em', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'status') {
            return self::statusBadge((int) ($values[$field] ?? 0));
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'status') {
            $options['display'] = false;
            $options['value'] = $values[$field] ?? '';
            return Dropdown::showFromArray($name, array_map(fn ($s) => $s[0], self::STATUSES), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public static function statusBadge(int $status): string
    {
        [$label, $color, $icon] = self::STATUSES[$status] ?? ['?', '#64748b', 'ti-help'];
        return "<span class='badge rb-status' style='--c:$color'><i class='ti $icon me-1'></i>" . htmlspecialchars($label) . "</span>";
    }

    public function prepareInputForAdd($input)
    {
        $me = (int) Session::getLoginUserID();
        $input['users_id'] = (int) ($input['users_id'] ?? 0) ?: $me;
        $input['users_id_recipient'] = $me;
        $input['status'] = self::DRAFT;
        $input['total'] = 0;
        $input['entities_id'] ??= $_SESSION['glpiactive_entity'] ?? 0;
        foreach (['date_begin', 'date_end'] as $f) {
            if (empty($input[$f])) {
                $input[$f] = date('Y-m-d');
            }
        }
        unset($input['users_id_approver'], $input['date_approval'], $input['approval_comment'], $input['date_payment'], $input['payment_reference'], $input['date_submit']);
        return $this->validateHeader($input);
    }

    public function prepareInputForUpdate($input)
    {
        // status, total e dados da aprovação só mudam pelo fluxo (transition)
        if (empty($input['_workflow'])) {
            unset($input['status'], $input['total'], $input['users_id_approver'], $input['date_approval'], $input['approval_comment'],
                $input['date_payment'], $input['payment_reference'], $input['date_submit'], $input['users_id_recipient']);
            if (!self::isApprover()) {
                unset($input['users_id']); // técnico não troca o dono da solicitação
            }
        }
        return $this->validateHeader($input);
    }

    private function validateHeader(array $input)
    {
        if (!empty($input['date_begin']) && !empty($input['date_end']) && $input['date_end'] < $input['date_begin']) {
            Session::addMessageAfterRedirect('O fim do período não pode ser antes do início.', false, ERROR);
            return false;
        }
        return $input;
    }

    public function post_addItem()
    {
        global $DB;
        if (empty($this->fields['name'])) {
            $name = self::code((int) $this->getID());
            $DB->update($this->getTable(), ['name' => $name], ['id' => $this->getID()]);
            $this->fields['name'] = $name;
        }
        parent::post_addItem();
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([Despesa::class]);
    }

    public static function code(int $id): string
    {
        return 'RB-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /** Recalcula o total da solicitação (soma das despesas). */
    public static function recalc(int $id): float
    {
        global $DB;
        $total = (float) ($DB->request(['SELECT' => ['SUM' => 'amount AS t'], 'FROM' => Despesa::getTable(), 'WHERE' => ['plugin_reembolso_reembolsos_id' => $id]])->current()['t'] ?? 0);
        $DB->update(self::getTable(), ['total' => $total], ['id' => $id]);
        return $total;
    }

    public function countDocuments(): int
    {
        return countElementsInTable(Document_Item::getTable(), ['itemtype' => self::class, 'items_id' => $this->getID()]);
    }

    // ------------------------------------------------------------------ fluxo

    /**
     * Muda o status: submit (enviar), approve, refuse, pay e reopen.
     * @return string mensagem de sucesso
     */
    public function transition(string $action, array $in): string
    {
        $status = (int) $this->fields['status'];
        $now = $_SESSION['glpi_currenttime'];
        $me = (int) Session::getLoginUserID();
        $data = ['id' => $this->getID(), '_workflow' => true];

        switch ($action) {
            case 'submit':
                if (!in_array($status, [self::DRAFT, self::REFUSED], true) || !($this->isOwner() || self::isApprover())) {
                    throw new RuntimeException('Só o técnico pode enviar um rascunho (ou uma solicitação recusada).');
                }
                $expenses = Despesa::forReembolso($this->getID());
                if (!$expenses) {
                    throw new RuntimeException('Lance pelo menos uma despesa antes de enviar.');
                }
                if (!$this->fields['plugin_reembolso_centrocustos_id']) {
                    throw new RuntimeException('Informe o centro de custo antes de enviar.');
                }
                $types = Tipodespesa::all();
                $needsReceipt = array_filter($expenses, fn ($e) => $types[(int) $e['plugin_reembolso_tipodespesas_id']]['receipt'] ?? false);
                if ($needsReceipt && Money::config()['require_receipt'] && !$this->countDocuments()) {
                    throw new RuntimeException('Há despesas que exigem comprovante: anexe os comprovantes na aba "Documentos" antes de enviar.');
                }
                $data += ['status' => self::SUBMITTED, 'date_submit' => $now, 'approval_comment' => ''];
                $msg = 'Solicitação enviada para aprovação.';
                break;

            case 'approve':
            case 'refuse':
                if ($status !== self::SUBMITTED || !self::isApprover()) {
                    throw new RuntimeException('Só quem aprova reembolsos pode aprovar ou recusar uma solicitação enviada.');
                }
                $comment = trim((string) ($in['approval_comment'] ?? ''));
                if ($action === 'refuse' && $comment === '') {
                    throw new RuntimeException('Informe o motivo da recusa.');
                }
                $data += ['status' => $action === 'approve' ? self::APPROVED : self::REFUSED, 'users_id_approver' => $me, 'date_approval' => $now, 'approval_comment' => $comment];
                $msg = $action === 'approve' ? 'Reembolso aprovado.' : 'Reembolso recusado; o técnico pode corrigir e enviar de novo.';
                break;

            case 'pay':
                if ($status !== self::APPROVED || !self::isApprover()) {
                    throw new RuntimeException('Só um reembolso aprovado pode ser marcado como pago.');
                }
                $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['date_payment'] ?? '')) ? $in['date_payment'] : date('Y-m-d');
                $data += ['status' => self::PAID, 'date_payment' => $date, 'payment_reference' => mb_substr(trim((string) ($in['payment_reference'] ?? '')), 0, 255)];
                $msg = 'Pagamento registrado.';
                break;

            case 'reopen':
                if (!self::isApprover() || !in_array($status, [self::SUBMITTED, self::APPROVED, self::REFUSED], true)) {
                    throw new RuntimeException('Não é possível reabrir esta solicitação.');
                }
                $data += ['status' => self::DRAFT, 'users_id_approver' => 0, 'date_approval' => null, 'date_submit' => null];
                $msg = 'Solicitação reaberta como rascunho.';
                break;

            default:
                throw new RuntimeException('Ação desconhecida.');
        }
        $this->update($data);
        $this->getFromDB($this->getID());
        Avisos::transition($this, $action);
        return $msg;
    }

    // ------------------------------------------------------------------ formulário

    public function showForm($ID, array $options = [])
    {
        global $CFG_GLPI;
        $new = $this->isNewItem();
        if ($new) {
            $this->getEmpty();
            $this->fields['users_id'] = Session::getLoginUserID();
            $this->fields['date_begin'] = date('Y-m-d');
            $this->fields['date_end'] = date('Y-m-d');
            $this->fields['status'] = self::DRAFT;
            // novo reembolso a partir de um chamado: traz chamado, entidade e localização
            $ticket = new Ticket();
            if (!empty($_GET['tickets_id']) && $ticket->getFromDB((int) $_GET['tickets_id']) && $ticket->canViewItem()) {
                $this->fields['tickets_id'] = $ticket->getID();
                $this->fields['locations_id'] = (int) $ticket->fields['locations_id'];
                $this->fields['entities_id'] = (int) $ticket->fields['entities_id'];
                $this->fields['date_begin'] = $this->fields['date_end'] = substr((string) $ticket->fields['date'], 0, 10) ?: date('Y-m-d');
            }
        }
        $f = $this->fields;
        $edit = $this->isEditable();
        $dis = $edit ? '' : 'disabled';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $status = (int) $f['status'];
        $action = $e(self::getFormURL());

        echo "<div class='rb'>";
        // cabeçalho com código, status, etapas e total
        echo "<div class='rb-head card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'>";
        echo "<div class='rb-head-icon'><i class='ti ti-receipt-2'></i></div><div class='me-auto'>";
        echo "<div class='text-secondary small text-uppercase fw-bold'>Solicitação de reembolso</div>";
        echo "<h2 class='m-0'>" . ($new ? 'Nova solicitação' : $e($f['name'])) . "</h2>";
        echo "<div class='text-secondary small rb-subtitle mt-1'>" . $e(Menu::SUBTITLES[$new ? 'form_new' : 'form']) . "</div>";
        if (!$new) {
            echo "<div class='mt-1'>" . self::statusBadge($status) . "</div>";
        }
        echo "</div>";
        if (!$new) {
            echo "<div class='text-end'><div class='text-secondary small text-uppercase fw-bold'>Total</div><div class='rb-total'>" . Money::format((float) $f['total']) . "</div></div>";
        }
        echo "</div>";
        if (!$new) {
            echo "<div class='rb-steps'>";
            $steps = [self::DRAFT => 'Rascunho', self::SUBMITTED => 'Enviado', self::APPROVED => 'Aprovado', self::PAID => 'Pago'];
            $order = [self::DRAFT => 1, self::REFUSED => 1, self::SUBMITTED => 2, self::APPROVED => 3, self::PAID => 4];
            foreach ($steps as $k => $label) {
                $cls = $order[$k] < $order[$status] ? 'done' : ($order[$k] === $order[$status] ? 'current' : '');
                if ($status === self::REFUSED && $k === self::DRAFT) {
                    $label = 'Recusado';
                    $cls = 'refused';
                }
                echo "<div class='rb-step $cls'><span></span>$label</div>";
            }
            echo "</div>";
        }
        echo "</div>";

        if (!$new && $status === self::REFUSED && $f['approval_comment']) {
            echo "<div class='alert alert-danger'><b>Recusado por " . $e(getUserName((int) $f['users_id_approver'])) . ":</b> " . nl2br($e($f['approval_comment'])) . "</div>";
        }

        // dados da solicitação
        echo "<form method='post' action='$action' class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-id-badge-2 me-2'></i>Dados da solicitação</h3></div><div class='card-body'><div class='row g-3'>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label required'>Técnico</label>";
        echo User::dropdown(['name' => 'users_id', 'value' => (int) $f['users_id'], 'right' => 'all', 'entity' => (int) $f['entities_id'], 'display' => false, 'width' => '100%',
            'disabled' => !$edit || (!$new && !self::isApprover())]);
        echo "</div>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label required'>Centro de custo</label>";
        echo Centrocusto::dropdown(['name' => 'plugin_reembolso_centrocustos_id', 'value' => (int) $f['plugin_reembolso_centrocustos_id'], 'entity' => (int) $f['entities_id'], 'display' => false, 'width' => '100%', 'disabled' => !$edit]);
        echo "</div>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label'>Chamado do atendimento</label>";
        echo Ticket::dropdown(['name' => 'tickets_id', 'value' => (int) $f['tickets_id'], 'entity' => (int) $f['entities_id'], 'entity_sons' => true, 'display' => false, 'width' => '100%', 'displaywith' => ['id'], 'disabled' => !$edit]);
        if ($f['tickets_id']) {
            echo "<div class='form-hint'><a href='" . $e(Ticket::getFormURLWithID((int) $f['tickets_id'])) . "'><i class='ti ti-external-link me-1'></i>Abrir o chamado</a></div>";
        }
        echo "</div>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label'>Localização</label>";
        echo Location::dropdown(['name' => 'locations_id', 'value' => (int) $f['locations_id'], 'entity' => (int) $f['entities_id'], 'entity_sons' => true, 'display' => false, 'width' => '100%', 'disabled' => !$edit]);
        echo "</div>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label'>Local do atendimento (endereço / cidade)</label><input class='form-control' name='address' maxlength='255' placeholder='Ex.: Av. Paulista, 1000 - São Paulo/SP' value='" . $e($f['address']) . "' $dis></div>";
        echo "<div class='col-6 col-xl-2'><label class='form-label required'>Período: início</label><input type='date' class='form-control' name='date_begin' value='" . $e($f['date_begin']) . "' required $dis></div>";
        echo "<div class='col-6 col-xl-2'><label class='form-label required'>Período: fim</label><input type='date' class='form-control' name='date_end' value='" . $e($f['date_end']) . "' required $dis></div>";
        echo "<div class='col-12'><label class='form-label'>Justificativa / observações</label><textarea class='form-control' rows='2' name='comment' placeholder='Motivo do deslocamento, cliente atendido, observações para o aprovador…' $dis>" . $e($f['comment']) . "</textarea></div>";
        echo "</div></div>";
        if ($edit) {
            echo "<div class='card-footer d-flex gap-2'>";
            if ($new) {
                echo "<input type='hidden' name='entities_id' value='" . (int) $f['entities_id'] . "'>";
                echo "<button class='btn btn-primary' name='add' value='1'><i class='ti ti-arrow-right me-1'></i>Criar e lançar despesas</button>";
            } else {
                echo "<input type='hidden' name='id' value='" . (int) $this->getID() . "'>";
                echo "<button class='btn btn-primary' name='update' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar dados</button>";
                if ($this->canPurgeItem()) {
                    echo "<button class='btn btn-ghost-danger ms-auto' name='purge' value='1' onclick=\"return confirm('Excluir esta solicitação e todas as despesas?')\"><i class='ti ti-trash me-1'></i>Excluir</button>";
                }
            }
            echo "</div>";
        }
        Html::closeForm();

        if ($new) {
            echo "</div>";
            return true;
        }

        $this->showExpenses($edit);
        $this->showWorkflow();
        echo "</div>";
        return true;
    }

    /** Tabela de despesas e janela de lançamento (public/js/reembolso.js). */
    private function showExpenses(bool $edit): void
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $types = Tipodespesa::all();
        $rows = Despesa::forReembolso($this->getID());
        $docs = $this->countDocuments();
        $needsReceipt = false;

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-list-details me-2'></i>Despesas</h3>";
        if ($edit) {
            echo "<div class='card-actions'><button type='button' class='btn btn-primary' data-rb-add><i class='ti ti-plus me-1'></i>Lançar despesa</button></div>";
        }
        echo "</div>";
        if (!$rows) {
            echo "<div class='card-body text-secondary'>Nenhuma despesa lançada." . ($edit ? ' Clique em <b>Lançar despesa</b> para começar.' : '') . "</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-vcenter card-table rb-table'><thead><tr><th>Data</th><th>Tipo</th><th>Descrição / detalhe</th><th class='text-end'>Valor</th>" . ($edit ? '<th></th>' : '') . "</tr></thead><tbody>";
            foreach ($rows as $r) {
                $t = $types[(int) $r['plugin_reembolso_tipodespesas_id']] ?? ['name' => '?', 'mileage' => false, 'max' => 0, 'receipt' => false];
                $needsReceipt = $needsReceipt || $t['receipt'];
                $detail = $e($r['description']);
                if ($t['mileage']) {
                    $route = trim($r['origin'] . ($r['destination'] ? ' → ' . $r['destination'] : ''));
                    $odo = $r['km_start'] !== null && $r['km_end'] !== null ? 'odômetro ' . Money::km((float) $r['km_start']) . ' → ' . Money::km((float) $r['km_end']) : '';
                    $detail .= "<div class='small text-secondary'><i class='ti ti-route me-1'></i>" . $e($route ?: 'sem endereços') . ($r['round_trip'] ? ' (ida e volta)' : '')
                        . ($odo ? " · $odo" : '') . " · <b>" . Money::km((float) $r['distance']) . "</b> × " . Money::format((float) $r['km_rate']) . "/km</div>";
                }
                $over = $t['max'] > 0 && (float) $r['amount'] > $t['max'] ? "<div><span class='badge bg-warning-lt' title='Limite do tipo: " . Money::format($t['max']) . "'><i class='ti ti-alert-triangle me-1'></i>acima do limite</span></div>" : '';
                echo "<tr><td class='text-nowrap'>" . Html::convDate($r['date']) . "</td><td>" . ($t['mileage'] ? "<i class='ti ti-car me-1 text-primary'></i>" : '') . $e($t['name']) . "</td>"
                    . "<td>$detail</td><td class='text-end text-nowrap fw-bold'>" . Money::format((float) $r['amount']) . "$over</td>";
                if ($edit) {
                    echo "<td class='text-end text-nowrap'><button type='button' class='btn btn-sm btn-ghost-secondary' data-rb-edit='" . (int) $r['id'] . "' title='Editar'><i class='ti ti-pencil'></i></button>"
                        . "<button type='button' class='btn btn-sm btn-ghost-danger' data-rb-del='" . (int) $r['id'] . "' title='Excluir'><i class='ti ti-trash'></i></button></td>";
                }
                echo "</tr>";
            }
            echo "</tbody><tfoot><tr><th colspan='3' class='text-end'>Total</th><th class='text-end rb-total-sm'>" . Money::format((float) $this->fields['total']) . "</th>" . ($edit ? '<th></th>' : '') . "</tr></tfoot></table></div>";
        }
        $tab = $e($this->getLinkURL() . '&forcetab=' . rawurlencode('Document_Item$1'));
        echo "<div class='card-footer small " . ($needsReceipt && !$docs ? 'text-warning' : 'text-secondary') . "'><i class='ti ti-paperclip me-1'></i>"
            . ($docs ? "$docs comprovante(s) anexado(s)." : ($needsReceipt ? 'Há despesas que exigem comprovante e nenhum foi anexado.' : 'Nenhum comprovante anexado.'))
            . " <a href='$tab'>Anexar comprovantes (aba Documentos)</a></div></div>";

        if ($edit) {
            $boot = [
                'reembolso' => (int) $this->getID(),
                'ajax'      => Menu::url('/front/ajax.php'),
                'types'     => array_values($types),
                'expenses'  => array_map(fn ($r) => Despesa::toArray($r), $rows),
                'config'    => Money::config(),
                'approver'  => self::isApprover(),
                'period'    => [$this->fields['date_begin'], $this->fields['date_end']],
            ];
            echo "<script type='application/json' id='rb-boot'>" . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
            echo Html::script(Menu::url('/js/reembolso.js'), ['version' => Menu::fileVersion('js/reembolso.js')], false);
        }
    }

    /** Ações do fluxo conforme o status e o perfil. */
    private function showWorkflow(): void
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $status = (int) $this->fields['status'];
        $action = $e(self::getFormURL());
        $id = (int) $this->getID();
        $hidden = "<input type='hidden' name='id' value='$id'>";
        $buttons = [];

        if (in_array($status, [self::DRAFT, self::REFUSED], true) && ($this->isOwner() || self::isApprover())) {
            $buttons[] = "<form method='post' action='$action'>$hidden<button class='btn btn-success' name='workflow' value='submit'><i class='ti ti-send me-1'></i>Enviar para aprovação</button>" . Html::closeForm(false);
        }
        if ($status === self::SUBMITTED && self::isApprover()) {
            $buttons[] = "<form method='post' action='$action' class='rb-approve'>$hidden<textarea class='form-control mb-2' name='approval_comment' rows='2' placeholder='Comentário (obrigatório para recusar)'></textarea>"
                . "<div class='d-flex gap-2'><button class='btn btn-success' name='workflow' value='approve'><i class='ti ti-circle-check me-1'></i>Aprovar</button>"
                . "<button class='btn btn-outline-danger' name='workflow' value='refuse'><i class='ti ti-circle-x me-1'></i>Recusar</button></div>" . Html::closeForm(false);
        }
        if ($status === self::APPROVED && self::isApprover()) {
            $buttons[] = "<form method='post' action='$action' class='d-flex flex-wrap gap-2 align-items-end'>$hidden"
                . "<div><label class='form-label'>Data do pagamento</label><input type='date' class='form-control' name='date_payment' value='" . date('Y-m-d') . "'></div>"
                . "<div class='flex-grow-1'><label class='form-label'>Referência (PIX, lote, folha…)</label><input class='form-control' name='payment_reference' maxlength='255'></div>"
                . "<button class='btn btn-success' name='workflow' value='pay'><i class='ti ti-cash me-1'></i>Marcar como pago</button>" . Html::closeForm(false);
        }
        if (in_array($status, [self::SUBMITTED, self::APPROVED, self::REFUSED], true) && self::isApprover()) {
            $buttons[] = "<form method='post' action='$action'>$hidden<button class='btn btn-ghost-secondary' name='workflow' value='reopen' onclick=\"return confirm('Voltar a solicitação para rascunho?')\"><i class='ti ti-arrow-back-up me-1'></i>Reabrir como rascunho</button>" . Html::closeForm(false);
        }

        $f = $this->fields;
        $facts = [];
        if ($f['date_submit']) {
            $facts[] = ['Enviado em', Html::convDateTime($f['date_submit'])];
        }
        if ($f['users_id_approver']) {
            $facts[] = [$status === self::REFUSED ? 'Recusado por' : 'Aprovado por', $e(getUserName((int) $f['users_id_approver'])) . ' em ' . Html::convDateTime($f['date_approval'])];
        }
        if ($f['approval_comment'] && $status !== self::REFUSED) {
            $facts[] = ['Comentário do aprovador', nl2br($e($f['approval_comment']))];
        }
        if ($status === self::PAID) {
            $facts[] = ['Pago em', Html::convDate($f['date_payment']) . ($f['payment_reference'] ? ' · ' . $e($f['payment_reference']) : '')];
        }
        $facts[] = ['Lançado por', $e(getUserName((int) $f['users_id_recipient'])) . ' em ' . Html::convDateTime($f['date_creation'])];

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-git-pull-request me-2'></i>Aprovação e pagamento</h3></div><div class='card-body'><div class='row g-3'>";
        echo "<div class='col-lg-5'><dl class='row mb-0 small'>";
        foreach ($facts as [$k, $v]) {
            echo "<dt class='col-5 text-secondary'>$k</dt><dd class='col-7'>$v</dd>";
        }
        echo "</dl></div><div class='col-lg-7 d-flex flex-column gap-3'>";
        echo $buttons ? implode('', $buttons) : "<div class='text-secondary small'>Nenhuma ação disponível para você nesta etapa.</div>";
        echo "</div></div></div></div>";
    }
}
