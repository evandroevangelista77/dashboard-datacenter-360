<?php

use GlpiPlugin\Ciclo\Ciclo;
use GlpiPlugin\Ciclo\Documentos;
use GlpiPlugin\Ciclo\Menu;

Session::checkRight(Ciclo::$rightname, READ);

Menu::header('Documentos dos Ciclos', 'documentos');
Documentos::page($_GET);
Html::footer();
