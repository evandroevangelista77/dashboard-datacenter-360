<?php

use GlpiPlugin\Rh\Candidato;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
if (!Candidato::canView()) {
    Html::displayRightError();
}

Menu::header('Banco de Currículo', 'candidato');
echo "<div class='rh'>" . Menu::title('Banco de Currículo', 'candidato', Candidato::canCreate() ? Menu::button('Novo candidato', Candidato::getFormURL()) : '') . "</div>";
echo "<div class='rh'>" . Candidato::filters($_GET) . "</div>";
Search::show(Candidato::class);
Html::footer();
