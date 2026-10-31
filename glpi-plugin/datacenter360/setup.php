<?php

/**
 * Plugin DataCenter 360 para GLPI: seção no menu lateral que abre o painel
 * NOC (Zabbix × GLPI) e a topologia da rede dentro do próprio GLPI.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Datacenter360\Menu;
use GlpiPlugin\Datacenter360\ProfileTab;

define('PLUGIN_DATACENTER360_VERSION', '1.2.0');
define('PLUGIN_DATACENTER360_MIN_GLPI', '11.0.0');
define('PLUGIN_DATACENTER360_MAX_GLPI', '12.0.99');

function plugin_init_datacenter360(): void
{
    global $PLUGIN_HOOKS;

    // aba "DataCenter 360" no cadastro de perfis (quem pode ver o menu)
    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);

    // Sessões abertas antes da instalação não têm o direito do plugin: busca no banco
    // (a sessão já está iniciada quando os plugins inicializam no GLPI 11/12).
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Menu::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::$rightname]);
        $_SESSION['glpiactiveprofile'][Menu::$rightname] = (int) ($rights[Menu::$rightname] ?? 0);
    }

    // Sessão sem paleta própria cai na constante interna "auror" do ThemeManager,
    // ignorando a paleta padrão configurada; aplica a padrão (ex.: datacenter360).
    // Quem escolheu uma paleta em "Minhas configurações" já tem a chave e não é afetado.
    global $CFG_GLPI;
    if (isset($_SESSION['glpiID']) && empty($_SESSION['glpipalette']) && !empty($CFG_GLPI['palette'])) {
        $_SESSION['glpipalette'] = $CFG_GLPI['palette'];
    }

    // árvore da Oakmont em marca d'água azul em todas as telas
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['datacenter360'] = ['css/noc360-fundo.css'];

    // Seção "Dashboard" no menu lateral; quem não tem o direito não vê os itens
    // (Menu::getMenuContent() devolve false).
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['datacenter360'] = [Menu::SECTOR => [Menu::class]];

    // Aplicado a cada desenho do menu: põe a seção no topo e a acrescenta mesmo
    // quando o menu guardado na sessão é de antes do plugin.
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['datacenter360'] = 'plugin_datacenter360_redefine_menus';
}

function plugin_datacenter360_redefine_menus(array $menu): array
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
            'default' => $content['datacenter360']['page'],
        ];
    }

    // título atual da seção e do item (o menu guardado na sessão pode ter o nome antigo)
    $content = Menu::getMenuContent();
    if ($content) {
        $menu[Menu::SECTOR]['title'] = $content['title'];
        $menu[Menu::SECTOR]['content']['title'] = $content['title'];
        if (isset($menu[Menu::SECTOR]['content']['datacenter360'])) {
            $menu[Menu::SECTOR]['content']['datacenter360']['title'] = $content['datacenter360']['title'];
        }
    }

    // "NOC" acima de "Ativos"
    return [Menu::SECTOR => $menu[Menu::SECTOR]] + $menu;
}

function plugin_version_datacenter360(): array
{
    return [
        'name'         => 'NOC 360',
        'version'      => PLUGIN_DATACENTER360_VERSION,
        'author'       => 'Evandro Evangelista',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DATACENTER360_MIN_GLPI,
                'max' => PLUGIN_DATACENTER360_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_datacenter360_check_prerequisites(): bool
{
    return true;
}

function plugin_datacenter360_check_config($verbose = false): bool
{
    return true;
}
