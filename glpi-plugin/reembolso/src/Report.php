<?php

namespace GlpiPlugin\Reembolso;

use DbUtils;
use Session;

/**
 * Dados do dashboard e da exportação XLSX (mesmos filtros e mesma visibilidade):
 * despesas pela data da despesa, com a solicitação, técnico, centro de custo e status.
 */
class Report
{
    /** Filtros vindos da URL, normalizados. */
    public static function filters(array $in): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $from = $date($in['from'] ?? null) ?? date('Y-m-01', strtotime('-5 months'));
        $to = $date($in['to'] ?? null) ?? date('Y-m-d');
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $readAll = Session::haveRight(Reembolso::$rightname, Reembolso::READALL);
        return [
            'from'    => $from,
            'to'      => $to,
            'users'   => $readAll ? max(0, (int) ($in['users'] ?? 0)) : (int) Session::getLoginUserID(),
            'centro'  => max(0, (int) ($in['centro'] ?? 0)),
            'tipo'    => max(0, (int) ($in['tipo'] ?? 0)),
            'status'  => isset(Reembolso::STATUSES[(int) ($in['status'] ?? 0)]) ? (int) $in['status'] : 0,
            'readall' => $readAll,
        ];
    }

    /** Despesas do período com os dados da solicitação. */
    public static function rows(array $f): array
    {
        global $DB;
        $where = [
            'r.is_deleted' => 0,
            ['d.date' => ['>=', $f['from']]],
            ['d.date' => ['<=', $f['to']]],
        ] + (new DbUtils())->getEntitiesRestrictCriteria('r', '', '', true);
        if (!$f['readall']) {
            $me = (int) Session::getLoginUserID();
            $where[] = ['OR' => ['r.users_id' => $me, 'r.users_id_recipient' => $me]];
        } elseif ($f['users']) {
            $where['r.users_id'] = $f['users'];
        }
        if ($f['centro']) {
            $where['r.plugin_reembolso_centrocustos_id'] = $f['centro'];
        }
        if ($f['tipo']) {
            $where['d.plugin_reembolso_tipodespesas_id'] = $f['tipo'];
        }
        if ($f['status']) {
            $where['r.status'] = $f['status'];
        }
        $rows = [];
        $users = [];
        foreach ($DB->request([
            'SELECT'    => [
                'd.id', 'd.date', 'd.description', 'd.origin', 'd.destination', 'd.round_trip', 'd.km_start', 'd.km_end', 'd.distance', 'd.km_rate', 'd.amount',
                'r.id AS reembolso', 'r.name AS code', 'r.status', 'r.users_id', 'r.tickets_id', 'r.address', 'r.date_payment', 'r.payment_reference',
                't.name AS tipo', 't.is_mileage', 't.max_amount',
                'c.name AS centro', 'c.code AS centro_code',
                'l.completename AS location',
                'k.name AS ticket',
            ],
            'FROM'      => Despesa::getTable() . ' AS d',
            'INNER JOIN' => [Reembolso::getTable() . ' AS r' => ['ON' => ['r' => 'id', 'd' => 'plugin_reembolso_reembolsos_id']]],
            'LEFT JOIN' => [
                Tipodespesa::getTable() . ' AS t' => ['ON' => ['t' => 'id', 'd' => 'plugin_reembolso_tipodespesas_id']],
                Centrocusto::getTable() . ' AS c' => ['ON' => ['c' => 'id', 'r' => 'plugin_reembolso_centrocustos_id']],
                'glpi_locations AS l'             => ['ON' => ['l' => 'id', 'r' => 'locations_id']],
                'glpi_tickets AS k'               => ['ON' => ['k' => 'id', 'r' => 'tickets_id']],
            ],
            'WHERE'     => $where,
            'ORDER'     => ['d.date', 'd.id'],
        ]) as $r) {
            $uid = (int) $r['users_id'];
            $users[$uid] ??= (string) getUserName($uid);
            $r['tecnico'] = $users[$uid];
            $r['centro_label'] = trim(($r['centro_code'] ? $r['centro_code'] . ' - ' : '') . (string) $r['centro']);
            $r['amount'] = (float) $r['amount'];
            $r['distance'] = (float) $r['distance'];
            $r['status'] = (int) $r['status'];
            $r['over'] = (float) $r['max_amount'] > 0 && $r['amount'] > (float) $r['max_amount'];
            $rows[] = $r;
        }
        return $rows;
    }

    /** Indicadores e agrupamentos. Recusados ficam fora dos totais (aparecem à parte). */
    public static function summary(array $rows): array
    {
        $k = ['total' => 0.0, 'draft' => 0.0, 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'refused' => 0.0, 'km' => 0.0, 'over' => 0, 'expenses' => 0];
        $requests = $byType = $byUser = $byCentro = $byMonth = $techs = [];
        foreach ($rows as $r) {
            $v = $r['amount'];
            if ($r['status'] === Reembolso::REFUSED) {
                $k['refused'] += $v;
                continue;
            }
            $k['total'] += $v;
            $k['expenses']++;
            $k[[Reembolso::DRAFT => 'draft', Reembolso::SUBMITTED => 'pending', Reembolso::APPROVED => 'approved', Reembolso::PAID => 'paid'][$r['status']]] += $v;
            if ($r['is_mileage']) {
                $k['km'] += $r['distance'];
            }
            $k['over'] += $r['over'] ? 1 : 0;
            $requests[$r['reembolso']] = true;
            $techs[$r['users_id']] = true;
            $byType[$r['tipo'] ?: '—'] = ($byType[$r['tipo'] ?: '—'] ?? 0) + $v;
            $byUser[$r['tecnico']] = ($byUser[$r['tecnico']] ?? 0) + $v;
            $byCentro[$r['centro_label'] ?: 'Sem centro de custo'] = ($byCentro[$r['centro_label'] ?: 'Sem centro de custo'] ?? 0) + $v;
            $m = substr((string) $r['date'], 0, 7);
            $byMonth[$m][$r['status']] = ($byMonth[$m][$r['status']] ?? 0) + $v;
        }
        $k['requests'] = count($requests);
        $k['techs'] = count($techs);
        $k['average'] = $k['requests'] ? $k['total'] / $k['requests'] : 0;
        arsort($byType);
        arsort($byUser);
        arsort($byCentro);
        ksort($byMonth);
        return ['kpi' => $k, 'type' => $byType, 'user' => $byUser, 'centro' => $byCentro, 'month' => $byMonth];
    }

    /** Meses entre as datas (para o gráfico não pular meses vazios). */
    public static function months(string $from, string $to): array
    {
        $out = [];
        for ($d = strtotime(substr($from, 0, 7) . '-01'); $d <= strtotime($to); $d = strtotime('+1 month', $d)) {
            $out[] = date('Y-m', $d);
        }
        return $out;
    }
}
