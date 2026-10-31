<?php

/** Relatório final do evento (página própria, para imprimir ou salvar em PDF). */

use GlpiPlugin\Gestaoeventos\Encerramento;
use GlpiPlugin\Gestaoeventos\Evento;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('dossie')) {
    Html::displayRightError();
}

$evento = new Evento();
if (!$evento->getFromDB((int) ($_GET['id'] ?? 0)) || !$evento->canViewItem()) {
    Html::displayNotFoundError();
}
Encerramento::report($evento);
