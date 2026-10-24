<?php

use GlpiPlugin\Reembolso\Dashboard;
use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Reembolso;

Session::checkLoginUser();
if (!Reembolso::canView()) {
    Html::displayRightError();
}

Html::requireJs('charts');
Html::header(Dashboard::getTypeName(), '', Menu::SECTOR, Menu::item(Dashboard::class));
Dashboard::show($_GET);
Html::footer();
