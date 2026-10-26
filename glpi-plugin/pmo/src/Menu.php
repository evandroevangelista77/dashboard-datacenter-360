<?php

namespace GlpiPlugin\Pmo;

use CommonGLPI;
use Session;

/**
 * Seção "PMO" do menu lateral: Portfólio e Meu trabalho.
 * Direito plugin_pmo: READ vê, UPDATE edita quadro, sprints, documentação e riscos.
 */
class Menu extends CommonGLPI
{
    public static string $rightname = 'plugin_pmo';

    public const SECTOR = 'pmo';

    public static function getTypeName($nb = 0)
    {
        return 'PMO';
    }

    public static function getMenuName()
    {
        return 'PMO';
    }

    public static function getIcon()
    {
        return 'ti ti-briefcase';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }
        $front = '/plugins/pmo/front';
        return [
            'title'            => 'PMO',
            'is_multi_entries' => true,
            'portfolio'        => [
                'title' => 'Portfólio de projetos',
                'page'  => "$front/portfolio.php",
                'icon'  => 'ti ti-layout-dashboard',
            ],
            'mywork'           => [
                'title' => 'Meu trabalho',
                'page'  => "$front/mywork.php",
                'icon'  => 'ti ti-user-check',
            ],
            'documentos'       => [
                'title' => 'Documentos dos projetos',
                'page'  => "$front/documentos.php",
                'icon'  => 'ti ti-files',
            ],
            'projects'         => [
                'title' => 'Projetos (GLPI)',
                'page'  => '/front/project.php',
                'icon'  => 'ti ti-list-details',
            ],
        ];
    }

    /** Endereço de um recurso do plugin (front/…, js/…, css/…). */
    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/pmo' . $path;
    }

    public static function canEdit(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }
}
