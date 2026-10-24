<?php

use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\ProjectConfig;
use GlpiPlugin\Pmo\View;

Session::checkRight(Menu::$rightname, READ);

$project = new Project();
if (!$project->getFromDB((int) ($_GET['id'] ?? 0)) || !ProjectConfig::canView($project)) {
    Html::displayRightError();
}
$view = (string) ($_GET['view'] ?? 'board');

View::requireAssets($view);
Html::header('PMO - ' . $project->getName(), '', Menu::SECTOR, 'portfolio');
View::showProject($project, $view);
Html::footer();
