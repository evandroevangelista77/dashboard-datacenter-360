<?php

namespace GlpiPlugin\Avisos;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Avisos" em Administração → Perfis: direitos do plugin por perfil. */
class ProfileTab extends CommonGLPI
{
    public static string $rightname = 'plugin_avisos';

    public static function getRights($interface = 'central')
    {
        return [
            UPDATE => 'Configurar canais do Teams e avisos'
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Avisos', 0, null, 'ti ti-bell');
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
            [['itemtype' => self::class, 'label' => 'Avisos', 'field' => 'plugin_avisos']],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Avisos']
        );
        echo "<div class='small text-secondary mt-2'><b>Configurar</b>: gerencia os canais do Teams e as opções dos avisos. Todos os usuários continuam recebendo os próprios avisos.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
