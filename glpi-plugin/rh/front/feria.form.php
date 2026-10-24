<?php

use GlpiPlugin\Rh\Feria;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
$ferias = new Feria();

try {
    if (isset($_POST['add'])) {
        $ferias->check(-1, CREATE, $_POST);
        if ($ferias->add($_POST)) {
            Session::addMessageAfterRedirect('Pedido enviado ao gestor.', false, INFO);
            Html::redirect($ferias->getLinkURL());
        }
        Html::back();
    } elseif (isset($_POST['update'])) {
        $ferias->check((int) $_POST['id'], UPDATE);
        $ferias->update($_POST);
        Html::back();
    } elseif (isset($_POST['workflow'])) {
        $ferias->check((int) $_POST['id'], READ);
        Session::addMessageAfterRedirect(htmlspecialchars($ferias->transition((string) $_POST['workflow'], $_POST)), false, INFO);
        Html::back();
    }
} catch (RuntimeException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$ferias->check($id ?: -1, $id ? READ : CREATE);
Menu::header(Feria::getTypeName(2), 'ferias');
$ferias->display(['id' => $id]);
Html::footer();
