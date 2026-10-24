<?php

namespace GlpiPlugin\Reembolso;

/**
 * Endereços dos recursos do plugin.
 */
class Menu
{
    /** Chave da seção "Financeiro" no menu lateral. */
    public const SECTOR = 'financeiro';

    /** Itens da seção Financeiro conforme os direitos do usuário. */
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

    public static function css(): string
    {
        return "<link rel='stylesheet' href='" . htmlspecialchars(self::url('/css/reembolso.css') . '?m=' . self::fileVersion('css/reembolso.css'), ENT_QUOTES) . "'>";
    }
}
