<?php

namespace GlpiPlugin\Reembolso;

use CommonDBTM;
use Document_Item;
use CommonGLPI;
use Dropdown;
use Html;

/** Comprovantes das solicitações de reembolso na biblioteca de documentos. */
class Docs extends CommonGLPI
{
    public static function sources(): array
    {
        return [
            Reembolso::class => ['label' => 'Solicitações de reembolso', 'icon' => 'ti-receipt-2', 'color' => '#22c55e',
                'code' => fn ($f) => Reembolso::code((int) $f['id']),
                'title' => fn ($f) => getUserName((int) $f['users_id']) . ' · ' . Money::format((float) $f['total']),
                'sub' => fn ($f) => Dropdown::getDropdownName(Centrocusto::getTable(), (int) $f['plugin_reembolso_centrocustos_id'], false, true, false, '') . ' · ' . (Reembolso::STATUSES[(int) $f['status']][0] ?? ''),
                'edit' => fn ($i) => $i->isEditable() || Reembolso::isApprover()],
        ];
    }

    public static function card(CommonDBTM $item): string
    {
        return Biblioteca::card(self::sources(), $item, Menu::url('/front/documentos.form.php'), 'Comprovantes', 'Nota fiscal, cupom, recibo de pedágio ou estacionamento, foto do odômetro…')
            . Html::script(Menu::url('/js/drawer.js'), ['version' => Menu::fileVersion('js/drawer.js')], false)
            . Html::script(Menu::url('/js/biblioteca.js'), ['version' => Menu::fileVersion('js/biblioteca.js')], false);
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
        echo "<div class='oak-dl-tab'>" . self::card($item) . "</div>";
        return true;
    }
}
