<?php

use GlpiPlugin\Ciclo\Ciclo;
use GlpiPlugin\Ciclo\Menu;
use GlpiPlugin\Ciclo\Painel;

Session::checkRight(Ciclo::$rightname, READ);

Html::requireJs('charts');
Menu::header('Painel do Ciclo', 'painel');
Painel::show($_GET);
Html::footer();
