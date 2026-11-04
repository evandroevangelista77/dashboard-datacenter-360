<?php

namespace GlpiPlugin\Operacional360;

use RuntimeException;

/**
 * Dados do painel Operacional 360 (JSON), calculados no servidor do GLPI.
 *
 *   action=data      → painel da entidade (KPIs, clientes, hosts, alertas, chamados, detalhe)
 *   action=alertas   → alertas do navegador (problemas do Zabbix, chamados abertos, preferências)
 *   action=hostlog   → histórico de alertas de um host (dentro do escopo)
 *   action=ticketlog → histórico de um chamado (dentro do escopo)
 *
 * Tudo é limitado à entidade escolhida e aos host groups ligados a ela no mapa.
 */
class Api
{
    public static function route(string $action, array $q): array
    {
        return match ($action) {
            'data'      => self::data($q),
            'alertas'   => self::alertas($q),
            'hostlog'   => self::hostLog($q),
            'ticketlog' => self::ticketLog($q),
            default     => throw new HttpError(404, 'Ação desconhecida.'),
        };
    }

    /* ------------------------------------------------------------------ painel */

    public static function data(array $q): array
    {
        [$fromDate, $toDate] = self::dates($q);
        $from = strtotime("$fromDate 00:00:00");
        $till = strtotime("$toDate 23:59:59");
        $hostid = isset($q['hostid']) && ctype_digit((string) $q['hostid']) ? (string) $q['hostid'] : null;
        $ticketQ = trim((string) ($q['ticket'] ?? ''));
        $clientSel = trim((string) ($q['client'] ?? ''));
        $now = time();
        $ttl = Settings::int('cache_seconds', 0, 600);
        $sevs = Settings::severities();
        $front = Settings::zabbixFront();

        $scope = Scope::resolve(self::entityParam($q));
        $root = $scope['name'];
        $entityIds = Glpi::subtree($scope['id']);
        $groupIds = array_values(array_unique(array_column($scope['groups'], 'group_id')));
        $sevAll = $scope['severities'];

        // ---------------- Zabbix (hosts do escopo; alertas filtrados por severidade de cada host group)
        $idx = self::hostIndex($scope, $ttl);
        $hostClient = $idx['client'];
        $hostSev = $idx['sev'];
        $zHosts = $idx['hosts'];
        $raw = self::zabbixRaw($groupIds, $sevAll, $from, $till, $ttl);
        $raw['problems'] = array_values(array_filter($raw['problems'], fn ($p) => self::allowed($hostSev, $p['hostid'], $p['severity'])));
        $raw['events'] = array_values(array_filter($raw['events'], fn ($e) => self::allowed($hostSev, $e['hostid'], $e['severity'])));

        // ---------------- GLPI: chamados da entidade e das filhas
        $periodAll = array_map(fn ($t) => self::ticketRow($t, $now, $root), Glpi::tickets($entityIds, $from, $till, false));
        $openAll = array_map(fn ($t) => self::ticketRow($t, $now, $root), Glpi::tickets($entityIds, 0, 0, true));

        // ---------------- totais por cliente (sem os filtros de cliente/host/chamado)
        $agg = [];
        $touch = function (string $n, string $kind) use (&$agg, $sevs) {
            $agg[$n] ??= ['name' => $n, 'kind' => $kind, 'tickets' => 0, 'open' => 0, 'pending' => 0, 'overdue' => 0, 'alerts' => 0,
                'sev' => array_fill_keys($sevs, 0), 'active' => 0, 'unacked' => 0, 'hosts' => 0, 'groups' => []];
        };
        $touch($scope['short'], 'glpi');
        foreach (Glpi::directChildren($scope['id']) as $child) {
            $touch($child, 'glpi');
        }
        foreach ($scope['groups'] as $g) {
            $gname = $idx['groupnames'][$g['group_id']] ?? ($g['group_name'] !== '' ? $g['group_name'] : $g['group_id']);
            $touch($gname, 'zabbix');
        }
        foreach ($periodAll as $t) {
            $touch($t['client'], 'glpi');
            $agg[$t['client']]['tickets']++;
        }
        foreach ($openAll as $t) {
            $touch($t['client'], 'glpi');
            $agg[$t['client']]['open']++;
            $agg[$t['client']]['pending'] += $t['pending'] ? 1 : 0;
            $agg[$t['client']]['overdue'] += $t['late'] ? 1 : 0;
        }
        foreach ($zHosts as $h) {
            $c = $hostClient[$h['hostid']];
            $touch($c, 'zabbix');
            $agg[$c]['hosts']++;
        }
        foreach ($raw['events'] as $e) {
            $c = $hostClient[$e['hostid']];
            $touch($c, 'zabbix');
            $agg[$c]['alerts']++;
            $agg[$c]['sev'][$e['severity']] = ($agg[$c]['sev'][$e['severity']] ?? 0) + 1;
        }
        foreach ($raw['problems'] as $p) {
            $c = $hostClient[$p['hostid']];
            $touch($c, 'zabbix');
            $agg[$c]['active']++;
            $agg[$c]['unacked'] += $p['acknowledged'] ? 0 : 1;
        }
        foreach ($agg as $n => &$a) {
            $a['groups'] = $a['kind'] === 'zabbix' ? [$n] : [];
        }
        unset($a);
        uasort($agg, fn ($a, $b) => ($b['tickets'] + $b['alerts']) <=> ($a['tickets'] + $a['alerts']) ?: strcasecmp($a['name'], $b['name']));

        // ---------------- filtros: cliente (entidade ou host group), host e chamado
        $clientKind = $clientSel !== '' && isset($agg[$clientSel]) ? $agg[$clientSel]['kind'] : null;
        $hostFilter = $clientKind === 'zabbix' ? $clientSel : '';
        $ticketFilter = $clientKind === 'glpi' ? $clientSel : '';

        $hostName = null;
        foreach ($zHosts as $h) {
            if ((string) $h['hostid'] === $hostid) {
                $hostName = $h['name'];
            }
        }
        $inScope = function ($hid) use ($hostid, $hostFilter, $hostClient): bool {
            $hid = (string) $hid;
            return (!$hostid || $hid === $hostid) && ($hostFilter === '' || ($hostClient[$hid] ?? null) === $hostFilter);
        };
        $matchT = function (array $t) use ($ticketFilter, $hostName, $ticketQ): bool {
            if ($ticketFilter !== '' && $t['client'] !== $ticketFilter) {
                return false;
            }
            if ($hostName !== null && stripos($t['name'], '] ' . $hostName . ':') === false) {
                return false;
            }
            if ($ticketQ !== '') {
                return ctype_digit(ltrim($ticketQ, '#')) ? (string) $t['id'] === ltrim($ticketQ, '#') : stripos($t['name'], $ticketQ) !== false;
            }
            return true;
        };
        $scopeHosts = array_values(array_filter($zHosts, fn ($h) => $inScope($h['hostid'])));
        $active = array_values(array_filter($raw['problems'], fn ($p) => $inScope($p['hostid'])));
        $events = array_values(array_filter($raw['events'], fn ($e) => $inScope($e['hostid'])));
        $P = array_values(array_filter($periodAll, $matchT));
        $O = array_values(array_filter($openAll, $matchT));

        // ---------------- alertas (Zabbix)
        $sevCount = array_fill_keys($sevs, 0);
        $byHost = [];
        $alertsPerDay = [];
        $detect = [];
        $repair = [];
        $hostSevCount = [];
        foreach ($events as $e) {
            $sevCount[$e['severity']] = ($sevCount[$e['severity']] ?? 0) + 1;
            $k = $e['hostid'] . '|' . $e['host'];
            $byHost[$k] = ($byHost[$k] ?? 0) + 1;
            $hostSevCount[$e['hostid']][$e['severity']] = ($hostSevCount[$e['hostid']][$e['severity']] ?? 0) + 1;
            $day = date('Y-m-d', $e['clock']);
            $alertsPerDay[$day] = ($alertsPerDay[$day] ?? 0) + 1;
            if ($e['detect'] !== null) {
                $detect[] = $e['detect'];
            }
            if ($e['repair'] !== null) {
                $repair[] = $e['repair'];
            }
        }
        arsort($byHost);
        $activeCount = array_fill_keys($sevs, 0);
        $unacked = 0;
        $perHost = [];
        $hostAct = [];
        $hostUn = [];
        foreach ($active as &$p) {
            $activeCount[$p['severity']] = ($activeCount[$p['severity']] ?? 0) + 1;
            $unacked += $p['acknowledged'] ? 0 : 1;
            $perHost[$p['hostid']][] = $p['severity'];
            $hostAct[$p['hostid']] = ($hostAct[$p['hostid']] ?? 0) + 1;
            $hostUn[$p['hostid']] = ($hostUn[$p['hostid']] ?? 0) + ($p['acknowledged'] ? 0 : 1);
            $p['url'] = $front . '/tr_events.php?triggerid=' . $p['objectid'] . '&eventid=' . $p['eventid'];
        }
        unset($p);

        // ---------------- chamados
        $byStatus = [];
        $byCategory = [];
        $ticketsPerDay = [];
        $tma = [];
        $tme = [];
        foreach ($P as $t) {
            $byStatus[$t['status']] = ($byStatus[$t['status']] ?? 0) + 1;
            $cat = $t['category'] !== '' ? $t['category'] : 'Sem categoria';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
            $d = substr($t['date'], 0, 10);
            $ticketsPerDay[$d] = ($ticketsPerDay[$d] ?? 0) + 1;
            if ($t['solvedate']) {
                $tma[] = $t['resolve_seconds'];
            }
            if ($t['take_seconds'] > 0) {
                $tme[] = $t['take_seconds'];
            }
        }
        arsort($byCategory);
        $seen = [];
        $list = [];
        foreach (array_merge($P, $O) as $t) {
            if (!isset($seen[$t['id']])) {
                $seen[$t['id']] = true;
                $list[] = $t;
            }
        }
        usort($list, fn ($a, $b) => $b['id'] <=> $a['id']);
        $list = array_slice($list, 0, Settings::int('list_max', 1, 5000));

        // detalhe de um chamado (quando o filtro tem um único resultado): evento do Zabbix citado no chamado
        $detail = null;
        if ($ticketQ !== '' && count($list) === 1) {
            $t = $list[0];
            preg_match('/eventid=(\d+)/', html_entity_decode(Glpi::ticketContent($t['id'])), $m);
            $t['eventid'] = $m[1] ?? null;
            $detail = ['ticket' => $t, 'event' => self::ticketEvent($t['eventid'] ?? null, $hostClient, $front)];
        }

        // ---------------- hosts
        $hostCards = [];
        foreach ($scopeHosts as $h) {
            $sev = $perHost[$h['hostid']] ?? [];
            $hostCards[] = ['hostid' => $h['hostid'], 'name' => $h['name'], 'status' => self::hostStatus($h), 'client' => $hostClient[$h['hostid']],
                'groups' => array_column($h['hostgroups'] ?? [], 'name'), 'problems' => count($sev), 'max_severity' => $sev ? max($sev) : null,
                'availability' => $raw['avail'][$h['hostid']] ?? null];
        }

        $days = [];
        for ($d = $from; $d <= $till; $d += 86400) {
            $k = date('Y-m-d', $d);
            $days[] = ['day' => $k, 'alerts' => $alertsPerDay[$k] ?? 0, 'tickets' => $ticketsPerDay[$k] ?? 0];
        }

        $topHosts = [];
        foreach (array_slice(array_keys($byHost), 0, 12) as $k) {
            [$id, $name] = explode('|', (string) $k, 2);
            $topHosts[] = ['hostid' => $id, 'host' => $name, 'alerts' => $byHost[$k], 'sev' => $hostSevCount[$id] ?? [],
                'active' => $hostAct[$id] ?? 0, 'unacked' => $hostUn[$id] ?? 0];
        }

        return [
            'generated_at' => time(),
            'entity'       => $scope['id'],
            'entity_name'  => $root,
            'entities'     => $scope['entities'],
            'filters'      => ['from' => $fromDate, 'to' => $toDate, 'hostid' => $hostid, 'host' => $hostName, 'ticket' => $ticketQ, 'client' => $clientSel],
            'severities'   => $sevs,
            'kpi'          => [
                'alerts' => count($events), 'alerts_by_sev' => $sevCount,
                'active' => count($active), 'active_by_sev' => $activeCount, 'unacked' => $unacked,
                'tickets' => count($P), 'backlog' => count($O),
                'pending' => count(array_filter($O, fn ($t) => $t['pending'])), 'overdue' => count(array_filter($O, fn ($t) => $t['late'])),
                'mttd_zbx' => self::avg($detect), 'mttd_zbx_n' => count($detect), 'mttd_glpi' => self::avg($tme), 'mttd_glpi_n' => count($tme),
                'mttr_zbx' => self::avg($repair), 'mttr_zbx_n' => count($repair), 'mttr_glpi' => self::avg($tma), 'mttr_glpi_n' => count($tma),
                'hosts' => count($hostCards), 'hosts_online' => count(array_filter($hostCards, fn ($h) => $h['status'] === 'online')),
                'hosts_offline' => count(array_filter($hostCards, fn ($h) => $h['status'] === 'offline')),
            ],
            'clients'             => array_values($agg),
            'timeline'            => $days,
            'top_hosts'           => $topHosts,
            'tickets_by_status'   => $byStatus,
            'tickets_by_category' => $byCategory,
            'hosts'               => $hostCards,
            'active'              => array_slice($active, 0, 600),
            'tickets_list'        => $list,
            'detail'              => $detail,
            'links'               => ['glpi' => Settings::glpiFront(), 'zabbix' => $front],
        ];
    }

    /* ------------------------------------------------------------------ alertas do navegador */

    public static function alertas(array $q): array
    {
        $now = time();
        $ttl = Settings::int('cache_seconds', 0, 600);
        $scope = Scope::resolve(self::entityParam($q));
        $groupIds = array_values(array_unique(array_column($scope['groups'], 'group_id')));
        $idx = self::hostIndex($scope, $ttl);

        $zbx = self::cached('alertas:z:' . $scope['id'] . ':' . implode(',', $scope['severities']) . ':' . implode(',', $groupIds), 10, function () use ($groupIds, $scope) {
            $w1 = Zabbix::batchStrict(['p' => ['problem.get', ['output' => ['eventid', 'r_eventid', 'r_clock', 'name', 'severity', 'clock', 'acknowledged'],
                'groupids' => $groupIds, 'severities' => $scope['severities'], 'recent' => true, 'sortfield' => ['eventid'], 'sortorder' => 'DESC', 'limit' => 400]]]);
            $hosts = [];
            if ($w1['p']) {
                $w2 = Zabbix::batchStrict(['e' => ['event.get', ['output' => ['eventid'], 'eventids' => array_column($w1['p'], 'eventid'), 'selectHosts' => ['hostid', 'name']]]]);
                foreach ($w2['e'] as $e) {
                    $hosts[$e['eventid']] = $e['hosts'][0] ?? null;
                }
            }
            return array_map(fn ($p) => ['eventid' => (int) $p['eventid'], 'r_eventid' => (int) $p['r_eventid'], 'r_clock' => (int) $p['r_clock'], 'name' => $p['name'],
                'severity' => (int) $p['severity'], 'clock' => (int) $p['clock'], 'ack' => $p['acknowledged'] === '1',
                'host' => $hosts[$p['eventid']]['name'] ?? '—', 'hostid' => $hosts[$p['eventid']]['hostid'] ?? null], $w1['p']);
        });
        $zbx = array_values(array_filter($zbx, fn ($p) => self::allowed($idx['sev'], $p['hostid'], $p['severity'])));

        $root = $scope['name'];
        $tickets = [];
        foreach (Glpi::tickets(Glpi::subtree($scope['id']), 0, 0, true) as $t) {
            $r = self::ticketRow($t, $now, $root);
            $tickets[] = ['id' => $r['id'], 'name' => $r['name'], 'date' => $r['date'], 'client' => $r['client'], 'status' => $r['status'], 'status_id' => $r['status_id'],
                'due' => $t['time_to_resolve'] ? strtotime($t['time_to_resolve']) : null, 'pending' => $r['pending']];
        }

        $a = Settings::alerts();
        $tipos = [];
        foreach (Settings::ALERT_TYPES as $k => [$label, $group, $icon, $color, $canais, $tone]) {
            $tipos[$k] = ['label' => $label, 'group' => $group, 'icon' => $icon, 'color' => $color,
                'tone' => $a['tipos'][$k]['tone'] ?? $tone, 'channels' => $a['tipos'][$k]['channels'] ?? $canais];
        }
        return [
            'ok' => true, 'now' => $now, 'entity' => $scope['id'], 'problems' => $zbx, 'tickets' => $tickets, 'tipos' => $tipos,
            'prefs' => ['poll' => max(10, $a['poll']), 'volume' => max(0, min(100, $a['volume'])) / 100, 'rate' => $a['voice_rate'],
                'popup_seconds' => $a['popup_seconds'], 'popup_max' => $a['popup_max'], 'mute_minutes' => $a['mute_minutes'],
                'quiet' => $a['quiet'], 'sla_warn' => $a['sla_warn_minutes']],
            'links' => ['zabbix' => Settings::zabbixFront(), 'glpi' => Settings::glpiFront()],
        ];
    }

    /* ------------------------------------------------------------------ históricos */

    public static function hostLog(array $q): array
    {
        $hid = preg_replace('/\D/', '', (string) ($q['hostid'] ?? ''));
        if ($hid === '') {
            throw new HttpError(400, 'hostid obrigatório');
        }
        $scope = Scope::resolve(self::entityParam($q));
        $idx = self::hostIndex($scope, Settings::int('cache_seconds', 0, 600));
        if (!isset($idx['client'][$hid])) {
            throw new HttpError(404, 'Host fora do escopo do painel');
        }
        $days = 30;
        $ev = Zabbix::call('event.get', ['output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid', 'acknowledged'], 'hostids' => [$hid],
            'source' => 0, 'object' => 0, 'value' => 1, 'severities' => $scope['severities'], 'time_from' => time() - $days * 86400,
            'sortfield' => ['clock'], 'sortorder' => 'DESC', 'limit' => 60]) ?? [];
        $rec = [];
        $ids = array_values(array_filter(array_column($ev, 'r_eventid'), fn ($i) => $i !== '0'));
        if ($ids) {
            foreach (Zabbix::call('event.get', ['output' => ['eventid', 'clock'], 'eventids' => $ids]) ?? [] as $r) {
                $rec[$r['eventid']] = (int) $r['clock'];
            }
        }
        $front = Settings::zabbixFront();
        $items = array_map(fn ($e) => ['eventid' => $e['eventid'], 'clock' => (int) $e['clock'], 'severity' => (int) $e['severity'], 'name' => $e['name'],
            'acknowledged' => $e['acknowledged'] === '1', 'end' => $rec[$e['r_eventid']] ?? null,
            'url' => $front . '/tr_events.php?triggerid=' . $e['objectid'] . '&eventid=' . $e['eventid']], $ev);
        return ['host' => $idx['names'][$hid] ?? '', 'days' => $days, 'events' => $items,
            'url' => $front . '/zabbix.php?action=problem.view&hostids%5B%5D=' . $hid . '&filter_set=1'];
    }

    public static function ticketLog(array $q): array
    {
        $id = (int) ($q['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpError(400, 'id obrigatório');
        }
        $scope = Scope::resolve(self::entityParam($q));
        if (Glpi::ticketIn($id, Glpi::subtree($scope['id'])) === null) {
            throw new HttpError(404, 'Chamado fora do escopo do painel');
        }
        $items = Glpi::history($id);
        foreach ($items as &$o) {
            $o['text'] = self::plainText($o['text']);
        }
        unset($o);
        return ['id' => $id, 'items' => $items];
    }

    /* ------------------------------------------------------------------ Zabbix: hosts do escopo */

    /**
     * Hosts dos host groups da entidade, com o host group do cliente (o primeiro do mapa que casar)
     * e as severidades permitidas para cada host (união das severidades dos seus host groups).
     */
    private static function hostIndex(array $scope, int $ttl): array
    {
        $groupIds = array_values(array_unique(array_column($scope['groups'], 'group_id')));
        return self::cached('hosts:' . $scope['id'] . ':' . md5(json_encode($scope['groups'])), $ttl, function () use ($scope, $groupIds) {
            $hosts = Zabbix::batchStrict(['h' => ['host.get', ['output' => ['hostid', 'host', 'name', 'active_available'], 'groupids' => $groupIds,
                'filter' => ['status' => 0], 'selectInterfaces' => ['type', 'available'], 'selectHostGroups' => ['groupid', 'name'], 'sortfield' => 'name']]])['h'];
            $names = [];
            foreach ($hosts as $h) {
                foreach ($h['hostgroups'] ?? [] as $g) {
                    $names[$g['groupid']] = $g['name'];
                }
            }
            $client = [];
            $sev = [];
            $kept = [];
            foreach ($hosts as $h) {
                $mine = array_column($h['hostgroups'] ?? [], 'groupid');
                $c = null;
                $allowed = [];
                foreach ($scope['groups'] as $g) {
                    if (in_array($g['group_id'], $mine, true)) {
                        $c ??= $names[$g['group_id']] ?? ($g['group_name'] !== '' ? $g['group_name'] : $g['group_id']);
                        array_push($allowed, ...$g['severities']);
                    }
                }
                if ($c === null) {
                    continue;
                }
                $client[$h['hostid']] = $c;
                $sev[$h['hostid']] = array_values(array_unique($allowed));
                $kept[] = $h;
            }
            return ['hosts' => $kept, 'client' => $client, 'sev' => $sev, 'names' => self::hostNames($kept), 'groupnames' => $names];
        });
    }

    /** id do host => nome (para o histórico). */
    private static function hostNames(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $h) {
            $out[$h['hostid']] = $h['name'];
        }
        return $out;
    }

    /** Alertas do Zabbix (problemas, eventos do período e disponibilidade ICMP), em cache. */
    private static function zabbixRaw(array $groupIds, array $sevAll, int $from, int $till, int $ttl): array
    {
        return self::cached('zraw:' . md5(json_encode([$groupIds, $sevAll, $from, $till])), $ttl, function () use ($groupIds, $sevAll, $from, $till) {
            $w1 = Zabbix::batchStrict([
                'problems' => ['problem.get', ['output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged'], 'groupids' => $groupIds,
                    'severities' => $sevAll, 'sortfield' => ['eventid'], 'sortorder' => 'DESC']],
                'events' => ['event.get', ['output' => ['eventid', 'clock', 'severity', 'name', 'r_eventid', 'acknowledged'], 'groupids' => $groupIds,
                    'source' => 0, 'object' => 0, 'value' => 1, 'severities' => $sevAll, 'time_from' => $from, 'time_till' => $till,
                    'selectHosts' => ['hostid', 'name'], 'selectAcknowledges' => ['clock', 'action', 'username', 'name', 'surname'],
                    'sortfield' => ['clock'], 'sortorder' => 'ASC', 'limit' => 20000]],
                'icmp' => ['item.get', ['output' => ['itemid', 'hostid'], 'groupids' => $groupIds, 'filter' => ['key_' => 'icmpping'], 'monitored' => true]],
            ]);
            $recIds = array_values(array_filter(array_column($w1['events'], 'r_eventid'), fn ($id) => $id !== '0'));
            $w2 = [];
            if ($w1['problems']) {
                $w2['phosts'] = ['event.get', ['output' => ['eventid'], 'eventids' => array_column($w1['problems'], 'eventid'), 'selectHosts' => ['hostid', 'name']]];
            }
            foreach (array_chunk($recIds, 1000) as $i => $chunk) {
                $w2["rec$i"] = ['event.get', ['output' => ['eventid', 'clock'], 'eventids' => $chunk]];
            }
            if ($w1['icmp']) {
                $w2['trend'] = ['trend.get', ['output' => ['itemid', 'num', 'value_avg'], 'itemids' => array_column($w1['icmp'], 'itemid'), 'time_from' => $from, 'time_till' => $till]];
            }
            $w2 = $w2 ? Zabbix::batchStrict($w2) : [];

            $probHost = [];
            foreach ($w2['phosts'] ?? [] as $e) {
                $probHost[$e['eventid']] = $e['hosts'][0] ?? null;
            }
            $problems = [];
            foreach ($w1['problems'] as $p) {
                $h = $probHost[$p['eventid']] ?? null;
                $problems[] = ['eventid' => $p['eventid'], 'objectid' => $p['objectid'], 'name' => $p['name'], 'severity' => (int) $p['severity'],
                    'clock' => (int) $p['clock'], 'acknowledged' => $p['acknowledged'] === '1', 'host' => $h['name'] ?? '—', 'hostid' => $h['hostid'] ?? null];
            }
            $recClock = [];
            foreach ($w2 as $k => $v) {
                if (str_starts_with((string) $k, 'rec')) {
                    foreach ($v as $r) {
                        $recClock[$r['eventid']] = (int) $r['clock'];
                    }
                }
            }
            $events = [];
            foreach ($w1['events'] as $e) {
                $acks = array_values(array_filter($e['acknowledges'] ?? [], fn ($a) => ((int) $a['action'] & 2) === 2));
                $who = fn ($a) => trim(trim(($a['name'] ?? '') . ' ' . ($a['surname'] ?? '')) ?: (string) ($a['username'] ?? ''));
                usort($acks, fn ($a, $b) => (int) $a['clock'] <=> (int) $b['clock']);
                $events[] = ['clock' => (int) $e['clock'], 'severity' => (int) $e['severity'], 'hostid' => $e['hosts'][0]['hostid'] ?? null,
                    'host' => $e['hosts'][0]['name'] ?? '—', 'acknowledged' => $e['acknowledged'] === '1',
                    'detect' => $acks ? (int) $acks[0]['clock'] - (int) $e['clock'] : null,
                    'repair' => isset($recClock[$e['r_eventid']]) ? $recClock[$e['r_eventid']] - (int) $e['clock'] : null];
            }
            $byItem = array_column($w1['icmp'], 'hostid', 'itemid');
            $acc = [];
            foreach ($w2['trend'] ?? [] as $t) {
                $h = $byItem[$t['itemid']];
                $acc[$h]['sum'] = ($acc[$h]['sum'] ?? 0) + $t['value_avg'] * $t['num'];
                $acc[$h]['num'] = ($acc[$h]['num'] ?? 0) + $t['num'];
            }
            $avail = [];
            foreach ($acc as $h => $a) {
                $avail[$h] = $a['num'] ? round(100 * $a['sum'] / $a['num'], 2) : null;
            }
            return ['problems' => $problems, 'events' => $events, 'avail' => $avail];
        });
    }

    /** Evento do Zabbix citado no chamado, se o host dele está no escopo. */
    private static function ticketEvent(?string $eventid, array $hostClient, string $front): ?array
    {
        if (!$eventid) {
            return null;
        }
        $ev = Zabbix::call('event.get', ['output' => ['eventid', 'clock', 'name', 'severity', 'r_eventid', 'acknowledged'], 'eventids' => [$eventid],
            'selectHosts' => ['hostid', 'name'], 'selectAcknowledges' => ['clock', 'message', 'action', 'username', 'name', 'surname']]);
        if (!$ev) {
            return null;
        }
        $e = $ev[0];
        $hid = $e['hosts'][0]['hostid'] ?? null;
        if ($hid === null || !isset($hostClient[$hid])) {
            return null;
        }
        $rec = $e['r_eventid'] !== '0' ? Zabbix::call('event.get', ['output' => ['clock'], 'eventids' => [$e['r_eventid']]]) : [];
        return ['eventid' => $e['eventid'], 'name' => $e['name'], 'severity' => (int) $e['severity'],
            'host' => $e['hosts'][0]['name'] ?? null, 'start' => (int) $e['clock'],
            'end' => $rec ? (int) $rec[0]['clock'] : null,
            'acks' => array_map(fn ($a) => ['clock' => (int) $a['clock'], 'message' => $a['message'],
                'user' => trim(($a['name'] ?? '') . ' ' . ($a['surname'] ?? '')) ?: ($a['username'] ?? '')], $e['acknowledges']),
            'url' => $front . '/tr_events.php?eventid=' . $e['eventid']];
    }

    /* ------------------------------------------------------------------ utilidades */

    /** Linha do chamado com entidade (cliente), atraso e pendência calculados. */
    private static function ticketRow(array $t, int $now, string $root): array
    {
        $st = $t['status'];
        $opened = strtotime($t['date']);
        $ttr = $t['time_to_resolve'] ? strtotime($t['time_to_resolve']) : null;
        $target = $ttr ?: $opened + 3600 * (Settings::slaHours()[$t['priority']] ?? 24);
        $slaOk = $t['solvedate'] ? strtotime($t['solvedate']) <= $target : null;
        return ['id' => $t['id'], 'name' => $t['name'], 'status' => Glpi::STATUS[$st] ?? (string) $st, 'status_id' => $st,
            'priority' => Glpi::PRIORITY[$t['priority']] ?? (string) $t['priority'], 'priority_id' => $t['priority'],
            'entity' => $t['entity'], 'client' => Glpi::clientOf((string) $t['entity'], $root), 'category' => $t['category'], 'date' => $t['date'],
            'solvedate' => $t['solvedate'], 'resolve_seconds' => $t['solvedate'] ? $t['solve_delay_stat'] : null, 'take_seconds' => $t['takeintoaccount_delay_stat'],
            'sla_ok' => $slaOk, 'sla_target' => date('Y-m-d H:i:s', $target), 'due' => $ttr ? date('Y-m-d H:i:s', $ttr) : null,
            'group' => $t['group'] !== '' ? $t['group'] : 'Sem grupo', 'tech' => $t['tech'] !== '' ? $t['tech'] : 'Sem responsável',
            'open' => $st < 5, 'pending' => $st === 4, 'late' => $st < 5 && $ttr !== null && $ttr < $now];
    }

    private static function hostStatus(array $host): string
    {
        $states = array_column($host['interfaces'] ?? [], 'available');
        if ((string) ($host['active_available'] ?? '0') === '1') {
            $states[] = '1';
        }
        if (in_array('2', $states, true)) {
            return in_array('1', $states, true) ? 'parcial' : 'offline';
        }
        return in_array('1', $states, true) ? 'online' : 'desconhecido';
    }

    /** O host tem o alerta com essa severidade permitida pelos host groups dele? */
    private static function allowed(array $hostSev, mixed $hostid, mixed $severity): bool
    {
        return $hostid !== null && isset($hostSev[$hostid]) && in_array((int) $severity, $hostSev[$hostid], true);
    }

    /** @return array{0:string,1:string} datas YYYY-MM-DD, com validação e período máximo de 1 ano. */
    private static function dates(array $q): array
    {
        $from = $q['from'] ?? date('Y-m-d', strtotime('-6 days'));
        $to = $q['to'] ?? date('Y-m-d');
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) {
                throw new HttpError(400, 'Data inválida');
            }
        }
        if (strtotime("$to 23:59:59") < strtotime("$from 00:00:00") || strtotime("$to") - strtotime("$from") > 366 * 86400) {
            throw new HttpError(400, 'Período inválido (máximo de 1 ano)');
        }
        return [(string) $from, (string) $to];
    }

    private static function entityParam(array $q): ?int
    {
        $e = $q['entity'] ?? null;
        return ($e !== null && ctype_digit((string) $e)) ? (int) $e : null;
    }

    private static function avg(array $values): ?float
    {
        return $values ? array_sum($values) / count($values) : null;
    }

    /** Guarda em arquivo temporário o resultado de $fn por $ttl segundos (0 = sem cache). */
    private static function cached(string $key, int $ttl, callable $fn): mixed
    {
        $file = sys_get_temp_dir() . '/op360_' . md5($key);
        if ($ttl > 0 && is_file($file) && time() - (int) filemtime($file) < $ttl) {
            $raw = (string) file_get_contents($file);
            $value = json_decode($raw, true);
            if ($value !== null) {
                return $value;
            }
        }
        $value = $fn();
        if ($ttl > 0) {
            @file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        return $value;
    }

    /** HTML do GLPI para texto simples (histórico do chamado). */
    private static function plainText(?string $html): string
    {
        $t = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('#<\s*(br|/p|/div|/li|/tr)\s*/?>#i', "\n", $t);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', (string) $t);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) $t));
    }
}
