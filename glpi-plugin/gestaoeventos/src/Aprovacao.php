<?php

namespace GlpiPlugin\Gestaoeventos;

use Config;
use Group;
use Html;
use RuntimeException;
use Session;
use User;

/**
 * Aprovações por alçada. Um pedido (batch) vira uma ou mais linhas, uma por aprovador;
 * linhas da mesma etapa aprovam em paralelo, etapas diferentes em sequência. Toda decisão
 * guarda quem, quando, a alçada e a justificativa, e é conferida no servidor.
 */
class Aprovacao
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_aprovacoes';

    public static string $rightname = 'plugin_gestaoeventos';

    public const KINDS = [
        'entrada'     => 'Entrada do evento (participar e investir)',
        'orcamento'   => 'Orçamento base',
        'compra'      => 'Compra / contratação',
        'mudanca'     => 'Mudança de escopo, prazo ou custo',
        'aditivo'     => 'Aditivo de custo ou escopo',
        'excecao'     => 'Exceção (antecedência ou premissa)',
        'emergencial' => 'Custo emergencial',
        'revisao'     => 'Revisão por estouro de tolerância',
    ];

    public const STATUSES = [
        'waiting'    => ['Aguarda etapa anterior', '#94a3b8', 'ti-hourglass'],
        'pending'    => ['Pendente', '#f59e0b', 'ti-clock'],
        'approved'   => ['Aprovada', '#22c55e', 'ti-circle-check'],
        'rejected'   => ['Rejeitada', '#ef4444', 'ti-circle-x'],
        'postponed'  => ['Postergada', '#8b5cf6', 'ti-player-pause'],
        'canceled'   => ['Cancelada', '#94a3b8', 'ti-ban'],
        'superseded' => ['Substituída (versão anterior)', '#64748b', 'ti-history'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Aprovações' : 'Aprovação';
    }

    public static function badge(string $status): string
    {
        [$label, $color, $icon] = self::STATUSES[$status] ?? [$status, '#64748b', 'ti-help'];
        return "<span class='badge ge-badge' style='--c:$color'><i class='ti $icon me-1'></i>" . Menu::e($label) . "</span>";
    }

    // ------------------------------------------------------------------ consultas

    public static function forEvento(int $eventoId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $eventoId], 'ORDER' => ['batch DESC', 'step', 'id']]), false);
    }

    public static function row(int $id): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current() ?: null;
    }

    /** approved | pending | rejected | postponed | canceled de um pedido, a partir das linhas. */
    public static function batchState(int $batch): string
    {
        global $DB;
        $st = array_column(iterator_to_array($DB->request(['SELECT' => 'status', 'FROM' => self::getTable(), 'WHERE' => ['batch' => $batch]]), false), 'status');
        return match (true) {
            !$st => 'none',
            in_array('rejected', $st, true) => 'rejected',
            in_array('postponed', $st, true) => 'postponed',
            count(array_diff($st, ['approved'])) === 0 => 'approved',
            in_array('pending', $st, true) || in_array('waiting', $st, true) => 'pending',
            default => 'canceled',
        };
    }

    /** Estado do pedido mais recente de um tipo (e registro): none quando nunca houve. */
    public static function stateFor(int $eventoId, string $kind, int $registroId = 0): string
    {
        global $DB;
        $last = $DB->request(['SELECT' => ['batch'], 'FROM' => self::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $eventoId, 'kind' => $kind, 'registros_id' => $registroId, 'NOT' => ['status' => 'superseded']],
            'ORDER' => 'batch DESC', 'LIMIT' => 1])->current();
        return $last ? self::batchState((int) $last['batch']) : 'none';
    }

    /** Quem pode decidir uma linha agora (ids de usuário), incluindo o delegado válido. */
    public static function approverIds(array $row): array
    {
        $ids = (int) $row['users_id_approver'] ? [(int) $row['users_id_approver']] : ((int) $row['groups_id'] ? Avisos::members((int) $row['groups_id']) : Avisos::approvers());
        if ((int) $row['delegate_users_id'] && $row['delegate_until'] && $row['delegate_until'] >= date('Y-m-d')) {
            $ids[] = (int) $row['delegate_users_id'];
        }
        return array_values(array_unique($ids));
    }

    public static function isOverdue(array $row): bool
    {
        return $row['status'] === 'pending' && $row['date_due'] && $row['date_due'] < $_SESSION['glpi_currenttime'];
    }

    /** Por que o usuário atual não pode decidir (null = pode). */
    public static function whyNot(array $row, Evento $ev): ?string
    {
        $me = (int) Session::getLoginUserID();
        if ($row['status'] !== 'pending') {
            return 'Esta aprovação não está pendente.';
        }
        if (!Session::haveRight(Evento::$rightname, READ) || !$ev->canViewItem()) {
            return 'Você não tem acesso a este evento.';
        }
        if (!Acesso::canView('decidir')) {
            return 'Seu perfil não tem o direito de decidir aprovações.';
        }
        if ($me === (int) $row['users_id_requested'] && !(Config::getConfigurationValues('plugin:gestaoeventos', ['allow_self_approval'])['allow_self_approval'] ?? '0')) {
            return 'Quem solicitou não aprova o próprio pedido.';
        }
        if (!in_array($me, self::approverIds($row), true)) {
            return 'Você não está na alçada desta aprovação.';
        }
        return null;
    }

    // ------------------------------------------------------------------ pedido

    /**
     * Abre um pedido de aprovação conforme as alçadas. Devolve o número do pedido (batch).
     * Se já existe um pendente do mesmo tipo e registro, devolve o dele.
     */
    public static function request(Evento $ev, string $kind, string $title, float $amount = 0, int $registroId = 0, string $reason = ''): int
    {
        global $DB;
        if (!isset(self::KINDS[$kind])) {
            throw new RuntimeException('Tipo de aprovação inválido.');
        }
        $evId = $ev->getID();
        $last = $DB->request(['SELECT' => ['batch'], 'FROM' => self::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $evId, 'kind' => $kind, 'registros_id' => $registroId, 'NOT' => ['status' => 'superseded']],
            'ORDER' => 'batch DESC', 'LIMIT' => 1])->current();
        if ($last && self::batchState((int) $last['batch']) === 'pending') {
            return (int) $last['batch'];
        }
        $now = $_SESSION['glpi_currenttime'];
        $sla = (int) (Config::getConfigurationValues('plugin:gestaoeventos', ['approval_sla_days'])['approval_sla_days'] ?? 3);
        $steps = Alcada::steps($kind, $amount, (string) $ev->fields['event_type']);
        $first = (int) min(array_map(fn ($s) => (int) $s['step'], $steps));
        $batch = 0;
        $version = in_array($kind, ['orcamento', 'revisao'], true) ? (int) $ev->fields['budget_version'] : 1;
        foreach ($steps as $s) {
            $DB->insert(self::getTable(), [
                'plugin_gestaoeventos_eventos_id' => $evId, 'batch' => $batch, 'kind' => $kind, 'registros_id' => $registroId, 'title' => mb_substr($title, 0, 255),
                'amount' => $amount, 'step' => (int) $s['step'], 'label' => mb_substr((string) $s['name'], 0, 100), 'groups_id' => (int) ($s['groups_id'] ?? 0), 'users_id_approver' => (int) ($s['users_id'] ?? 0),
                'status' => (int) $s['step'] === $first ? 'pending' : 'waiting', 'users_id_requested' => (int) Session::getLoginUserID(), 'request_reason' => $reason, 'version' => $version,
                'date_requested' => $now, 'date_due' => $sla > 0 ? date('Y-m-d H:i:s', strtotime($now) + $sla * 86400) : null,
            ]);
            $rowId = (int) $DB->insertId();
            $batch = $batch ?: $rowId;
            $DB->update(self::getTable(), ['batch' => $batch], ['id' => $rowId]);
        }
        Historico::record($evId, $ev->fields['current_phase'], 'approval', 'Aprovação solicitada: ' . self::KINDS[$kind] . ' — ' . $title . ($amount > 0 ? ' (' . Menu::money($amount) . ')' : ''));
        if ($kind === 'entrada') {
            $ev->update(['id' => $evId, '_workflow' => true, 'approval_status' => 'em_aprovacao']);
            $ev->getFromDB($evId);
        }
        self::notifyPending($ev, $batch);
        return $batch;
    }

    /** Avisa os aprovadores das linhas pendentes do pedido. */
    private static function notifyPending(Evento $ev, int $batch): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['batch' => $batch, 'status' => 'pending']]) as $r) {
            $ids = array_diff(self::approverIds($r), [(int) $r['users_id_requested']]);
            $due = $r['date_due'] ? ' Prazo: ' . Menu::date($r['date_due'], true) . '.' : '';
            Avisos::send($ev, 'aprovacao_pendente', $ids, 'Aprovação pendente: ' . self::KINDS[$r['kind']], $r['title'] . ($r['amount'] > 0 ? ' — ' . Menu::money((float) $r['amount']) : '') . ". Alçada: {$r['label']}.$due", 'warning',
                ['Tipo' => self::KINDS[$r['kind']], 'Alçada' => (string) $r['label']] + ($r['amount'] > 0 ? ['Valor' => Menu::money((float) $r['amount'])] : []));
        }
    }

    // ------------------------------------------------------------------ decisão

    public static function decide(Evento $ev, int $id, string $decision, string $justification): string
    {
        global $DB;
        $row = self::row($id);
        if (!$row || (int) $row['plugin_gestaoeventos_eventos_id'] !== $ev->getID()) {
            throw new RuntimeException('Aprovação não encontrada neste evento.');
        }
        if ($why = self::whyNot($row, $ev)) {
            Historico::record($ev->getID(), $ev->fields['current_phase'], 'blocked', 'Decisão de aprovação recusada para ' . getUserName((int) Session::getLoginUserID()) . ": $why");
            throw new RuntimeException($why);
        }
        $justification = trim($justification);
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'postpone' => 'postponed'];
        if (!isset($map[$decision])) {
            throw new RuntimeException('Decisão inválida.');
        }
        if ($decision !== 'approve' && $justification === '') {
            throw new RuntimeException('Informe a justificativa para rejeitar ou postergar.');
        }
        $now = $_SESSION['glpi_currenttime'];
        $DB->update(self::getTable(), ['status' => $map[$decision], 'users_id_decided' => (int) Session::getLoginUserID(), 'justification' => $justification, 'date_decision' => $now], ['id' => $id]);
        Historico::record($ev->getID(), $ev->fields['current_phase'], 'approval', self::STATUSES[$map[$decision]][0] . ': ' . self::KINDS[$row['kind']] . ' — ' . $row['title'] . " (alçada {$row['label']})" . ($justification !== '' ? ". $justification" : ''));
        $batch = (int) $row['batch'];

        if ($decision !== 'approve') {
            // o pedido inteiro para: as demais linhas deixam de valer
            $DB->update(self::getTable(), ['status' => 'canceled'], ['batch' => $batch, 'status' => ['pending', 'waiting']]);
            self::resolved($ev, $row, $decision === 'reject' ? 'rejected' : 'postponed', $justification);
            return $decision === 'reject' ? 'Aprovação rejeitada.' : 'Aprovação postergada.';
        }
        // aprovou: libera a próxima etapa quando a atual fechou
        $open = countElementsInTable(self::getTable(), ['batch' => $batch, 'step' => $row['step'], 'status' => 'pending']);
        if ($open) {
            return 'Sua aprovação foi registrada. Faltam outros aprovadores desta etapa.';
        }
        $next = $DB->request(['SELECT' => ['step'], 'FROM' => self::getTable(), 'WHERE' => ['batch' => $batch, 'status' => 'waiting'], 'ORDER' => 'step', 'LIMIT' => 1])->current();
        if ($next) {
            $DB->update(self::getTable(), ['status' => 'pending', 'date_requested' => $now], ['batch' => $batch, 'step' => $next['step'], 'status' => 'waiting']);
            self::notifyPending($ev, $batch);
            return 'Aprovada nesta alçada. O pedido seguiu para a próxima.';
        }
        self::resolved($ev, $row, 'approved', $justification);
        return 'Aprovação concluída em todas as alçadas.';
    }

    /** Consequências do pedido resolvido (aprovado, rejeitado ou postergado) no evento e no registro de origem. */
    private static function resolved(Evento $ev, array $row, string $outcome, string $why): void
    {
        global $DB;
        $evId = $ev->getID();
        $kind = $row['kind'];
        $title = self::KINDS[$kind] . ': ' . $row['title'];
        $label = ['approved' => 'aprovada', 'rejected' => 'rejeitada', 'postponed' => 'postergada'][$outcome];
        switch ($kind) {
            case 'entrada':
                $ev->update(['id' => $evId, '_workflow' => true, 'approval_status' => ['approved' => 'aprovado', 'rejected' => 'rejeitado', 'postponed' => 'postergado'][$outcome]]);
                break;
            case 'excecao':
                $ev->update(['id' => $evId, '_workflow' => true, 'exception_status' => ['approved' => 'aprovada', 'rejected' => 'rejeitada', 'postponed' => 'pendente'][$outcome]]);
                break;
            case 'orcamento':
            case 'revisao':
                if ($outcome === 'approved') {
                    $budget = Financeiro::budgetTotal($evId);
                    if ($kind === 'revisao') {
                        $DB->update(self::getTable(), ['status' => 'superseded'], ['plugin_gestaoeventos_eventos_id' => $evId, 'kind' => ['orcamento', 'revisao'], 'status' => 'approved', 'NOT' => ['batch' => $row['batch']]]);
                        $ev->update(['id' => $evId, '_workflow' => true, 'budget_approved' => $budget, 'budget_version' => (int) $ev->fields['budget_version'] + 1, 'approval_status' => 'aprovado']);
                    } else {
                        $ev->update(['id' => $evId, '_workflow' => true, 'budget_approved' => $budget]);
                    }
                    Historico::record($evId, null, 'finance', 'Budget aprovado: ' . Menu::money($budget) . ' (versão ' . (int) $ev->fields['budget_version'] . ').');
                } elseif ($kind === 'revisao') {
                    $ev->update(['id' => $evId, '_workflow' => true, 'approval_status' => 'aprovado']);
                }
                break;
            case 'mudanca':
            case 'aditivo':
            case 'emergencial':
            case 'compra':
                if ((int) $row['registros_id']) {
                    Registro::onApproval((int) $row['registros_id'], $kind, $outcome, $ev);
                }
                break;
        }
        $ev->getFromDB($evId);
        $facts = ['Decisão' => ucfirst($label)] + ($why !== '' ? ['Justificativa' => mb_substr($why, 0, 200)] : []);
        Avisos::send($ev, 'aprovacao_' . $outcome, array_merge(Avisos::followers($ev), [(int) $row['users_id_requested']]), "Aprovação $label", "$title.", $outcome === 'approved' ? 'success' : 'danger', $facts);
        // entrada aprovada com a etapa de avaliação pronta: segue sozinho para o planejamento
        if ($kind === 'entrada' && $outcome === 'approved') {
            Fase::autoAdvance($ev, 'avaliacao', 'Aprovação formal concluída.');
        }
    }

    // ------------------------------------------------------------------ delegação

    public static function delegate(Evento $ev, int $id, int $to, string $until): void
    {
        global $DB;
        $row = self::row($id);
        if (!$row || (int) $row['plugin_gestaoeventos_eventos_id'] !== $ev->getID() || $row['status'] !== 'pending') {
            throw new RuntimeException('Aprovação não encontrada ou já decidida.');
        }
        $me = (int) Session::getLoginUserID();
        $isTitular = in_array($me, array_diff(self::approverIds($row), [(int) $row['delegate_users_id']]), true);
        if (!$isTitular && !Session::haveRight(Evento::$rightname, UPDATE)) {
            throw new RuntimeException('Só quem está na alçada (ou administra eventos) delega a aprovação.');
        }
        if (!$to || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < date('Y-m-d')) {
            throw new RuntimeException('Escolha o delegado e uma validade a partir de hoje (não há delegação retroativa).');
        }
        if ($to === (int) $row['users_id_requested']) {
            throw new RuntimeException('Quem solicitou não pode ser o delegado.');
        }
        $DB->update(self::getTable(), ['delegate_users_id' => $to, 'delegate_until' => $until], ['id' => $id]);
        Historico::record($ev->getID(), null, 'approval', 'Aprovação delegada de ' . getUserName($me) . ' para ' . getUserName($to) . ' até ' . Menu::date($until) . ': ' . $row['title']);
        Avisos::send($ev, 'aprovacao_delegada', [$to], 'Aprovação delegada a você', $row['title'] . ' — válida até ' . Menu::date($until) . '.', 'warning');
    }

    // ------------------------------------------------------------------ telas

    /** Um bloco de decisão: dados da linha, quem decide, prazo e o formulário (se o usuário pode decidir). */
    public static function decisionCard(array $r, Evento $ev, string $action, string $back = ''): string
    {
        $e = [Menu::class, 'e'];
        $overdue = self::isOverdue($r);
        $who = (int) $r['users_id_approver'] ? getUserName((int) $r['users_id_approver']) : ((int) $r['groups_id'] ? 'grupo ' . \Dropdown::getDropdownName(Group::getTable(), (int) $r['groups_id'], false, true, false, '') : 'quem tem o direito Aprovar');
        $deleg = (int) $r['delegate_users_id'] && $r['delegate_until'] >= date('Y-m-d') ? ' · delegado a <b>' . $e(getUserName((int) $r['delegate_users_id'])) . '</b> até ' . Menu::date($r['delegate_until']) : '';
        $h = "<div class='ge-appr" . ($overdue ? ' late' : '') . "'><div class='ge-appr-head'><div><b>" . $e(self::KINDS[$r['kind']] ?? $r['kind']) . "</b>"
            . "<div class='small'>" . $e($r['title']) . ((float) $r['amount'] > 0 ? ' · <b>' . Menu::money((float) $r['amount']) . '</b>' : '') . "</div></div>"
            . "<div class='text-end'>" . self::badge($r['status']) . ($overdue ? " <span class='badge bg-red-lt'>vencida</span>" : '') . "</div></div>"
            . "<div class='small text-secondary'>Alçada <b>" . $e($r['label']) . "</b> (etapa {$r['step']}) · decide: " . $who . $deleg
            . " · solicitada por " . $e(getUserName((int) $r['users_id_requested'])) . ' em ' . Menu::date($r['date_requested'], true) . ($r['date_due'] ? ' · prazo ' . Menu::date($r['date_due'], true) : '') . "</div>"
            . ($r['request_reason'] ? "<div class='ge-appr-note'>" . nl2br($e($r['request_reason'])) . "</div>" : '');
        if ($r['status'] === 'pending') {
            $why = self::whyNot($r, $ev);
            if ($why === null) {
                $h .= "<form method='post' action='" . $e($action) . "' class='row g-2 align-items-end mt-1'><input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='approval_id' value='" . (int) $r['id'] . "'><input type='hidden' name='_back' value='" . $e($back) . "'>"
                    . "<div class='col-md-6'><label class='form-label small mb-0'>Justificativa (obrigatória para rejeitar ou postergar)</label><input class='form-control form-control-sm' name='justification' maxlength='1000'></div>"
                    . "<div class='col-auto d-flex gap-1'><button class='btn btn-sm btn-success' name='approval_decide' value='approve'><i class='ti ti-check me-1'></i>Aprovar</button>"
                    . "<button class='btn btn-sm btn-outline-danger' name='approval_decide' value='reject'><i class='ti ti-x me-1'></i>Rejeitar</button>"
                    . "<button class='btn btn-sm btn-outline-secondary' name='approval_decide' value='postpone'><i class='ti ti-player-pause me-1'></i>Postergar</button></div>" . Html::closeForm(false);
                $h .= "<details class='mt-2 small'><summary class='text-secondary' style='cursor:pointer'><i class='ti ti-user-share me-1'></i>Delegar</summary><form method='post' action='" . $e($action) . "' class='row g-2 align-items-end mt-1'>"
                    . "<input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='approval_id' value='" . (int) $r['id'] . "'><input type='hidden' name='_back' value='" . $e($back) . "'>"
                    . "<div class='col-md-4'>" . User::dropdown(['name' => 'delegate_to', 'right' => 'all', 'display' => false, 'width' => '100%']) . "</div>"
                    . "<div class='col-md-3'><input type='date' class='form-control form-control-sm' name='delegate_until' min='" . date('Y-m-d') . "'></div>"
                    . "<div class='col-auto'><button class='btn btn-sm btn-outline-primary' name='approval_delegate' value='1'>Delegar até a data</button></div>" . Html::closeForm(false) . "</details>";
            } else {
                $h .= "<div class='small text-secondary mt-1'><i class='ti ti-lock me-1'></i>" . $e($why) . "</div>";
            }
        } elseif (in_array($r['status'], ['approved', 'rejected', 'postponed'], true)) {
            $h .= "<div class='small mt-1'>" . $e(getUserName((int) $r['users_id_decided'])) . ' · ' . Menu::date($r['date_decision'], true) . ($r['justification'] ? ' — ' . $e($r['justification']) : '') . "</div>";
        }
        return $h . "</div>";
    }

    /** Aba Aprovações do evento. */
    public static function tab(Evento $ev, bool $edit): void
    {
        $e = [Menu::class, 'e'];
        $action = Evento::getFormURL();
        $rows = self::forEvento($ev->getID());
        $f = $ev->fields;
        $pending = array_filter($rows, fn ($r) => $r['status'] === 'pending');
        echo "<div class='ge ge-tab'>" . Abas::banner($ev, 'avaliacao');
        echo "<div class='row g-3 mb-3'>"
            . self::tile('Situação da aprovação', Evento::APPROVAL[$f['approval_status']][0] ?? '', Evento::APPROVAL[$f['approval_status']][1] ?? '#64748b')
            . self::tile('Exceção de antecedência', ['none' => 'Não precisa', 'pendente' => 'Pendente', 'aprovada' => 'Aprovada', 'rejeitada' => 'Rejeitada'][$f['exception_status']] ?? '', $f['exception_status'] === 'aprovada' ? '#22c55e' : ($f['exception_status'] === 'none' ? '#64748b' : '#f59e0b'))
            . self::tile('Budget aprovado', (float) $f['budget_approved'] ? Menu::money((float) $f['budget_approved']) . ' (v' . (int) $f['budget_version'] . ')' : 'ainda não aprovado', (float) $f['budget_approved'] ? '#22c55e' : '#f59e0b')
            . self::tile('Pendentes agora', (string) count($pending), count($pending) ? '#f59e0b' : '#22c55e') . "</div>";

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-signature me-2'></i>Decisões pendentes</h3></div><div class='card-body'>";
        foreach ($pending as $r) {
            echo self::decisionCard($r, $ev, $action, Evento::tabUrl($ev->getID(), 'aprovacoes'));
        }
        if (!$pending) {
            echo "<div class='text-secondary small'>Nenhuma aprovação pendente neste evento.</div>";
        }
        echo "</div></div>";

        if ($edit && (int) $f['status'] === Evento::OPEN) {
            $kinds = ['entrada' => self::KINDS['entrada'], 'orcamento' => self::KINDS['orcamento'], 'excecao' => self::KINDS['excecao'], 'mudanca' => self::KINDS['mudanca'], 'revisao' => self::KINDS['revisao']];
            echo "<form method='post' action='" . $e($action) . "' class='card mb-3'><input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e(Evento::tabUrl($ev->getID(), 'aprovacoes')) . "'>"
                . "<div class='card-header'><h3 class='card-title'><i class='ti ti-send me-2'></i>Solicitar aprovação</h3></div><div class='card-body row g-2 align-items-end'>"
                . "<div class='col-md-4'><label class='form-label small mb-0'>Tipo</label><select class='form-select' name='kind'>" . Menu::options($kinds, $f['approval_status'] === 'aprovado' ? 'orcamento' : 'entrada') . "</select></div>"
                . "<div class='col-md-3'><label class='form-label small mb-0'>Valor (R$, se houver)</label><input class='form-control' name='amount' placeholder='define a alçada'></div>"
                . "<div class='col-md-5'><label class='form-label small mb-0'>Motivo / resumo para o aprovador</label><input class='form-control' name='reason' maxlength='500'></div>"
                . "<div class='col-12 small text-secondary'>Entrada: pedido formal de participar e investir (valor padrão: orçamento previsto). Orçamento base: soma do Budget de despesas da aba Financeiro. "
                . "Compras, aditivos e custos emergenciais pedem aprovação nas próprias abas.</div></div>"
                . "<div class='card-footer'><button class='btn btn-primary' name='approval_request' value='1'><i class='ti ti-send me-1'></i>Solicitar</button></div>" . Html::closeForm(false) . "</form>";
        }

        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-history me-2'></i>Histórico de decisões</h3><div class='card-actions small text-secondary'>" . count($rows) . " linha(s)</div></div>";
        echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Pedido</th><th>Tipo</th><th>Alçada</th><th class='text-end'>Valor</th><th>Situação</th><th>Decisão</th><th>Justificativa</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            echo "<tr><td class='small'>#{$r['batch']} · " . Menu::date($r['date_requested'], true) . "<br>" . $e(getUserName((int) $r['users_id_requested'])) . "</td><td class='small'>" . $e(self::KINDS[$r['kind']] ?? '') . "<div class='text-secondary'>" . $e($r['title']) . "</div></td>"
                . "<td class='small'>" . $e($r['label']) . " · etapa {$r['step']}</td><td class='text-end'>" . ((float) $r['amount'] > 0 ? Menu::money((float) $r['amount']) : '—') . "</td><td>" . self::badge($r['status']) . "</td>"
                . "<td class='small text-nowrap'>" . ($r['date_decision'] ? $e(getUserName((int) $r['users_id_decided'])) . '<br>' . Menu::date($r['date_decision'], true) : '—') . "</td><td class='small'>" . $e($r['justification']) . "</td></tr>";
        }
        if (!$rows) {
            echo "<tr><td colspan='7' class='text-secondary small'>Nenhuma aprovação solicitada ainda.</td></tr>";
        }
        echo "</tbody></table></div></div></div>";
    }

    public static function tile(string $label, string $value, string $color): string
    {
        return "<div class='col-6 col-lg-3'><div class='ge-kpi' style='--c:$color;min-height:0'><div class='ge-kpi-label'>" . Menu::e($label) . "</div><div class='fw-bold' style='font-size:1.05rem;color:$color'>" . Menu::e($value) . "</div></div></div>";
    }

    /** Página Aprovações: minhas pendências, fila por alçada e histórico recente. */
    public static function page(): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $me = (int) Session::getLoginUserID();
        $events = [];
        $load = function (int $id) use (&$events) {
            if (!isset($events[$id])) {
                $ev = new Evento();
                $events[$id] = $ev->getFromDB($id) && $ev->canViewItem() ? $ev : false;
            }
            return $events[$id];
        };
        $all = iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['status' => 'pending'], 'ORDER' => 'date_due']), false);
        $mine = [];
        $queue = [];
        foreach ($all as $r) {
            if (!($ev = $load((int) $r['plugin_gestaoeventos_eventos_id']))) {
                continue;
            }
            if (self::whyNot($r, $ev) === null) {
                $mine[] = [$r, $ev];
            } else {
                $queue[] = [$r, $ev];
            }
        }
        $history = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['status' => ['approved', 'rejected', 'postponed']], 'ORDER' => 'date_decision DESC', 'LIMIT' => 60]) as $r) {
            if ($load((int) $r['plugin_gestaoeventos_eventos_id'])) {
                $history[] = [$r, $events[(int) $r['plugin_gestaoeventos_eventos_id']]];
            }
        }
        $back = Menu::url('/front/aprovacoes.php');
        echo "<div class='ge'>" . Menu::title('Aprovações', 'aprovacoes', Menu::button('Eventos', Evento::getSearchURL(), 'ti-list', 'btn-outline-secondary'));
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-user-check me-2'></i>Minhas pendências</h3><div class='card-actions small text-secondary'>" . count($mine) . "</div></div><div class='card-body'>";
        foreach ($mine as [$r, $ev]) {
            echo "<div class='small mb-1'><a href='" . $e(Evento::getFormURLWithID($ev->getID())) . "'><b>" . $e($ev->fields['code']) . "</b> · " . $e($ev->fields['name']) . "</a></div>" . self::decisionCard($r, $ev, Evento::getFormURL(), $back);
        }
        echo $mine ? '' : "<div class='text-secondary small'>Nada esperando a sua decisão.</div>";
        echo "</div></div>";

        echo "<div class='row g-3'><div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-list-details me-2'></i>Fila por alçada</h3><div class='card-actions small text-secondary'>" . count($queue) . "</div></div>";
        echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Evento</th><th>Aprovação</th><th>Alçada</th><th>Prazo</th></tr></thead><tbody>";
        foreach ($queue as [$r, $ev]) {
            echo "<tr><td><a href='" . $e(Evento::getFormURLWithID($ev->getID())) . "'><b>" . $e($ev->fields['code']) . "</b></a> <span class='small text-secondary'>" . $e(mb_strimwidth((string) $ev->fields['name'], 0, 32, '…')) . "</span></td>"
                . "<td class='small'>" . $e(self::KINDS[$r['kind']] ?? '') . ((float) $r['amount'] > 0 ? '<br>' . Menu::money((float) $r['amount']) : '') . "</td><td class='small'>" . $e($r['label']) . "</td>"
                . "<td class='small" . (self::isOverdue($r) ? ' text-danger fw-bold' : '') . "'>" . Menu::date($r['date_due'], true) . "</td></tr>";
        }
        echo $queue ? '' : "<tr><td colspan='4' class='text-secondary small'>Fila vazia.</td></tr>";
        echo "</tbody></table></div></div></div>";

        echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-history me-2'></i>Últimas decisões</h3></div>";
        echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Evento</th><th>Aprovação</th><th>Decisão</th><th>Quem</th></tr></thead><tbody>";
        foreach ($history as [$r, $ev]) {
            echo "<tr><td><a href='" . $e(Evento::getFormURLWithID($ev->getID())) . "'><b>" . $e($ev->fields['code']) . "</b></a></td><td class='small'>" . $e(self::KINDS[$r['kind']] ?? '') . "<div class='text-secondary'>" . $e(mb_strimwidth((string) $r['title'], 0, 40, '…')) . "</div></td>"
                . "<td>" . self::badge($r['status']) . "</td><td class='small'>" . $e(getUserName((int) $r['users_id_decided'])) . "<br>" . Menu::date($r['date_decision'], true) . "</td></tr>";
        }
        echo $history ? '' : "<tr><td colspan='4' class='text-secondary small'>Nenhuma decisão registrada.</td></tr>";
        echo "</tbody></table></div></div></div></div></div>";
    }
}
