<?php

/**
 * Plugin "Operacional 360" para GLPI 11 e 12.
 *
 * Painel operacional por entidade: os chamados da entidade e das filhas (GLPI) e os
 * alertas do Zabbix dos host groups ligados a ela, com o mesmo layout e tema do NOC 360.
 * A configuração (entidade → host group → severidades) é só para o administrador.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Operacional360\Menu;
use GlpiPlugin\Operacional360\Settings;

define('PLUGIN_OPERACIONAL360_VERSION', '1.0.0');
define('PLUGIN_OPERACIONAL360_MIN_GLPI', '11.0.0');
define('PLUGIN_OPERACIONAL360_MAX_GLPI', '12.0.99');

function plugin_init_operacional360(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['operacional360'] = true;

    // seção "Operacional" no menu lateral, no topo (também para menus guardados antes do plugin)
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['operacional360']     = [Menu::SECTOR => [Menu::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['operacional360'] = 'plugin_operacional360_redefine_menus';

    // configuração (Configurar → Plugins), só para o administrador; token do Zabbix cifrado no banco
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['operacional360']        = 'front/config.form.php';
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['operacional360']    = ['zabbix_token'];
}

/**
 * Aplicado a cada desenho do menu: garante a seção "Operacional" com o painel
 * (e a configuração, quando o perfil pode configurar).
 */
function plugin_operacional360_redefine_menus(array $menu): array
{
    unset($menu[Menu::SECTOR]);
    $content = Menu::getMenuContent();
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => $content['title'],
        'icon'    => Menu::getIcon(),
        'types'   => [Menu::class],
        'content' => $content,
        'default' => $content['operacional360']['page'],
    ]];
    return $section + $menu;
}

function plugin_version_operacional360(): array
{
    return [
        'name'         => 'Operacional 360',
        'version'      => PLUGIN_OPERACIONAL360_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_OPERACIONAL360_MIN_GLPI,
                'max' => PLUGIN_OPERACIONAL360_MAX_GLPI,
            ],
            'php'  => ['exts' => ['curl' => ['required' => true], 'json' => ['required' => true]]],
        ],
    ];
}

function plugin_operacional360_check_prerequisites(): bool
{
    if (!extension_loaded('curl')) {
        echo 'A extensão curl do PHP é necessária.';
        return false;
    }
    return true;
}

function plugin_operacional360_check_config($verbose = false): bool
{
    return true;
}
