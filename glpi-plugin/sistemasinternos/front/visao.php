<?php

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;

Session::checkRight(Menu::RIGHT_PAINEL, READ);

$topo = !empty($_GET['topologia']);
Html::header('Sistemas Internos 360', '', Menu::SECTOR, $topo ? 'topologia' : 'visao');
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$src = Menu::url('/front/si.php') . ($topo ? '?topologia=1' : '?p=/');

$actions = "<a class='btn btn-light' href='" . $e($src) . "' target='_blank' rel='noopener'><i class='ti ti-arrows-maximize me-1'></i>Tela cheia</a>"
    . ($topo ? "<a class='btn btn-ghost-light' href='" . $e(Menu::url('/front/visao.php')) . "'><i class='ti ti-layout-dashboard me-1'></i>Visão 360</a>"
        : "<a class='btn btn-ghost-light' href='" . $e(Menu::url('/front/visao.php?topologia=1')) . "'><i class='ti ti-topology-star-3 me-1'></i>Topologia</a>");
echo Menu::open($topo ? 'Topologia' : 'Visão 360', $topo ? 'topologia' : 'visao', $actions);

if (!Settings::isConfigured()) {
    echo Menu::notConfigured();
} else {
    echo "<iframe class='si360-frame' src='" . $e($src) . "' title='Sistemas Internos 360' allow='fullscreen; autoplay' allowfullscreen></iframe>";
}
echo Menu::close();
Html::footer();
