<?php

namespace GlpiPlugin\Gestaoeventos;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Gestão de Eventos" em Administração → Perfis. */
class ProfileTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Gestão de Eventos', 0, null, Evento::getIcon());
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
            [['itemtype' => Evento::class, 'label' => 'Eventos', 'field' => Evento::$rightname]],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Gestão de Eventos']
        );
        echo "<div class='small text-secondary mt-2'><b>Ler</b>: vê os eventos (das entidades do perfil) e o painel. <b>Criar</b>: abre briefings. <b>Atualizar</b>: edita qualquer evento, move etapas, pula, cancela e reabre. "
            . "<b>Aprovar</b>: decide as alçadas que não têm grupo definido. <b>Financeiro</b>: vê e edita Budget, EAC, Realizado, viagens e os relatórios financeiros. "
            . "Sem <i>Atualizar</i>, o solicitante, o dono e o GP editam o próprio evento, e quem está no grupo da etapa atual conclui ou devolve a etapa. "
            . "As alçadas, as etapas e os SLOs são configurados por quem administra a configuração do GLPI.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo Acesso::profileForm($item, $canedit);
        echo "</div>";
        return true;
    }
}
