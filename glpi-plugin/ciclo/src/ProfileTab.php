<?php

namespace GlpiPlugin\Ciclo;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Ciclo Financeiro" em Administração → Perfis. */
class ProfileTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Ciclo Financeiro', 0, null, Ciclo::getIcon());
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
            [['itemtype' => Ciclo::class, 'label' => 'Ciclos', 'field' => Ciclo::$rightname]],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Ciclo Financeiro']
        );
        echo "<div class='small text-secondary mt-2'><b>Ler</b>: vê os Ciclos e o painel. <b>Criar</b>: abre um Ciclo a partir do aviso de fechamento. "
            . "<b>Atualizar</b>: edita qualquer Ciclo, conclui, devolve e pula fases, cancela e reabre. "
            . "Sem esse direito, quem está no grupo da fase atual conclui ou devolve a fase, e o GP e o OTC do Ciclo editam os dados.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
