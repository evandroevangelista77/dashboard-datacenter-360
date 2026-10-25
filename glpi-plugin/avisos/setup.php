<?php

/**
 * Plugin "Avisos" para GLPI 12.
 *
 * Central de avisos dos plugins RH, PMO e Reembolso: sino no topo da tela,
 * pop-up para quem foi avisado, página "Meus avisos" e envio para canais do
 * Microsoft Teams por webhook (um canal por plugin).
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Avisos\Aviso;
use GlpiPlugin\Avisos\Canal;

define('PLUGIN_AVISOS_VERSION', '1.1.1');
define('PLUGIN_AVISOS_MIN_GLPI', '12.0.0');
define('PLUGIN_AVISOS_MAX_GLPI', '12.0.99');

function plugin_init_avisos(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Aviso::class);
    Plugin::registerClass(Canal::class);

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['avisos']        = ['css/avisos.css'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['avisos'] = ['js/avisos.js'];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['avisos']    = 'front/config.php';
}

function plugin_version_avisos(): array
{
    return [
        'name'         => 'Avisos',
        'version'      => PLUGIN_AVISOS_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_AVISOS_MIN_GLPI, 'max' => PLUGIN_AVISOS_MAX_GLPI],
        ],
    ];
}

function plugin_avisos_check_prerequisites(): bool
{
    return true;
}

function plugin_avisos_check_config($verbose = false): bool
{
    return true;
}
