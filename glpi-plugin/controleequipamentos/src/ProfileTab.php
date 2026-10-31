<?php

namespace GlpiPlugin\Controleequipamentos;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Controle de Equipamentos" em Administração → Perfis. */
class ProfileTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Controle de Equipamentos');
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
            [['itemtype' => Entrega::class, 'label' => 'Controle de Equipamentos', 'field' => Menu::RIGHT]],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Controle de Equipamentos']
        );
        echo "<div class='small text-secondary mt-2'><b>Ler</b>: vê os painéis e as listas de entrega e devolução. "
            . "<b>Criar</b>: abre fluxos de entrega (com chamado) e devoluções. "
            . "<b>Atualizar</b>: avança etapas de qualquer fluxo, conclui ou cancela devoluções e configura os grupos. "
            . "Sem Atualizar, quem é do grupo da etapa atual avança essa etapa.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
