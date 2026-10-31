<?php

namespace GlpiPlugin\Gestaoeventos;

use Html;
use Session;

/**
 * Seção "Gestão de Eventos" do menu, endereços e pequenos ajudantes de tela.
 */
class Menu
{
    public const SECTOR = 'gestaoeventos';

    public const SUBTITLES = [
        'evento'      => 'Cada evento, do briefing ao encerramento. Abra o evento, acompanhe a etapa atual e o que falta para concluí-la; as abas levam cada etapa do processo (aprovação, planejamento, compras, financeiro, viagens, execução e encerramento).',
        'calendario'  => 'Os eventos no calendário: cada barra é um evento (a cor mostra a situação), com marcos, prazos críticos e aprovações com vencimento nos dias. Passe o mouse para os detalhes e clique para abrir.',
        'painel'      => 'Quanto tempo o fluxo leva e onde ele para: ciclo médio, tempo de cada etapa contra o SLO, etapas atrasadas agora, aprovações pendentes, retrabalho e Budget × EAC × Realizado.',
        'aprovacoes'  => 'Minhas pendências, fila por alçada e histórico de decisões. Nenhuma despesa é comprometida sem aprovação formal registrada aqui.',
        'relatorios'  => 'Relatórios gerenciais com filtros e exportação (CSV): eventos, financeiro, SLO por etapa, aprovações e viagens. O relatório final de cada evento fica na aba Encerramento.',
        'documentos'  => 'Todos os documentos anexados aos eventos (contratos, NFs, comprovantes, peças, atas…). PDF e imagens abrem aqui mesmo; os demais são baixados.',
        'config'      => 'Etapas do fluxo: o grupo do GLPI que executa cada uma (recebe o chamado e os avisos), o SLO em dias corridos, o alerta antes do prazo e quais etapas estão ativas.',
        'admin'       => 'Alçadas de aprovação, regras gerais (antecedência, tolerância, chamados), categorias financeiras, tarifas de viagem versionadas e a integração Uber.',
    ];

    public static function content(): array
    {
        if (!Session::haveRight(Evento::$rightname, READ)) {
            return [];
        }
        $items = [
            'evento'     => ['title' => 'Eventos', 'page' => self::page('/front/evento.php'), 'icon' => 'ti ti-calendar-event'],
            'calendario' => ['title' => 'Calendário', 'page' => self::page('/front/calendario.php'), 'icon' => 'ti ti-calendar'],
            'painel'     => ['title' => 'Painel de Eventos', 'page' => self::page('/front/painel.php'), 'icon' => 'ti ti-chart-dots-3'],
            'aprovacoes' => ['title' => 'Aprovações', 'page' => self::page('/front/aprovacoes.php'), 'icon' => 'ti ti-checklist'],
            'relatorios' => ['title' => 'Relatórios', 'page' => self::page('/front/relatorios.php'), 'icon' => 'ti ti-report-analytics'],
            'documentos' => ['title' => 'Documentos', 'page' => self::page('/front/documentos.php'), 'icon' => 'ti ti-files'],
        ];
        foreach (['calendario' => 'calendario', 'painel' => 'painel', 'aprovacoes' => 'aprovacoes', 'relatorios' => 'relatorios', 'documentos' => 'documentos'] as $item => $area) {
            if (!Acesso::can($area)) {
                unset($items[$item]);
            }
        }
        if (Session::haveRight('config', UPDATE)) {
            $items['config'] = ['title' => 'Etapas e SLOs', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
            $items['admin'] = ['title' => 'Alçadas e regras', 'page' => self::page('/front/admin.php'), 'icon' => 'ti ti-adjustments'];
        }
        return $items;
    }

    public static function page(string $path): string
    {
        return '/plugins/gestaoeventos' . $path;
    }

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/gestaoeventos' . $path;
    }

    public static function fileVersion(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . $path;
        return PLUGIN_GESTAOEVENTOS_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
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
        return "<link rel='stylesheet' href='" . self::e(self::url('/css/gestaoeventos.css') . '?m=' . self::fileVersion('css/gestaoeventos.css')) . "'>";
    }

    public static function title(string $title, string $key, string $actions = ''): string
    {
        return "<div class='ge-title'><div><div class='ge-crumb'>Gestão de Eventos</div><h2>" . self::e($title) . "</h2>"
            . (isset(self::SUBTITLES[$key]) ? "<div class='text-secondary small ge-subtitle'>" . self::e(self::SUBTITLES[$key]) . "</div>" : '')
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
        return $d && $d !== '0000-00-00' ? date($time ? 'd/m/Y H:i' : 'd/m/Y', strtotime($d)) : '—';
    }

    /** Dias (com uma casa) de um total em segundos. */
    public static function days(int $seconds): string
    {
        return number_format($seconds / 86400, $seconds < 86400 * 10 ? 1 : 0, ',', '.');
    }

    /** Número com vírgula para CSV e telas. */
    public static function num(float $v, int $dec = 2): string
    {
        return number_format($v, $dec, ',', '.');
    }

    /** Opções <option> de uma lista chave => rótulo. */
    public static function options(array $list, $selected = ''): string
    {
        $h = '';
        foreach ($list as $k => $v) {
            $h .= "<option value='" . self::e($k) . "'" . ((string) $k === (string) $selected ? ' selected' : '') . ">" . self::e($v) . "</option>";
        }
        return $h;
    }

    /** Vira lista de rótulos [chave => rótulo] a partir de [chave => [rótulo, …]]. */
    public static function labels(array $map): array
    {
        return array_map(fn ($x) => is_array($x) ? $x[0] : $x, $map);
    }
}
