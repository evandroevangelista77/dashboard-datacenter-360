<?php

/**
 * Plugin "RH" para GLPI 12.
 *
 * Recrutamento e seleção (requisição de vaga com aprovação em dois níveis e
 * linha do tempo, banco de candidatos e funil visual por vaga), admissão com
 * checklist e chamado de TI, férias com aprovação e calendário, "Minhas
 * aprovações", avisos por e-mail e dashboard de RH.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Rh\Admissao;
use GlpiPlugin\Rh\Aprovacoes;
use GlpiPlugin\Rh\Candidato;
use GlpiPlugin\Rh\Candidatura;
use GlpiPlugin\Rh\Cargo;
use GlpiPlugin\Rh\Dashboard;
use GlpiPlugin\Rh\Departamento;
use GlpiPlugin\Rh\Feria;
use GlpiPlugin\Rh\Menu;
use GlpiPlugin\Rh\ProfileTab;
use GlpiPlugin\Rh\Vaga;

define('PLUGIN_RH_VERSION', '1.0.5');
define('PLUGIN_RH_MIN_GLPI', '12.0.0');
define('PLUGIN_RH_MAX_GLPI', '12.0.99');

function plugin_init_rh(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Vaga::class, ['notificationtemplates_types' => true]);
    Plugin::registerClass(Candidato::class, ['document_types' => true]);
    Plugin::registerClass(Candidatura::class);
    Plugin::registerClass(Admissao::class);
    Plugin::registerClass(Feria::class, ['notificationtemplates_types' => true]);
    Plugin::registerClass(Departamento::class);
    Plugin::registerClass(Cargo::class);
    Plugin::registerClass(Aprovacoes::class);
    Plugin::registerClass(Dashboard::class);
    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);

    // sessões abertas antes da instalação ainda não têm os direitos do plugin
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $missing = array_filter([Vaga::$rightname, Feria::$rightname], fn ($r) => !isset($_SESSION['glpiactiveprofile'][$r]));
        if ($missing) {
            $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], $missing);
            foreach ($missing as $r) {
                $_SESSION['glpiactiveprofile'][$r] = (int) ($rights[$r] ?? 0);
            }
        }
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['rh']     = [Menu::SECTOR => [Vaga::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['rh'] = 'plugin_rh_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['rh']        = ['css/rh.css'];
}

/** Seção "RH" no menu lateral, logo abaixo de Financeiro (ou de PMO, ou de Dashboard). */
function plugin_rh_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'RH',
        'icon'    => 'ti ti-users-group',
        'types'   => [Vaga::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    foreach (['financeiro', 'pmo', 'dashboard'] as $after) {
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

function plugin_version_rh(): array
{
    return [
        'name'         => 'RH',
        'version'      => PLUGIN_RH_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_RH_MIN_GLPI, 'max' => PLUGIN_RH_MAX_GLPI],
        ],
    ];
}

function plugin_rh_check_prerequisites(): bool
{
    return true;
}

function plugin_rh_check_config($verbose = false): bool
{
    return true;
}
