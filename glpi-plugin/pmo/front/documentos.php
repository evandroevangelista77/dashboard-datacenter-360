<?php

use GlpiPlugin\Pmo\Biblioteca;
use GlpiPlugin\Pmo\Docs;
use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\View;

Session::checkRight(Menu::$rightname, READ);

Html::header('PMO - Documentos', '', Menu::SECTOR, 'documentos');
echo View::css();
Biblioteca::page(Docs::sources(), $_GET, "<div class='pmo'><div class='pmo-head'><div><div class='pmo-crumb'><i class='ti ti-briefcase me-1'></i>PMO</div><h2 class='pmo-title'>Documentos dos projetos</h2>"
    . View::subtitle('documentos') . "</div></div></div>");
echo View::script('drawer.js') . View::script('biblioteca.js');
Html::footer();
