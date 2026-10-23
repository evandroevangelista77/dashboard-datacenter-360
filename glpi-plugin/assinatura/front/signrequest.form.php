<?php

use GlpiPlugin\Assinatura\SignRequest;

Session::checkLoginUser();

$ticket = new Ticket();
$ticketId = (int) ($_POST['tickets_id'] ?? 0);
if (!$ticket->getFromDB($ticketId) || !$ticket->canUpdateItem()) {
    Html::displayRightError();
}

try {
    if (isset($_POST['add'])) {
        $document = (string) ($_POST['document'] ?? 'ticket_pdf');
        $source = str_starts_with($document, 'doc:') ? 'document' : 'ticket_pdf';
        $req = SignRequest::createFor(
            $ticket,
            (int) ($_POST['users_id'] ?? 0),
            $source,
            $source === 'document' ? (int) substr($document, 4) : 0,
            trim((string) ($_POST['message'] ?? ''))
        );
        Session::addMessageAfterRedirect(sprintf('Assinatura enviada para %s pelo LibreSign.', htmlspecialchars($req->fields['signer_email'])), false, INFO);
    } elseif (isset($_POST['sync']) || isset($_POST['cancel'])) {
        $req = new SignRequest();
        if (!$req->getFromDB((int) ($_POST['id'] ?? 0)) || (int) $req->fields['tickets_id'] !== $ticketId) {
            Html::displayRightError();
        }
        if (isset($_POST['cancel'])) {
            $req->cancel();
            Session::addMessageAfterRedirect('Solicitação de assinatura cancelada.', false, INFO);
        } else {
            $status = $req->sync();
            Session::addMessageAfterRedirect($status === 'signed' ? 'Documento assinado! O PDF assinado foi anexado.' : 'Ainda aguardando a assinatura.', false, INFO);
        }
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect('Assinatura digital: ' . htmlspecialchars($e->getMessage()), false, ERROR);
}

Html::redirect($ticket->getLinkURL() . '&forcetab=' . rawurlencode('GlpiPlugin\\Assinatura\\TicketTab$1'));
