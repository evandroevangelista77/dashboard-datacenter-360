<?php

namespace GlpiPlugin\Rh;

use Session;

/**
 * Seção "RH" do menu lateral e endereços dos recursos do plugin.
 */
class Menu
{
    public const SECTOR = 'rh';

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/rh' . $path;
    }

    /** Endereço relativo, como o menu do GLPI espera. */
    public static function page(string $path): string
    {
        return '/plugins/rh' . $path;
    }

    public static function fileVersion(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . $path;
        return PLUGIN_RH_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
    }

    public static function css(): string
    {
        return "<link rel='stylesheet' href='" . htmlspecialchars(self::url('/css/rh.css') . '?m=' . self::fileVersion('css/rh.css'), ENT_QUOTES) . "'>";
    }

    public static function script(string $file): string
    {
        return \Html::script(self::url('/js/' . $file), ['version' => self::fileVersion('js/' . $file)], false);
    }

    /** Itens da seção RH conforme os direitos. */
    public static function content(): array
    {
        $items = [];
        $vaga = Session::haveRightsOr(Vaga::$rightname, [READ, Vaga::READALL]);
        $ferias = Session::haveRightsOr(Feria::$rightname, [READ, Feria::READALL]);
        $rh = Session::haveRight(Vaga::$rightname, Vaga::READALL);
        if ($vaga || $ferias) {
            $pending = Aprovacoes::count();
            $items['aprovacoes'] = ['title' => 'Minhas aprovações' . ($pending ? " ($pending)" : ''), 'page' => self::page('/front/aprovacoes.php'), 'icon' => 'ti ti-checklist'];
        }
        if ($vaga) {
            $items['vaga'] = ['title' => 'Vagas abertas', 'page' => self::page('/front/vaga.php'), 'icon' => 'ti ti-briefcase-2',
                'links' => Vaga::canCreate() ? ['add' => self::page('/front/vaga.form.php'), 'search' => self::page('/front/vaga.php')] : []];
        }
        if ($rh) {
            $items['candidato'] = ['title' => 'Banco de Currículo', 'page' => self::page('/front/candidato.php'), 'icon' => 'ti ti-user-search',
                'links' => ['add' => self::page('/front/candidato.form.php'), 'search' => self::page('/front/candidato.php')]];
        }
        if ($vaga) {
            $items['admissao'] = ['title' => 'Admissões', 'page' => self::page('/front/admissao.php'), 'icon' => 'ti ti-user-plus'];
        }
        if ($ferias) {
            $items['ferias'] = ['title' => 'Férias', 'page' => self::page('/front/feria.php'), 'icon' => 'ti ti-beach',
                'links' => Feria::canCreate() ? ['add' => self::page('/front/feria.form.php'), 'search' => self::page('/front/feria.php')] : []];
            $items['calendario'] = ['title' => 'Calendário de férias', 'page' => self::page('/front/calendario.php'), 'icon' => 'ti ti-calendar-event'];
        }
        if ($vaga) {
            $items['documentos'] = ['title' => 'Documentos', 'page' => self::page('/front/documentos.php'), 'icon' => 'ti ti-files'];
        }
        if ($vaga || $ferias) {
            $items['dashboard'] = ['title' => 'Dashboard RH', 'page' => self::page('/front/dashboard.php'), 'icon' => 'ti ti-chart-bar'];
        }
        if ($rh && Session::haveRight('dropdown', READ)) {
            $items['departamento'] = ['title' => Departamento::getTypeName(2), 'page' => Departamento::getSearchURL(false), 'icon' => Departamento::getIcon()];
            $items['cargo'] = ['title' => 'Cadastro de Cargos', 'page' => Cargo::getSearchURL(false), 'icon' => Cargo::getIcon()];
        }
        return $items;
    }

    public static function header(string $title, string $item): void
    {
        \Html::header($title, '', self::SECTOR, $item);
        echo self::css();
    }

    /** Subtítulos das telas do RH (o mesmo texto em todo lugar). */
    public const SUBTITLES = [
        'aprovacoes'   => 'O que está esperando a sua decisão: vagas (como gestor da área ou como diretoria) e férias da sua equipe.',
        'vaga'         => 'Requisições de vaga e processos seletivos. Crie a requisição, envie para aprovação (gestor da área e depois diretoria) e, com a vaga aberta, acompanhe os candidatos no funil de seleção.',
        'candidato'    => 'Todos os candidatos cadastrados, com contato, origem e pretensão salarial. Anexe o currículo na aba Documentos da ficha e coloque o candidato numa vaga pelo funil de seleção.',
        'admissao'     => 'Onboarding dos contratados: checklist por área (RH, TI, gestor e facilities) e chamado de TI para usuário, e-mail, equipamento e acessos. É criada sozinha quando o candidato vai para Contratado.',
        'ferias'       => 'Pedidos de férias. O gestor (o Responsável do colaborador no GLPI) aprova ou reprova em Minhas aprovações. Mínimo de 5 dias por período e abono de até 10 dias.',
        'calendario'   => 'Quem está de férias no mês ou no ano inteiro: barras verdes são férias aprovadas e as listradas aguardam o gestor. Clique numa barra para abrir o pedido.',
        'dashboard'    => 'Visão geral do RH: vagas e aprovações, funil de seleção, origem dos candidatos, contratações, admissões em andamento e férias.',
        'departamento' => 'Departamentos da empresa e o gestor de cada um, que aprova a 1ª etapa das requisições de vaga da área.',
        'documentos'   => 'Todos os documentos do RH: anexos das vagas, currículos do banco de candidatos e documentos admissionais. PDF e imagens abrem aqui mesmo; os demais são baixados.',
        'cargo'        => 'Cargos da empresa com CBO e job description (missão, responsabilidades, requisitos, competências e indicadores). O job description aparece na requisição de vaga.',
    ];

    /**
     * Cabeçalho padrão das telas: seção, título, subtítulo e ações à direita.
     * @param string $actions HTML dos botões/filtros do canto direito
     */
    public static function title(string $title, string $key, string $actions = '', string $crumb = 'RH'): string
    {
        return "<div class='rh-title'><div><div class='rh-crumb'>" . self::e($crumb) . "</div><h2>" . self::e($title) . "</h2>"
            . (isset(self::SUBTITLES[$key]) ? "<div class='text-secondary small rh-subtitle'>" . self::e(self::SUBTITLES[$key]) . "</div>" : '')
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

    public static function badge(string $label, string $color, string $icon = ''): string
    {
        return "<span class='badge rh-badge' style='--c:$color'>" . ($icon ? "<i class='ti $icon me-1'></i>" : '') . self::e($label) . "</span>";
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: ['?'];
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    }
}
