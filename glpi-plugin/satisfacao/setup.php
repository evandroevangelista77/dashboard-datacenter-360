<?php

/**
 * Plugin "Pesquisa de Satisfação" para GLPI 11 e 12.
 *
 * Envia uma pesquisa ao solicitante quando o chamado é fechado (ou resolvido) e a
 * todos os destinatários configurados quando um Ciclo Financeiro é concluído. As
 * perguntas são editáveis, e o painel mostra as notas, o NPS e os comentários.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Satisfacao\Campaign;
use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Question;
use GlpiPlugin\Satisfacao\Survey;

define('PLUGIN_SATISFACAO_VERSION', '1.1.0');
define('PLUGIN_SATISFACAO_MIN_GLPI', '11.0.0');
define('PLUGIN_SATISFACAO_MAX_GLPI', '12.0.99');

/** Ciclo Financeiro (plugin "ciclo"): status de ciclo concluído. */
define('PLUGIN_SATISFACAO_CICLO_CLASS', 'GlpiPlugin\\Ciclo\\Ciclo');
define('PLUGIN_SATISFACAO_CICLO_DONE', 2);

/** Evento (plugin "gestaoeventos"): status de evento concluído. */
define('PLUGIN_SATISFACAO_EVENTO_CLASS', 'GlpiPlugin\\Gestaoeventos\\Evento');
define('PLUGIN_SATISFACAO_EVENTO_DONE', 2);

function plugin_init_satisfacao(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Survey::class);
    Plugin::registerClass(Question::class);
    Plugin::registerClass(Campaign::class);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Menu::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::$rightname]);
        $_SESSION['glpiactiveprofile'][Menu::$rightname] = (int) ($rights[Menu::$rightname] ?? 0);
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['satisfacao']     = [Menu::SECTOR => [Survey::class, Question::class, Campaign::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['satisfacao'] = 'plugin_satisfacao_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['satisfacao']        = ['css/satisfacao.css'];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['satisfacao']    = 'front/config.php';

    // gatilhos: chamado fechado (ou resolvido), projeto finalizado, evento e ciclo concluídos;
    // os de Evento e Ciclo só disparam se o plugin deles estiver ativo
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['satisfacao'] = [
        'Ticket'                       => 'plugin_satisfacao_item_update',
        'Project'                      => 'plugin_satisfacao_item_update',
        PLUGIN_SATISFACAO_EVENTO_CLASS => 'plugin_satisfacao_item_update',
        PLUGIN_SATISFACAO_CICLO_CLASS  => 'plugin_satisfacao_item_update',
    ];
}

/** Gatilho de atualização (chamado, projeto, evento e ciclo): envia a pesquisa quando o item chega ao estado final. */
function plugin_satisfacao_item_update(CommonDBTM $item): void
{
    Survey::onItemUpdate($item);
}

/** Seção "CSAT / e-NPS" no menu lateral, abaixo do Suporte (ou no topo). */
function plugin_satisfacao_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'CSAT / e-NPS',
        'icon'    => 'ti ti-mood-smile',
        'types'   => [Survey::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    foreach (['assets', 'management', 'tools'] as $after) {
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

function plugin_version_satisfacao(): array
{
    return [
        'name'         => 'CSAT / e-NPS',
        'version'      => PLUGIN_SATISFACAO_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_SATISFACAO_MIN_GLPI, 'max' => PLUGIN_SATISFACAO_MAX_GLPI],
        ],
    ];
}

function plugin_satisfacao_check_prerequisites(): bool
{
    return true;
}

function plugin_satisfacao_check_config($verbose = false): bool
{
    return true;
}
