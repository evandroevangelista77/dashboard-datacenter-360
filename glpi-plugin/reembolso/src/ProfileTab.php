<?php

namespace GlpiPlugin\Reembolso;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * Aba "Reembolsos" em Administração → Perfis.
 */
class ProfileTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Reembolsos', 0, null, Reembolso::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }
        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlspecialchars(Profile::getFormURL(), ENT_QUOTES) . "'>";
        }
        $item->displayRightsChoiceMatrix(
            [[
                'itemtype' => Reembolso::class,
                'label'    => 'Reembolso de despesas',
                'field'    => Reembolso::$rightname,
            ]],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Reembolsos']
        );
        echo "<div class='small text-secondary mt-2'><b>Ver os próprios</b>: o técnico vê e lança só os seus. <b>Ver todos</b>: vê os de todos (gestor, financeiro). "
            . "<b>Aprovar e registrar pagamento</b>: aprova, recusa, reabre e marca como pago.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
