<?php

namespace GlpiPlugin\Cronograma;

use Project;
use ProjectTask;
use ProjectTaskLink;
use ProjectTaskTeam;
use Session;
use User;

/**
 * Aplica um cronograma lido do MS Project às tarefas de um projeto do GLPI.
 */
class Importer
{
    // MSPDI (0=FF 1=FS 2=SF 3=SS) → GLPI/Gantt (0=FS 1=SS 2=FF 3=SF)
    private const LINK_TYPES = [1 => 0, 3 => 1, 0 => 2, 2 => 3];
    private const MINUTES_PER_DAY = 480; // jornada de 8 h, padrão do MS Project

    private array $userIndex = [];

    /**
     * @param array{update_project_dates?: bool, team?: bool, remove_missing?: bool} $opts
     * @return array{created:int, updated:int, removed:int, links:int, team:int, warnings:list<string>}
     */
    public function apply(Project $project, array $data, array $opts): array
    {
        global $DB;

        $projectId = (int) $project->getID();
        $stats = ['created' => 0, 'updated' => 0, 'removed' => 0, 'links' => 0, 'team' => 0, 'warnings' => []];

        // UID do Project → id da tarefa no GLPI (só tarefas que ainda existem)
        $map = [];
        foreach ($DB->request([
            'SELECT' => ['m.ms_uid', 'm.projecttasks_id'],
            'FROM'   => 'glpi_plugin_cronograma_tasks AS m',
            'INNER JOIN' => ['glpi_projecttasks AS t' => ['ON' => ['m' => 'projecttasks_id', 't' => 'id']]],
            'WHERE'  => ['m.projects_id' => $projectId],
        ]) as $row) {
            $map[(int) $row['ms_uid']] = (int) $row['projecttasks_id'];
        }
        $previous = $map;

        $parents = [0 => 0];  // nível → id da última tarefa naquele nível
        $seen = [];
        $task = new ProjectTask();
        foreach ($data['tasks'] as $t) {
            if ($t['level'] === 0) {
                // tarefa-resumo do projeto: vira datas do próprio projeto, se pedido
                if (!empty($opts['update_project_dates']) && $t['start'] && $t['finish']) {
                    $project->update(['id' => $projectId, 'plan_start_date' => $t['start'], 'plan_end_date' => $t['finish']]);
                }
                continue;
            }
            $parent = $parents[$t['level'] - 1] ?? 0;
            $input = [
                'name'               => mb_substr($t['name'], 0, 255),
                'content'            => $t['notes'] !== '' ? nl2br(htmlspecialchars($t['notes'])) : '',
                'comment'            => $t['resources'] ? 'Recursos no MS Project: ' . implode(', ', $t['resources']) : '',
                'projects_id'        => $projectId,
                'projecttasks_id'    => $parent,
                'entities_id'        => $project->fields['entities_id'],
                'is_recursive'       => $project->fields['is_recursive'],
                'plan_start_date'    => $t['start'],
                'plan_end_date'      => $t['finish'],
                'real_start_date'    => $t['actual_start'],
                'real_end_date'      => $t['actual_finish'],
                'planned_duration'   => $t['duration'],
                'percent_done'       => $t['percent'],
                'auto_percent_done'  => 0,
                'is_milestone'       => $t['milestone'] ? 1 : 0,
                '_disablenotif'      => true,
            ];

            if (isset($map[$t['uid']])) {
                $task->update(['id' => $map[$t['uid']]] + $input);
                $stats['updated']++;
                $id = $map[$t['uid']];
            } else {
                $id = (int) $task->add($input);
                if (!$id) {
                    $stats['warnings'][] = "Tarefa não criada: {$t['name']}";
                    continue;
                }
                $DB->insert('glpi_plugin_cronograma_tasks', ['projects_id' => $projectId, 'projecttasks_id' => $id, 'ms_uid' => $t['uid']]);
                $map[$t['uid']] = $id;
                $stats['created']++;
            }
            $seen[$t['uid']] = true;
            $parents[$t['level']] = $id;
            // níveis mais profundos não podem herdar um pai de outro ramo
            foreach (array_keys($parents) as $lvl) {
                if ($lvl > $t['level']) {
                    unset($parents[$lvl]);
                }
            }
        }

        $stats['links'] = $this->syncLinks($data['tasks'], $map);
        if (!empty($opts['team'])) {
            [$stats['team'], $unmatched] = $this->syncTeam($data['tasks'], $map);
            if ($unmatched) {
                $stats['warnings'][] = 'Recursos sem usuário correspondente no GLPI (registrados no comentário da tarefa): ' . implode(', ', $unmatched);
            }
        }

        if (!empty($opts['remove_missing'])) {
            foreach (array_diff_key($previous, $seen) as $uid => $taskId) {
                if ($task->getFromDB($taskId)) {
                    $task->delete(['id' => $taskId]); // vai para a lixeira, dá para restaurar
                    $stats['removed']++;
                }
                $DB->delete('glpi_plugin_cronograma_tasks', ['projects_id' => $projectId, 'ms_uid' => $uid]);
            }
        }

        return $stats;
    }

    /** Recria as dependências entre as tarefas importadas. */
    private function syncLinks(array $tasks, array $map): int
    {
        global $DB;
        $ids = array_values($map);
        if ($ids) {
            $DB->delete(ProjectTaskLink::getTable(), ['projecttasks_id_source' => $ids, 'projecttasks_id_target' => $ids]);
        }
        $uuids = [];
        foreach ($DB->request(['SELECT' => ['id', 'uuid'], 'FROM' => ProjectTask::getTable(), 'WHERE' => ['id' => $ids ?: [0]]]) as $r) {
            $uuids[(int) $r['id']] = $r['uuid'];
        }
        $link = new ProjectTaskLink();
        $count = 0;
        foreach ($tasks as $t) {
            foreach ($t['links'] as $l) {
                $src = $map[$l['uid']] ?? null;
                $dst = $map[$t['uid']] ?? null;
                if (!$src || !$dst || !isset(self::LINK_TYPES[$l['type']])) {
                    continue;
                }
                $lagDays = (int) round($l['lag'] / 10 / self::MINUTES_PER_DAY);
                if ($link->add([
                    'projecttasks_id_source' => $src,
                    'source_uuid'            => $uuids[$src] ?? '',
                    'projecttasks_id_target' => $dst,
                    'target_uuid'            => $uuids[$dst] ?? '',
                    'type'                   => self::LINK_TYPES[$l['type']],
                    'lag'                    => max(0, $lagDays),
                    'lead'                   => max(0, -$lagDays),
                ])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** Recursos do Project que batem com usuários do GLPI entram na equipe da tarefa. */
    private function syncTeam(array $tasks, array $map): array
    {
        $team = new ProjectTaskTeam();
        $added = 0;
        $unmatched = [];
        foreach ($tasks as $t) {
            $taskId = $map[$t['uid']] ?? null;
            if (!$taskId) {
                continue;
            }
            foreach ($t['resources'] as $name) {
                $uid = $this->findUser($name);
                if (!$uid) {
                    $unmatched[$name] = true;
                    continue;
                }
                $crit = ['projecttasks_id' => $taskId, 'itemtype' => User::class, 'items_id' => $uid];
                if (!countElementsInTable(ProjectTaskTeam::getTable(), $crit)) {
                    $team->add($crit) && $added++;
                }
            }
        }
        return [$added, array_keys($unmatched)];
    }

    private function findUser(string $name): ?int
    {
        global $DB;
        if (!$this->userIndex) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => User::getTable(), 'WHERE' => ['is_deleted' => 0, 'is_active' => 1]]) as $u) {
                foreach ([$u['name'], trim($u['firstname'] . ' ' . $u['realname']), trim($u['realname'] . ' ' . $u['firstname'])] as $key) {
                    if (trim((string) $key) !== '') {
                        $this->userIndex[mb_strtolower(trim($key))] = (int) $u['id'];
                    }
                }
            }
        }
        return $this->userIndex[mb_strtolower(trim($name))] ?? null;
    }
}
