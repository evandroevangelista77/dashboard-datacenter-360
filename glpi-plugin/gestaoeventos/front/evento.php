<?php

use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Menu;

Session::checkRight(Evento::$rightname, READ);

Menu::header('Eventos', 'evento');
echo "<div class='ge'>" . Menu::title('Eventos', 'evento', Menu::button('Calendário', Menu::url('/front/calendario.php'), 'ti-calendar', 'btn-outline-secondary') . Menu::button('Painel', Menu::url('/front/painel.php'), 'ti-chart-dots-3', 'btn-outline-secondary')
    . (Evento::canCreate() ? Menu::button('Novo evento', Evento::getFormURL()) : '')) . "</div>";
if (GlpiPlugin\Gestaoeventos\Acesso::restricted()) {
    GlpiPlugin\Gestaoeventos\Acesso::restrictedList();
} else {
    Search::show(Evento::class);
}
Html::footer();
