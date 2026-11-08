<?php

namespace GlpiPlugin\Assinatura;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Assinatura digital" em Administração → Perfis: direitos do plugin por perfil. */
class ProfileTab extends CommonGLPI
{
    public static string $rightname = 'plugin_assinatura';

    public static function getRights($interface = 'central')
    {
        return [
            READ => 'Usar a aba Assinatura digital no chamado',
            UPDATE => 'Configurar a conexão com o LibreSign'
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Assinatura digital', 0, null, 'ti ti-signature');
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
            [['itemtype' => self::class, 'label' => 'Assinatura digital', 'field' => 'plugin_assinatura']],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Assinatura digital']
        );
        echo "<div class='small text-secondary mt-2'><b>Ler</b>: vê e usa a aba 'Assinatura digital' nos chamados. <b>Atualizar</b>: configura a conexão com o LibreSign.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
