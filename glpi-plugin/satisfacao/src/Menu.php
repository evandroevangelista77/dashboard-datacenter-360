<?php

namespace GlpiPlugin\Satisfacao;

use Html;
use Session;

/**
 * Seção "Satisfação" do menu, endereços e pequenos ajudantes de tela.
 */
class Menu
{
    public const SECTOR = 'satisfacao';

    /** Direito do plugin: ver o painel; atualizar edita as perguntas. */
    public static string $rightname = 'plugin_satisfacao';

    public static function content(): array
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return [];
        }
        // CSAT (satisfação de chamados, projetos, eventos e ciclos) e e-NPS (clima da equipe), cada um com o seu painel
        $items = [
            'painel' => ['title' => 'Painel CSAT', 'page' => self::page('/front/painel.php'), 'icon' => 'ti ti-chart-bar'],
        ];
        if (Session::haveRight(self::$rightname, UPDATE)) {
            $items['perguntas'] = ['title' => 'Perguntas CSAT', 'page' => self::page('/front/pergunta.php'), 'icon' => 'ti ti-message-question'];
        }
        $items['enps'] = ['title' => 'Painel e-NPS', 'page' => self::page('/front/enps.php'), 'icon' => 'ti ti-heart-handshake'];
        if (Session::haveRight(self::$rightname, UPDATE)) {
            $items['campanhas'] = ['title' => 'Campanhas e-NPS', 'page' => self::page('/front/campanha.php'), 'icon' => 'ti ti-send'];
        }
        if (Session::haveRight('config', UPDATE)) {
            $items['config'] = ['title' => 'Configuração', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
        }
        return $items;
    }

    public static function page(string $path): string
    {
        return '/plugins/satisfacao' . $path;
    }

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/satisfacao' . $path;
    }

    /** Endereço das telas de dentro do GLPI, por extenso (para link em e-mail). */
    public static function absolute(string $path): string
    {
        global $CFG_GLPI;
        return rtrim((string) $CFG_GLPI['url_base'], '/') . '/plugins/satisfacao' . $path;
    }

    /** Folha de estilo com a data do arquivo no endereço (o navegador guardava a versão antiga em cache). */
    public static function css(): string
    {
        $file = dirname(__DIR__) . '/public/css/satisfacao.css';
        $version = is_file($file) ? filemtime($file) : 0;
        return "<link rel='stylesheet' href='" . self::e(self::url('/css/satisfacao.css') . '?m=' . $version) . "'>";
    }

    /** Script do plugin, com a data do arquivo no endereço. */
    public static function script(string $file): string
    {
        $path = dirname(__DIR__) . '/public/js/' . $file;
        return \Html::script(self::url('/js/' . $file), ['version' => is_file($path) ? filemtime($path) : 0], false);
    }

    public static function header(string $title): void
    {
        Html::header($title, '', self::SECTOR, Survey::class);
        echo self::css();
    }

    /** Cabeçalho da tela, com a seção do plugin e os botões de ação. */
    public static function title(string $title, string $subtitle, string $actions = ''): string
    {
        return "<div class='sf-title'><div><div class='sf-crumb'>CSAT / e-NPS</div><h2>" . self::e($title) . "</h2>"
            . ($subtitle ? "<div class='text-secondary small'>" . self::e($subtitle) . "</div>" : '')
            . "</div>" . ($actions ? "<div class='sf-actions'>$actions</div>" : '') . "</div>";
    }

    public static function button(string $label, string $url, string $icon = 'ti-plus', string $cls = 'btn-primary'): string
    {
        return "<a class='btn $cls' href='" . self::e($url) . "'><i class='ti $icon me-1'></i>" . self::e($label) . "</a>";
    }

    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
