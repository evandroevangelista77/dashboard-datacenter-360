<?php

use GlpiPlugin\Rh\Dashboard;
use GlpiPlugin\Rh\Menu;

Session::checkLoginUser();
if (!Dashboard::canView()) {
    Html::displayRightError();
}
Html::requireJs('charts');
Menu::header(Dashboard::getTypeName(), 'dashboard');
Dashboard::show($_GET);
Html::footer();
