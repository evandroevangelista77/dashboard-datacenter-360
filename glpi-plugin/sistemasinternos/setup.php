<?php

/**
 * Plugin "Sistemas Internos 360" para GLPI 12.
 *
 * Painel do departamento (Zabbix × GLPI), cadastro de hosts do Zabbix pelo GLPI
 * (incluir, editar, desativar e excluir, só nos grupos liberados para o perfil,
 * por uma conta de serviço da API do Zabbix, com auditoria) e alertas com
 * sinalizador, som, voz e pop-up no rodapé, configuráveis por tipo de alerta.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Sistemasinternos\Host;
use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Painel;
use GlpiPlugin\Sistemasinternos\ProfileTab;
use GlpiPlugin\Sistemasinternos\Settings;

define('PLUGIN_SISTEMASINTERNOS_VERSION', '1.1.0');
define('PLUGIN_SISTEMASINTERNOS_MIN_GLPI', '12.0.0');
define('PLUGIN_SISTEMASINTERNOS_MAX_GLPI', '12.0.99');

function plugin_init_sistemasinternos(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Painel::class);
    Plugin::registerClass(Host::class);
    Plugin::registerClass(Settings::class);
    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);

    // sessões abertas antes da instalação ainda não têm os direitos do plugin
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $missing = array_filter(Menu::RIGHTS, fn ($r) => !isset($_SESSION['glpiactiveprofile'][$r]));
        if ($missing) {
            $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], $missing);
            foreach ($missing as $r) {
                $_SESSION['glpiactiveprofile'][$r] = (int) ($rights[$r] ?? 0);
            }
        }
    }

    // seção "Sistemas Internos" no menu lateral, logo abaixo de Dashboard
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['sistemasinternos']     = [Menu::SECTOR => [Painel::class, Host::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['sistemasinternos'] = 'plugin_sistemasinternos_redefine_menus';

    // sinalizador, som, voz e pop-up dos alertas em todas as telas do GLPI
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['sistemasinternos']        = ['css/sistemasinternos.css'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['sistemasinternos'] = ['js/alertas.js'];

    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['sistemasinternos'] = 'front/config.form.php';

    // token da conta de serviço do Zabbix gravado criptografado (GLPIKey)
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['sistemasinternos'] = ['zabbix_token'];
}

/**
 * Aplicado a cada desenho do menu: título e ícone da seção, logo abaixo de
 * Dashboard (DataCenter 360), mesmo com o menu guardado na sessão antes do plugin.
 */
function plugin_sistemasinternos_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'Sistemas Internos',
        'icon'    => Menu::ICON,
        'types'   => [Painel::class, Host::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    if (!isset($menu['dashboard'])) {
        return $section + $menu;
    }
    $out = [];
    foreach ($menu as $key => $value) {
        $out[$key] = $value;
        if ($key === 'dashboard') {
            $out += $section;
        }
    }
    return $out;
}

function plugin_version_sistemasinternos(): array
{
    return [
        'name'         => 'Sistemas Internos 360',
        'version'      => PLUGIN_SISTEMASINTERNOS_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_SISTEMASINTERNOS_MIN_GLPI, 'max' => PLUGIN_SISTEMASINTERNOS_MAX_GLPI],
            'php'  => ['exts' => ['curl' => ['required' => true]]],
        ],
    ];
}

function plugin_sistemasinternos_check_prerequisites(): bool
{
    return true;
}

function plugin_sistemasinternos_check_config($verbose = false): bool
{
    return true;
}
