<?php

namespace GlpiPlugin\Operacional360;

use Session;

/**
 * Seção "Operacional" do menu lateral: o painel (todo perfil com o direito de leitura)
 * e a configuração (só quem pode configurar o GLPI, ou seja, o administrador).
 */
class Menu
{
    /** Direito do plugin: ver o painel (o escopo por entidade e host group é aplicado pela API). */
    public static string $rightname = 'plugin_operacional360';

    /** Chave da seção no menu lateral. */
    public const SECTOR = 'operacional360';

    public const PAGE = '/plugins/operacional360/front/painel.php';
    public const CONFIG_PAGE = '/plugins/operacional360/front/config.form.php';

    public static function getMenuContent(): array|false
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }
        $content = [
            'title'            => 'Operacional',
            'is_multi_entries' => true,
            'operacional360'   => [
                'title' => 'Operacional 360',
                'page'  => self::PAGE,
                'icon'  => self::getIcon(),
            ],
        ];
        if (Session::haveRight('config', UPDATE)) {
            $content['operacional360_config'] = [
                'title' => 'Configurar Operacional 360',
                'page'  => self::CONFIG_PAGE,
                'icon'  => 'ti ti-settings',
            ];
        }
        return $content;
    }

    public static function getIcon(): string
    {
        return 'ti ti-chart-dots-3';
    }
}
