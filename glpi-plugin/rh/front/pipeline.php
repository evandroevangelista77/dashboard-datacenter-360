<?php

use GlpiPlugin\Rh\Candidatura;
use GlpiPlugin\Rh\Menu;
use GlpiPlugin\Rh\Vaga;

Session::checkLoginUser();
$vaga = new Vaga();
if (!$vaga->getFromDB((int) ($_GET['vaga'] ?? 0)) || !$vaga->canViewItem()) {
    Html::displayRightError();
}
Menu::header('Funil de seleção - ' . $vaga->fields['name'], 'vaga');
$boot = ['ajax' => Menu::url('/front/ajax.php'), 'vaga' => (int) $vaga->getID(), 'vagaUrl' => $vaga->getLinkURL(), 'rh' => Vaga::isRH()];
echo "<div class='rh'><div id='rh-pipeline' class='rh-loading'><span class='spinner-border spinner-border-sm me-2'></span>Carregando o funil…</div></div>";
echo "<script type='application/json' id='rh-boot'>" . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
echo Menu::script('pipeline.js');
Html::footer();
