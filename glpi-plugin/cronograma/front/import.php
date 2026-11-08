<?php

use GlpiPlugin\Cronograma\ProjectTab;

Session::checkLoginUser();
Session::checkRight('plugin_cronograma', UPDATE);

$project = new Project();
if (!$project->getFromDB((int) ($_GET['projects_id'] ?? 0)) || !$project->canUpdateItem()) {
    Html::displayRightError();
}

Html::header('Importar cronograma', '', 'tools', Project::class);
ProjectTab::showImportForm($project);
Html::footer();
