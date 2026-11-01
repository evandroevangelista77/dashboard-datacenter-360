<?php

namespace GlpiPlugin\Satisfacao;

/**
 * Números do eNPS (clima): promotores (9 e 10), neutros (7 e 8) e detratores (0 a 6); eNPS = % de
 * promotores menos % de detratores. As respostas são anônimas: grupos com menos de
 * Survey::ENPS_MIN_GROUP respostas não aparecem, para não identificar ninguém.
 */
class Enps
{
    public const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /** Classe da nota: promotor, neutro ou detrator. */
    public static function kind(int $score): string
    {
        return $score >= 9 ? 'promoter' : ($score >= 7 ? 'passive' : 'detractor');
    }

    /** Faixa do eNPS: [texto, cor]. */
    public static function zone(?int $score): array
    {
        return match (true) {
            $score === null => ['sem respostas', '#94a3b8'],
            $score >= 75    => ['zona de excelência', '#22c55e'],
            $score >= 50    => ['zona de qualidade', '#84cc16'],
            $score >= 0     => ['zona de aperfeiçoamento', '#f59e0b'],
            default         => ['zona crítica', '#ef4444'],
        };
    }

    /**
     * Respostas das campanhas enviadas no período (ou de uma campanha só).
     *
     * @return array<string, mixed>
     */
    public static function data(string $from, string $to, int $campaign = 0): array
    {
        global $DB;
        $campaigns = [];
        foreach ($DB->request(['FROM' => Campaign::getTable(), 'WHERE' => $campaign ? ['id' => $campaign] : [
            'AND' => [['date_sent' => ['>=', "$from 00:00:00"]], ['date_sent' => ['<=', "$to 23:59:59"]]], 'status' => [Campaign::SENT, Campaign::CLOSED],
        ], 'ORDER' => ['date_sent']]) as $c) {
            $campaigns[(int) $c['id']] = $c + ['sent' => 0, 'answered' => 0, 'scores' => []];
        }

        $sent = 0;
        $answered = 0;
        $answers = [];
        if ($campaigns) {
            foreach ($DB->request(['SELECT' => ['items_id', 'status', 'COUNT' => 'id AS n'], 'FROM' => Survey::getTable(),
                'WHERE' => ['itemtype' => Survey::ENPS, 'items_id' => array_keys($campaigns)], 'GROUPBY' => ['items_id', 'status']]) as $r) {
                $campaigns[(int) $r['items_id']]['sent'] += (int) $r['n'];
                $sent += (int) $r['n'];
                if ((int) $r['status'] === Survey::ANSWERED) {
                    $campaigns[(int) $r['items_id']]['answered'] += (int) $r['n'];
                    $answered += (int) $r['n'];
                }
            }
            foreach ($DB->request(['FROM' => Survey::ENPS_TABLE, 'WHERE' => ['campaigns_id' => array_keys($campaigns)], 'ORDER' => ['date_answered DESC', 'id DESC']]) as $a) {
                $answers[] = $a;
                $campaigns[(int) $a['campaigns_id']]['scores'][] = (int) $a['score'];
            }
        }

        $dist = array_fill(0, 11, 0);
        $groups = [];
        foreach ($answers as $a) {
            $dist[(int) $a['score']]++;
            $label = (string) $a['group_name'] !== '' ? (string) $a['group_name'] : 'Sem grupo';
            $groups[$label][] = (int) $a['score'];
        }
        $summary = self::summary(array_map(fn ($a) => (int) $a['score'], $answers));

        $byCampaign = [];
        foreach ($campaigns as $c) {
            $sum = self::summary($c['scores']);
            $byCampaign[] = [
                'id' => (int) $c['id'], 'name' => (string) $c['name'], 'status' => (int) $c['status'], 'date' => substr((string) $c['date_sent'], 0, 10),
                'sent' => $c['sent'], 'answered' => $c['answered'], 'rate' => $c['sent'] ? round(100 * $c['answered'] / $c['sent'], 1) : 0,
            ] + $sum;
        }

        $byGroup = [];
        $hidden = 0;
        foreach ($groups as $label => $scores) {
            if (count($scores) < Survey::ENPS_MIN_GROUP) {
                $hidden++;
                continue;
            }
            $byGroup[] = ['label' => (string) $label] + self::summary($scores);
        }
        usort($byGroup, fn ($x, $y) => $y['n'] <=> $x['n']);

        $comments = [];
        foreach ($answers as $a) {
            if ($a['comment'] !== null && $a['comment'] !== '') {
                $comments[] = ['c' => (string) $a['comment'], 's' => (int) $a['score'], 'k' => self::kind((int) $a['score']), 'd' => (string) $a['date_answered'],
                    'campaign' => (string) ($campaigns[(int) $a['campaigns_id']]['name'] ?? '')];
            }
        }

        return $summary + [
            'sent' => $sent, 'answered' => $answered, 'rate' => $sent ? round(100 * $answered / $sent, 1) : 0,
            'dist' => $dist, 'campaigns' => $byCampaign, 'groups' => $byGroup, 'hiddenGroups' => $hidden, 'comments' => $comments,
        ];
    }

    /** n, promotores, neutros, detratores (quantidades e %) e o eNPS de uma lista de notas. */
    public static function summary(array $scores): array
    {
        $n = count($scores);
        $count = ['promoter' => 0, 'passive' => 0, 'detractor' => 0];
        foreach ($scores as $s) {
            $count[self::kind((int) $s)]++;
        }
        $pct = fn (int $x) => $n ? round(100 * $x / $n, 1) : 0;
        return [
            'n'         => $n,
            'promoter'  => $count['promoter'], 'passive' => $count['passive'], 'detractor' => $count['detractor'],
            'pPromoter' => $pct($count['promoter']), 'pPassive' => $pct($count['passive']), 'pDetractor' => $pct($count['detractor']),
            'enps'      => $n ? (int) round(100 * ($count['promoter'] - $count['detractor']) / $n) : null,
            'avg'       => $n ? round(array_sum($scores) / $n, 1) : null,
        ];
    }
}
