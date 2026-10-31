<?php

use GlpiPlugin\Gestaoeventos\Aprovacao;
use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Menu;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('aprovacoes')) {
    Html::displayRightError();
}

Menu::header('Aprovações', 'aprovacoes');
Aprovacao::page();
Html::footer();
