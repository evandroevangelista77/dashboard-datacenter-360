<?php

namespace GlpiPlugin\Pmo;

use Project;
use ProjectState;

/**
 * Números dos painéis. Tudo sai das tarefas nativas + cartões do PMO; o histórico
 * de burndown/burnup vem da data de conclusão de cada cartão, e o do fluxo
 * cumulativo e da curva S real vem das fotografias diárias (Snapshot).
 */
class Metrics
{
    /** Tarefas-folha (as que viram cartão). */
    public static function leaves(array $tasks): array
    {
        return array_filter($tasks, fn ($t) => $t['children'] === 0);
    }

    /** Peso da tarefa na curva S: duração planejada, ou o intervalo planejado. */
    private static function weight(array $t): float
    {
        if ((int) $t['planned_duration'] > 0) {
            return (float) $t['planned_duration'];
        }
        if ($t['plan_start_date'] && $t['plan_end_date']) {
            return max(3600.0, strtotime($t['plan_end_date']) - strtotime($t['plan_start_date']));
        }
        return 0.0;
    }

    private static function dated(array $leaves): array
    {
        return array_filter($leaves, fn ($t) => $t['plan_start_date'] && $t['plan_end_date'] && self::weight($t) > 0);
    }

    /** % planejado em um instante: quanto do trabalho datado já deveria estar feito. */
    public static function plannedAt(array $dated, int $ts): float
    {
        $sum = $total = 0.0;
        foreach ($dated as $t) {
            $w = self::weight($t);
            $s = strtotime($t['plan_start_date']);
            $e = max($s + 1, strtotime($t['plan_end_date']));
            $sum += $w * max(0.0, min(1.0, ($ts - $s) / ($e - $s)));
            $total += $w;
        }
        return $total > 0 ? round(100 * $sum / $total, 1) : 0.0;
    }

    /**
     * % real e % planejado de hoje.
     * Cronograma datado (waterfall): média do % ponderada pela duração.
     * Sem datas (ágil): pontos (ou horas, ou quantidade) concluídos / total.
     */
    public static function progress(array $project, array $tasks, array $kinds, string $estimation): array
    {
        $leaves = self::leaves($tasks);
        $dated = self::dated($leaves);
        if ($dated) {
            $sum = $total = 0.0;
            foreach ($dated as $t) {
                $w = self::weight($t);
                $sum += $w * $t['percent_done'] / 100;
                $total += $w;
            }
            return ['actual' => round(100 * $sum / $total, 1), 'planned' => self::plannedAt($dated, time())];
        }
        [$total, $done] = self::sizes($leaves, $kinds, $estimation);
        $actual = $total > 0 ? round(100 * $done / $total, 1) : (float) ($project['percent_done'] ?? 0);
        $planned = null;
        if (!empty($project['plan_start_date']) && !empty($project['plan_end_date'])) {
            $s = strtotime($project['plan_start_date']);
            $e = max($s + 1, strtotime($project['plan_end_date']));
            $planned = round(100 * max(0, min(1, (time() - $s) / ($e - $s))), 1);
        }
        return ['actual' => $actual, 'planned' => $planned];
    }

    /** [total, concluído, unidade]: pontos/horas; se ninguém estimou, quantidade de cartões. */
    public static function sizes(array $leaves, array $kinds, string $estimation): array
    {
        $total = $done = 0.0;
        foreach ($leaves as $t) {
            $size = Board::size($t, $estimation);
            $total += $size;
            if (self::isDone($t, $kinds)) {
                $done += $size;
            }
        }
        if ($total > 0) {
            return [$total, $done, $estimation === 'hours' ? 'horas' : 'pontos'];
        }
        $done = count(array_filter($leaves, fn ($t) => self::isDone($t, $kinds)));
        return [(float) count($leaves), (float) $done, 'tarefas'];
    }

    public static function isDone(array $t, array $kinds): bool
    {
        return isset($kinds[(int) ($t['columns_id'] ?? 0)])
            ? $kinds[(int) $t['columns_id']] === 'done'
            : $t['percent_done'] >= 100;
    }

    /** Saúde do projeto (semáforo do portfólio). */
    public static function health(array $project, ?float $planned, float $actual, int $overdue): array
    {
        $finished = $project['projectstates_id'] && self::stateFinished((int) $project['projectstates_id']);
        if ($finished || $actual >= 100) {
            return ['code' => 'done', 'label' => 'Concluído'];
        }
        $late = !empty($project['plan_end_date']) && strtotime($project['plan_end_date']) < time();
        $gap = $planned === null ? 0 : $planned - $actual;
        if ($late || $gap > 15) {
            return ['code' => 'red', 'label' => $late ? 'Prazo vencido' : sprintf('Atrasado (%.0f p.p.)', $gap)];
        }
        if ($gap > 5 || $overdue > 0) {
            return ['code' => 'yellow', 'label' => $gap > 5 ? sprintf('Atenção (%.0f p.p.)', $gap) : "$overdue tarefa(s) atrasada(s)"];
        }
        return ['code' => 'green', 'label' => 'No prazo'];
    }

    private static function stateFinished(int $stateId): bool
    {
        static $cache = [];
        if (!isset($cache[$stateId])) {
            $s = new ProjectState();
            $cache[$stateId] = $s->getFromDB($stateId) && (int) $s->fields['is_finished'] === 1;
        }
        return $cache[$stateId];
    }

    /** Tudo o que a página de painéis do projeto precisa. */
    public static function dashboard(Project $project, ?int $sprintId = null): array
    {
        global $DB;
        $projectId = (int) $project->getID();
        $config = ProjectConfig::get($projectId) ?? ['methodology' => 'kanban', 'estimation' => 'points'];
        $estimation = $config['estimation'];
        $columns = ProjectConfig::columns($projectId);
        $kinds = array_column($columns, 'kind', 'id');
        $tasks = Board::sync($projectId);
        Snapshot::take($projectId, $tasks);
        $leaves = self::leaves($tasks);
        $now = time();

        $progress = self::progress($project->fields, $tasks, $kinds, $estimation);
        [$total, $done, $unit] = self::sizes($leaves, $kinds, $estimation);

        $overdue = $soon = [];
        $byColumn = array_fill_keys(array_column($columns, 'id'), 0);
        $byKind = ['todo' => 0, 'doing' => 0, 'done' => 0];
        foreach ($leaves as $t) {
            $col = (int) $t['columns_id'];
            if (isset($byColumn[$col])) {
                $byColumn[$col]++;
            }
            $byKind[$kinds[$col] ?? 'todo']++;
            if (self::isDone($t, $kinds) || !$t['plan_end_date']) {
                continue;
            }
            $end = strtotime($t['plan_end_date']);
            $row = [
                'id' => $t['id'], 'name' => (string) $t['name'], 'end' => substr($t['plan_end_date'], 0, 10),
                'percent' => $t['percent_done'], 'users' => array_map(fn ($u) => Board::person($u)['name'], $t['users']),
                'days' => (int) floor(($now - $end) / DAY_TIMESTAMP),
            ];
            if ($end < $now) {
                $overdue[] = $row;
            } elseif ($end < $now + 7 * DAY_TIMESTAMP) {
                $soon[] = $row;
            }
        }
        usort($overdue, fn ($a, $b) => $b['days'] <=> $a['days']);
        usort($soon, fn ($a, $b) => strcmp($a['end'], $b['end']));

        $sprints = Sprint::all($projectId);
        $sprint = null;
        foreach ($sprints as $s) {
            if ($sprintId && $s['id'] === $sprintId) {
                $sprint = $s;
            }
        }
        $closed = array_values(array_filter($sprints, fn ($s) => $s['state'] === 'closed'));
        $sprint ??= Sprint::active($projectId) ?? ($closed ? end($closed) : null);

        $openRisks = (int) $DB->request([
            'COUNT' => 'c', 'FROM' => 'glpi_plugin_pmo_risks',
            'WHERE' => ['projects_id' => $projectId, 'status' => ['open', 'mitigating']],
        ])->current()['c'];

        $planEnd = $project->fields['plan_end_date'] ?: null;
        $health = self::health($project->fields, $progress['planned'], $progress['actual'], count($overdue));

        return [
            'config'   => $config,
            'unit'     => $unit,
            'kpi'      => [
                'actual'     => $progress['actual'],
                'planned'    => $progress['planned'],
                'spi'        => $progress['planned'] ? round($progress['actual'] / max(0.1, $progress['planned']), 2) : null,
                'total'      => count($leaves),
                'done'       => $byKind['done'],
                'doing'      => $byKind['doing'],
                'todo'       => $byKind['todo'],
                'overdue'    => count($overdue),
                'soon'       => count($soon),
                'size_total' => $total,
                'size_done'  => $done,
                'risks'      => $openRisks,
                'plan_end'   => $planEnd ? substr($planEnd, 0, 10) : null,
                'days_left'  => $planEnd ? (int) ceil((strtotime($planEnd) - $now) / DAY_TIMESTAMP) : null,
                'health'     => $health,
            ],
            'columns'  => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'count' => $byColumn[$c['id']] ?? 0], $columns),
            'overdue'  => array_slice($overdue, 0, 15),
            'soon'     => array_slice($soon, 0, 15),
            'sprints'  => array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'state' => $s['state']], $sprints),
            'sprint'   => $sprint ? ['id' => $sprint['id'], 'name' => $sprint['name'], 'state' => $sprint['state'], 'goal' => $sprint['goal']] : null,
            'burndown' => $sprint ? self::burndown($sprint, $tasks, $kinds, $estimation) : null,
            'burnup'   => self::burnup($leaves, $kinds, $estimation),
            'scurve'   => self::scurve($projectId, $leaves),
            'cfd'      => self::cfd($projectId, $columns),
            'workload' => self::workload($leaves, $kinds),
            'velocity' => self::velocity($sprints, $tasks, $kinds, $estimation),
        ];
    }

    /** Burndown da sprint: restante por dia × linha ideal. */
    public static function burndown(array $sprint, array $tasks, array $kinds, string $estimation): array
    {
        $ids = $sprint['state'] === 'closed' && $sprint['scope']
            ? array_map('intval', $sprint['scope'])
            : array_keys(array_filter($tasks, fn ($t) => (int) $t['sprints_id'] === $sprint['id'] && $t['children'] === 0));
        $items = array_values(array_intersect_key($tasks, array_flip($ids)));
        $useCount = array_sum(array_map(fn ($t) => Board::size($t, $estimation), $items)) <= 0;
        $size = fn ($t) => $useCount ? 1.0 : Board::size($t, $estimation);
        $total = array_sum(array_map($size, $items));

        $start = strtotime($sprint['start_date'] ?: 'today');
        $end = max($start, strtotime($sprint['end_date'] ?: 'today'));
        $days = (int) round(($end - $start) / DAY_TIMESTAMP);
        $labels = $ideal = $remaining = [];
        $today = strtotime('today');
        for ($i = 0; $i <= $days; $i++) {
            $d = strtotime("+$i day", $start);
            $labels[] = date('Y-m-d', $d);
            $ideal[] = round($total * (1 - ($days ? $i / $days : 1)), 1);
            if ($d > $today) {
                $remaining[] = null;
                continue;
            }
            $eod = $d + DAY_TIMESTAMP - 1;
            $left = 0.0;
            foreach ($items as $t) {
                if (!$t['date_done'] || strtotime($t['date_done']) > $eod) {
                    $left += $size($t);
                }
            }
            $remaining[] = round($left, 1);
        }
        return [
            'labels' => $labels, 'ideal' => $ideal, 'remaining' => $remaining, 'total' => $total,
            'unit' => $useCount ? 'tarefas' : ($estimation === 'hours' ? 'horas' : 'pontos'),
        ];
    }

    /** Burnup do projeto: escopo × concluído ao longo do tempo, com previsão de término pelo ritmo. */
    public static function burnup(array $leaves, array $kinds, string $estimation): array
    {
        if (!$leaves) {
            return ['labels' => [], 'scope' => [], 'done' => [], 'forecast' => null, 'unit' => 'tarefas'];
        }
        $useCount = array_sum(array_map(fn ($t) => Board::size($t, $estimation), $leaves)) <= 0;
        $size = fn ($t) => $useCount ? 1.0 : Board::size($t, $estimation);
        $created = fn ($t) => strtotime($t['date_creation'] ?: 'today');
        $doneAt = fn ($t) => $t['date_done'] ? strtotime($t['date_done']) : ($t['percent_done'] >= 100 && $t['real_end_date'] ? strtotime($t['real_end_date']) : null);

        $first = min(array_map($created, $leaves));
        foreach ($leaves as $t) {
            if ($d = $doneAt($t)) {
                $first = min($first, $d);
            }
        }
        $first = strtotime('today', $first);
        $today = strtotime('today');
        $span = max(1, (int) round(($today - $first) / DAY_TIMESTAMP));
        $step = $span > 120 ? 7 : 1;
        $labels = $scope = $done = [];
        for ($d = $first; $d <= $today + ($step > 1 ? $step - 1 : 0); $d += $step * DAY_TIMESTAMP) {
            $eod = min($d, $today) + DAY_TIMESTAMP - 1;
            $s = $c = 0.0;
            foreach ($leaves as $t) {
                if ($created($t) <= $eod) {
                    $s += $size($t);
                }
                if (($x = $doneAt($t)) && $x <= $eod) {
                    $c += $size($t);
                }
            }
            $labels[] = date('Y-m-d', min($d, $today));
            $scope[] = round($s, 1);
            $done[] = round($c, 1);
        }
        // ritmo dos últimos 28 dias (ou do período todo, se for menor)
        $window = min(28, $span);
        $recent = 0.0;
        foreach ($leaves as $t) {
            if (($x = $doneAt($t)) && $x > $today - $window * DAY_TIMESTAMP) {
                $recent += $size($t);
            }
        }
        $left = end($scope) - end($done);
        $forecast = null;
        if ($left <= 0) {
            $forecast = 'concluído';
        } elseif ($recent > 0) {
            $forecast = date('Y-m-d', $today + (int) ceil($left / ($recent / $window)) * DAY_TIMESTAMP);
        }
        return [
            'labels' => $labels, 'scope' => $scope, 'done' => $done, 'forecast' => $forecast,
            'rate' => round($recent / max(1, $window) * 7, 1), 'unit' => $useCount ? 'tarefas' : ($estimation === 'hours' ? 'horas' : 'pontos'),
        ];
    }

    /** Curva S: % planejado (das datas do cronograma) × % real (fotografias diárias). */
    public static function scurve(int $projectId, array $leaves): ?array
    {
        global $DB;
        $dated = self::dated($leaves);
        if (!$dated) {
            return null;
        }
        $start = strtotime('today', min(array_map(fn ($t) => strtotime($t['plan_start_date']), $dated)));
        $end = strtotime('today', max(array_map(fn ($t) => strtotime($t['plan_end_date']), $dated)));
        $end = max($end, strtotime('today'));
        $days = max(1, (int) round(($end - $start) / DAY_TIMESTAMP));
        $step = max(1, (int) ceil($days / 90));

        $real = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_pmo_snapshots', 'WHERE' => ['projects_id' => $projectId], 'ORDER' => 'day']) as $s) {
            $real[$s['day']] = (float) $s['actual_percent'];
        }
        $labels = $planned = $actual = [];
        $today = strtotime('today');
        $last = null;
        for ($i = 0; $i <= $days; $i += $step) {
            $d = $start + $i * DAY_TIMESTAMP;
            $key = date('Y-m-d', $d);
            $labels[] = $key;
            $planned[] = self::plannedAt($dated, $d + 18 * 3600);
            // dias sem fotografia repetem o último valor conhecido
            foreach ($real as $day => $v) {
                if ($day <= $key) {
                    $last = $v;
                }
            }
            $actual[] = $d <= $today ? $last : null;
        }
        return ['labels' => $labels, 'planned' => $planned, 'actual' => $actual, 'since' => $real ? array_key_first($real) : null];
    }

    /** Fluxo cumulativo: cartões por coluna em cada dia fotografado. */
    public static function cfd(int $projectId, array $columns): array
    {
        global $DB;
        $labels = [];
        $series = array_fill_keys(array_column($columns, 'id'), []);
        foreach ($DB->request(['FROM' => 'glpi_plugin_pmo_snapshots', 'WHERE' => ['projects_id' => $projectId, 'day' => ['>=', date('Y-m-d', strtotime('-90 days'))]], 'ORDER' => 'day']) as $s) {
            $labels[] = $s['day'];
            $counts = json_decode((string) $s['columns'], true) ?: [];
            foreach ($series as $id => $_) {
                $series[$id][] = (int) ($counts[$id] ?? 0);
            }
        }
        $out = [];
        foreach (array_reverse($columns) as $c) {
            $out[] = ['name' => $c['name'], 'color' => $c['color'], 'data' => $series[$c['id']]];
        }
        return ['labels' => $labels, 'series' => $out];
    }

    /**
     * Carga da equipe por semana: horas planejadas das tarefas abertas, divididas
     * igualmente entre os responsáveis e pelos dias úteis do período planejado.
     * Sem duração planejada, conta 8 h por dia útil.
     */
    public static function workload(array $leaves, array $kinds, ?int $weeksBefore = 2, int $weeksAfter = 9): array
    {
        $monday = strtotime('monday this week');
        $weeks = [];
        for ($w = -$weeksBefore; $w <= $weeksAfter; $w++) {
            $weeks[] = $monday + $w * 7 * DAY_TIMESTAMP;
        }
        $first = $weeks[0];
        $last = end($weeks) + 7 * DAY_TIMESTAMP;
        $load = [];
        foreach ($leaves as $t) {
            if (!$t['users'] || !$t['plan_start_date'] || !$t['plan_end_date'] || self::isDone($t, $kinds)) {
                continue;
            }
            $s = strtotime('today', strtotime($t['plan_start_date']));
            $e = strtotime('today', strtotime($t['plan_end_date']));
            $workdays = [];
            for ($d = $s; $d <= $e; $d += DAY_TIMESTAMP) {
                if ((int) date('N', $d) <= 5) {
                    $workdays[] = $d;
                }
            }
            if (!$workdays) {
                $workdays = [$s];
            }
            $hours = (int) $t['planned_duration'] > 0 ? $t['planned_duration'] / 3600 : count($workdays) * 8;
            // tarefa parcialmente feita: só o que falta
            $hours *= (100 - $t['percent_done']) / 100;
            $perDay = $hours / count($workdays) / count($t['users']);
            foreach ($workdays as $d) {
                if ($d < $first || $d >= $last) {
                    continue;
                }
                $wk = (int) floor(($d - $first) / (7 * DAY_TIMESTAMP));
                foreach ($t['users'] as $u) {
                    $load[$u][$wk] = ($load[$u][$wk] ?? 0) + $perDay;
                }
            }
        }
        $users = [];
        foreach ($load as $u => $byWeek) {
            $row = [];
            foreach (array_keys($weeks) as $i) {
                $row[] = round($byWeek[$i] ?? 0, 1);
            }
            $users[] = ['name' => Board::person($u)['name'], 'hours' => $row];
        }
        usort($users, fn ($a, $b) => array_sum($b['hours']) <=> array_sum($a['hours']));
        return [
            'weeks'    => array_map(fn ($w) => date('d/m', $w), $weeks),
            'current'  => $weeksBefore,
            'capacity' => 40,
            'users'    => $users,
        ];
    }

    /** Velocidade: comprometido × entregue por sprint. */
    public static function velocity(array $sprints, array $tasks, array $kinds, string $estimation): array
    {
        $rows = [];
        foreach ($sprints as $s) {
            if ($s['state'] === 'future') {
                continue;
            }
            if ($s['state'] === 'active') {
                $total = $done = 0.0;
                foreach ($tasks as $t) {
                    if ((int) $t['sprints_id'] === $s['id'] && $t['children'] === 0) {
                        $total += Board::size($t, $estimation);
                        $done += self::isDone($t, $kinds) ? Board::size($t, $estimation) : 0;
                    }
                }
                $rows[] = ['name' => $s['name'] . ' (atual)', 'committed' => max($s['committed'], $total), 'completed' => $done];
            } else {
                $rows[] = ['name' => $s['name'], 'committed' => $s['committed'], 'completed' => $s['completed']];
            }
        }
        $closed = array_values(array_filter($sprints, fn ($s) => $s['state'] === 'closed'));
        $lastThree = array_slice($closed, -3);
        return [
            'sprints' => $rows,
            'average' => $lastThree ? round(array_sum(array_column($lastThree, 'completed')) / count($lastThree), 1) : null,
        ];
    }
}
