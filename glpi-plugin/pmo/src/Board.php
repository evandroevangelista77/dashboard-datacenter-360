<?php

namespace GlpiPlugin\Pmo;

use Dropdown;
use Project;
use ProjectTask;
use ProjectTaskTeam;
use ProjectTaskType;
use RuntimeException;
use User;

/**
 * Cartões do quadro: cada tarefa-folha do projeto (sem subtarefas) é um cartão.
 * Tarefas com subtarefas são agrupadores (épicos / fases): aparecem como rótulo do
 * cartão e como barra-resumo no Gantt, mas não no quadro.
 */
class Board
{
    /** Tarefas do projeto (fora da lixeira) com os dados do PMO, indexadas pelo id. */
    public static function tasks(int $projectId): array
    {
        global $DB;
        $tasks = [];
        $it = $DB->request([
            'SELECT'    => ['t.*', 'i.columns_id', 'i.sprints_id', 'i.rank AS pmo_rank', 'i.story_points', 'i.priority', 'i.date_done', 'i.id AS items_id'],
            'FROM'      => 'glpi_projecttasks AS t',
            'LEFT JOIN' => ['glpi_plugin_pmo_items AS i' => ['ON' => ['i' => 'projecttasks_id', 't' => 'id']]],
            'WHERE'     => ['t.projects_id' => $projectId, 't.is_deleted' => 0, 't.is_template' => 0],
            'ORDER'     => ['t.plan_start_date', 't.id'],
        ]);
        foreach ($it as $r) {
            $r['id'] = (int) $r['id'];
            $r['projecttasks_id'] = (int) $r['projecttasks_id'];
            $r['percent_done'] = (int) $r['percent_done'];
            $r['children'] = 0;
            $r['users'] = [];
            $tasks[$r['id']] = $r;
        }
        foreach ($tasks as $t) {
            if ($t['projecttasks_id'] && isset($tasks[$t['projecttasks_id']])) {
                $tasks[$t['projecttasks_id']]['children']++;
            }
        }
        if ($tasks) {
            foreach ($DB->request([
                'FROM'  => 'glpi_projecttaskteams',
                'WHERE' => ['projecttasks_id' => array_keys($tasks), 'itemtype' => User::class],
            ]) as $m) {
                $tasks[(int) $m['projecttasks_id']]['users'][] = (int) $m['items_id'];
            }
        }
        return $tasks;
    }

    /**
     * Garante um cartão para cada tarefa-folha e acerta a coluna pelo % do GLPI
     * (tarefa a 100% editada no formulário nativo ou reimportada do MS Project vai
     * para "Concluído"; tarefa reaberta sai de lá).
     */
    public static function sync(int $projectId): array
    {
        global $DB;
        $columns = ProjectConfig::columns($projectId);
        if (!$columns) {
            return self::tasks($projectId);
        }
        $byId = array_column($columns, null, 'id');
        $first = ProjectConfig::firstByKind($columns);
        $tasks = self::tasks($projectId);
        $maxRank = (int) ($DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_pmo_items', 'WHERE' => ['projects_id' => $projectId]])->current()['m'] ?? 0);
        $now = $_SESSION['glpi_currenttime'];

        foreach ($tasks as $id => $t) {
            if ($t['children'] > 0) {
                continue;
            }
            $pct = $t['percent_done'];
            if ($t['items_id'] === null) {
                $kind = $pct >= 100 ? 'done' : ($pct > 0 ? 'doing' : 'todo');
                $maxRank += 10;
                $item = [
                    'projecttasks_id' => $id,
                    'projects_id'     => $projectId,
                    'columns_id'      => $first[$kind],
                    'rank'            => $maxRank,
                    'date_done'       => $kind === 'done' ? ($t['real_end_date'] ?: ($t['date_mod'] ?: $now)) : null,
                    'date_creation'   => $t['date_creation'] ?: $now,
                ];
                $DB->insert('glpi_plugin_pmo_items', $item);
                $tasks[$id] = array_merge($t, [
                    'items_id' => $DB->insertId(), 'columns_id' => $item['columns_id'], 'sprints_id' => 0, 'pmo_rank' => $maxRank,
                    'story_points' => 0, 'priority' => 3, 'date_done' => $item['date_done'],
                ]);
                continue;
            }
            $col = $byId[(int) $t['columns_id']] ?? null;
            $update = [];
            if ($col === null) {
                $update['columns_id'] = $first[$pct >= 100 ? 'done' : ($pct > 0 ? 'doing' : 'todo')];
            } elseif ($pct >= 100 && $col['kind'] !== 'done') {
                $update['columns_id'] = $first['done'];
            } elseif ($pct < 100 && $col['kind'] === 'done') {
                $update['columns_id'] = $first['doing'];
            }
            $kind = $byId[$update['columns_id'] ?? (int) $t['columns_id']]['kind'] ?? 'todo';
            if ($kind === 'done' && !$t['date_done']) {
                $update['date_done'] = $t['real_end_date'] ?: $now;
            } elseif ($kind !== 'done' && $t['date_done']) {
                $update['date_done'] = null;
            }
            if ($update) {
                $DB->update('glpi_plugin_pmo_items', $update, ['projecttasks_id' => $id]);
                $tasks[$id] = array_merge($t, $update);
            }
        }
        return $tasks;
    }

    /** Dados de um cartão para o JavaScript. */
    public static function card(array $t, array $tasks, string $estimation = 'points'): array
    {
        static $types = [], $users = [];
        $typeId = (int) $t['projecttasktypes_id'];
        if ($typeId && !isset($types[$typeId])) {
            $types[$typeId] = Dropdown::getDropdownName(ProjectTaskType::getTable(), $typeId);
        }
        $people = [];
        foreach ($t['users'] as $uid) {
            $users[$uid] ??= self::person($uid);
            $people[] = $users[$uid];
        }
        $end = $t['plan_end_date'] ? strtotime($t['plan_end_date']) : null;
        $due = '';
        if ($end && $t['percent_done'] < 100) {
            $due = $end < time() ? 'late' : ($end < time() + 3 * DAY_TIMESTAMP ? 'soon' : '');
        }
        $parent = $t['projecttasks_id'] && isset($tasks[$t['projecttasks_id']]) ? $tasks[$t['projecttasks_id']] : null;
        return [
            'id'        => $t['id'],
            'key'       => 'T-' . $t['id'],
            'name'      => (string) $t['name'],
            'column'    => (int) $t['columns_id'],
            'sprint'    => (int) $t['sprints_id'],
            'rank'      => (int) $t['pmo_rank'],
            'points'    => (float) $t['story_points'],
            'hours'     => round((int) $t['planned_duration'] / 3600, 1),
            'size'      => self::size($t, $estimation),
            'priority'  => (int) ($t['priority'] ?? 3),
            'type'      => $typeId,
            'typeName'  => $typeId ? ($types[$typeId] ?? '') : '',
            'percent'   => $t['percent_done'],
            'start'     => $t['plan_start_date'] ? substr($t['plan_start_date'], 0, 10) : '',
            'end'       => $t['plan_end_date'] ? substr($t['plan_end_date'], 0, 10) : '',
            'due'       => $due,
            'milestone' => (bool) $t['is_milestone'],
            'users'     => $people,
            'parent'    => $parent ? ['id' => $parent['id'], 'name' => (string) $parent['name']] : null,
            'url'       => ProjectTask::getFormURLWithID($t['id']),
        ];
    }

    /** Tamanho do cartão para burndown/velocidade: pontos ou horas planejadas. */
    public static function size(array $t, string $estimation): float
    {
        return $estimation === 'hours'
            ? round((int) $t['planned_duration'] / 3600, 1)
            : (float) $t['story_points'];
    }

    public static function person(int $uid): array
    {
        $name = trim((string) getUserName($uid)) ?: "#$uid";
        $parts = preg_split('/\s+/', $name);
        $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
        return ['id' => $uid, 'name' => $name, 'initials' => $initials, 'hue' => ($uid * 47) % 360];
    }

    /** Usuários que podem ser responsáveis por cartões (ativos, sem o usuário interno do sistema). */
    public static function assignableUsers(): array
    {
        global $DB, $CFG_GLPI;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => User::getTable(),
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0, 'NOT' => ['id' => (int) ($CFG_GLPI['system_user'] ?? 0)]],
            'ORDER'  => ['realname', 'firstname', 'name'],
            'LIMIT'  => 500,
        ]) as $u) {
            $out[] = self::person((int) $u['id']);
        }
        return $out;
    }

    /** Move o cartão para a coluna (e posição) e reflete na tarefa: %, estado e datas reais. */
    public static function move(Project $project, int $taskId, int $columnId, array $order): array
    {
        global $DB;
        $projectId = (int) $project->getID();
        $columns = array_column(ProjectConfig::columns($projectId), null, 'id');
        $col = $columns[$columnId] ?? null;
        $task = new ProjectTask();
        if (!$col || !$task->getFromDB($taskId) || (int) $task->fields['projects_id'] !== $projectId) {
            throw new RuntimeException('Cartão ou coluna inválidos.');
        }
        $now = $_SESSION['glpi_currenttime'];
        $input = ['id' => $taskId];
        $pct = (int) $task->fields['percent_done'];
        if ($col['kind'] === 'done') {
            $input['percent_done'] = 100;
            if (!$task->fields['real_end_date']) {
                $input['real_end_date'] = $now;
            }
        } elseif ($col['percent'] >= 0) {
            $input['percent_done'] = $col['percent'];
        } elseif ($pct >= 100) {
            // reaberta: sai dos 100% para não contar como concluída
            $input['percent_done'] = 90;
        }
        if ($col['kind'] !== 'todo' && !$task->fields['real_start_date']) {
            $input['real_start_date'] = $now;
        }
        if ($col['kind'] !== 'done' && $task->fields['real_end_date'] && $pct >= 100) {
            $input['real_end_date'] = null;
        }
        if ($col['projectstates_id']) {
            $input['projectstates_id'] = $col['projectstates_id'];
        }
        if (count($input) > 1) {
            $task->update($input);
        }
        $DB->update('glpi_plugin_pmo_items', [
            'columns_id' => $columnId,
            'date_done'  => $col['kind'] === 'done' ? $now : null,
        ], ['projecttasks_id' => $taskId]);
        self::reorder($projectId, $order);
        Snapshot::take($projectId);

        $count = count($DB->request(['FROM' => 'glpi_plugin_pmo_items', 'WHERE' => ['projects_id' => $projectId, 'columns_id' => $columnId]]));
        return [
            'percent' => (int) ($input['percent_done'] ?? $pct),
            'warning' => $col['wip_limit'] && $count > $col['wip_limit'] ? "A coluna \"{$col['name']}\" passou do limite de WIP ({$col['wip_limit']})." : '',
        ];
    }

    /** Grava a ordem dos cartões (lista de ids de tarefa na ordem exibida). */
    public static function reorder(int $projectId, array $order, ?int $sprintId = null): void
    {
        global $DB;
        $ranks = [];
        foreach ($DB->request(['SELECT' => ['projecttasks_id', 'rank'], 'FROM' => 'glpi_plugin_pmo_items', 'WHERE' => ['projects_id' => $projectId], 'ORDER' => ['rank']]) as $r) {
            $ranks[(int) $r['projecttasks_id']] = (int) $r['rank'];
        }
        $order = array_values(array_filter(array_map('intval', $order), fn ($id) => isset($ranks[$id])));
        if (!$order) {
            return;
        }
        // os cartões reordenados ocupam, na ordem nova, as posições que já tinham entre si
        $slots = array_map(fn ($id) => $ranks[$id], $order);
        sort($slots);
        if (count(array_unique($slots)) < count($slots)) {
            $slots = range(min($slots), min($slots) + 10 * (count($slots) - 1), 10);
            $offset = max($slots) + 10;
            foreach ($ranks as $id => $rank) {
                if (!in_array($id, $order, true) && $rank >= min($slots)) {
                    $DB->update('glpi_plugin_pmo_items', ['rank' => $rank + $offset], ['projecttasks_id' => $id]);
                }
            }
        }
        foreach ($order as $i => $id) {
            $data = ['rank' => $slots[$i]];
            if ($sprintId !== null) {
                $data['sprints_id'] = $sprintId;
            }
            $DB->update('glpi_plugin_pmo_items', $data, ['projecttasks_id' => $id]);
        }
    }

    /** Criação rápida de cartão (tarefa nativa) numa coluna ou no backlog/sprint. */
    public static function create(Project $project, string $name, int $columnId, int $sprintId, int $typeId = 0): int
    {
        global $DB;
        $projectId = (int) $project->getID();
        $columns = ProjectConfig::columns($projectId);
        $byId = array_column($columns, null, 'id');
        $col = $byId[$columnId] ?? $columns[0] ?? null;
        if (!$col) {
            throw new RuntimeException('O projeto não tem colunas configuradas.');
        }
        $pct = $col['kind'] === 'done' ? 100 : max(0, $col['percent']);
        $task = new ProjectTask();
        $id = (int) $task->add([
            'projects_id'         => $projectId,
            'name'                => mb_substr(trim($name), 0, 255),
            'entities_id'         => $project->fields['entities_id'],
            'is_recursive'        => $project->fields['is_recursive'],
            'percent_done'        => $pct,
            'projecttasktypes_id' => $typeId,
        ]);
        if (!$id) {
            throw new RuntimeException('O GLPI não criou a tarefa.');
        }
        $min = (int) ($DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_pmo_items', 'WHERE' => ['projects_id' => $projectId]])->current()['m'] ?? 0);
        $DB->insert('glpi_plugin_pmo_items', [
            'projecttasks_id' => $id,
            'projects_id'     => $projectId,
            'columns_id'      => $col['id'],
            'sprints_id'      => $sprintId,
            'rank'            => $min + 10,
            'date_done'       => $col['kind'] === 'done' ? $_SESSION['glpi_currenttime'] : null,
            'date_creation'   => $_SESSION['glpi_currenttime'],
        ]);
        Snapshot::take($projectId);
        return $id;
    }

    /** Edição rápida do cartão (janela do quadro/backlog). */
    public static function update(Project $project, int $taskId, array $in): void
    {
        global $DB;
        $projectId = (int) $project->getID();
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || (int) $task->fields['projects_id'] !== $projectId) {
            throw new RuntimeException('Tarefa inválida.');
        }
        $input = ['id' => $taskId];
        if (isset($in['name']) && trim($in['name']) !== '') {
            $input['name'] = mb_substr(trim($in['name']), 0, 255);
        }
        foreach (['plan_start_date' => '08:00:00', 'plan_end_date' => '18:00:00'] as $f => $time) {
            if (array_key_exists($f, $in)) {
                $input[$f] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in[$f]) ? "{$in[$f]} $time" : null;
            }
        }
        if (isset($in['hours']) && $in['hours'] !== '') {
            $input['planned_duration'] = (int) round(max(0, (float) $in['hours']) * 3600);
        }
        if (isset($in['type'])) {
            $input['projecttasktypes_id'] = max(0, (int) $in['type']);
        }
        if (isset($in['content'])) {
            $input['content'] = (string) $in['content'];
        }
        if (count($input) > 1) {
            $task->update($input);
        }

        $item = [];
        if (isset($in['points'])) {
            $item['story_points'] = max(0, min(999, (float) $in['points']));
        }
        if (isset($in['priority'])) {
            $item['priority'] = max(1, min(5, (int) $in['priority']));
        }
        if (isset($in['sprint'])) {
            $sprint = (int) $in['sprint'];
            if ($sprint && !count($DB->request(['FROM' => 'glpi_plugin_pmo_sprints', 'WHERE' => ['id' => $sprint, 'projects_id' => $projectId]]))) {
                throw new RuntimeException('Sprint inválida.');
            }
            $item['sprints_id'] = $sprint;
        }
        if ($item) {
            $DB->update('glpi_plugin_pmo_items', $item, ['projecttasks_id' => $taskId]);
        }

        // responsáveis: só os membros do tipo usuário; grupos e fornecedores da equipe ficam
        if (isset($in['users']) && is_array($in['users'])) {
            $want = array_unique(array_filter(array_map('intval', $in['users'])));
            $have = [];
            foreach ($DB->request(['FROM' => 'glpi_projecttaskteams', 'WHERE' => ['projecttasks_id' => $taskId, 'itemtype' => User::class]]) as $m) {
                $have[(int) $m['items_id']] = (int) $m['id'];
            }
            $team = new ProjectTaskTeam();
            foreach (array_diff($want, array_keys($have)) as $uid) {
                $team->add(['projecttasks_id' => $taskId, 'itemtype' => User::class, 'items_id' => $uid]);
            }
            foreach (array_diff_key($have, array_flip($want)) as $linkId) {
                $team->delete(['id' => $linkId]);
            }
        }
        Snapshot::take($projectId);
    }
}
