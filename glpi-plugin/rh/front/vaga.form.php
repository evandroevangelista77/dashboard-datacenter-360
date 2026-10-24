<?php

use GlpiPlugin\Rh\Menu;
use GlpiPlugin\Rh\Vaga;

Session::checkLoginUser();
$vaga = new Vaga();

try {
    if (isset($_POST['add'])) {
        $vaga->check(-1, CREATE, $_POST);
        if ($vaga->add($_POST)) {
            Session::addMessageAfterRedirect('Requisição salva como rascunho. Revise e clique em "Enviar para aprovação".', false, INFO);
            Html::redirect($vaga->getLinkURL());
        }
        Html::back();
    } elseif (isset($_POST['update'])) {
        $vaga->check((int) $_POST['id'], UPDATE);
        $vaga->update($_POST);
        Html::back();
    } elseif (isset($_POST['purge'])) {
        $vaga->check((int) $_POST['id'], PURGE);
        $vaga->delete(['id' => (int) $_POST['id']], true);
        Html::redirect(Vaga::getSearchURL());
    } elseif (isset($_POST['workflow'])) {
        $vaga->check((int) $_POST['id'], READ);
        Session::addMessageAfterRedirect(htmlspecialchars($vaga->transition((string) $_POST['workflow'], $_POST)), false, INFO);
        Html::back();
    }
} catch (RuntimeException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$vaga->check($id ?: -1, $id ? READ : CREATE);
Menu::header(Vaga::getTypeName(2), 'vaga');
$vaga->display(['id' => $id]);
Html::footer();
