<?php

namespace GlpiPlugin\Rh;

use CommonDBTM;
use Dropdown;
use Html;
use Location;
use Log;
use NotificationEvent;
use RuntimeException;
use Session;
use User;

/**
 * Requisição de vaga, com aprovação do gestor da área e da diretoria, e o
 * processo seletivo (funil) depois de aberta.
 */
class Vaga extends CommonDBTM
{
    public static string $rightname = 'plugin_rh_recrutamento';
    public bool $dohistory = true;

    public const READALL       = 1024;
    public const APPROVE_BOARD = 2048;

    public const DRAFT        = 1;
    public const WAIT_MANAGER = 2;
    public const WAIT_BOARD   = 3;
    public const OPEN         = 4;
    public const FILLED       = 5;
    public const CANCELED     = 6;
    public const REJECTED     = 7;

    public const STATUSES = [
        self::DRAFT        => ['Rascunho', '#64748b', 'ti-pencil'],
        self::WAIT_MANAGER => ['Aguardando gestor', '#f59e0b', 'ti-hourglass'],
        self::WAIT_BOARD   => ['Aguardando diretoria', '#f97316', 'ti-hourglass-high'],
        self::OPEN         => ['Aberta (em seleção)', '#3b82f6', 'ti-user-search'],
        self::FILLED       => ['Preenchida', '#22c55e', 'ti-circle-check'],
        self::CANCELED     => ['Cancelada', '#94a3b8', 'ti-ban'],
        self::REJECTED     => ['Reprovada', '#ef4444', 'ti-circle-x'],
    ];

    public const CONTRACTS = ['clt' => 'CLT', 'pj' => 'PJ', 'estagio' => 'Estágio', 'temporario' => 'Temporário', 'aprendiz' => 'Jovem aprendiz'];
    public const WORKMODES = ['presencial' => 'Presencial', 'hibrido' => 'Híbrido', 'remoto' => 'Remoto'];
    public const REASONS = ['aumento' => 'Aumento de quadro', 'substituicao' => 'Substituição'];
    public const PRIORITIES = [1 => 'Alta', 2 => 'Normal', 3 => 'Baixa'];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Vagas' : 'Vaga';
    }

    public static function getIcon()
    {
        return 'ti ti-briefcase-2';
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
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE]);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, PURGE);
    }

    public static function isRH(): bool
    {
        return Session::haveRight(self::$rightname, self::READALL) && Session::haveRight(self::$rightname, UPDATE);
    }

    public static function isBoard(): bool
    {
        return Session::haveRight(self::$rightname, self::APPROVE_BOARD);
    }

    public function isMine(): bool
    {
        $me = (int) Session::getLoginUserID();
        return in_array($me, [(int) ($this->fields['users_id_requester'] ?? 0), (int) ($this->fields['users_id_manager'] ?? 0), (int) ($this->fields['users_id_recruiter'] ?? 0)], true);
    }

    public function canViewItem(): bool
    {
        return $this->isNewItem() || Session::haveRight(self::$rightname, self::READALL) || $this->isMine();
    }

    /** Dados da requisição: o solicitante (ou o RH) mexe em rascunho/reprovada; o RH também com a vaga aberta. */
    public function isEditable(): bool
    {
        if ($this->isNewItem()) {
            return self::canCreate();
        }
        $status = (int) $this->fields['status'];
        $requester = (int) $this->fields['users_id_requester'] === (int) Session::getLoginUserID();
        if (in_array($status, [self::DRAFT, self::REJECTED], true)) {
            return $requester || self::isRH();
        }
        return $status === self::OPEN && self::isRH();
    }

    public function canUpdateItem(): bool
    {
        return $this->isEditable();
    }

    public function canPurgeItem(): bool
    {
        return self::canPurge() && ((int) $this->fields['status'] === self::DRAFT || self::isRH());
    }

    /** Pode mover candidatos no funil? (RH, recrutador ou gestor da vaga, com a vaga aberta) */
    public function canManagePipeline(): bool
    {
        if ((int) $this->fields['status'] !== self::OPEN) {
            return false;
        }
        $me = (int) Session::getLoginUserID();
        return self::isRH() || in_array($me, [(int) $this->fields['users_id_recruiter'], (int) $this->fields['users_id_manager']], true);
    }

    public function getRights($interface = 'central')
    {
        return [
            READ                => 'Ver as próprias (solicitante, gestor, recrutador)',
            CREATE              => 'Solicitar vaga',
            UPDATE              => 'Conduzir seleção (RH)',
            PURGE               => 'Excluir',
            self::READALL       => 'Ver todas e o banco de candidatos (RH)',
            self::APPROVE_BOARD => 'Aprovar como diretoria',
        ];
    }

    // ------------------------------------------------------------------ GLPI

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
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Vaga', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => Cargo::getTable(), 'field' => 'name', 'name' => 'Cargo', 'datatype' => 'dropdown'],
            ['id' => 4, 'table' => Departamento::getTable(), 'field' => 'name', 'name' => 'Departamento', 'datatype' => 'dropdown'],
            ['id' => 5, 'table' => $t, 'field' => 'status', 'name' => __('Status'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 6, 'table' => $t, 'field' => 'positions', 'name' => 'Posições', 'datatype' => 'number'],
            ['id' => 7, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_requester', 'name' => 'Solicitante', 'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false],
            ['id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_manager', 'name' => 'Gestor', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 9, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_recruiter', 'name' => 'Recrutador', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 10, 'table' => $t, 'field' => 'contract', 'name' => 'Contratação', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
            ['id' => 11, 'table' => $t, 'field' => 'workmode', 'name' => 'Modalidade', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
            ['id' => 12, 'table' => 'glpi_locations', 'field' => 'completename', 'name' => __('Location'), 'datatype' => 'dropdown'],
            ['id' => 13, 'table' => $t, 'field' => 'deadline', 'name' => 'Prazo', 'datatype' => 'date'],
            ['id' => 14, 'table' => $t, 'field' => 'date_open', 'name' => 'Aberta em', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 15, 'table' => $t, 'field' => 'date_close', 'name' => 'Encerrada em', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 16, 'table' => $t, 'field' => 'salary_max', 'name' => 'Salário até', 'datatype' => 'decimal'],
            ['id' => 17, 'table' => $t, 'field' => 'priority', 'name' => 'Prioridade', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown', 'massiveaction' => false],
        ];
    }

    private const SPECIFIC = ['status' => 'STATUSES', 'contract' => 'CONTRACTS', 'workmode' => 'WORKMODES', 'priority' => 'PRIORITIES'];

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'status') {
            return self::statusBadge((int) ($values[$field] ?? 0));
        }
        if (isset(self::SPECIFIC[$field])) {
            return Menu::e(constant(self::class . '::' . self::SPECIFIC[$field])[$values[$field]] ?? '');
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (isset(self::SPECIFIC[$field])) {
            $list = constant(self::class . '::' . self::SPECIFIC[$field]);
            if ($field === 'status') {
                $list = array_map(fn ($s) => $s[0], $list);
            }
            return Dropdown::showFromArray($name, $list, ['value' => $values[$field] ?? '', 'display' => false] + $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public static function statusBadge(int $status): string
    {
        [$label, $color, $icon] = self::STATUSES[$status] ?? ['?', '#64748b', 'ti-help'];
        return Menu::badge($label, $color, $icon);
    }

    public function prepareInputForAdd($input)
    {
        $input['users_id_requester'] = (int) Session::getLoginUserID();
        $input['status'] = self::DRAFT;
        $input['entities_id'] ??= $_SESSION['glpiactive_entity'] ?? 0;
        // gestor padrão: o do departamento
        if (empty($input['users_id_manager']) && !empty($input['plugin_rh_departamentos_id'])) {
            $input['users_id_manager'] = Departamento::manager((int) $input['plugin_rh_departamentos_id']);
        }
        return $this->clean($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (empty($input['_workflow'])) {
            unset($input['status'], $input['users_id_requester'], $input['date_submit'], $input['date_open'], $input['date_close']);
        }
        return $this->clean($input);
    }

    private function clean(array $input)
    {
        foreach (['salary_min', 'salary_max'] as $f) {
            if (isset($input[$f])) {
                $input[$f] = max(0, Menu::parseMoney((string) $input[$f]));
            }
        }
        if (isset($input['positions'])) {
            $input['positions'] = max(1, min(500, (int) $input['positions']));
        }
        if (!empty($input['salary_min']) && !empty($input['salary_max']) && $input['salary_max'] < $input['salary_min']) {
            Session::addMessageAfterRedirect('O salário máximo não pode ser menor que o mínimo.', false, ERROR);
            return false;
        }
        if (empty($input['_workflow']) && array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect('Informe o título da vaga.', false, ERROR);
            return false;
        }
        if (isset($input['deadline']) && $input['deadline'] === '') {
            $input['deadline'] = null;
        }
        return $input;
    }

    public function cleanDBonPurge()
    {
        global $DB;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Candidatura::getTable(), 'WHERE' => ['plugin_rh_vagas_id' => $this->getID()]]) as $c) {
            $DB->delete('glpi_plugin_rh_historicos', ['plugin_rh_candidaturas_id' => $c['id']]);
        }
        $DB->delete(Candidatura::getTable(), ['plugin_rh_vagas_id' => $this->getID()]);
        $DB->delete('glpi_plugin_rh_aprovacoes', ['itemtype' => self::class, 'items_id' => $this->getID()]);
    }

    public function hiredCount(): int
    {
        return countElementsInTable(Candidatura::getTable(), ['plugin_rh_vagas_id' => $this->getID(), 'status' => 'hired']);
    }

    // ------------------------------------------------------------------ fluxo

    public function transition(string $action, array $in): string
    {
        $status = (int) $this->fields['status'];
        $me = (int) Session::getLoginUserID();
        $now = $_SESSION['glpi_currenttime'];
        $comment = trim((string) ($in['comment'] ?? ''));
        $data = ['id' => $this->getID(), '_workflow' => true];
        $event = null;

        switch ($action) {
            case 'submit':
                if (!in_array($status, [self::DRAFT, self::REJECTED], true) || !((int) $this->fields['users_id_requester'] === $me || self::isRH())) {
                    throw new RuntimeException('Só o solicitante (ou o RH) envia uma requisição em rascunho.');
                }
                if (!$this->fields['plugin_rh_cargos_id'] || !$this->fields['plugin_rh_departamentos_id']) {
                    throw new RuntimeException('Informe o cargo e o departamento antes de enviar.');
                }
                $manager = (int) $this->fields['users_id_manager'] ?: Departamento::manager((int) $this->fields['plugin_rh_departamentos_id']);
                $data += ['users_id_manager' => $manager, 'date_submit' => $now, 'date_close' => null, 'close_comment' => ''];
                Aprovacoes::record(self::class, $this->getID(), 'submit', 'done', $comment);
                if ($manager && $manager === $me) {
                    // quem pede é o próprio gestor: a etapa do gestor fica aprovada
                    Aprovacoes::record(self::class, $this->getID(), 'manager', 'approved', 'Aprovada automaticamente: o solicitante é o gestor da área.');
                    $data['status'] = self::WAIT_BOARD;
                    $event = 'rh_vaga_diretoria';
                    $msg = 'Requisição enviada. Como você é o gestor da área, ela foi direto para a diretoria.';
                } else {
                    $data['status'] = self::WAIT_MANAGER;
                    $event = 'rh_vaga_gestor';
                    $msg = $manager ? 'Requisição enviada para aprovação de ' . getUserName($manager) . '.' : 'Requisição enviada. O departamento não tem gestor: a diretoria aprova as duas etapas.';
                }
                break;

            case 'approve_manager':
                if ($status !== self::WAIT_MANAGER || !((int) $this->fields['users_id_manager'] === $me || self::isBoard())) {
                    throw new RuntimeException('Esta etapa é do gestor da área.');
                }
                Aprovacoes::record(self::class, $this->getID(), 'manager', 'approved', $comment);
                $data['status'] = self::WAIT_BOARD;
                $event = 'rh_vaga_diretoria';
                $msg = 'Aprovada pelo gestor. Agora segue para a diretoria.';
                break;

            case 'approve_board':
                if ($status !== self::WAIT_BOARD || !self::isBoard()) {
                    throw new RuntimeException('Esta etapa é da diretoria.');
                }
                Aprovacoes::record(self::class, $this->getID(), 'board', 'approved', $comment);
                $data += ['status' => self::OPEN, 'date_open' => $now];
                $event = 'rh_vaga_aprovada';
                $msg = 'Vaga aprovada e aberta para seleção.';
                break;

            case 'reject':
                $canManager = $status === self::WAIT_MANAGER && ((int) $this->fields['users_id_manager'] === $me || self::isBoard());
                $canBoard = $status === self::WAIT_BOARD && self::isBoard();
                if (!$canManager && !$canBoard) {
                    throw new RuntimeException('Você não pode reprovar esta requisição nesta etapa.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o motivo da reprovação.');
                }
                Aprovacoes::record(self::class, $this->getID(), $status === self::WAIT_MANAGER ? 'manager' : 'board', 'rejected', $comment);
                $data += ['status' => self::REJECTED, 'close_comment' => $comment];
                $event = 'rh_vaga_reprovada';
                $msg = 'Requisição reprovada. O solicitante pode ajustar e enviar de novo.';
                break;

            case 'fill':
                if ($status !== self::OPEN || !self::isRH()) {
                    throw new RuntimeException('Só o RH encerra uma vaga aberta.');
                }
                Aprovacoes::record(self::class, $this->getID(), 'close', 'filled', $comment);
                $data += ['status' => self::FILLED, 'date_close' => $now, 'close_comment' => $comment];
                $msg = 'Vaga encerrada como preenchida.';
                break;

            case 'cancel':
                $requester = (int) $this->fields['users_id_requester'] === $me;
                if (!in_array($status, [self::DRAFT, self::WAIT_MANAGER, self::WAIT_BOARD, self::OPEN], true) || !($requester || self::isRH())) {
                    throw new RuntimeException('Não é possível cancelar esta vaga.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o motivo do cancelamento.');
                }
                Aprovacoes::record(self::class, $this->getID(), 'close', 'canceled', $comment);
                $data += ['status' => self::CANCELED, 'date_close' => $now, 'close_comment' => $comment];
                $msg = 'Vaga cancelada.';
                break;

            case 'reopen':
                if (!in_array($status, [self::FILLED, self::CANCELED], true) || !self::isRH()) {
                    throw new RuntimeException('Só o RH reabre uma vaga encerrada.');
                }
                Aprovacoes::record(self::class, $this->getID(), 'reopen', 'done', $comment);
                $data += ['status' => $this->fields['date_open'] ? self::OPEN : self::DRAFT, 'date_close' => null];
                $msg = 'Vaga reaberta.';
                break;

            default:
                throw new RuntimeException('Ação desconhecida.');
        }
        $this->update($data);
        $this->getFromDB($this->getID());
        if ($event) {
            NotificationEvent::raiseEvent($event, $this, ['comment' => $comment]);
        }
        return $msg;
    }

    /** Chamado quando um candidato é contratado: com todas as posições preenchidas, encerra a vaga. */
    public function checkFilled(): bool
    {
        if ((int) $this->fields['status'] === self::OPEN && $this->hiredCount() >= (int) $this->fields['positions']) {
            Aprovacoes::record(self::class, $this->getID(), 'close', 'filled', 'Todas as posições foram preenchidas.');
            $this->update(['id' => $this->getID(), '_workflow' => true, 'status' => self::FILLED, 'date_close' => $_SESSION['glpi_currenttime']]);
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------ formulário

    public function showForm($ID, array $options = [])
    {
        $new = $this->isNewItem();
        if ($new) {
            $this->getEmpty();
            $this->fields['positions'] = 1;
            $this->fields['priority'] = 2;
            $this->fields['status'] = self::DRAFT;
            $this->fields['users_id_requester'] = Session::getLoginUserID();
        }
        $f = $this->fields;
        $e = [Menu::class, 'e'];
        $edit = $this->isEditable();
        $dis = $edit ? '' : 'disabled';
        $status = (int) $f['status'];
        $sel = function (string $name, array $list, $value) use ($edit) {
            return Dropdown::showFromArray($name, $list, ['value' => $value, 'display' => false, 'width' => '100%', 'disabled' => !$edit]);
        };

        echo "<div class='rh'>";
        echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'>";
        echo "<div class='rh-icon'><i class='ti ti-briefcase-2'></i></div><div class='me-auto'><div class='rh-crumb'>Requisição de vaga" . ($new ? '' : ' · VG-' . str_pad((string) $this->getID(), 4, '0', STR_PAD_LEFT)) . "</div>";
        echo "<h2 class='m-0'>" . ($new ? 'Nova requisição de vaga' : $e($f['name'])) . "</h2>";
        if (!$new) {
            echo "<div class='mt-1 d-flex gap-2 flex-wrap'>" . self::statusBadge($status) . Menu::badge(self::PRIORITIES[(int) $f['priority']] ?? '', (int) $f['priority'] === 1 ? '#ef4444' : '#64748b', 'ti-flag') . "</div>";
        }
        echo "</div>";
        if (!$new && in_array($status, [self::OPEN, self::FILLED], true)) {
            $hired = $this->hiredCount();
            $active = countElementsInTable(Candidatura::getTable(), ['plugin_rh_vagas_id' => $this->getID(), 'status' => 'active']);
            echo "<div class='text-end'><div class='rh-crumb'>Seleção</div><div class='fs-3 fw-bold'>$hired / " . (int) $f['positions'] . " <small class='fs-5 text-secondary'>contratados</small></div><div class='small text-secondary'>$active candidato(s) em processo</div></div>";
            echo "<a class='btn btn-primary btn-lg' href='" . $e(Menu::url('/front/pipeline.php?vaga=' . $this->getID())) . "'><i class='ti ti-layout-kanban me-1'></i>Abrir funil de seleção</a>";
        }
        echo "</div>";
        if (!$new) {
            echo $this->timeline();
        }
        echo "</div>";

        if (!$new && in_array($status, [self::REJECTED, self::CANCELED], true) && $f['close_comment']) {
            echo "<div class='alert alert-" . ($status === self::REJECTED ? 'danger' : 'secondary') . "'><b>" . ($status === self::REJECTED ? 'Motivo da reprovação' : 'Motivo do cancelamento') . ":</b> " . nl2br($e($f['close_comment'])) . "</div>";
        }

        echo "<form method='post' action='" . $e(self::getFormURL()) . "'>";
        echo "<div class='row g-3'><div class='col-xl-8'>";
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-file-description me-2'></i>Dados da vaga</h3></div><div class='card-body row g-3'>";
        echo "<div class='col-md-8'><label class='form-label required'>Título da vaga</label><input class='form-control' name='name' maxlength='255' value='" . $e($f['name']) . "' placeholder='Ex.: Analista de Suporte N2 - Curitiba' required $dis></div>";
        echo "<div class='col-md-4'><label class='form-label'>Prioridade</label>" . $sel('priority', self::PRIORITIES, (int) $f['priority']) . "</div>";
        echo "<div class='col-md-6'><label class='form-label required'>Cargo</label>" . Cargo::dropdown(['name' => 'plugin_rh_cargos_id', 'value' => (int) $f['plugin_rh_cargos_id'], 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        echo "<div class='col-md-6'><label class='form-label required'>Departamento</label>" . Departamento::dropdown(['name' => 'plugin_rh_departamentos_id', 'value' => (int) $f['plugin_rh_departamentos_id'], 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        echo "<div class='col-6 col-md-3'><label class='form-label'>Posições</label><input type='number' min='1' max='500' class='form-control' name='positions' value='" . (int) $f['positions'] . "' $dis></div>";
        echo "<div class='col-6 col-md-3'><label class='form-label'>Contratação</label>" . $sel('contract', self::CONTRACTS, $f['contract'] ?: 'clt') . "</div>";
        echo "<div class='col-6 col-md-3'><label class='form-label'>Modalidade</label>" . $sel('workmode', self::WORKMODES, $f['workmode'] ?: 'presencial') . "</div>";
        echo "<div class='col-6 col-md-3'><label class='form-label'>Prazo para preencher</label><input type='date' class='form-control' name='deadline' value='" . $e($f['deadline']) . "' $dis></div>";
        echo "<div class='col-md-6'><label class='form-label'>Local de trabalho</label>" . Location::dropdown(['name' => 'locations_id', 'value' => (int) $f['locations_id'], 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        echo "<div class='col-6 col-md-3'><label class='form-label'>Salário de (R$)</label><input class='form-control' name='salary_min' value='" . ((float) $f['salary_min'] ? number_format((float) $f['salary_min'], 2, ',', '.') : '') . "' $dis></div>";
        echo "<div class='col-6 col-md-3'><label class='form-label'>Salário até (R$)</label><input class='form-control' name='salary_max' value='" . ((float) $f['salary_max'] ? number_format((float) $f['salary_max'], 2, ',', '.') : '') . "' $dis></div>";
        echo "<div class='col-md-4'><label class='form-label'>Motivo</label>" . $sel('reason', self::REASONS, $f['reason'] ?: 'aumento') . "</div>";
        echo "<div class='col-md-8'><label class='form-label'>Substitui (se for substituição)</label><input class='form-control' name='replaced_name' maxlength='255' value='" . $e($f['replaced_name']) . "' $dis></div>";
        if ($edit) {
            echo "<div class='col-12'><div class='rh-jd-pull' id='rh-jd-pull' data-url='" . $e(Menu::url('/front/ajax.php')) . "'><i class='ti ti-file-import'></i><div class='flex-grow-1'><b>Job description do cargo</b>"
                . "<div class='small text-secondary' id='rh-jd-msg'>Ao escolher o cargo, a descrição e os requisitos são preenchidos com o job description dele. Depois é só editar e acrescentar o que for desta vaga.</div></div>"
                . "<button type='button' class='btn btn-sm btn-outline-primary' id='rh-jd-btn'><i class='ti ti-refresh me-1'></i>Puxar do cargo</button></div></div>";
        }
        foreach (['description' => ['Descrição e atividades', 'Principais responsabilidades do cargo…', 8], 'requirements' => ['Requisitos', 'Formação, experiência, certificações, conhecimentos…', 8], 'benefits' => ['Benefícios', 'VR/VA, plano de saúde, auxílio home office…', 3]] as $field => [$label, $ph, $rows]) {
            echo "<div class='col-12'><label class='form-label'>$label</label><textarea class='form-control' rows='" . ($f[$field] || $field !== 'benefits' ? $rows : 3) . "' name='$field' id='rh-f-$field' placeholder='$ph' $dis>" . $e($f[$field]) . "</textarea></div>";
        }
        echo "</div></div>";
        if ($edit) {
            echo Menu::script('vaga.js');
        }
        // job description do cargo (cadastro de Cargos), para quem aprova e para o recrutador
        $cargo = new Cargo();
        if ($f['plugin_rh_cargos_id'] && $cargo->getFromDB((int) $f['plugin_rh_cargos_id']) && trim(strip_tags((string) $cargo->fields['job_description'])) !== '') {
            echo "<details class='card mb-3 rh-jd'" . ($new ? '' : ' open') . "><summary class='card-header'><h3 class='card-title'><i class='ti ti-file-text me-2'></i>Job description: " . $e($cargo->fields['name'])
                . ($cargo->fields['cbo'] ? " <span class='badge bg-secondary-lt ms-2'>CBO " . $e($cargo->fields['cbo']) . "</span>" : '') . "</h3>"
                . "<div class='card-actions'><a class='small' href='" . $e(Cargo::getFormURLWithID($cargo->getID())) . "'><i class='ti ti-pencil me-1'></i>editar no cadastro de cargos</a></div></summary>"
                . "<div class='card-body rich_text_container'>" . \Glpi\RichText\RichText::getEnhancedHtml($cargo->fields['job_description']) . "</div></details>";
        }
        echo "</div><div class='col-xl-4'>";
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-users me-2'></i>Pessoas</h3></div><div class='card-body row g-3'>";
        echo "<div class='col-12'><label class='form-label'>Solicitante</label><div class='form-control-plaintext'>" . $e(getUserName((int) $f['users_id_requester'])) . "</div></div>";
        echo "<div class='col-12'><label class='form-label'>Gestor da área (1ª aprovação)</label>" . User::dropdown(['name' => 'users_id_manager', 'value' => (int) $f['users_id_manager'], 'right' => 'all', 'display' => false, 'width' => '100%', 'disabled' => !$edit])
            . "<div class='form-hint'>Vazio = o gestor cadastrado no departamento.</div></div>";
        echo "<div class='col-12'><label class='form-label'>Recrutador (RH)</label>" . User::dropdown(['name' => 'users_id_recruiter', 'value' => (int) $f['users_id_recruiter'], 'right' => 'all', 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        echo "</div>";
        if ($edit) {
            echo "<div class='card-footer d-flex gap-2'>";
            if ($new) {
                echo "<input type='hidden' name='entities_id' value='" . (int) ($_SESSION['glpiactive_entity'] ?? 0) . "'>";
                echo "<button class='btn btn-primary w-100' name='add' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar rascunho</button>";
            } else {
                echo "<input type='hidden' name='id' value='" . (int) $this->getID() . "'>";
                echo "<button class='btn btn-primary' name='update' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>";
                if ($this->canPurgeItem()) {
                    echo "<button class='btn btn-ghost-danger ms-auto' name='purge' value='1' onclick=\"return confirm('Excluir esta vaga e todo o processo seletivo?')\"><i class='ti ti-trash'></i></button>";
                }
            }
            echo "</div>";
        }
        echo "</div>";
        Html::closeForm();
        if (!$new) {
            $this->showActions();
        }
        echo "</div></div></div>";
        return true;
    }

    /** Linha do tempo visual do processo da vaga. */
    private function timeline(): string
    {
        $e = [Menu::class, 'e'];
        $f = $this->fields;
        $status = (int) $f['status'];
        $decisions = Aprovacoes::forItem(self::class, $this->getID());
        $last = fn (string $step) => array_values(array_filter(array_reverse($decisions), fn ($d) => $d['step'] === $step))[0] ?? null;
        $who = fn ($d) => $d ? $e(getUserName((int) $d['users_id'])) . ' · ' . Html::convDateTime($d['date']) . ($d['comment'] ? "<div class='rh-step-comment'>“" . $e($d['comment']) . "”</div>" : '') : '';

        $manager = $last('manager');
        $board = $last('board');
        $steps = [
            ['Requisição', 'ti-file-plus', 'done', $e(getUserName((int) $f['users_id_requester'])) . ' · ' . Html::convDateTime($f['date_creation'])],
            ['Enviada', 'ti-send', $f['date_submit'] ? 'done' : ($status === self::DRAFT ? 'current' : 'todo'), $f['date_submit'] ? Html::convDateTime($f['date_submit']) : 'aguardando envio'],
            ['Gestor da área', 'ti-user-check', $manager ? ($manager['decision'] === 'approved' ? 'done' : 'rejected') : ($status === self::WAIT_MANAGER ? 'current' : 'todo'),
                $manager ? $who($manager) : ((int) $f['users_id_manager'] ? 'com ' . $e(getUserName((int) $f['users_id_manager'])) : 'gestor do departamento')],
            ['Diretoria', 'ti-building', $board ? ($board['decision'] === 'approved' ? 'done' : 'rejected') : ($status === self::WAIT_BOARD ? 'current' : 'todo'), $board ? $who($board) : 'quem tem o direito de aprovar como diretoria'],
            ['Seleção', 'ti-user-search', $f['date_open'] ? ($status === self::OPEN ? 'current' : 'done') : 'todo', $f['date_open'] ? 'aberta em ' . Html::convDateTime($f['date_open']) : ''],
            [$status === self::CANCELED ? 'Cancelada' : 'Preenchida', $status === self::CANCELED ? 'ti-ban' : 'ti-circle-check', $status === self::FILLED ? 'done' : ($status === self::CANCELED ? 'rejected' : 'todo'), $f['date_close'] ? Html::convDateTime($f['date_close']) : ''],
        ];
        // reprovada: o fluxo para na etapa que reprovou
        if ($status === self::REJECTED) {
            $steps[4][2] = $steps[5][2] = 'todo';
        }
        $html = "<div class='rh-timeline'>";
        foreach ($steps as [$label, $icon, $state, $detail]) {
            $html .= "<div class='rh-step $state'><div class='rh-step-dot'><i class='ti $icon'></i></div><div class='rh-step-label'>$label</div><div class='rh-step-detail'>$detail</div></div>";
        }
        return $html . "</div>";
    }

    /** Ações do fluxo conforme o status e quem está vendo. */
    private function showActions(): void
    {
        $status = (int) $this->fields['status'];
        $me = (int) Session::getLoginUserID();
        $requester = (int) $this->fields['users_id_requester'] === $me;
        $a = Menu::e(self::getFormURL());
        $id = (int) $this->getID();
        $form = function (string $buttons, bool $comment = true, string $ph = 'Comentário') use ($a, $id) {
            return "<form method='post' action='$a' class='mb-2'><input type='hidden' name='id' value='$id'>" . ($comment ? "<textarea class='form-control mb-2' rows='2' name='comment' placeholder='$ph'></textarea>" : '')
                . "<div class='d-flex flex-wrap gap-2'>$buttons</div>" . Html::closeForm(false);
        };
        $out = [];
        if (in_array($status, [self::DRAFT, self::REJECTED], true) && ($requester || self::isRH())) {
            $out[] = $form("<button class='btn btn-success w-100' name='workflow' value='submit'><i class='ti ti-send me-1'></i>Enviar para aprovação</button>", true, 'Observação para os aprovadores (opcional)');
        }
        if ($status === self::WAIT_MANAGER && ((int) $this->fields['users_id_manager'] === $me || self::isBoard())) {
            $out[] = $form("<button class='btn btn-success' name='workflow' value='approve_manager'><i class='ti ti-check me-1'></i>Aprovar (gestor)</button><button class='btn btn-outline-danger' name='workflow' value='reject'><i class='ti ti-x me-1'></i>Reprovar</button>", true, 'Comentário (obrigatório para reprovar)');
        }
        if ($status === self::WAIT_BOARD && self::isBoard()) {
            $out[] = $form("<button class='btn btn-success' name='workflow' value='approve_board'><i class='ti ti-check me-1'></i>Aprovar (diretoria)</button><button class='btn btn-outline-danger' name='workflow' value='reject'><i class='ti ti-x me-1'></i>Reprovar</button>", true, 'Comentário (obrigatório para reprovar)');
        }
        if ($status === self::OPEN && self::isRH()) {
            $out[] = $form("<button class='btn btn-outline-success' name='workflow' value='fill'><i class='ti ti-circle-check me-1'></i>Encerrar como preenchida</button>", false);
        }
        if (in_array($status, [self::DRAFT, self::WAIT_MANAGER, self::WAIT_BOARD, self::OPEN], true) && ($requester || self::isRH())) {
            $out[] = "<details class='mt-1'><summary class='small text-secondary'>Cancelar a vaga</summary>" . $form("<button class='btn btn-sm btn-outline-secondary' name='workflow' value='cancel'><i class='ti ti-ban me-1'></i>Cancelar vaga</button>", true, 'Motivo do cancelamento (obrigatório)') . "</details>";
        }
        if (in_array($status, [self::FILLED, self::CANCELED], true) && self::isRH()) {
            $out[] = $form("<button class='btn btn-outline-secondary' name='workflow' value='reopen'><i class='ti ti-arrow-back-up me-1'></i>Reabrir</button>", false);
        }
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-git-pull-request me-2'></i>Aprovação</h3></div><div class='card-body'>";
        echo $out ? implode('', $out) : "<div class='text-secondary small'>Nenhuma ação sua nesta etapa.</div>";
        echo "</div></div>";
    }
}
