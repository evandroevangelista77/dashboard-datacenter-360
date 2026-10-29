<?php

namespace GlpiPlugin\Sistemasinternos;

use CommonGLPI;
use Dropdown;
use Html;
use Profile;
use Session;
use Throwable;

/**
 * Aba "Sistemas Internos 360" em Administração → Perfis: direitos do plugin e os
 * hostgroups do Zabbix que o perfil pode gerenciar.
 */
class ProfileTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Sistemas Internos 360', 0, null, Menu::ICON);
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
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . $e(Profile::getFormURL()) . "'>";
        }
        $item->displayRightsChoiceMatrix(
            [
                ['itemtype' => Painel::class, 'label' => 'Painel 360 e alertas', 'field' => Menu::RIGHT_PAINEL],
                ['itemtype' => Host::class, 'label' => 'Hosts do Zabbix', 'field' => Menu::RIGHT_HOST],
                ['itemtype' => Settings::class, 'label' => 'Configurações do plugin', 'field' => Menu::RIGHT_CONFIG],
            ],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Sistemas Internos 360']
        );
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='$id'>"
                . "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }

        // grupos do Zabbix do perfil (gravados pela página do plugin)
        $current = Access::profileGroups($id);
        echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-server-2 me-2'></i>Grupos do Zabbix que este perfil pode gerenciar</h3></div><div class='card-body'>";
        $all = [];
        $error = '';
        if (Settings::isConfigured()) {
            try {
                $all = Zabbix::allGroups();
            } catch (Throwable $ex) {
                $error = $ex->getMessage();
            }
        } else {
            $error = 'A conexão com o Zabbix ainda não foi configurada (Sistemas Internos → Configurações).';
        }
        $default = array_map(fn ($g) => $all[$g] ?? "Grupo $g", Settings::ids('groups'));
        echo "<p class='text-secondary mb-2'>Sem grupos marcados, o perfil usa os grupos do departamento: <b>" . $e(implode(', ', $default) ?: 'nenhum') . "</b>. "
            . "A conta de serviço do Zabbix limita o resto: o GLPI nunca alcança o que ela não alcança.</p>";
        if ($error) {
            echo Menu::error($error);
        }
        if ($canedit && $all) {
            echo "<form method='post' action='" . $e(Menu::url('/front/profile.form.php')) . "'>"
                . "<input type='hidden' name='profiles_id' value='$id'>"
                . Dropdown::showFromArray('groups', $all, ['multiple' => true, 'values' => array_keys($current), 'display' => false, 'width' => '100%'])
                . "<div class='mt-2'><button class='btn btn-primary' name='save_groups' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar grupos</button></div>";
            Html::closeForm();
        } elseif ($current) {
            echo "<div>" . $e(implode(', ', $current)) . "</div>";
        }
        echo "</div></div></div>";
        return true;
    }
}
