<?php

use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\View;

Session::checkRight(Menu::$rightname, READ);

Html::header('PMO - Meu trabalho', '', Menu::SECTOR, 'mywork');
View::showMyWork();
Html::footer();
