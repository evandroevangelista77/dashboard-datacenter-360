<?php

/**
 * Plugin "PMO" para GLPI 12.
 *
 * Gerenciamento de projetos ágeis (Scrum/Kanban) e waterfall sobre os Projetos e
 * Tarefas nativos do GLPI: quadro Kanban com colunas configuráveis, backlog e
 * sprints, Gantt com dependências, painéis (burndown, burnup, curva S, CFD, carga
 * da equipe), documentação, riscos e o portfólio do PMO.
 * As tarefas continuam sendo ProjectTask; o plugin guarda só o que o GLPI não tem
 * (coluna, ordem, sprint, pontos, prioridade).
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\Notifications;
use GlpiPlugin\Pmo\ProfileTab;
use GlpiPlugin\Pmo\ProjectTab;
use GlpiPlugin\Pmo\Snapshot;

define('PLUGIN_PMO_VERSION', '1.2.0');
define('PLUGIN_PMO_MIN_GLPI', '12.0.0');
define('PLUGIN_PMO_MAX_GLPI', '12.0.99');

function plugin_init_pmo(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);
    Plugin::registerClass(ProjectTab::class, ['addtabon' => [Project::class]]);
    Plugin::registerClass(Snapshot::class);
    Plugin::registerClass(Notifications::class);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Menu::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::$rightname]);
        $_SESSION['glpiactiveprofile'][Menu::$rightname] = (int) ($rights[Menu::$rightname] ?? 0);
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['pmo']      = [Menu::SECTOR => [Menu::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['pmo']  = 'plugin_pmo_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['pmo']         = ['css/pmo.css'];

    // Gantt das atividades na tela principal do projeto, abaixo do formulário
    $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['pmo'] = [ProjectTab::class, 'showGanttOnForm'];

    // avisos do PMO nas notificações nativas de Projeto e de Tarefa de projeto
    $PLUGIN_HOOKS[Hooks::ITEM_GET_EVENTS]['pmo'] = [
        NotificationTargetProject::class     => [Notifications::class, 'addEvents'],
        NotificationTargetProjectTask::class => [Notifications::class, 'addEvents'],
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_GET_DATA]['pmo'] = [
        NotificationTargetProject::class     => [Notifications::class, 'addData'],
        NotificationTargetProjectTask::class => [Notifications::class, 'addData'],
    ];

    // tarefa ou projeto apagado de vez: os dados do PMO saem junto
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['pmo'] = [
        ProjectTask::class => 'plugin_pmo_item_purge',
        Project::class     => 'plugin_pmo_item_purge',
    ];
}

/** Seção "PMO" no menu lateral, logo abaixo de "Dashboard" (ou no topo). */
function plugin_pmo_redefine_menus(array $menu): array
{
    if (!isset($menu[Menu::SECTOR]['content'])) {
        $content = Menu::getMenuContent();
        if (!$content) {
            return $menu;
        }
        $menu[Menu::SECTOR] = [
            'title'   => $content['title'],
            'icon'    => Menu::getIcon(),
            'types'   => [Menu::class],
            'content' => $content,
            'default' => $content['portfolio']['page'],
        ];
    }

    $section = [Menu::SECTOR => $menu[Menu::SECTOR]];
    unset($menu[Menu::SECTOR]);
    if (isset($menu['dashboard'])) {
        $first = ['dashboard' => $menu['dashboard']];
        unset($menu['dashboard']);
        return $first + $section + $menu;
    }
    return $section + $menu;
}

function plugin_version_pmo(): array
{
    return [
        'name'         => 'PMO',
        'version'      => PLUGIN_PMO_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_PMO_MIN_GLPI, 'max' => PLUGIN_PMO_MAX_GLPI],
        ],
    ];
}

function plugin_pmo_check_prerequisites(): bool
{
    return true;
}

function plugin_pmo_check_config($verbose = false): bool
{
    return true;
}
