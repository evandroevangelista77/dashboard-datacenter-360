<?php

namespace GlpiPlugin\Sistemasinternos;

use CronTask;
use RuntimeException;
use Throwable;

/**
 * Wazuh: cliente do Indexer (OpenSearch) e a base comum das telas Vulnerabilidades e Segurança.
 *
 * A conexão é feita pelo endereço do Dashboard do Wazuh (porta 443, que repassa as buscas ao
 * Indexer) ou direto no Indexer (porta 9200). O usuário só precisa de leitura nos índices wazuh-*.
 * O GLPI guarda a senha criptografada e o navegador nunca fala com o Wazuh.
 */
class Wazuh
{
    public const IDX_ALERTS = 'wazuh-alerts-4.x-*';
    public const IDX_VULN   = 'wazuh-states-vulnerabilities-*';
    public const IDX_MON    = 'wazuh-monitoring-*';
    public const IDX_INV    = 'wazuh-states-inventory-%s-*';

    /** Severidade do Wazuh → [rótulo, cor, peso]. "-" é o que o Wazuh ainda não classificou. */
    public const SEV = [
        'Critical' => ['Crítica', '#e45959', 4],
        'High'     => ['Alta', '#e97659', 3],
        'Medium'   => ['Média', '#ffa059', 2],
        'Low'      => ['Baixa', '#7499ff', 1],
        '-'        => ['Sem classificação', '#97aab3', 0],
    ];

    public const STATUS = ['active' => 'Ativo', 'disconnected' => 'Desconectado', 'never_connected' => 'Nunca conectou', 'pending' => 'Pendente'];

    public static function isConfigured(): bool
    {
        return Settings::get('wazuh_url') !== '' && Settings::get('wazuh_user') !== '' && Settings::get('wazuh_pass') !== '';
    }

    // ------------------------------------------------------------------ cliente

    /** Faz a chamada ao Indexer (direto ou pelo Dashboard) e devolve o JSON já decodificado. */
    public static function request(string $method, string $path, ?array $body = null, int $timeout = 45): array
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('A conexão com o Wazuh ainda não foi configurada (Sistemas Internos → Configurações → Wazuh).');
        }
        $base = rtrim(Settings::get('wazuh_url'), '/');
        $direct = (int) (parse_url($base, PHP_URL_PORT) ?: 0) === 9200;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($direct) {
            $url = "$base/$path";
        } else { // o Dashboard repassa a chamada ao Indexer
            $url = $base . '/api/console/proxy?path=' . rawurlencode($path) . '&method=' . $method;
            $headers[] = 'osd-xsrf: true';
        }
        $verify = Settings::get('wazuh_verify') === '1';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $direct ? $method : 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERPWD        => Settings::get('wazuh_user') . ':' . Settings::get('wazuh_pass'),
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        } elseif (!$direct) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        global $CFG_GLPI;
        if (!empty($CFG_GLPI['proxy_name'])) {
            curl_setopt($ch, CURLOPT_PROXY, $CFG_GLPI['proxy_name'] . ':' . (int) $CFG_GLPI['proxy_port']);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Não foi possível falar com o Wazuh: ' . ($err ?: 'sem resposta') . '.');
        }
        if ($code === 401 || $code === 403) {
            throw new RuntimeException('O Wazuh recusou o usuário ou a senha (HTTP ' . $code . ').');
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new RuntimeException("Resposta inesperada do Wazuh (HTTP $code). Confira o endereço: use o do Dashboard (https://servidor) ou do Indexer (https://servidor:9200).");
        }
        if (isset($json['error'])) {
            $e = $json['error'];
            throw new RuntimeException('Wazuh: ' . (is_array($e) ? ($e['reason'] ?? $e['type'] ?? json_encode($e)) : $e));
        }
        return $json;
    }

    public static function search(string $index, array $body, int $timeout = 45): array
    {
        return self::request('POST', $index . '/_search', $body, $timeout);
    }

    /** Busca com cache compartilhado (cache do GLPI), por padrão 60 s. */
    public static function cachedSearch(string $index, array $body, int $ttl = 60): array
    {
        return Si360::cached('wz|' . md5($index . json_encode($body)), $ttl, fn () => self::search($index, $body), false);
    }

    /** Baldes de uma agregação. */
    public static function buckets(array $res, string $name): array
    {
        return $res['aggregations'][$name]['buckets'] ?? [];
    }

    public static function total(array $res): int
    {
        $t = $res['hits']['total'] ?? 0;
        return (int) (is_array($t) ? ($t['value'] ?? 0) : $t);
    }

    public static function tz(): string
    {
        $tz = date_default_timezone_get();
        return $tz === 'UTC' ? 'America/Sao_Paulo' : $tz;
    }

    // ------------------------------------------------------------------ ativos (agentes) e GLPI

    /**
     * Agentes do Wazuh com o computador correspondente no GLPI (pelo nome ou pelo IP),
     * a entidade e o responsável.
     */
    public static function agents(): array
    {
        return Si360::cached('wz|agents', 120, function () {
            $mon = self::search(self::IDX_MON, ['size' => 0, 'aggs' => ['ag' => ['terms' => ['field' => 'id', 'size' => 1000],
                'aggs' => ['last' => ['top_hits' => ['size' => 1, 'sort' => [['timestamp' => 'desc']]]]]]]]);
            $sys = self::search(sprintf(self::IDX_INV, 'system'), ['size' => 1000, '_source' => ['agent.id', 'agent.name', 'agent.host.ip', 'host.hostname', 'host.os', 'host.architecture']]);
            $info = [];
            foreach ($sys['hits']['hits'] ?? [] as $h) {
                $s = $h['_source'];
                $info[(string) ($s['agent']['id'] ?? '')] = [
                    'hostname' => (string) ($s['host']['hostname'] ?? ''),
                    'ip'       => (string) ($s['agent']['host']['ip'] ?? ''),
                    'kernel'   => (string) ($s['host']['os']['kernel']['release'] ?? ''),
                ];
            }
            $out = [];
            foreach (self::buckets($mon, 'ag') as $b) {
                $m = $b['last']['hits']['hits'][0]['_source'] ?? [];
                $id = (string) $b['key'];
                $os = trim(($m['os']['name'] ?? '') . ' ' . ($m['os']['version'] ?? ''));
                $out[$id] = [
                    'id'       => $id,
                    'name'     => (string) ($m['name'] ?? $id),
                    'hostname' => $info[$id]['hostname'] ?? '',
                    'ip'       => (string) ($m['ip'] ?? ($info[$id]['ip'] ?? '')),
                    'os'       => $os,
                    'kernel'   => $info[$id]['kernel'] ?? '',
                    'version'  => (string) ($m['version'] ?? ''),
                    'status'   => (string) ($m['status'] ?? ''),
                    'last'     => (string) ($m['lastKeepAlive'] ?? ''),
                    'added'    => (string) ($m['dateAdd'] ?? ''),
                    'groups'   => (array) ($m['group'] ?? []),
                ];
            }
            // agentes que aparecem só no inventário
            foreach ($info as $id => $i) {
                if ($id !== '' && !isset($out[$id])) {
                    $out[$id] = ['id' => $id, 'name' => $i['hostname'] ?: $id, 'hostname' => $i['hostname'], 'ip' => $i['ip'], 'os' => '', 'kernel' => $i['kernel'], 'version' => '', 'status' => '', 'last' => '', 'added' => '', 'groups' => []];
                }
            }
            ksort($out);
            return self::withGlpi($out);
        }, false);
    }

    private static function withGlpi(array $agents): array
    {
        global $DB;
        if (!$agents) {
            return [];
        }
        $computers = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'entities_id', 'users_id_tech', 'users_id'], 'FROM' => 'glpi_computers', 'WHERE' => ['is_deleted' => 0, 'is_template' => 0]]) as $c) {
            $computers[(int) $c['id']] = $c;
        }
        $byName = [];
        foreach ($computers as $c) {
            $byName[mb_strtolower(trim((string) $c['name']))][] = (int) $c['id'];
        }
        // IP → computador (endereço → nome de rede → porta de rede → computador)
        $ips = array_values(array_filter(array_unique(array_column($agents, 'ip'))));
        $byIp = [];
        if ($ips) {
            $addr = [];
            foreach ($DB->request(['SELECT' => ['name', 'items_id'], 'FROM' => 'glpi_ipaddresses', 'WHERE' => ['itemtype' => 'NetworkName', 'name' => $ips, 'is_deleted' => 0]]) as $r) {
                $addr[(int) $r['items_id']] = (string) $r['name'];
            }
            $nn = [];
            if ($addr) {
                foreach ($DB->request(['SELECT' => ['id', 'items_id'], 'FROM' => 'glpi_networknames', 'WHERE' => ['id' => array_keys($addr), 'itemtype' => 'NetworkPort', 'is_deleted' => 0]]) as $r) {
                    $nn[(int) $r['items_id']] = $addr[(int) $r['id']];
                }
            }
            if ($nn) {
                foreach ($DB->request(['SELECT' => ['id', 'items_id'], 'FROM' => 'glpi_networkports', 'WHERE' => ['id' => array_keys($nn), 'itemtype' => 'Computer', 'is_deleted' => 0]]) as $r) {
                    $byIp[$nn[(int) $r['id']]] = (int) $r['items_id'];
                }
            }
        }
        // grupo técnico (GLPI 11+: tabela de ligação, tipo 2 = grupo técnico)
        $techGroups = [];
        if ($computers) {
            foreach ($DB->request(['SELECT' => ['items_id', 'groups_id'], 'FROM' => 'glpi_groups_items', 'WHERE' => ['itemtype' => 'Computer', 'type' => 2, 'items_id' => array_keys($computers)]]) as $r) {
                $techGroups[(int) $r['items_id']][] = (int) $r['groups_id'];
            }
        }
        $names = ['entity' => [], 'user' => [], 'group' => []];
        foreach ($agents as &$a) {
            $cid = null;
            $keys = array_filter([mb_strtolower($a['hostname']), mb_strtolower((string) preg_replace('/-\d{1,3}(\.\d{1,3}){3}$/', '', $a['name'])), mb_strtolower($a['name'])]);
            foreach ($keys as $k) {
                if (isset($byName[$k])) {
                    $cid = $byName[$k][0];
                    break;
                }
            }
            $cid ??= $byIp[$a['ip']] ?? null;
            $a['computer'] = null;
            if ($cid && isset($computers[$cid])) {
                $c = $computers[$cid];
                $a['computer'] = ['id' => $cid, 'name' => (string) $c['name'], 'url' => \Computer::getFormURLWithID($cid), 'entity_id' => (int) $c['entities_id'],
                    'entity' => $names['entity'][$c['entities_id']] ??= \Dropdown::getDropdownName('glpi_entities', (int) $c['entities_id']),
                    'tech' => $c['users_id_tech'] ? ($names['user'][$c['users_id_tech']] ??= self::userName((int) $c['users_id_tech'])) : '',
                    'tech_group' => implode(', ', array_map(fn ($g) => $names['group'][$g] ??= \Dropdown::getDropdownName('glpi_groups', $g), $techGroups[$cid] ?? [])),
                    'owner' => $c['users_id'] ? ($names['user'][$c['users_id']] ??= self::userName((int) $c['users_id'])) : '',
                    'groups_id_tech' => (int) ($techGroups[$cid][0] ?? 0)];
            }
        }
        unset($a);
        return $agents;
    }

    private static function userName(int $id): string
    {
        global $DB;
        $u = $DB->request(['SELECT' => ['name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $id]])->current();
        return $u ? (trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: (string) $u['name']) : '';
    }

    /** Entidades dos ativos: id → [nome, quantidade de ativos]. */
    public static function entities(): array
    {
        $out = [];
        foreach (self::agents() as $a) {
            $id = $a['computer']['entity_id'] ?? 0;
            $name = $a['computer']['entity'] ?? 'Sem cadastro no GLPI';
            $key = $a['computer'] ? (string) $id : 'none';
            $out[$key] ??= ['id' => $key, 'name' => $name, 'assets' => 0];
            $out[$key]['assets']++;
        }
        usort($out, fn ($x, $y) => strcmp($x['name'], $y['name']));
        return array_values($out);
    }

    /** IDs de agentes de uma entidade ("none" = sem cadastro no GLPI; vazio = todos). */
    public static function agentIdsOf(string $entity): ?array
    {
        if ($entity === '') {
            return null;
        }
        $ids = [];
        foreach (self::agents() as $id => $a) {
            if (($entity === 'none' && !$a['computer']) || ($a['computer'] && (string) $a['computer']['entity_id'] === $entity)) {
                $ids[] = (string) $id;
            }
        }
        return $ids ?: ['__nenhum__'];
    }

    // ------------------------------------------------------------------ chamados relacionados

    /** Chamados ligados aos computadores (item do chamado) e aos criados a partir do Wazuh. */
    public static function ticketsOf(array $agentIds): array
    {
        global $DB;
        $agents = self::agents();
        $byComputer = [];
        foreach ($agentIds as $aid) {
            if (isset($agents[$aid]['computer'])) {
                $byComputer[$agents[$aid]['computer']['id']][] = (string) $aid;
            }
        }
        $out = [];
        $add = function (string $aid, array $t) use (&$out) {
            $out[$aid][$t['id']] = $t;
        };
        $fmt = fn (array $t) => ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'status' => (int) $t['status'], 'open' => (int) $t['status'] < 5,
            'date' => (string) $t['date'], 'url' => \Ticket::getFormURLWithID((int) $t['id'])];
        if ($byComputer) {
            foreach ($DB->request(['SELECT' => ['t.id', 't.name', 't.status', 't.date', 'it.items_id'], 'FROM' => 'glpi_items_tickets AS it',
                'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['it' => 'tickets_id', 't' => 'id']]],
                'WHERE' => ['it.itemtype' => 'Computer', 'it.items_id' => array_keys($byComputer), 't.is_deleted' => 0], 'ORDER' => 't.date DESC']) as $t) {
                foreach ($byComputer[(int) $t['items_id']] ?? [] as $aid) {
                    $add($aid, $fmt($t));
                }
            }
        }
        if ($agentIds && $DB->tableExists('glpi_plugin_sistemasinternos_wztickets')) {
            foreach ($DB->request(['SELECT' => ['w.agent_id', 't.id', 't.name', 't.status', 't.date'], 'FROM' => 'glpi_plugin_sistemasinternos_wztickets AS w',
                'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['w' => 'tickets_id', 't' => 'id']]],
                'WHERE' => ['w.agent_id' => array_values($agentIds), 't.is_deleted' => 0]]) as $t) {
                $add((string) $t['agent_id'], $fmt($t));
            }
        }
        return array_map(fn ($list) => array_slice(array_values($list), 0, 15), $out);
    }

    // ------------------------------------------------------------------ histórico (sincronização)

    /**
     * Guarda no GLPI o que o Wazuh não guarda: quando cada vulnerabilidade apareceu e quando
     * sumiu (corrigida) e uma foto diária dos totais. Disso saem a evolução e o tempo de correção.
     */
    public static function sync(): array
    {
        global $DB;
        $dir = defined('GLPI_TMP_DIR') && is_writable(GLPI_TMP_DIR) ? GLPI_TMP_DIR : sys_get_temp_dir();
        $fh = @fopen($dir . '/plugin_si_wzsync.lock', 'c');
        if ($fh && !flock($fh, LOCK_EX | LOCK_NB)) {
            return ['skipped' => true];
        }
        try {
            @set_time_limit(600);
            $start = date('Y-m-d H:i:s');
            $rows = [];
            $res = self::request('POST', self::IDX_VULN . '/_search?scroll=2m', ['size' => 5000, 'sort' => ['_doc'], '_source' => ['agent.id', 'agent.name', 'vulnerability.id', 'vulnerability.severity', 'vulnerability.score.base',
                'vulnerability.detected_at', 'package.name', 'package.version']], 120);
            $scroll = $res['_scroll_id'] ?? null;
            $n = 0;
            $count = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0, '-' => 0];
            $assets = [];
            $now = $DB->quote($start);
            $flush = function () use (&$rows, $DB) {
                if ($rows) {
                    $DB->doQuery('INSERT INTO `glpi_plugin_sistemasinternos_wzvulns` (`vkey`,`agent_id`,`agent_name`,`cve`,`package`,`version`,`severity`,`score`,`first_seen`,`last_seen`) VALUES '
                        . implode(',', $rows) . ' ON DUPLICATE KEY UPDATE `last_seen`=VALUES(`last_seen`),`severity`=VALUES(`severity`),`score`=VALUES(`score`),`agent_name`=VALUES(`agent_name`),`resolved_at`=NULL');
                    $rows = [];
                }
            };
            while (true) {
                $hits = $res['hits']['hits'] ?? [];
                if (!$hits) {
                    break;
                }
                foreach ($hits as $h) {
                    $s = $h['_source'];
                    $sev = (string) ($s['vulnerability']['severity'] ?? '-');
                    $sev = isset(self::SEV[$sev]) ? $sev : '-';
                    $agent = (string) ($s['agent']['id'] ?? '');
                    $cve = (string) ($s['vulnerability']['id'] ?? '');
                    $pkg = (string) ($s['package']['name'] ?? '');
                    $ver = (string) ($s['package']['version'] ?? '');
                    $first = strtotime((string) ($s['vulnerability']['detected_at'] ?? '')) ?: time();
                    $score = (float) ($s['vulnerability']['score']['base'] ?? 0);
                    $rows[] = '(' . implode(',', [$DB->quote(sha1("$agent|$cve|$pkg|$ver")), $DB->quote($agent), $DB->quote(mb_substr((string) ($s['agent']['name'] ?? ''), 0, 255)),
                        $DB->quote(mb_substr($cve, 0, 64)), $DB->quote(mb_substr($pkg, 0, 255)), $DB->quote(mb_substr($ver, 0, 128)), $DB->quote($sev),
                        $score > 0 ? number_format($score, 1, '.', '') : 'NULL', $DB->quote(gmdate('Y-m-d H:i:s', $first)), $now]) . ')';
                    $count[$sev]++;
                    $assets[$agent] = true;
                    $n++;
                    if (count($rows) >= 500) {
                        $flush();
                    }
                }
                if (!$scroll) {
                    break;
                }
                $res = self::request('POST', '_search/scroll', ['scroll' => '2m', 'scroll_id' => $scroll], 120);
                $scroll = $res['_scroll_id'] ?? $scroll;
            }
            $flush();
            if ($scroll) {
                try {
                    self::request('DELETE', '_search/scroll', ['scroll_id' => $scroll], 15);
                } catch (Throwable) {
                }
            }
            $fixed = 0;
            if ($n > 0) { // sem nenhum documento, não dá para saber se foi correção ou falha da busca
                $DB->doQuery("UPDATE `glpi_plugin_sistemasinternos_wzvulns` SET `resolved_at` = $now WHERE `resolved_at` IS NULL AND `last_seen` < $now");
                $fixed = (int) $DB->affectedRows();
            }
            $day = $DB->quote(date('Y-m-d'));
            $DB->doQuery('INSERT INTO `glpi_plugin_sistemasinternos_wzsnaps` (`day`,`total`,`critical`,`high`,`medium`,`low`,`unrated`,`assets`,`date_mod`) VALUES ('
                . "$day,$n,{$count['Critical']},{$count['High']},{$count['Medium']},{$count['Low']},{$count['-']}," . count($assets) . ",$now)"
                . ' ON DUPLICATE KEY UPDATE `total`=VALUES(`total`),`critical`=VALUES(`critical`),`high`=VALUES(`high`),`medium`=VALUES(`medium`),`low`=VALUES(`low`),`unrated`=VALUES(`unrated`),`assets`=VALUES(`assets`),`date_mod`=VALUES(`date_mod`)');
            return ['total' => $n, 'fixed' => $fixed, 'assets' => count($assets)];
        } finally {
            if ($fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    /** Sincroniza depois de responder, se a última foto é mais velha que $maxAge segundos (padrão 2 horas). */
    public static function syncIfStale(int $maxAge = 7200): void
    {
        global $DB;
        if (!self::isConfigured() || !$DB->tableExists('glpi_plugin_sistemasinternos_wzsnaps')) {
            return;
        }
        $last = $DB->request(['SELECT' => ['date_mod'], 'FROM' => 'glpi_plugin_sistemasinternos_wzsnaps', 'ORDER' => 'date_mod DESC', 'LIMIT' => 1])->current();
        if ($last && strtotime((string) $last['date_mod']) > time() - $maxAge) {
            return;
        }
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::sync();
            } catch (Throwable) {
            }
        });
    }

    public static function cronInfo(string $name): array
    {
        return ['description' => 'Sincroniza as vulnerabilidades do Wazuh (histórico e tempo de correção)'];
    }

    /** Ação automática do GLPI (a cada hora). */
    public static function cronWazuhsync(CronTask $task): int
    {
        if (!self::isConfigured()) {
            return 0;
        }
        try {
            $r = self::sync();
            $task->addVolume((int) ($r['total'] ?? 0));
            $task->log('Vulnerabilidades: ' . ($r['total'] ?? 0) . ', corrigidas: ' . ($r['fixed'] ?? 0));
            return 1;
        } catch (Throwable $e) {
            $task->log('Erro: ' . $e->getMessage());
            return -1;
        }
    }

    /** Teste da conexão para a tela de configuração. */
    public static function test(): array
    {
        $out = [];
        $h = self::request('GET', '_cluster/health', null, 15);
        $out[] = ['ok', 'Conectado ao Indexer do Wazuh (cluster "' . ($h['cluster_name'] ?? '?') . '", estado ' . ($h['status'] ?? '?') . ').'];
        $v = self::search(self::IDX_VULN, ['size' => 0, 'track_total_hits' => true, 'aggs' => ['ag' => ['cardinality' => ['field' => 'agent.id']]]]);
        $out[] = ['ok', self::total($v) . ' vulnerabilidade(s) em ' . (int) ($v['aggregations']['ag']['value'] ?? 0) . ' agente(s).'];
        $a = self::search(self::IDX_ALERTS, ['size' => 0, 'track_total_hits' => true, 'query' => ['range' => ['@timestamp' => ['gte' => 'now-24h']]]]);
        $out[] = ['ok', self::total($a) . ' alerta(s) de segurança nas últimas 24 horas.'];
        return $out;
    }
}
