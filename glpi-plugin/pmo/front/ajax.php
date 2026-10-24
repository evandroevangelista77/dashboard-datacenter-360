<?php

/**
 * API JSON do PMO para o quadro, o backlog, o Gantt e os painéis.
 * GET  ?action=board|backlog|gantt|dashboard|portfolio&project=ID
 * POST {action, project, ...} em JSON (CSRF: o GLPI 12 valida a origem pelo Sec-Fetch-Site)
 */

use GlpiPlugin\Pmo\Board;
use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\Metrics;
use GlpiPlugin\Pmo\Portfolio;
use GlpiPlugin\Pmo\ProjectConfig;
use GlpiPlugin\Pmo\Sprint;
use GlpiPlugin\Pmo\View;

global $DB;

Session::checkLoginUser();

$reply = function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
};

try {
    if (!Session::haveRight(Menu::$rightname, READ)) {
        throw new RuntimeException('Sem permissão para o PMO.', 403);
    }
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $in = $post ? (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []) : $_GET;
    $action = (string) ($in['action'] ?? '');

    if ($action === 'portfolio') {
        $reply(['rows' => Portfolio::rows(!empty($in['closed']))]);
        return;
    }

    $project = new Project();
    if (!$project->getFromDB((int) ($in['project'] ?? 0)) || !ProjectConfig::canView($project)) {
        throw new RuntimeException('Projeto não encontrado ou sem permissão.', 403);
    }
    $projectId = (int) $project->getID();
    $config = ProjectConfig::get($projectId);
    // o Gantt lê e ajusta as tarefas nativas, então funciona mesmo sem o PMO ativado
    if (!$config && !in_array($action, ['gantt', 'shift', 'link_add', 'link_delete'], true)) {
        throw new RuntimeException('O PMO não está ativo neste projeto.', 400);
    }
    if ($post && !ProjectConfig::canEdit($project)) {
        throw new RuntimeException('Você só tem leitura neste projeto.', 403);
    }

    $cards = function (array $tasks) use ($config): array {
        $out = [];
        foreach ($tasks as $t) {
            if ($t['children'] === 0 && $t['items_id'] !== null) {
                $out[] = Board::card($t, $tasks, $config['estimation']);
            }
        }
        usort($out, fn ($a, $b) => $a['rank'] <=> $b['rank']);
        return $out;
    };

    switch ($action) {
        case 'board':
        case 'backlog':
            $tasks = Board::sync($projectId);
            $reply([
                'columns' => ProjectConfig::columns($projectId),
                'cards'   => $cards($tasks),
                'sprints' => Sprint::all($projectId),
                'active'  => Sprint::active($projectId),
                'users'   => Board::assignableUsers(),
                'types'   => View::taskTypes(),
                'epics'   => array_values(array_map(fn ($t) => ['id' => $t['id'], 'name' => (string) $t['name']], array_filter($tasks, fn ($t) => $t['children'] > 0))),
                'config'  => $config,
            ]);
            return;

        case 'gantt':
            $tasks = Board::sync($projectId);
            $kinds = array_column(ProjectConfig::columns($projectId), 'kind', 'id');
            $rows = [];
            foreach ($tasks as $t) {
                $rows[] = [
                    'id'        => $t['id'],
                    'parent'    => $t['projecttasks_id'] && isset($tasks[$t['projecttasks_id']]) ? $t['projecttasks_id'] : 0,
                    'name'      => (string) $t['name'],
                    'start'     => $t['plan_start_date'],
                    'end'       => $t['plan_end_date'],
                    'rstart'    => $t['real_start_date'],
                    'rend'      => $t['real_end_date'],
                    'percent'   => $t['percent_done'],
                    'milestone' => (bool) $t['is_milestone'],
                    'summary'   => $t['children'] > 0,
                    'done'      => Metrics::isDone($t, $kinds),
                    'hours'     => round((int) $t['planned_duration'] / 3600, 1),
                    'users'     => array_map(fn ($u) => Board::person($u), $t['users']),
                    'url'       => ProjectTask::getFormURLWithID($t['id']),
                ];
            }
            $links = [];
            if ($tasks) {
                foreach ($DB->request(['FROM' => ProjectTaskLink::getTable(), 'WHERE' => ['projecttasks_id_source' => array_keys($tasks)]]) as $l) {
                    $links[] = ['id' => (int) $l['id'], 'source' => (int) $l['projecttasks_id_source'], 'target' => (int) $l['projecttasks_id_target'], 'type' => (int) $l['type'], 'lag' => (int) $l['lag']];
                }
            }
            $reply([
                'tasks'   => $rows,
                'links'   => $links,
                'project' => ['start' => $project->fields['plan_start_date'], 'end' => $project->fields['plan_end_date']],
            ]);
            return;

        case 'dashboard':
            $reply(Metrics::dashboard($project, (int) ($in['sprint'] ?? 0) ?: null));
            return;

        case 'move':
            $reply(['ok' => true] + Board::move($project, (int) $in['task'], (int) $in['column'], (array) ($in['order'] ?? [])));
            return;

        case 'reorder':
            Board::reorder($projectId, (array) ($in['order'] ?? []), array_key_exists('sprint', $in) ? (int) $in['sprint'] : null);
            $reply(['ok' => true]);
            return;

        case 'create':
            $id = Board::create($project, (string) ($in['name'] ?? ''), (int) ($in['column'] ?? 0), (int) ($in['sprint'] ?? 0), (int) ($in['type'] ?? 0));
            $tasks = Board::tasks($projectId);
            $reply(['ok' => true, 'card' => Board::card($tasks[$id], $tasks, $config['estimation'])]);
            return;

        case 'update':
            Board::update($project, (int) $in['task'], (array) ($in['data'] ?? []));
            $tasks = Board::tasks($projectId);
            $reply(['ok' => true, 'card' => Board::card($tasks[(int) $in['task']], $tasks, $config['estimation'])]);
            return;

        case 'shift':
            // Gantt: arrasta a barra (dias inteiros, preservando o horário de início/fim)
            $task = new ProjectTask();
            if (!$task->getFromDB((int) $in['task']) || (int) $task->fields['projects_id'] !== $projectId) {
                throw new RuntimeException('Tarefa inválida.');
            }
            $ds = (int) ($in['start'] ?? 0);
            $de = (int) ($in['end'] ?? 0);
            $start = $task->fields['plan_start_date'];
            $end = $task->fields['plan_end_date'];
            if (!$start || !$end) {
                throw new RuntimeException('A tarefa não tem datas planejadas.');
            }
            $newStart = date('Y-m-d H:i:s', strtotime("$start $ds days"));
            $newEnd = date('Y-m-d H:i:s', strtotime("$end $de days"));
            if (strtotime($newEnd) < strtotime($newStart)) {
                throw new RuntimeException('O fim não pode ficar antes do início.');
            }
            $task->update(['id' => $task->getID(), 'plan_start_date' => $newStart, 'plan_end_date' => $newEnd]);
            $reply(['ok' => true, 'start' => $newStart, 'end' => $newEnd]);
            return;

        case 'link_add':
            $src = new ProjectTask();
            $dst = new ProjectTask();
            if (!$src->getFromDB((int) $in['source']) || !$dst->getFromDB((int) $in['target'])
                || (int) $src->fields['projects_id'] !== $projectId || (int) $dst->fields['projects_id'] !== $projectId || $src->getID() === $dst->getID()) {
                throw new RuntimeException('Tarefas inválidas para a dependência.');
            }
            $link = ['projecttasks_id_source' => $src->getID(), 'projecttasks_id_target' => $dst->getID(), 'type' => max(0, min(3, (int) ($in['type'] ?? 0)))];
            $pl = new ProjectTaskLink();
            if ($pl->checkIfExist($link)) {
                throw new RuntimeException('Essa dependência já existe.');
            }
            $id = $pl->add($link + ['source_uuid' => $src->fields['uuid'], 'target_uuid' => $dst->fields['uuid'], 'lag' => (int) ($in['lag'] ?? 0)]);
            $reply(['ok' => (bool) $id, 'id' => (int) $id]);
            return;

        case 'link_delete':
            $pl = new ProjectTaskLink();
            if (!$pl->getFromDB((int) $in['link'])) {
                throw new RuntimeException('Dependência não encontrada.');
            }
            $src = new ProjectTask();
            if (!$src->getFromDB($pl->fields['projecttasks_id_source']) || (int) $src->fields['projects_id'] !== $projectId) {
                throw new RuntimeException('Dependência de outro projeto.');
            }
            $pl->delete(['id' => $pl->getID()], true);
            $reply(['ok' => true]);
            return;

        case 'sprint_create':
            $reply(['ok' => true, 'id' => Sprint::create($projectId, (array) ($in['data'] ?? []))]);
            return;
        case 'sprint_update':
            Sprint::update($projectId, (int) $in['sprint'], (array) ($in['data'] ?? []));
            $reply(['ok' => true]);
            return;
        case 'sprint_start':
            Sprint::start($projectId, (int) $in['sprint'], (array) ($in['data'] ?? []));
            $reply(['ok' => true]);
            return;
        case 'sprint_close':
            $reply(['ok' => true] + Sprint::close($projectId, (int) $in['sprint'], (int) ($in['target'] ?? 0)));
            return;
        case 'sprint_delete':
            Sprint::delete($projectId, (int) $in['sprint']);
            $reply(['ok' => true]);
            return;
    }
    throw new RuntimeException("Ação desconhecida: $action", 400);
} catch (Throwable $e) {
    $code = $e->getCode();
    $reply(['ok' => false, 'error' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 500);
}
