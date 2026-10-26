<?php

namespace GlpiPlugin\Rh;

use CommonDBTM;
use Document_Item;
use CommonGLPI;

/**
 * Documentos do RH na biblioteca: vagas, currículos do banco de candidatos e
 * documentos admissionais.
 */
class Docs extends CommonGLPI
{
    public static function sources(): array
    {
        return [
            Vaga::class => ['label' => 'Vagas', 'icon' => 'ti-briefcase-2', 'color' => '#3b82f6',
                'code' => fn ($f) => 'VG-' . (int) $f['id'], 'title' => fn ($f) => (string) $f['name'], 'sub' => fn ($f) => Vaga::STATUSES[(int) $f['status']][0] ?? ''],
            Candidato::class => ['label' => 'Currículos (Banco de Currículo)', 'icon' => 'ti-user-search', 'color' => '#a855f7',
                'code' => fn ($f) => 'Candidato', 'title' => fn ($f) => (string) $f['name'], 'sub' => fn ($f) => (string) $f['city'], 'edit' => fn ($i) => Vaga::isRH()],
            Admissao::class => ['label' => 'Admissões', 'icon' => 'ti-user-plus', 'color' => '#ec4899',
                'code' => fn ($f) => 'Admissão', 'title' => fn ($f) => (string) $f['name'], 'sub' => fn ($f) => $f['start_date'] ? 'início ' . date('d/m/Y', strtotime($f['start_date'])) : ''],
        ];
    }

    /** Card de documentos no formulário do item, com os scripts. */
    public static function card(CommonDBTM $item, string $title, string $hint): string
    {
        return Biblioteca::card(self::sources(), $item, Menu::url('/front/documentos.form.php'), $title, $hint) . Menu::script('drawer.js') . Menu::script('biblioteca.js');
    }

    // ------------------------------------------------------------------ aba Documentos (no lugar da aba nativa)

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem()) {
            return '';
        }
        $n = $_SESSION['glpishow_count_on_tabs'] ? countElementsInTable(Document_Item::getTable(), ['itemtype' => $item::class, 'items_id' => $item->getID()]) : 0;
        return self::createTabEntry('Documentos', $n, $item::class, 'ti ti-files');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        echo "<div class='oak-dl-tab'>" . match ($item::class) {
            Candidato::class => self::card($item, 'Documentos do candidato', 'Currículo, certificados, portfólio, carta de referência…'),
            Admissao::class => self::card($item, 'Documentos admissionais', 'RG, CPF, comprovante de residência, ASO, contrato assinado…'),
            default => self::card($item, 'Documentos da vaga', 'Requisição assinada, organograma, descrição do cargo, anúncio…'),
        } . "</div>";
        return true;
    }
}
