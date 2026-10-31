<?php

include('../../../inc/includes.php');

use GlpiPlugin\Controleequipamentos\Devolucao;
use GlpiPlugin\Controleequipamentos\Entrega;
use GlpiPlugin\Controleequipamentos\Menu;
use GlpiPlugin\Controleequipamentos\Painel;

Session::checkRight(Menu::RIGHT, READ);

$aba = ($_GET['aba'] ?? 'entrega') === 'devolucao' ? 'devolucao' : 'entrega';
$classe = $aba === 'devolucao' ? Devolucao::class : Entrega::class;
$meses = in_array((int) ($_GET['meses'] ?? 6), [3, 6, 12, 24], true) ? (int) $_GET['meses'] : 6;

Html::requireJs('charts');
Menu::header('Painel de equipamentos', 'painel');

$filtro = "<form method='get' class='d-flex gap-2 align-items-center m-0'><input type='hidden' name='aba' value='" . Menu::e($aba) . "'>"
    . "<label class='small text-secondary' for='ce-meses'>Concluídos nos últimos</label><select class='form-select form-select-sm' id='ce-meses' name='meses' onchange='this.form.submit()'>";
foreach ([3, 6, 12, 24] as $m) {
    $filtro .= "<option value='$m'" . ($m === $meses ? ' selected' : '') . ">$m meses</option>";
}
$filtro .= "</select></form>" . Menu::button('Ciclos', Menu::url('/front/controle.php?aba=' . $aba), 'ti-list', 'btn-outline-secondary');
echo Menu::title($aba === 'devolucao' ? 'Painel da devolução' : 'Painel da entrega', 'Indicadores, prazos (SLO) por área, grupo e responsável.', $filtro);
echo Menu::abas($aba, '/front/painel.php');

Painel::show($classe, $meses);

Menu::footer();
