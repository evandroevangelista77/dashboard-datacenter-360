<?php

use GlpiPlugin\Rh\Departamento;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
if (!Departamento::canView()) {
    Html::displayRightError();
}

Menu::header('Departamentos', 'departamento');
echo "<div class='rh'>" . Menu::title('Departamentos', 'departamento', Departamento::canCreate() ? Menu::button('Novo', Departamento::getFormURL()) : '') . "</div>";
Search::show(Departamento::class);
Html::footer();
