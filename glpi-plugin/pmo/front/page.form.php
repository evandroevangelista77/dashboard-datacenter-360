<?php

use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\Page;
use GlpiPlugin\Pmo\ProjectConfig;
use GlpiPlugin\Pmo\View;

Session::checkRight(Menu::$rightname, UPDATE);

$project = new Project();
$id = (int) ($_POST['projects_id'] ?? 0);
if (!$project->getFromDB($id) || !ProjectConfig::canEdit($project)) {
    Html::displayRightError();
}

try {
    if (!empty($_POST['create'])) {
        $page = Page::create($id, (string) ($_POST['template'] ?? 'blank'));
        Html::redirect(View::projectUrl($id, 'docs', ['page' => $page, 'edit' => 1]));
    }
    $page = (int) ($_POST['id'] ?? 0);
    if (!empty($_POST['delete'])) {
        Page::delete($id, $page);
        Session::addMessageAfterRedirect('Página excluída.', false, INFO);
        Html::redirect(View::projectUrl($id, 'docs'));
    }
    if (!empty($_POST['save'])) {
        Page::save($id, $page, (string) ($_POST['name'] ?? ''), (string) ($_POST['content'] ?? ''));
        Session::addMessageAfterRedirect('Página salva.', false, INFO);
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
}
Html::redirect(View::projectUrl($id, 'docs', ['page' => $page ?? 0]));
