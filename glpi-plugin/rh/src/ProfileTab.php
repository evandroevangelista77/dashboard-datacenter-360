<?php

namespace GlpiPlugin\Rh;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * Aba "RH" em Administração → Perfis.
 */
class ProfileTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('RH', 0, null, 'ti ti-users-group');
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
        $item->displayRightsChoiceMatrix([
            ['itemtype' => Vaga::class, 'label' => 'Recrutamento e seleção (vagas, candidatos, admissões)', 'field' => Vaga::$rightname],
            ['itemtype' => Feria::class, 'label' => 'Férias', 'field' => Feria::$rightname],
        ], ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'RH']);
        echo "<div class='small text-secondary mt-2'>Quem pede uma vaga vê as próprias requisições; o gestor indicado na vaga (ou no departamento) aprova a 1ª etapa; "
            . "<b>Aprovar como diretoria</b> aprova a 2ª. <b>Ver todas</b> + <b>Conduzir seleção</b> é o perfil do RH (banco de candidatos, funil, admissões). "
            . "Nas férias, o gestor (\"Responsável\" do usuário) aprova a equipe; <b>Aprovar de qualquer colaborador</b> é para o RH.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'><button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
