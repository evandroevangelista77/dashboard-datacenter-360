<?php
/**
 * DataCenter 360 — backend (somente leitura).
 *
 * Junta dados da API do Zabbix (token só de leitura) e do banco do GLPI
 * (usuário só com SELECT). Credenciais em /etc/datacenter360/config.php,
 * fora da pasta web.
 *
 * GET api.php?action=hosts
 * GET api.php?action=data&from=AAAA-MM-DD&to=AAAA-MM-DD[&hostid=N][&ticket=ID|texto]
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = require '/etc/datacenter360/config.php';
date_default_timezone_set($cfg['timezone']);

const STATUS_GLPI = [1 => 'Novo', 2 => 'Em atendimento', 3 => 'Planejado', 4 => 'Pendente', 5 => 'Solucionado', 6 => 'Fechado'];
const PRIORIDADE_GLPI = [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta', 6 => 'Crítica'];

function fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function zbx(string $method, array $params = []): mixed
{
    global $cfg;
    $ch = curl_init($cfg['zabbix_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc', 'Authorization: Bearer ' . $cfg['zabbix_token']],
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params ?: new stdClass(), 'id' => 1]),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('Zabbix inacessível: ' . curl_error($ch));
    }
    $resp = json_decode($raw, true);
    if (isset($resp['error'])) {
        throw new RuntimeException("Zabbix $method: " . ($resp['error']['data'] ?? $resp['error']['message']));
    }
    return $resp['result'];
}

/** Versões em execução (apiinfo.version recusa autenticação; versão do GLPI vem do banco). */
function versions(): array
{
    global $cfg;
    $ch = curl_init($cfg['zabbix_url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc'],
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => 'apiinfo.version', 'params' => new stdClass(), 'id' => 1])]);
    $zbx = json_decode((string)curl_exec($ch), true)['result'] ?? null;
    $glpi = rows("SELECT value FROM glpi_configs WHERE context = 'core' AND name = 'version'")[0]['value'] ?? null;
    return ['zabbix' => $zbx, 'glpi' => $glpi];
}

function db(): mysqli
{
    static $conn = null;
    global $cfg;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli($cfg['db']['host'], $cfg['db']['user'], $cfg['db']['pass'], $cfg['db']['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function rows(string $sql, array $args = []): array
{
    $stmt = db()->prepare($sql);
    if ($args) {
        $stmt->bind_param(str_repeat('s', count($args)), ...array_map('strval', $args));
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function avg(array $values): ?float
{
    return $values ? array_sum($values) / count($values) : null;
}

/** Disponibilidade por host = média do icmpping (trends horárias) no período. */
function availability(array $hostids, int $from, int $till): array
{
    $items = zbx('item.get', ['output' => ['itemid', 'hostid'], 'hostids' => $hostids, 'filter' => ['key_' => 'icmpping'], 'monitored' => true]);
    if (!$items) {
        return [];
    }
    $byItem = array_column($items, 'hostid', 'itemid');
    $acc = [];
    foreach (zbx('trend.get', ['output' => ['itemid', 'num', 'value_avg'], 'itemids' => array_keys($byItem), 'time_from' => $from, 'time_till' => $till]) as $t) {
        $h = $byItem[$t['itemid']];
        $acc[$h]['sum'] = ($acc[$h]['sum'] ?? 0) + $t['value_avg'] * $t['num'];
        $acc[$h]['num'] = ($acc[$h]['num'] ?? 0) + $t['num'];
    }
    $out = [];
    foreach ($acc as $h => $a) {
        $out[$h] = $a['num'] ? round(100 * $a['sum'] / $a['num'], 2) : null;
    }
    return $out;
}

function hostStatus(array $host): string
{
    $states = array_column($host['interfaces'] ?? [], 'available');
    if ((string)($host['active_available'] ?? '0') === '1') {
        $states[] = '1';
    }
    if (in_array('2', $states, true)) {
        return in_array('1', $states, true) ? 'parcial' : 'offline';
    }
    return in_array('1', $states, true) ? 'online' : 'desconhecido';
}

function hosts(): array
{
    return zbx('host.get', [
        'output' => ['hostid', 'host', 'name', 'active_available'],
        'filter' => ['status' => 0],
        'selectInterfaces' => ['type', 'available'],
        'selectHostGroups' => ['name'],
        'sortfield' => 'name',
    ]);
}

/** Métricas do banco (MariaDB/MySQL) coletadas pelo Zabbix no host configurado. */
function dbMetrics(): array
{
    global $cfg;
    $h = zbx('host.get', ['output' => ['hostid', 'name'], 'filter' => ['host' => $cfg['db_metrics_host']]]);
    if (!$h) {
        return ['host' => null];
    }
    $items = zbx('item.get', ['output' => ['itemid', 'key_', 'lastvalue', 'lastclock', 'name'], 'hostids' => $h[0]['hostid'],
        'search' => ['key_' => ['mysql.', 'glpi.', 'web.test.fail', 'web.test.time']], 'searchByAny' => true, 'webitems' => true]);
    $v = [];
    $ids = [];
    foreach ($items as $i) {
        $key = preg_replace('/\[.*$/', '', $i['key_']);
        if (str_starts_with($i['key_'], 'mysql.db.size')) {
            $v['db_size'][$i['name']] = (float)$i['lastvalue'];
            continue;
        }
        if ($key === 'web.test.time' && !str_contains($i['key_'], ',resp]')) {
            continue;
        }
        $v[$key] = is_numeric($i['lastvalue']) ? (float)$i['lastvalue'] : $i['lastvalue'];
        $ids[$key] = $i['itemid'];
    }
    // max_connections vem do próprio servidor de banco (o template não coleta)
    $maxConn = (int)(rows("SHOW GLOBAL VARIABLES LIKE 'max_connections'")[0]['Value'] ?? 0);
    $spark = [];
    if (isset($ids['mysql.queries.rate'])) {
        foreach (zbx('history.get', ['output' => ['clock', 'value'], 'itemids' => $ids['mysql.queries.rate'], 'history' => 0,
            'time_from' => time() - 3600, 'sortfield' => 'clock']) as $p) {
            $spark[] = [(int)$p['clock'] * 1000, round((float)$p['value'], 3)];
        }
    }
    $glpiDb = null;
    foreach ($v['db_size'] ?? [] as $name => $size) {
        if (str_ends_with($name, ' ' . $cfg['db']['name'])) {
            $glpiDb = $size;
        }
    }
    return [
        'host' => $h[0]['name'],
        'online' => ($v['mysql.ping'] ?? 0) == 1,
        'version' => $v['mysql.version'] ?? null,
        'uptime' => $v['mysql.uptime'] ?? null,
        'buffer_efficiency' => isset($v['mysql.buffer_pool_efficiency']) ? round($v['mysql.buffer_pool_efficiency'], 2) : null,
        'buffer_utilization' => isset($v['mysql.buffer_pool_utilization']) ? round($v['mysql.buffer_pool_utilization'], 2) : null,
        'connections' => $v['mysql.threads_connected'] ?? null,
        'max_connections' => $maxConn ?: null,
        'connections_pct' => ($maxConn && isset($v['mysql.threads_connected'])) ? round(100 * $v['mysql.threads_connected'] / $maxConn, 2) : null,
        'threads_running' => $v['mysql.threads_running'] ?? null,
        'qps' => isset($v['mysql.queries.rate']) ? round($v['mysql.queries.rate'], 2) : null,
        'slow_qps' => isset($v['mysql.slow_queries.rate']) ? round($v['mysql.slow_queries.rate'], 3) : null,
        'qps_history' => $spark,
        'glpidb_size' => $glpiDb,
        'app' => [
            'glpi' => $v['glpi.status'] ?? null,
            'database' => $v['glpi.db.status'] ?? null,
            'crontasks' => $v['glpi.crontasks.status'] ?? null,
            'filesystem' => $v['glpi.filesystem.status'] ?? null,
            'login_ok' => isset($v['web.test.fail']) ? ((int)$v['web.test.fail'] === 0) : null,
            'login_time' => isset($v['web.test.time']) ? round((float)$v['web.test.time'], 3) : null,
        ],
    ];
}

function slas(int $from, int $till): array
{
    $out = [];
    foreach (zbx('sla.get', ['output' => ['slaid', 'name', 'slo', 'status']]) as $sla) {
        $sli = null;
        $services = 0;
        try {
            $r = zbx('sla.getsli', ['slaid' => $sla['slaid'], 'period_from' => $from, 'period_to' => $till + 1]);
            $services = count($r['serviceids']);
            $vals = [];
            foreach ($r['sli'] as $period) {
                foreach ($period as $svc) {
                    $vals[] = (float)$svc['sli'];
                }
            }
            $sli = $vals ? round(avg($vals), 3) : null;
        } catch (RuntimeException) {
            // SLA sem serviços visíveis: mostra só o SLO
        }
        $out[] = ['name' => $sla['name'], 'slo' => (float)$sla['slo'], 'sli' => $sli, 'services' => $services, 'enabled' => $sla['status'] === '1'];
    }
    return $out;
}

function data(): array
{
    global $cfg;
    $fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-6 days'));
    $toDate = $_GET['to'] ?? date('Y-m-d');
    foreach ([$fromDate, $toDate] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            fail(400, 'Data inválida');
        }
    }
    $from = strtotime("$fromDate 00:00:00");
    $till = strtotime("$toDate 23:59:59");
    if ($till < $from || $till - $from > 366 * 86400) {
        fail(400, 'Período inválido (máximo de 1 ano)');
    }
    $hostid = isset($_GET['hostid']) && ctype_digit($_GET['hostid']) ? $_GET['hostid'] : null;
    $ticketQ = trim((string)($_GET['ticket'] ?? ''));

    // ---------------- Zabbix: hosts e problemas ativos
    $allHosts = hosts();
    $hostName = null;
    foreach ($allHosts as $h) {
        if ($h['hostid'] === $hostid) {
            $hostName = $h['name'];
        }
    }
    $scopeHosts = $hostid ? array_values(array_filter($allHosts, fn($h) => $h['hostid'] === $hostid)) : $allHosts;
    $hostids = array_column($scopeHosts, 'hostid');

    $problemParams = ['output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged'], 'sortfield' => ['eventid'], 'sortorder' => 'DESC'];
    if ($hostid) {
        $problemParams['hostids'] = [$hostid];
    }
    $problems = zbx('problem.get', $problemParams);
    $problemHosts = [];
    if ($problems) {
        foreach (zbx('event.get', ['output' => ['eventid'], 'eventids' => array_column($problems, 'eventid'), 'selectHosts' => ['hostid', 'name']]) as $e) {
            $problemHosts[$e['eventid']] = $e['hosts'][0] ?? null;
        }
    }
    $perHostProblems = [];
    $active = [];
    foreach ($problems as $p) {
        $h = $problemHosts[$p['eventid']] ?? null;
        if ($h) {
            $perHostProblems[$h['hostid']][] = (int)$p['severity'];
        }
        $active[] = ['eventid' => $p['eventid'], 'name' => $p['name'], 'severity' => (int)$p['severity'], 'clock' => (int)$p['clock'],
            'acknowledged' => $p['acknowledged'] === '1', 'host' => $h['name'] ?? '—', 'hostid' => $h['hostid'] ?? null];
    }

    $avail = availability($hostids, $from, $till);
    $hostCards = [];
    foreach ($scopeHosts as $h) {
        $sev = $perHostProblems[$h['hostid']] ?? [];
        $hostCards[] = ['hostid' => $h['hostid'], 'name' => $h['name'], 'status' => hostStatus($h),
            'groups' => array_column($h['hostgroups'] ?? [], 'name'), 'problems' => count($sev),
            'max_severity' => $sev ? max($sev) : null, 'availability' => $avail[$h['hostid']] ?? null];
    }

    // ---------------- Zabbix: eventos (alertas) do período, MTTD e MTTR
    $evParams = ['output' => ['eventid', 'clock', 'severity', 'name', 'r_eventid'], 'source' => 0, 'object' => 0, 'value' => 1,
        'time_from' => $from, 'time_till' => $till, 'selectHosts' => ['hostid', 'name'], 'selectAcknowledges' => ['clock', 'action'],
        'sortfield' => ['clock'], 'sortorder' => 'ASC', 'limit' => 20000];
    if ($hostid) {
        $evParams['hostids'] = [$hostid];
    }
    $events = zbx('event.get', $evParams);
    $recIds = array_values(array_filter(array_column($events, 'r_eventid'), fn($id) => $id !== '0'));
    $recClock = [];
    foreach (array_chunk($recIds, 1000) as $chunk) {
        foreach (zbx('event.get', ['output' => ['eventid', 'clock'], 'eventids' => $chunk]) as $r) {
            $recClock[$r['eventid']] = (int)$r['clock'];
        }
    }
    $detect = $repair = [];
    $bySeverity = array_fill(0, 6, 0);
    $byHost = [];
    $alertsPerDay = [];
    foreach ($events as $e) {
        $clock = (int)$e['clock'];
        $bySeverity[(int)$e['severity']]++;
        $hn = $e['hosts'][0]['name'] ?? '—';
        $byHost[$hn] = ($byHost[$hn] ?? 0) + 1;
        $day = date('Y-m-d', $clock);
        $alertsPerDay[$day] = ($alertsPerDay[$day] ?? 0) + 1;
        $acks = array_filter($e['acknowledges'] ?? [], fn($a) => ((int)$a['action'] & 2) === 2);
        if ($acks) {
            $detect[] = min(array_map(fn($a) => (int)$a['clock'], $acks)) - $clock;
        }
        if (isset($recClock[$e['r_eventid']])) {
            $repair[] = $recClock[$e['r_eventid']] - $clock;
        }
    }
    arsort($byHost);

    // ---------------- GLPI: chamados do período
    $where = ['t.is_deleted = 0', 't.date BETWEEN ? AND ?'];
    $args = [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $till)];
    if ($hostName !== null) {
        // título gerado pela integração: "[Severidade] HOST: problema"
        $where[] = 't.name LIKE ?';
        $args[] = '%] ' . addcslashes($hostName, '%_\\') . ':%';
    }
    if ($ticketQ !== '') {
        if (ctype_digit(ltrim($ticketQ, '#'))) {
            $where[] = 't.id = ?';
            $args[] = ltrim($ticketQ, '#');
        } else {
            $where[] = 't.name LIKE ?';
            $args[] = '%' . addcslashes($ticketQ, '%_\\') . '%';
        }
    }
    $sql = 'SELECT t.id, t.name, t.status, t.priority, t.urgency, t.type, t.date, t.solvedate, t.time_to_resolve,
                   t.solve_delay_stat, t.takeintoaccount_delay_stat, t.content,
                   e.completename AS entity, c.completename AS category
            FROM glpi_tickets t
            LEFT JOIN glpi_entities e ON e.id = t.entities_id
            LEFT JOIN glpi_itilcategories c ON c.id = t.itilcategories_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC';
    $tickets = rows($sql, $args);

    $byStatus = [];
    $byEntity = [];
    $byCategory = [];
    $ticketsPerDay = [];
    $tma = $tme = [];
    $slaMet = $slaTotal = 0;
    $list = [];
    foreach ($tickets as $t) {
        $st = (int)$t['status'];
        $byStatus[STATUS_GLPI[$st] ?? $st] = ($byStatus[STATUS_GLPI[$st] ?? $st] ?? 0) + 1;
        $byEntity[$t['entity'] ?? '—'] = ($byEntity[$t['entity'] ?? '—'] ?? 0) + 1;
        $cat = $t['category'] ?: 'Sem categoria';
        $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
        $day = substr($t['date'], 0, 10);
        $ticketsPerDay[$day] = ($ticketsPerDay[$day] ?? 0) + 1;

        $opened = strtotime($t['date']);
        $target = $t['time_to_resolve'] ? strtotime($t['time_to_resolve'])
            : $opened + 3600 * ($cfg['sla_hours'][(int)$t['priority']] ?? 24);
        $slaOk = null;
        if ($t['solvedate']) {
            $tma[] = (int)$t['solve_delay_stat'];
            $slaOk = strtotime($t['solvedate']) <= $target;
            $slaTotal++;
            $slaMet += $slaOk ? 1 : 0;
        }
        if ((int)$t['takeintoaccount_delay_stat'] > 0) {
            $tme[] = (int)$t['takeintoaccount_delay_stat'];
        }
        preg_match('/eventid=(\d+)/', html_entity_decode((string)$t['content']), $m);
        if (count($list) < 200) {
            $list[] = ['id' => (int)$t['id'], 'name' => $t['name'], 'status' => STATUS_GLPI[$st] ?? (string)$st, 'status_id' => $st,
                'priority' => PRIORIDADE_GLPI[(int)$t['priority']] ?? $t['priority'], 'priority_id' => (int)$t['priority'],
                'entity' => $t['entity'], 'category' => $t['category'], 'date' => $t['date'], 'solvedate' => $t['solvedate'],
                'resolve_seconds' => $t['solvedate'] ? (int)$t['solve_delay_stat'] : null, 'sla_ok' => $slaOk,
                'sla_target' => date('Y-m-d H:i:s', $target), 'eventid' => $m[1] ?? null];
        }
    }
    arsort($byEntity);
    arsort($byCategory);

    // Backlog atual (independente do período, mas respeitando host/chamado)
    $bWhere = array_values(array_filter($where, fn($w) => !str_starts_with($w, 't.date')));
    $bArgs = array_slice($args, 2);
    $backlog = (int)rows('SELECT COUNT(*) n FROM glpi_tickets t WHERE ' . implode(' AND ', $bWhere) . ' AND t.status < 5', $bArgs)[0]['n'];

    // Detalhe de um chamado (filtro por chamado com um único resultado)
    $detail = null;
    if ($ticketQ !== '' && count($tickets) === 1) {
        $t = $list[0];
        $detail = ['ticket' => $t, 'event' => null];
        if ($t['eventid']) {
            $ev = zbx('event.get', ['output' => ['eventid', 'clock', 'name', 'severity', 'r_eventid', 'acknowledged'], 'eventids' => [$t['eventid']],
                'selectHosts' => ['name'], 'selectAcknowledges' => ['clock', 'message', 'action', 'username', 'name', 'surname']]);
            if ($ev) {
                $e = $ev[0];
                $rec = $e['r_eventid'] !== '0' ? zbx('event.get', ['output' => ['clock'], 'eventids' => [$e['r_eventid']]]) : [];
                $detail['event'] = ['eventid' => $e['eventid'], 'name' => $e['name'], 'severity' => (int)$e['severity'],
                    'host' => $e['hosts'][0]['name'] ?? null, 'start' => (int)$e['clock'],
                    'end' => $rec ? (int)$rec[0]['clock'] : null,
                    'acks' => array_map(fn($a) => ['clock' => (int)$a['clock'], 'message' => $a['message'],
                        'user' => trim(($a['name'] ?? '') . ' ' . ($a['surname'] ?? '')) ?: ($a['username'] ?? '')], $e['acknowledges']),
                    'url' => rtrim($cfg['zabbix_front'], '/') . '/tr_events.php?eventid=' . $e['eventid']];
            }
        }
    }

    // Série diária comum aos dois gráficos
    $days = [];
    for ($d = $from; $d <= $till; $d += 86400) {
        $k = date('Y-m-d', $d);
        $days[] = ['day' => $k, 'alerts' => $alertsPerDay[$k] ?? 0, 'tickets' => $ticketsPerDay[$k] ?? 0];
    }

    $online = count(array_filter($hostCards, fn($h) => $h['status'] === 'online'));
    $availVals = array_values(array_filter(array_column($hostCards, 'availability'), fn($v) => $v !== null));

    return [
        'generated_at' => time(),
        'versions' => versions(),
        'filters' => ['from' => $fromDate, 'to' => $toDate, 'hostid' => $hostid, 'host' => $hostName, 'ticket' => $ticketQ],
        'kpi' => [
            'alerts' => count($events),
            'active_problems' => count($active),
            'tickets' => count($tickets),
            'backlog' => $backlog,
            'hosts' => count($hostCards),
            'hosts_online' => $online,
            'hosts_offline' => count(array_filter($hostCards, fn($h) => $h['status'] === 'offline')),
            'sla_pct' => $slaTotal ? round(100 * $slaMet / $slaTotal, 1) : null,
            'sla_met' => $slaMet,
            'sla_total' => $slaTotal,
            'tma' => avg($tma),
            'tme' => avg($tme),
            'mttd' => avg($detect),
            'mttd_samples' => count($detect),
            'mttr' => avg($repair),
            'mttr_samples' => count($repair),
            'availability' => $availVals ? round(avg($availVals), 2) : null,
        ],
        'severity' => $bySeverity,
        'active_by_severity' => array_count_values(array_map('strval', array_column($active, 'severity'))),
        'timeline' => $days,
        'top_hosts' => array_slice(array_map(fn($k, $v) => ['host' => $k, 'alerts' => $v], array_keys($byHost), $byHost), 0, 10),
        'tickets_by_status' => $byStatus,
        'tickets_by_entity' => $byEntity,
        'tickets_by_category' => $byCategory,
        'hosts' => $hostCards,
        'active' => array_slice($active, 0, 100),
        'tickets_list' => $list,
        'detail' => $detail,
        'database' => dbMetrics(),
        'slas' => slas($from, $till),
        'sla_hours' => $cfg['sla_hours'],
        'links' => ['glpi' => rtrim($cfg['glpi_front'], '/'), 'zabbix' => rtrim($cfg['zabbix_front'], '/')],
    ];
}

try {
    $action = $_GET['action'] ?? 'data';
    $out = match ($action) {
        'hosts' => array_map(fn($h) => ['hostid' => $h['hostid'], 'name' => $h['name']], hosts()),
        'data' => data(),
        default => fail(400, 'Ação desconhecida'),
    };
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[DataCenter360] ' . $e->getMessage());
    fail(502, $e->getMessage());
}
