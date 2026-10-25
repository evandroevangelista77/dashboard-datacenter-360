<?php

namespace GlpiPlugin\Rh;

use CommonDBTM;
use Dropdown;
use Html;
use Log;
use NotificationEvent;
use RuntimeException;
use Session;
use User;

/**
 * Pedido de férias: o colaborador pede, o gestor (por padrão, o "Responsável"
 * do usuário no GLPI) aprova ou reprova, e o calendário mostra a equipe.
 */
class Feria extends CommonDBTM
{
    public static string $rightname = 'plugin_rh_ferias';
    public bool $dohistory = true;

    public const READALL = 1024;
    public const APPROVE = 2048;

    public const PENDING  = 2;
    public const APPROVED = 3;
    public const REJECTED = 4;
    public const CANCELED = 5;

    public const STATUSES = [
        self::PENDING  => ['Aguardando gestor', '#f59e0b', 'ti-hourglass'],
        self::APPROVED => ['Aprovada', '#22c55e', 'ti-circle-check'],
        self::REJECTED => ['Reprovada', '#ef4444', 'ti-circle-x'],
        self::CANCELED => ['Cancelada', '#94a3b8', 'ti-ban'],
    ];

    public static function getTypeName($nb = 0)
    {
        return 'Férias';
    }

    public static function getIcon()
    {
        return 'ti ti-beach';
    }

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

    public function canViewItem(): bool
    {
        $me = (int) Session::getLoginUserID();
        return $this->isNewItem() || Session::haveRight(self::$rightname, self::READALL)
            || in_array($me, [(int) $this->fields['users_id'], (int) $this->fields['users_id_manager']], true);
    }

    /** Só dá para mudar o pedido enquanto aguarda o gestor (o próprio colaborador ou o RH). */
    public function canUpdateItem(): bool
    {
        return (int) $this->fields['status'] === self::PENDING
            && ((int) $this->fields['users_id'] === (int) Session::getLoginUserID() || Session::haveRight(self::$rightname, self::READALL));
    }

    public function canApprove(): bool
    {
        $me = (int) Session::getLoginUserID();
        return (int) $this->fields['status'] === self::PENDING && (int) $this->fields['users_id'] !== $me
            && ((int) $this->fields['users_id_manager'] === $me || Session::haveRight(self::$rightname, self::APPROVE));
    }

    public function getRights($interface = 'central')
    {
        return [
            READ          => 'Ver as próprias e as da equipe que gere',
            CREATE        => 'Pedir férias',
            UPDATE        => __('Update'),
            PURGE         => 'Excluir',
            self::READALL => 'Ver todas (RH)',
            self::APPROVE => 'Aprovar de qualquer colaborador',
        ];
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
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Pedido', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'name' => 'Colaborador', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 4, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_manager', 'name' => 'Gestor', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 5, 'table' => $t, 'field' => 'date_begin', 'name' => 'Início', 'datatype' => 'date'],
            ['id' => 6, 'table' => $t, 'field' => 'date_end', 'name' => 'Fim', 'datatype' => 'date'],
            ['id' => 7, 'table' => $t, 'field' => 'days', 'name' => 'Dias', 'datatype' => 'number'],
            ['id' => 8, 'table' => $t, 'field' => 'status', 'name' => __('Status'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 9, 'table' => $t, 'field' => 'sell_days', 'name' => 'Abono (dias vendidos)', 'datatype' => 'number'],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'status') {
            return self::statusBadge((int) $values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'status') {
            return Dropdown::showFromArray($name, array_map(fn ($s) => $s[0], self::STATUSES), ['value' => $values[$field] ?? '', 'display' => false] + $options);
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
        $me = (int) Session::getLoginUserID();
        $input['users_id'] = Session::haveRight(self::$rightname, self::READALL) && !empty($input['users_id']) ? (int) $input['users_id'] : $me;
        if (empty($input['users_id_manager'])) {
            $u = new User();
            $input['users_id_manager'] = $u->getFromDB($input['users_id']) ? (int) $u->fields['users_id_supervisor'] : 0;
        }
        $input['status'] = self::PENDING;
        $input['entities_id'] ??= $_SESSION['glpiactive_entity'] ?? 0;
        return $this->validate($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (empty($input['_workflow'])) {
            unset($input['status'], $input['users_id_approver'], $input['date_approval'], $input['approval_comment']);
            if (!Session::haveRight(self::$rightname, self::READALL)) {
                unset($input['users_id']);
            }
            $input = $this->validate($input + ['users_id' => $this->fields['users_id']]);
        }
        return $input;
    }

    private function validate(array $input)
    {
        global $DB;
        $begin = $input['date_begin'] ?? $this->fields['date_begin'] ?? null;
        $end = $input['date_end'] ?? $this->fields['date_end'] ?? null;
        $fail = function (string $msg) {
            Session::addMessageAfterRedirect($msg, false, ERROR);
            return false;
        };
        if (!$begin || !$end || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $begin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return $fail('Informe o início e o fim das férias.');
        }
        if ($end < $begin) {
            return $fail('O fim das férias não pode ser antes do início.');
        }
        $days = (int) round((strtotime($end) - strtotime($begin)) / DAY_TIMESTAMP) + 1;
        if ($days < 5) {
            return $fail('Cada período de férias precisa ter pelo menos 5 dias corridos.');
        }
        if ($days > 30) {
            return $fail('O período não pode passar de 30 dias.');
        }
        $sell = max(0, min(10, (int) ($input['sell_days'] ?? $this->fields['sell_days'] ?? 0)));
        if ($days + $sell > 30) {
            return $fail('Dias de férias + abono não podem passar de 30.');
        }
        // sobreposição com outro pedido do mesmo colaborador
        $overlap = $DB->request([
            'COUNT' => 'c', 'FROM' => self::getTable(),
            'WHERE' => [
                'users_id' => (int) $input['users_id'], 'is_deleted' => 0, 'status' => [self::PENDING, self::APPROVED],
                'date_begin' => ['<=', $end], 'date_end' => ['>=', $begin], 'NOT' => ['id' => (int) ($this->fields['id'] ?? 0)],
            ],
        ])->current()['c'];
        if ($overlap) {
            return $fail('Já existe um pedido de férias nesse período para este colaborador.');
        }
        $input['days'] = $days;
        $input['sell_days'] = $sell;
        $input['name'] = 'Férias de ' . getUserName((int) $input['users_id']) . ' (' . date('d/m/Y', strtotime($begin)) . ' a ' . date('d/m/Y', strtotime($end)) . ')';
        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        NotificationEvent::raiseEvent('rh_ferias_pendente', $this);
        $conflicts = self::conflicts($this->fields);
        $this->avisar('ferias_pendente', [(int) $this->fields['users_id_manager']], 'Férias aguardando sua aprovação',
            getUserName((int) $this->fields['users_id']) . ' pediu ' . (int) $this->fields['days'] . ' dias de férias.', 'warning',
            $conflicts ? ['Fora no mesmo período' => implode(', ', $conflicts)] : []);
    }

    /** Colegas da mesma equipe (mesmo gestor) com férias aprovadas que se sobrepõem. */
    public static function conflicts(array $f): array
    {
        global $DB;
        if (!(int) $f['users_id_manager']) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'FROM' => self::getTable(),
            'WHERE' => [
                'users_id_manager' => (int) $f['users_id_manager'], 'status' => self::APPROVED, 'is_deleted' => 0,
                'date_begin' => ['<=', $f['date_end']], 'date_end' => ['>=', $f['date_begin']], 'NOT' => ['users_id' => (int) $f['users_id']],
            ],
        ]) as $r) {
            $out[] = getUserName((int) $r['users_id']) . ' (' . date('d/m', strtotime($r['date_begin'])) . '–' . date('d/m', strtotime($r['date_end'])) . ')';
        }
        return $out;
    }

    public function transition(string $action, array $in): string
    {
        $comment = trim((string) ($in['comment'] ?? ''));
        $data = ['id' => $this->getID(), '_workflow' => true];
        switch ($action) {
            case 'approve':
            case 'reject':
                if (!$this->canApprove()) {
                    throw new RuntimeException('Só o gestor do colaborador (ou quem aprova férias) decide este pedido.');
                }
                if ($action === 'reject' && $comment === '') {
                    throw new RuntimeException('Informe o motivo da reprovação.');
                }
                Aprovacoes::record(self::class, $this->getID(), 'manager', $action === 'approve' ? 'approved' : 'rejected', $comment);
                $data += ['status' => $action === 'approve' ? self::APPROVED : self::REJECTED, 'users_id_approver' => (int) Session::getLoginUserID(),
                    'date_approval' => $_SESSION['glpi_currenttime'], 'approval_comment' => $comment];
                $msg = $action === 'approve' ? 'Férias aprovadas.' : 'Férias reprovadas.';
                break;
            case 'cancel':
                $mine = (int) $this->fields['users_id'] === (int) Session::getLoginUserID();
                if (!in_array((int) $this->fields['status'], [self::PENDING, self::APPROVED], true) || !($mine || Session::haveRight(self::$rightname, self::READALL))) {
                    throw new RuntimeException('Não é possível cancelar este pedido.');
                }
                if ((int) $this->fields['status'] === self::APPROVED && $this->fields['date_begin'] <= date('Y-m-d') && !Session::haveRight(self::$rightname, self::READALL)) {
                    throw new RuntimeException('Férias já iniciadas só podem ser canceladas pelo RH.');
                }
                Aprovacoes::record(self::class, $this->getID(), 'cancel', 'canceled', $comment);
                $data += ['status' => self::CANCELED];
                $msg = 'Pedido cancelado.';
                break;
            default:
                throw new RuntimeException('Ação desconhecida.');
        }
        $this->update($data);
        $this->getFromDB($this->getID());
        if ($action !== 'cancel') {
            NotificationEvent::raiseEvent('rh_ferias_decisao', $this, ['comment' => $comment]);
            $ok = $action === 'approve';
            $this->avisar('ferias_decisao', [(int) $this->fields['users_id']], $ok ? 'Suas férias foram aprovadas' : 'Suas férias foram reprovadas',
                ($ok ? 'Aprovadas por ' : 'Reprovadas por ') . getUserName((int) Session::getLoginUserID()) . ($comment !== '' ? ". Comentário: $comment" : '.'), $ok ? 'success' : 'danger');
        } else {
            NotificationEvent::raiseEvent('rh_ferias_cancelada', $this, ['comment' => $comment]);
            $this->avisar('ferias_cancelada', [(int) $this->fields['users_id_manager'], (int) $this->fields['users_id']], 'Férias canceladas',
                'O pedido de férias de ' . getUserName((int) $this->fields['users_id']) . ' foi cancelado' . ($comment !== '' ? ": $comment" : '.'), 'info');
        }
        return $msg;
    }

    private function avisar(string $event, array $users, string $title, string $message, string $level, array $facts = []): void
    {
        $f = $this->fields;
        Avisos::send([
            'event' => $event, 'users' => $users, 'title' => $title, 'message' => $message, 'level' => $level,
            'url' => self::getFormURLWithID($this->getID()), 'item' => [self::class, $this->getID()],
            'facts' => ['Colaborador' => (string) getUserName((int) $f['users_id']), 'Período' => date('d/m/Y', strtotime($f['date_begin'])) . ' a ' . date('d/m/Y', strtotime($f['date_end'])),
                'Dias' => (int) $f['days'] . ((int) $f['sell_days'] ? ' + ' . (int) $f['sell_days'] . ' de abono' : ''), 'Situação' => self::STATUSES[(int) $f['status']][0] ?? ''] + $facts,
        ]);
    }

    public function showForm($ID, array $options = [])
    {
        $new = $this->isNewItem();
        if ($new) {
            $this->getEmpty();
            $this->fields['users_id'] = Session::getLoginUserID();
            $u = new User();
            $this->fields['users_id_manager'] = $u->getFromDB((int) Session::getLoginUserID()) ? (int) $u->fields['users_id_supervisor'] : 0;
            $this->fields['status'] = self::PENDING;
        }
        $f = $this->fields;
        $e = [Menu::class, 'e'];
        $edit = $new ? self::canCreate() : $this->canUpdateItem();
        $dis = $edit ? '' : 'disabled';
        $rh = Session::haveRight(self::$rightname, self::READALL);
        $a = $e(self::getFormURL());

        echo "<div class='rh'><div class='row g-3'><div class='col-xl-7'>";
        echo "<form method='post' action='$a' class='card'>";
        echo "<div class='card-header'><div class='rh-icon me-3'><i class='ti ti-beach'></i></div><div><div class='rh-crumb'>Férias</div><h3 class='card-title m-0'>" . ($new ? 'Pedir férias' : $e($f['name'])) . "</h3>"
            . ($new ? '' : "<div class='mt-1'>" . self::statusBadge((int) $f['status']) . "</div>") . "</div></div><div class='card-body row g-3'>";
        echo "<div class='col-md-6'><label class='form-label'>Colaborador</label>" . ($rh && $edit
            ? User::dropdown(['name' => 'users_id', 'value' => (int) $f['users_id'], 'right' => 'all', 'display' => false, 'width' => '100%'])
            : "<div class='form-control-plaintext'>" . $e(getUserName((int) $f['users_id'])) . "</div>") . "</div>";
        echo "<div class='col-md-6'><label class='form-label'>Gestor que aprova</label>" . User::dropdown(['name' => 'users_id_manager', 'value' => (int) $f['users_id_manager'], 'right' => 'all', 'display' => false, 'width' => '100%', 'disabled' => !$edit])
            . "<div class='form-hint'>Padrão: o \"Responsável\" do usuário no GLPI.</div></div>";
        echo "<div class='col-6 col-md-4'><label class='form-label required'>Início</label><input type='date' class='form-control' name='date_begin' data-rh-days value='" . $e($f['date_begin']) . "' required $dis></div>";
        echo "<div class='col-6 col-md-4'><label class='form-label required'>Fim</label><input type='date' class='form-control' name='date_end' data-rh-days value='" . $e($f['date_end']) . "' required $dis></div>";
        echo "<div class='col-6 col-md-2'><label class='form-label'>Dias</label><div class='form-control-plaintext fw-bold' id='rh-days'>" . ((int) $f['days'] ?: '—') . "</div></div>";
        echo "<div class='col-6 col-md-2'><label class='form-label'>Abono</label><input type='number' min='0' max='10' class='form-control' name='sell_days' value='" . (int) $f['sell_days'] . "' title='Dias vendidos (até 10)' $dis></div>";
        echo "<div class='col-12'><label class='form-label'>Observação</label><textarea class='form-control' rows='2' name='comment' $dis>" . $e($f['comment']) . "</textarea></div>";
        echo "<div class='col-12 small text-secondary'>Regras: mínimo de 5 dias corridos por período, até 30 dias somando o abono (até 10 dias vendidos).</div>";
        echo "</div>";
        if ($edit) {
            echo "<div class='card-footer'>" . ($new
                ? "<input type='hidden' name='entities_id' value='" . (int) ($_SESSION['glpiactive_entity'] ?? 0) . "'><button class='btn btn-primary' name='add' value='1'><i class='ti ti-send me-1'></i>Enviar pedido ao gestor</button>"
                : "<input type='hidden' name='id' value='" . (int) $this->getID() . "'><button class='btn btn-primary' name='update' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>") . "</div>";
        }
        echo Html::closeForm(false);
        echo "<script>(()=>{const i=[...document.querySelectorAll('[data-rh-days]')],o=document.getElementById('rh-days');const c=()=>{const [a,b]=i.map(x=>x.value);if(a&&b&&b>=a){o.textContent=Math.round((new Date(b)-new Date(a))/864e5)+1;}else{o.textContent='—';}};i.forEach(x=>x.addEventListener('change',c));})();</script>";
        echo "</div><div class='col-xl-5'>";
        if (!$new) {
            $conflicts = self::conflicts($f);
            if ($conflicts && (int) $f['status'] === self::PENDING) {
                echo "<div class='alert alert-warning'><i class='ti ti-alert-triangle me-1'></i><b>Equipe no mesmo período:</b> " . $e(implode(', ', $conflicts)) . "</div>";
            }
            echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-git-pull-request me-2'></i>Aprovação</h3></div><div class='card-body'>";
            echo "<div class='rh-timeline rh-timeline-sm'>";
            $decision = array_values(array_filter(Aprovacoes::forItem(self::class, $this->getID()), fn ($d) => $d['step'] === 'manager'))[0] ?? null;
            $state = (int) $f['status'] === self::APPROVED ? 'done' : ((int) $f['status'] === self::REJECTED ? 'rejected' : ((int) $f['status'] === self::PENDING ? 'current' : 'todo'));
            echo "<div class='rh-step done'><div class='rh-step-dot'><i class='ti ti-send'></i></div><div class='rh-step-label'>Pedido</div><div class='rh-step-detail'>" . Html::convDateTime($f['date_creation']) . "</div></div>";
            echo "<div class='rh-step $state'><div class='rh-step-dot'><i class='ti ti-user-check'></i></div><div class='rh-step-label'>Gestor</div><div class='rh-step-detail'>"
                . ($decision ? $e(getUserName((int) $decision['users_id'])) . ' · ' . Html::convDateTime($decision['date']) . ($decision['comment'] ? "<div class='rh-step-comment'>“" . $e($decision['comment']) . "”</div>" : '') : $e(getUserName((int) $f['users_id_manager']) ?: 'sem gestor definido')) . "</div></div>";
            echo "</div>";
            if ($this->canApprove()) {
                echo "<form method='post' action='$a' class='mt-3'><input type='hidden' name='id' value='" . (int) $this->getID() . "'><textarea class='form-control mb-2' rows='2' name='comment' placeholder='Comentário (obrigatório para reprovar)'></textarea>"
                    . "<div class='d-flex gap-2'><button class='btn btn-success' name='workflow' value='approve'><i class='ti ti-check me-1'></i>Aprovar</button><button class='btn btn-outline-danger' name='workflow' value='reject'><i class='ti ti-x me-1'></i>Reprovar</button></div>" . Html::closeForm(false);
            }
            if (in_array((int) $f['status'], [self::PENDING, self::APPROVED], true) && ((int) $f['users_id'] === (int) Session::getLoginUserID() || $rh)) {
                echo "<form method='post' action='$a' class='mt-3'><input type='hidden' name='id' value='" . (int) $this->getID() . "'><button class='btn btn-sm btn-ghost-secondary' name='workflow' value='cancel' onclick=\"return confirm('Cancelar este pedido de férias?')\"><i class='ti ti-ban me-1'></i>Cancelar pedido</button>" . Html::closeForm(false);
            }
            echo "</div></div>";
        }
        echo "<a class='btn btn-outline-primary w-100' href='" . $e(Menu::url('/front/calendario.php')) . "'><i class='ti ti-calendar-event me-1'></i>Ver o calendário de férias da equipe</a>";
        echo "</div></div></div>";
        return true;
    }

    /** Férias aprovadas e aguardando no intervalo, agrupadas por colaborador, e os nomes em ordem. */
    private static function periodRows(string $from, string $to): array
    {
        global $DB;
        $me = (int) Session::getLoginUserID();
        $where = ['is_deleted' => 0, 'status' => [self::PENDING, self::APPROVED], 'date_begin' => ['<=', $to], 'date_end' => ['>=', $from]];
        if (!Session::haveRight(self::$rightname, self::READALL)) {
            $where[] = ['OR' => ['users_id' => $me, 'users_id_manager' => $me]];
        }
        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'date_begin']) as $r) {
            $rows[(int) $r['users_id']][] = $r;
        }
        $names = [];
        foreach (array_keys($rows) as $uid) {
            $names[$uid] = (string) getUserName($uid);
        }
        asort($names);
        return [$rows, $names];
    }

    /** Botões Mês | Ano e o pedido de férias, à direita do título. */
    private static function calendarNav(string $nav, string $mode, string $month, int $year): string
    {
        $tabs = "<div class='btn-group'>"
            . "<a class='btn " . ($mode === 'mes' ? 'btn-primary' : 'btn-outline-secondary') . "' href='?month=$month'><i class='ti ti-calendar-month me-1'></i>Mês</a>"
            . "<a class='btn " . ($mode === 'ano' ? 'btn-primary' : 'btn-outline-secondary') . "' href='?view=ano&year=$year'><i class='ti ti-calendar-stats me-1'></i>Ano</a></div>";
        $nav = $tabs . "<div class='d-flex align-items-center gap-2 ms-2'>$nav</div>";
        if (self::canCreate()) {
            $nav .= Menu::button('Pedir férias', self::getFormURL());
        }
        return $nav;
    }

    private static function barTitle(array $r): string
    {
        return date('d/m/Y', strtotime($r['date_begin'])) . ' a ' . date('d/m/Y', strtotime($r['date_end'])) . ' · ' . (int) $r['days'] . ' dias · ' . self::STATUSES[(int) $r['status']][0];
    }

    private static function legend(string $extra): string
    {
        return "<div class='card-footer small text-secondary d-flex flex-wrap gap-3'><span><i class='rh-legend ok'></i>Aprovada</span><span><i class='rh-legend wait'></i>Aguardando gestor</span><span>$extra</span></div>";
    }

    /** Calendário do mês: uma linha por colaborador com férias no mês. */
    public static function calendar(string $month): void
    {
        $first = strtotime($month . '-01');
        if (!$first) {
            $first = strtotime(date('Y-m-01'));
        }
        $last = strtotime(date('Y-m-t', $first));
        [$rows, $names] = self::periodRows(date('Y-m-d', $first), date('Y-m-d', $last));
        $days = (int) date('t', $first);
        $e = [Menu::class, 'e'];
        $prev = date('Y-m', strtotime('-1 month', $first));
        $next = date('Y-m', strtotime('+1 month', $first));

        $nav = "<a class='btn btn-outline-secondary' href='?month=$prev' title='Mês anterior'><i class='ti ti-chevron-left'></i></a>"
            . "<div class='fs-3 fw-bold text-capitalize px-2'>" . self::MONTHS[(int) date('n', $first) - 1] . ' ' . date('Y', $first) . "</div>"
            . "<a class='btn btn-outline-secondary' href='?month=$next' title='Próximo mês'><i class='ti ti-chevron-right'></i></a><a class='btn btn-ghost-secondary' href='?month=" . date('Y-m') . "'>Hoje</a>";
        echo "<div class='rh'>" . Menu::title('Calendário de férias', 'calendario', self::calendarNav($nav, 'mes', date('Y-m', $first), (int) date('Y', $first)));
        echo "<div class='card'><div class='rh-cal' style='--days:$days'>";
        echo "<div class='rh-cal-row rh-cal-head'><div class='rh-cal-name'>Colaborador</div>";
        $wd = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
        for ($d = 1; $d <= $days; $d++) {
            $ts = strtotime(date('Y-m-', $first) . sprintf('%02d', $d));
            $we = (int) date('w', $ts) % 6 === 0 ? ' we' : '';
            $today = date('Y-m-d', $ts) === date('Y-m-d') ? ' today' : '';
            echo "<div class='rh-cal-day$we$today' style='grid-column:" . ($d + 1) . "'><small>" . $wd[(int) date('w', $ts)] . "</small>$d</div>";
        }
        echo "</div>";
        if (!$names) {
            echo "<div class='p-4 text-secondary'>Ninguém de férias neste mês.</div>";
        }
        foreach ($names as $uid => $name) {
            echo "<div class='rh-cal-row'><div class='rh-cal-name'><span class='rh-avatar rh-avatar-sm'>" . $e(Menu::initials($name)) . "</span>" . $e($name) . "</div>";
            // cada célula na sua coluna: a barra por cima não empurra os fins de semana
            for ($d = 1; $d <= $days; $d++) {
                $ts = strtotime(date('Y-m-', $first) . sprintf('%02d', $d));
                $we = (int) date('w', $ts) % 6 === 0 ? ' we' : '';
                echo "<div class='rh-cal-cell$we' style='grid-column:" . ($d + 1) . "'></div>";
            }
            foreach ($rows[$uid] as $r) {
                $s = max(1, (int) date('j', max($first, strtotime($r['date_begin']))));
                $eDay = min($days, (int) date('j', min($last, strtotime($r['date_end']))));
                $cls = (int) $r['status'] === self::APPROVED ? 'ok' : 'wait';
                echo "<a class='rh-cal-bar $cls' href='" . $e(self::getFormURLWithID((int) $r['id'])) . "' style='grid-column:" . ($s + 1) . ' / ' . ($eDay + 2) . "' title='" . $e(self::barTitle($r)) . "'>"
                    . (int) $r['days'] . "d</a>";
            }
            echo "</div>";
        }
        echo "</div>" . self::legend('Clique na barra para abrir o pedido.') . "</div></div>";
    }

    public const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /**
     * Visão do ano: uma coluna por dia (sem largura mínima), meses alternados no fundo,
     * a linha de hoje e um resumo por mês de quantas pessoas saem e quantos dias somam.
     */
    public static function calendarYear(int $year): void
    {
        $first = strtotime("$year-01-01");
        $last = strtotime("$year-12-31");
        [$rows, $names] = self::periodRows("$year-01-01", "$year-12-31");
        $days = (int) date('z', $last) + 1;
        $e = [Menu::class, 'e'];
        $doy = fn (int $ts) => (int) date('z', $ts) + 1;

        // resumo por mês: dias de férias que caem no mês e pessoas distintas
        $sum = array_fill(1, 12, ['people' => [], 'days' => 0, 'wait' => 0]);
        foreach ($rows as $uid => $list) {
            foreach ($list as $r) {
                for ($t = max($first, strtotime($r['date_begin'])), $end = min($last, strtotime($r['date_end'])); $t <= $end; $t = strtotime('+1 day', $t)) {
                    $m = (int) date('n', $t);
                    $sum[$m]['people'][$uid] = true;
                    $sum[$m]['days']++;
                    if ((int) $r['status'] !== self::APPROVED) {
                        $sum[$m]['wait']++;
                    }
                }
            }
        }
        $nav = "<a class='btn btn-outline-secondary' href='?view=ano&year=" . ($year - 1) . "' title='Ano anterior'><i class='ti ti-chevron-left'></i></a>"
            . "<div class='fs-3 fw-bold px-2'>$year</div>"
            . "<a class='btn btn-outline-secondary' href='?view=ano&year=" . ($year + 1) . "' title='Próximo ano'><i class='ti ti-chevron-right'></i></a><a class='btn btn-ghost-secondary' href='?view=ano&year=" . date('Y') . "'>Este ano</a>";
        $curMonth = (int) date('Y') === $year ? date('Y-m') : "$year-01";
        echo "<div class='rh'>" . Menu::title('Calendário de férias', 'calendario', self::calendarNav($nav, 'ano', $curMonth, $year));

        // cartões do topo
        $people = count($names);
        $total = array_sum(array_column($sum, 'days'));
        $counts = array_map(fn ($x) => count($x['people']), $sum);
        $peak = $total ? (int) array_search(max($counts), $counts, true) : 0;
        echo "<div class='row g-3 mb-3'>";
        foreach ([
            ['ti-users', 'Colaboradores com férias', $people, 'no ano'],
            ['ti-calendar-time', 'Dias de férias', $total, 'dentro de ' . $year],
            ['ti-hourglass', 'Aguardando gestor', array_sum(array_column($sum, 'wait')), 'dias ainda não aprovados'],
            ['ti-flame', 'Mês mais cheio', $peak ? ucfirst(self::MONTHS[$peak - 1]) : '—', $peak ? count($sum[$peak]['people']) . ' pessoa(s) fora' : 'sem férias'],
        ] as [$icon, $label, $value, $hint]) {
            echo "<div class='col-6 col-lg-3'><div class='card card-sm'><div class='card-body d-flex align-items-center gap-3'><span class='avatar bg-primary-lt'><i class='ti $icon'></i></span>"
                . "<div><div class='text-secondary small'>$label</div><div class='fs-2 fw-bold lh-1'>" . $e($value) . "</div><div class='small text-secondary'>" . $e($hint) . "</div></div></div></div></div>";
        }
        echo "</div>";

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $a = strtotime(sprintf('%d-%02d-01', $year, $m));
            $months[$m] = [$doy($a), $doy(strtotime(date('Y-m-t', $a)))];
        }
        $todayCol = date('Y') == $year ? $doy(time()) : 0;
        $bg = function () use ($months, $todayCol): string {
            $h = '';
            foreach ($months as $m => [$s, $f]) {
                $h .= "<div class='rh-cal-cell" . ($m % 2 ? '' : ' alt') . "' style='grid-column:" . ($s + 1) . ' / ' . ($f + 2) . "'></div>";
            }
            return $h . ($todayCol ? "<div class='rh-cal-now' style='grid-column:" . ($todayCol + 1) . "' title='Hoje'></div>" : '');
        };

        echo "<div class='card'><div class='rh-cal rh-cal-year' style='--days:$days'>";
        echo "<div class='rh-cal-row rh-cal-head'><div class='rh-cal-name'>Colaborador</div>";
        foreach ($months as $m => [$s, $f]) {
            $cur = date('Y-m') === sprintf('%d-%02d', $year, $m) ? ' today' : '';
            echo "<a class='rh-cal-month$cur" . ($m % 2 ? '' : ' alt') . "' style='grid-column:" . ($s + 1) . ' / ' . ($f + 2) . "' href='?month=" . sprintf('%d-%02d', $year, $m) . "' title='Abrir " . self::MONTHS[$m - 1] . "'>"
                . ucfirst(mb_substr(self::MONTHS[$m - 1], 0, 3)) . "</a>";
        }
        echo "</div><div class='rh-cal-row rh-cal-sum'><div class='rh-cal-name text-secondary small'>Pessoas fora · dias</div>";
        foreach ($months as $m => [$s, $f]) {
            $n = count($sum[$m]['people']);
            $heat = $people ? min(1, $n / max(1, $people)) : 0;
            echo "<div class='rh-cal-msum" . ($m % 2 ? '' : ' alt') . "' style='grid-column:" . ($s + 1) . ' / ' . ($f + 2) . ";--heat:" . round($heat, 2) . "' title='" . $e(ucfirst(self::MONTHS[$m - 1]) . ": $n pessoa(s), {$sum[$m]['days']} dia(s) de férias") . "'>"
                . ($n ? "<b>$n</b><small>{$sum[$m]['days']}d</small>" : "<small>—</small>") . "</div>";
        }
        echo "</div>";
        if (!$names) {
            echo "<div class='p-4 text-secondary'>Ninguém de férias em $year.</div>";
        }
        foreach ($names as $uid => $name) {
            $tot = 0;
            foreach ($rows[$uid] as $r) {
                $tot += (int) $r['days'];
            }
            echo "<div class='rh-cal-row'><div class='rh-cal-name'><span class='rh-avatar rh-avatar-sm'>" . $e(Menu::initials($name)) . "</span><span class='text-truncate'>" . $e($name) . "</span><span class='ms-auto ps-2 small text-secondary'>{$tot}d</span></div>" . $bg();
            foreach ($rows[$uid] as $r) {
                $s = $doy(max($first, strtotime($r['date_begin'])));
                $f = $doy(min($last, strtotime($r['date_end'])));
                $cls = (int) $r['status'] === self::APPROVED ? 'ok' : 'wait';
                echo "<a class='rh-cal-bar $cls' href='" . $e(self::getFormURLWithID((int) $r['id'])) . "' style='grid-column:" . ($s + 1) . ' / ' . ($f + 2) . "' title='" . $e(self::barTitle($r)) . "'>"
                    . ($f - $s >= 7 ? (int) $r['days'] . 'd' : '') . "</a>";
            }
            echo "</div>";
        }
        echo "</div>" . self::legend('A linha azul marca hoje. Passe o mouse numa barra para ver o período e clique para abrir o pedido; clique no mês para ver os dias.') . "</div></div>";
    }
}
