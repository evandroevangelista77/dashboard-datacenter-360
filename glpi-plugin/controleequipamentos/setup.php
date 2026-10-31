<?php

/**
 * Plugin "Controle de Equipamentos" para GLPI 11 e 12.
 *
 * Uma aba com duas frentes:
 * - Entrega de equipamento: fluxo de 8 etapas para novos colaboradores, com chamado no GLPI;
 * - Devolução de equipamento: formulário de solicitação de transporte para desligados.
 * Cada frente tem um painel com indicadores em texto e tabelas acessíveis.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Controleequipamentos\Menu;
use GlpiPlugin\Controleequipamentos\ProfileTab;

define('PLUGIN_CONTROLEEQUIPAMENTOS_VERSION', '1.3.0');
define('PLUGIN_CONTROLEEQUIPAMENTOS_MIN_GLPI', '11.0.0');
define('PLUGIN_CONTROLEEQUIPAMENTOS_MAX_GLPI', '12.0.99');

function plugin_init_controleequipamentos(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Menu::RIGHT])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::RIGHT]);
        $_SESSION['glpiactiveprofile'][Menu::RIGHT] = (int) ($rights[Menu::RIGHT] ?? 0);
    }

    // literal: a constante não existe em todas as versões (GLPI 12 não a define)
    $PLUGIN_HOOKS['csrf_compliant']['controleequipamentos'] = true;
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['controleequipamentos'] = 'plugin_controleequipamentos_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['controleequipamentos']        = ['css/controleequipamentos.css'];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['controleequipamentos']    = 'front/config.php';
}

/** Seção "Controle de Equipamentos" no menu lateral, logo abaixo de Ciclo Financeiro (ou de Financeiro). */
function plugin_controleequipamentos_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'Controle de Equipamentos',
        'icon'    => 'ti ti-devices',
        'types'   => [],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    foreach (['ciclo', 'financeiro', 'pmo', 'dashboard'] as $after) {
        if (isset($menu[$after])) {
            $out = [];
            foreach ($menu as $key => $value) {
                $out[$key] = $value;
                if ($key === $after) {
                    $out += $section;
                }
            }
            return $out;
        }
    }
    return $section + $menu;
}

function plugin_version_controleequipamentos(): array
{
    return [
        'name'         => 'Controle de Equipamentos',
        'version'      => PLUGIN_CONTROLEEQUIPAMENTOS_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_CONTROLEEQUIPAMENTOS_MIN_GLPI, 'max' => PLUGIN_CONTROLEEQUIPAMENTOS_MAX_GLPI],
        ],
    ];
}

function plugin_controleequipamentos_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_CONTROLEEQUIPAMENTOS_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_CONTROLEEQUIPAMENTOS_MAX_GLPI, 'gt')) {
        echo 'Requer GLPI ' . PLUGIN_CONTROLEEQUIPAMENTOS_MIN_GLPI . ' a ' . PLUGIN_CONTROLEEQUIPAMENTOS_MAX_GLPI;
        return false;
    }
    return true;
}

function plugin_controleequipamentos_check_config(bool $verbose = false): bool
{
    return true;
}
