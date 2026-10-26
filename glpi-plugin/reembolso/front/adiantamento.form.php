<?php

use GlpiPlugin\Reembolso\Adiantamento;
use GlpiPlugin\Reembolso\Menu;

Session::checkLoginUser();

$ad = new Adiantamento();

try {
    if (isset($_POST['add'])) {
        $ad->check(-1, CREATE, $_POST);
        if ($ad->add($_POST)) {
            Session::addMessageAfterRedirect('Adiantamento criado. Agora lance as despesas previstas.', false, INFO);
            Html::redirect($ad->getLinkURL());
        }
        Html::back();
    } elseif (isset($_POST['update'])) {
        $ad->check((int) $_POST['id'], UPDATE);
        $ad->update($_POST);
        Html::back();
    } elseif (isset($_POST['item_add']) || isset($_POST['item_delete'])) {
        $ad->check((int) $_POST['id'], UPDATE);
        if (!$ad->isEditable()) {
            throw new RuntimeException('As despesas previstas só mudam no rascunho ou com o pedido devolvido.');
        }
        isset($_POST['item_add']) ? $ad->addItem($_POST) : $ad->deleteItem((int) $_POST['item_delete']);
        Html::back();
    } elseif (isset($_POST['lote_action'])) {
        if (!$ad->getFromDB((int) ($_POST['id'] ?? 0)) || !$ad->canViewItem()) {
            Html::displayRightError();
        }
        $msg = $_POST['lote_action'] === 'check'
            ? ($ad->syncSignature() ? 'Assinatura confirmada: o pedido seguiu para o gestor da área.' : 'O LibreSign ainda não tem a assinatura do técnico.')
            : $ad->act((string) $_POST['lote_action'], $_POST, $_FILES);
        Session::addMessageAfterRedirect(htmlspecialchars($msg), false, INFO);
        Html::back();
    }
} catch (RuntimeException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    if (!$ad->getFromDB($id) || !$ad->canViewItem()) {
        Html::displayRightError();
    }
} elseif (!Adiantamento::canCreate()) {
    Html::displayRightError();
}
Html::header(Adiantamento::getTypeName(2), '', Menu::SECTOR, Menu::item(Adiantamento::class));
echo Menu::css();
$ad->display(['id' => $id]);
Html::footer();
