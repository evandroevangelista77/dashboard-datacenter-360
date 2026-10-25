<?php

use GlpiPlugin\Ciclo\Ciclo;
use GlpiPlugin\Ciclo\Menu;

Session::checkRight(Ciclo::$rightname, READ);

Menu::header('Ciclos', 'ciclo');
echo "<div class='cf'>" . Menu::title('Ciclos', 'ciclo', Menu::button('Painel', Menu::url('/front/painel.php'), 'ti-chart-dots-3', 'btn-outline-secondary')
    . (Ciclo::canCreate() ? Menu::button('Novo Ciclo', Ciclo::getFormURL()) : '')) . "</div>";
Search::show(Ciclo::class);
Html::footer();
