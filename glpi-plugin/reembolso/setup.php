<?php

/**
 * Plugin "Reembolso de despesas" para GLPI 12.
 *
 * Lançamento de despesas de técnicos em atendimento externo: solicitação com
 * técnico, centro de custo, localização e chamado; despesas por tipo, com
 * quilometragem calculada pela rota (origem → destino) ou pelo odômetro;
 * fluxo de envio, aprovação e pagamento; dashboard com gráficos e exportação XLSX.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Reembolso\Centrocusto;
use GlpiPlugin\Reembolso\Dashboard;
use GlpiPlugin\Reembolso\Despesa;
use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\ProfileTab;
use GlpiPlugin\Reembolso\Reembolso;
use GlpiPlugin\Reembolso\TicketTab;
use GlpiPlugin\Reembolso\Tipodespesa;

define('PLUGIN_REEMBOLSO_VERSION', '1.1.1');
define('PLUGIN_REEMBOLSO_MIN_GLPI', '12.0.0');
define('PLUGIN_REEMBOLSO_MAX_GLPI', '12.0.99');

function plugin_init_reembolso(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Reembolso::class, ['document_types' => true, 'notificationtemplates_types' => true]);
    Plugin::registerClass(Despesa::class);
    Plugin::registerClass(Tipodespesa::class);
    Plugin::registerClass(Centrocusto::class);
    Plugin::registerClass(Dashboard::class);
    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);
    Plugin::registerClass(TicketTab::class, ['addtabon' => [Ticket::class]]);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Reembolso::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Reembolso::$rightname]);
        $_SESSION['glpiactiveprofile'][Reembolso::$rightname] = (int) ($rights[Reembolso::$rightname] ?? 0);
    }

    // seção "Financeiro" no menu lateral (abaixo de PMO): Reembolsos e Dashboard de reembolsos
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['reembolso']     = [Menu::SECTOR => [Reembolso::class, Dashboard::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['reembolso'] = 'plugin_reembolso_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['reembolso']    = ['css/reembolso.css'];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['reembolso'] = 'front/config.form.php';
}

/**
 * Aplicado a cada desenho do menu: dá título e ícone à seção Financeiro, põe logo
 * abaixo de PMO (ou de Dashboard), acrescenta os cadastros e a configuração e
 * funciona mesmo com o menu guardado na sessão antes do plugin (ou da versão antiga,
 * que ficava em Gerência).
 */
function plugin_reembolso_redefine_menus(array $menu): array
{
    $content = Menu::content();
    foreach (['management'] as $old) {
        foreach (array_keys($content) as $key) {
            unset($menu[$old]['content'][$key]);
        }
    }
    if (!$content) {
        unset($menu[Menu::SECTOR]);
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'Financeiro',
        'icon'    => 'ti ti-coin',
        'types'   => [Reembolso::class, Dashboard::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    unset($menu[Menu::SECTOR]);

    // logo depois de PMO; sem PMO, depois de Dashboard; senão, no topo
    $after = isset($menu['pmo']) ? 'pmo' : (isset($menu['dashboard']) ? 'dashboard' : null);
    if ($after === null) {
        return $section + $menu;
    }
    $out = [];
    foreach ($menu as $key => $value) {
        $out[$key] = $value;
        if ($key === $after) {
            $out += $section;
        }
    }
    return $out;
}

function plugin_version_reembolso(): array
{
    return [
        'name'         => 'Reembolso de despesas',
        'version'      => PLUGIN_REEMBOLSO_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_REEMBOLSO_MIN_GLPI, 'max' => PLUGIN_REEMBOLSO_MAX_GLPI],
            'php'  => ['exts' => ['zip' => ['required' => true], 'curl' => ['required' => false]]],
        ],
    ];
}

function plugin_reembolso_check_prerequisites(): bool
{
    return true;
}

function plugin_reembolso_check_config($verbose = false): bool
{
    return true;
}
