<?php

use GlpiPlugin\Rh\Admissao;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
$adm = new Admissao();

if (!empty($_POST['id'])) {
    $adm->check((int) $_POST['id'], READ);
    try {
        if (!$adm->canUpdateItem()) {
            throw new RuntimeException('Você não pode alterar esta admissão.');
        }
        if (isset($_POST['update'])) {
            $adm->update(['id' => $adm->getID(), 'start_date' => ($_POST['start_date'] ?? '') ?: null, 'comment' => (string) ($_POST['comment'] ?? '')]);
        } elseif (isset($_POST['additem'])) {
            $adm->addItem((string) ($_POST['area'] ?? ''), (string) ($_POST['label'] ?? ''));
        } elseif (isset($_POST['ticket'])) {
            $tid = $adm->openTicket();
            Session::addMessageAfterRedirect("Chamado de TI #$tid aberto.", false, INFO);
        } elseif (isset($_POST['finish'])) {
            $adm->update(['id' => $adm->getID(), 'status' => Admissao::DONE]);
            Session::addMessageAfterRedirect('Admissão concluída. Bem-vindo(a) ao time!', false, INFO);
        } elseif (isset($_POST['cancel'])) {
            $adm->update(['id' => $adm->getID(), 'status' => Admissao::CANCELED]);
            Session::addMessageAfterRedirect('Admissão cancelada.', false, INFO);
        }
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    }
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$adm->check($id, READ);
Menu::header(Admissao::getTypeName(2), 'admissao');
$adm->display(['id' => $id]);
Html::footer();
