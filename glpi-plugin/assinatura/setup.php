<?php

/**
 * Plugin "Assinatura digital (LibreSign)" para GLPI 12.
 *
 * Aba "Assinatura digital" no chamado: envia o PDF do chamado (plugin PDF) ou um
 * PDF anexado para assinatura no LibreSign (Nextcloud), acompanha o status e anexa
 * o PDF assinado ao chamado.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Assinatura\TicketTab;

define('PLUGIN_ASSINATURA_VERSION', '1.0.0');
define('PLUGIN_ASSINATURA_MIN_GLPI', '12.0.0');
define('PLUGIN_ASSINATURA_MAX_GLPI', '12.0.99');

function plugin_init_assinatura(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(TicketTab::class, ['addtabon' => [Ticket::class]]);

    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['assinatura'] = 'front/config.form.php';

    // senha de aplicativo do Nextcloud gravada criptografada (GLPIKey)
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['assinatura'] = ['app_password'];
}

function plugin_version_assinatura(): array
{
    return [
        'name'         => 'Assinatura digital (LibreSign)',
        'version'      => PLUGIN_ASSINATURA_VERSION,
        'author'       => 'Evandro Evangelista',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => [
                'min'     => PLUGIN_ASSINATURA_MIN_GLPI,
                'max'     => PLUGIN_ASSINATURA_MAX_GLPI,
                'plugins' => ['pdf'],
            ],
        ],
    ];
}

function plugin_assinatura_check_prerequisites(): bool
{
    if (!function_exists('curl_init')) {
        echo 'A extensão PHP curl é necessária.';
        return false;
    }
    return true;
}

function plugin_assinatura_check_config($verbose = false): bool
{
    return true;
}
