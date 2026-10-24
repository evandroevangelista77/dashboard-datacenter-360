<?php

use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Reembolso;

Session::checkLoginUser();
if (!Reembolso::canView()) {
    Html::displayRightError();
}

Html::header(Reembolso::getTypeName(2), '', Menu::SECTOR, Menu::item(Reembolso::class));
echo Menu::css();
Search::show(Reembolso::class);
Html::footer();
