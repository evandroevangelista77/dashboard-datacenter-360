<?php

use GlpiPlugin\Datacenter360\Menu;
use GlpiPlugin\Datacenter360\Settings;

if (!Settings::canView()) {
    Html::displayRightError();
}

Html::header('NOC 360 · Topologia', '', Menu::SECTOR, 'topologia');
Menu::showFrame('topologia.html');
Html::footer();
