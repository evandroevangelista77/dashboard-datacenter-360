<?php

/**
 * Plugin "Gestão de Eventos" para GLPI 11 e 12.
 *
 * Leva cada evento (feira, congresso, evento de parceiro, ação interna ou agenda
 * comercial) do briefing ao encerramento: oito etapas com área responsável, SLO,
 * aprovações por alçada, chamado por etapa e avisos; abas para planejamento,
 * compras, financeiro (Budget, EAC e Realizado), viagens, execução e encerramento;
 * painel com o tempo de cada etapa contra o SLO, relatórios e auditoria.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Menu;
use GlpiPlugin\Gestaoeventos\ProfileTab;

define('PLUGIN_GESTAOEVENTOS_VERSION', '1.2.0');
define('PLUGIN_GESTAOEVENTOS_MIN_GLPI', '11.0.0');
define('PLUGIN_GESTAOEVENTOS_MAX_GLPI', '12.0.99');

function plugin_init_gestaoeventos(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['gestaoeventos'] = true;

    Plugin::registerClass(Evento::class, ['document_types' => true]);
    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Evento::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Evento::$rightname]);
        $_SESSION['glpiactiveprofile'][Evento::$rightname] = (int) ($rights[Evento::$rightname] ?? 0);
    }

    \GlpiPlugin\Gestaoeventos\Acesso::sessionLoad();

    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['gestaoeventos'] = ['js/avisos.js'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['gestaoeventos']    = ['ProjectTask' => 'plugin_gestaoeventos_projecttask_update'];
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['gestaoeventos']     = [Menu::SECTOR => [Evento::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['gestaoeventos'] = 'plugin_gestaoeventos_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['gestaoeventos']        = ['css/gestaoeventos.css'];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['gestaoeventos']    = 'front/config.php';
}

/** Seção "Gestão de Eventos" no menu lateral, logo abaixo de Financeiro (ou de PMO, ou de Dashboard). */
function plugin_gestaoeventos_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'Gestão de Eventos',
        'icon'    => 'ti ti-calendar-event',
        'types'   => [Evento::class],
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

/** Tarefa do projeto alterada: atualiza o marco do evento ligado a ela. */
function plugin_gestaoeventos_projecttask_update(ProjectTask $item): void
{
    \GlpiPlugin\Gestaoeventos\Projeto::taskToMarco($item);
}

function plugin_version_gestaoeventos(): array
{
    return [
        'name'         => 'Gestão de Eventos',
        'version'      => PLUGIN_GESTAOEVENTOS_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_GESTAOEVENTOS_MIN_GLPI, 'max' => PLUGIN_GESTAOEVENTOS_MAX_GLPI],
        ],
    ];
}

function plugin_gestaoeventos_check_prerequisites(): bool
{
    return true;
}

function plugin_gestaoeventos_check_config($verbose = false): bool
{
    return true;
}
