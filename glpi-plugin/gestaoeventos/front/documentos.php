<?php

use GlpiPlugin\Gestaoeventos\Documentos;
use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Menu;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('documentos')) {
    Html::displayRightError();
}

Menu::header('Documentos dos eventos', 'documentos');
Documentos::page($_GET);
Html::footer();
