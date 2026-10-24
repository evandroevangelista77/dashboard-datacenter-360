<?php

use GlpiPlugin\Reembolso\Export;
use GlpiPlugin\Reembolso\Reembolso;
use GlpiPlugin\Reembolso\Report;

Session::checkLoginUser();
if (!Reembolso::canView()) {
    Html::displayRightError();
}

Export::send(Report::filters($_GET));
