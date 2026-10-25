<?php

/**
 * Plugin "Ciclo Financeiro" para GLPI 12.
 *
 * Acompanha cada venda do aviso de fechamento até o recebimento dos valores:
 * fases com área responsável, SLO, chamado por fase e avisos; itens da venda
 * (RMs, pedidos, NFs e parcelas); devoluções contadas como retrabalho; painel
 * com o tempo do ciclo e de cada fase.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Ciclo\Ciclo;
use GlpiPlugin\Ciclo\Menu;
use GlpiPlugin\Ciclo\ProfileTab;

define('PLUGIN_CICLO_VERSION', '1.0.1');
define('PLUGIN_CICLO_MIN_GLPI', '12.0.0');
define('PLUGIN_CICLO_MAX_GLPI', '12.0.99');

function plugin_init_ciclo(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Ciclo::class, ['document_types' => true]);
    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Ciclo::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Ciclo::$rightname]);
        $_SESSION['glpiactiveprofile'][Ciclo::$rightname] = (int) ($rights[Ciclo::$rightname] ?? 0);
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['ciclo']     = [Menu::SECTOR => [Ciclo::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['ciclo'] = 'plugin_ciclo_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['ciclo']        = ['css/ciclo.css'];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['ciclo']    = 'front/config.php';
}

/** Seção "Ciclo Financeiro" no menu lateral, logo abaixo de Financeiro (ou de PMO, ou de Dashboard). */
function plugin_ciclo_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'Ciclo Financeiro',
        'icon'    => 'ti ti-recycle',
        'types'   => [Ciclo::class],
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

function plugin_version_ciclo(): array
{
    return [
        'name'         => 'Ciclo Financeiro',
        'version'      => PLUGIN_CICLO_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_CICLO_MIN_GLPI, 'max' => PLUGIN_CICLO_MAX_GLPI],
        ],
    ];
}

function plugin_ciclo_check_prerequisites(): bool
{
    return true;
}

function plugin_ciclo_check_config($verbose = false): bool
{
    return true;
}
