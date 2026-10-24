<?php

use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\ProjectConfig;
use GlpiPlugin\Pmo\View;

Session::checkRight(Menu::$rightname, UPDATE);

$project = new Project();
$id = (int) ($_POST['projects_id'] ?? 0);
if (!$project->getFromDB($id) || !ProjectConfig::canEdit($project)) {
    Html::displayRightError();
}

$methodology = (string) ($_POST['methodology'] ?? 'kanban');
$estimation  = (string) ($_POST['estimation'] ?? 'points');
$sprintDays  = (int) ($_POST['sprint_days'] ?? 14);

if (!empty($_POST['enable'])) {
    ProjectConfig::enable($id, $methodology, $estimation, $sprintDays);
    Session::addMessageAfterRedirect('PMO ativado no projeto. As tarefas viraram cartões do quadro.', false, INFO);
    Html::redirect(View::projectUrl($id, $methodology === 'waterfall' ? 'gantt' : 'board'));
}

if (!empty($_POST['disable'])) {
    global $DB;
    foreach (['projects', 'columns', 'items', 'sprints', 'snapshots', 'pages', 'risks'] as $t) {
        $DB->delete("glpi_plugin_pmo_$t", ['projects_id' => $id]);
    }
    Session::addMessageAfterRedirect('PMO desativado no projeto.', false, INFO);
    Html::redirect($project->getLinkURL());
}

ProjectConfig::enable($id, $methodology, $estimation, $sprintDays);
if (!empty($_POST['template'])) {
    ProjectConfig::applyTemplate($id, (string) $_POST['template']);
    Session::addMessageAfterRedirect('Colunas recriadas pelo modelo.', false, INFO);
} else {
    $cols = $_POST['columns'] ?? [];
    $rows = [];
    foreach (($cols['name'] ?? []) as $i => $name) {
        $rows[] = [
            'id'               => $cols['id'][$i] ?? 0,
            'name'             => $name,
            'kind'             => $cols['kind'][$i] ?? 'todo',
            'color'            => $cols['color'][$i] ?? '',
            'wip_limit'        => $cols['wip_limit'][$i] ?? 0,
            'percent'          => $cols['percent'][$i] ?? '',
            'projectstates_id' => $cols['projectstates_id'][$i] ?? 0,
        ];
    }
    if ($rows) {
        ProjectConfig::saveColumns($id, $rows);
    }
    Session::addMessageAfterRedirect('Configuração salva.', false, INFO);
}
Html::redirect(View::projectUrl($id, 'config'));
