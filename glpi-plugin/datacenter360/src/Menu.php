<?php

namespace GlpiPlugin\Datacenter360;

use CommonGLPI;
use Session;

/**
 * Seção "Dashboard" do menu lateral, com os itens DataCenter 360 e Topologia.
 */
class Menu extends CommonGLPI
{
    public static string $rightname = 'plugin_datacenter360';

    /** Chave da seção no menu lateral (também usada no cabeçalho das páginas). */
    public const SECTOR = 'dashboard';

    /** Endereço do dashboard publicado no Apache (alias /datacenter360). */
    public const DASHBOARD_URL = '/datacenter360/';

    public static function getTypeName($nb = 0)
    {
        return 'DataCenter 360';
    }

    public static function getMenuName()
    {
        return 'DataCenter 360';
    }

    public static function getIcon()
    {
        return 'ti ti-dashboard'; // ícone da seção "Dashboard"
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        $front = '/plugins/datacenter360/front';

        // 'title' dá nome à seção; as demais chaves viram itens dela
        return [
            'title'            => 'Dashboard',
            'is_multi_entries' => true,
            'datacenter360'    => [
                'title' => 'DataCenter 360',
                'page'  => "$front/painel.php",
                'icon'  => 'ti ti-building-broadcast-tower',
            ],
            'topologia'        => [
                'title' => 'Topologia da rede',
                'page'  => "$front/topologia.php",
                'icon'  => 'ti ti-topology-star-3',
            ],
        ];
    }

    /**
     * Mostra o dashboard embutido na área de conteúdo do GLPI.
     *
     * @param string $page página do dashboard (index.html ou topologia.html)
     */
    public static function showFrame(string $page): void
    {
        $src = htmlspecialchars(self::DASHBOARD_URL . $page, ENT_QUOTES);
        echo <<<HTML
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="text-secondary small">
                    <i class="ti ti-building-broadcast-tower me-1"></i>
                    Painel NOC integrando Zabbix e GLPI
                </div>
                <a class="btn btn-sm btn-outline-secondary" href="$src" target="_blank" rel="noopener">
                    <i class="ti ti-external-link me-1"></i>Abrir em tela cheia
                </a>
            </div>
            <iframe src="$src" title="DataCenter 360"
                    style="width:100%; height:calc(100vh - 190px); min-height:640px; border:0; border-radius:10px; background:#070b14;"
                    allow="fullscreen" allowfullscreen></iframe>
            HTML;
    }
}
