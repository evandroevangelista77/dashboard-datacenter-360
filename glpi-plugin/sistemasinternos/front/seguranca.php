<?php

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;
use GlpiPlugin\Sistemasinternos\Wazuh;
use GlpiPlugin\Sistemasinternos\WazuhSec;

Session::checkRight(Menu::RIGHT_PAINEL, READ);
Html::requireJs('charts');
Html::header('Segurança · Sistemas Internos 360', '', Menu::SECTOR, 'seguranca');
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

$actions = "<button type='button' class='btn btn-ghost-light' data-wz-refresh><i class='ti ti-refresh me-1'></i>Atualizar</button>"
    . "<a class='btn btn-light' href='" . $e(Menu::url('/front/vulnerabilidades.php')) . "'><i class='ti ti-bug me-1'></i>Vulnerabilidades</a>";
echo Menu::open('Segurança', 'seguranca', $actions);

if (!Wazuh::isConfigured()) {
    $link = Session::haveRight(Menu::RIGHT_CONFIG, UPDATE)
        ? " <a href='" . $e(Menu::url('/front/config.form.php?tab=wazuh')) . "'>Configurar agora</a>." : ' Peça ao administrador do GLPI para configurar.';
    echo "<div class='alert alert-warning'><i class='ti ti-plug-x me-2'></i>A conexão com o Wazuh ainda não foi configurada.$link</div>";
} else {
    $tabs = ['geral' => ['Visão geral', 'ti-shield-check'], 'eventos' => ['Eventos', 'ti-bolt'], 'mitre' => ['MITRE ATT&CK', 'ti-target-arrow'], 'conformidade' => ['Conformidade', 'ti-certificate'],
        'fim' => ['Integridade (FIM)', 'ti-file-search'], 'sca' => ['Configuração (SCA)', 'ti-checklist'], 'acesso' => ['Acesso e autenticação', 'ti-user-shield'], 'agentes' => ['Agentes e inventário', 'ti-server-cog']];
    $periods = '';
    foreach (WazuhSec::PERIODS as $k => [$label]) {
        $periods .= "<option value='$k'" . ($k === '24h' ? ' selected' : '') . ">Últimas $label</option>";
    }
    echo "<div class='d-flex flex-wrap gap-2 align-items-center mb-3 si360-wzbar'>"
        . "<select class='form-select form-select-sm w-auto' data-wz-period>$periods</select>"
        . "<select class='form-select form-select-sm w-auto' data-wz-agent><option value=''>Todos os agentes</option></select>"
        . "<span class='text-secondary small ms-auto' data-wz-stamp></span></div>";
    echo "<ul class='nav nav-tabs si360-wztabs mb-3' data-wz-tabs>";
    foreach ($tabs as $k => [$label, $icon]) {
        echo "<li class='nav-item'><a href='#wz-$k' class='nav-link" . ($k === 'geral' ? ' active' : '') . "' data-wz-tab='$k'><i class='ti $icon me-1'></i>" . $e($label) . "</a></li>";
    }
    echo "</ul><div id='wz-root' data-wz='sec' data-api='" . $e(Menu::url('/front/wazuh.php')) . "'>";
    foreach (array_keys($tabs) as $k) {
        echo "<div class='si360-wzpane' id='wz-$k'" . ($k === 'geral' ? '' : ' hidden') . "></div>";
    }
    echo "</div><div class='si360-foot mt-3'><i class='ti ti-pointer me-1'></i>Clique num gráfico, regra ou linha para ver os eventos por trás. Dados do Wazuh (Indexer) em tempo quase real; a página se atualiza a cada 2 minutos.</div>";
}
echo Menu::close();
if (Wazuh::isConfigured()) {
    echo Html::script(Menu::url('/js/wazuh.js'), ['version' => Menu::fileVersion('js/wazuh.js')], false);
}
Html::footer();
