<?php

namespace GlpiPlugin\Sistemasinternos;

use RuntimeException;
use Throwable;

/**
 * EPSS (Exploit Prediction Scoring System, FIRST.org): a probabilidade de uma CVE ser explorada nos
 * próximos 30 dias. Cruza as CVEs de um ativo (ou de todos) no Wazuh com o EPSS e devolve o ranking
 * da mais à menos provável. O EPSS vem da API pública api.first.org, consultada pelo servidor do GLPI
 * e guardada em cache por 12 horas (o FIRST atualiza uma vez por dia).
 */
class WazuhEpss
{
    private const API = 'https://api.first.org/data/v1/epss';
    private const TTL = 43200;
    private const BATCH = 80;

    /** Faixas de probabilidade: [mínimo, rótulo, cor]. */
    public const BANDS = [
        ['min' => 0.5, 'label' => 'Muito provável (≥ 50%)', 'color' => '#ef4f4f'],
        ['min' => 0.1, 'label' => 'Provável (10 a 50%)', 'color' => '#f2783f'],
        ['min' => 0.01, 'label' => 'Possível (1 a 10%)', 'color' => '#f7b84b'],
        ['min' => 0.0, 'label' => 'Improvável (< 1%)', 'color' => '#5b8def'],
    ];

    public static function route(array $q): array
    {
        $agent = preg_match('/^\d{1,6}$/', (string) ($q['agent'] ?? '')) ? (string) $q['agent'] : '';
        $filter = [];
        if ($agent !== '') {
            $filter[] = ['term' => ['agent.id' => $agent]];
        } elseif (($ids = Wazuh::agentIdsOf((string) ($q['entity'] ?? ''))) !== null) {
            $filter[] = ['terms' => ['agent.id' => $ids]];
        }
        $res = Wazuh::cachedSearch(Wazuh::IDX_VULN, ['size' => 0, 'query' => ['bool' => ['filter' => $filter]], 'aggs' => ['c' => ['terms' => ['field' => 'vulnerability.id', 'size' => 3000, 'order' => ['score' => 'desc']], 'aggs' => [
            'score' => ['max' => ['field' => 'vulnerability.score.base']], 'sev' => ['terms' => ['field' => 'vulnerability.severity', 'size' => 1, 'order' => ['_key' => 'asc']]],
            'pkg' => ['terms' => ['field' => 'package.name', 'size' => 1]], 'assets' => ['cardinality' => ['field' => 'agent.id']], 'since' => ['min' => ['field' => 'vulnerability.detected_at']],
            'cond' => ['terms' => ['field' => 'vulnerability.scanner.condition', 'size' => 4]]]], 'dist' => ['cardinality' => ['field' => 'vulnerability.id']]]], 120);
        $cves = [];
        foreach (Wazuh::buckets($res, 'c') as $b) {
            $id = (string) $b['key'];
            if (preg_match('/^CVE-\d{4}-\d{4,}$/i', $id)) {
                $cves[strtoupper($id)] = $b;
            }
        }
        $all = (int) ($res['aggregations']['c']['sum_other_doc_count'] ?? 0);
        $epss = self::lookup(array_keys($cves));

        $items = [];
        $missing = 0;
        $date = '';
        foreach ($cves as $id => $b) {
            $e = $epss[$id] ?? null;
            if ($e === null) {
                $missing++;
                continue;
            }
            $date = max($date, $e['date']);
            $fix = '';
            foreach ($b['cond']['buckets'] ?? [] as $c) {
                if (preg_match('/less than(?: or equal to)?\s+(.+)$/i', (string) $c['key'], $m) && ($fix === '' || version_compare(trim($m[1]), $fix, '>'))) {
                    $fix = trim($m[1]);
                }
            }
            $items[] = ['cve' => $id, 'epss' => $e['epss'], 'pct' => $e['pct'], 'cvss' => round((float) ($b['score']['value'] ?? 0), 1), 'severity' => (string) ($b['sev']['buckets'][0]['key'] ?? '-'),
                'package' => (string) ($b['pkg']['buckets'][0]['key'] ?? ''), 'assets' => (int) $b['assets']['value'], 'fix' => $fix,
                'days' => ($t = strtotime((string) ($b['since']['value_as_string'] ?? ''))) ? max(0, (int) floor((time() - $t) / 86400)) : null];
        }
        usort($items, fn ($a, $b) => [$b['epss'], $b['cvss']] <=> [$a['epss'], $a['cvss']]);

        $bands = [];
        foreach (self::BANDS as $i => $band) {
            $bands[$i] = ['label' => $band['label'], 'color' => $band['color'], 'min' => $band['min'], 'count' => 0];
        }
        foreach ($items as $it) {
            foreach (self::BANDS as $i => $band) {
                if ($it['epss'] >= $band['min']) {
                    $bands[$i]['count']++;
                    break;
                }
            }
        }
        $n = count($items);
        return [
            'agent' => $agent, 'total' => count($cves), 'distinct' => (int) ($res['aggregations']['dist']['value'] ?? count($cves)), 'analyzed' => $n, 'missing' => $missing, 'truncated' => $all > 0, 'date' => $date,
            'max' => $n ? $items[0]['epss'] : 0, 'avg' => $n ? round(array_sum(array_column($items, 'epss')) / $n, 5) : 0,
            'bands' => array_values($bands), 'items' => array_slice($items, 0, 300),
            // curva completa (da mais à menos provável): só o necessário para o gráfico
            'curve' => array_map(fn ($it) => [$it['cve'], $it['epss']], $items),
            'at' => time(),
        ];
    }

    /**
     * EPSS de cada CVE: do cache (12 h) ou da API do FIRST, em lotes e em paralelo.
     * @return array<string, array{epss: float, pct: float, date: string}>
     */
    public static function lookup(array $ids): array
    {
        global $GLPI_CACHE;
        $out = [];
        $need = [];
        $key = fn (string $id) => 'plugin_si_epss_' . str_replace('-', '_', $id);
        foreach ($ids as $id) {
            try {
                $hit = $GLPI_CACHE?->get($key($id));
            } catch (Throwable) {
                $hit = null;
            }
            if (is_array($hit)) {
                if (isset($hit['epss'])) {
                    $out[$id] = $hit;
                }
            } else {
                $need[] = $id;
            }
        }
        if (!$need) {
            return $out;
        }
        $batches = array_chunk($need, self::BATCH);
        $failed = 0;
        foreach (array_chunk($batches, 8) as $group) { // 8 pedidos de cada vez
            $mh = curl_multi_init();
            $handles = [];
            foreach ($group as $i => $batch) {
                $ch = curl_init(self::API . '?limit=100&cve=' . implode(',', $batch));
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'GLPI-SistemasInternos360']);
                global $CFG_GLPI;
                if (!empty($CFG_GLPI['proxy_name'])) {
                    curl_setopt($ch, CURLOPT_PROXY, $CFG_GLPI['proxy_name'] . ':' . (int) $CFG_GLPI['proxy_port']);
                }
                curl_multi_add_handle($mh, $ch);
                $handles[$i] = $ch;
            }
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $status === CURLM_OK);
            foreach ($handles as $i => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $json = $code === 200 ? json_decode((string) $body, true) : null;
                if (!is_array($json) || !isset($json['data'])) {
                    $failed++;
                    continue;
                }
                $found = [];
                foreach ($json['data'] as $row) {
                    $id = strtoupper((string) ($row['cve'] ?? ''));
                    $found[$id] = ['epss' => (float) $row['epss'], 'pct' => (float) $row['percentile'], 'date' => (string) ($row['date'] ?? '')];
                }
                foreach ($group[$i] as $id) {
                    $val = $found[$id] ?? ['none' => true]; // CVE que o FIRST ainda não pontuou: não pergunta de novo por 12 h
                    try {
                        $GLPI_CACHE?->set($key($id), $val, self::TTL);
                    } catch (Throwable) {
                    }
                    if (isset($val['epss'])) {
                        $out[$id] = $val;
                    }
                }
            }
            curl_multi_close($mh);
        }
        if ($failed === count($batches) && !$out) {
            throw new RuntimeException('Não foi possível consultar o EPSS em api.first.org. O servidor do GLPI precisa de acesso HTTPS a esse endereço (ou ao proxy configurado no GLPI).');
        }
        return $out;
    }
}
