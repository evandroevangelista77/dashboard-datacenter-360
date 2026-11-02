<?php

namespace GlpiPlugin\Sistemasinternos;

use RuntimeException;
use Session;
use Ticket;

/**
 * Vulnerabilidades do Wazuh em três visões (executiva, por ativo, operacional)
 * e a abertura de chamados sem duplicar.
 */
class WazuhVuln
{
    private const SEV_ORDER = ['Critical', 'High', 'Medium', 'Low', '-'];

    public static function route(string $path, array $q): array
    {
        return match ($path) {
            'vuln/exec'    => self::executive((string) ($q['entity'] ?? '')),
            'vuln/assets'  => self::assets((string) ($q['entity'] ?? ''), trim((string) ($q['q'] ?? ''))),
            'vuln/asset'   => self::asset((string) ($q['id'] ?? ''), (string) ($q['sev'] ?? ''), trim((string) ($q['q'] ?? '')), max(0, (int) ($q['page'] ?? 0))),
            'vuln/ops'     => self::operational((string) ($q['entity'] ?? ''), (string) ($q['sev'] ?? 'Medium'), trim((string) ($q['q'] ?? '')), !empty($q['free'])),
            'vuln/list'    => self::listing($q),
            'vuln/epss'    => WazuhEpss::route($q),
            'vuln/cve'     => self::cve((string) ($q['id'] ?? '')),
            'vuln/alerts'  => self::opsAlerts((string) ($q['entity'] ?? ''), max(1, min(720, (int) ($q['hours'] ?? 24))), max(1, min(15, (int) ($q['level'] ?? Settings::get('wazuh_min_level') ?: 10)))),
            default        => throw new NotFound('Rota desconhecida.'),
        };
    }

    /** Contagem por severidade, na ordem fixa, a partir dos baldes de uma agregação. */
    private static function sevCounts(array $buckets): array
    {
        $out = array_fill_keys(self::SEV_ORDER, 0);
        foreach ($buckets as $b) {
            $k = isset($out[$b['key']]) ? $b['key'] : '-';
            $out[$k] += (int) $b['doc_count'];
        }
        return $out;
    }

    private static function sevAgg(): array
    {
        return ['terms' => ['field' => 'vulnerability.severity', 'size' => 6]];
    }

    private static function days(string $iso): ?int
    {
        $t = strtotime($iso);
        return $t ? max(0, (int) floor((time() - $t) / 86400)) : null;
    }

    // ------------------------------------------------------------------ executiva

    private static function executive(string $entity = ''): array
    {
        global $DB;
        $ids = Wazuh::agentIdsOf($entity);
        $res = Wazuh::cachedSearch(Wazuh::IDX_VULN, ['size' => 0, 'track_total_hits' => true, 'query' => $ids === null ? ['match_all' => new \stdClass()] : ['terms' => ['agent.id' => $ids]], 'aggs' => [
            'sev'     => self::sevAgg(),
            'agents'  => ['cardinality' => ['field' => 'agent.id']],
            'cves'    => ['cardinality' => ['field' => 'vulnerability.id']],
            'crit_agents' => ['filter' => ['term' => ['vulnerability.severity' => 'Critical']], 'aggs' => ['n' => ['cardinality' => ['field' => 'agent.id']]]],
            'crit_old' => ['filter' => ['bool' => ['filter' => [['term' => ['vulnerability.severity' => 'Critical']], ['range' => ['vulnerability.detected_at' => ['lte' => 'now-30d']]]]]]],
            'top_cves' => ['terms' => ['field' => 'vulnerability.id', 'size' => 10, 'order' => ['score' => 'desc']], 'aggs' => [
                'score' => ['max' => ['field' => 'vulnerability.score.base']], 'assets' => ['cardinality' => ['field' => 'agent.id']],
                'sev' => ['terms' => ['field' => 'vulnerability.severity', 'size' => 1, 'order' => ['_key' => 'asc']]],
                'since' => ['min' => ['field' => 'vulnerability.detected_at']],
                'pkg' => ['terms' => ['field' => 'package.name', 'size' => 1]]]],
            'top_crit' => ['filter' => ['term' => ['vulnerability.severity' => 'Critical']], 'aggs' => ['c' => ['terms' => ['field' => 'vulnerability.id', 'size' => 8], 'aggs' => [
                'score' => ['max' => ['field' => 'vulnerability.score.base']], 'assets' => ['cardinality' => ['field' => 'agent.id']],
                'since' => ['min' => ['field' => 'vulnerability.detected_at']], 'pkg' => ['terms' => ['field' => 'package.name', 'size' => 1]]]]]],
            'cvss'    => ['filter' => ['range' => ['vulnerability.score.base' => ['gte' => 0]]], 'aggs' => ['h' => ['histogram' => ['field' => 'vulnerability.score.base', 'interval' => 1, 'min_doc_count' => 0, 'extended_bounds' => ['min' => 0, 'max' => 10]]]]],
            'pkgs'    => ['terms' => ['field' => 'package.name', 'size' => 20], 'aggs' => ['sev' => self::sevAgg(), 'assets' => ['cardinality' => ['field' => 'agent.id']]]],
            'os'      => ['terms' => ['field' => 'host.os.full', 'size' => 8], 'aggs' => ['sev' => self::sevAgg()]],
            'age'     => ['range' => ['field' => 'vulnerability.detected_at', 'ranges' => [
                ['key' => 'Até 7 dias', 'from' => 'now-7d'], ['key' => '8 a 30 dias', 'from' => 'now-30d', 'to' => 'now-7d'],
                ['key' => '31 a 90 dias', 'from' => 'now-90d', 'to' => 'now-30d'], ['key' => 'Mais de 90 dias', 'to' => 'now-90d']]], 'aggs' => ['sev' => self::sevAgg()]],
            'timeline' => ['date_histogram' => ['field' => 'vulnerability.detected_at', 'calendar_interval' => 'day', 'time_zone' => Wazuh::tz(), 'min_doc_count' => 1], 'aggs' => ['sev' => self::sevAgg()]],
            'by_agent' => ['terms' => ['field' => 'agent.id', 'size' => 200], 'aggs' => ['sev' => self::sevAgg(), 'score' => ['max' => ['field' => 'vulnerability.score.base']]]],
        ]], 120);
        $agents = Wazuh::agents();
        $sev = self::sevCounts(Wazuh::buckets($res, 'sev'));
        $total = Wazuh::total($res);
        $cveRow = fn (array $b) => ['cve' => (string) $b['key'], 'score' => round((float) ($b['score']['value'] ?? 0), 1), 'assets' => (int) $b['assets']['value'],
            'severity' => (string) ($b['sev']['buckets'][0]['key'] ?? '-'), 'days' => self::days((string) ($b['since']['value_as_string'] ?? '')), 'package' => (string) ($b['pkg']['buckets'][0]['key'] ?? ''), 'count' => (int) $b['doc_count']];
        $byAgent = [];
        foreach (Wazuh::buckets($res, 'by_agent') as $b) {
            $id = (string) $b['key'];
            $byAgent[] = ['id' => $id, 'name' => $agents[$id]['name'] ?? $id, 'sev' => self::sevCounts($b['sev']['buckets']), 'score' => round((float) ($b['score']['value'] ?? 0), 1), 'total' => (int) $b['doc_count']];
        }
        usort($byAgent, fn ($a, $b) => [$b['sev']['Critical'], $b['sev']['High'], $b['total']] <=> [$a['sev']['Critical'], $a['sev']['High'], $a['total']]);

        // evolução: foto diária guardada pelo GLPI
        $snaps = [];
        $mttr = ['rows' => [], 'since' => null];
        if ($DB->tableExists('glpi_plugin_sistemasinternos_wzsnaps')) {
            $inIds = $ids === null ? '' : ' AND `agent_id` IN (' . implode(',', array_map(fn ($i) => $DB->quote((string) $i), $ids)) . ')';
            foreach ($DB->request(['FROM' => 'glpi_plugin_sistemasinternos_wzsnaps', 'WHERE' => ['day' => ['>=', date('Y-m-d', strtotime('-180 days'))]], 'ORDER' => 'day ASC']) as $r) {
                $snaps[] = ['day' => (string) $r['day'], 'total' => (int) $r['total'], 'Critical' => (int) $r['critical'], 'High' => (int) $r['high'], 'Medium' => (int) $r['medium'], 'Low' => (int) $r['low'], '-' => (int) $r['unrated'], 'assets' => (int) $r['assets']];
            }
            $first = $DB->doQuery('SELECT MIN(`day`) AS d FROM `glpi_plugin_sistemasinternos_wzsnaps`')->fetch_assoc();
            $mttr['since'] = $first['d'] ?? null;
            $from = $DB->quote(date('Y-m-d', strtotime('-180 days')));
            $it = $DB->doQuery("SELECT `severity`, COUNT(*) AS n, AVG(TIMESTAMPDIFF(HOUR, `first_seen`, `resolved_at`)) AS hours, MAX(TIMESTAMPDIFF(HOUR, `first_seen`, `resolved_at`)) AS maxh FROM `glpi_plugin_sistemasinternos_wzvulns` WHERE `resolved_at` >= $from$inIds GROUP BY `severity`");
            while ($r = $it->fetch_assoc()) {
                $mttr['rows'][(string) $r['severity']] = ['fixed' => (int) $r['n'], 'avg_days' => round(((float) $r['hours']) / 24, 1), 'max_days' => round(((float) $r['maxh']) / 24, 1)];
            }
            $from30 = $DB->quote(date('Y-m-d H:i:s', strtotime('-30 days')));
            $fx = $DB->doQuery("SELECT COUNT(*) AS n FROM `glpi_plugin_sistemasinternos_wzvulns` WHERE `resolved_at` >= $from30$inIds")->fetch_assoc();
            $mttr['fixed30'] = (int) ($fx['n'] ?? 0);
        }
        $age = [];
        foreach (Wazuh::buckets($res, 'age') as $b) {
            $age[] = ['name' => (string) $b['key'], 'sev' => self::sevCounts($b['sev']['buckets'])];
        }
        $timeline = [];
        foreach (Wazuh::buckets($res, 'timeline') as $b) {
            $timeline[] = ['day' => substr((string) $b['key_as_string'], 0, 10), 'sev' => self::sevCounts($b['sev']['buckets'])];
        }
        return [
            'total' => $total, 'sev' => $sev, 'assets' => (int) $res['aggregations']['agents']['value'], 'agents_total' => count($agents),
            'cves' => (int) $res['aggregations']['cves']['value'], 'crit_assets' => (int) $res['aggregations']['crit_agents']['n']['value'], 'crit_old' => (int) $res['aggregations']['crit_old']['doc_count'],
            'top_cves' => array_map($cveRow, Wazuh::buckets($res, 'top_cves')),
            'top_crit' => array_map($cveRow, $res['aggregations']['top_crit']['c']['buckets'] ?? []),
            'pkgs' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count'], 'assets' => (int) $b['assets']['value'], 'sev' => self::sevCounts($b['sev']['buckets'])], Wazuh::buckets($res, 'pkgs')),
            'os' => array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count'], 'sev' => self::sevCounts($b['sev']['buckets'])], Wazuh::buckets($res, 'os')),
            'cvss' => array_map(fn ($b) => ['from' => (float) $b['key'], 'count' => (int) $b['doc_count']], $res['aggregations']['cvss']['h']['buckets'] ?? []),
            'age' => $age, 'timeline' => $timeline, 'snaps' => $snaps, 'mttr' => $mttr, 'by_agent' => array_slice($byAgent, 0, 12),
            'at' => time(),
        ];
    }

    // ------------------------------------------------------------------ por ativo

    private static function assets(string $entity, string $q): array
    {
        $agents = Wazuh::agents();
        $filter = Wazuh::agentIdsOf($entity);
        $query = $filter === null ? ['match_all' => new \stdClass()] : ['terms' => ['agent.id' => $filter]];
        $res = Wazuh::cachedSearch(Wazuh::IDX_VULN, ['size' => 0, 'query' => $query, 'aggs' => ['ag' => ['terms' => ['field' => 'agent.id', 'size' => 1000], 'aggs' => [
            'sev' => self::sevAgg(), 'score' => ['max' => ['field' => 'vulnerability.score.base']], 'since' => ['min' => ['field' => 'vulnerability.detected_at']],
            'pk' => ['cardinality' => ['field' => 'package.name']]]]]], 60);
        $found = [];
        foreach (Wazuh::buckets($res, 'ag') as $b) {
            $found[(string) $b['key']] = $b;
        }
        $ids = array_keys($found);
        $tickets = Wazuh::ticketsOf($ids);
        $rows = [];
        foreach ($agents as $id => $a) {
            if ($filter !== null && !in_array((string) $id, $filter, true)) {
                continue;
            }
            $b = $found[(string) $id] ?? null;
            $t = $tickets[(string) $id] ?? [];
            $row = [
                'id' => (string) $id, 'name' => $a['name'], 'ip' => $a['ip'], 'os' => $a['os'], 'status' => $a['status'], 'computer' => $a['computer'],
                'sev' => $b ? self::sevCounts($b['sev']['buckets']) : array_fill_keys(self::SEV_ORDER, 0), 'total' => $b ? (int) $b['doc_count'] : 0,
                'score' => $b ? round((float) ($b['score']['value'] ?? 0), 1) : 0, 'packages' => $b ? (int) $b['pk']['value'] : 0,
                'days' => $b ? self::days((string) ($b['since']['value_as_string'] ?? '')) : null,
                'tickets' => $t, 'open_tickets' => count(array_filter($t, fn ($x) => $x['open'])),
            ];
            if ($q !== '' && stripos(implode(' ', [$row['name'], $row['ip'], $row['os'], $a['computer']['name'] ?? '', $a['computer']['tech'] ?? '', $a['computer']['owner'] ?? '', $a['computer']['tech_group'] ?? '']), $q) === false) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => [$b['sev']['Critical'], $b['sev']['High'], $b['sev']['Medium'], $b['total']] <=> [$a['sev']['Critical'], $a['sev']['High'], $a['sev']['Medium'], $a['total']]);
        return ['assets' => $rows, 'entities' => Wazuh::entities()];
    }

    /** Vulnerabilidades de um ativo: lista paginada, pacotes afetados e totais. */
    private static function asset(string $id, string $sev, string $q, int $page): array
    {
        $agents = Wazuh::agents();
        if (!isset($agents[$id])) {
            throw new NotFound('Ativo desconhecido.');
        }
        $filter = [['term' => ['agent.id' => $id]]];
        if (isset(Wazuh::SEV[$sev])) {
            $filter[] = ['term' => ['vulnerability.severity' => $sev]];
        }
        $must = [];
        if ($q !== '') {
            $like = '*' . str_replace(['\\', '*', '?'], ['\\\\', '\\*', '\\?'], $q) . '*';
            $must[] = ['bool' => ['should' => [['wildcard' => ['vulnerability.id' => ['value' => $like, 'case_insensitive' => true]]],
                ['wildcard' => ['package.name' => ['value' => $like, 'case_insensitive' => true]]]], 'minimum_should_match' => 1]];
        }
        $size = 50;
        $res = Wazuh::cachedSearch(Wazuh::IDX_VULN, ['from' => $page * $size, 'size' => $size, 'track_total_hits' => true,
            'query' => ['bool' => ['filter' => $filter, 'must' => $must]],
            'sort' => [['vulnerability.score.base' => 'desc'], ['vulnerability.detected_at' => 'asc']],
            '_source' => ['vulnerability.id', 'vulnerability.severity', 'vulnerability.score', 'vulnerability.description', 'vulnerability.detected_at', 'vulnerability.published_at', 'vulnerability.reference', 'vulnerability.scanner',
                'package.name', 'package.version', 'package.type'],
            'aggs' => ['sev' => self::sevAgg(), 'pk' => ['terms' => ['field' => 'package.name', 'size' => 200, 'order' => ['score' => 'desc']], 'aggs' => [
                'score' => ['max' => ['field' => 'vulnerability.score.base']], 'sev' => self::sevAgg(), 'ver' => ['terms' => ['field' => 'package.version', 'size' => 1]], 'since' => ['min' => ['field' => 'vulnerability.detected_at']]]]]], 60);
        $items = [];
        foreach ($res['hits']['hits'] ?? [] as $h) {
            $s = $h['_source'];
            $v = $s['vulnerability'] ?? [];
            $cond = (string) ($v['scanner']['condition'] ?? '');
            $items[] = ['cve' => (string) ($v['id'] ?? ''), 'severity' => (string) ($v['severity'] ?? '-'), 'score' => (float) ($v['score']['base'] ?? 0),
                'package' => (string) ($s['package']['name'] ?? ''), 'version' => (string) ($s['package']['version'] ?? ''),
                'fix' => preg_match('/less than(?: or equal to)?\s+(.+)$/i', $cond, $m) ? trim($m[1]) : '', 'nofix' => stripos($cond, 'default status') !== false, 'detected' => (string) ($v['detected_at'] ?? ''), 'published' => (string) ($v['published_at'] ?? ''),
                'days' => self::days((string) ($v['detected_at'] ?? '')), 'description' => mb_substr((string) ($v['description'] ?? ''), 0, 400),
                'ref' => (string) (preg_split('/,\s*/', (string) ($v['reference'] ?? ''))[0] ?? '')];
        }
        $pkgs = array_map(fn ($b) => ['name' => (string) $b['key'], 'count' => (int) $b['doc_count'], 'score' => round((float) ($b['score']['value'] ?? 0), 1), 'sev' => self::sevCounts($b['sev']['buckets']),
            'version' => (string) ($b['ver']['buckets'][0]['key'] ?? ''), 'days' => self::days((string) ($b['since']['value_as_string'] ?? '')),
            'ticket' => self::openTicketFor(self::ticketKey('vuln', $id, (string) $b['key']))], array_slice(Wazuh::buckets($res, 'pk'), 0, 60));
        $a = $agents[$id];
        return ['asset' => $a + ['tickets' => Wazuh::ticketsOf([$id])[$id] ?? []], 'total' => Wazuh::total($res), 'page' => $page, 'size' => $size,
            'sev' => self::sevCounts(Wazuh::buckets($res, 'sev')), 'items' => $items, 'packages' => $pkgs];
    }

    // ------------------------------------------------------------------ lista filtrada e detalhe da CVE

    /** Versão corrigida a partir das condições do scanner ("Package less than X"): a maior entre as encontradas. */
    private static function fixOf(array $buckets): string
    {
        $best = '';
        foreach ($buckets as $b) {
            if (preg_match('/less than(?: or equal to)?\s+(.+)$/i', (string) $b['key'], $m) && ($best === '' || version_compare(trim($m[1]), $best, '>'))) {
                $best = trim($m[1]);
            }
        }
        return $best;
    }

    private static function fixText(string $cond): array
    {
        return [preg_match('/less than(?: or equal to)?\s+(.+)$/i', $cond, $m) ? trim($m[1]) : '', stripos($cond, 'default status') !== false];
    }

    /** Filtros comuns dos gráficos e das listas: severidade, ativo, entidade, pacote, CVE, idade, CVSS e dia. */
    private static function filters(array $q): array
    {
        $f = [];
        if (isset(Wazuh::SEV[(string) ($q['sev'] ?? '')])) {
            $f[] = ['term' => ['vulnerability.severity' => (string) $q['sev']]];
        }
        if (preg_match('/^\d{1,6}$/', (string) ($q['agent'] ?? ''))) {
            $f[] = ['term' => ['agent.id' => (string) $q['agent']]];
        }
        if (($ids = Wazuh::agentIdsOf((string) ($q['entity'] ?? ''))) !== null) {
            $f[] = ['terms' => ['agent.id' => $ids]];
        }
        if (($q['package'] ?? '') !== '') {
            $f[] = ['term' => ['package.name' => (string) $q['package']]];
        }
        if (preg_match('/^[A-Za-z0-9._:-]{3,64}$/', (string) ($q['cve'] ?? ''))) {
            $f[] = ['term' => ['vulnerability.id' => (string) $q['cve']]];
        }
        $range = ['0-7' => ['gte' => 'now-7d'], '8-30' => ['gte' => 'now-30d', 'lt' => 'now-7d'], '31-90' => ['gte' => 'now-90d', 'lt' => 'now-30d'], '90+' => ['lt' => 'now-90d']];
        if (isset($range[(string) ($q['age'] ?? '')])) {
            $f[] = ['range' => ['vulnerability.detected_at' => $range[$q['age']]]];
        }
        if (isset($q['min']) && is_numeric($q['min']) || isset($q['max']) && is_numeric($q['max'])) {
            $r = [];
            if (isset($q['min']) && is_numeric($q['min'])) {
                $r['gte'] = (float) $q['min'];
            }
            if (isset($q['max']) && is_numeric($q['max'])) {
                $r['lt'] = (float) $q['max'];
            }
            $f[] = ['range' => ['vulnerability.score.base' => $r]];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['day'] ?? ''))) {
            $f[] = ['range' => ['vulnerability.detected_at' => ['gte' => $q['day'], 'lt' => $q['day'] . '||+1d', 'time_zone' => Wazuh::tz()]]];
        }
        return $f;
    }

    private static function item(array $s, array $agents): array
    {
        $v = $s['vulnerability'] ?? [];
        $aid = (string) ($s['agent']['id'] ?? '');
        [$fix, $nofix] = self::fixText((string) ($v['scanner']['condition'] ?? ''));
        $ag = $agents[$aid] ?? null;
        return ['cve' => (string) ($v['id'] ?? ''), 'severity' => (string) ($v['severity'] ?? '-'), 'score' => (float) ($v['score']['base'] ?? 0), 'agent' => $aid,
            'asset' => (string) ($ag['name'] ?? ($s['agent']['name'] ?? $aid)), 'entity' => $ag['computer']['entity'] ?? 'Sem cadastro no GLPI',
            'package' => (string) ($s['package']['name'] ?? ''), 'version' => (string) ($s['package']['version'] ?? ''), 'fix' => $fix, 'nofix' => $nofix,
            'detected' => (string) ($v['detected_at'] ?? ''), 'published' => (string) ($v['published_at'] ?? ''), 'days' => self::days((string) ($v['detected_at'] ?? '')),
            'description' => mb_substr(html_entity_decode((string) ($v['description'] ?? ''), ENT_QUOTES | ENT_HTML5), 0, 280)];
    }

    private static function listing(array $q): array
    {
        $agents = Wazuh::agents();
        $must = [];
        $term = trim((string) ($q['q'] ?? ''));
        if ($term !== '') {
            $like = '*' . str_replace(['\\', '*', '?'], ['\\\\', '\\*', '\\?'], $term) . '*';
            $must[] = ['bool' => ['should' => [['wildcard' => ['vulnerability.id' => ['value' => $like, 'case_insensitive' => true]]], ['wildcard' => ['package.name' => ['value' => $like, 'case_insensitive' => true]]],
                ['wildcard' => ['agent.name' => ['value' => $like, 'case_insensitive' => true]]]], 'minimum_should_match' => 1]];
        }
        $sorts = ['score' => [['vulnerability.score.base' => 'desc'], ['vulnerability.detected_at' => 'asc']], 'recent' => [['vulnerability.detected_at' => 'desc']], 'old' => [['vulnerability.detected_at' => 'asc']]];
        $page = max(0, min(199, (int) ($q['page'] ?? 0)));
        $res = Wazuh::cachedSearch(Wazuh::IDX_VULN, ['from' => $page * 50, 'size' => 50, 'track_total_hits' => true, 'sort' => $sorts[$q['sort'] ?? ''] ?? $sorts['score'],
            'query' => ['bool' => ['filter' => self::filters($q), 'must' => $must]],
            '_source' => ['agent.id', 'agent.name', 'vulnerability.id', 'vulnerability.severity', 'vulnerability.score', 'vulnerability.detected_at', 'vulnerability.published_at', 'vulnerability.description', 'vulnerability.scanner.condition', 'package.name', 'package.version'],
            'aggs' => ['sev' => self::sevAgg(), 'assets' => ['cardinality' => ['field' => 'agent.id']], 'cves' => ['cardinality' => ['field' => 'vulnerability.id']]]], 45);
        return ['total' => Wazuh::total($res), 'page' => $page, 'size' => 50, 'sev' => self::sevCounts(Wazuh::buckets($res, 'sev')), 'assets' => (int) ($res['aggregations']['assets']['value'] ?? 0),
            'cves' => (int) ($res['aggregations']['cves']['value'] ?? 0), 'items' => array_map(fn ($h) => self::item($h['_source'], $agents), $res['hits']['hits'] ?? [])];
    }

    /** Tudo sobre uma CVE: descrição, pontuação, datas, referências e cada ativo/pacote afetado (com chamado). */
    private static function cve(string $id): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{3,64}$/', $id)) {
            throw new NotFound('CVE inválida.');
        }
        $res = Wazuh::cachedSearch(Wazuh::IDX_VULN, ['size' => 200, 'track_total_hits' => true, 'query' => ['term' => ['vulnerability.id' => $id]], 'sort' => [['agent.id' => 'asc']]], 45);
        $hits = $res['hits']['hits'] ?? [];
        if (!$hits) {
            throw new NotFound('Essa vulnerabilidade não está mais no Wazuh (provavelmente já foi corrigida).');
        }
        $agents = Wazuh::agents();
        $v = $hits[0]['_source']['vulnerability'];
        $refs = array_values(array_filter(array_map('trim', preg_split('/,\s*/', (string) ($v['reference'] ?? '')) ?: []), fn ($u) => preg_match('#^https?://#', $u)));
        $rows = [];
        foreach ($hits as $h) {
            $s = $h['_source'];
            $r = self::item($s, $agents);
            $r['description'] = '';
            $r['arch'] = (string) ($s['package']['architecture'] ?? '');
            $r['type'] = (string) ($s['package']['type'] ?? '');
            $r['condition'] = (string) ($s['vulnerability']['scanner']['condition'] ?? '');
            $r['computer'] = $agents[$r['agent']]['computer'] ?? null;
            $r['ticket'] = self::openTicketFor(self::ticketKey('vuln', $r['agent'], $r['package']));
            $rows[] = $r;
        }
        $order = array_flip(self::SEV_ORDER);
        usort($rows, fn ($a, $b) => [$order[$a['severity']] ?? 9, $a['asset']] <=> [$order[$b['severity']] ?? 9, $b['asset']]);
        return ['cve' => $id, 'severity' => (string) ($v['severity'] ?? '-'), 'score' => (float) ($v['score']['base'] ?? 0), 'score_version' => (string) ($v['score']['version'] ?? ''),
            'description' => html_entity_decode((string) ($v['description'] ?? ''), ENT_QUOTES | ENT_HTML5), 'published' => (string) ($v['published_at'] ?? ''), 'category' => (string) ($v['category'] ?? ''), 'classification' => (string) ($v['classification'] ?? ''),
            'enumeration' => (string) ($v['enumeration'] ?? ''), 'under_evaluation' => (bool) ($v['under_evaluation'] ?? false), 'source' => (string) ($v['scanner']['source'] ?? ''), 'cti' => (string) ($v['scanner']['reference'] ?? ''),
            'references' => array_slice($refs, 0, 40), 'rows' => $rows, 'total' => Wazuh::total($res),
            'first_detected' => min(array_filter(array_column($rows, 'detected')) ?: ['']), 'days' => self::days(min(array_filter(array_column($rows, 'detected')) ?: ['']))];
    }

    // ------------------------------------------------------------------ operacional

    /** Chave estável do que vira chamado (evita abrir o mesmo duas vezes). */
    public static function ticketKey(string $kind, string $agent, string $ref): string
    {
        return substr(sha1("$kind|$agent|$ref"), 0, 12);
    }

    /** Chamado aberto para a chave: da tabela do plugin ou achado pelo [WZ:chave] no título. */
    private static function openTicketFor(string $key): ?array
    {
        global $DB;
        $hit = $DB->request(['SELECT' => ['t.id', 't.name', 't.status'], 'FROM' => 'glpi_plugin_sistemasinternos_wztickets AS w',
            'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['w' => 'tickets_id', 't' => 'id']]],
            'WHERE' => ['w.tkey' => $key, 't.is_deleted' => 0, 't.status' => ['<', 5]], 'ORDER' => 't.id DESC', 'LIMIT' => 1])->current();
        $hit ??= $DB->request(['SELECT' => ['id', 'name', 'status'], 'FROM' => 'glpi_tickets', 'WHERE' => ['is_deleted' => 0, 'status' => ['<', 5], 'name' => ['LIKE', "%[WZ:$key]%"]], 'LIMIT' => 1])->current();
        return $hit ? ['id' => (int) $hit['id'], 'name' => (string) $hit['name'], 'status' => (int) $hit['status'], 'url' => Ticket::getFormURLWithID((int) $hit['id'])] : null;
    }

    private static function lastTicketFor(string $key): ?array
    {
        global $DB;
        $hit = $DB->request(['SELECT' => ['t.id', 't.status'], 'FROM' => 'glpi_plugin_sistemasinternos_wztickets AS w',
            'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['w' => 'tickets_id', 't' => 'id']]], 'WHERE' => ['w.tkey' => $key, 't.is_deleted' => 0], 'ORDER' => 't.id DESC', 'LIMIT' => 1])->current();
        return $hit ? ['id' => (int) $hit['id'], 'status' => (int) $hit['status'], 'url' => Ticket::getFormURLWithID((int) $hit['id'])] : null;
    }

    private static function operational(string $entity, string $minSev, string $q, bool $free): array
    {
        $min = Wazuh::SEV[$minSev][2] ?? 2;
        $sevs = array_values(array_filter(array_keys(Wazuh::SEV), fn ($k) => Wazuh::SEV[$k][2] >= max(1, $min)));
        $filter = [['terms' => ['vulnerability.severity' => $sevs]]];
        $ids = Wazuh::agentIdsOf($entity);
        if ($ids !== null) {
            $filter[] = ['terms' => ['agent.id' => $ids]];
        }
        $res = Wazuh::cachedSearch(Wazuh::IDX_VULN, ['size' => 0, 'query' => ['bool' => ['filter' => $filter]], 'aggs' => ['ag' => ['terms' => ['field' => 'agent.id', 'size' => 500], 'aggs' => [
            'pk' => ['terms' => ['field' => 'package.name', 'size' => 300], 'aggs' => ['score' => ['max' => ['field' => 'vulnerability.score.base']], 'sev' => self::sevAgg(),
                'since' => ['min' => ['field' => 'vulnerability.detected_at']], 'ver' => ['terms' => ['field' => 'package.version', 'size' => 1]], 'cves' => ['cardinality' => ['field' => 'vulnerability.id']],
                'cond' => ['terms' => ['field' => 'vulnerability.scanner.condition', 'size' => 6]]]]]]]], 60);
        $agents = Wazuh::agents();
        $rows = [];
        foreach (Wazuh::buckets($res, 'ag') as $a) {
            $aid = (string) $a['key'];
            $ag = $agents[$aid] ?? ['name' => $aid, 'computer' => null];
            foreach ($a['pk']['buckets'] as $p) {
                $sev = self::sevCounts($p['sev']['buckets']);
                $top = 'Low';
                foreach (self::SEV_ORDER as $k) {
                    if ($sev[$k] > 0) {
                        $top = $k;
                        break;
                    }
                }
                $score = round((float) ($p['score']['value'] ?? 0), 1);
                $days = self::days((string) ($p['since']['value_as_string'] ?? '')) ?? 0;
                $row = ['agent' => $aid, 'asset' => $ag['name'], 'entity' => $ag['computer']['entity'] ?? 'Sem cadastro no GLPI', 'computer' => $ag['computer'],
                    'package' => (string) $p['key'], 'version' => (string) ($p['ver']['buckets'][0]['key'] ?? ''), 'cves' => (int) $p['cves']['value'], 'fix' => self::fixOf($p['cond']['buckets'] ?? []), 'sev' => $sev, 'top' => $top, 'score' => $score, 'days' => $days,
                    'priority' => round($score * 10 + $sev['Critical'] * 8 + $sev['High'] * 3 + min($days, 180) / 6, 1),
                    'key' => self::ticketKey('vuln', $aid, (string) $p['key'])];
                if ($q !== '' && stripos($row['asset'] . ' ' . $row['package'] . ' ' . $row['entity'], $q) === false) {
                    continue;
                }
                $rows[] = $row;
            }
        }
        usort($rows, fn ($a, $b) => $b['priority'] <=> $a['priority']);
        $rows = array_slice($rows, 0, 400);
        $out = [];
        foreach ($rows as $r) {
            $r['ticket'] = self::openTicketFor($r['key']);
            $r['last_ticket'] = $r['ticket'] ? null : self::lastTicketFor($r['key']);
            if ($free && $r['ticket']) {
                continue;
            }
            $r['level'] = $r['top'] === 'Critical' ? 'P1' : ($r['top'] === 'High' ? 'P2' : ($r['top'] === 'Medium' ? 'P3' : 'P4'));
            $out[] = $r;
        }
        return ['rows' => $out, 'entities' => Wazuh::entities(), 'summary' => [
            'p1' => count(array_filter($out, fn ($r) => $r['level'] === 'P1')), 'p2' => count(array_filter($out, fn ($r) => $r['level'] === 'P2')),
            'with_ticket' => count(array_filter($out, fn ($r) => $r['ticket'])), 'total' => count($out)]];
    }

    /** Alertas do Wazuh (nível alto) que podem virar chamado: um por regra e ativo, já com o chamado se existir. */
    private static function opsAlerts(string $entity, int $hours, int $level): array
    {
        $filter = [['range' => ['@timestamp' => ['gte' => "now-{$hours}h"]]], ['range' => ['rule.level' => ['gte' => $level]]]];
        $ids = Wazuh::agentIdsOf($entity);
        if ($ids !== null) {
            $filter[] = ['terms' => ['agent.id' => $ids]];
        }
        $res = Wazuh::cachedSearch(Wazuh::IDX_ALERTS, ['size' => 0, 'query' => ['bool' => ['filter' => $filter]], 'aggs' => ['r' => ['terms' => ['field' => 'rule.id', 'size' => 100], 'aggs' => [
            'ag' => ['terms' => ['field' => 'agent.id', 'size' => 50], 'aggs' => ['last' => ['top_hits' => ['size' => 1, 'sort' => [['@timestamp' => 'desc']],
                '_source' => ['rule.description', 'rule.level', 'rule.groups', 'rule.mitre', 'agent.name', '@timestamp', 'data.srcip', 'full_log']]], 'first' => ['min' => ['field' => '@timestamp']]]]]]]], 60);
        $agents = Wazuh::agents();
        $rows = [];
        foreach (Wazuh::buckets($res, 'r') as $r) {
            foreach ($r['ag']['buckets'] as $a) {
                $aid = (string) $a['key'];
                $s = $a['last']['hits']['hits'][0]['_source'] ?? [];
                $key = self::ticketKey('alert', $aid, (string) $r['key']);
                $ag = $agents[$aid] ?? ['name' => (string) ($s['agent']['name'] ?? $aid), 'computer' => null];
                $rows[] = ['rule' => (string) $r['key'], 'agent' => $aid, 'asset' => $ag['name'], 'entity' => $ag['computer']['entity'] ?? 'Sem cadastro no GLPI', 'computer' => $ag['computer'],
                    'description' => (string) ($s['rule']['description'] ?? ''), 'level' => (int) ($s['rule']['level'] ?? 0), 'groups' => array_slice((array) ($s['rule']['groups'] ?? []), 0, 4),
                    'mitre' => array_slice((array) ($s['rule']['mitre']['technique'] ?? []), 0, 2), 'count' => (int) $a['doc_count'], 'last' => (string) ($s['@timestamp'] ?? ''),
                    'srcip' => (string) ($s['data']['srcip'] ?? ''), 'key' => $key, 'ticket' => self::openTicketFor($key), 'last_ticket' => null];
            }
        }
        usort($rows, fn ($a, $b) => [$b['level'], $b['count']] <=> [$a['level'], $a['count']]);
        foreach ($rows as &$r) {
            $r['last_ticket'] = $r['ticket'] ? null : self::lastTicketFor($r['key']);
        }
        return ['rows' => array_slice($rows, 0, 200), 'entities' => Wazuh::entities(), 'hours' => $hours, 'level' => $level];
    }

    // ------------------------------------------------------------------ chamado

    /** Abre o chamado de uma vulnerabilidade (pacote em um ativo) ou de um alerta, sem duplicar. */
    public static function openTicket(string $kind, string $agentId, string $ref): array
    {
        global $DB;
        if (!Session::haveRight('ticket', CREATE)) {
            throw new Denied('Seu perfil não pode abrir chamados.');
        }
        if (!in_array($kind, ['vuln', 'alert'], true) || $agentId === '' || $ref === '') {
            throw new RuntimeException('Pedido inválido.');
        }
        $agents = Wazuh::agents();
        $ag = $agents[$agentId] ?? null;
        if (!$ag) {
            throw new NotFound('Ativo desconhecido.');
        }
        $key = self::ticketKey($kind, $agentId, $ref);
        if ($open = self::openTicketFor($key)) {
            return ['ok' => true, 'duplicate' => true, 'ticket' => $open, 'message' => "Já existe o chamado #{$open['id']} em aberto para isto."];
        }
        $host = $ag['computer']['name'] ?? $ag['name'];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        if ($kind === 'vuln') {
            $res = Wazuh::search(Wazuh::IDX_VULN, ['size' => 100, 'query' => ['bool' => ['filter' => [['term' => ['agent.id' => $agentId]], ['term' => ['package.name' => $ref]]]]],
                'sort' => [['vulnerability.score.base' => 'desc']], '_source' => ['vulnerability.id', 'vulnerability.severity', 'vulnerability.score', 'vulnerability.detected_at', 'vulnerability.scanner', 'vulnerability.reference', 'package.version']]);
            $hits = $res['hits']['hits'] ?? [];
            if (!$hits) {
                throw new NotFound('Essa vulnerabilidade não existe mais no Wazuh (provavelmente já foi corrigida).');
            }
            $top = 'Low';
            $lines = [];
            $fix = '';
            foreach ($hits as $h) {
                $v = $h['_source']['vulnerability'];
                $sv = (string) ($v['severity'] ?? '-');
                if ((Wazuh::SEV[$sv][2] ?? 0) > (Wazuh::SEV[$top][2] ?? 0)) {
                    $top = $sv;
                }
                if ($fix === '' && preg_match('/less than(?: or equal to)?\s+(.+)$/i', (string) ($v['scanner']['condition'] ?? ''), $m)) {
                    $fix = trim($m[1]);
                }
                $lines[] = '<li><b>' . $e($v['id'] ?? '') . '</b> · ' . $e(Wazuh::SEV[$sv][0] ?? $sv) . ' · CVSS ' . $e($v['score']['base'] ?? '—') . ' · detectada em ' . $e(substr((string) ($v['detected_at'] ?? ''), 0, 10)) . '</li>';
            }
            $ver = (string) ($hits[0]['_source']['package']['version'] ?? '');
            $title = '[Wazuh] Atualizar ' . $ref . ' em ' . $host . ' (' . count($hits) . ' vulnerabilidade' . (count($hits) > 1 ? 's' : '') . ', ' . (Wazuh::SEV[$top][0] ?? $top) . ") [WZ:$key]";
            $content = '<p>O Wazuh detectou vulnerabilidades no pacote <b>' . $e($ref) . '</b> (versão instalada <b>' . $e($ver) . '</b>) no ativo <b>' . $e($ag['name']) . '</b> (' . $e($ag['ip']) . ').</p>'
                . ($fix !== '' ? '<p><b>Ação sugerida:</b> atualizar o pacote para a versão <b>' . $e($fix) . '</b> ou superior (por exemplo, <code>apt update &amp;&amp; apt upgrade ' . $e($ref) . '</code>).</p>' : '<p><b>Ação sugerida:</b> atualizar o pacote.</p>')
                . '<ul>' . implode('', array_slice($lines, 0, 40)) . '</ul>' . (count($lines) > 40 ? '<p>… e mais ' . (count($lines) - 40) . '.</p>' : '')
                . '<p><i>Chamado aberto pelo plugin Sistemas Internos 360 a partir do Wazuh.</i></p>';
            $level = Wazuh::SEV[$top][2] ?? 1;
        } else {
            $res = Wazuh::search(Wazuh::IDX_ALERTS, ['size' => 1, 'track_total_hits' => true, 'sort' => [['@timestamp' => 'desc']],
                'query' => ['bool' => ['filter' => [['term' => ['agent.id' => $agentId]], ['term' => ['rule.id' => $ref]], ['range' => ['@timestamp' => ['gte' => 'now-7d']]]]]]]);
            $h = $res['hits']['hits'][0]['_source'] ?? null;
            if (!$h) {
                throw new NotFound('Nenhum alerta recente dessa regra neste ativo.');
            }
            $rl = (int) ($h['rule']['level'] ?? 0);
            $title = '[Wazuh] ' . ($h['rule']['description'] ?? 'Alerta') . ' em ' . $host . ' (nível ' . $rl . ") [WZ:$key]";
            $content = '<p>Alerta do Wazuh na regra <b>' . $e($ref) . '</b> (nível <b>' . $rl . '</b>) no ativo <b>' . $e($ag['name']) . '</b> (' . $e($ag['ip']) . ').</p>'
                . '<ul><li>Descrição: ' . $e($h['rule']['description'] ?? '') . '</li><li>Ocorrências nos últimos 7 dias: <b>' . Wazuh::total($res) . '</b></li><li>Último evento: ' . $e($h['@timestamp'] ?? '') . '</li>'
                . (!empty($h['rule']['groups']) ? '<li>Grupos: ' . $e(implode(', ', (array) $h['rule']['groups'])) . '</li>' : '')
                . (!empty($h['rule']['mitre']['technique']) ? '<li>MITRE ATT&amp;CK: ' . $e(implode(', ', (array) $h['rule']['mitre']['technique'])) . '</li>' : '')
                . (!empty($h['data']['srcip']) ? '<li>IP de origem: ' . $e($h['data']['srcip']) . '</li>' : '') . '</ul>'
                . (!empty($h['full_log']) ? '<p>Registro:</p><pre>' . $e(mb_substr((string) $h['full_log'], 0, 800)) . '</pre>' : '')
                . '<p><i>Chamado aberto pelo plugin Sistemas Internos 360 a partir do Wazuh.</i></p>';
            $level = $rl >= 12 ? 4 : ($rl >= 10 ? 3 : ($rl >= 7 ? 2 : 1));
        }
        $urgency = max(1, min(5, $level + 1));
        $input = ['name' => mb_substr($title, 0, 250), 'content' => $content, 'type' => Ticket::INCIDENT_TYPE, 'urgency' => $urgency, 'impact' => 3, 'priority' => $urgency,
            'entities_id' => (int) ($ag['computer']['entity_id'] ?? Settings::get('entities_id') ?: 0), '_users_id_requester' => (int) Session::getLoginUserID()];
        if ($ag['computer']) {
            $input['_items_id'] = ['Computer' => [$ag['computer']['id']]];
            if (!empty($ag['computer']['groups_id_tech'])) {
                $input['_groups_id_assign'] = $ag['computer']['groups_id_tech'];
            }
        }
        $t = new Ticket();
        $id = $t->add($input);
        if (!$id) {
            $msg = '';
            foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [] as $list) {
                $msg .= strip_tags(implode(' ', (array) $list)) . ' ';
            }
            unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
            throw new RuntimeException('O GLPI não criou o chamado. ' . trim($msg));
        }
        $DB->insert('glpi_plugin_sistemasinternos_wztickets', ['tkey' => $key, 'kind' => $kind, 'agent_id' => $agentId, 'ref' => mb_substr($ref, 0, 255), 'tickets_id' => (int) $id,
            'users_id' => (int) Session::getLoginUserID(), 'date_creation' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'duplicate' => false, 'ticket' => ['id' => (int) $id, 'url' => Ticket::getFormURLWithID((int) $id), 'status' => 1], 'message' => "Chamado #$id aberto."];
    }
}
