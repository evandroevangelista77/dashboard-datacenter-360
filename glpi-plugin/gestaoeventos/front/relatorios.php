<?php

use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Menu;
use GlpiPlugin\Gestaoeventos\Relatorios;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('relatorios')) {
    Html::displayRightError();
}

Menu::header('Relatórios', 'relatorios');
Relatorios::page($_GET);
Html::footer();
