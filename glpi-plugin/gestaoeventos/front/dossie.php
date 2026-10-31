<?php

/** Dossiê executivo do evento em PDF (?id=); com &anexar=1 também guarda uma cópia nos documentos do evento. */

use GlpiPlugin\Gestaoeventos\Dossie;
use GlpiPlugin\Gestaoeventos\Evento;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('dossie')) {
    Html::displayRightError();
}

$evento = new Evento();
if (!$evento->getFromDB((int) ($_GET['id'] ?? 0)) || !$evento->canViewItem()) {
    Html::displayNotFoundError();
}
if (!empty($_GET['anexar']) && $evento->canEditData()) {
    Dossie::attach($evento);
}
$pdf = Dossie::build($evento);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Dossie-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $evento->fields['code']) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
