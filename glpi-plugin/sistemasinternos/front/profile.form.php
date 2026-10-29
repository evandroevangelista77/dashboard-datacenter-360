<?php

use GlpiPlugin\Sistemasinternos\Access;
use GlpiPlugin\Sistemasinternos\Zabbix;

Session::checkRightsOr('profile', [CREATE, UPDATE, PURGE]);

if (isset($_POST['save_groups'])) {
    $pid = (int) ($_POST['profiles_id'] ?? 0);
    $all = Zabbix::allGroups();
    $picked = array_intersect_key($all, array_flip(array_map('strval', (array) ($_POST['groups'] ?? []))));
    Access::setProfileGroups($pid, $picked);
    Session::addMessageAfterRedirect($picked ? count($picked) . ' grupo(s) do Zabbix liberado(s) para o perfil.' : 'O perfil volta a usar os grupos do departamento.', false, INFO);
}
Html::back();
