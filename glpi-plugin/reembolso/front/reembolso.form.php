<?php

use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Reembolso;

Session::checkLoginUser();

$reembolso = new Reembolso();

try {
    if (isset($_POST['add'])) {
        $reembolso->check(-1, CREATE, $_POST);
        if ($id = $reembolso->add($_POST)) {
            Session::addMessageAfterRedirect('Solicitação criada. Agora lance as despesas.', false, INFO);
            Html::redirect($reembolso->getLinkURL());
        }
        Html::back();
    } elseif (isset($_POST['update'])) {
        $reembolso->check((int) $_POST['id'], UPDATE);
        $reembolso->update($_POST);
        Html::back();
    } elseif (isset($_POST['purge'])) {
        $reembolso->check((int) $_POST['id'], PURGE);
        $reembolso->delete(['id' => (int) $_POST['id']], true);
        Session::addMessageAfterRedirect('Solicitação excluída.', false, INFO);
        Html::redirect(Reembolso::getSearchURL());
    } elseif (isset($_POST['workflow'])) {
        $reembolso->check((int) $_POST['id'], READ);
        Session::addMessageAfterRedirect($reembolso->transition((string) $_POST['workflow'], $_POST), false, INFO);
        Html::back();
    }
} catch (RuntimeException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$reembolso->check($id ?: -1, $id ? READ : CREATE);
Html::header(Reembolso::getTypeName(2), '', Menu::SECTOR, Menu::item(Reembolso::class));
echo Menu::css();
$reembolso->display(['id' => $id]);
Html::footer();
