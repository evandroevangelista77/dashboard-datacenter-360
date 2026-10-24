<?php

use GlpiPlugin\Rh\Feria;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
if (!Feria::canView()) {
    Html::displayRightError();
}
Menu::header('Calendário de férias', 'calendario');
if (($_GET['view'] ?? '') === 'ano') {
    $year = (int) ($_GET['year'] ?? date('Y'));
    Feria::calendarYear($year >= 2000 && $year <= 2100 ? $year : (int) date('Y'));
} else {
    Feria::calendar(preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m'));
}
Html::footer();
