<?php

/** Modelos de planilha para importação (?tipo=convidados). */

use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Importacao;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('marketing')) {
    Html::displayRightError();
}

if (($_GET['tipo'] ?? '') === 'convidados') {
    Importacao::modelo();
    return;
}
Html::displayNotFoundError();
