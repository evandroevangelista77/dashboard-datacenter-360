<?php

/** Alçadas, regras gerais, categorias financeiras, tarifas de viagem e a integração Uber. */

use GlpiPlugin\Gestaoeventos\Acesso;
use GlpiPlugin\Gestaoeventos\Alcada;
use GlpiPlugin\Gestaoeventos\Alerta;
use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Menu;
use GlpiPlugin\Gestaoeventos\Parametro;
use GlpiPlugin\Gestaoeventos\Registro;
use GlpiPlugin\Gestaoeventos\Uber;
use GlpiPlugin\Gestaoeventos\Viagem;

Session::checkRight('config', UPDATE);

$tabs = ['alcadas' => ['Alçadas', 'ti-signature'], 'regras' => ['Regras gerais', 'ti-adjustments'], 'categorias' => ['Categorias financeiras', 'ti-tags'], 'tarifas' => ['Tarifas de viagem', 'ti-car'], 'uber' => ['Integração Uber', 'ti-brand-uber'], 'avisos' => ['Avisos e contagem', 'ti-bell-ringing'], 'acesso' => ['Acesso por perfil', 'ti-lock-access']];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'alcadas';
$ctx = 'plugin:gestaoeventos';

if (isset($_POST['save_alcadas'])) {
    Alcada::saveConfig($_POST);
    Session::addMessageAfterRedirect('Alçadas salvas. Valem para os pedidos de aprovação novos.');
    Html::back();
} elseif (isset($_POST['save_avisos'])) {
    Alerta::save(0, (array) ($_POST['al'] ?? []));
    Session::addMessageAfterRedirect('Regra padrão dos avisos salva. Vale para os eventos sem regra própria.');
    Html::back();
} elseif (isset($_POST['save_avisos_dash'])) {
    Alerta::saveDash($_POST);
    Session::addMessageAfterRedirect('Configuração do pop-up de próximos eventos salva.');
    Html::back();
} elseif (isset($_POST['save_regras'])) {
    Config::setConfigurationValues($ctx, [
        'tickets' => empty($_POST['tickets']) ? '0' : '1',
        'lead_days' => (string) max(0, min(365, (int) ($_POST['lead_days'] ?? 60))),
        'lead_mode' => in_array($_POST['lead_mode'] ?? '', ['exception', 'block', 'warn'], true) ? $_POST['lead_mode'] : 'exception',
        'tolerance_pct' => (string) max(0, min(100, (float) str_replace(',', '.', (string) ($_POST['tolerance_pct'] ?? 5)))),
        'allow_self_approval' => empty($_POST['allow_self_approval']) ? '0' : '1',
        'dicas_on' => empty($_POST['dicas_on']) ? '0' : '1',
        'sharepoint_base' => rtrim(trim((string) ($_POST['sharepoint_base'] ?? '')), '/'),
    ]);
    Session::addMessageAfterRedirect('Regras salvas.');
    Html::back();
} elseif (isset($_POST['save_categorias'])) {
    $lines = array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['categories'] ?? ''))));
    Config::setConfigurationValues($ctx, ['categories' => implode("\n", $lines) ?: Registro::DEFAULT_CATEGORIES]);
    Session::addMessageAfterRedirect('Categorias salvas.');
    Html::back();
} elseif (isset($_POST['save_tarifas'])) {
    Session::addMessageAfterRedirect(Parametro::saveNew($_POST) ? 'Nova versão dos parâmetros criada. As simulações antigas mantêm os parâmetros que usaram.' : 'Nada mudou: a versão vigente continua.');
    Html::back();
} elseif (isset($_POST['save_uber']) || isset($_POST['test_uber'])) {
    $values = [
        'uber_mode' => in_array($_POST['uber_mode'] ?? '', ['off', 'mock', 'live'], true) ? $_POST['uber_mode'] : 'off',
        'uber_env' => ($_POST['uber_env'] ?? '') === 'sandbox' ? 'sandbox' : 'production',
        'uber_authorized' => empty($_POST['uber_authorized']) ? '0' : '1',
        'uber_client_id' => mb_substr(trim((string) ($_POST['uber_client_id'] ?? '')), 0, 200),
        'uber_timeout' => (string) max(2, min(30, (int) ($_POST['uber_timeout'] ?? 10))),
        'uber_rate_per_min' => (string) max(1, min(600, (int) ($_POST['uber_rate_per_min'] ?? 20))),
        'uber_fail_limit' => (string) max(1, min(50, (int) ($_POST['uber_fail_limit'] ?? 5))),
        'uber_policy' => in_array($_POST['uber_policy'] ?? '', ['min', 'mid', 'max'], true) ? $_POST['uber_policy'] : 'max',
        'uber_open_until' => '0',
    ];
    Config::setConfigurationValues($ctx, $values);
    if (trim((string) ($_POST['uber_client_secret'] ?? '')) !== '') {
        Uber::saveSecret('uber_client_secret', trim((string) $_POST['uber_client_secret']));
        Config::setConfigurationValues($ctx, ['uber_token' => '', 'uber_token_exp' => '0']);
    }
    if (isset($_POST['test_uber'])) {
        [$state, $msg] = Uber::test();
        Session::addMessageAfterRedirect(htmlspecialchars(['conectado' => 'Conectado: ', 'mock' => 'Simulado: ', 'off' => 'Desligado: '][$state] ?? ($state === 'sem_autorizacao' ? 'Sem autorização: ' : 'Erro: ') . '') . htmlspecialchars($msg), false, $state === 'conectado' || $state === 'mock' ? INFO : WARNING);
    } else {
        Session::addMessageAfterRedirect('Configuração da integração Uber salva.');
    }
    Html::back();
}

if (!defined('GE_ONLY_BODY')) {
    Menu::header('Alçadas e regras', 'admin');
}
echo "<div class='ge'>" . Menu::title('Alçadas e regras', 'admin');
echo "<ul class='nav nav-tabs mb-3'>";
foreach ($tabs as $k => [$label, $icon]) {
    echo "<li class='nav-item'><a class='nav-link" . ($k === $tab ? ' active' : '') . "' href='?tab=$k'><i class='ti $icon me-1'></i>" . Menu::e($label) . "</a></li>";
}
echo "</ul>";
$cfg = Config::getConfigurationValues($ctx);
switch ($tab) {
    case 'alcadas':
        Alcada::showConfig();
        break;
    case 'regras':
        echo "<form method='post' action='" . Menu::e(Menu::url('/front/admin.php')) . "?tab=regras' class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-adjustments me-2'></i>Regras gerais</h3></div><div class='card-body row g-3'>"
            . "<div class='col-md-3'><label class='form-label'>Antecedência mínima (dias)</label><input type='number' min='0' class='form-control' name='lead_days' value='" . (int) ($cfg['lead_days'] ?? 60) . "'><div class='form-hint'>Entre o envio do briefing e o início do evento (RN-01). 0 desliga. Não vale para agenda comercial.</div></div>"
            . "<div class='col-md-4'><label class='form-label'>Quando a antecedência é menor</label><select class='form-select' name='lead_mode'>" . Menu::options(['exception' => 'Exigir exceção justificada e aprovada', 'block' => 'Bloquear o envio', 'warn' => 'Só avisar'], $cfg['lead_mode'] ?? 'exception') . "</select></div>"
            . "<div class='col-md-3'><label class='form-label'>Tolerância do EAC sobre o Budget (%)</label><input class='form-control' name='tolerance_pct' value='" . Menu::e($cfg['tolerance_pct'] ?? '5') . "'><div class='form-hint'>Acima disso a aprovação do orçamento é reaberta (nova versão).</div></div>"
            . "<div class='col-md-6'><label class='form-label'>Endereço-base do SharePoint dos eventos</label><input class='form-control' name='sharepoint_base' placeholder='https://empresa.sharepoint.com/sites/marketing/Eventos' value='" . Menu::e($cfg['sharepoint_base'] ?? '') . "'></div>"
            . "<div class='col-md-6 d-flex flex-column justify-content-end gap-2'><label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='tickets' value='1'" . (($cfg['tickets'] ?? '1') ? ' checked' : '') . "><span class='form-check-label'>Abrir um chamado para o grupo da etapa quando ela começar (e resolver quando terminar)</span></label>"
            . "<label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='dicas_on' value='1'" . (($cfg['dicas_on'] ?? '1') === '1' ? ' checked' : '') . "><span class='form-check-label'>Mostrar o assistente \"O que fazer agora\" e as dicas em cada aba</span></label>"
            . "<label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='allow_self_approval' value='1'" . (($cfg['allow_self_approval'] ?? '0') === '1' ? ' checked' : '') . "><span class='form-check-label'>Permitir que o solicitante aprove o próprio pedido (não recomendado)</span></label></div>"
            . "</div><div class='card-footer'><button class='btn btn-primary' name='save_regras' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div>";
        Html::closeForm();
        break;
    case 'categorias':
        echo "<form method='post' action='" . Menu::e(Menu::url('/front/admin.php')) . "?tab=categorias' class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-tags me-2'></i>Categorias de receita e despesa</h3></div><div class='card-body'>"
            . "<div class='small text-secondary mb-2'>Uma por linha. Use <code>Categoria &gt; Subcategoria</code>. A lista vem dos modelos de controle de custos (materiais, locação e serviços, RDV, viagens, receita de patrocínio) e pode ser ajustada sem alterar o código.</div>"
            . "<textarea class='form-control' rows='16' name='categories'>" . Menu::e(implode("\n", array_keys(Registro::categories()))) . "</textarea></div>"
            . "<div class='card-footer'><button class='btn btn-primary' name='save_categorias' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div>";
        Html::closeForm();
        break;
    case 'tarifas':
        Parametro::showConfig();
        break;
    case 'uber':
        Viagem::showUberConfig();
        break;
    case 'avisos':
        Alerta::showAdmin();
        break;
    case 'acesso':
        Acesso::overview();
        break;
}
echo "</div>";
if (!defined('GE_ONLY_BODY')) {
    Html::footer();
}
