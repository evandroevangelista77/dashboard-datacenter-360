<?php

use GlpiPlugin\Gestaoeventos\Calendario;
use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Menu;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('calendario')) {
    Html::displayRightError();
}

Menu::header('Calendário de Eventos', 'calendario');
Calendario::show($_GET);
Html::footer();
