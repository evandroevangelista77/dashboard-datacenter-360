<?php

namespace GlpiPlugin\Satisfacao;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Satisfação" em Administração → Perfis: direitos do plugin por perfil. */
class ProfileTab extends CommonGLPI
{
    public static function getRights($interface = 'central')
    {
        return [
            READ => __('Read'),
            UPDATE => __('Update'),
            CREATE => __('Create'),
            PURGE => __('Delete permanently')
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Satisfação', 0, null, 'ti ti-mood-smile');
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
            [['itemtype' => self::class, 'label' => 'Satisfação', 'field' => 'plugin_satisfacao']],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Satisfação']
        );
        echo "<div class='small text-secondary mt-2'><b>Ler</b>: vê as pesquisas e o painel. <b>Atualizar/Criar</b>: gerencia perguntas e campanhas.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
