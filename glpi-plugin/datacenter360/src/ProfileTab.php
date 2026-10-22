<?php

namespace GlpiPlugin\Datacenter360;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * Aba "DataCenter 360" em Administração → Perfis: define quem vê o menu.
 */
class ProfileTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('DataCenter 360', 0, null, Menu::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }

        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        $id = (int) $item->getID();

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlspecialchars(Profile::getFormURL(), ENT_QUOTES) . "'>";
        }
        $item->displayRightsChoiceMatrix(
            [[
                'itemtype' => Menu::class,
                'label'    => 'Acessar o DataCenter 360 (menu lateral)',
                'field'    => Menu::$rightname,
                'rights'   => [READ => __('Read')],
            ]],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'DataCenter 360']
        );
        if ($canedit) {
            echo "<div class='center mt-2'>";
            echo "<input type='hidden' name='id' value='$id'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button>";
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
