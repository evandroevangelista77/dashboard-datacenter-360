<?php

use GlpiPlugin\Reembolso\Biblioteca;
use GlpiPlugin\Reembolso\Docs;
use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Reembolso;

Session::checkLoginUser();
if (!Reembolso::canView()) {
    Html::displayRightError();
}
Html::header('Comprovantes', '', Menu::SECTOR, 'documentos');
echo Menu::css();
Biblioteca::page(Docs::sources(), $_GET, "<div class='rb'>" . Menu::title('Comprovantes', 'documentos') . "</div>");
echo Html::script(Menu::url('/js/drawer.js'), ['version' => Menu::fileVersion('js/drawer.js')], false)
    . Html::script(Menu::url('/js/biblioteca.js'), ['version' => Menu::fileVersion('js/biblioteca.js')], false);
Html::footer();
