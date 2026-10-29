<?php

namespace GlpiPlugin\Sistemasinternos;

use Session;
use Throwable;

/**
 * Alertas para o navegador (js/alertas.js): o que é novo para o usuário desde a
 * última consulta, com os canais de cada tipo (sinalizador, som, voz, pop-up),
 * e o resumo do sinalizador (problemas e chamados que pedem atenção agora).
 */
class Alerts
{
    public const TABLE = 'glpi_plugin_sistemasinternos_seens';

    public static function poll(int $uid): array
    {
        $state = self::state($uid);
        $first = $state['_new']; // primeira consulta: só marca o ponto de partida, sem disparar
        $now = time();
        $items = [];
        $flag = [];
        $errors = [];

        // ---------------------------------------------------------------- Zabbix
        if (Settings::isConfigured() && Settings::ids('groups')) {
            try {
                $z = self::zabbixEvents();
                $maxE = (int) $state['last_eventid'];
                $maxR = (int) $state['last_recoveryid'];
                foreach ($z as $p) {
                    $key = 'zbx_' . $p['severity'];
                    $resolved = (int) $p['r_eventid'] > 0;
                    if (!$resolved && Settings::alertOn($key, 'flag')) {
                        $flag[] = ['title' => $p['name'], 'sub' => $p['host'] . ' · há ' . Painel::duration($now - $p['clock']), 'color' => Settings::ALERTS[$key][3],
                            'sev' => $p['severity'], 'url' => self::problemUrl($p), 'icon' => Settings::ALERTS[$key][2]];
                    }
                    if (!$resolved && (int) $p['eventid'] > (int) $state['last_eventid'] && !$first && Settings::alertOn($key)) {
                        $label = Settings::ALERTS[$key][0];
                        $items[] = self::item($key, "$label · {$p['host']}", $p['name'], self::problemUrl($p),
                            "Alerta $label. {$p['host']}. {$p['name']}.");
                    }
                    if ($resolved && (int) $p['r_eventid'] > (int) $state['last_recoveryid'] && !$first && Settings::alertOn('zbx_ok')) {
                        $items[] = self::item('zbx_ok', "Resolvido · {$p['host']}", $p['name'] . ' (durou ' . Painel::duration((int) $p['r_clock'] - $p['clock']) . ')',
                            self::problemUrl($p), "Problema resolvido em {$p['host']}. {$p['name']}.");
                    }
                    $maxE = max($maxE, (int) $p['eventid']);
                    $maxR = max($maxR, (int) $p['r_eventid']);
                }
                $state['last_eventid'] = $maxE;
                $state['last_recoveryid'] = $maxR;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        // ---------------------------------------------------------------- GLPI
        global $DB;
        $ents = Painel::entities();
        $base = ['is_deleted' => 0, 'entities_id' => $ents, 'status' => [1, 2, 3, 4]];
        $maxT = (int) $state['last_tickets_id'];
        $since = date('Y-m-d H:i:s', $now - 86400);
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_tickets', 'WHERE' => $base + ['id' => ['>', $maxT], 'date' => ['>=', $since]], 'ORDER' => 'id', 'LIMIT' => 20]) as $t) {
            if (!$first && Settings::alertOn('glpi_new')) {
                $items[] = self::item('glpi_new', 'Chamado novo #' . $t['id'], (string) $t['name'], \Ticket::getFormURLWithID((int) $t['id']), "Chamado novo, número {$t['id']}. {$t['name']}.");
            }
            $maxT = max($maxT, (int) $t['id']);
        }
        if ($first) {
            $maxT = (int) ($DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => 'glpi_tickets'])->current()['m'] ?? 0);
        }
        $state['last_tickets_id'] = $maxT;

        $alerted = json_decode((string) $state['sla_alerted'], true) ?: ['vence' => [], 'vencido' => []];
        $prest = Settings::prestadores();
        $soon = date('Y-m-d H:i:s', $now + 3600);
        $rows = iterator_to_array($DB->request(['SELECT' => ['id', 'name', 'time_to_resolve'], 'FROM' => 'glpi_tickets',
            'WHERE' => $base + ['NOT' => ['time_to_resolve' => null], 'time_to_resolve' => ['<=', $soon]], 'ORDER' => 'time_to_resolve', 'LIMIT' => 200]), false);
        $techs = [];
        if ($prest && $rows) {
            foreach ($DB->request(['SELECT' => ['tickets_id', 'users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['type' => 2, 'tickets_id' => array_column($rows, 'id')]]) as $u) {
                $techs[(int) $u['tickets_id']][] = mb_strtolower((string) getUserName((int) $u['users_id']));
            }
        }
        $keepAlerted = ['vence' => [], 'vencido' => []];
        foreach ($rows as $t) {
            $id = (int) $t['id'];
            if ($prest && array_intersect($techs[$id] ?? [], $prest)) {
                continue; // prestador de serviço: fora do SLA do departamento
            }
            $due = strtotime((string) $t['time_to_resolve']);
            $late = $due < $now;
            $kind = $late ? 'vencido' : 'vence';
            $key = $late ? 'glpi_slalate' : 'glpi_sla1h';
            $url = \Ticket::getFormURLWithID($id);
            if (Settings::alertOn($key, 'flag')) {
                $flag[] = ['title' => "#$id " . $t['name'], 'sub' => $late ? 'SLA vencido há ' . Painel::duration($now - $due) : 'SLA vence em ' . Painel::duration($due - $now),
                    'color' => Settings::ALERTS[$key][3], 'sev' => $late ? 5 : 3, 'url' => $url, 'icon' => Settings::ALERTS[$key][2]];
            }
            $keepAlerted[$kind][] = $id;
            if (!in_array($id, $alerted[$kind] ?? [], true) && !$first && Settings::alertOn($key)) {
                $items[] = self::item($key, ($late ? 'SLA vencido' : 'SLA vence em ' . Painel::duration($due - $now)) . " · chamado #$id", (string) $t['name'], $url,
                    ($late ? 'SLA vencido' : 'SLA vencendo') . ", chamado número $id.");
            }
        }
        $state['sla_alerted'] = json_encode($keepAlerted);

        // ---------------------------------------------------------------- cadastro de hosts (feito por outras pessoas)
        $maxL = (int) $state['last_logs_id'];
        foreach ($DB->request(['FROM' => Audit::TABLE, 'WHERE' => ['id' => ['>', $maxL]], 'ORDER' => 'id', 'LIMIT' => 50]) as $l) {
            if (!$first && (int) $l['users_id'] !== $uid && $l['result'] === 'ok' && Settings::alertOn('host_change')) {
                $verb = Audit::ACTIONS[$l['action']][0] ?? $l['action'];
                $items[] = self::item('host_change', getUserName((int) $l['users_id']) . ' ' . mb_strtolower($verb) . ' um host', (string) $l['host'],
                    Menu::url('/front/auditoria.php?id=' . (int) $l['id']), '');
            }
            $maxL = max($maxL, (int) $l['id']);
        }
        if ($first) {
            $maxL = (int) ($DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => Audit::TABLE])->current()['m'] ?? 0);
        }
        $state['last_logs_id'] = $maxL;

        // primeira consulta com o Zabbix fora do ar: não grava, senão a próxima dispararia todos os problemas ativos
        if (!($first && $errors)) {
            self::saveState($uid, $state);
        }

        usort($flag, fn ($a, $b) => $b['sev'] <=> $a['sev']);
        $muted = $state['muted_until'] && strtotime((string) $state['muted_until']) > $now;
        return [
            'ok'       => true,
            'poll'     => Settings::int('poll_seconds', 15, 600),
            'popup'    => ['seconds' => Settings::int('popup_seconds', 5, 300), 'max' => Settings::int('popup_max', 1, 10), 'position' => Settings::get('popup_position') === 'left' ? 'left' : 'right'],
            'volume'   => Settings::int('volume', 0, 100) / 100,
            'rate'     => max(0.6, min(1.6, (float) Settings::get('voice_rate'))),
            'quiet'    => Settings::isQuietNow(),
            'muted'    => $muted,
            'mutedUntil' => $muted ? date('H:i', strtotime((string) $state['muted_until'])) : '',
            'muteMinutes' => Settings::int('mute_minutes', 5, 1440),
            'everywhere' => Settings::get('everywhere') !== '0',
            'flag'     => ['count' => count($flag), 'color' => $flag[0]['color'] ?? '', 'items' => array_slice($flag, 0, 12)],
            'items'    => array_slice($items, 0, 20),
            'painel'   => Menu::url('/front/painel.php'),
            'errors'   => Session::haveRight(Menu::RIGHT_CONFIG, UPDATE) ? $errors : [],
        ];
    }

    /** Monta um alerta com os canais configurados para o tipo. */
    public static function item(string $key, string $title, string $message, string $url, string $speech): array
    {
        [$label, $group, $icon, $color] = Settings::ALERTS[$key];
        $cfg = Settings::alerts()[$key];
        $has = fn ($c) => in_array($c, $cfg['channels'], true);
        return [
            'key' => $key, 'group' => $group, 'label' => $label, 'icon' => $icon, 'color' => $color,
            'title' => $title, 'message' => $message, 'url' => $url, 'speech' => $speech, 'tone' => $cfg['tone'],
            'popup' => $has('popup'), 'sound' => $has('sound'), 'voice' => $has('voice') && $speech !== '', 'sticky' => $has('sticky'), 'flag' => $has('flag'),
        ];
    }

    /** Alerta de teste (botão "Testar" da configuração), sempre com o pop-up. */
    public static function test(string $key): array
    {
        if (!isset(Settings::ALERTS[$key])) {
            return [];
        }
        [$label] = Settings::ALERTS[$key];
        $item = self::item($key, "Teste · $label", 'Assim chega este tipo de alerta.', Menu::url('/front/painel.php'), "Teste de alerta: $label.");
        $item['popup'] = true;
        return $item;
    }

    /** Problemas ativos e resolvidos há pouco nos grupos do departamento (cache de 20 s, para todos). */
    private static function zabbixEvents(): array
    {
        $groups = Settings::ids('groups');
        return Zabbix::cached('alerts|' . implode(',', $groups), 20, function () use ($groups) {
            $problems = Zabbix::call('problem.get', [
                'groupids' => $groups, 'recent' => true, 'output' => ['eventid', 'r_eventid', 'r_clock', 'objectid', 'name', 'severity', 'clock'],
                'sortfield' => ['eventid'], 'sortorder' => 'DESC', 'limit' => 300,
            ]);
            $hosts = [];
            if ($problems) {
                foreach (Zabbix::call('trigger.get', ['triggerids' => array_values(array_unique(array_column($problems, 'objectid'))), 'output' => ['triggerid'], 'selectHosts' => ['hostid', 'name']]) as $t) {
                    $hosts[$t['triggerid']] = $t['hosts'][0] ?? ['hostid' => '', 'name' => ''];
                }
            }
            return array_map(fn ($p) => [
                'eventid' => $p['eventid'], 'r_eventid' => $p['r_eventid'], 'r_clock' => (int) $p['r_clock'], 'name' => $p['name'],
                'severity' => (int) $p['severity'], 'clock' => (int) $p['clock'],
                'hostid' => $hosts[$p['objectid']]['hostid'] ?? '', 'host' => $hosts[$p['objectid']]['name'] ?? '',
            ], $problems);
        });
    }

    private static function problemUrl(array $p): string
    {
        $web = Zabbix::webUrl();
        return $web !== '' && $p['hostid'] !== '' ? $web . 'zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=' . rawurlencode((string) $p['hostid']) : Menu::url('/front/painel.php');
    }

    // ---------------------------------------------------------------- estado por usuário

    private static function state(int $uid): array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['users_id' => $uid]])->current();
        if ($row) {
            return $row + ['_new' => false];
        }
        return ['users_id' => $uid, 'last_eventid' => 0, 'last_recoveryid' => 0, 'last_tickets_id' => 0, 'last_logs_id' => 0, 'sla_alerted' => '', 'muted_until' => null, '_new' => true];
    }

    private static function saveState(int $uid, array $s): void
    {
        global $DB;
        $data = array_intersect_key($s, array_flip(['last_eventid', 'last_recoveryid', 'last_tickets_id', 'last_logs_id', 'sla_alerted', 'muted_until']));
        $data['date_mod'] = date('Y-m-d H:i:s');
        $DB->updateOrInsert(self::TABLE, $data, ['users_id' => $uid]);
    }

    /** Silencia som e voz por N minutos (0 = religar). Pop-up e sinalizador continuam. */
    public static function mute(int $uid, int $minutes): void
    {
        global $DB;
        if (!$DB->request(['FROM' => self::TABLE, 'WHERE' => ['users_id' => $uid]])->count()) {
            self::poll($uid); // marca o ponto de partida antes, para não disparar o que já existia
        }
        $until = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
        $DB->updateOrInsert(self::TABLE, ['muted_until' => $until, 'date_mod' => date('Y-m-d H:i:s')], ['users_id' => $uid]);
    }
}
