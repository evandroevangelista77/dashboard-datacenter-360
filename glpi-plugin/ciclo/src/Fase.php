<?php

namespace GlpiPlugin\Ciclo;

use CommonDBTM;
use Group;
use ITILSolution;
use RuntimeException;
use Session;
use Ticket;
use Throwable;

/**
 * Fases de um Ciclo e o fluxo entre elas: concluir (com o que a fase exige),
 * devolver para uma fase anterior (retrabalho), pular, cancelar e reabrir.
 * O tempo de cada fase soma os períodos em que ela esteve ativa.
 */
class Fase extends CommonDBTM
{
    public static string $rightname = 'plugin_ciclo';

    public const STATUSES = [
        'pending' => ['A fazer', '#64748b'],
        'active'  => ['Em andamento', '#3b82f6'],
        'done'    => ['Concluída', '#22c55e'],
        'skipped' => ['Pulada', '#94a3b8'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Fases' : 'Fase';
    }

    public static function forCiclo(int $cicloId): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_ciclo_ciclos_id' => $cicloId], 'ORDER' => 'position']) as $r) {
            $rows[$r['code']] = $r;
        }
        return $rows;
    }

    public static function active(int $cicloId): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_ciclo_ciclos_id' => $cicloId, 'status' => 'active'], 'LIMIT' => 1])->current() ?: null;
    }

    /** Tempo da fase em segundos (os períodos anteriores mais o atual, se ativa). */
    public static function elapsed(array $f): int
    {
        $s = (int) $f['seconds'];
        if ($f['status'] === 'active' && $f['date_start']) {
            $s += max(0, strtotime($_SESSION['glpi_currenttime'] ?? 'now') - strtotime($f['date_start']));
        }
        return $s;
    }

    public static function isLate(array $f): bool
    {
        return (int) $f['slo_days'] > 0 && self::elapsed($f) > (int) $f['slo_days'] * 86400;
    }

    // ------------------------------------------------------------------ montagem

    /** Cria as fases de um Ciclo novo; a de aviso de fechamento já nasce concluída. */
    public static function build(Ciclo $c): void
    {
        global $DB;
        self::sync($c);
        $now = $_SESSION['glpi_currenttime'];
        $phases = self::forCiclo($c->getID());
        $first = reset($phases);
        if ($first) {
            $start = $c->fields['date_closing'] && $c->fields['date_closing'] < $now ? $c->fields['date_closing'] : $now;
            $DB->update(self::getTable(), ['status' => 'done', 'date_start' => $start, 'date_end' => $now, 'seconds' => max(0, strtotime($now) - strtotime($start)), 'users_id_done' => (int) Session::getLoginUserID()], ['id' => $first['id']]);
            Evento::record($c->getID(), $first['code'], 'done', 'Ciclo aberto a partir do aviso de fechamento.');
            self::next($c);
        }
    }

    /** Acerta as fases ao tipo de venda: cria as que passaram a valer e tira as pendentes que não valem mais. */
    public static function sync(Ciclo $c): void
    {
        global $DB;
        $have = self::forCiclo($c->getID());
        foreach (Etapa::all() as $code => $e) {
            $applies = $e['is_active'] && Etapa::appliesTo($e['applies'], (string) $c->fields['sale_type']);
            if ($applies && !isset($have[$code])) {
                $DB->insert(self::getTable(), [
                    'plugin_ciclo_ciclos_id' => $c->getID(), 'code' => $code, 'position' => (int) $e['position'],
                    'status' => 'pending', 'groups_id' => (int) $e['groups_id'], 'slo_days' => (int) $e['slo_days'],
                ]);
            } elseif (!$applies && isset($have[$code]) && $have[$code]['status'] === 'pending') {
                $DB->delete(self::getTable(), ['id' => $have[$code]['id']]);
            }
        }
    }

    // ------------------------------------------------------------------ permissões

    /** Executa a fase: quem tem o direito de editar Ciclos ou está no grupo da fase. */
    public static function canAct(Ciclo $c, ?array $f): bool
    {
        if (Session::haveRight(Ciclo::$rightname, UPDATE)) {
            return true;
        }
        return $f && in_array((int) Session::getLoginUserID(), Avisos::members((int) $f['groups_id']), true);
    }

    // ------------------------------------------------------------------ o que falta para concluir

    public static function missing(Ciclo $c, array $f): array
    {
        $req = (string) (Etapa::all()[$f['code']]['requirement'] ?? '');
        $c->fields += ['cc_number' => '', 'transport_mode' => '', 'date_delivery' => null, 'date_accept' => null];
        $items = fn (string $kind) => Item::forCiclo($c->getID(), $kind);
        $out = [];
        switch ($req) {
            case 'cc_number':
                if (trim((string) $c->fields['cc_number']) === '') {
                    $out[] = 'Informe o número do centro de custo (Dados do Ciclo).';
                }
                break;
            case 'pedido_pa':
            case 'rm':
            case 'pedido':
                if (!$items($req)) {
                    $out[] = 'Registre pelo menos um item em "' . Item::KINDS[$req][0] . '".';
                }
                break;
            case 'nf_saida_hw':
            case 'nf_saida_srv':
                $want = $req === 'nf_saida_hw' ? ['hw'] : ['sw', 'srv'];
                if (!array_filter($items('nf_saida'), fn ($n) => in_array($n['category'], $want, true))) {
                    $out[] = $req === 'nf_saida_hw' ? 'Registre a NF de saída do hardware (tipo Hardware).' : 'Registre a NF de saída do serviço ou da licença (tipo Serviço ou Software).';
                }
                break;
            case 'nf_entrada_ok':
                $nfs = $items('nf_entrada');
                if (!$nfs) {
                    $out[] = 'Registre a NF de entrada do material recebido.';
                } elseif (array_filter($nfs, fn ($n) => $n['status'] !== 'ok')) {
                    $out[] = 'Todas as NFs de entrada precisam estar conferidas como OK. Entrega NOK: use "Devolver" para Compras.';
                }
                break;
            case 'transport':
                if (!$c->fields['transport_mode']) {
                    $out[] = 'Informe o transporte: cotação aprovada ou motorista próprio (Dados do Ciclo).';
                }
                break;
            case 'date_delivery':
                if (!$c->fields['date_delivery']) {
                    $out[] = 'Informe a data da entrega ao cliente (Dados do Ciclo).';
                }
                break;
            case 'date_accept':
                if (!$c->fields['date_accept']) {
                    $out[] = 'Informe a data do aceite do cliente (Dados do Ciclo).';
                }
                break;
            case 'parcelas_pagas':
                $p = $items('parcela');
                if (!$p) {
                    $out[] = 'Registre as parcelas a receber.';
                } elseif ($open = array_filter($p, fn ($x) => !$x['date_done'])) {
                    $out[] = count($open) . ' parcela(s) ainda sem data de recebimento.';
                }
                break;
        }
        return $out;
    }

    // ------------------------------------------------------------------ fluxo

    public static function workflow(Ciclo $c, string $action, array $in): string
    {
        $comment = trim((string) ($in['comment'] ?? ''));
        $f = self::active($c->getID());
        switch ($action) {
            case 'conclude':
                if (!$f || !self::canAct($c, $f)) {
                    throw new RuntimeException('Você não executa esta fase.');
                }
                if ($missing = self::missing($c, $f)) {
                    throw new RuntimeException('Falta para concluir: ' . implode(' ', $missing));
                }
                self::leave($f, 'done', $comment ?: 'Fase concluída.');
                Evento::record($c->getID(), $f['code'], 'done', $comment);
                return self::next($c) ? 'Fase concluída. O Ciclo seguiu para a próxima fase.' : 'Última fase concluída: Ciclo encerrado.';

            case 'return':
                if (!$f || !self::canAct($c, $f)) {
                    throw new RuntimeException('Você não executa esta fase.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o motivo da devolução.');
                }
                $phases = self::forCiclo($c->getID());
                $target = $phases[(string) ($in['target'] ?? '')] ?? null;
                if (!$target || $target['position'] >= $f['position'] || !in_array($target['status'], ['done', 'skipped'], true)) {
                    throw new RuntimeException('Escolha uma fase anterior para devolver.');
                }
                global $DB;
                self::leave($f, 'pending', "Devolvido para {$target['code']}: $comment");
                // as fases entre a de destino e a atual voltam a ficar a fazer
                foreach ($phases as $p) {
                    if ($p['position'] > $target['position'] && $p['position'] < $f['position'] && $p['status'] !== 'pending') {
                        $DB->update(self::getTable(), ['status' => 'pending'], ['id' => $p['id']]);
                    }
                }
                $DB->update(self::getTable(), ['returns' => (int) $target['returns'] + 1], ['id' => $target['id']]);
                $DB->update(Ciclo::getTable(), ['returns' => (int) $c->fields['returns'] + 1], ['id' => $c->getID()]);
                $c->getFromDB($c->getID());
                Evento::record($c->getID(), $f['code'], 'return', 'Para "' . self::name($target['code']) . "\": $comment");
                $target['status'] = 'pending';
                self::activate($c, $target, $comment, self::name($f['code']));
                return 'Devolvido para "' . self::name($target['code']) . '".';

            case 'skip':
                if (!$f || !Session::haveRight(Ciclo::$rightname, UPDATE)) {
                    throw new RuntimeException('Só quem edita Ciclos pode pular uma fase.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe por que a fase será pulada.');
                }
                self::leave($f, 'skipped', "Fase pulada: $comment");
                Evento::record($c->getID(), $f['code'], 'skip', $comment);
                return self::next($c) ? 'Fase pulada.' : 'Ciclo encerrado.';

            case 'cancel':
                if (!Session::haveRight(Ciclo::$rightname, UPDATE) || (int) $c->fields['status'] !== Ciclo::OPEN) {
                    throw new RuntimeException('Não é possível cancelar este Ciclo.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o motivo do cancelamento.');
                }
                if ($f) {
                    self::leave($f, 'pending', "Ciclo cancelado: $comment");
                }
                $c->update(['id' => $c->getID(), '_workflow' => true, 'status' => Ciclo::CANCELED, 'date_end' => $_SESSION['glpi_currenttime'], 'close_comment' => $comment, 'current_phase' => null]);
                Evento::record($c->getID(), $f['code'] ?? null, 'canceled', $comment);
                $c->getFromDB($c->getID());
                Avisos::send($c, 'ciclo_cancelado', array_merge(Avisos::followers($c), [(int) $c->fields['users_id_commercial']]), 'Ciclo cancelado: ' . $c->fields['name'], "Motivo: $comment", 'warning');
                return 'Ciclo cancelado.';

            case 'reopen':
                if (!Session::haveRight(Ciclo::$rightname, UPDATE) || (int) $c->fields['status'] === Ciclo::OPEN) {
                    throw new RuntimeException('Não é possível reabrir este Ciclo.');
                }
                $c->update(['id' => $c->getID(), '_workflow' => true, 'status' => Ciclo::OPEN, 'date_end' => null]);
                $c->getFromDB($c->getID());
                Evento::record($c->getID(), null, 'reopened', $comment);
                // volta para a última fase que não terminou (ou a última de todas)
                $phases = self::forCiclo($c->getID());
                $back = null;
                foreach ($phases as $p) {
                    if ($p['status'] === 'pending') {
                        $back = $p;
                        break;
                    }
                }
                $back ??= end($phases);
                self::activate($c, $back, $comment);
                return 'Ciclo reaberto.';

            case 'comment':
                if ($comment === '') {
                    throw new RuntimeException('Escreva o comentário.');
                }
                Evento::record($c->getID(), $f['code'] ?? null, 'comment', $comment);
                return 'Comentário registrado.';
        }
        throw new RuntimeException('Ação desconhecida.');
    }

    /** Ativa a próxima fase a fazer; sem nenhuma, encerra o Ciclo. Devolve se ainda há fase. */
    private static function next(Ciclo $c): bool
    {
        foreach (self::forCiclo($c->getID()) as $p) {
            if ($p['status'] === 'pending') {
                self::activate($c, $p);
                return true;
            }
        }
        $c->update(['id' => $c->getID(), '_workflow' => true, 'status' => Ciclo::DONE, 'date_end' => $_SESSION['glpi_currenttime'], 'current_phase' => null]);
        $c->getFromDB($c->getID());
        Evento::record($c->getID(), null, 'closed', 'Ciclo concluído em ' . Menu::days(Ciclo::cycleSeconds($c->fields)) . ' dias.');
        Avisos::send($c, 'ciclo_concluido', array_merge(Avisos::followers($c), [(int) $c->fields['users_id_commercial']]), 'Ciclo concluído: ' . $c->fields['name'],
            'Valores recebidos. Ciclo total de ' . Menu::days(Ciclo::cycleSeconds($c->fields)) . ' dias, com ' . (int) $c->fields['returns'] . ' devolução(ões).', 'success');
        return false;
    }

    private static function activate(Ciclo $c, array $p, string $returnReason = '', string $returnedFrom = ''): void
    {
        global $DB;
        $now = $_SESSION['glpi_currenttime'];
        $DB->update(self::getTable(), ['status' => 'active', 'date_start' => $now, 'date_end' => null, 'late_notified' => 0], ['id' => $p['id']]);
        $c->update(['id' => $c->getID(), '_workflow' => true, 'current_phase' => $p['code']]);
        $c->getFromDB($c->getID());
        Evento::record($c->getID(), $p['code'], 'start');
        $p = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $p['id']]])->current();

        $name = self::name($p['code']);
        $ticket = self::openTicket($c, $p, $returnReason, $returnedFrom);
        $slo = (int) $p['slo_days'] ? 'SLO de ' . (int) $p['slo_days'] . ' dia(s).' : '';
        $facts = ['Fase' => $name, 'Área' => self::area($p), 'SLO' => (int) $p['slo_days'] ? (int) $p['slo_days'] . ' dia(s)' : '—'] + ($ticket ? ['Chamado' => "#$ticket"] : []);
        if ($returnedFrom !== '') {
            // um cartão no Teams por evento: o da área; o do GP/OTC só quando a fase não tem grupo
            $area = Avisos::members((int) $p['groups_id']);
            Avisos::send($c, 'ciclo_devolvido', $area, "Ciclo devolvido para sua área: $name", "\"$returnedFrom\" devolveu o Ciclo {$c->fields['name']}. Motivo: $returnReason", 'danger', $facts);
            Avisos::send($c, 'ciclo_devolvido', Avisos::followers($c), "Ciclo devolvido: {$c->fields['name']}", "Voltou de \"$returnedFrom\" para \"$name\". Motivo: $returnReason", 'warning', $facts, !$area);
        } else {
            $area = Avisos::members((int) $p['groups_id']);
            Avisos::send($c, 'ciclo_fase', $area, "Nova fase para sua área: $name", "Ciclo {$c->fields['name']} ({$c->fields['customer']}). $slo", 'warning', $facts);
            Avisos::send($c, 'ciclo_fase', Avisos::followers($c), "Ciclo avançou: $name", "{$c->fields['name']} está agora em \"$name\" (" . self::area($p) . "). $slo", 'info', $facts, !$area);
        }
    }

    /** Sai da fase: soma o tempo, marca a situação e resolve o chamado dela. */
    private static function leave(array $f, string $status, string $solution): void
    {
        global $DB;
        $now = $_SESSION['glpi_currenttime'];
        $seconds = (int) $f['seconds'] + ($f['date_start'] ? max(0, strtotime($now) - strtotime($f['date_start'])) : 0);
        $DB->update(self::getTable(), [
            'status' => $status, 'seconds' => $seconds, 'date_end' => $status === 'pending' ? null : $now,
            'users_id_done' => $status === 'done' ? (int) Session::getLoginUserID() : (int) $f['users_id_done'],
        ], ['id' => $f['id']]);
        self::solveTicket((int) $f['tickets_id'], $solution);
    }

    // ------------------------------------------------------------------ chamado da fase

    private static function openTicket(Ciclo $c, array $p, string $returnReason, string $returnedFrom): int
    {
        global $DB;
        if (!(int) $p['groups_id'] || !(\Config::getConfigurationValues('plugin:ciclo', ['tickets'])['tickets'] ?? '1')) {
            return 0;
        }
        $e = [Menu::class, 'e'];
        $etapa = Etapa::all()[$p['code']] ?? [];
        $url = Ciclo::absolute(Ciclo::getFormURLWithID($c->getID()));
        $content = '<p><strong>' . $e(Ciclo::code($c->getID()) . ' · ' . $c->fields['name']) . '</strong> — cliente ' . $e($c->fields['customer']) . '</p>'
            . ($returnedFrom !== '' ? '<p><strong>Devolvido por "' . $e($returnedFrom) . '":</strong> ' . $e($returnReason) . '</p>' : '')
            . '<p>' . $e($etapa['description'] ?? '') . '</p>'
            . ((int) $p['slo_days'] ? '<p>SLO: ' . (int) $p['slo_days'] . ' dia(s).</p>' : '')
            . '<p>Conclua a fase no Ciclo: <a href="' . $e($url) . '">' . $e($url) . '</a></p>';
        try {
            $t = new Ticket();
            $id = (int) $t->add([
                'name' => '[' . Ciclo::code($c->getID()) . '] ' . self::name($p['code']) . ' — ' . $c->fields['name'],
                'content' => $content, 'entities_id' => (int) $c->fields['entities_id'], 'type' => Ticket::DEMAND_TYPE,
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
            trigger_error('Ciclo: chamado da fase não aberto: ' . $ex->getMessage(), E_USER_WARNING);
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
            trigger_error('Ciclo: chamado da fase não resolvido: ' . $e->getMessage(), E_USER_WARNING);
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
