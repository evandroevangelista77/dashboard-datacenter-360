<?php

namespace GlpiPlugin\Sistemasinternos;

use CommonGLPI;
use Throwable;

/**
 * Painel 360 do departamento: Zabbix (grupos do departamento) e GLPI (entidade).
 */
class Painel extends CommonGLPI
{
    public static string $rightname = Menu::RIGHT_PAINEL;

    public static function getTypeName($nb = 0)
    {
        return 'Painel 360';
    }

    public static function getIcon()
    {
        return Menu::ICON;
    }

    public static function getMenuContent()
    {
        $items = Menu::content();
        return $items['visao'] ?? $items['painel'] ?? false;
    }

    public function getRights($interface = 'central')
    {
        return [READ => 'Ver o painel e receber os alertas'];
    }

    /** Situação do Zabbix nos grupos do departamento (cache de 30 s, para todos). */
    public static function zabbix(): array
    {
        $groups = Settings::ids('groups');
        if (!$groups) {
            return ['hosts' => [], 'problems' => []];
        }
        return Zabbix::cached('panel|' . implode(',', $groups), 30, function () use ($groups) {
            $hosts = Zabbix::call('host.get', [
                'groupids' => $groups, 'output' => ['hostid', 'host', 'name', 'status'],
                'selectInterfaces' => ['type', 'ip', 'dns', 'useip', 'available', 'main'], 'sortfield' => 'name',
            ]);
            // ICMP: vale para hosts só com ping, sem agente
            $ping = [];
            foreach (Zabbix::call('item.get', ['groupids' => $groups, 'output' => ['hostid', 'lastvalue', 'lastclock'], 'filter' => ['key_' => 'icmpping'], 'monitored' => true]) as $it) {
                if ((int) $it['lastclock'] > 0) {
                    $ping[$it['hostid']] = (int) $it['lastvalue'];
                }
            }
            $problems = Zabbix::call('problem.get', [
                'groupids' => $groups, 'output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'suppressed'],
                'recent' => false, 'sortfield' => ['eventid'], 'sortorder' => 'DESC', 'limit' => 500,
            ]);
            // host de cada problema (problem.get não traz o host)
            $byTrigger = [];
            if ($problems) {
                foreach (Zabbix::call('trigger.get', ['triggerids' => array_values(array_unique(array_column($problems, 'objectid'))), 'output' => ['triggerid'], 'selectHosts' => ['hostid', 'name']]) as $t) {
                    $byTrigger[$t['triggerid']] = $t['hosts'][0] ?? null;
                }
            }
            $outHosts = [];
            foreach ($hosts as $h) {
                $avail = Host::availability($h);
                if (isset($ping[$h['hostid']])) {
                    $avail = $ping[$h['hostid']] === 1 ? 1 : 2;
                }
                $main = Host::mainInterface($h);
                $outHosts[] = [
                    'id' => $h['hostid'], 'name' => $h['name'], 'status' => (int) $h['status'],
                    'ip' => $main ? ((int) $main['useip'] ? $main['ip'] : $main['dns']) : '', 'avail' => (int) $h['status'] === 1 ? -1 : $avail,
                ];
            }
            $outProblems = [];
            foreach ($problems as $p) {
                $h = $byTrigger[$p['objectid']] ?? null;
                $outProblems[] = [
                    'eventid' => $p['eventid'], 'triggerid' => $p['objectid'], 'name' => $p['name'], 'severity' => (int) $p['severity'], 'clock' => (int) $p['clock'],
                    'ack' => (int) $p['acknowledged'], 'hostid' => $h['hostid'] ?? '', 'host' => $h['name'] ?? '',
                ];
            }
            return ['hosts' => $outHosts, 'problems' => $outProblems, 'at' => time()];
        });
    }

    /** Descarta o cache do painel (depois de mexer num host). */
    public static function forget(): void
    {
        Zabbix::forget('panel|' . implode(',', Settings::ids('groups')));
    }

    /** Entidade do departamento e as filhas. */
    public static function entities(): array
    {
        $id = (int) Settings::get('entities_id');
        try {
            return array_map('intval', array_values(getSonsOf('glpi_entities', $id)));
        } catch (Throwable) {
            return [$id];
        }
    }

    /** Chamados da entidade: em aberto (com o prazo de solução) e solucionados nos últimos 14 dias. */
    public static function glpi(): array
    {
        global $DB;
        $ents = self::entities();
        $open = [];
        $now = time();
        $prest = Settings::prestadores();
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'status', 'priority', 'date', 'time_to_resolve', 'entities_id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['is_deleted' => 0, 'entities_id' => $ents, 'status' => [1, 2, 3, 4]],
            'ORDER'  => ['date DESC'],
            'LIMIT'  => 1000,
        ]) as $t) {
            $open[(int) $t['id']] = $t + ['techs' => []];
        }
        if ($open) {
            foreach ($DB->request(['SELECT' => ['tickets_id', 'users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['type' => 2, 'tickets_id' => array_keys($open)]]) as $u) {
                $open[(int) $u['tickets_id']]['techs'][] = getUserName((int) $u['users_id']);
            }
        }
        foreach ($open as &$t) {
            $due = $t['time_to_resolve'] ? strtotime($t['time_to_resolve']) : null;
            $isPrest = $prest && array_intersect(array_map('mb_strtolower', $t['techs']), $prest);
            $t['sla'] = $isPrest ? 'prestador' : ($due === null ? 'sem' : ($due < $now ? 'vencido' : ($due - $now <= 3600 ? 'vence' : 'ok')));
        }
        unset($t);

        // abertos × solucionados por dia (14 dias)
        $from = date('Y-m-d 00:00:00', strtotime('-13 days'));
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-$i days"))] = ['opened' => 0, 'solved' => 0, 'tickets' => []];
        }
        foreach ($DB->request(['SELECT' => ['id', 'name', 'status', 'date', 'solvedate'], 'FROM' => 'glpi_tickets', 'WHERE' => ['is_deleted' => 0, 'entities_id' => $ents, 'OR' => [['date' => ['>=', $from]], ['solvedate' => ['>=', $from]]]]]) as $r) {
            $t = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'status' => (int) $r['status'], 'date' => $r['date'], 'solvedate' => $r['solvedate']];
            $d = substr((string) $r['date'], 0, 10);
            if (isset($days[$d])) {
                $days[$d]['opened']++;
                $days[$d]['tickets'][] = $t + ['kind' => 'aberto'];
            }
            $s = substr((string) $r['solvedate'], 0, 10);
            if ($s && isset($days[$s])) {
                $days[$s]['solved']++;
                $days[$s]['tickets'][] = $t + ['kind' => 'solucionado'];
            }
        }
        return ['open' => array_values($open), 'days' => $days];
    }

    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        return $d ? "{$d}d {$h}h" : ($h ? "{$h}h {$m}min" : "{$m}min");
    }
}
