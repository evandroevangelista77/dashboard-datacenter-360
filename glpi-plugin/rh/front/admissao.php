<?php

use GlpiPlugin\Rh\Admissao;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
if (!Admissao::canView()) {
    Html::displayRightError();
}

Menu::header('Admissões', 'admissao');
echo "<div class='rh'>" . Menu::title('Admissões', 'admissao', '') . "</div>";
Search::show(Admissao::class);
Html::footer();
