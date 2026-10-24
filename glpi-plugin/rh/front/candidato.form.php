<?php

use GlpiPlugin\Rh\Candidato;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
$cand = new Candidato();

$photo = function (Candidato $c): void {
    try {
        if (!empty($_POST['remove_picture'])) {
            $c->removePictureFile();
            $c->update(['id' => $c->getID(), 'picture' => '']);
        }
        if (!empty($_FILES['picture'])) {
            $c->savePicture($_FILES['picture']);
        }
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    }
    try {
        if (!empty($_FILES['resume'])) {
            $c->saveResume($_FILES['resume']);
            if (($_FILES['resume']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                Session::addMessageAfterRedirect('Currículo anexado.');
            }
        }
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    }
};

if (isset($_POST['add'])) {
    $cand->check(-1, CREATE, $_POST);
    if ($cand->add($_POST)) {
        $photo($cand);
        Html::redirect($cand->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $cand->check((int) $_POST['id'], UPDATE);
    $cand->update($_POST);
    $cand->getFromDB((int) $_POST['id']);
    $photo($cand);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $cand->check((int) $_POST['id'], PURGE);
    $cand->delete(['id' => (int) $_POST['id']], true);
    Html::redirect(Candidato::getSearchURL());
}

$id = (int) ($_GET['id'] ?? 0);
$cand->check($id ?: -1, $id ? READ : CREATE);
Menu::header(Candidato::getTypeName(2), 'candidato');
$cand->display(['id' => $id]);
Html::footer();
