<?php

namespace GlpiPlugin\Satisfacao;

use Ticket;

/**
 * Painel de satisfação: totais de envios e respostas, % de positivas, negativas e sem
 * resposta, notas e NPS, evolução por mês, origem, categoria e entidade. Cada cartão e
 * cada barra abre a lista das pesquisas correspondentes num painel lateral.
 */
class Painel
{
    /** Humor da pesquisa: rótulo, emoji e cor. */
    public const MOODS = [
        'positive' => ['Positivas', '😀', '#22c55e'],
        'neutral'  => ['Neutras', '😐', '#f59e0b'],
        'negative' => ['Negativas', '🙁', '#ef4444'],
        'none'     => ['Sem resposta', '⏳', '#94a3b8'],
    ];

    private const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /**
     * Pesquisas do período com o humor de cada uma e os totais para os cartões e gráficos.
     *
     * @param array{from: string, to: string, origin?: string, entity?: ?int, category?: ?int} $f (entidade e categoria null = todas)
     */
    public static function data(array $f): array
    {
        global $DB;
        $surveys = [];
        foreach ($DB->request([
            'FROM'  => Survey::getTable(),
            'WHERE' => ['AND' => [['date_sent' => ['>=', $f['from'] . ' 00:00:00']], ['date_sent' => ['<=', $f['to'] . ' 23:59:59']]], 'NOT' => ['itemtype' => Survey::ENPS]],
            'ORDER' => ['date_sent DESC'],
        ]) as $row) {
            $surveys[(int) $row['id']] = $row + ['rating' => [], 'nps' => [], 'yesno' => [], 'comment' => '', 'category' => 0];
        }

        // respostas de cada pesquisa
        if ($surveys) {
            foreach ($DB->request(['FROM' => Survey::ANSWERS_TABLE, 'WHERE' => ['plugin_satisfacao_surveys_id' => array_keys($surveys)]]) as $a) {
                $id = (int) $a['plugin_satisfacao_surveys_id'];
                if ($a['score'] !== null && isset($surveys[$id][$a['type']])) {
                    $surveys[$id][$a['type']][] = (int) $a['score'];
                }
                if ($a['comment'] !== null && $a['comment'] !== '') {
                    $surveys[$id]['comment'] = trim($surveys[$id]['comment'] . ' ' . $a['comment']);
                }
            }
        }

        // categoria dos chamados e nomes de categoria e entidade
        $tickets = [];
        foreach ($surveys as $s) {
            if ($s['itemtype'] === Ticket::class) {
                $tickets[] = (int) $s['items_id'];
            }
        }
        $categoryOf = [];
        if ($tickets) {
            foreach ($DB->request(['SELECT' => ['id', 'itilcategories_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $tickets]]) as $t) {
                $categoryOf[(int) $t['id']] = (int) $t['itilcategories_id'];
            }
        }
        foreach ($surveys as $id => $s) {
            $surveys[$id]['category'] = $s['itemtype'] === Ticket::class ? ($categoryOf[(int) $s['items_id']] ?? 0) : 0;
        }
        $categories = self::names('glpi_itilcategories', 'completename', array_column($surveys, 'category'));
        $entities = self::names('glpi_entities', 'completename', array_column($surveys, 'entities_id'), true);

        $rows = [];
        foreach ($surveys as $id => $s) {
            if (($f['origin'] ?? '') !== '' && $s['itemtype'] !== $f['origin']) {
                continue;
            }
            if (($f['entity'] ?? null) !== null && (int) $s['entities_id'] !== (int) $f['entity']) {
                continue;
            }
            if (($f['category'] ?? null) !== null && (int) $s['category'] !== (int) $f['category']) {
                continue;
            }
            $answered = (int) $s['status'] === Survey::ANSWERED;
            $rating = $s['rating'] ? array_sum($s['rating']) / count($s['rating']) : null;
            $rows[$id] = [
                'id' => $id,
                'n'  => (string) $s['name'],
                'oc' => $s['itemtype'],
                'o'  => Survey::ORIGINS[$s['itemtype']] ?? $s['itemtype'],
                's'  => substr((string) $s['date_sent'], 0, 10),
                'a'  => $answered ? substr((string) $s['date_answered'], 0, 10) : null,
                'm'  => $answered ? self::mood($s) : 'none',
                'r'  => $rating !== null ? round($rating, 1) : null,
                'p'  => $s['nps'] ? (int) round(array_sum($s['nps']) / count($s['nps'])) : null,
                'y'  => $s['yesno'] ? (int) (array_sum($s['yesno']) === count($s['yesno'])) : null,
                'c'  => (string) $s['comment'],
                'e'  => (string) ($entities[(int) $s['entities_id']] ?? ''),
                'k'  => (string) ($categories[(int) $s['category']] ?? ''),
                'u'  => self::url($s['itemtype'], (int) $s['items_id']),
                'd'  => $answered && $s['date_answered'] ? max(0, (strtotime($s['date_answered']) - strtotime($s['date_sent'])) / DAY_TIMESTAMP) : null,
                'rs' => $s['rating'],
                'ns' => $s['nps'],
            ];
        }

        return self::summarize($rows) + [
            // opções dos filtros: o que existe no período
            'entities'   => $entities,
            'categories' => array_filter($categories, fn ($id) => $id > 0, ARRAY_FILTER_USE_KEY),
        ];
    }

    /** Totais, percentuais e agrupamentos a partir das pesquisas já filtradas. */
    private static function summarize(array $rows): array
    {
        $total = count($rows);
        $empty = array_fill_keys(array_keys(self::MOODS), 0);
        $mood = $empty;
        $answered = 0;
        $days = [];
        $stars = [];
        $nps = [];
        $dist = array_fill(1, 5, 0);
        $months = [];
        $origins = [];
        $cats = [];
        $ents = [];

        $score = function (array &$bucket, string $label, array $rating) {
            if ($label === '') {
                return;
            }
            $bucket[$label] ??= ['n' => 0, 'rated' => 0, 'sum' => 0.0];
            $bucket[$label]['n']++;
            $bucket[$label]['rated'] += count($rating);
            $bucket[$label]['sum'] += array_sum($rating);
        };

        foreach ($rows as $r) {
            $mood[$r['m']]++;
            if ($r['a']) {
                $answered++;
                if ($r['d'] !== null) {
                    $days[] = $r['d'];
                }
            }
            foreach ($r['rs'] as $v) {
                $stars[] = $v;
                $dist[$v] = ($dist[$v] ?? 0) + 1;
            }
            foreach ($r['ns'] as $v) {
                $nps[] = $v;
            }

            $month = substr($r['s'], 0, 7);
            $months[$month] ??= $empty + ['rated' => 0, 'sum' => 0.0];
            $months[$month][$r['m']]++;
            $months[$month]['rated'] += count($r['rs']);
            $months[$month]['sum'] += array_sum($r['rs']);

            $origins[$r['o']] ??= $empty;
            $origins[$r['o']][$r['m']]++;
            $score($cats, $r['k'], $r['rs']);
            $score($ents, $r['e'], $r['rs']);
        }
        ksort($months);

        $pct = fn (int $n) => $total ? round(100 * $n / $total, 1) : 0;
        $promoters = count(array_filter($nps, fn ($v) => $v >= 9));
        $detractors = count(array_filter($nps, fn ($v) => $v <= 6));

        $group = function (array $bucket) {
            $out = [];
            foreach ($bucket as $label => $b) {
                $out[] = ['label' => (string) $label, 'n' => $b['n'], 'avg' => $b['rated'] ? round($b['sum'] / $b['rated'], 2) : null];
            }
            usort($out, fn ($a, $b) => $b['n'] <=> $a['n']);
            return array_slice($out, 0, 12);
        };

        return [
            'total'    => $total,
            'answered' => $answered,
            'rate'     => $pct($answered),
            'mood'     => $mood,
            'pct'      => array_map($pct, $mood),
            'stars'    => $stars ? round(array_sum($stars) / count($stars), 2) : null,
            'starsN'   => count($stars),
            'nps'      => $nps ? (int) round(100 * ($promoters - $detractors) / count($nps)) : null,
            'npsN'     => count($nps),
            'days'     => $days ? round(array_sum($days) / count($days), 1) : null,
            'dist'     => $dist,
            'months'   => array_map(fn ($k, $m) => [
                'label'    => ucfirst(self::MONTHS[(int) substr($k, 5, 2) - 1]) . '/' . substr($k, 2, 2),
                'key'      => $k,
                'positive' => $m['positive'], 'neutral' => $m['neutral'], 'negative' => $m['negative'], 'none' => $m['none'],
                'avg'      => $m['rated'] ? round($m['sum'] / $m['rated'], 2) : null,
            ], array_keys($months), array_values($months)),
            'origins'  => array_map(fn ($k, $o) => ['label' => (string) $k] + $o, array_keys($origins), array_values($origins)),
            'cats'     => $group($cats),
            'ents'     => $group($ents),
            'rows'     => array_values($rows),
        ];
    }

    /** Humor da pesquisa respondida: pelas estrelas; sem estrelas, pela recomendação; depois pelo sim ou não. */
    private static function mood(array $s): string
    {
        if ($s['rating']) {
            $r = (int) round(array_sum($s['rating']) / count($s['rating']));
            return $r >= 4 ? 'positive' : ($r === 3 ? 'neutral' : 'negative');
        }
        if ($s['nps']) {
            $n = array_sum($s['nps']) / count($s['nps']);
            return $n >= 9 ? 'positive' : ($n >= 7 ? 'neutral' : 'negative');
        }
        if ($s['yesno']) {
            return array_sum($s['yesno']) === count($s['yesno']) ? 'positive' : 'negative';
        }
        return 'neutral';
    }

    /** @return array<int, string> id => nome */
    private static function names(string $table, string $field, array $ids, bool $withZero = false): array
    {
        global $DB;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = $withZero ? $ids : array_values(array_filter($ids));
        $out = [];
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['id', $field], 'FROM' => $table, 'WHERE' => ['id' => $ids]]) as $r) {
                $out[(int) $r['id']] = (string) $r[$field];
            }
        }
        return $out;
    }

    private static function url(string $itemtype, int $id): string
    {
        return class_exists($itemtype) && method_exists($itemtype, 'getFormURLWithID') ? (string) $itemtype::getFormURLWithID($id) : '';
    }

    /** Estatísticas por pergunta (média, % positivo, NPS) das pesquisas informadas, para a tabela do painel. */
    public static function questions(array $rows): array
    {
        global $DB;
        $ids = array_column($rows, 'id');
        $stats = [];
        if ($ids) {
            foreach ($DB->request([
                'SELECT'  => ['questions_id', 'score', 'COUNT' => 'id AS n'],
                'FROM'    => Survey::ANSWERS_TABLE,
                'WHERE'   => ['plugin_satisfacao_surveys_id' => $ids, 'NOT' => ['score' => null]],
                'GROUPBY' => ['questions_id', 'score'],
            ]) as $r) {
                $stats[(int) $r['questions_id']][(int) $r['score']] = (int) $r['n'];
            }
        }
        $out = [];
        foreach (Question::all() as $q) {
            $dist = $stats[(int) $q['id']] ?? [];
            $n = array_sum($dist);
            $sum = 0;
            foreach ($dist as $value => $count) {
                $sum += $value * $count;
            }
            $good = match ($q['type']) {
                'rating' => ($dist[4] ?? 0) + ($dist[5] ?? 0),
                'yesno'  => $dist[1] ?? 0,
                'nps'    => ($dist[9] ?? 0) + ($dist[10] ?? 0),
                default  => null,
            };
            $bad = $q['type'] === 'nps' ? array_sum(array_intersect_key($dist, array_flip(range(0, 6)))) : null;
            $out[] = [
                'name'   => (string) $q['name'], 'type' => $q['type'], 'active' => (bool) $q['is_active'], 'n' => $n,
                'avg'    => $n && in_array($q['type'], ['rating', 'nps'], true) ? round($sum / $n, 2) : null,
                'good'   => $good !== null && $n ? (int) round(100 * $good / $n) : null,
                'nps'    => $bad !== null && $n ? (int) round(100 * ($good - $bad) / $n) : null,
            ];
        }
        return $out;
    }
}
