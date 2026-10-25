<?php

/** Meus avisos: todos os avisos do usuário, com filtro por plugin e por lidos. */

use GlpiPlugin\Avisos\Aviso;
use GlpiPlugin\Avisos\Canal;
use GlpiPlugin\Avisos\Pagina;

Session::checkLoginUser();

$uid = (int) Session::getLoginUserID();
if (($_POST['action'] ?? '') === 'read_all') {
    Aviso::markRead($uid);
    Html::back();
}

Html::header('Meus avisos', '', 'preference');
Pagina::meusAvisos($uid, isset(Canal::SOURCES[$_GET['source'] ?? '']) ? $_GET['source'] : '', !empty($_GET['unread']));
Html::footer();
