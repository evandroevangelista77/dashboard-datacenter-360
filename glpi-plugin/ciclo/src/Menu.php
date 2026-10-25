<?php

namespace GlpiPlugin\Ciclo;

use Html;
use Session;

/**
 * Seção "Ciclo Financeiro" do menu, endereços e pequenos ajudantes de tela.
 */
class Menu
{
    public const SECTOR = 'ciclo';

    public const SUBTITLES = [
        'ciclo'  => 'Cada venda fechada, do aviso de fechamento no CRM até o recebimento dos valores. Abra o Ciclo, acompanhe a fase atual e o que falta para concluí-la; cada área recebe a ação no seu grupo.',
        'painel' => 'Quanto tempo o Ciclo leva e onde ele para: ciclo médio, tempo de cada fase contra o SLO, fases atrasadas agora, retrabalho e valores a receber.',
        'config' => 'Fases do Ciclo: o grupo do GLPI que executa cada uma (recebe o chamado e os avisos), o SLO em dias corridos e quais fases estão ativas.',
    ];

    public static function content(): array
    {
        if (!Session::haveRight(Ciclo::$rightname, READ)) {
            return [];
        }
        $items = [
            'ciclo'  => ['title' => 'Ciclos', 'page' => self::page('/front/ciclo.php'), 'icon' => 'ti ti-recycle'],
            'painel' => ['title' => 'Painel do Ciclo', 'page' => self::page('/front/painel.php'), 'icon' => 'ti ti-chart-dots-3'],
        ];
        if (Session::haveRight('config', UPDATE)) {
            $items['config'] = ['title' => 'Fases e SLOs', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
        }
        return $items;
    }

    public static function page(string $path): string
    {
        return '/plugins/ciclo' . $path;
    }

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/ciclo' . $path;
    }

    public static function fileVersion(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . $path;
        return PLUGIN_CICLO_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
    }

    public static function script(string $file): string
    {
        return Html::script(self::url('/js/' . $file), ['version' => self::fileVersion('js/' . $file)], false);
    }

    public static function header(string $title, string $item): void
    {
        Html::header($title, '', self::SECTOR, $item);
        echo self::css();
    }

    /** CSS com a data do arquivo no endereço (o do hook ficava em cache no navegador). */
    public static function css(): string
    {
        return "<link rel='stylesheet' href='" . self::e(self::url('/css/ciclo.css') . '?m=' . self::fileVersion('css/ciclo.css')) . "'>";
    }

    public static function title(string $title, string $key, string $actions = ''): string
    {
        return "<div class='cf-title'><div><div class='cf-crumb'>Ciclo Financeiro</div><h2>" . self::e($title) . "</h2>"
            . (isset(self::SUBTITLES[$key]) ? "<div class='text-secondary small cf-subtitle'>" . self::e(self::SUBTITLES[$key]) . "</div>" : '')
            . "</div>" . ($actions ? "<div class='d-flex flex-wrap gap-2 align-items-center'>$actions</div>" : '') . "</div>";
    }

    public static function button(string $label, string $url, string $icon = 'ti-plus', string $cls = 'btn-primary'): string
    {
        return "<a class='btn $cls' href='" . self::e($url) . "'><i class='ti $icon me-1'></i>" . self::e($label) . "</a>";
    }

    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    public static function money(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    /** "1.234,56" ou "1234.56" → 1234.56 */
    public static function parseMoney(string $v): float
    {
        $v = trim(str_replace(['R$', ' '], '', $v));
        if (str_contains($v, ',')) {
            $v = str_replace(',', '.', str_replace('.', '', $v));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);
        }
        return round((float) $v, 2);
    }

    public static function date(?string $d, bool $time = false): string
    {
        return $d ? date($time ? 'd/m/Y H:i' : 'd/m/Y', strtotime($d)) : '—';
    }

    /** Dias (com uma casa) de um total em segundos. */
    public static function days(int $seconds): string
    {
        return number_format($seconds / 86400, $seconds < 86400 * 10 ? 1 : 0, ',', '.');
    }
}
