<?php

namespace GlpiPlugin\Reembolso;

/**
 * Endereços dos recursos do plugin.
 */
class Menu
{
    /** Chave da seção "Reembolso" (chave interna: financeiro) no menu lateral. */
    public const SECTOR = 'financeiro';

    /** Itens da seção Reembolso conforme os direitos do usuário. */
    public static function content(): array
    {
        $items = [];
        foreach ([Reembolso::class, Dashboard::class] as $class) {
            if ($data = $class::getMenuContent()) {
                $items[strtolower($class)] = $data;
            }
        }
        if (!$items) {
            return [];
        }
        $items['documentos'] = ['title' => 'Comprovantes', 'page' => '/plugins/reembolso/front/documentos.php', 'icon' => 'ti ti-files'];
        if (\Session::haveRight('dropdown', READ)) {
            $items[strtolower(Tipodespesa::class)] = ['title' => Tipodespesa::getTypeName(2), 'page' => Tipodespesa::getSearchURL(false), 'icon' => Tipodespesa::getIcon()];
            $items[strtolower(Centrocusto::class)] = ['title' => Centrocusto::getTypeName(2), 'page' => Centrocusto::getSearchURL(false), 'icon' => Centrocusto::getIcon()];
        }
        if (\Session::haveRight('config', UPDATE)) {
            $items['config'] = ['title' => 'Configuração de reembolsos', 'page' => '/plugins/reembolso/front/config.form.php', 'icon' => 'ti ti-settings'];
        }
        return $items;
    }

    /** Item do menu (chave de Menu::content) para o Html::header marcar o ativo. */
    public static function item(string $class): string
    {
        return strtolower($class);
    }

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/reembolso' . $path;
    }

    /** Versão do arquivo para o ?v= (a versão fixa do plugin deixava o navegador com o arquivo antigo). */
    public static function fileVersion(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . $path;
        return PLUGIN_REEMBOLSO_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
    }

    /** Subtítulo de cada página: para que serve a tela. */
    public const SUBTITLES = [
        'reembolso' => 'Solicitações de reembolso de despesas dos técnicos em atendimentos externos. Crie a solicitação, lance as despesas com comprovante e envie para aprovação; depois de aprovada, o financeiro registra o pagamento.',
        'dashboard' => 'Quanto foi gasto, com o quê e por quem: indicadores e gráficos por período, técnico, centro de custo, tipo de despesa e situação, com exportação para Excel (XLSX).',
        'documentos' => 'Todos os comprovantes das solicitações de reembolso (notas, cupons, recibos e fotos), por solicitação, por categoria ou por tipo de arquivo. PDF e imagens abrem aqui mesmo.',
        'config'    => 'Regras gerais do reembolso: valor pago por KM rodado, cálculo do KM pela rota entre origem e destino e exigência de comprovante. Tipos de despesa e centros de custo ficam nas listas próprias.',
        'form_new'  => 'Informe o técnico, o centro de custo, o chamado e o local do atendimento, lance as despesas abaixo e envie para aprovação.',
        'form'      => 'Dados do atendimento, despesas lançadas com comprovantes e o andamento da aprovação e do pagamento. O histórico completo fica na aba Histórico.',
    ];

    /** Cabeçalho das páginas: seção, título, subtítulo e botões à direita. */
    public static function title(string $title, string $key, string $actions = '', string $crumb = 'Reembolso'): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        return "<div class='rb-title'><div><div class='rb-crumb'>" . $e($crumb) . "</div><h2>" . $e($title) . "</h2>"
            . (isset(self::SUBTITLES[$key]) ? "<div class='text-secondary small rb-subtitle'>" . $e(self::SUBTITLES[$key]) . "</div>" : '')
            . "</div>" . ($actions ? "<div class='d-flex flex-wrap gap-2 align-items-center'>$actions</div>" : '') . "</div>";
    }

    public static function css(): string
    {
        return "<link rel='stylesheet' href='" . htmlspecialchars(self::url('/css/reembolso.css') . '?m=' . self::fileVersion('css/reembolso.css'), ENT_QUOTES) . "'>";
    }
}
