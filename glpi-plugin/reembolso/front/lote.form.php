<?php

use GlpiPlugin\Reembolso\Lote;
use GlpiPlugin\Reembolso\Menu;

Session::checkLoginUser();

$lote = new Lote();
if (isset($_POST['lote_action'])) {
    try {
        if (!$lote->getFromDB((int) ($_POST['id'] ?? 0)) || !$lote->canViewItem()) {
            Html::displayRightError();
        }
        if ($_POST['lote_action'] === 'check') {
            $msg = $lote->syncSignature() ? 'Assinatura confirmada: o lote seguiu para o gestor da área.' : 'O LibreSign ainda não tem a assinatura do técnico.';
        } else {
            $msg = $lote->act((string) $_POST['lote_action'], $_POST, $_FILES);
        }
        Session::addMessageAfterRedirect(htmlspecialchars($msg), false, INFO);
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    }
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
if (!$lote->getFromDB($id) || !$lote->canViewItem()) {
    Html::displayRightError();
}
Html::header(Lote::getTypeName(2), '', Menu::SECTOR, Menu::item(Lote::class));
echo Menu::css();
$lote->display(['id' => $id]);
Html::footer();
