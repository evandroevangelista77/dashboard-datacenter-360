<?php

use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Reembolso;

Session::checkLoginUser();
if (!Reembolso::canView()) {
    Html::displayRightError();
}

Html::header(Reembolso::getTypeName(2), '', Menu::SECTOR, Menu::item(Reembolso::class));
echo Menu::css();
$actions = "<a class='btn btn-outline-secondary' href='" . htmlspecialchars(Menu::url('/front/dashboard.php'), ENT_QUOTES) . "'><i class='ti ti-chart-bar me-1'></i>Dashboard</a>"
    . (Reembolso::canCreate() ? "<a class='btn btn-primary' href='" . htmlspecialchars(Reembolso::getFormURL(), ENT_QUOTES) . "'><i class='ti ti-plus me-1'></i>Nova solicitação</a>" : '');
echo "<div class='rb'>" . Menu::title('Reembolsos', 'reembolso', $actions) . "</div>";
Search::show(Reembolso::class);
Html::footer();
