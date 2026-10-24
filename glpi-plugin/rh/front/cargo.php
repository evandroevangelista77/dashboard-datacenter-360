<?php

use GlpiPlugin\Rh\Cargo;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
if (!Cargo::canView()) {
    Html::displayRightError();
}

Menu::header('Cadastro de Cargos', 'cargo');
echo "<div class='rh'>" . Menu::title('Cadastro de Cargos', 'cargo', Cargo::canCreate() ? Menu::button('Novo', Cargo::getFormURL()) : '') . "</div>";
Search::show(Cargo::class);
Html::footer();
