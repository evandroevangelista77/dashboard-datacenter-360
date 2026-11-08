<?php

namespace GlpiPlugin\Cronograma;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Cronograma (MS Project)" em Administração → Perfis: direitos do plugin por perfil. */
class ProfileTab extends CommonGLPI
{
    public static string $rightname = 'plugin_cronograma';

    public static function getRights($interface = 'central')
    {
        return [
            READ => 'Ver a aba Cronograma nos projetos',
            UPDATE => 'Importar cronogramas do MS Project'
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Cronograma (MS Project)', 0, null, 'ti ti-timeline');
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
            [['itemtype' => self::class, 'label' => 'Cronograma (MS Project)', 'field' => 'plugin_cronograma']],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Cronograma (MS Project)']
        );
        echo "<div class='small text-secondary mt-2'><b>Ler</b>: vê a aba 'Cronograma (MS Project)' nos projetos. <b>Atualizar</b>: importa e reimporta cronogramas.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
