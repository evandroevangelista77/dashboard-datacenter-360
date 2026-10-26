<?php

use GlpiPlugin\Ciclo\Ciclo;
use GlpiPlugin\Ciclo\Documentos;
use GlpiPlugin\Ciclo\Fase;
use GlpiPlugin\Ciclo\Item;
use GlpiPlugin\Ciclo\Menu;

Session::checkRight(Ciclo::$rightname, READ);
$ciclo = new Ciclo();

/** Carrega o Ciclo do POST e confere quem pode mexer nos dados. */
$load = function () use ($ciclo): void {
    if (!$ciclo->getFromDB((int) ($_POST['id'] ?? 0))) {
        Html::displayNotFoundError();
    }
    if (!$ciclo->canEditData()) {
        Html::displayRightError();
    }
};

try {
    if (isset($_POST['add'])) {
        $ciclo->check(-1, CREATE, $_POST);
        if ($id = $ciclo->add($_POST)) {
            Session::addMessageAfterRedirect('Ciclo aberto. A validação da documentação já está com o OTC.');
            Html::redirect(Ciclo::getFormURLWithID($id));
        }
        Html::back();
    } elseif (isset($_POST['update'])) {
        $load();
        $ciclo->update(array_intersect_key($_POST, array_flip([
            'id', 'name', 'customer', 'opportunity', 'sale_type', 'amount', 'date_closing', 'date_signed', 'users_id_commercial', 'users_id_otc', 'users_id_gp',
            'cc_number', 'cc_description', 'date_transfer', 'transfer_participants', 'transfer_minutes', 'cubage', 'transport_mode', 'transport_cost', 'date_delivery', 'projects_id', 'date_accept', 'comment',
        ])));
        Html::back();
    } elseif (isset($_POST['purge'])) {
        $ciclo->check((int) $_POST['id'], PURGE);
        $ciclo->delete(['id' => (int) $_POST['id']], true);
        Html::redirect(Ciclo::getSearchURL());
    } elseif (isset($_POST['workflow'])) {
        if (!$ciclo->getFromDB((int) ($_POST['id'] ?? 0))) {
            Html::displayNotFoundError();
        }
        Session::addMessageAfterRedirect(htmlspecialchars(Fase::workflow($ciclo, (string) $_POST['workflow'], $_POST)));
        Html::back();
    } elseif (isset($_POST['item_save'])) {
        $load();
        Item::save($ciclo, (array) ($_POST['item'] ?? []));
        Html::back();
    } elseif (isset($_POST['doc_upload'])) {
        $load();
        $n = Documentos::upload($ciclo, (array) ($_FILES['docs'] ?? []), (int) ($_POST['doc_category'] ?? 0), (string) ($_POST['doc_code'] ?? ''), trim((string) ($_POST['doc_note'] ?? '')));
        Session::addMessageAfterRedirect($n ? "$n documento(s) anexado(s)." : 'Escolha pelo menos um arquivo.', false, $n ? INFO : WARNING);
        Html::redirect(Ciclo::getFormURLWithID($ciclo->getID()) . '#cf-docs');
    } elseif (isset($_POST['doc_remove'])) {
        $load();
        Documentos::remove($ciclo, (int) ($_POST['doc_id'] ?? 0));
        Html::redirect(Ciclo::getFormURLWithID($ciclo->getID()) . '#cf-docs');
    } elseif (isset($_POST['item_delete'])) {
        $load();
        Item::remove($ciclo, (int) ($_POST['item_id'] ?? 0));
        Html::back();
    }
} catch (RuntimeException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$ciclo->check($id ?: -1, $id ? READ : CREATE);
Menu::header(Ciclo::getTypeName(2), 'ciclo');
$ciclo->display(['id' => $id]);
Html::footer();
