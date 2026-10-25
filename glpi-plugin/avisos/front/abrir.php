<?php

/** Clique num aviso: marca como lido e vai para a página do item. */

use GlpiPlugin\Avisos\Aviso;

Session::checkLoginUser();

$uid = (int) Session::getLoginUserID();
$aviso = new Aviso();
$id = (int) ($_GET['id'] ?? 0);
if (!$aviso->getFromDBByCrit(['id' => $id, 'users_id' => $uid])) {
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/avisos/front/avisos.php');
}
Aviso::markRead($uid, $id);
Html::redirect(Aviso::safeUrl((string) $aviso->fields['url']) ?: $CFG_GLPI['root_doc'] . '/plugins/avisos/front/avisos.php');
