<?php

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;
use GlpiPlugin\Sistemasinternos\Wazuh;

Session::checkRight(Menu::RIGHT_PAINEL, READ);
Html::requireJs('charts');
Html::header('Vulnerabilidades · Sistemas Internos 360', '', Menu::SECTOR, 'vulnerabilidades');
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

$actions = "<button type='button' class='btn btn-ghost-light' data-wz-refresh><i class='ti ti-refresh me-1'></i>Atualizar</button>"
    . "<a class='btn btn-light' href='" . $e(Menu::url('/front/relatorio.php?view=1')) . "' target='_blank' rel='noopener' data-wz-pdf data-base='" . $e(Menu::url('/front/relatorio.php?view=1')) . "' title='Relatório executivo completo para a diretoria'><i class='ti ti-file-type-pdf me-1'></i>Relatório PDF</a>"
    . "<a class='btn btn-ghost-light' href='" . $e(Menu::url('/front/seguranca.php')) . "'><i class='ti ti-shield-lock me-1'></i>Segurança</a>";
echo Menu::open('Vulnerabilidades', 'vulnerabilidades', $actions);

if (!Wazuh::isConfigured()) {
    $link = Session::haveRight(Menu::RIGHT_CONFIG, UPDATE)
        ? " <a href='" . $e(Menu::url('/front/config.form.php?tab=wazuh')) . "'>Configurar agora</a>." : ' Peça ao administrador do GLPI para configurar.';
    echo "<div class='alert alert-warning'><i class='ti ti-plug-x me-2'></i>A conexão com o Wazuh ainda não foi configurada.$link</div>";
} else {
    $tabs = ['exec' => ['Executiva', 'ti-presentation-analytics'], 'assets' => ['Por ativo', 'ti-server-2'], 'ops' => ['Operacional', 'ti-list-check'], 'epss' => ['EPSS', 'ti-target-arrow']];
    echo "<ul class='nav nav-tabs si360-wztabs mb-3' data-wz-tabs>";
    foreach ($tabs as $k => [$label, $icon]) {
        echo "<li class='nav-item'><a href='#wz-$k' class='nav-link" . ($k === 'exec' ? ' active' : '') . "' data-wz-tab='$k'><i class='ti $icon me-1'></i>$label</a></li>";
    }
    echo "</ul>"
        . "<div id='wz-root' data-wz='vuln' data-api='" . $e(Menu::url('/front/wazuh.php')) . "' data-ticket='" . (Session::haveRight('ticket', CREATE) ? '1' : '0') . "' data-minlevel='" . $e(Settings::get('wazuh_min_level') ?: '10') . "'>"
        . "<div class='si360-wzpane' id='wz-exec'></div><div class='si360-wzpane' id='wz-assets' hidden></div><div class='si360-wzpane' id='wz-ops' hidden></div><div class='si360-wzpane' id='wz-epss' hidden></div></div>"
        . "<div class='si360-foot mt-3'><i class='ti ti-info-circle me-1'></i>Dados do Wazuh (Indexer), atualizados a cada minuto. O histórico e o tempo de correção são guardados pelo GLPI a cada hora.</div>";
}
echo Menu::close();
if (Wazuh::isConfigured()) {
    echo Html::script(Menu::url('/js/wazuh.js'), ['version' => Menu::fileVersion('js/wazuh.js')], false);
}
Html::footer();
