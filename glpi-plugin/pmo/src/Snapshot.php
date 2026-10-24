<?php

namespace GlpiPlugin\Pmo;

use CommonGLPI;
use CronTask;
use Project;

/**
 * Fotografia diária de cada projeto do PMO (uma linha por dia, regravada a cada
 * mudança no dia): cartões por coluna, tamanho total/concluído e % planejado/real.
 * Alimenta o fluxo cumulativo e a curva S real.
 */
class Snapshot extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'PMO';
    }

    public static function take(int $projectId, ?array $tasks = null): void
    {
        global $DB;
        $config = ProjectConfig::get($projectId);
        $project = new Project();
        if (!$config || !$project->getFromDB($projectId)) {
            return;
        }
        $columns = ProjectConfig::columns($projectId);
        $kinds = array_column($columns, 'kind', 'id');
        $tasks ??= Board::tasks($projectId);
        $leaves = Metrics::leaves($tasks);
        $counts = array_fill_keys(array_column($columns, 'id'), 0);
        foreach ($leaves as $t) {
            if (isset($counts[(int) $t['columns_id']])) {
                $counts[(int) $t['columns_id']]++;
            }
        }
        [$total, $done, $unit] = Metrics::sizes($leaves, $kinds, $config['estimation']);
        $progress = Metrics::progress($project->fields, $tasks, $kinds, $config['estimation']);
        $doneItems = count(array_filter($leaves, fn ($t) => Metrics::isDone($t, $kinds)));
        $DB->updateOrInsert('glpi_plugin_pmo_snapshots', [
            'total_items'     => count($leaves),
            'done_items'      => $doneItems,
            'total_points'    => $unit === 'tarefas' ? 0 : $total,
            'done_points'     => $unit === 'tarefas' ? 0 : $done,
            'planned_percent' => $progress['planned'] ?? 0,
            'actual_percent'  => $progress['actual'],
            'columns'         => json_encode($counts),
        ], ['projects_id' => $projectId, 'day' => date('Y-m-d')]);
    }

    public static function cronInfo($name)
    {
        return ['description' => 'PMO: fotografia diária dos projetos (fluxo cumulativo e curva S)'];
    }

    public static function cronPmosnapshot(CronTask $task): int
    {
        global $DB;
        $n = 0;
        foreach ($DB->request(['SELECT' => ['p.projects_id'], 'FROM' => 'glpi_plugin_pmo_projects AS p',
            'INNER JOIN' => ['glpi_projects AS g' => ['ON' => ['p' => 'projects_id', 'g' => 'id']]],
            'WHERE' => ['g.is_deleted' => 0]]) as $r) {
            Board::sync((int) $r['projects_id']);
            self::take((int) $r['projects_id']);
            $n++;
        }
        $task->addVolume($n);
        return 1;
    }
}
