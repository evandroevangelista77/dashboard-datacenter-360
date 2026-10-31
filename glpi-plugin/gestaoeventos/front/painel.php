<?php

use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Menu;
use GlpiPlugin\Gestaoeventos\Painel;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('painel')) {
    Html::displayRightError();
}

Html::requireJs('charts');
Menu::header('Painel de Eventos', 'painel');
Painel::show($_GET);
Html::footer();
