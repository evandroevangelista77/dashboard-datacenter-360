<?php

use GlpiPlugin\Datacenter360\Menu;
use GlpiPlugin\Datacenter360\Settings;

if (!Settings::canView()) {
    Html::displayRightError();
}

Html::header('NOC 360', '', Menu::SECTOR, 'datacenter360');
Menu::showFrame('index.html');
Html::footer();
