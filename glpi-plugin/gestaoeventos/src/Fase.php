<?php

namespace GlpiPlugin\Gestaoeventos;

use Config;
use Group;
use ITILSolution;
use RuntimeException;
use Session;
use Throwable;
use Ticket;

/**
 * Etapas de um evento e o fluxo entre elas: enviar à triagem, concluir (com o que a etapa
 * exige), devolver para uma etapa anterior (retrabalho), pular, cancelar e reabrir.
 * O tempo de cada etapa soma os períodos em que ela esteve ativa.
 */
class Fase
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_fases';

    public const STATUSES = [
        'pending' => ['A fazer', '#64748b'],
        'active'  => ['Em andamento', '#3b82f6'],
        'done'    => ['Concluída', '#22c55e'],
        'skipped' => ['Pulada', '#94a3b8'],
    ];

    public static function forEvento(int $eventoId): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $eventoId], 'ORDER' => 'position']) as $r) {
            $rows[$r['code']] = $r;
        }
        return $rows;
    }

    public static function active(int $eventoId): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $eventoId, 'status' => 'active'], 'LIMIT' => 1])->current() ?: null;
    }

    private static function now(): int
    {
        return strtotime($_SESSION['glpi_currenttime'] ?? 'now');
    }

    /** Tempo da etapa em segundos (os períodos anteriores mais o atual, se ativa; o relógio pode começar no início do evento). */
    public static function elapsed(array $f): int
    {
        $s = (int) $f['seconds'];
        $from = $f['date_clock'] ?: $f['date_start'];
        if ($f['status'] === 'active' && $from) {
            $s += max(0, self::now() - strtotime($from));
        }
        return $s;
    }

    public static function isLate(array $f): bool
    {
        return (int) $f['slo_days'] > 0 && self::elapsed($f) > (int) $f['slo_days'] * 86400;
    }

    /** Etapa ativa que já consumiu o percentual de alerta do SLO, sem ter estourado. */
    public static function isWarn(array $f): bool
    {
        return $f['status'] === 'active' && (int) $f['slo_days'] > 0 && (int) $f['warn_pct'] > 0 && !self::isLate($f)
            && self::elapsed($f) >= (int) $f['slo_days'] * 86400 * (int) $f['warn_pct'] / 100;
    }

    // ------------------------------------------------------------------ montagem

    /** Cria as etapas de um evento novo (nenhuma começa antes do envio à triagem). */
    public static function build(Evento $ev): void
    {
        self::sync($ev);
    }

    /** Acerta as etapas ao tipo de evento: cria as que passaram a valer e tira as pendentes que não valem mais. */
    public static function sync(Evento $ev): void
    {
        global $DB;
        $have = self::forEvento($ev->getID());
        foreach (Etapa::all() as $code => $et) {
            $applies = $et['is_active'] && Etapa::appliesTo((string) $et['applies'], (string) $ev->fields['event_type']);
            if ($applies && !isset($have[$code])) {
                $DB->insert(self::getTable(), [
                    'plugin_gestaoeventos_eventos_id' => $ev->getID(), 'code' => $code, 'position' => (int) $et['position'],
                    'status' => 'pending', 'groups_id' => (int) $et['groups_id'], 'slo_days' => (int) $et['slo_days'], 'warn_pct' => (int) $et['warn_pct'],
                ]);
            } elseif (!$applies && isset($have[$code]) && $have[$code]['status'] === 'pending') {
                $DB->delete(self::getTable(), ['id' => $have[$code]['id']]);
            }
        }
    }

    // ------------------------------------------------------------------ permissões

    /** Executa a etapa: quem edita eventos, o grupo da etapa ou, na primeira, o solicitante e o dono. */
    public static function canAct(Evento $ev, ?array $f): bool
    {
        if (Session::haveRight(Evento::$rightname, UPDATE)) {
            return true;
        }
        if (!$f) {
            return false;
        }
        $me = (int) Session::getLoginUserID();
        if (in_array($me, Avisos::members((int) $f['groups_id']), true)) {
            return true;
        }
        return $f['code'] === 'briefing' && in_array($me, [(int) $ev->fields['users_id_requester'], (int) $ev->fields['users_id_owner']], true)
            || in_array($f['code'], ['planejamento', 'prontidao', 'encerramento'], true) && $me === (int) $ev->fields['users_id_gp'];
    }

    /** Faz a triagem: quem edita eventos ou o grupo da etapa de briefing (Marketing). */
    public static function canTriage(array $f): bool
    {
        return Session::haveRight(Evento::$rightname, UPDATE) || in_array((int) Session::getLoginUserID(), Avisos::members((int) $f['groups_id']), true);
    }

    // ------------------------------------------------------------------ o que falta para concluir

    public static function missing(Evento $ev, array $f): array
    {
        $req = (string) (Etapa::all()[$f['code']]['requirement'] ?? '');
        $fl = $ev->fields;
        $id = $ev->getID();
        $out = [];
        $n = fn (string $kind, array $where = []) => Registro::countKind($id, $kind, $where);
        switch ($req) {
            case 'briefing_ok':
                $out = Evento::briefingMissing($fl);
                if ($fl['triage_status'] !== 'aceito') {
                    $out[] = 'A triagem do Marketing ainda não aceitou o briefing (aceitar ou devolver ao solicitante).';
                }
                if ($lead = Evento::leadInfo($fl)) {
                    if ($lead['short'] && $lead['mode'] !== 'warn' && $fl['exception_status'] !== 'aprovada') {
                        $out[] = "Antecedência de {$lead['days']} dia(s), abaixo do mínimo de {$lead['min']}: a exceção ainda não foi aprovada (aba Aprovações).";
                    }
                }
                break;
            case 'aprovado':
                if (trim((string) $fl['cc_number']) === '') {
                    $out[] = 'Informe o centro de custo (dados do evento).';
                }
                $st = Aprovacao::stateFor($id, 'entrada');
                $out[] = match ($st) {
                    'approved' => null,
                    'pending' => 'A aprovação de entrada está pendente nas alçadas.',
                    'rejected' => 'A aprovação de entrada foi rejeitada: devolva o evento para ajustar e solicite de novo.',
                    'postponed' => 'A aprovação de entrada foi postergada.',
                    default => 'Solicite a aprovação formal de entrada (aba Aprovações).',
                };
                $out = array_values(array_filter($out));
                break;
            case 'planejamento_ok':
                if (!(int) $fl['users_id_gp']) {
                    $out[] = 'Designe o gerente do projeto (GP) nos dados do evento.';
                }
                if (!(int) $fl['projects_id']) {
                    $out[] = 'Vincule ou crie o projeto do evento no GLPI (aba Planejamento).';
                }
                if (!$n('kickoff')) {
                    $out[] = 'Registre o kickoff com a ata (aba Planejamento).';
                }
                if (!$n('marco')) {
                    $out[] = 'Defina os marcos do cronograma (aba Planejamento).';
                }
                if (!$n('raci')) {
                    $out[] = 'Defina a matriz RACI do evento (aba Planejamento).';
                }
                if ((float) $fl['budget_approved'] <= 0) {
                    $out[] = 'O orçamento base precisa estar aprovado (aba Financeiro).';
                }
                break;
            case 'compras_ok':
                if ($c = $n('compra', ['status' => ['requisicao', 'cotacao', 'negociacao', 'aprovacao']])) {
                    $out[] = "$c compra(s) ainda em requisição, cotação, negociação ou aprovação (aba Compras).";
                }
                if ($c = $n('contrato', ['status' => ['minuta', 'juridico']])) {
                    $out[] = "$c contrato(s) sem assinatura (aba Compras).";
                }
                if ($c = countElementsInTable(Aprovacao::getTable(), ['plugin_gestaoeventos_eventos_id' => $id, 'kind' => ['compra', 'aditivo'], 'status' => 'pending'])) {
                    $out[] = "$c aprovação(ões) de compra ou aditivo pendente(s).";
                }
                break;
            case 'producao_ok':
                if ($c = $n('checklist', ['is_critical' => 1, 'status' => 'a_fazer', 'phase' => ['compras', 'producao']])) {
                    $out[] = "$c item(ns) crítico(s) do checklist de compras e produção pendente(s) (aba Checklists).";
                }
                if ($c = $n('material', ['status' => 'divergente']) + $n('recebimento', ['status' => 'divergente'])) {
                    $out[] = "$c entrega(s) com divergência a resolver (aba Logística / Compras).";
                }
                if ($c = $n('pendencia', ['is_critical' => 1, 'status' => 'aberta'])) {
                    $out[] = "$c pendência(s) crítica(s) aberta(s) (aba Planejamento).";
                }
                if ($c = $n('peca', ['status' => 'em_aprovacao'])) {
                    $out[] = "$c peça(s) de comunicação aguardando aprovação do Marketing.";
                }
                break;
            case 'prontidao_ok':
                if (!$fl['ready_gp_date']) {
                    $out[] = 'Falta o aceite de prontidão do GP.';
                }
                if (!$fl['ready_mkt_date']) {
                    $out[] = 'Falta o aceite de prontidão do Marketing.';
                }
                if ($c = $n('checklist', ['is_critical' => 1, 'status' => 'a_fazer'])) {
                    $out[] = "$c item(ns) crítico(s) de checklist pendente(s) (aba Checklists).";
                }
                if ($c = $n('pendencia', ['is_critical' => 1, 'status' => 'aberta'])) {
                    $out[] = "$c pendência(s) crítica(s) aberta(s).";
                }
                if (!$n('contingencia')) {
                    $out[] = 'Registre o plano de contingência e os contatos (aba Logística).';
                }
                break;
            case 'execucao_ok':
                if ($fl['date_finish'] && $fl['date_finish'] > date('Y-m-d', self::now())) {
                    $out[] = 'O evento só termina em ' . Menu::date($fl['date_finish']) . '.';
                }
                if ($c = $n('ocorrencia', ['category' => 'critica', 'status' => 'aberta'])) {
                    $out[] = "$c ocorrência(s) crítica(s) aberta(s) (aba Execução).";
                }
                if ($c = countElementsInTable(Aprovacao::getTable(), ['plugin_gestaoeventos_eventos_id' => $id, 'kind' => 'emergencial', 'status' => 'pending'])) {
                    $out[] = "$c custo(s) emergencial(is) aguardando decisão.";
                }
                break;
            case 'dod':
                $out = Encerramento::dodMissing($ev);
                break;
        }
        return $out;
    }

    // ------------------------------------------------------------------ fluxo

    public static function workflow(Evento $ev, string $action, array $in): string
    {
        global $DB;
        $comment = trim((string) ($in['comment'] ?? ''));
        $f = self::active($ev->getID());
        $me = (int) Session::getLoginUserID();
        switch ($action) {
            case 'submit':
                return self::submit($ev, trim((string) ($in['exception_reason'] ?? '')));

            case 'triage_accept':
            case 'triage_return':
                if (!$f || $f['code'] !== 'briefing' || !self::canTriage($f)) {
                    throw new RuntimeException('Só o Marketing (ou quem edita eventos) faz a triagem.');
                }
                if ($action === 'triage_return') {
                    if ($comment === '') {
                        throw new RuntimeException('Informe o motivo da devolução ao solicitante.');
                    }
                    self::leave($f, 'pending', "Triagem devolvida ao solicitante: $comment");
                    $ev->update(['id' => $ev->getID(), '_workflow' => true, 'triage_status' => 'devolvido', 'triage_comment' => $comment, 'users_id_triage' => $me, 'date_triage' => $_SESSION['glpi_currenttime'],
                        'is_draft' => 1, 'approval_status' => 'rascunho', 'current_phase' => null, 'returns' => (int) $ev->fields['returns'] + 1]);
                    $DB->update(self::getTable(), ['returns' => (int) $f['returns'] + 1], ['id' => $f['id']]);
                    $ev->getFromDB($ev->getID());
                    Historico::record($ev->getID(), 'briefing', 'triage', "Triagem devolvida ao solicitante: $comment");
                    Avisos::send($ev, 'triagem_devolvida', array_merge([(int) $ev->fields['users_id_requester']], [(int) $ev->fields['users_id_owner']]), 'Briefing devolvido pelo Marketing', "Ajuste e reenvie. Motivo: $comment", 'danger', ['Motivo' => $comment]);
                    return 'Briefing devolvido ao solicitante.';
                }
                $ev->update(['id' => $ev->getID(), '_workflow' => true, 'triage_status' => 'aceito', 'triage_comment' => $comment, 'users_id_triage' => $me, 'date_triage' => $_SESSION['glpi_currenttime']]);
                $ev->getFromDB($ev->getID());
                Historico::record($ev->getID(), 'briefing', 'triage', 'Triagem aceita pelo Marketing.' . ($comment !== '' ? " $comment" : ''));
                self::maybeAdvance($ev);
                return 'Triagem aceita. Falta apenas o que a etapa ainda exige para concluir.';

            case 'conclude':
                if (!$f || !self::canAct($ev, $f)) {
                    throw new RuntimeException('Você não executa esta etapa.');
                }
                if ($missing = self::missing($ev, $f)) {
                    throw new RuntimeException('Falta para concluir: ' . implode(' ', $missing));
                }
                self::leave($f, 'done', $comment ?: 'Etapa concluída.');
                Historico::record($ev->getID(), $f['code'], 'done', $comment);
                return self::next($ev) ? 'Etapa concluída. O evento seguiu para a próxima.' : 'Última etapa concluída: evento encerrado.';

            case 'return':
                if (!$f || !self::canAct($ev, $f)) {
                    throw new RuntimeException('Você não executa esta etapa.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o motivo da devolução.');
                }
                $phases = self::forEvento($ev->getID());
                $target = $phases[(string) ($in['target'] ?? '')] ?? null;
                if (!$target || $target['position'] >= $f['position'] || !in_array($target['status'], ['done', 'skipped'], true)) {
                    throw new RuntimeException('Escolha uma etapa anterior para devolver.');
                }
                self::leave($f, 'pending', "Devolvido para {$target['code']}: $comment");
                foreach ($phases as $p) {
                    if ($p['position'] > $target['position'] && $p['position'] < $f['position'] && $p['status'] !== 'pending') {
                        $DB->update(self::getTable(), ['status' => 'pending'], ['id' => $p['id']]);
                    }
                }
                $DB->update(self::getTable(), ['returns' => (int) $target['returns'] + 1], ['id' => $target['id']]);
                $ev->update(['id' => $ev->getID(), '_workflow' => true, 'returns' => (int) $ev->fields['returns'] + 1]);
                $ev->getFromDB($ev->getID());
                Historico::record($ev->getID(), $f['code'], 'return', 'Para "' . self::name($target['code']) . "\": $comment");
                $target['status'] = 'pending';
                self::activate($ev, $target, $comment, self::name($f['code']));
                return 'Devolvido para "' . self::name($target['code']) . '".';

            case 'skip':
                if (!$f || !Session::haveRight(Evento::$rightname, UPDATE)) {
                    throw new RuntimeException('Só quem edita eventos pode pular uma etapa.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe por que a etapa será pulada.');
                }
                if ($f['code'] === 'avaliacao') {
                    throw new RuntimeException('A avaliação e aprovação formal não pode ser pulada.');
                }
                self::leave($f, 'skipped', "Etapa pulada: $comment");
                Historico::record($ev->getID(), $f['code'], 'skip', $comment);
                return self::next($ev) ? 'Etapa pulada.' : 'Evento encerrado.';

            case 'cancel':
                if ((int) $ev->fields['status'] !== Evento::OPEN || !(Session::haveRight(Evento::$rightname, UPDATE) || $me === (int) $ev->fields['users_id_owner'])) {
                    throw new RuntimeException('Não é possível cancelar este evento.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o motivo do cancelamento.');
                }
                $s = Financeiro::summary($ev->getID());
                if ($f) {
                    self::leave($f, 'pending', "Evento cancelado: $comment");
                }
                $DB->update(Aprovacao::getTable(), ['status' => 'canceled'], ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'status' => ['pending', 'waiting']]);
                $ev->update(['id' => $ev->getID(), '_workflow' => true, 'status' => Evento::CANCELED, 'date_end' => $_SESSION['glpi_currenttime'], 'close_comment' => $comment, 'current_phase' => null, 'project_status' => 'cancelado']);
                $impact = 'Custos incorridos: realizado ' . Menu::money($s['despesa']['real']) . ', comprometido aberto ' . Menu::money($s['despesa']['open']) . '.';
                Historico::record($ev->getID(), $f['code'] ?? null, 'canceled', "$comment $impact");
                $ev->getFromDB($ev->getID());
                Avisos::send($ev, 'evento_cancelado', Avisos::followers($ev), 'Evento cancelado: ' . $ev->fields['name'], "Motivo: $comment. $impact", 'warning');
                return 'Evento cancelado. O histórico e o impacto financeiro continuam registrados.';

            case 'reopen':
                if (!Session::haveRight(Evento::$rightname, UPDATE) || (int) $ev->fields['status'] === Evento::OPEN) {
                    throw new RuntimeException('Não é possível reabrir este evento.');
                }
                $ev->update(['id' => $ev->getID(), '_workflow' => true, 'status' => Evento::OPEN, 'date_end' => null, 'accepted_date' => null, 'accepted_users' => 0]);
                $ev->getFromDB($ev->getID());
                Historico::record($ev->getID(), null, 'reopened', $comment);
                $phases = self::forEvento($ev->getID());
                $back = null;
                foreach ($phases as $p) {
                    if ($p['status'] === 'pending') {
                        $back = $p;
                        break;
                    }
                }
                $back ??= end($phases);
                if ($back) {
                    self::activate($ev, $back, $comment);
                }
                return 'Evento reaberto.';

            case 'ready_gp':
            case 'ready_mkt':
                if (!$f || $f['code'] !== 'prontidao') {
                    throw new RuntimeException('O aceite de prontidão acontece na etapa de Preparação final.');
                }
                $isGp = $action === 'ready_gp';
                if ($isGp ? !($me === (int) $ev->fields['users_id_gp'] || Session::haveRight(Evento::$rightname, UPDATE)) : !Evento::isMarketing()) {
                    throw new RuntimeException($isGp ? 'Só o GP do evento dá o aceite de prontidão.' : 'Só o Marketing dá o aceite de prontidão.');
                }
                $ev->update(['id' => $ev->getID(), '_workflow' => true] + ($isGp ? ['ready_gp_users' => $me, 'ready_gp_date' => $_SESSION['glpi_currenttime']] : ['ready_mkt_users' => $me, 'ready_mkt_date' => $_SESSION['glpi_currenttime']]));
                $ev->getFromDB($ev->getID());
                Historico::record($ev->getID(), 'prontidao', 'done', 'Aceite de prontidão do ' . ($isGp ? 'GP' : 'Marketing') . ' por ' . getUserName($me) . '.');
                self::maybeAdvance($ev);
                return 'Aceite de prontidão registrado.';

            case 'accept':
                if (!$f || $f['code'] !== 'encerramento') {
                    throw new RuntimeException('O aceite do dono acontece na etapa de encerramento.');
                }
                if (!($me === (int) $ev->fields['users_id_owner'] || Session::haveRight(Evento::$rightname, UPDATE))) {
                    throw new RuntimeException('Só o dono do evento dá o aceite formal.');
                }
                $ev->update(['id' => $ev->getID(), '_workflow' => true, 'accepted_users' => $me, 'accepted_date' => $_SESSION['glpi_currenttime']]);
                $ev->getFromDB($ev->getID());
                Historico::record($ev->getID(), 'encerramento', 'done', 'Aceite formal do dono do evento: ' . getUserName($me) . '.');
                self::maybeAdvance($ev);
                return 'Aceite formal registrado.';

            case 'comment':
                if ($comment === '') {
                    throw new RuntimeException('Escreva o comentário.');
                }
                Historico::record($ev->getID(), $f['code'] ?? null, 'comment', $comment);
                return 'Comentário registrado.';
        }
        throw new RuntimeException('Ação desconhecida.');
    }

    /** Envia o rascunho à triagem: confere o briefing, a antecedência (RN-01) e começa o relógio da primeira etapa. */
    private static function submit(Evento $ev, string $exceptionReason): string
    {
        $fl = $ev->fields;
        if ((int) $fl['status'] !== Evento::OPEN || !$fl['is_draft']) {
            throw new RuntimeException('Só um rascunho é enviado à triagem.');
        }
        if (!$ev->canEditData()) {
            throw new RuntimeException('Você não edita este evento.');
        }
        if ($missing = Evento::briefingMissing($fl)) {
            throw new RuntimeException('O briefing está incompleto: ' . implode(' ', $missing));
        }
        $lead = Evento::leadInfo($fl, date('Y-m-d', self::now()));
        $ex = 'none';
        if ($lead && $lead['short'] && $lead['mode'] !== 'warn') {
            if ($lead['mode'] === 'block') {
                throw new RuntimeException("Antecedência de {$lead['days']} dia(s), abaixo do mínimo de {$lead['min']}: ajuste a data do evento.");
            }
            if ($exceptionReason === '' && trim((string) $fl['exception_reason']) === '') {
                throw new RuntimeException("Antecedência de {$lead['days']} dia(s), abaixo do mínimo de {$lead['min']}: informe a justificativa da exceção para enviar.");
            }
            $ex = 'pendente';
        }
        $now = $_SESSION['glpi_currenttime'];
        $ev->update(['id' => $ev->getID(), '_workflow' => true, 'is_draft' => 0, 'date_request' => $now, 'approval_status' => 'triagem', 'triage_status' => 'pendente', 'triage_comment' => '',
            'exception_status' => $ex, 'exception_reason' => $exceptionReason !== '' ? $exceptionReason : $fl['exception_reason']]);
        $ev->getFromDB($ev->getID());
        Historico::record($ev->getID(), 'briefing', 'submit', 'Briefing enviado à triagem do Marketing.');
        if ($ex === 'pendente') {
            Aprovacao::request($ev, 'excecao', 'Antecedência de ' . $lead['days'] . ' dia(s) (mínimo ' . $lead['min'] . ')', 0, 0, (string) $ev->fields['exception_reason']);
        }
        self::sync($ev);
        $phases = self::forEvento($ev->getID());
        $first = reset($phases);
        if ($first) {
            self::activate($ev, $first);
        }
        return $ex === 'pendente' ? 'Enviado à triagem. A exceção de antecedência foi para aprovação.' : 'Enviado à triagem do Marketing.';
    }

    /** Ativa a próxima etapa a fazer; sem nenhuma, encerra o evento. Devolve se ainda há etapa. */
    private static function next(Evento $ev): bool
    {
        foreach (self::forEvento($ev->getID()) as $p) {
            if ($p['status'] === 'pending') {
                self::activate($ev, $p);
                return true;
            }
        }
        $ev->update(['id' => $ev->getID(), '_workflow' => true, 'status' => Evento::DONE, 'date_end' => $_SESSION['glpi_currenttime'], 'current_phase' => null, 'project_status' => 'concluido']);
        $ev->getFromDB($ev->getID());
        Dossie::attach($ev);
        Historico::record($ev->getID(), null, 'closed', 'Evento concluído em ' . Menu::days(Evento::cycleSeconds($ev->fields)) . ' dias.');
        Avisos::send($ev, 'evento_concluido', Avisos::followers($ev), 'Evento concluído: ' . $ev->fields['name'],
            'Definition of Done cumprida e aceite do dono registrado. Ciclo total de ' . Menu::days(Evento::cycleSeconds($ev->fields)) . ' dias, com ' . (int) $ev->fields['returns'] . ' devolução(ões).', 'success');
        return false;
    }

    private static function activate(Evento $ev, array $p, string $returnReason = '', string $returnedFrom = ''): void
    {
        global $DB;
        $now = $_SESSION['glpi_currenttime'];
        $et = Etapa::all()[$p['code']] ?? [];
        $clock = $now;
        if (($et['clock_from'] ?? '') === 'event_start' && $ev->fields['date_start'] && $ev->fields['date_start'] . ' 00:00:00' > $now) {
            $clock = $ev->fields['date_start'] . ' 00:00:00';
        }
        $DB->update(self::getTable(), ['status' => 'active', 'date_start' => $now, 'date_clock' => $clock, 'date_end' => null, 'late_notified' => 0, 'ready_notified' => 0], ['id' => $p['id']]);
        $ev->update(['id' => $ev->getID(), '_workflow' => true, 'current_phase' => $p['code']]);
        $ev->getFromDB($ev->getID());
        Evento::syncProjectStatus($ev);
        Historico::record($ev->getID(), $p['code'], 'start');
        $p = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $p['id']]])->current();

        $name = self::name($p['code']);
        $ticket = self::openTicket($ev, $p, $returnReason, $returnedFrom);
        $slo = (int) $p['slo_days'] ? 'SLO de ' . (int) $p['slo_days'] . ' dia(s).' : '';
        $facts = ['Etapa' => $name, 'Área' => self::area($p), 'SLO' => (int) $p['slo_days'] ? (int) $p['slo_days'] . ' dia(s)' : '—'] + ($ticket ? ['Chamado' => "#$ticket"] : []);
        $area = Avisos::members((int) $p['groups_id']);
        if ($returnedFrom !== '') {
            Avisos::send($ev, 'evento_devolvido', $area, "Evento devolvido para sua área: $name", "\"$returnedFrom\" devolveu {$ev->fields['name']}. Motivo: $returnReason", 'danger', $facts);
            Avisos::send($ev, 'evento_devolvido', Avisos::followers($ev), "Evento devolvido: {$ev->fields['name']}", "Voltou de \"$returnedFrom\" para \"$name\". Motivo: $returnReason", 'warning', $facts, !$area);
        } else {
            Avisos::send($ev, 'evento_etapa', $area, "Nova etapa para sua área: $name", "Evento {$ev->fields['name']}. $slo", 'warning', $facts);
            Avisos::send($ev, 'evento_etapa', Avisos::followers($ev), "Evento avançou: $name", "{$ev->fields['name']} está agora em \"$name\" (" . self::area($p) . "). $slo", 'info', $facts, !$area);
        }
    }

    /** Sai da etapa: soma o tempo, marca a situação e resolve o chamado dela. */
    private static function leave(array $f, string $status, string $solution): void
    {
        global $DB;
        $from = $f['date_clock'] ?: $f['date_start'];
        $seconds = (int) $f['seconds'] + ($from && $f['status'] === 'active' ? max(0, self::now() - strtotime($from)) : 0);
        $DB->update(self::getTable(), [
            'status' => $status, 'seconds' => $seconds, 'date_end' => $status === 'pending' ? null : $_SESSION['glpi_currenttime'],
            'users_id_done' => $status === 'done' ? (int) Session::getLoginUserID() : (int) $f['users_id_done'],
        ], ['id' => $f['id']]);
        self::solveTicket((int) $f['tickets_id'], $solution);
    }

    /** Conclui sozinha a etapa ativa quando uma decisão (como a aprovação de entrada) deixou tudo pronto. */
    public static function autoAdvance(Evento $ev, string $code, string $comment): void
    {
        $f = self::active($ev->getID());
        if (!$f || $f['code'] !== $code || (int) $ev->fields['status'] !== Evento::OPEN || self::missing($ev, $f)) {
            return;
        }
        self::leave($f, 'done', $comment);
        Historico::record($ev->getID(), $f['code'], 'done', $comment);
        self::next($ev);
    }

    /** Avisa a área uma vez quando a etapa ativa ficou pronta para concluir (e rearma se deixou de estar). */
    public static function maybeAdvance(Evento $ev): void
    {
        global $DB;
        $ev->getFromDB($ev->getID());
        $f = self::active($ev->getID());
        if (!$f || (int) $ev->fields['status'] !== Evento::OPEN) {
            return;
        }
        $ready = !self::missing($ev, $f);
        if ($ready && !(int) $f['ready_notified']) {
            $DB->update(self::getTable(), ['ready_notified' => 1], ['id' => $f['id']]);
            $name = self::name($f['code']);
            Avisos::send($ev, 'etapa_pronta', array_merge(Avisos::members((int) $f['groups_id']), Avisos::followers($ev)), "Etapa pronta para concluir: $name",
                "Tudo o que a etapa \"$name\" exige está preenchido em {$ev->fields['name']}. Falta concluir a etapa.", 'success', ['Etapa' => $name]);
        } elseif (!$ready && (int) $f['ready_notified']) {
            $DB->update(self::getTable(), ['ready_notified' => 0], ['id' => $f['id']]);
        }
    }

    // ------------------------------------------------------------------ chamado da etapa

    private static function openTicket(Evento $ev, array $p, string $returnReason, string $returnedFrom): int
    {
        global $DB;
        if (!(int) $p['groups_id'] || !(Config::getConfigurationValues('plugin:gestaoeventos', ['tickets'])['tickets'] ?? '1')) {
            return 0;
        }
        $e = [Menu::class, 'e'];
        $etapa = Etapa::all()[$p['code']] ?? [];
        $url = Evento::absolute(Evento::getFormURLWithID($ev->getID()));
        $content = '<p><strong>' . $e($ev->fields['code'] . ' · ' . $ev->fields['name']) . '</strong> — ' . $e(Evento::TYPES[$ev->fields['event_type']][0] ?? '') . ' em ' . $e(Menu::date($ev->fields['date_start'])) . '</p>'
            . ($returnedFrom !== '' ? '<p><strong>Devolvido por "' . $e($returnedFrom) . '":</strong> ' . $e($returnReason) . '</p>' : '')
            . '<p>' . $e($etapa['description'] ?? '') . '</p>'
            . ((int) $p['slo_days'] ? '<p>SLO: ' . (int) $p['slo_days'] . ' dia(s).</p>' : '')
            . '<p>Conclua a etapa no evento: <a href="' . $e($url) . '">' . $e($url) . '</a></p>';
        try {
            $t = new Ticket();
            $id = (int) $t->add([
                'name' => '[' . $ev->fields['code'] . '] ' . self::name($p['code']) . ' — ' . $ev->fields['name'],
                'content' => $content, 'entities_id' => (int) $ev->fields['entities_id'], 'type' => Ticket::DEMAND_TYPE,
                '_actors' => [
                    'requester' => array_values(array_map(fn ($u) => ['itemtype' => 'User', 'items_id' => $u, 'use_notification' => 1], array_filter([(int) Session::getLoginUserID()]))),
                    'assign'    => [['itemtype' => Group::class, 'items_id' => (int) $p['groups_id'], 'use_notification' => 1]],
                ],
                '_disablenotif' => false,
            ]);
            if ($id) {
                $DB->update(self::getTable(), ['tickets_id' => $id], ['id' => $p['id']]);
            }
            return $id;
        } catch (Throwable $ex) {
            trigger_error('Gestão de Eventos: chamado da etapa não aberto: ' . $ex->getMessage(), E_USER_WARNING);
            return 0;
        }
    }

    private static function solveTicket(int $ticketId, string $text): void
    {
        $t = new Ticket();
        if (!$ticketId || !$t->getFromDB($ticketId) || in_array((int) $t->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)) {
            return;
        }
        try {
            (new ITILSolution())->add(['itemtype' => Ticket::class, 'items_id' => $ticketId, 'content' => '<p>' . htmlspecialchars($text) . '</p>']);
        } catch (Throwable $e) {
            trigger_error('Gestão de Eventos: chamado da etapa não resolvido: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    // ------------------------------------------------------------------ nomes

    public static function name(string $code): string
    {
        return (string) (Etapa::all()[$code]['name'] ?? Etapa::DEFAULTS[$code][0] ?? $code);
    }

    public static function area(array $p): string
    {
        if ((int) $p['groups_id']) {
            return \Dropdown::getDropdownName('glpi_groups', (int) $p['groups_id'], false, true, false, '');
        }
        return (string) (Etapa::all()[$p['code']]['area'] ?? '');
    }
}
