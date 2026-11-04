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
// Ajustes salvos pela tela do GLPI (Configurar → Plugins → NOC 360) valem mais que o config.php do instalador.
$ovr = is_readable('/var/lib/datacenter360/config.json') ? json_decode((string)file_get_contents('/var/lib/datacenter360/config.json'), true) : null;
if (is_array($ovr)) {
    foreach (['zabbix_url', 'zabbix_token', 'zabbix_front', 'glpi_front', 'severities', 'sla_hours', 'list_max', 'glpi_max_tickets', 'cache_ttl', 'glpi_entity_prefix', 'alertas', 'mapa_entidades'] as $k) {
        if (array_key_exists($k, $ovr) && $ovr[$k] !== null && $ovr[$k] !== '' && $ovr[$k] !== []) {
            $cfg[$k] = $ovr[$k];
        }
    }
    if (array_key_exists('zabbix_verify', $ovr)) {
        $cfg['zabbix_verify'] = (bool)$ovr['zabbix_verify'];
    }
}
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
/** Opções do cURL para o certificado HTTPS do Zabbix (config 'zabbix_verify', padrão verificar). */
function zbxSsl(): array
{
    global $cfg;
    $v = (bool)($cfg['zabbix_verify'] ?? true);
    return [CURLOPT_SSL_VERIFYPEER => $v, CURLOPT_SSL_VERIFYHOST => $v ? 2 : 0];
}

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
            CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params ?: new stdClass(), 'id' => 1])] + zbxSsl());
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
    ] + zbxSsl());
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
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => 'apiinfo.version', 'params' => new stdClass(), 'id' => 1])] + zbxSsl());
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

/* ---------------- Mapa de rede (tela Topologia): hosts por camada, links e APs, e a ficha de cada ativo */

const MAP_TIERS = [['link', 'Links'], ['firewall', 'Firewalls'], ['switch', 'Switches'], ['fisico', 'Servidores físicos'], ['vm', 'Virtualização'],
    ['servidor', 'Servidores'], ['web', 'Serviços web'], ['outros', 'Outros']];

/** Camada do ativo. 'map_tiers' (config) = ['camada' => '/regex/i'] vence as regras padrão, que olham nome, grupos e templates. */
function mapTier(array $h, string $ip, array $groups, array $tpls): string
{
    global $cfg;
    $txt = $h['name'] . ' ' . implode(' ', $tpls);
    foreach ($cfg['map_tiers'] ?? [] as $tier => $rx) {
        if (@preg_match($rx, $txt)) {
            return (string)$tier;
        }
    }
    if (in_array('Website', $groups, true) || $ip === '127.0.0.1' || preg_match('/^web\b/i', $h['name'])) {
        return 'web';
    }
    if (preg_match('/idrac|esxi|\bilo\b|ipmi|hypervisor|f[ií]sico/iu', $h['name'])) {
        return 'fisico';
    }
    if (preg_match('/(^|[\s|])(fw|firewall)\b|fortigate|palo alto|pfsense|sophos/i', $txt)) {
        return 'firewall';
    }
    if (preg_match('/switch|(^|[\s|])sw\d*\b|fortiswitch/i', $txt)) {
        return 'switch';
    }
    if (preg_match('/^vm\b|vmware (guest|vm)|hyper-v guest/i', $txt)) {
        return 'vm';
    }
    if (preg_match('/linux|windows|macos|esxi/i', $txt)) {
        return 'servidor';
    }
    return 'outros';
}

function mapa(): array
{
    global $cfg;
    return fileCache('mapa', 30, function () use ($cfg) {
        $want = array_values(array_unique(array_merge($cfg['map_groups'] ?? [], ($cfg['map_groups'] ?? []) ? [] : array_merge($cfg['si_groups'] ?? [], $cfg['office_groups'] ?? []))));
        $w1 = zbxBatchStrict([
            'hosts' => ['host.get', ['output' => ['hostid', 'host', 'name', 'active_available', 'maintenance_status'], 'filter' => ['status' => 0],
                'selectInterfaces' => ['ip', 'type', 'available', 'main'], 'selectHostGroups' => ['name'], 'selectParentTemplates' => ['name'],
                'selectInventory' => ['os', 'model', 'vendor', 'hardware', 'type'], 'sortfield' => 'name']],
            'problems' => ['problem.get', ['output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged'], 'severities' => sevList(), 'sortfield' => ['eventid'], 'sortorder' => 'DESC']],
            'icmp' => ['item.get', ['output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock'], 'filter' => ['key_' => ['icmpping']], 'monitored' => true]],
            'upt' => ['item.get', ['output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'units'], 'search' => ['key_' => 'uptime'], 'monitored' => true]],
        ]);
        $hosts = array_values(array_filter($w1['hosts'], function ($h) use ($want) {
            return !$want || array_intersect($want, array_column($h['hostgroups'], 'name'));
        }));
        $ids = array_flip(array_column($hosts, 'hostid'));
        $w2 = [];
        if ($w1['problems']) {
            $w2['phosts'] = ['event.get', ['output' => ['eventid'], 'eventids' => array_column($w1['problems'], 'eventid'), 'selectHosts' => ['hostid']]];
        }
        $icmpIds = array_column(array_filter($w1['icmp'], fn($i) => isset($ids[$i['hostid']])), 'itemid');
        if ($icmpIds) {
            $w2['trend'] = ['trend.get', ['output' => ['itemid', 'num', 'value_avg'], 'itemids' => $icmpIds, 'time_from' => time() - 7 * 86400, 'time_till' => time()]];
        }
        $w2 = $w2 ? zbxBatchStrict($w2) : [];

        $probHost = [];
        foreach ($w2['phosts'] ?? [] as $e) {
            $probHost[$e['eventid']] = $e['hosts'][0]['hostid'] ?? null;
        }
        $probs = [];
        foreach ($w1['problems'] as $p) {
            if (($h = $probHost[$p['eventid']] ?? null) && isset($ids[$h])) {
                $probs[$h][] = ['name' => $p['name'], 'severity' => (int)$p['severity'], 'clock' => (int)$p['clock']];
            }
        }
        $ping = [];
        foreach ($w1['icmp'] as $i) {
            $ping[$i['hostid']] = ['item' => $i['itemid'], 'up' => (float)$i['lastvalue'] >= 1, 'has' => (int)$i['lastclock'] > 0];
        }
        $tr = [];
        foreach ($w2['trend'] ?? [] as $t) {
            $tr[$t['itemid']]['n'] = ($tr[$t['itemid']]['n'] ?? 0) + (int)$t['num'];
            $tr[$t['itemid']]['s'] = ($tr[$t['itemid']]['s'] ?? 0) + (float)$t['value_avg'] * (int)$t['num'];
        }
        // uptime: o melhor item de cada host (agente, SNMP ou VMware), ignorando os zerados e os de serviços
        $pri = fn($k) => $k === 'system.uptime' ? 0 : (stripos($k, 'fgsysuptime') !== false ? 1 : (str_starts_with($k, 'vmware.hv.uptime') ? 2 : (str_starts_with($k, 'system.net.uptime') ? 3 : 9)));
        $best = [];
        foreach ($w1['upt'] as $i) {
            if (!isset($ids[$i['hostid']]) || preg_match('/mysql|wtp|zabbix\[/i', $i['key_']) || !in_array($i['units'], ['uptime', 's'], true) || (float)$i['lastvalue'] <= 0) {
                continue;
            }
            if (!isset($best[$i['hostid']]) || $pri($i['key_']) < $best[$i['hostid']][0]) {
                $best[$i['hostid']] = [$pri($i['key_']), (int)round((float)$i['lastvalue']), (int)$i['lastclock']];
            }
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
            $pr = $probs[$h['hostid']] ?? [];
            usort($pr, fn($a, $b) => $b['severity'] <=> $a['severity'] ?: $a['clock'] <=> $b['clock']);
            $worst = $pr ? $pr[0]['severity'] : -1;
            $pg = $ping[$h['hostid']] ?? null;
            $stat = hostStatus($h);
            $state = $h['maintenance_status'] === '1' ? 'maintenance' : ($pg && $pg['has'] ? ($pg['up'] ? 'up' : 'down') : ($stat === 'offline' ? 'down' : 'up'));
            $t = $pg ? ($tr[$pg['item']] ?? null) : null;
            $inv = is_array($h['inventory'] ?? null) ? $h['inventory'] : [];
            $nodes[] = ['id' => $h['hostid'], 'name' => $h['name'], 'ip' => $ip, 'tier' => mapTier($h, $ip, $groups, $tpls), 'state' => $state,
                'problems' => count($pr), 'worst' => $worst, 'probs' => array_slice($pr, 0, 3),
                'avail' => $t && $t['n'] ? round(100 * $t['s'] / $t['n'], 3) : null,
                'open_since' => $state === 'down' ? ($pr ? min(array_column($pr, 'clock')) : null) : null,
                'uptime' => isset($best[$h['hostid']]) ? $best[$h['hostid']][1] : null, 'uptime_at' => isset($best[$h['hostid']]) ? $best[$h['hostid']][2] : null,
                'vendor' => $inv['vendor'] ?? '', 'model' => $inv['model'] ?? '', 'os' => $inv['os'] ?? '', 'hw' => $inv['hardware'] ?? ($inv['type'] ?? ''),
                'groups' => $groups];
        }
        try {
            $net = officeNetwork();
        } catch (Throwable) {
            $net = ['links' => [], 'aps' => []];
        }
        return ['tiers' => array_map(fn($t) => ['id' => $t[0], 'label' => $t[1]], MAP_TIERS), 'nodes' => $nodes, 'links' => $net['links'] ?? [], 'aps' => $net['aps'] ?? [],
            'zabbix' => rtrim($cfg['zabbix_front'], '/'), 'generated' => time()];
    });
}

/** Ficha de um ativo: disponibilidade e quedas em 30 dias, métricas, problemas ativos e histórico de alertas. */
function hostFicha(): array
{
    global $cfg;
    $hid = preg_replace('/\D/', '', (string)($_GET['hostid'] ?? ''));
    if ($hid === '') {
        fail(400, 'hostid obrigatório');
    }
    $now = time();
    $w = zbxBatchStrict([
        'host' => ['host.get', ['output' => ['hostid', 'host', 'name', 'description', 'status', 'maintenance_status', 'maintenanceid', 'maintenance_from', 'monitored_by', 'proxyid', 'inventory_mode'],
            'hostids' => [$hid], 'selectInterfaces' => ['type', 'main', 'ip', 'dns', 'port', 'useip', 'available', 'error'], 'selectTags' => ['tag', 'value'],
            'selectHostGroups' => ['name'], 'selectParentTemplates' => ['name'], 'selectInventory' => 'extend']],
        'info' => ['item.get', ['output' => ['name', 'key_', 'lastvalue', 'units', 'lastclock'], 'hostids' => [$hid], 'monitored' => true, 'searchByAny' => true, 'limit' => 40,
            'search' => ['key_' => ['system.uptime', 'system.sw.os', 'system.hostname', 'agent.version', 'system.cpu.num', 'vm.memory.size[total]', 'sysUpTime', 'sysName', 'sysDescr',
                'sysLocation', 'sysContact', 'system.hw.serialnumber', 'system.hw.model', 'system.descr', 'system.location', 'system.contact']]]],
        'items' => ['item.get', ['output' => ['itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type'], 'hostids' => [$hid], 'monitored' => true, 'limit' => 600, 'sortfield' => 'name']],
        'active' => ['problem.get', ['output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged'], 'hostids' => [$hid], 'severities' => sevList(), 'sortfield' => ['eventid'], 'sortorder' => 'DESC']],
        'events' => ['event.get', ['output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid', 'acknowledged'], 'hostids' => [$hid], 'source' => 0, 'object' => 0, 'value' => 1,
            'severities' => sevList(), 'time_from' => $now - 30 * 86400, 'sortfield' => ['clock'], 'sortorder' => 'DESC', 'limit' => 300]],
    ]);
    $h = $w['host'][0] ?? fail(404, 'Host não encontrado');
    $it = [];
    foreach ($w['items'] as $i) {
        $it[$i['key_']] ??= $i;
    }
    // primeiro item cujo código começa com o prefixo (ex.: vmware.hv.uptime[...])
    $first = function (string ...$prefixes) use ($w) {
        foreach ($prefixes as $p) {
            foreach ($w['items'] as $i) {
                if (str_starts_with($i['key_'], $p) && ($i['lastvalue'] ?? '') !== '' && is_numeric($i['lastvalue'])) {
                    return (float)$i['lastvalue'];
                }
            }
        }
        return null;
    };
    // datastores do VMware: tamanho, % livre e latências
    $ds = [];
    foreach ($w['items'] as $i) {
        if (preg_match('/^vmware\.(?:hv\.)?datastore\.(size|read|write)\[[^,]*,(.+?)(?:,(pfree|latency))?\]$/', $i['key_'], $m) && is_numeric($i['lastvalue'] ?? null)) {
            $d = &$ds[$m[2]];
            $d['name'] = $m[2];
            if ($m[1] === 'size') {
                $d[($m[3] ?? '') === 'pfree' ? 'pfree' : 'total'] = (float)$i['lastvalue'];
            } else {
                $d[$m[1]] = (float)$i['lastvalue'];
            }
            unset($d);
        }
    }
    $todos = [];
    foreach ($w['items'] as $i) {
        if (($i['lastvalue'] ?? '') === '' || strlen((string)$i['lastvalue']) > 400) {
            continue;
        }
        $todos[] = ['name' => $i['name'], 'value' => $i['lastvalue'], 'units' => $i['units'] ?? '', 'clock' => (int)($i['lastclock'] ?? 0), 'key' => $i['key_']];
    }
    $rec = [];
    $rids = array_values(array_filter(array_column($w['events'], 'r_eventid'), fn($i) => $i !== '0'));
    if ($rids) {
        foreach (zbx('event.get', ['output' => ['eventid', 'clock'], 'eventids' => $rids]) as $r) {
            $rec[$r['eventid']] = (int)$r['clock'];
        }
    }
    $avail = null;
    if (isset($it['icmpping'])) {
        $n = $s = 0;
        foreach (zbx('trend.get', ['output' => ['num', 'value_avg'], 'itemids' => [$it['icmpping']['itemid']], 'time_from' => $now - 30 * 86400, 'time_till' => $now]) as $t) {
            $n += (int)$t['num'];
            $s += (float)$t['value_avg'] * (int)$t['num'];
        }
        $avail = $n ? round(100 * $s / $n, 3) : null;
    }
    $availEst = false;
    $comp = [];
    $sibIds = array_slice(array_values(array_filter(array_map(fn($x) => preg_replace('/\D/', '', $x), explode(',', (string)($_GET['siblings'] ?? ''))))), 0, 6);
    if ($sibIds) {
        $hs = zbx('host.get', ['hostids' => $sibIds, 'output' => ['hostid', 'name']]);
        $its = zbx('item.get', ['hostids' => $sibIds, 'output' => ['hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units'], 'monitored' => true, 'limit' => 900, 'sortfield' => 'name']);
        foreach ($hs as $x) {
            $list = [];
            foreach ($its as $i) {
                if ($i['hostid'] === $x['hostid'] && ($i['lastvalue'] ?? '') !== '' && strlen((string)$i['lastvalue']) <= 300) {
                    $list[] = ['name' => $i['name'], 'value' => $i['lastvalue'], 'units' => $i['units'] ?? '', 'clock' => (int)($i['lastclock'] ?? 0)];
                }
            }
            $comp[] = ['hostid' => $x['hostid'], 'name' => $x['name'], 'items' => array_slice($list, 0, 120)];
        }
    }
    $front = rtrim($cfg['zabbix_front'], '/');
    $ev = array_map(fn($e) => ['clock' => (int)$e['clock'], 'severity' => (int)$e['severity'], 'name' => $e['name'], 'end' => $rec[$e['r_eventid']] ?? null,
        'acknowledged' => $e['acknowledged'] === '1', 'url' => $front . '/tr_events.php?triggerid=' . $e['objectid'] . '&eventid=' . $e['eventid']], $w['events']);
    $big = array_values(array_filter($ev, fn($e) => $e['severity'] >= 4));
    $closed = array_values(array_filter($big, fn($e) => $e['end']));
    $down = array_sum(array_map(fn($e) => $e['end'] - $e['clock'], $closed));
    if ($avail === null) {  // sem ICMP (ex.: ESXi, BMC): estima pelas quedas Alta e Desastre dos 30 dias
        $avail = round(max(0, 100 - 100 * $down / (30 * 86400)), 3);
        $availEst = true;
    }
    $num = fn($k) => isset($it[$k]) && is_numeric($it[$k]['lastvalue']) ? (float)$it[$k]['lastvalue'] : null;
    $ip = '';
    foreach ($h['interfaces'] as $if) {
        $ip = $if['main'] === '1' ? $if['ip'] : $ip;
    }
    $iface = ['1' => 'Agente', '2' => 'SNMP', '3' => 'IPMI', '4' => 'JMX'];
    $avl = ['0' => 'desconhecido', '1' => 'disponível', '2' => 'indisponível'];
    $inv = is_array($h['inventory']) && !array_is_list($h['inventory']) ? $h['inventory'] : [];
    $inv = array_filter($inv, fn($v, $k) => !in_array($v, [null, '', '0'], true) && !in_array($k, ['hostid', 'inventory_mode'], true), ARRAY_FILTER_USE_BOTH);
    $maint = null;
    if ($h['maintenance_status'] === '1' && !in_array($h['maintenanceid'] ?? '0', [null, '0'], true)) {
        try {
            $m = zbx('maintenance.get', ['maintenanceids' => [$h['maintenanceid']], 'output' => ['name', 'description', 'active_since', 'active_till']]);
            $maint = $m ? ['name' => $m[0]['name'], 'description' => $m[0]['description'] ?? '', 'since' => (int)($h['maintenance_from'] ?: $m[0]['active_since']), 'till' => (int)$m[0]['active_till']] : ['name' => 'em manutenção'];
        } catch (Throwable $e) {
            $maint = ['name' => 'em manutenção'];
        }
    }
    $proxy = null;
    if (!in_array($h['proxyid'] ?? '0', [null, '', '0'], true)) {
        try {
            $px = zbx('proxy.get', ['proxyids' => [$h['proxyid']], 'output' => 'extend']);
            $proxy = $px ? ($px[0]['name'] ?? $px[0]['host'] ?? null) : null;
        } catch (Throwable $e) {
        }
    }
    $seen = [];
    $info = [];
    foreach ($w['info'] as $i) {
        if (($i['lastvalue'] ?? '') === '' || isset($seen[$i['name']])) {
            continue;
        }
        $seen[$i['name']] = true;
        $info[] = ['name' => $i['name'], 'value' => $i['lastvalue'], 'units' => $i['units'] ?? '', 'clock' => (int)($i['lastclock'] ?? 0)];
    }
    return ['hostid' => $hid, 'name' => $h['name'], 'host' => $h['host'], 'ip' => $ip, 'maintenance' => $h['maintenance_status'] === '1',
        'description' => (string)($h['description'] ?? ''), 'status' => ($h['status'] ?? '0') === '1' ? 'desativado' : 'monitorado', 'proxy' => $proxy, 'maint' => $maint,
        'inventory_mode' => ['-1' => 'desativado', '0' => 'manual', '1' => 'automático'][(string)($h['inventory_mode'] ?? '')] ?? '',
        'tags' => $h['tags'] ?? [], 'info' => $info,
        'interfaces' => array_map(fn($i) => ['type' => $iface[(string)($i['type'] ?? '')] ?? '?', 'main' => ($i['main'] ?? '') === '1', 'address' => ($i['useip'] ?? '1') === '1' ? ($i['ip'] ?? '') : ($i['dns'] ?? ''),
            'port' => $i['port'] ?? '', 'available' => $avl[(string)($i['available'] ?? '')] ?? 'desconhecido', 'error' => $i['error'] ?? ''], $h['interfaces'] ?? []),
        'outages' => array_map(fn($e) => ['clock' => $e['clock'], 'end' => $e['end'], 'name' => $e['name'], 'severity' => $e['severity'], 'url' => $e['url']], array_slice($big, 0, 40)),
        'groups' => array_column($h['hostgroups'], 'name'), 'templates' => array_column($h['parentTemplates'], 'name'),
        'inventory' => (object)$inv,
        'availability' => $avail, 'availability_est' => $availEst, 'componentes' => $comp, 'datastores' => array_values($ds), 'items' => array_slice($todos, 0, 150), 'drops' => count($big), 'downtime' => $down, 'mttr' => $closed ? (int)round($down / count($closed)) : null,
        'cpu' => $num('system.cpu.util') ?? $first('vmware.hv.cpu.usage.perf', 'system.cpu.load'), 'memory' => $num('vm.memory.util') ?? $num('vm.memory.utilization') ?? ($first('vmware.hv.hw.memory') ? 100 * $first('vmware.hv.memory.used') / $first('vmware.hv.hw.memory') : null),
        'latency_ms' => $num('icmppingsec') !== null ? round($num('icmppingsec') * 1000, 2) : null, 'loss' => $num('icmppingloss'), 'uptime' => $num('system.uptime') ?? $first('vmware.hv.uptime', 'system.net.uptime') ?? (($first('sysUpTime', 'hrSystemUptime')) !== null ? $first('sysUpTime', 'hrSystemUptime') / 100 : null),
        'active' => array_map(fn($p) => ['name' => $p['name'], 'severity' => (int)$p['severity'], 'clock' => (int)$p['clock'], 'acknowledged' => $p['acknowledged'] === '1'], $w['active']),
        'history' => array_slice(array_values(array_filter($ev, fn($e) => $e['clock'] >= $now - 7 * 86400)), 0, 40),
        'front' => $front . '/zabbix.php?action=problem.view&hostids%5B%5D=' . $hid . '&filter_set=1', 'front_latest' => $front . '/zabbix.php?action=latest.view&hostids%5B%5D=' . $hid . '&filter_set=1', 'days' => 30];
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

/** Severidades que o painel mostra (padrão: Desastre, Alta e Atenção/Warning; troque em config.php, ex.: [5, 4, 3] para Desastre, Alta e Média). */
/** Linhas do mapa entidade → host group → severidades, salvas na tela de configuração do plugin. */
function mapaEntidades(): array
{
    global $cfg;
    return array_values(array_filter((array)($cfg['mapa_entidades'] ?? []), fn($m) => is_array($m) && !empty($m['group_name'])));
}

/** Nome do host group => severidades que aparecem para ele (união das linhas do mapa). */
function grupoSev(): array
{
    $out = [];
    foreach (mapaEntidades() as $m) {
        $out[$m['group_name']] = array_values(array_unique(array_merge($out[$m['group_name']] ?? [], array_map('intval', (array)($m['severities'] ?? [])))));
    }
    return $out;
}

/** O alerta de severidade $sev, de um host destes grupos, aparece no painel? */
function sevPermitida(array $groupNames, int $sev, array $gsev): bool
{
    $m = [];
    foreach ($groupNames as $g) {
        if (isset($gsev[$g])) {
            $m = array_merge($m, $gsev[$g]);
        }
    }
    return $m ? in_array($sev, $m, true) : in_array($sev, sevList(), true);
}

/** Severidades a buscar no Zabbix: as do painel mais as liberadas por algum host group do mapa. */
function sevAll(): array
{
    return array_values(array_unique(array_merge(sevList(), ...array_values(grupoSev()))));
}

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
    foreach (mapaEntidades() as $m) {   // linhas do mapa da tela de configuração: valem mais que o nome
        if (!empty($m['entity_name'])) {
            $map[$m['group_name']] = cliOf((string)$m['entity_name']);
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

/**
 * Horas lançadas no TrackingTime pelos analistas do NOC, por dia e por centro de custo (projeto).
 * Configuração em config.php:
 *   'trackingtime' => ['account_id' => 123, 'user' => 'email', 'password' => '...',   // acesso só para leitura
 *                      'users' => [id => 'Nome', ...]]                                 // analistas mostrados
 * Sem a chave 'trackingtime' o painel de horas não aparece. Só faz GET; nada é lançado no TrackingTime.
 * 'fixture' => '/caminho/eventos.json' lê eventos de um arquivo (teste sem acesso à API).
 */
function ttEventos(array $t, int $uid, string $from, string $to): array
{
    if (!empty($t['fixture'])) {
        $all = json_decode((string)file_get_contents($t['fixture']), true) ?: [];
        return array_values(array_filter($all, fn($e) => (int)($e['uid'] ?? $e['user_id'] ?? 0) === $uid));
    }
    $out = [];
    $size = 500;
    for ($page = 0; $page < 20; $page++) {
        $url = 'https://app.trackingtime.co/api/v4/' . rawurlencode((string)$t['account_id']) . '/events?' . http_build_query([
            'filter' => 'USER', 'id' => $uid, 'from' => $from, 'to' => $to, 'page' => $page, 'page_size' => $size]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $t['user'] . ':' . $t['password'], CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            throw new RuntimeException("TrackingTime respondeu HTTP $code");
        }
        $j = json_decode((string)$raw, true);
        $rows = is_array($j) && isset($j['data']) ? $j['data'] : (is_array($j) ? $j : []);
        $out = array_merge($out, $rows);
        if (count($rows) < $size) {
            break;
        }
    }
    return $out;
}

/** Foto do perfil de cada usuário no TrackingTime (id => url); falha em silêncio, o painel cai nas iniciais. */
function ttAvatares(array $t): array
{
    try {
        if (!empty($t['fixture_users'])) {
            return json_decode((string)file_get_contents($t['fixture_users']), true) ?: [];
        }
        if (!empty($t['fixture'])) {
            return [];
        }
        $ch = curl_init('https://app.trackingtime.co/api/v4/' . rawurlencode((string)$t['account_id']) . '/users?' . http_build_query(['filter' => 'ALL', 'page_size' => 200]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $t['user'] . ':' . $t['password'], CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $j = json_decode((string)$raw, true);
        $rows = is_array($j) && isset($j['data']) ? $j['data'] : (is_array($j) ? $j : []);
        $out = [];
        foreach ($rows as $u) {
            if (isset($u['id'], $u['avatar_url'])) {
                $out[(int)$u['id']] = $u['avatar_url'];
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function horas(): array
{
    global $cfg;
    $t = $cfg['trackingtime'] ?? null;
    $users = is_array($t) ? ($t['users'] ?? []) : [];
    if (!$users || !(!empty($t['fixture']) || (!empty($t['account_id']) && !empty($t['user']) && !empty($t['password'])))) {
        return ['configured' => false];
    }
    $from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')));
    $to = (string)($_GET['to'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
        fail(400, 'Período inválido');
    }
    if (strtotime($to) - strtotime($from) > 92 * 86400) {
        $from = date('Y-m-d', strtotime($to) - 92 * 86400);
    }
    return fileCache("horas:v2:$from:$to", (int)($t['cache'] ?? 300), function () use ($t, $users, $from, $to) {
        $days = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $days[date('Y-m-d', $d)] = ['day' => date('Y-m-d', $d), 'dow' => (int)date('w', $d), 'by' => [], 'total' => 0];
        }
        $people = [];
        $cc = [];
        $fotos = ttAvatares($t);
        foreach ($users as $uid => $name) {
            $uid = (int)$uid;
            $people[$uid] = ['id' => $uid, 'name' => $name, 'total' => 0, 'days' => 0, 'avatar' => $fotos[$uid] ?? null];
            foreach (ttEventos($t, $uid, $from, $to) as $e) {
                $start = (string)($e['s'] ?? $e['start'] ?? '');
                $sec = (int)($e['d'] ?? $e['duration'] ?? 0);
                $people[$uid]['avatar'] ??= ($e['uau'] ?? $e['user_avatar_url'] ?? null);
                $day = substr($start, 0, 10);
                if ($sec <= 0 || !isset($days[$day])) {
                    continue;
                }
                $proj = trim((string)($e['p'] ?? $e['project_name'] ?? '')) ?: 'Sem projeto';
                $cust = trim((string)($e['c'] ?? $e['customer_name'] ?? ''));
                $days[$day]['by'][$uid] = ($days[$day]['by'][$uid] ?? 0) + $sec;
                $days[$day]['total'] += $sec;
                $people[$uid]['total'] += $sec;
                $cc[$proj] ??= ['name' => $proj, 'customer' => $cust, 'total' => 0, 'by' => []];
                $cc[$proj]['total'] += $sec;
                $cc[$proj]['by'][$uid] = ($cc[$proj]['by'][$uid] ?? 0) + $sec;
            }
        }
        foreach ($days as $d) {
            foreach ($d['by'] as $uid => $sec) {
                $people[$uid]['days']++;
            }
        }
        usort($cc, fn($a, $b) => $b['total'] <=> $a['total']);
        return ['configured' => true, 'url' => rtrim((string)($t['url'] ?? 'https://pro.trackingtime.co'), '/'), 'from' => $from, 'to' => $to, 'people' => array_values($people), 'days' => array_values($days), 'cc' => $cc,
            'total' => array_sum(array_column($people, 'total')), 'generated' => time()];
    });
}

/** Palavras do nome sem acento, em minúsculas e sem partículas (de, da, dos...). */
function nomeTokens(string $n): array
{
    $n = strtr(mb_strtolower($n), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ò' => 'o',
        'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'ç' => 'c']);
    $t = preg_split('/[^a-z]+/', $n, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_diff($t, ['de', 'da', 'do', 'das', 'dos', 'e', 'tecnico', 'ext']));
}

/** Mesma pessoa? Todas as palavras do nome menor (mínimo 2) estão no maior (nome completo ou login nome.sobrenome). */
function mesmaPessoa(string $a, string $b): bool
{
    $x = nomeTokens($a);
    $y = nomeTokens($b);
    [$menor, $maior] = count($x) <= count($y) ? [$x, $y] : [$y, $x];
    return count($menor) >= 2 && !array_diff($menor, $maior);
}

/**
 * MTTD e MTTR de cada analista do NOC (mesmos analistas do quadro de horas), ligados pelo nome:
 * Zabbix = alertas do período cujo 1º reconhecimento foi dele (MTTD) e que ele reconheceu (MTTR até a recuperação);
 * GLPI = chamados do período atribuídos a ele (MTTD até o 1º atendimento, MTTR até a solução).
 */
function analistas(): array
{
    global $cfg;
    $t = $cfg['trackingtime'] ?? null;
    $users = is_array($t) ? ($t['users'] ?? []) : [];
    if (!$users) {
        return ['configured' => false];
    }
    $from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')));
    $to = (string)($_GET['to'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
        fail(400, 'Período inválido');
    }
    $now = time();
    $raw = zabbixRaw(strtotime($from . ' 00:00:00'), min($now, strtotime($to . ' 23:59:59')));
    $tickets = array_map(fn($x) => ticketRow($x, $now), glpiTickets(strtotime($from . ' 00:00:00'), strtotime($to . ' 23:59:59'), false));
    $avg = fn(array $v) => $v ? (int)round(array_sum($v) / count($v)) : null;
    $out = [];
    $usedTech = $usedAck = [];
    foreach ($users as $uid => $name) {
        $dz = $rz = $dg = $rg = [];
        $nz = 0;
        foreach ($raw['events'] as $e) {
            $first = $e['ackfirst'] ?? null;
            $mine = false;
            foreach ($e['ackers'] ?? [] as $a) {
                [$n, $u] = array_pad(explode('|', $a, 2), 2, '');
                if (mesmaPessoa($name, $n) || mesmaPessoa($name, $u)) {
                    $mine = true;
                    $usedAck[$n ?: $u] = true;
                }
            }
            if ($mine) {
                $nz++;
                if ($e['repair'] !== null) {
                    $rz[] = $e['repair'];
                }
            }
            if ($first && $e['detect'] !== null && (mesmaPessoa($name, $first[0]) || mesmaPessoa($name, $first[1]))) {
                $dz[] = $e['detect'];
            }
        }
        $ng = 0;
        foreach ($tickets as $tk) {
            $mine = false;
            foreach (explode(',', (string)$tk['tech']) as $tn) {
                if (mesmaPessoa($name, trim($tn))) {
                    $mine = true;
                    $usedTech[trim($tn)] = true;
                }
            }
            if (!$mine) {
                continue;
            }
            $ng++;
            if ($tk['take_seconds'] > 0) {
                $dg[] = $tk['take_seconds'];
            }
            if ($tk['solvedate'] && $tk['resolve_seconds'] !== null) {
                $rg[] = $tk['resolve_seconds'];
            }
        }
        $out[] = ['id' => (int)$uid, 'name' => $name, 'zbx' => ['n' => $nz, 'mttd' => $avg($dz), 'nd' => count($dz), 'mttr' => $avg($rz), 'nr' => count($rz)],
            'glpi' => ['n' => $ng, 'mttd' => $avg($dg), 'nd' => count($dg), 'mttr' => $avg($rg), 'nr' => count($rg)]];
    }
    // quem aparece no GLPI/Zabbix e não casou com nenhum analista (para ajustar nomes, se preciso)
    $techs = [];
    foreach ($tickets as $tk) {
        foreach (explode(',', (string)$tk['tech']) as $tn) {
            $tn = trim($tn);
            if ($tn !== '' && $tn !== 'Sem responsável' && !isset($usedTech[$tn])) {
                $techs[$tn] = ($techs[$tn] ?? 0) + 1;
            }
        }
    }
    arsort($techs);
    return ['configured' => true, 'from' => $from, 'to' => $to, 'people' => $out, 'outros_glpi' => array_slice(array_keys($techs), 0, 12), 'generated' => time()];
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
    return fileCache("zraw:v3:" . implode('', sevAll()) . ":$from:$till", (int)($cfg['cache_ttl'] ?? 20), function () use ($from, $till) {
        $sev = sevAll();
        $w1 = zbxBatchStrict([
            'hosts' => ['host.get', ['output' => ['hostid', 'host', 'name', 'active_available'], 'filter' => ['status' => 0],
                'selectInterfaces' => ['type', 'available'], 'selectHostGroups' => ['name'], 'sortfield' => 'name']],
            'problems' => ['problem.get', ['output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged'], 'severities' => $sev,
                'sortfield' => ['eventid'], 'sortorder' => 'DESC']],
            'events' => ['event.get', ['output' => ['eventid', 'clock', 'severity', 'name', 'r_eventid', 'acknowledged'], 'source' => 0, 'object' => 0, 'value' => 1,
                'severities' => $sev, 'time_from' => $from, 'time_till' => $till, 'selectHosts' => ['hostid', 'name'], 'selectAcknowledges' => ['clock', 'action', 'username', 'name', 'surname'],
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
            $who = fn($a) => trim(trim(($a['name'] ?? '') . ' ' . ($a['surname'] ?? '')) ?: (string)($a['username'] ?? ''));
            usort($acks, fn($a, $b) => (int)$a['clock'] <=> (int)$b['clock']);
            $events[] = ['clock' => (int)$e['clock'], 'severity' => (int)$e['severity'], 'hostid' => $e['hosts'][0]['hostid'] ?? null, 'host' => $e['hosts'][0]['name'] ?? '—',
                'acknowledged' => $e['acknowledged'] === '1', 'detect' => $acks ? (int)$acks[0]['clock'] - (int)$e['clock'] : null,
                'ackfirst' => $acks ? [$who($acks[0]), (string)($acks[0]['username'] ?? '')] : null,
                'ackers' => array_values(array_unique(array_map(fn($a) => $who($a) . '|' . ($a['username'] ?? ''), $acks))),
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

/** Tipos de alerta do navegador: [rótulo, grupo, ícone, cor, canais padrão, som]. Sobrescreva em config.php → 'alertas' => ['tipos' => ['zbx_5' => ['channels' => ['popup', 'voice'], 'tone' => 'sirene']]]. */
const ALERTAS_TIPOS = [
    'zbx_5'        => ['Desastre', 'Zabbix', 'ti-flame', '#e45959', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'sirene'],
    'zbx_4'        => ['Alta', 'Zabbix', 'ti-alert-octagon', '#e97659', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'duplo'],
    'zbx_3'        => ['Média', 'Zabbix', 'ti-alert-triangle', '#ffa059', ['flag', 'popup', 'voice'], 'suave'],
    'zbx_2'        => ['Atenção', 'Zabbix', 'ti-alert-circle', '#ffc859', ['flag', 'popup', 'voice'], 'suave'],
    'zbx_1'        => ['Informação', 'Zabbix', 'ti-info-circle', '#7499ff', [], 'suave'],
    'zbx_0'        => ['Não classificada', 'Zabbix', 'ti-help-circle', '#97aab3', [], 'suave'],
    'zbx_ok'       => ['Problema resolvido', 'Zabbix', 'ti-circle-check', '#22c55e', ['popup', 'voice'], 'sino'],
    'glpi_new'     => ['Chamado novo', 'GLPI', 'ti-ticket', '#3769B2', ['flag', 'sound', 'popup', 'voice'], 'sino'],
    'glpi_sla1h'   => ['SLA vence em até 1 h', 'GLPI', 'ti-hourglass-high', '#f59e0b', ['flag', 'sound', 'popup', 'voice'], 'duplo'],
    'glpi_slalate' => ['SLA vencido', 'GLPI', 'ti-hourglass-empty', '#ef4444', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'sirene'],
];

/**
 * Alertas para o navegador (js/noc360-alertas.js): problemas do Zabbix (ativos e resolvidos há pouco),
 * chamados abertos da entidade NOC (novos e SLA) e as preferências de canais, som e voz.
 * O que é "novo" quem decide é o navegador (cursor guardado nele), então esta ação não guarda estado.
 */
function alertas(): array
{
    global $cfg;
    $a = $cfg['alertas'] ?? [];
    $now = time();
    $sev = sevAll();
    $zbx = fileCache('alertas:z:' . implode(',', $sev), 10, function () use ($sev) {
        $w1 = zbxBatchStrict(['p' => ['problem.get', ['output' => ['eventid', 'r_eventid', 'r_clock', 'name', 'severity', 'clock', 'acknowledged'], 'severities' => $sev,
            'recent' => true, 'sortfield' => ['eventid'], 'sortorder' => 'DESC', 'limit' => 400]]]);
        $hosts = [];
        if ($w1['p']) {
            $w2 = zbxBatchStrict(['e' => ['event.get', ['output' => ['eventid'], 'eventids' => array_column($w1['p'], 'eventid'), 'selectHosts' => ['hostid', 'name']]]]);
            foreach ($w2['e'] as $e) {
                $hosts[$e['eventid']] = $e['hosts'][0] ?? null;
            }
        }
        return array_map(fn($p) => ['eventid' => (int)$p['eventid'], 'r_eventid' => (int)$p['r_eventid'], 'r_clock' => (int)$p['r_clock'], 'name' => $p['name'],
            'severity' => (int)$p['severity'], 'clock' => (int)$p['clock'], 'ack' => $p['acknowledged'] === '1',
            'host' => $hosts[$p['eventid']]['name'] ?? '—', 'hostid' => $hosts[$p['eventid']]['hostid'] ?? null], $w1['p']);
    });
    if (sevAll() !== sevList()) {   // severidades por host group (mapa da tela de configuração)
        $gsev = grupoSev();
        $hg = fileCache('alertas:hg', 300, function () {
            $o = [];
            foreach (zbx('host.get', ['output' => ['hostid'], 'selectHostGroups' => ['name'], 'filter' => ['status' => 0]]) as $h) {
                $o[$h['hostid']] = array_column($h['hostgroups'] ?? [], 'name');
            }
            return $o;
        });
        $zbx = array_values(array_filter($zbx, fn($x) => sevPermitida($hg[(string)$x['hostid']] ?? [], (int)$x['severity'], $gsev)));
    }
    $tk = fileCache('alertas:t', 20, fn() => glpiRemote() ? glpiTicketsRest(0, 0, true) : glpiTicketsDb(0, 0, true));
    $tickets = [];
    foreach ($tk as $t) {
        $r = ticketRow($t, $now);
        if (!$r['open']) {
            continue;
        }
        $tickets[] = ['id' => $r['id'], 'name' => $r['name'], 'date' => $r['date'], 'client' => $r['client'], 'status' => $r['status'], 'status_id' => $r['status_id'],
            'due' => $t['time_to_resolve'] ? strtotime($t['time_to_resolve']) : null, 'pending' => $r['pending']];
    }
    $tipos = [];
    foreach (ALERTAS_TIPOS as $k => [$label, $group, $icon, $color, $canais, $tone]) {
        $o = $a['tipos'][$k] ?? [];
        $tipos[$k] = ['label' => $label, 'group' => $group, 'icon' => $icon, 'color' => $color, 'tone' => $o['tone'] ?? $tone, 'channels' => $o['channels'] ?? $canais];
    }
    return [
        'ok' => true, 'now' => $now, 'problems' => $zbx, 'tickets' => $tickets, 'tipos' => $tipos,
        'prefs' => ['poll' => max(10, (int)($a['poll'] ?? 20)), 'volume' => max(0, min(100, (int)($a['volume'] ?? 80))) / 100, 'rate' => (float)($a['voice_rate'] ?? 1.05),
            'popup_seconds' => (int)($a['popup_seconds'] ?? 20), 'popup_max' => (int)($a['popup_max'] ?? 5), 'mute_minutes' => (int)($a['mute_minutes'] ?? 60),
            'quiet' => $a['quiet'] ?? null, 'sla_warn' => (int)($a['sla_warn_minutes'] ?? 60)],
        'links' => ['zabbix' => rtrim($cfg['zabbix_front'], '/'), 'glpi' => rtrim($cfg['glpi_front'], '/')],
    ];
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
    if (sevAll() !== sevList()) {   // há host group do mapa com severidades próprias
        $gsev = grupoSev();
        $hg = [];
        foreach ($zHosts as $h) {
            $hg[(string)$h['hostid']] = array_column($h['hostgroups'] ?? [], 'name');
        }
        $raw['problems'] = array_values(array_filter($raw['problems'], fn($x) => sevPermitida($hg[(string)$x['hostid']] ?? [], (int)$x['severity'], $gsev)));
        $raw['events'] = array_values(array_filter($raw['events'], fn($x) => sevPermitida($hg[(string)$x['hostid']] ?? [], (int)$x['severity'], $gsev)));
    }
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
        $agg[$c]['unacked'] += ($p['acknowledged'] || $p['severity'] < 4) ? 0 : 1;   // Sem ACK: só Desastre e Alta
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
        $unacked += ($p['acknowledged'] || $p['severity'] < 4) ? 0 : 1;   // Sem ACK: só Desastre e Alta
        $perHost[$p['hostid']][] = $p['severity'];
        $hostAct[$p['hostid']] = ($hostAct[$p['hostid']] ?? 0) + 1;
        $hostUn[$p['hostid']] = ($hostUn[$p['hostid']] ?? 0) + (($p['acknowledged'] || $p['severity'] < 4) ? 0 : 1);
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
            'active' => count($active), 'active_by_sev' => $activeCount, 'active_high' => (int)(($activeCount[5] ?? 0) + ($activeCount[4] ?? 0)), 'unacked' => $unacked,
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
        'mapa' => mapa(),
        'hostficha' => hostFicha(),
        'alertas' => alertas(),
        'horas' => horas(),
        'analistas' => analistas(),
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
