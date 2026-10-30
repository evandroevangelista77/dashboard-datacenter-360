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

/** Tempo de cada etapa (aparece na resposta só com &debug=1). */
function tick(string $etapa): void
{
    static $t0 = null, $last = null;
    global $TIMINGS;
    $now = microtime(true);
    $t0 ??= $now;
    $TIMINGS[$etapa] = round($now - ($last ?? $t0), 2);
    $last = $now;
}
$TIMINGS = [];

function fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Trava de somente leitura: o painel só consulta. Mesmo com um token de perfil mais amplo, nada é alterado no Zabbix. */
function zbxGuard(string $method): void
{
    if (!preg_match('/\.get$|^sla\.getsli$|^apiinfo\.version$/', $method)) {
        throw new RuntimeException("Método do Zabbix não permitido (somente leitura): $method");
    }
}

/**
 * Várias consultas ao Zabbix ao mesmo tempo (a latência até o servidor é paga uma vez só).
 * @param array<string, array{0:string,1:array}> $calls chave => [método, parâmetros]
 * @return array<string, mixed> chave => resultado, ou null quando aquela consulta falhou
 */
function zbxBatch(array $calls): array
{
    global $cfg;
    $mh = curl_multi_init();
    $hs = [];
    foreach ($calls as $key => [$method, $params]) {
        zbxGuard($method);
        $ch = curl_init($cfg['zabbix_url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc', 'Authorization: Bearer ' . $cfg['zabbix_token']],
            CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params ?: new stdClass(), 'id' => 1])]);
        curl_multi_add_handle($mh, $ch);
        $hs[$key] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $key => $ch) {
        $resp = json_decode((string)curl_multi_getcontent($ch), true);
        $out[$key] = (is_array($resp) && !isset($resp['error'])) ? ($resp['result'] ?? null) : null;
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $out;
}

function zbx(string $method, array $params = []): mixed
{
    global $cfg;
    zbxGuard($method);
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

/** true quando o GLPI é lido pela API REST (painel fora do servidor do GLPI), em vez do banco local. */
function glpiRemote(): bool
{
    global $cfg;
    return ($cfg['glpi_mode'] ?? 'db') === 'rest';
}

/**
 * GET na API REST do GLPI (somente leitura: o painel nunca envia POST/PUT/DELETE ao GLPI).
 * A sessão é guardada em arquivo temporário e renovada sozinha quando expira.
 */
function glpiGet(string $path, array $query = []): array
{
    global $cfg;
    $g = $cfg['glpi_rest'];
    $base = rtrim($g['url'], '/');
    $sessFile = sys_get_temp_dir() . '/dc360_glpi_' . md5($base . $g['user_token']);
    $call = function (string $url, array $headers) {
        $ch = curl_init($url);
        $hdr = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $hdr[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            }]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('GLPI inacessível: ' . curl_error($ch));
        }
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string)$raw, $hdr];
    };
    $login = function () use ($call, $base, $g, $sessFile): string {
        [$code, $raw] = $call($base . '/initSession', ['Authorization: user_token ' . $g['user_token'], 'App-Token: ' . $g['app_token'], 'Content-Type: application/json']);
        $tok = json_decode($raw, true)['session_token'] ?? null;
        if ($code !== 200 || !$tok) {
            throw new RuntimeException("GLPI recusou o login da API (HTTP $code)");
        }
        file_put_contents($sessFile, $tok);
        @chmod($sessFile, 0600);
        return $tok;
    };
    $tok = is_file($sessFile) ? trim((string)file_get_contents($sessFile)) : $login();
    $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
    for ($try = 0; $try < 2; $try++) {
        [$code, $raw, $hdr] = $call($url, ['Session-Token: ' . $tok, 'App-Token: ' . $g['app_token'], 'Content-Type: application/json']);
        if ($code === 401 && $try === 0) {
            $tok = $login();
            continue;
        }
        break;
    }
    if ($code >= 400) {
        throw new RuntimeException("GLPI $path: HTTP $code " . substr($raw, 0, 160));
    }
    $total = null;
    if (isset($hdr['content-range']) && preg_match('#/(\d+)#', $hdr['content-range'], $m)) {
        $total = (int)$m[1];
    }
    return ['body' => json_decode($raw, true) ?? [], 'total' => $total];
}

/** Versões em execução (apiinfo.version recusa autenticação; versão do GLPI vem do banco). */
function versions(): array
{
    return fileCache('versions', 3600, 'versionsRaw');
}

function versionsRaw(): array
{
    global $cfg;
    $ch = curl_init($cfg['zabbix_url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc'],
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => 'apiinfo.version', 'params' => new stdClass(), 'id' => 1])]);
    $zbx = json_decode((string)curl_exec($ch), true)['result'] ?? null;
    try {
        $glpi = glpiRemote()
            ? (glpiGet('/getGlpiConfig')['body']['cfg_glpi']['version'] ?? null)
            : (rows("SELECT value FROM glpi_configs WHERE context = 'core' AND name = 'version'")[0]['value'] ?? null);
    } catch (Throwable) {
        $glpi = null;
    }
    return ['zabbix' => $zbx, 'glpi' => $glpi];
}

/** Últimas versões publicadas (tags do GitHub): a mais nova da mesma série x.y (correção) e a mais nova de todas. */
function latestTags(string $repo): ?array
{
    $ch = curl_init("https://api.github.com/repos/$repo/tags?per_page=100");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['User-Agent: datacenter360', 'Accept: application/vnd.github+json']]);
    $j = json_decode((string)curl_exec($ch), true);
    if (!is_array($j)) {
        return null;
    }
    $v = [];
    foreach ($j as $t) {
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', (string)($t['name'] ?? ''), $m)) {
            $v[] = $m[1];
        }
    }
    usort($v, 'version_compare');
    return $v ?: null;
}

function updates(): array
{
    return fileCache('updates', 6 * 3600, function () {
        $cur = versions();
        $out = [];
        foreach (['zabbix' => 'zabbix/zabbix', 'glpi' => 'glpi-project/glpi'] as $k => $repo) {
            $c = $cur[$k] ?? null;
            $all = $c ? latestTags($repo) : null;
            if (!$c || !$all) {
                $out[$k] = ['current' => $c, 'patch' => null, 'major' => null];
                continue;
            }
            $serie = implode('.', array_slice(explode('.', $c), 0, 2));
            $same = array_values(array_filter($all, fn($x) => str_starts_with($x, $serie . '.')));
            $top = end($same) ?: null;
            $newest = end($all);
            $out[$k] = ['current' => $c,
                'patch' => $top && version_compare($top, $c, '>') ? $top : null,
                'major' => version_compare($newest, $c, '>') && !str_starts_with($newest, $serie . '.') ? $newest : null];
        }
        return $out;
    });
}

/* ---------------- Gaveta lateral: histórico do host (Zabbix) e interações do chamado (GLPI), só leitura */

function plainText(?string $html): string
{
    $t = html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('#<\s*(br|/p|/div|/li|/tr)\s*/?>#i', "\n", $t);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    return trim((string)preg_replace("/\n{3,}/", "\n\n", $t));
}

function hostLog(): array
{
    $hid = preg_replace('/\D/', '', (string)($_GET['hostid'] ?? ''));
    if ($hid === '') {
        fail(400, 'hostid obrigatório');
    }
    $days = 30;
    $ev = zbx('event.get', ['output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid', 'acknowledged'], 'hostids' => [$hid],
        'source' => 0, 'object' => 0, 'value' => 1, 'severities' => sevList(), 'time_from' => time() - $days * 86400,
        'sortfield' => ['clock'], 'sortorder' => 'DESC', 'limit' => 60]);
    $rec = [];
    $ids = array_values(array_filter(array_column($ev, 'r_eventid'), fn($i) => $i !== '0'));
    if ($ids) {
        foreach (zbx('event.get', ['output' => ['eventid', 'clock'], 'eventids' => $ids]) as $r) {
            $rec[$r['eventid']] = (int)$r['clock'];
        }
    }
    global $cfg;
    $front = rtrim($cfg['zabbix_front'], '/');
    $host = zbx('host.get', ['output' => ['name'], 'hostids' => [$hid]])[0]['name'] ?? '';
    $items = array_map(fn($e) => ['eventid' => $e['eventid'], 'clock' => (int)$e['clock'], 'severity' => (int)$e['severity'], 'name' => $e['name'],
        'acknowledged' => $e['acknowledged'] === '1', 'end' => $rec[$e['r_eventid']] ?? null,
        'url' => $front . '/tr_events.php?triggerid=' . $e['objectid'] . '&eventid=' . $e['eventid']], $ev);
    return ['host' => $host, 'days' => $days, 'events' => $items,
        'url' => $front . '/zabbix.php?action=problem.view&hostids%5B%5D=' . $hid . '&filter_set=1'];
}

function ticketLog(): array
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        fail(400, 'id obrigatório');
    }
    // só chamados da entidade NOC para baixo (os mesmos que o painel lista)
    $now = time();
    $from = strtotime(($_GET['from'] ?? date('Y-m-d', $now - 7 * 86400)) . ' 00:00:00') ?: $now - 7 * 86400;
    $till = strtotime(($_GET['to'] ?? date('Y-m-d', $now)) . ' 23:59:59') ?: $now;
    $ok = false;
    foreach ([glpiTickets($from, $till, false), glpiTickets(0, 0, true)] as $list) {
        foreach ($list as $t) {
            if ((int)$t['id'] === $id) {
                $ok = true;
                break 2;
            }
        }
    }
    if (!$ok) {
        fail(404, 'Chamado fora do escopo do painel');
    }
    $out = [];
    if (glpiRemote()) {
        $t = glpiGet('/Ticket/' . $id, ['expand_dropdowns' => 'true'])['body'];
        $out[] = ['kind' => 'abertura', 'date' => $t['date'] ?? '', 'author' => (string)($t['users_id_recipient'] ?? ''), 'private' => false, 'text' => plainText($t['content'] ?? '')];
        foreach (['ITILFollowup' => 'acompanhamento', 'TicketTask' => 'tarefa', 'ITILSolution' => 'solução'] as $path => $kind) {
            try {
                $r = glpiGet("/Ticket/$id/$path", ['expand_dropdowns' => 'true', 'range' => '0-199'])['body'];
            } catch (Throwable) {
                continue;
            }
            foreach (is_array($r) && array_is_list($r) ? $r : [] as $x) {
                $out[] = ['kind' => $kind, 'date' => $x['date'] ?? ($x['date_creation'] ?? ''), 'author' => (string)($x['users_id'] ?? ''),
                    'private' => !empty($x['is_private']), 'text' => plainText($x['content'] ?? '')];
            }
        }
    } else {
        $u = "LEFT JOIN glpi_users u ON u.id = %s";
        $out[] = ['kind' => 'abertura'] + (rows("SELECT t.date, COALESCE(NULLIF(CONCAT_WS(' ', u.firstname, u.realname), ''), u.name) AS author, 0 AS private, t.content AS text
            FROM glpi_tickets t " . sprintf($u, 't.users_id_recipient') . " WHERE t.id = ?", [$id])[0] ?? ['date' => '', 'author' => '', 'private' => 0, 'text' => '']);
        $q = [['acompanhamento', 'glpi_itilfollowups', 'f.date_creation', 'f.users_id', 'f.is_private', "f.itemtype = 'Ticket' AND f.items_id = ?"],
              ['tarefa', 'glpi_tickettasks', 'f.date', 'f.users_id', 'f.is_private', 'f.tickets_id = ?'],
              ['solução', 'glpi_itilsolutions', 'f.date_creation', 'f.users_id', '0', "f.itemtype = 'Ticket' AND f.items_id = ?"]];
        foreach ($q as [$kind, $tbl, $dt, $uid, $priv, $where]) {
            foreach (rows("SELECT $dt AS date, COALESCE(NULLIF(CONCAT_WS(' ', u.firstname, u.realname), ''), u.name) AS author, $priv AS private, f.content AS text
                           FROM $tbl f " . sprintf($u, $uid) . " WHERE $where ORDER BY $dt", [$id]) as $x) {
                $out[] = ['kind' => $kind] + $x;
            }
        }
    }
    foreach ($out as &$o) {
        $o['text'] = plainText($o['text']);
        $o['private'] = (bool)$o['private'];
    }
    unset($o);
    usort($out, fn($x, $y) => strcmp((string)$x['date'], (string)$y['date']));
    return ['id' => $id, 'items' => $out];
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
    $maxConn = glpiRemote() ? 0 : (int)(rows("SHOW GLOBAL VARIABLES LIKE 'max_connections'")[0]['Value'] ?? 0);
    $spark = [];
    if (isset($ids['mysql.queries.rate'])) {
        foreach (zbx('history.get', ['output' => ['clock', 'value'], 'itemids' => $ids['mysql.queries.rate'], 'history' => 0,
            'time_from' => time() - 3600, 'sortfield' => 'clock']) as $p) {
            $spark[] = [(int)$p['clock'] * 1000, round((float)$p['value'], 3)];
        }
    }
    $glpiDb = null;
    foreach ($v['db_size'] ?? [] as $name => $size) {
        if (($cfg['db']['name'] ?? '') !== '' && str_ends_with($name, ' ' . $cfg['db']['name'])) {
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
    $list = zbx('sla.get', ['output' => ['slaid', 'name', 'slo', 'status']]);
    $calls = [];
    foreach ($list as $sla) {
        $calls[$sla['slaid']] = ['sla.getsli', ['slaid' => $sla['slaid'], 'period_from' => $from, 'period_to' => $till + 1]];
    }
    $res = $calls ? zbxBatch($calls) : [];
    $out = [];
    foreach ($list as $sla) {
        $sli = null;
        $services = 0;
        $r = $res[$sla['slaid']] ?? null;  // null: SLA sem serviços visíveis, mostra só o SLO
        if (is_array($r)) {
            $services = count($r['serviceids'] ?? []);
            $vals = [];
            foreach ($r['sli'] ?? [] as $period) {
                foreach ($period as $svc) {
                    $vals[] = (float)$svc['sli'];
                }
            }
            $sli = $vals ? round(avg($vals), 3) : null;
        }
        $out[] = ['name' => $sla['name'], 'slo' => (float)$sla['slo'], 'sli' => $sli, 'services' => $services, 'enabled' => $sla['status'] === '1'];
    }
    return $out;
}

/* ===================================================================================================
 * Painel por cliente: severidades, chamados da entidade NOC, links/APs do escritório e servidores
 * =================================================================================================== */

/** Severidades que o painel mostra (padrão: Desastre, Alta e Atenção/Warning). */
function sevList(): array
{
    global $cfg;
    return array_map('intval', $cfg['severities'] ?? [5, 4, 2]);
}

/** Guarda em arquivo temporário o resultado de $fn por $ttl segundos. */
function fileCache(string $key, int $ttl, callable $fn): mixed
{
    $f = sys_get_temp_dir() . '/dc360_c_' . md5($key);
    if ($ttl > 0 && !isset($_GET['nocache']) && is_file($f) && time() - filemtime($f) < $ttl) {
        $raw = (string)file_get_contents($f);
        $v = json_decode($raw, true);
        if ($v !== null || $raw === 'null') {
            return $v;
        }
    }
    $v = $fn();
    @file_put_contents($f, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $v;
}

/** Falha se qualquer consulta do lote falhar (os painéis não devem mostrar números pela metade). */
function zbxBatchStrict(array $calls): array
{
    $r = zbxBatch($calls);
    foreach ($r as $k => $v) {
        if ($v === null) {
            throw new RuntimeException("Zabbix: a consulta '$k' falhou");
        }
    }
    return $r;
}

/**
 * Cliente de uma entidade do GLPI. Regras (config.php):
 *  - client_root_markers: nomes de entidade cujo filho é o cliente (ex.: "CLIENTES" > Cliente A);
 *  - client_child_markers: nomes de entidade cujo pai é o cliente (ex.: Cliente A > "PARCEIROS");
 *  - senão, o último nível do caminho que não seja só código/número.
 */
function cliOf(string $path): string
{
    global $cfg;
    $roots = $cfg['client_root_markers'] ?? [];
    $kids = $cfg['client_child_markers'] ?? [];
    $p = explode(' > ', $path);
    foreach ($p as $i => $x) {
        if (in_array(mb_strtoupper(trim($x)), array_map('mb_strtoupper', $roots), true) && isset($p[$i + 1])) {
            return $p[$i + 1];
        }
    }
    foreach ($p as $i => $x) {
        if (in_array(mb_strtoupper(trim($x)), array_map('mb_strtoupper', $kids), true) && $i > 0) {
            return $p[$i - 1];
        }
    }
    $i = count($p) - 1;
    while ($i > 0 && (preg_match('/^[\d.\s\-–]+$/u', $p[$i]) || in_array(mb_strtoupper(trim($p[$i])), array_map('mb_strtoupper', $cfg['client_skip_levels'] ?? []), true))) {
        $i--;
    }
    return $p[$i];
}

/** Entidades do GLPI (id => nome completo) da entidade raiz do painel para baixo. */
function glpiEntities(): array
{
    global $cfg;
    $prefix = $cfg['glpi_entity_prefix'] ?? null;
    $all = fileCache('entities', 3600, function () {
        $out = [];
        if (glpiRemote()) {
            foreach (glpiGet('/getMyEntities', ['is_recursive' => 'true'])['body']['myentities'] ?? [] as $e) {
                $out[(string)(int)$e['id']] = (string)$e['name'];
            }
        } else {
            foreach (rows('SELECT id, completename FROM glpi_entities') as $e) {
                $out[(string)(int)$e['id']] = (string)$e['completename'];
            }
        }
        return $out;
    });
    if ($prefix) {
        $all = array_filter($all, fn($n) => $n === $prefix || str_starts_with($n, $prefix . ' > '));
    }
    return $all;
}

/** Clientes do GLPI (um por entidade-cliente) abaixo da entidade raiz. */
function glpiClients(): array
{
    global $cfg;
    $skip = array_map('mb_strtoupper', $cfg['client_exclude'] ?? []);
    $c = [];
    foreach (glpiEntities() as $n) {
        $name = cliOf($n);
        if (!in_array(mb_strtoupper($name), $skip, true)) {
            $c[$name] = true;
        }
    }
    $names = array_keys($c);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function normTokens(string $s): array
{
    $s = strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    $t = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_diff($t, ['de', 'da', 'do', 'dos', 'das', 'e', 'municipal']));
}

/**
 * Grupo de hosts do Zabbix => cliente do GLPI. Primeiro a tabela 'client_groups' do config
 * (cliente => [grupos]); o que não estiver nela é casado pelo nome (mesmas palavras, sem acento).
 */
function groupClientMap(array $groupNames, array $clients): array
{
    global $cfg;
    $map = [];
    foreach (($cfg['client_groups'] ?? []) as $client => $groups) {
        foreach ((array)$groups as $g) {
            $map[$g] = $client;
        }
    }
    foreach ($groupNames as $g) {
        if (isset($map[$g])) {
            continue;
        }
        $gt = normTokens($g);
        if (!$gt) {
            continue;
        }
        $best = null;
        $bestScore = 0;
        foreach ($clients as $c) {
            $ct = normTokens($c);
            if (!$ct) {
                continue;
            }
            $inter = count(array_intersect($ct, $gt));
            if ($inter > 0 && ($inter === count($ct) || $inter === count($gt))) {
                $score = $inter * 10 - abs(count($ct) - count($gt));
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $c;
                }
            }
        }
        if ($best !== null) {
            $map[$g] = $best;
        }
    }
    return $map;
}

/* ---------------- GLPI: chamados da entidade raiz para baixo (banco local ou API REST) */

const TICKET_FIELDS_REST = [2, 1, 12, 3, 10, 14, 15, 17, 18, 154, 150, 80, 7, 8, 5];

function glpiTicketsDb(int $from, int $till, bool $open): array
{
    global $cfg;
    $prefix = $cfg['glpi_entity_prefix'] ?? null;
    $where = ['t.is_deleted = 0'];
    $args = [];
    if ($open) {
        $where[] = 't.status < 5';
    } else {
        $where[] = 't.date BETWEEN ? AND ?';
        $args = [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $till)];
    }
    if ($prefix) {
        $where[] = '(e.completename = ? OR e.completename LIKE ?)';
        $args[] = $prefix;
        $args[] = addcslashes($prefix, '%_\\') . ' > %';
    }
    return rows('SELECT t.id, t.name, t.status, t.priority, t.urgency, t.type, t.date, t.solvedate, t.time_to_resolve,
                        t.solve_delay_stat, t.takeintoaccount_delay_stat, e.completename AS entity, c.completename AS category,
                        (SELECT GROUP_CONCAT(g.completename SEPARATOR ", ") FROM glpi_groups_tickets gt JOIN glpi_groups g ON g.id = gt.groups_id WHERE gt.tickets_id = t.id AND gt.type = 2) AS `group`,
                        (SELECT GROUP_CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(" ", u.firstname, u.realname)), ""), u.name) SEPARATOR ", ") FROM glpi_tickets_users tu JOIN glpi_users u ON u.id = tu.users_id WHERE tu.tickets_id = t.id AND tu.type = 2) AS tech
                 FROM glpi_tickets t
                 LEFT JOIN glpi_entities e ON e.id = t.entities_id
                 LEFT JOIN glpi_itilcategories c ON c.id = t.itilcategories_id
                 WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT ' . (int)($cfg['glpi_max_tickets'] ?? 8000), $args);
}

function glpiTicketsRest(int $from, int $till, bool $open): array
{
    global $cfg;
    $prefix = $cfg['glpi_entity_prefix'] ?? null;
    $c = [];
    $add = function (int $field, string $type, string $value) use (&$c) {
        $c[] = ['link' => 'AND', 'field' => $field, 'searchtype' => $type, 'value' => $value];
    };
    if ($open) {
        $add(12, 'equals', 'notold');
    } else {
        $add(15, 'morethan', date('Y-m-d H:i:s', $from - 1));
        $add(15, 'lessthan', date('Y-m-d H:i:s', $till + 1));
    }
    if ($prefix) {
        $eid = array_search($prefix, glpiEntities(), true);
        $eid !== false ? $add(80, 'under', (string)$eid) : $add(80, 'contains', $prefix);
    }
    $q = ['criteria' => $c, 'forcedisplay' => TICKET_FIELDS_REST, 'sort' => 2, 'order' => 'DESC'];
    $page = 500;
    $max = (int)($cfg['glpi_max_tickets'] ?? 8000);
    $out = [];
    for ($start = 0; $start < $max; $start += $page) {
        $r = glpiGet('/search/Ticket', $q + ['range' => $start . '-' . ($start + $page - 1)]);
        foreach ($r['body']['data'] ?? [] as $row) {
            $out[] = [
                'id' => (int)($row[2] ?? 0), 'name' => (string)($row[1] ?? ''), 'status' => (int)($row[12] ?? 0), 'priority' => (int)($row[3] ?? 0),
                'urgency' => (int)($row[10] ?? 0), 'type' => (int)($row[14] ?? 0), 'date' => (string)($row[15] ?? ''), 'solvedate' => ($row[17] ?? null) ?: null,
                'time_to_resolve' => ($row[18] ?? null) ?: null, 'solve_delay_stat' => (int)($row[154] ?? 0), 'takeintoaccount_delay_stat' => (int)($row[150] ?? 0),
                'entity' => ($row[80] ?? null) ?: null, 'category' => ($row[7] ?? null) ?: null,
                'group' => implode(', ', (array)($row[8] ?? [])), 'tech_ids' => array_values(array_filter((array)($row[5] ?? []))),
            ];
        }
        if (count($r['body']['data'] ?? []) < $page) {
            break;
        }
    }
    foreach ($out as &$o) {
        $o['tech'] = implode(', ', array_map('glpiUserName', $o['tech_ids']));
        unset($o['tech_ids']);
    }
    unset($o);
    return $out;
}

/** Nome do usuário do GLPI (REST), em cache por um dia. */
function glpiUserName($id): string
{
    return fileCache('guser:' . $id, 86400, function () use ($id) {
        try {
            $u = glpiGet('/User/' . (int)$id)['body'];
            $n = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
            return $n !== '' ? $n : (string)($u['name'] ?? "#$id");
        } catch (Throwable) {
            return "#$id";
        }
    });
}

/** Chamados da entidade raiz para baixo: abertos no período ($open = false) ou todos os que estão em aberto agora. */
function glpiTickets(int $from, int $till, bool $open): array
{
    global $cfg;
    return fileCache("tickets:$from:$till:" . (int)$open, (int)($cfg['glpi_cache'] ?? 90),
        fn() => glpiRemote() ? glpiTicketsRest($from, $till, $open) : glpiTicketsDb($from, $till, $open));
}

/** Texto do chamado (só para o detalhe de um chamado). */
function glpiTicketContent(int $id): string
{
    if (glpiRemote()) {
        return (string)(glpiGet('/Ticket/' . $id)['body']['content'] ?? '');
    }
    return (string)(rows('SELECT content FROM glpi_tickets WHERE id = ?', [$id])[0]['content'] ?? '');
}

/* ---------------- Zabbix: hosts, problemas, eventos e disponibilidade (uma leitura por período, em cache) */

function zabbixRaw(int $from, int $till): array
{
    global $cfg;
    return fileCache("zraw:$from:$till", (int)($cfg['cache_ttl'] ?? 20), function () use ($from, $till) {
        $sev = sevList();
        $w1 = zbxBatchStrict([
            'hosts' => ['host.get', ['output' => ['hostid', 'host', 'name', 'active_available'], 'filter' => ['status' => 0],
                'selectInterfaces' => ['type', 'available'], 'selectHostGroups' => ['name'], 'sortfield' => 'name']],
            'problems' => ['problem.get', ['output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged'], 'severities' => $sev,
                'sortfield' => ['eventid'], 'sortorder' => 'DESC']],
            'events' => ['event.get', ['output' => ['eventid', 'clock', 'severity', 'name', 'r_eventid', 'acknowledged'], 'source' => 0, 'object' => 0, 'value' => 1,
                'severities' => $sev, 'time_from' => $from, 'time_till' => $till, 'selectHosts' => ['hostid', 'name'], 'selectAcknowledges' => ['clock', 'action'],
                'sortfield' => ['clock'], 'sortorder' => 'ASC', 'limit' => 20000]],
            'icmp' => ['item.get', ['output' => ['itemid', 'hostid'], 'filter' => ['key_' => 'icmpping'], 'monitored' => true]],
        ]);
        $recIds = array_values(array_filter(array_column($w1['events'], 'r_eventid'), fn($id) => $id !== '0'));
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
        $w2 = $w2 ? zbxBatchStrict($w2) : [];

        $probHost = [];
        foreach ($w2['phosts'] ?? [] as $e) {
            $probHost[$e['eventid']] = $e['hosts'][0] ?? null;
        }
        $problems = [];
        foreach ($w1['problems'] as $p) {
            $h = $probHost[$p['eventid']] ?? null;
            $problems[] = ['eventid' => $p['eventid'], 'objectid' => $p['objectid'], 'name' => $p['name'], 'severity' => (int)$p['severity'], 'clock' => (int)$p['clock'],
                'acknowledged' => $p['acknowledged'] === '1', 'host' => $h['name'] ?? '—', 'hostid' => $h['hostid'] ?? null];
        }
        $recClock = [];
        foreach ($w2 as $k => $v) {
            if (str_starts_with($k, 'rec')) {
                foreach ($v as $r) {
                    $recClock[$r['eventid']] = (int)$r['clock'];
                }
            }
        }
        $events = [];
        foreach ($w1['events'] as $e) {
            $acks = array_filter($e['acknowledges'] ?? [], fn($a) => ((int)$a['action'] & 2) === 2);
            $events[] = ['clock' => (int)$e['clock'], 'severity' => (int)$e['severity'], 'hostid' => $e['hosts'][0]['hostid'] ?? null, 'host' => $e['hosts'][0]['name'] ?? '—',
                'acknowledged' => $e['acknowledged'] === '1', 'detect' => $acks ? min(array_map(fn($a) => (int)$a['clock'], $acks)) - (int)$e['clock'] : null,
                'repair' => isset($recClock[$e['r_eventid']]) ? $recClock[$e['r_eventid']] - (int)$e['clock'] : null];
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
        return ['hosts' => $w1['hosts'], 'problems' => $problems, 'events' => $events, 'avail' => $avail];
    });
}

/** Linha do chamado já com cliente, atraso e pendência calculados. */
function ticketRow(array $t, int $now): array
{
    global $cfg;
    $st = (int)$t['status'];
    $opened = strtotime($t['date']);
    $ttr = $t['time_to_resolve'] ? strtotime($t['time_to_resolve']) : null;
    $target = $ttr ?: $opened + 3600 * ($cfg['sla_hours'][(int)$t['priority']] ?? 24);
    $slaOk = $t['solvedate'] ? strtotime($t['solvedate']) <= $target : null;
    return ['id' => (int)$t['id'], 'name' => $t['name'], 'status' => STATUS_GLPI[$st] ?? (string)$st, 'status_id' => $st,
        'priority' => PRIORIDADE_GLPI[(int)$t['priority']] ?? $t['priority'], 'priority_id' => (int)$t['priority'],
        'entity' => $t['entity'], 'client' => cliOf((string)($t['entity'] ?? '')), 'category' => $t['category'], 'date' => $t['date'], 'solvedate' => $t['solvedate'],
        'resolve_seconds' => $t['solvedate'] ? (int)$t['solve_delay_stat'] : null, 'take_seconds' => (int)$t['takeintoaccount_delay_stat'],
        'sla_ok' => $slaOk, 'sla_target' => date('Y-m-d H:i:s', $target), 'due' => $ttr ? date('Y-m-d H:i:s', $ttr) : null,
        'group' => trim((string)($t['group'] ?? '')) ?: 'Sem grupo', 'tech' => trim((string)($t['tech'] ?? '')) ?: 'Sem responsável',
        'open' => $st < 5, 'pending' => $st === 4, 'late' => $st < 5 && $ttr !== null && $ttr < $now];
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
    $clientSel = trim((string)($_GET['client'] ?? ''));
    $now = time();
    $sevs = sevList();
    $front = rtrim($cfg['zabbix_front'], '/');

    // ---------------- Zabbix e GLPI (leituras completas, em cache); os filtros são aplicados aqui
    $raw = zabbixRaw($from, $till);
    tick('zabbix');
    $zHosts = $raw['hosts'];
    $clients = glpiClients();
    $groupNames = [];
    foreach ($zHosts as $h) {
        foreach ($h['hostgroups'] ?? [] as $g) {
            $groupNames[$g['name']] = true;
        }
    }
    $g2c = groupClientMap(array_keys($groupNames), $clients);
    $hostClient = [];
    foreach ($zHosts as $h) {
        $first = null;
        $cl = null;
        foreach ($h['hostgroups'] ?? [] as $g) {
            $first ??= $g['name'];
            if (isset($g2c[$g['name']])) {
                $cl = $g2c[$g['name']];
                break;
            }
        }
        $hostClient[$h['hostid']] = $cl ?? $first ?? '—';
    }
    $periodAll = array_map(fn($t) => ticketRow($t, $now), glpiTickets($from, $till, false));
    $openAll = array_map(fn($t) => ticketRow($t, $now), glpiTickets(0, 0, true));
    tick('glpi');

    // ---------------- totais por cliente (todos os clientes, sem aplicar o filtro de cliente)
    $agg = [];
    $touch = function (string $n) use (&$agg, $sevs) {
        $agg[$n] ??= ['name' => $n, 'kind' => 'zabbix', 'tickets' => 0, 'open' => 0, 'pending' => 0, 'overdue' => 0, 'alerts' => 0,
            'sev' => array_fill_keys($sevs, 0), 'active' => 0, 'unacked' => 0, 'hosts' => 0];
    };
    foreach ($clients as $c) {
        $touch($c);
        $agg[$c]['kind'] = 'glpi';
    }
    foreach ($periodAll as $t) {
        $touch($t['client']);
        $agg[$t['client']]['tickets']++;
    }
    foreach ($openAll as $t) {
        $touch($t['client']);
        $agg[$t['client']]['open']++;
        $agg[$t['client']]['pending'] += $t['pending'] ? 1 : 0;
        $agg[$t['client']]['overdue'] += $t['late'] ? 1 : 0;
    }
    foreach ($zHosts as $h) {
        $touch($hostClient[$h['hostid']]);
        $agg[$hostClient[$h['hostid']]]['hosts']++;
    }
    foreach ($raw['events'] as $e) {
        $c = $hostClient[$e['hostid']] ?? '—';
        $touch($c);
        $agg[$c]['alerts']++;
        $agg[$c]['sev'][$e['severity']] = ($agg[$c]['sev'][$e['severity']] ?? 0) + 1;
    }
    foreach ($raw['problems'] as $p) {
        $c = $hostClient[$p['hostid']] ?? '—';
        $touch($c);
        $agg[$c]['active']++;
        $agg[$c]['unacked'] += $p['acknowledged'] ? 0 : 1;
    }

    // ---------------- filtros: cliente, host e chamado
    $inScope = function ($hid) use ($hostid, $clientSel, $hostClient): bool {
        $hid = (string)$hid;
        return (!$hostid || $hid === $hostid) && ($clientSel === '' || ($hostClient[$hid] ?? null) === $clientSel);
    };
    $hostName = null;
    foreach ($zHosts as $h) {
        if ($h['hostid'] === $hostid) {
            $hostName = $h['name'];
        }
    }
    $matchT = function (array $t) use ($clientSel, $hostName, $ticketQ): bool {
        if ($clientSel !== '' && $t['client'] !== $clientSel) {
            return false;
        }
        if ($hostName !== null && stripos($t['name'], '] ' . $hostName . ':') === false) {
            return false;
        }
        if ($ticketQ !== '') {
            return ctype_digit(ltrim($ticketQ, '#')) ? (string)$t['id'] === ltrim($ticketQ, '#') : stripos($t['name'], $ticketQ) !== false;
        }
        return true;
    };
    $scopeHosts = array_values(array_filter($zHosts, fn($h) => $inScope($h['hostid'])));
    $active = array_values(array_filter($raw['problems'], fn($p) => $p['hostid'] !== null && $inScope($p['hostid'])));
    $events = array_values(array_filter($raw['events'], fn($e) => $e['hostid'] !== null && $inScope($e['hostid'])));
    $P = array_values(array_filter($periodAll, $matchT));
    $O = array_values(array_filter($openAll, $matchT));

    // ---------------- alertas (Zabbix)
    $sevCount = array_fill_keys($sevs, 0);
    $byHost = [];
    $alertsPerDay = [];
    $detect = $repair = [];
    $hostSev = [];
    foreach ($events as $e) {
        $sevCount[$e['severity']] = ($sevCount[$e['severity']] ?? 0) + 1;
        $k = $e['hostid'] . '|' . $e['host'];
        $byHost[$k] = ($byHost[$k] ?? 0) + 1;
        $hostSev[$e['hostid']][$e['severity']] = ($hostSev[$e['hostid']][$e['severity']] ?? 0) + 1;
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
    $perHost = $hostAct = $hostUn = [];
    foreach ($active as &$p) {
        $activeCount[$p['severity']] = ($activeCount[$p['severity']] ?? 0) + 1;
        $unacked += $p['acknowledged'] ? 0 : 1;
        $perHost[$p['hostid']][] = $p['severity'];
        $hostAct[$p['hostid']] = ($hostAct[$p['hostid']] ?? 0) + 1;
        $hostUn[$p['hostid']] = ($hostUn[$p['hostid']] ?? 0) + ($p['acknowledged'] ? 0 : 1);
        $p['url'] = $front . '/tr_events.php?triggerid=' . $p['objectid'] . '&eventid=' . $p['eventid'];
    }
    unset($p);

    // ---------------- chamados (GLPI, entidade NOC para baixo)
    $byStatus = $byCategory = $ticketsPerDay = [];
    $tma = $tme = [];
    foreach ($P as $t) {
        $byStatus[$t['status']] = ($byStatus[$t['status']] ?? 0) + 1;
        $cat = $t['category'] ?: 'Sem categoria';
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
    usort($list, fn($a, $b) => $b['id'] <=> $a['id']);
    $list = array_slice($list, 0, (int)($cfg['list_max'] ?? 400));

    // Detalhe de um chamado (filtro por chamado com um único resultado)
    $detail = null;
    if ($ticketQ !== '' && count($P) + count($O) >= 1 && count($list) === 1) {
        $t = $list[0];
        $content = glpiTicketContent($t['id']);
        preg_match('/eventid=(\d+)/', html_entity_decode($content), $m);
        $t['eventid'] = $m[1] ?? null;
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
                    'url' => $front . '/tr_events.php?eventid=' . $e['eventid']];
            }
        }
    }

    // ---------------- hosts
    $hostCards = [];
    foreach ($scopeHosts as $h) {
        $sev = $perHost[$h['hostid']] ?? [];
        $hostCards[] = ['hostid' => $h['hostid'], 'name' => $h['name'], 'status' => hostStatus($h), 'client' => $hostClient[$h['hostid']],
            'groups' => array_column($h['hostgroups'] ?? [], 'name'), 'problems' => count($sev), 'max_severity' => $sev ? max($sev) : null,
            'availability' => $raw['avail'][$h['hostid']] ?? null];
    }
    $days = [];
    for ($d = $from; $d <= $till; $d += 86400) {
        $k = date('Y-m-d', $d);
        $days[] = ['day' => $k, 'alerts' => $alertsPerDay[$k] ?? 0, 'tickets' => $ticketsPerDay[$k] ?? 0];
    }
    uasort($agg, fn($a, $b) => ($b['tickets'] + $b['alerts']) <=> ($a['tickets'] + $a['alerts']) ?: strcasecmp($a['name'], $b['name']));
    $grpOf = [];
    foreach ($g2c as $g => $c) {
        $grpOf[$c][] = $g;
    }
    foreach ($agg as $n => &$a) {
        $a['groups'] = $grpOf[$n] ?? [];
    }
    unset($a);

    return [
        'generated_at' => time(),
        'versions' => versions(),
        'filters' => ['from' => $fromDate, 'to' => $toDate, 'hostid' => $hostid, 'host' => $hostName, 'ticket' => $ticketQ, 'client' => $clientSel],
        'severities' => $sevs,
        'kpi' => [
            'alerts' => count($events), 'alerts_by_sev' => $sevCount,
            'active' => count($active), 'active_by_sev' => $activeCount, 'unacked' => $unacked,
            'tickets' => count($P), 'backlog' => count($O),
            'pending' => count(array_filter($O, fn($t) => $t['pending'])), 'overdue' => count(array_filter($O, fn($t) => $t['late'])),
            'mttd_zbx' => avg($detect), 'mttd_zbx_n' => count($detect), 'mttd_glpi' => avg($tme), 'mttd_glpi_n' => count($tme),
            'mttr_zbx' => avg($repair), 'mttr_zbx_n' => count($repair), 'mttr_glpi' => avg($tma), 'mttr_glpi_n' => count($tma),
            'hosts' => count($hostCards), 'hosts_online' => count(array_filter($hostCards, fn($h) => $h['status'] === 'online')),
            'hosts_offline' => count(array_filter($hostCards, fn($h) => $h['status'] === 'offline')),
        ],
        'clients' => array_values($agg),
        'timeline' => $days,
        'top_hosts' => array_slice(array_map(function ($k, $v) use ($hostSev, $hostAct, $hostUn) {
            [$id, $name] = explode('|', $k, 2);
            return ['hostid' => $id, 'host' => $name, 'alerts' => $v, 'sev' => $hostSev[$id] ?? [], 'active' => $hostAct[$id] ?? 0, 'unacked' => $hostUn[$id] ?? 0];
        }, array_keys($byHost), $byHost), 0, 12),
        'tickets_by_status' => $byStatus,
        'tickets_by_category' => $byCategory,
        'hosts' => $hostCards,
        'active' => array_slice($active, 0, 600),
        'tickets_list' => $list,
        'detail' => $detail,
        'links' => ['glpi' => rtrim($cfg['glpi_front'], '/'), 'zabbix' => $front],
    ];
}

/* ---------------- Links e APs do escritório (FortiGate) */

const FAP_STATE = ['0' => 'outro', '1' => 'offline', '2' => 'online', '3' => 'baixando firmware', '4' => 'firmware conectado', '5' => 'standby'];

/** No FortiGate a mesma operadora aparece como interface lógica (velocidade contratada no nome) e como porta física wanX (tráfego real): junta as duas. */
function mergeLinks(array $links): array
{
    $prov = function (array $l): ?string {
        preg_match('/\(([^)]*)\)/', $l['name'], $m);
        $toks = array_values(array_filter(preg_split('/[^A-Z]+/', strtoupper($m[1] ?? '')) ?: [], fn($t) => $t !== '' && !in_array($t, ['LINK', 'MB', 'GB', 'IT', 'OPERADORA'], true)));
        return $toks[0] ?? null;
    };
    $by = [];
    $out = [];
    foreach ($links as $l) {
        $p = $prov($l);
        $p === null ? $out[] = $l : $by[$l['fw'] . '|' . $p][] = $l;
    }
    foreach ($by as $grp) {
        if (count($grp) === 1) {
            $out[] = $grp[0];
            continue;
        }
        $comCap = null;
        foreach ($grp as $l) {
            if (!empty($l['uso']['capacidade'])) {
                $comCap = $l;
                break;
            }
        }
        $score = fn($l) => ($l['uso']['agora']['in'] ?? 0) + ($l['uso']['media24']['in'] ?? 0);
        usort($grp, fn($a, $b) => $score($b) <=> $score($a));
        $m = $grp[0];
        if ($comCap && $comCap['name'] !== $m['name']) {
            $m['name'] = $comCap['name'];
            $m['porta'] = preg_replace('/\(.*$/', '', $grp[0]['name']);
            if (!empty($m['uso'])) {
                $m['uso']['capacidade'] = $comCap['uso']['capacidade'];
                $m['uso']['capacidade_txt'] = 'contratada';
            }
        }
        $m['up'] = $grp[0]['up'] && count(array_filter($grp, fn($l) => !$l['up'])) === 0;
        $out[] = $m;
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

function officeNetwork(): array
{
    global $cfg;
    return fileCache('office', 30, function () use ($cfg) {
        $empty = ['firewalls' => [], 'links' => [], 'aps' => [], 'generated_at' => time()];
        $names = $cfg['office_groups'] ?? [];
        $groups = $names ? zbx('hostgroup.get', ['output' => ['groupid'], 'filter' => ['name' => $names]]) : [];
        if (!$groups) {
            return $empty;
        }
        $hosts = zbx('host.get', ['output' => ['hostid', 'name', 'active_available'], 'groupids' => array_column($groups, 'groupid'), 'filter' => ['status' => 0],
            'selectInterfaces' => ['type', 'available']]);
        $fws = array_values(array_filter($hosts, fn($h) => preg_match('/^FW\b|firewall/i', $h['name'])));
        if (!$fws) {
            return $empty;
        }
        $ids = array_column($fws, 'hostid');
        $w = zbxBatchStrict([
            'status' => ['item.get', ['hostids' => $ids, 'output' => ['hostid', 'name', 'key_', 'lastvalue', 'lastclock'], 'search' => ['name' => 'Operational status'], 'monitored' => true]],
            'trg' => ['trigger.get', ['hostids' => $ids, 'output' => ['triggerid', 'description', 'value', 'status', 'priority'], 'expandDescription' => true,
                'search' => ['description' => 'FortiAP'], 'selectItems' => ['key_'], 'filter' => ['status' => 0]]],
            'apit' => ['item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'], 'search' => ['key_' => 'fgWcWtpSession'], 'monitored' => true]],
        ]);
        $renames = $cfg['link_renames'] ?? ['Operadora Claro' => 'VPN Logística'];
        $links = [];
        foreach ($w['status'] as $i) {
            $low = mb_strtolower($i['name']);
            if (preg_match('/fortilink|vlink|vpn|ssl\./', $low) || !preg_match('/wan\d*\(|link|operadora|americanet|internet/', $low)) {
                continue;
            }
            $lab = preg_replace('/^Interface\s+|:\s*Operational status$/i', '', $i['name']);
            foreach ($renames as $de => $para) {
                $lab = str_ireplace($de, $para, $lab);
            }
            preg_match('/\.(\d+)\]$/', $i['key_'], $m);
            $links[] = ['fw' => $i['hostid'], 'name' => $lab, 'up' => $i['lastvalue'] === '1', 'idx' => $m[1] ?? null];
        }
        // consumo agora, velocidade da porta e média/pico das últimas 24 h
        $keys = [];
        foreach ($links as $l) {
            if ($l['idx'] !== null) {
                array_push($keys, "net.if.in[ifHCInOctets.{$l['idx']}]", "net.if.out[ifHCOutOctets.{$l['idx']}]", "net.if.speed[ifHighSpeed.{$l['idx']}]");
            }
        }
        $items = [];
        if ($keys) {
            foreach (zbx('item.get', ['hostids' => $ids, 'output' => ['itemid', 'hostid', 'key_', 'lastvalue'], 'filter' => ['key_' => $keys]]) as $it) {
                $items[$it['hostid'] . '|' . $it['key_']] = $it;
            }
        }
        $trend = [];
        $inIds = [];
        foreach ($items as $k => $it) {
            if (str_contains($it['key_'], 'net.if.in[')) {
                $inIds[] = $it['itemid'];
            }
        }
        if ($inIds) {
            foreach (zbx('trend.get', ['output' => ['itemid', 'num', 'value_avg', 'value_max'], 'itemids' => $inIds, 'time_from' => time() - 86400]) as $t) {
                $a = &$trend[$t['itemid']];
                $a['sum'] = ($a['sum'] ?? 0) + $t['value_avg'] * $t['num'];
                $a['num'] = ($a['num'] ?? 0) + $t['num'];
                $a['max'] = max($a['max'] ?? 0, (float)$t['value_max']);
                unset($a);
            }
        }
        foreach ($links as &$l) {
            if ($l['idx'] === null) {
                continue;
            }
            $in = $items[$l['fw'] . "|net.if.in[ifHCInOctets.{$l['idx']}]"] ?? null;
            $out = $items[$l['fw'] . "|net.if.out[ifHCOutOctets.{$l['idx']}]"] ?? null;
            $sp = $items[$l['fw'] . "|net.if.speed[ifHighSpeed.{$l['idx']}]"] ?? null;
            if (!$in) {
                continue;
            }
            $cap = null;
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(G|M)B/i', $l['name'], $m)) {
                $cap = (float)str_replace(',', '.', $m[1]) * (strtoupper($m[2]) === 'G' ? 1e9 : 1e6);
            }
            $tr = $trend[$in['itemid']] ?? null;
            $l['uso'] = ['agora' => ['in' => (int)$in['lastvalue'], 'out' => $out ? (int)$out['lastvalue'] : null], 'capacidade' => $cap,
                'capacidade_txt' => $cap ? 'contratada' : 'porta', 'porta' => $sp ? (int)$sp['lastvalue'] : null,
                'media24' => $tr ? ['in' => (int)round($tr['sum'] / max(1, $tr['num']))] : null, 'pico24' => $tr ? (int)round($tr['max']) : null];
        }
        unset($l);
        $links = mergeLinks($links);

        $aps = [];
        foreach ($w['trg'] as $t) {
            if (!preg_match('/^FortiAP (.+?) in (.+) is DOWN$/', trim($t['description']), $m)) {
                continue;
            }
            $sn = null;
            foreach ($t['items'] ?? [] as $it) {
                if (str_starts_with($it['key_'], 'fgWcWtpSessionConnectionState[') && preg_match('/\[(\w+)\]/', $it['key_'], $mm)) {
                    $sn = $mm[1];
                    break;
                }
            }
            if (!$sn || preg_match('/^FP\w{10,}$/', $m[1])) {
                continue;
            }
            $aps[$sn] = ['name' => trim($m[1]), 'serial' => $sn, 'fw' => trim($m[2]), 'down' => $t['value'] === '1', 'priority' => (int)$t['priority']];
        }
        foreach ($w['apit'] as $i) {
            if (!preg_match('/\[(\w+)\]/', $i['key_'], $sm) || !isset($aps[$sm[1]])) {
                continue;
            }
            $v = is_numeric($i['lastvalue']) ? (int)$i['lastvalue'] : null;
            $k = $i['key_'];
            $s = $sm[1];
            if (str_starts_with($k, 'fgWcWtpSessionConnectionState[')) {
                $aps[$s]['state'] = $i['lastvalue'];
                $aps[$s]['state_txt'] = FAP_STATE[$i['lastvalue']] ?? (string)$i['lastvalue'];
            } elseif (str_contains($k, 'WtpSessionUpTime')) {
                $aps[$s]['uptime'] = $v;
            } elseif (str_contains($k, 'WtpCpuUsage')) {
                $aps[$s]['cpu'] = $v;
            } elseif (str_contains($k, 'WtpMemoryUsage')) {
                $aps[$s]['mem'] = $v;
            } elseif (str_contains($k, 'WtpStationCount')) {
                $aps[$s]['clients'] = $v;
            }
        }
        foreach ($aps as &$ap) {
            $ap['up'] = !$ap['down'] && in_array($ap['state'] ?? '', ['2', '4'], true);
        }
        unset($ap);
        $aps = array_values($aps);
        usort($aps, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return ['firewalls' => array_map(fn($h) => ['hostid' => $h['hostid'], 'name' => $h['name'], 'status' => hostStatus($h)], $fws),
            'links' => $links, 'aps' => $aps, 'generated_at' => time()];
    });
}

/* ---------------- Servidores de um grupo interno (si_groups) */

function siServers(): array
{
    global $cfg;
    return fileCache('si', 30, function () use ($cfg) {
        $names = $cfg['si_groups'] ?? [];
        $groups = $names ? zbx('hostgroup.get', ['output' => ['groupid'], 'filter' => ['name' => $names]]) : [];
        if (!$groups) {
            return ['servers' => [], 'generated_at' => time()];
        }
        $skip = $cfg['si_exclude'] ?? '/^(FW|SW|WEB)\s*\||sensor|impressora|dvr|switch|forti|brother|imagens/i';
        $hosts = array_values(array_filter(zbx('host.get', ['output' => ['hostid', 'name', 'active_available'], 'groupids' => array_column($groups, 'groupid'),
            'filter' => ['status' => 0], 'selectInterfaces' => ['type', 'available']]), fn($h) => !preg_match($skip, $h['name'])));
        if (!$hosts) {
            return ['servers' => [], 'generated_at' => time()];
        }
        $ids = array_column($hosts, 'hostid');
        $w = zbxBatchStrict([
            'items' => ['item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'name', 'lastvalue', 'lastclock'], 'monitored' => true,
                'filter' => ['key_' => ['system.cpu.util', 'vm.memory.utilization', 'vm.memory.util', 'system.uptime']]]],
            'disks' => ['item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'name', 'lastvalue'], 'monitored' => true, 'search' => ['key_' => 'vfs.fs.size[']]],
            'probs' => ['problem.get', ['hostids' => $ids, 'output' => ['eventid', 'severity', 'acknowledged'], 'severities' => sevList()]],
            'vmw' => ['item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'lastvalue'], 'monitored' => true, 'search' => ['key_' => 'vmware.hv.']]],
        ]);
        $m = [];
        foreach ($w['items'] as $i) {
            $m[$i['hostid']][$i['key_']] = $i;
        }
        $disk = [];
        foreach ($w['disks'] as $i) {
            if (str_ends_with($i['key_'], ',pused]') && is_numeric($i['lastvalue'])) {
                $v = (float)$i['lastvalue'];
                if ($v > ($disk[$i['hostid']]['pct'] ?? -1)) {
                    preg_match('/\[([^,\]]+)/', $i['key_'], $mm);
                    $disk[$i['hostid']] = ['pct' => $v, 'vol' => $mm[1] ?? ''];
                }
            }
        }
        $perHost = [];
        if ($w['probs']) {
            foreach (zbx('event.get', ['output' => ['eventid'], 'eventids' => array_column($w['probs'], 'eventid'), 'selectHosts' => ['hostid']]) as $e) {
                $sev = array_column($w['probs'], 'severity', 'eventid')[$e['eventid']] ?? 0;
                if ($h = $e['hosts'][0]['hostid'] ?? null) {
                    $perHost[$h][] = (int)$sev;
                }
            }
        }
        $num = fn($i) => $i && is_numeric($i['lastvalue']) ? (float)$i['lastvalue'] : null;
        $out = [];
        foreach ($hosts as $h) {
            $x = $m[$h['hostid']] ?? [];
            $mem = $num($x['vm.memory.utilization'] ?? null) ?? $num($x['vm.memory.util'] ?? null);
            $sev = $perHost[$h['hostid']] ?? [];
            $out[] = ['hostid' => $h['hostid'], 'name' => $h['name'], 'status' => hostStatus($h), 'cpu' => $num($x['system.cpu.util'] ?? null), 'mem' => $mem,
                'disk' => $disk[$h['hostid']]['pct'] ?? null, 'disk_vol' => $disk[$h['hostid']]['vol'] ?? null, 'uptime' => $num($x['system.uptime'] ?? null),
                'problems' => count($sev), 'max_severity' => $sev ? max($sev) : null];
        }
        // Servidor físico: com 'si_server_prefix' (ex.: SRV), o iDRAC, o ESXi e o host do hypervisor do mesmo SRVnnn viram uma linha só (métricas do VMware).
        $pfx = preg_quote((string)($cfg['si_server_prefix'] ?? ''), '/');
        $vm = [];
        foreach ($w['vmw'] as $i) {
            if (!is_numeric($i['lastvalue'])) {
                continue;
            }
            $k = $i['key_'];
            if (str_starts_with($k, 'vmware.hv.cpu.usage.perf[')) {
                $vm[$i['hostid']]['cpu'] = (float)$i['lastvalue'];
            } elseif (str_starts_with($k, 'vmware.hv.memory.used[')) {
                $vm[$i['hostid']]['used'] = (float)$i['lastvalue'];
            } elseif (str_starts_with($k, 'vmware.hv.hw.memory[')) {
                $vm[$i['hostid']]['total'] = (float)$i['lastvalue'];
            } elseif (str_starts_with($k, 'vmware.hv.datastore.size[') && str_ends_with($k, ',pfree]')) {
                $vm[$i['hostid']]['disk'] = max($vm[$i['hostid']]['disk'] ?? 0, 100 - (float)$i['lastvalue']);
            }
        }
        $isPhys = fn($n) => (bool)preg_match('/idrac|esxi/i', $n);
        $physNums = [];
        foreach ($out as $r) {
            if ($isPhys($r['name']) && $pfx !== '' && preg_match('/' . $pfx . '0*(\d+)/i', $r['name'], $mm)) {
                $physNums[$mm[1]] = true;
            }
        }
        $rank = ['online' => 0, 'parcial' => 1, 'desconhecido' => 2, 'offline' => 3];
        $final = [];
        $phys = [];
        foreach ($out as $r) {
            $num = $pfx !== '' && preg_match('/' . $pfx . '0*(\d+)/i', $r['name'], $mm) ? $mm[1] : null;
            if ($num !== null && isset($physNums[$num]) && !str_starts_with($r['name'], 'VM')) {
                $phys[$num][] = $r;
            } else {
                $r['kind'] = str_starts_with($r['name'], 'VM') ? 'vm' : 'servidor';
                $final[] = $r;
            }
        }
        foreach ($phys as $num => $rows) {
            $st = 'online';
            $prob = 0;
            $sevMax = null;
            $cpu = $mem = $disk = null;
            foreach ($rows as $r) {
                $prob += $r['problems'];
                $sevMax = max($sevMax ?? 0, $r['max_severity'] ?? 0) ?: $sevMax;
                $st = $rank[$r['status']] > $rank[$st] && $r['status'] !== 'desconhecido' ? $r['status'] : $st;
                $v = $vm[$r['hostid']] ?? [];
                $cpu ??= $v['cpu'] ?? null;
                $mem ??= isset($v['used'], $v['total']) && $v['total'] > 0 ? round(100 * $v['used'] / $v['total'], 2) : null;
                $disk ??= $v['disk'] ?? null;
            }
            $final[] = ['hostid' => $rows[0]['hostid'], 'name' => ($cfg['si_server_prefix'] ?? '') . str_pad((string)$num, 3, '0', STR_PAD_LEFT) . ' (servidor físico)', 'status' => $st, 'cpu' => $cpu, 'mem' => $mem,
                'disk' => $disk, 'disk_vol' => $disk !== null ? 'datastore' : null, 'uptime' => null, 'problems' => $prob, 'max_severity' => $sevMax, 'kind' => 'fisico',
                'parts' => array_map(fn($r) => preg_replace('/^SRV \|\s*/', '', $r['name']), $rows)];
        }
        usort($final, fn($a, $b) => ($b['problems'] <=> $a['problems']) ?: strcasecmp($a['name'], $b['name']));
        return ['servers' => $final, 'generated_at' => time()];
    });
}


/**
 * Topologia: ativos monitorados, papel de cada um na rede e estado dos links.
 * O papel vem de grupos/templates/IP; o estado do link vem do ICMP quando existe,
 * senão da disponibilidade das interfaces, e dos problemas para serviços externos.
 */
function topology(): array
{
    global $cfg;
    $hosts = zbx('host.get', [
        'output' => ['hostid', 'host', 'name', 'monitored_by', 'proxyid', 'active_available'],
        'filter' => ['status' => 0],
        'selectInterfaces' => ['ip', 'type', 'available', 'main', 'error'],
        'selectHostGroups' => ['name'],
        'selectParentTemplates' => ['name'],
    ]);
    $hostids = array_column($hosts, 'hostid');

    $icmp = [];
    foreach (zbx('item.get', ['output' => ['hostid', 'key_', 'lastvalue', 'lastclock'], 'hostids' => $hostids,
        'filter' => ['key_' => ['icmpping', 'icmppingsec', 'icmppingloss']], 'monitored' => true]) as $i) {
        if ((int)$i['lastclock'] > 0) {
            $icmp[$i['hostid']][$i['key_']] = (float)$i['lastvalue'];
        }
    }

    $problems = [];
    $probs = zbx('problem.get', ['output' => ['eventid', 'name', 'severity', 'clock'], 'sortfield' => ['eventid'], 'sortorder' => 'DESC']);
    if ($probs) {
        $byEvent = [];
        foreach (zbx('event.get', ['output' => ['eventid'], 'eventids' => array_column($probs, 'eventid'), 'selectHosts' => ['hostid']]) as $e) {
            $byEvent[$e['eventid']] = $e['hosts'][0]['hostid'] ?? null;
        }
        foreach ($probs as $p) {
            if ($h = $byEvent[$p['eventid']] ?? null) {
                $problems[$h][] = ['name' => $p['name'], 'severity' => (int)$p['severity'], 'clock' => (int)$p['clock']];
            }
        }
    }

    $proxies = [];
    foreach (zbx('proxy.get', ['output' => ['proxyid', 'name', 'state', 'lastaccess']]) as $p) {
        $proxies[$p['proxyid']] = ['name' => $p['name'], 'online' => $p['state'] === '2', 'lastaccess' => (int)$p['lastaccess']];
    }

    $nodes = [];
    foreach ($hosts as $h) {
        $main = null;
        foreach ($h['interfaces'] as $if) {
            if ($if['main'] === '1') {
                $main = $if;
                break;
            }
        }
        $ip = $main['ip'] ?? '';
        $groups = array_column($h['hostgroups'], 'name');
        $tpls = array_column($h['parentTemplates'], 'name');
        $tplText = implode(' ', $tpls);

        if (str_contains($tplText, 'Zabbix server health')) {
            $role = 'zabbix';
        } elseif (str_contains($tplText, 'Zabbix proxy health')) {
            $role = 'proxy';
        } elseif ($ip === '127.0.0.1' || in_array('Website', $groups, true)) {
            $role = 'external';            // verificado pelo Zabbix server, vive na internet
        } elseif ($ip !== '' && preg_match('/\.1$/', $ip) && !str_contains($tplText, 'Linux')) {
            $role = 'gateway';
        } elseif (str_contains($tplText, 'macOS') || str_contains($tplText, 'Windows')) {
            $role = 'workstation';
        } else {
            $role = 'server';
        }

        $hp = $problems[$h['hostid']] ?? [];
        $maxSev = $hp ? max(array_column($hp, 'severity')) : null;
        $ping = $icmp[$h['hostid']] ?? [];
        if ($role === 'external') {
            $status = $maxSev === null ? 'online' : ($maxSev >= 4 ? 'offline' : 'degradado');
        } elseif (isset($ping['icmpping'])) {
            $status = $ping['icmpping'] >= 1 ? (($ping['icmppingloss'] ?? 0) > 0 ? 'degradado' : 'online') : 'offline';
        } else {
            $status = hostStatus($h);
        }
        if ($role === 'proxy') {
            // o estado real do proxy está no registro de proxy (último contato com o server)
            foreach ($proxies as $p) {
                if ($p['name'] === $h['host'] || count($proxies) === 1) {
                    $status = $p['online'] ? 'online' : 'offline';
                }
            }
        }
        if ($status === 'online' && $maxSev !== null && $maxSev >= 4 && $role !== 'external') {
            $status = 'degradado';     // responde, mas tem problema alto/desastre
        }

        $nodes[] = [
            'id' => $h['hostid'], 'name' => $h['name'], 'host' => $h['host'], 'ip' => $ip, 'role' => $role,
            'status' => $status, 'groups' => $groups, 'templates' => $tpls,
            'latency_ms' => isset($ping['icmppingsec']) ? round($ping['icmppingsec'] * 1000, 2) : null,
            'loss' => $ping['icmppingloss'] ?? null,
            'problems' => array_slice($hp, 0, 6), 'problem_count' => count($hp), 'max_severity' => $maxSev,
            'via_proxy' => $h['monitored_by'] === '1' ? ($proxies[$h['proxyid']]['name'] ?? 'proxy') : null,
            'proxyid' => $h['monitored_by'] === '1' ? $h['proxyid'] : null,
        ];
    }

    return ['generated_at' => time(), 'nodes' => $nodes, 'proxies' => $proxies,
        'lan' => $cfg['lan_label'] ?? '',
        'links' => ['zabbix' => rtrim($cfg['zabbix_front'], '/'), 'glpi' => rtrim($cfg['glpi_front'], '/')]];
}

try {
    $action = $_GET['action'] ?? 'data';
    $ttl = (int)($cfg['cache_ttl'] ?? 20);
    $cacheFile = sys_get_temp_dir() . '/dc360_resp_' . md5(json_encode(array_diff_key($_GET, ['nocache' => 1, 'debug' => 1, '_' => 1])));
    if ($ttl > 0 && $action !== 'hosts' && !isset($_GET['nocache']) && is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
        echo file_get_contents($cacheFile);
        exit;
    }
    $out = match ($action) {
        'hosts' => array_map(fn($h) => ['hostid' => $h['hostid'], 'name' => $h['name']], hosts()),
        'data' => data(),
        'office' => officeNetwork(),
        'si' => siServers(),
        'updates' => updates(),
        'topo' => ['url' => $cfg['topology_url'] ?? null],
        'hostlog' => hostLog(),
        'ticketlog' => ticketLog(),
        'topology' => topology(),
        default => fail(400, 'Ação desconhecida'),
    };
    if (isset($_GET['debug']) && is_array($out)) {
        $out['timings'] = $TIMINGS;
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($ttl > 0 && $action !== 'hosts' && !isset($_GET['debug'])) {
        @file_put_contents($cacheFile, $json);
    }
    echo $json;
} catch (Throwable $e) {
    error_log('[DataCenter360] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    fail(502, $e->getMessage() . (isset($_GET['debug']) ? ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']' : ''));
}
