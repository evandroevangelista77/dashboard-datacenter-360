<?php

namespace GlpiPlugin\Sistemasinternos;

/**
 * Dashboard de Segurança: tudo o que o Wazuh oferece para monitorar segurança, a partir do
 * Indexer: eventos e alertas, MITRE ATT&CK, conformidade (PCI DSS, GDPR, HIPAA, NIST 800-53, TSC),
 * integridade de arquivos (FIM), avaliação de configuração (SCA), rootcheck, autenticação,
 * vulnerabilidades, agentes e inventário.
 */
class WazuhSec
{
    public const PERIODS = ['1h' => ['1 hora', '1m'], '24h' => ['24 horas', '30m'], '7d' => ['7 dias', '3h'], '30d' => ['30 dias', '1d'], '90d' => ['90 dias', '1d']];

    /** Campos permitidos no detalhe (clique nos gráficos): campo do Wazuh por nome curto. */
    private const FIELDS = [
        'rule.id' => 'rule.id', 'rule.level' => 'rule.level', 'agent.id' => 'agent.id', 'rule.groups' => 'rule.groups', 'mitre.tactic' => 'rule.mitre.tactic',
        'mitre.technique' => 'rule.mitre.technique', 'mitre.id' => 'rule.mitre.id', 'pci_dss' => 'rule.pci_dss', 'gdpr' => 'rule.gdpr', 'hipaa' => 'rule.hipaa',
        'nist_800_53' => 'rule.nist_800_53', 'tsc' => 'rule.tsc', 'srcip' => 'data.srcip', 'srcuser' => 'data.srcuser', 'dstuser' => 'data.dstuser', 'sca.policy' => 'data.sca.policy',
        'sca.result' => 'data.sca.check.result', 'fim.path' => 'syscheck.path', 'fim.event' => 'syscheck.event', 'decoder' => 'decoder.name', 'location' => 'location', 'url' => 'data.url',
    ];

    public static function route(string $path, array $q): array
    {
        $period = isset(self::PERIODS[$q['period'] ?? '']) ? $q['period'] : '24h';
        $agent = preg_match('/^\d{1,6}$/', (string) ($q['agent'] ?? '')) ? (string) $q['agent'] : '';
        return match ($path) {
            'sec/overview'  => self::overview($period, $agent),
            'sec/alerts'    => self::alerts($period, $agent, (string) ($q['field'] ?? ''), (string) ($q['value'] ?? ''), max(0, min(15, (int) ($q['min'] ?? 0))), trim((string) ($q['q'] ?? ''))),
            'sec/inventory' => self::inventory($agent),
            default         => throw new NotFound('Rota desconhecida.'),
        };
    }

    private static function base(string $period, string $agent): array
    {
        $f = [['range' => ['@timestamp' => ['gte' => 'now-' . $period]]]];
        if ($agent !== '') {
            $f[] = ['term' => ['agent.id' => $agent]];
        }
        return $f;
    }

    private static function t(string $field, int $size = 10, array $sub = []): array
    {
        $a = ['terms' => ['field' => $field, 'size' => $size]];
        return $sub ? $a + ['aggs' => $sub] : $a;
    }

    private static function pairs(array $res, string $name): array
    {
        return array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count']], Wazuh::buckets($res, $name));
    }

    // ------------------------------------------------------------------ visão geral

    private static function overview(string $period, string $agent): array
    {
        $filter = self::base($period, $agent);
        $grp = fn (string ...$g) => ['bool' => ['should' => array_map(fn ($x) => ['term' => ['rule.groups' => $x]], $g), 'minimum_should_match' => 1]];
        $res = Wazuh::cachedSearch(Wazuh::IDX_ALERTS, ['size' => 0, 'track_total_hits' => true, 'query' => ['bool' => ['filter' => $filter]], 'aggs' => [
            'levels' => self::t('rule.level', 16),
            'class' => ['range' => ['field' => 'rule.level', 'ranges' => [['key' => 'Crítico (12+)', 'from' => 12], ['key' => 'Alto (10–11)', 'from' => 10, 'to' => 12], ['key' => 'Médio (7–9)', 'from' => 7, 'to' => 10], ['key' => 'Baixo (0–6)', 'to' => 7]]]],
            'timeline' => ['date_histogram' => ['field' => '@timestamp', 'fixed_interval' => self::PERIODS[$period][1], 'time_zone' => Wazuh::tz(), 'min_doc_count' => 0,
                'extended_bounds' => ['min' => 'now-' . $period, 'max' => 'now']], 'aggs' => ['hi' => ['filter' => ['range' => ['rule.level' => ['gte' => 10]]]]]],
            'rules' => self::t('rule.id', 15, ['d' => ['top_hits' => ['size' => 1, '_source' => ['rule.description', 'rule.level']]]]),
            'agents' => self::t('agent.id', 15, ['n' => ['top_hits' => ['size' => 1, '_source' => ['agent.name']]], 'hi' => ['filter' => ['range' => ['rule.level' => ['gte' => 10]]]]]),
            'groups' => self::t('rule.groups', 25),
            'tactic' => self::t('rule.mitre.tactic', 15), 'technique' => self::t('rule.mitre.technique', 15, ['id' => self::t('rule.mitre.id', 1)]),
            'pci' => self::t('rule.pci_dss', 12), 'gdpr' => self::t('rule.gdpr', 12), 'hipaa' => self::t('rule.hipaa', 12), 'nist' => self::t('rule.nist_800_53', 12), 'tsc' => self::t('rule.tsc', 12),
            'srcip' => self::t('data.srcip', 10), 'srcuser' => self::t('data.srcuser', 10), 'dstuser' => self::t('data.dstuser', 10), 'url' => self::t('data.url', 8),
            'decoders' => self::t('decoder.name', 12), 'locations' => self::t('location', 8),
            'auth_fail' => ['filter' => $grp('authentication_failed', 'authentication_failures', 'invalid_login')],
            'auth_ok' => ['filter' => $grp('authentication_success')],
            'sudo' => ['filter' => $grp('sudo')], 'ssh' => ['filter' => $grp('sshd')], 'web' => ['filter' => $grp('web', 'attack', 'appsec')],
            'adduser' => ['filter' => $grp('adduser', 'account_changed')],
            'fim' => ['filter' => $grp('syscheck', 'syscheck_entry_added', 'syscheck_entry_modified', 'syscheck_entry_deleted'), 'aggs' => ['event' => self::t('syscheck.event', 5), 'path' => self::t('syscheck.path', 12),
                'agents' => self::t('agent.id', 8), 'tl' => ['date_histogram' => ['field' => '@timestamp', 'fixed_interval' => self::PERIODS[$period][1], 'time_zone' => Wazuh::tz(), 'min_doc_count' => 0]]]],
            'sca' => ['filter' => $grp('sca'), 'aggs' => ['result' => self::t('data.sca.check.result', 5), 'policy' => self::t('data.sca.policy', 8, ['r' => self::t('data.sca.check.result', 5)]),
                'failed' => ['filter' => ['term' => ['data.sca.check.result' => 'failed']], 'aggs' => ['c' => self::t('data.sca.check.title', 12, ['h' => ['top_hits' => ['size' => 1, '_source' => ['data.sca.check.remediation', 'data.sca.policy', 'data.sca.check.id', 'agent.name']]]])]],
                'agents' => self::t('agent.id', 10, ['r' => self::t('data.sca.check.result', 5)])]],
            'rootcheck' => ['filter' => $grp('rootcheck'), 'aggs' => ['d' => self::t('rule.description', 8)]],
            'vuln_alerts' => ['filter' => $grp('vulnerability-detector')],
            'ar' => ['filter' => $grp('active_response')],
            'malware' => ['filter' => $grp('virustotal', 'yara', 'malware', 'rootcheck_trojans')],
            'docker' => ['filter' => $grp('docker')], 'cloud' => ['filter' => $grp('aws', 'azure', 'gcp', 'office365', 'github')],
        ]], 60);
        $a = $res['aggregations'];
        $agents = Wazuh::agents();
        $recent = Wazuh::cachedSearch(Wazuh::IDX_ALERTS, ['size' => 30, 'sort' => [['@timestamp' => 'desc']], 'query' => ['bool' => ['filter' => array_merge($filter, [['range' => ['rule.level' => ['gte' => 7]]]])]],
            '_source' => ['@timestamp', 'agent.id', 'agent.name', 'rule.id', 'rule.level', 'rule.description', 'rule.groups', 'rule.mitre.technique', 'data.srcip', 'location']], 60);
        $c = fn (string $n) => (int) ($a[$n]['doc_count'] ?? 0);
        $class = [];
        foreach ($a['class']['buckets'] as $b) {
            $class[(string) $b['key']] = (int) $b['doc_count'];
        }
        $timeline = array_map(fn ($b) => ['t' => (int) $b['key'], 'n' => (int) $b['doc_count'], 'hi' => (int) $b['hi']['doc_count']], $a['timeline']['buckets']);
        $sca = $a['sca'];
        $scaRes = [];
        foreach ($sca['result']['buckets'] as $b) {
            $scaRes[(string) $b['key']] = (int) $b['doc_count'];
        }
        $pass = $scaRes['passed'] ?? 0;
        $fail = $scaRes['failed'] ?? 0;
        $name = fn (string $id) => $agents[$id]['name'] ?? $id;
        return [
            'period' => $period, 'agent' => $agent, 'total' => Wazuh::total($res), 'class' => $class, 'levels' => self::pairs($res, 'levels'), 'timeline' => $timeline,
            'kpi' => ['critical' => $class['Crítico (12+)'] ?? 0, 'high' => $class['Alto (10–11)'] ?? 0, 'auth_fail' => $c('auth_fail'), 'auth_ok' => $c('auth_ok'), 'sudo' => $c('sudo'), 'ssh' => $c('ssh'),
                'web' => $c('web'), 'adduser' => $c('adduser'), 'fim' => $c('fim'), 'sca_failed' => $fail, 'sca_passed' => $pass, 'sca_score' => $pass + $fail ? round(100 * $pass / ($pass + $fail), 1) : null,
                'rootcheck' => $c('rootcheck'), 'vuln_alerts' => $c('vuln_alerts'), 'active_response' => $c('ar'), 'malware' => $c('malware'), 'docker' => $c('docker'), 'cloud' => $c('cloud')],
            'rules' => array_map(fn ($b) => ['id' => (string) $b['key'], 'count' => (int) $b['doc_count'], 'description' => (string) ($b['d']['hits']['hits'][0]['_source']['rule']['description'] ?? ''), 'level' => (int) ($b['d']['hits']['hits'][0]['_source']['rule']['level'] ?? 0)], Wazuh::buckets($res, 'rules')),
            'agents' => array_map(fn ($b) => ['id' => (string) $b['key'], 'name' => (string) ($b['n']['hits']['hits'][0]['_source']['agent']['name'] ?? $name((string) $b['key'])), 'count' => (int) $b['doc_count'], 'high' => (int) $b['hi']['doc_count']], Wazuh::buckets($res, 'agents')),
            'groups' => self::pairs($res, 'groups'), 'tactic' => self::pairs($res, 'tactic'),
            'technique' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count'], 'id' => (string) ($b['id']['buckets'][0]['key'] ?? '')], Wazuh::buckets($res, 'technique')),
            'compliance' => ['PCI DSS' => self::pairs($res, 'pci'), 'GDPR' => self::pairs($res, 'gdpr'), 'HIPAA' => self::pairs($res, 'hipaa'), 'NIST 800-53' => self::pairs($res, 'nist'), 'TSC' => self::pairs($res, 'tsc')],
            'compliance_keys' => ['PCI DSS' => 'pci_dss', 'GDPR' => 'gdpr', 'HIPAA' => 'hipaa', 'NIST 800-53' => 'nist_800_53', 'TSC' => 'tsc'],
            'srcip' => self::pairs($res, 'srcip'), 'srcuser' => self::pairs($res, 'srcuser'), 'dstuser' => self::pairs($res, 'dstuser'), 'url' => self::pairs($res, 'url'),
            'decoders' => self::pairs($res, 'decoders'), 'locations' => self::pairs($res, 'locations'),
            'fim' => ['total' => $c('fim'), 'event' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count']], $a['fim']['event']['buckets']),
                'path' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count']], $a['fim']['path']['buckets']),
                'agents' => array_map(fn ($b) => ['id' => (string) $b['key'], 'name' => $name((string) $b['key']), 'count' => (int) $b['doc_count']], $a['fim']['agents']['buckets'])],
            'sca' => ['result' => $scaRes, 'score' => $pass + $fail ? round(100 * $pass / ($pass + $fail), 1) : null,
                'policy' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count'], 'passed' => self::cnt($b['r']['buckets'], 'passed'), 'failed' => self::cnt($b['r']['buckets'], 'failed'), 'na' => self::cnt($b['r']['buckets'], 'not applicable')], $sca['policy']['buckets']),
                'failed' => array_map(fn ($b) => ['title' => (string) $b['key'], 'count' => (int) $b['doc_count'], 'remediation' => mb_substr((string) ($b['h']['hits']['hits'][0]['_source']['data']['sca']['check']['remediation'] ?? ''), 0, 500),
                    'policy' => (string) ($b['h']['hits']['hits'][0]['_source']['data']['sca']['policy'] ?? ''), 'agent' => (string) ($b['h']['hits']['hits'][0]['_source']['agent']['name'] ?? '')], $sca['failed']['c']['buckets']),
                'agents' => array_map(fn ($b) => ['id' => (string) $b['key'], 'name' => $name((string) $b['key']), 'passed' => self::cnt($b['r']['buckets'], 'passed'), 'failed' => self::cnt($b['r']['buckets'], 'failed')], $sca['agents']['buckets'])],
            'rootcheck' => ['total' => $c('rootcheck'), 'items' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count']], $a['rootcheck']['d']['buckets'])],
            'recent' => array_map(function ($h) {
                $s = $h['_source'];
                return ['t' => (string) ($s['@timestamp'] ?? ''), 'agent' => (string) ($s['agent']['name'] ?? ''), 'agent_id' => (string) ($s['agent']['id'] ?? ''), 'rule' => (string) ($s['rule']['id'] ?? ''), 'level' => (int) ($s['rule']['level'] ?? 0),
                    'description' => (string) ($s['rule']['description'] ?? ''), 'groups' => array_slice((array) ($s['rule']['groups'] ?? []), 0, 3), 'mitre' => (array) ($s['rule']['mitre']['technique'] ?? []), 'srcip' => (string) ($s['data']['srcip'] ?? '')];
            }, $recent['hits']['hits'] ?? []),
            'agent_list' => array_values(array_map(fn ($ag) => ['id' => $ag['id'], 'name' => $ag['name']], $agents)),
            'at' => time(),
        ];
    }

    private static function cnt(array $buckets, string $key): int
    {
        foreach ($buckets as $b) {
            if ($b['key'] === $key) {
                return (int) $b['doc_count'];
            }
        }
        return 0;
    }

    // ------------------------------------------------------------------ detalhe (clique)

    /** Alertas por trás de um gráfico: o campo e o valor clicados. */
    private static function alerts(string $period, string $agent, string $field, string $value, int $min, string $q): array
    {
        $filter = self::base($period, $agent);
        if ($min > 0) {
            $filter[] = ['range' => ['rule.level' => ['gte' => $min]]];
        }
        if ($field !== '') {
            $virtual = [
                'class' => ['Crítico (12+)' => ['gte' => 12], 'Alto (10–11)' => ['gte' => 10, 'lt' => 12], 'Médio (7–9)' => ['gte' => 7, 'lt' => 10], 'Baixo (0–6)' => ['lt' => 7]],
            ];
            if ($field === 'class' && isset($virtual['class'][$value])) {
                $filter[] = ['range' => ['rule.level' => $virtual['class'][$value]]];
            } elseif ($field === 'auth') {
                $filter[] = ['terms' => ['rule.groups' => ['authentication_failed', 'authentication_failures', 'invalid_login']]];
            } elseif ($field === 'fim') {
                $filter[] = ['terms' => ['rule.groups' => ['syscheck']]];
            } elseif ($field === 'group') {
                $filter[] = ['term' => ['rule.groups' => $value]];
            } elseif (isset(self::FIELDS[$field])) {
                $filter[] = ['term' => [self::FIELDS[$field] => $field === 'rule.level' ? (int) $value : $value]];
            } else {
                throw new NotFound('Campo desconhecido.');
            }
        }
        $query = ['bool' => ['filter' => $filter]];
        if ($q !== '') {
            $query['bool']['must'] = [['simple_query_string' => ['query' => $q, 'fields' => ['rule.description', 'agent.name', 'full_log', 'data.srcip', 'rule.id'], 'default_operator' => 'and']]];
        }
        $res = Wazuh::cachedSearch(Wazuh::IDX_ALERTS, ['size' => 200, 'track_total_hits' => true, 'sort' => [['@timestamp' => 'desc']], 'query' => $query,
            '_source' => ['@timestamp', 'agent.id', 'agent.name', 'rule.id', 'rule.level', 'rule.description', 'rule.groups', 'rule.mitre', 'data.srcip', 'data.srcuser', 'data.dstuser', 'data.sca', 'syscheck.path', 'syscheck.event', 'full_log', 'location']], 30);
        $items = array_map(function ($h) {
            $s = $h['_source'];
            $sca = $s['data']['sca']['check'] ?? null;
            return ['t' => (string) ($s['@timestamp'] ?? ''), 'agent' => (string) ($s['agent']['name'] ?? ''), 'rule' => (string) ($s['rule']['id'] ?? ''), 'level' => (int) ($s['rule']['level'] ?? 0),
                'description' => (string) ($s['rule']['description'] ?? ''), 'groups' => (array) ($s['rule']['groups'] ?? []), 'mitre' => (array) ($s['rule']['mitre']['technique'] ?? []),
                'srcip' => (string) ($s['data']['srcip'] ?? ''), 'user' => (string) ($s['data']['dstuser'] ?? $s['data']['srcuser'] ?? ''), 'location' => (string) ($s['location'] ?? ''),
                'fim' => isset($s['syscheck']) ? trim(($s['syscheck']['event'] ?? '') . ' ' . ($s['syscheck']['path'] ?? '')) : '',
                'sca' => $sca ? ['title' => (string) ($sca['title'] ?? ''), 'result' => (string) ($sca['result'] ?? ''), 'remediation' => mb_substr((string) ($sca['remediation'] ?? ''), 0, 400), 'policy' => (string) ($s['data']['sca']['policy'] ?? '')] : null,
                'log' => mb_substr((string) ($s['full_log'] ?? ''), 0, 500)];
        }, $res['hits']['hits'] ?? []);
        return ['total' => Wazuh::total($res), 'items' => $items];
    }

    // ------------------------------------------------------------------ inventário e agentes

    private static function inventory(string $agent): array
    {
        $f = $agent !== '' ? ['bool' => ['filter' => [['term' => ['agent.id' => $agent]]]]] : ['match_all' => new \stdClass()];
        $one = fn (string $name, array $body, int $ttl = 120) => Wazuh::cachedSearch(sprintf(Wazuh::IDX_INV, $name), $body + ['size' => 0, 'track_total_hits' => true, 'query' => $f], $ttl);
        $agents = Wazuh::agents();
        $nm = fn ($id) => $agents[(string) $id]['name'] ?? (string) $id;
        $pk = $one('packages', ['aggs' => ['ag' => self::t('agent.id', 50), 'type' => self::t('package.type', 6), 'vendor' => self::t('package.vendor', 10)]]);
        $ports = $one('ports', ['query' => ['bool' => ['filter' => array_merge($agent !== '' ? [['term' => ['agent.id' => $agent]]] : [], [['term' => ['interface.state' => 'listening']]])]],
            'aggs' => ['port' => self::t('source.port', 20, ['proc' => self::t('process.name', 2), 'ag' => self::t('agent.id', 20)]), 'tr' => self::t('network.transport', 4), 'ag' => self::t('agent.id', 50),
                'exposed' => ['filter' => ['terms' => ['source.ip' => ['0.0.0.0', '::']]]]]]);
        $svc = $one('services', ['aggs' => ['state' => self::t('service.state', 6), 'sub' => self::t('service.sub_state', 8), 'enabled' => self::t('service.enabled', 6),
            'running' => ['filter' => ['term' => ['service.state' => 'active']], 'aggs' => ['n' => self::t('service.name', 15)]]]]);
        $usr = $one('users', ['aggs' => ['shell' => self::t('user.shell', 8), 'pw' => self::t('user.password.status', 6),
            'login' => ['filter' => ['bool' => ['must_not' => [['terms' => ['user.shell' => ['/usr/sbin/nologin', '/bin/false', '/sbin/nologin']]]]]], 'aggs' => ['u' => self::t('user.name', 20, ['ag' => self::t('agent.id', 5)])]],
            'fails' => ['sum' => ['field' => 'user.auth_failures.count']], 'remote' => ['filter' => ['term' => ['user.is_remote' => true]]]]]);
        $proc = $one('processes', ['aggs' => ['ag' => self::t('agent.id', 50), 'top' => self::t('process.name', 12)]]);
        $hw = Wazuh::cachedSearch(sprintf(Wazuh::IDX_INV, 'hardware'), ['size' => 100, 'query' => $f, '_source' => ['agent.id', 'host.cpu.name', 'host.cpu.cores', 'host.memory.total', 'host.memory.usage']], 120);
        $sys = Wazuh::cachedSearch(sprintf(Wazuh::IDX_INV, 'system'), ['size' => 0, 'query' => $f, 'aggs' => ['os' => self::t('host.os.name', 8, ['v' => self::t('host.os.version', 6)]), 'kernel' => self::t('host.os.kernel.release', 8)]], 120);
        $net = $one('interfaces', ['aggs' => ['ag' => self::t('agent.id', 50)]]);
        $groups = $one('groups', ['aggs' => ['ag' => self::t('agent.id', 50)]]);
        $hotfix = $one('hotfixes', []);
        $browser = $one('browser-extensions', []);
        $row = fn ($res, $name) => array_map(fn ($b) => ['id' => (string) $b['key'], 'name' => $nm($b['key']), 'count' => (int) $b['doc_count']], Wazuh::buckets($res, $name));
        $health = [];
        try {
            $h = Wazuh::request('GET', '_cluster/health', null, 15);
            $health = ['status' => $h['status'] ?? '', 'nodes' => $h['number_of_nodes'] ?? 0, 'shards' => $h['active_shards'] ?? 0, 'unassigned' => $h['unassigned_shards'] ?? 0];
        } catch (\Throwable) {
        }
        $counts = array_count_values(array_column($agents, 'status'));
        return [
            'agents' => array_values(array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'ip' => $a['ip'], 'os' => $a['os'], 'kernel' => $a['kernel'], 'version' => $a['version'], 'status' => $a['status'],
                'status_label' => Wazuh::STATUS[$a['status']] ?? ($a['status'] ?: '—'), 'last' => $a['last'], 'added' => $a['added'], 'groups' => $a['groups'], 'computer' => $a['computer']], $agents)),
            'status' => $counts, 'health' => $health,
            'counts' => ['packages' => Wazuh::total($pk), 'ports' => Wazuh::total($ports), 'services' => Wazuh::total($svc), 'users' => Wazuh::total($usr), 'processes' => Wazuh::total($proc), 'interfaces' => Wazuh::total($net),
                'groups' => Wazuh::total($groups), 'hotfixes' => Wazuh::total($hotfix), 'extensions' => Wazuh::total($browser)],
            'packages' => ['by_agent' => $row($pk, 'ag'), 'type' => self::pairs($pk, 'type'), 'vendor' => self::pairs($pk, 'vendor')],
            'ports' => ['top' => array_map(fn ($b) => ['port' => (int) $b['key'], 'count' => (int) $b['doc_count'], 'process' => (string) ($b['proc']['buckets'][0]['key'] ?? ''), 'agents' => count($b['ag']['buckets'])], Wazuh::buckets($ports, 'port')),
                'transport' => self::pairs($ports, 'tr'), 'by_agent' => $row($ports, 'ag'), 'exposed' => (int) ($ports['aggregations']['exposed']['doc_count'] ?? 0)],
            'services' => ['state' => self::pairs($svc, 'state'), 'sub' => self::pairs($svc, 'sub'), 'enabled' => self::pairs($svc, 'enabled'),
                'running' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count']], $svc['aggregations']['running']['n']['buckets'] ?? [])],
            'users' => ['shell' => self::pairs($usr, 'shell'), 'password' => self::pairs($usr, 'pw'), 'failures' => (int) ($usr['aggregations']['fails']['value'] ?? 0), 'remote' => (int) ($usr['aggregations']['remote']['doc_count'] ?? 0),
                'interactive' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count']], $usr['aggregations']['login']['u']['buckets'] ?? [])],
            'processes' => ['by_agent' => $row($proc, 'ag'), 'top' => self::pairs($proc, 'top')],
            'hardware' => array_map(fn ($h) => ['agent' => $nm($h['_source']['agent']['id'] ?? ''), 'cpu' => (string) ($h['_source']['host']['cpu']['name'] ?? ''), 'cores' => (int) ($h['_source']['host']['cpu']['cores'] ?? 0),
                'memory_gb' => round(((float) ($h['_source']['host']['memory']['total'] ?? 0)) / 1073741824, 1), 'usage' => (float) ($h['_source']['host']['memory']['usage'] ?? 0)], $hw['hits']['hits'] ?? []),
            'os' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count'], 'versions' => array_map(fn ($v) => (string) $v['key'], $b['v']['buckets'])], Wazuh::buckets($sys, 'os')),
            'kernel' => self::pairs($sys, 'kernel'), 'interfaces' => $row($net, 'ag'),
        ];
    }
}
