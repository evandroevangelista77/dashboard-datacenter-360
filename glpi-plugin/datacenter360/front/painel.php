<?php

use GlpiPlugin\Datacenter360\Menu;

Session::checkRight(Menu::$rightname, READ);

Html::header('NOC 360', '', Menu::SECTOR, 'datacenter360');
Menu::showFrame('index.html');
Html::footer();
