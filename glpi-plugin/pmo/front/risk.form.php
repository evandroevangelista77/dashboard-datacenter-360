<?php

use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\ProjectConfig;
use GlpiPlugin\Pmo\Risk;
use GlpiPlugin\Pmo\View;

Session::checkRight(Menu::$rightname, UPDATE);

$project = new Project();
$id = (int) ($_POST['projects_id'] ?? 0);
if (!$project->getFromDB($id) || !ProjectConfig::canEdit($project)) {
    Html::displayRightError();
}

if (!empty($_POST['delete'])) {
    Risk::delete($id, (int) ($_POST['id'] ?? 0));
    Session::addMessageAfterRedirect('Item excluído.', false, INFO);
    Html::redirect(View::projectUrl($id, 'risks'));
}
$risk = Risk::save($id, $_POST);
Session::addMessageAfterRedirect('Item salvo.', false, INFO);
Html::redirect(View::projectUrl($id, 'risks', ['risk' => $risk]));
