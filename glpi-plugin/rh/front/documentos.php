<?php

use GlpiPlugin\Rh\Biblioteca;
use GlpiPlugin\Rh\Docs;
use GlpiPlugin\Rh\Menu;
use GlpiPlugin\Rh\Vaga;

Session::checkLoginUser();
if (!Session::haveRightsOr(Vaga::$rightname, [READ, Vaga::READALL])) {
    Html::displayRightError();
}
Menu::header('Documentos do RH', 'documentos');
Biblioteca::page(Docs::sources(), $_GET, "<div class='rh'>" . Menu::title('Documentos do RH', 'documentos') . "</div>");
echo Menu::script('drawer.js') . Menu::script('biblioteca.js');
Html::footer();
