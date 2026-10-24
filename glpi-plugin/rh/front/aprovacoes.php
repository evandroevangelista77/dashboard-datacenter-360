<?php

use GlpiPlugin\Rh\Aprovacoes;
use GlpiPlugin\Rh\Feria;
use GlpiPlugin\Rh\Menu;
use GlpiPlugin\Rh\Vaga;

Session::checkLoginUser();
if (!Vaga::canView() && !Feria::canView()) {
    Html::displayRightError();
}
Menu::header(Aprovacoes::getTypeName(), 'aprovacoes');
Aprovacoes::show();
Html::footer();
