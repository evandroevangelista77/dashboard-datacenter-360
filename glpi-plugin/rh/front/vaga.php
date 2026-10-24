<?php

use GlpiPlugin\Rh\Vaga;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
if (!Vaga::canView()) {
    Html::displayRightError();
}

Menu::header('Vagas abertas', 'vaga');
echo "<div class='rh'>" . Menu::title('Vagas abertas', 'vaga', Vaga::canCreate() ? Menu::button('Nova requisição de vaga', Vaga::getFormURL()) : '') . "</div>";
Search::show(Vaga::class);
Html::footer();
