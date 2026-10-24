<?php

use GlpiPlugin\Rh\Feria;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
if (!Feria::canView()) {
    Html::displayRightError();
}

Menu::header('Férias', 'ferias');
echo "<div class='rh'>" . Menu::title('Férias', 'ferias', (Feria::canCreate() ? Menu::button('Pedir férias', Feria::getFormURL()) : '') . Menu::button('Calendário', Menu::url('/front/calendario.php'), 'ti-calendar-event', 'btn-outline-secondary')) . "</div>";
Search::show(Feria::class);
Html::footer();
