<?php

use GlpiPlugin\Datacenter360\Menu;

Session::checkRight(Menu::$rightname, READ);

Html::header('DataCenter 360 · Topologia', '', Menu::SECTOR, 'topologia');
Menu::showFrame('topologia.html');
Html::footer();
