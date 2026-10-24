<?php

use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\View;

Session::checkRight(Menu::$rightname, READ);

View::requireAssets('portfolio');
Html::header('PMO - Portfólio', '', Menu::SECTOR, 'portfolio');
View::showPortfolio();
Html::footer();
