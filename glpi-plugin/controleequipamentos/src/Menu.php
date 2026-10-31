<?php

namespace GlpiPlugin\Controleequipamentos;

use Html;
use Session;

/**
 * Seção do menu, endereços e pequenos ajudantes de tela.
 * Não estende CommonDBTM: as classes do plugin acessam o banco direto, o que evita
 * diferenças de tipagem de propriedades entre GLPI 11 e 12.
 */
class Menu
{
    public const SECTOR = 'controleequipamentos';
    public const RIGHT = 'plugin_controleequipamentos';
    public const CONFIG_CONTEXT = 'plugin:controleequipamentos';

    public static function content(): array
    {
        if (!Session::haveRight(self::RIGHT, READ)) {
            return [];
        }
        $items = [
            'controle' => ['title' => 'Ciclos', 'page' => self::page('/front/controle.php'), 'icon' => 'ti ti-devices'],
            'painel'   => ['title' => 'Painel', 'page' => self::page('/front/painel.php'), 'icon' => 'ti ti-chart-dots-3'],
        ];
        if (Session::haveRight('config', UPDATE)) {
            $items['config'] = ['title' => 'Fases e SLOs', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
        }
        return $items;
    }

    public static function page(string $path): string
    {
        return '/plugins/controleequipamentos' . $path;
    }

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/controleequipamentos' . $path;
    }

    /** Endereço completo, para links que saem do GLPI (no chamado). */
    public static function absolute(string $url): string
    {
        global $CFG_GLPI;
        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        return $base . $url;
    }

    /** Script do plugin, com a versão no endereço para não ficar em cache. */
    public static function script(string $file): string
    {
        return Html::script(self::url('/js/' . $file), ['version' => self::version('js/' . $file)], false);
    }

    public static function version(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . $path;
        return PLUGIN_CONTROLEEQUIPAMENTOS_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
    }

    /** Abre a página no menu da seção e inclui o CSS do plugin. */
    public static function header(string $title, string $item): void
    {
        Html::header($title, '', self::SECTOR, $item);
        echo "<link rel='stylesheet' href='" . self::e(self::url('/css/controleequipamentos.css') . '?v=' . self::version('css/controleequipamentos.css')) . "'>";
        echo "<div class='cf'>";
    }

    /** Fecha o contêiner aberto por header() e o rodapé do GLPI. */
    public static function footer(): void
    {
        echo "</div>";
        Html::footer();
    }

    /** Cartão de cabeçalho de página de cadastro, no padrão do Ciclo Financeiro (ícone, migalha, título, subtítulo). */
    public static function cabecalhoNovo(string $migalha, string $titulo, string $subtitulo, string $icone, string $voltar = ''): string
    {
        return "<div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'><div class='cf-head-icon'><i class='ti $icone' aria-hidden='true'></i></div><div class='me-auto'>"
            . "<div class='cf-crumb'>" . self::e($migalha) . "</div><h2 class='m-0'>" . self::e($titulo) . "</h2>"
            . "<div class='text-secondary small cf-subtitle'>" . self::e($subtitulo) . "</div></div>" . $voltar . "</div></div>";
    }

    /** Título no padrão do Ciclo Financeiro: migalha, título, subtítulo e ações à direita. */
    public static function title(string $title, string $subtitle = '', string $actions = ''): string
    {
        return "<div class='cf-title'><div><div class='cf-crumb'>Controle de Equipamentos</div><h2>" . self::e($title) . "</h2>"
            . ($subtitle !== '' ? "<div class='text-secondary small cf-subtitle'>" . self::e($subtitle) . "</div>" : '')
            . "</div>" . ($actions !== '' ? "<div class='d-flex flex-wrap gap-2 align-items-center'>$actions</div>" : '') . "</div>";
    }

    /** Abas da página principal. Usa links com aria-current, que dispensa o teclado de setas do padrão tablist. */
    public static function abas(string $atual, string $pagina = '/front/controle.php'): string
    {
        $abas = ['entrega' => ['Entrega de equipamento', 'ti-user-plus'], 'devolucao' => ['Devolução de equipamento', 'ti-package-export']];
        $html = "<nav aria-label='Abas do Controle de Equipamentos'><ul class='nav nav-tabs mb-3'>";
        foreach ($abas as $key => [$label, $icone]) {
            $ativa = $key === $atual;
            $html .= "<li class='nav-item'><a class='nav-link" . ($ativa ? ' active' : '') . "' href='" . self::e(self::url($pagina . '?aba=' . $key)) . "'"
                . ($ativa ? " aria-current='page'" : '') . "><i class='ti $icone me-1' aria-hidden='true'></i>" . self::e($label) . "</a></li>";
        }
        return $html . "</ul></nav>";
    }

    /**
     * Campo de formulário no padrão Tabler: rótulo acima, controle a largura da coluna e, quando
     * obrigatório, um asterisco. Sem o atributo "required" (o formulário usa novalidate e a validação
     * é feita no servidor), para não pintar de vermelho antes de o usuário preencher.
     * Tipos: text, textarea, date, money, select (usa $opcoes). Textarea ocupa a linha inteira.
     */
    public static function campo(string $nome, string $rotulo, string $tipo, bool $obrigatorio, string $valor = '', array $opcoes = [], string $ajuda = ''): string
    {
        $id = 'ce-' . $nome;
        $attrs = "id='$id' name='" . self::e($nome) . "'" . ($obrigatorio ? " aria-required='true'" : '');
        $label = "<label class='form-label" . ($obrigatorio ? ' required' : '') . "' for='$id'>" . self::e($rotulo) . "</label>";
        $coluna = 'col-12 col-md-4';
        switch ($tipo) {
            case 'textarea':
                $ctrl = "<textarea class='form-control' $attrs rows='3'>" . self::e($valor) . "</textarea>";
                $coluna = 'col-12';
                break;
            case 'date':
                $ctrl = "<input class='form-control' type='date' $attrs value='" . self::e($valor) . "'>";
                break;
            case 'money':
                $ctrl = "<div class='input-group'><span class='input-group-text'>R$</span><input class='form-control' type='text' inputmode='decimal' placeholder='0,00' $attrs value='" . self::e($valor) . "'></div>";
                break;
            case 'select':
                $ctrl = "<select class='form-select' $attrs><option value=''>Selecione</option>";
                foreach ($opcoes as $v => $texto) {
                    $ctrl .= "<option value='" . self::e($v) . "'" . ((string) $v === $valor && $valor !== '' ? ' selected' : '') . ">" . self::e($texto) . "</option>";
                }
                $ctrl .= "</select>";
                break;
            default:
                $ctrl = "<input class='form-control' type='text' $attrs value='" . self::e($valor) . "'>";
        }
        $dica = $ajuda !== '' ? "<div class='form-hint'>" . self::e($ajuda) . "</div>" : '';
        return "<div class='$coluna'>$label$ctrl$dica</div>";
    }

    /** Filtro de situação da lista, em GET (aba e status). */
    public static function filtroStatus(string $aba, string $atual, array $opcoes): string
    {
        $html = "<form method='get' action='" . self::e(self::url('/front/controle.php')) . "' class='d-flex gap-2 align-items-center m-0'>"
            . "<input type='hidden' name='aba' value='" . self::e($aba) . "'>"
            . "<label class='small text-secondary' for='ce-status-$aba'>Mostrar</label><select class='form-select form-select-sm' id='ce-status-$aba' name='status' onchange='this.form.submit()'>";
        foreach ($opcoes as $valor => $rotulo) {
            $html .= "<option value='" . self::e($valor) . "'" . ($valor === $atual ? ' selected' : '') . ">" . self::e($rotulo) . "</option>";
        }
        return $html . "</select><noscript><button type='submit' class='btn btn-sm btn-outline-secondary'>Filtrar</button></noscript></form>";
    }

    /** Filtro por situação, em GET, com rótulo associado ao campo. */
    public static function filtro(string $aba, string $atual, array $opcoes): string
    {
        $html = "<form method='get' action='" . self::e(self::url('/front/controle.php')) . "' class='ce-filtro'>"
            . "<input type='hidden' name='aba' value='" . self::e($aba) . "'>"
            . "<label for='ce-status-$aba'>Mostrar</label> <select id='ce-status-$aba' name='status'>";
        foreach ($opcoes as $valor => $rotulo) {
            $html .= "<option value='" . self::e($valor) . "'" . ($valor === $atual ? ' selected' : '') . ">" . self::e($rotulo) . "</option>";
        }
        return $html . "</select> <button type='submit' class='btn btn-outline-primary btn-sm'>Filtrar</button></form>";
    }

    public static function button(string $label, string $url, string $icon = 'ti-plus', string $cls = 'btn-primary'): string
    {
        return "<a class='btn $cls' href='" . self::e($url) . "'><i class='ti $icon me-1' aria-hidden='true'></i>" . self::e($label) . "</a>";
    }

    /** Campo de token que o GLPI exige em todo POST de formulário. */
    public static function csrf(): string
    {
        return "<input type='hidden' name='_glpi_csrf_token' value='" . self::e(Session::getNewCSRFToken()) . "'>";
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
        }
        return round((float) $v, 2);
    }

    public static function date(?string $d, bool $time = false): string
    {
        return $d ? date($time ? 'd/m/Y H:i' : 'd/m/Y', strtotime($d)) : '—';
    }
}
