<?php

/**
 * Avisos de contagem regressiva (JSON), chamados pelo avisos.js em qualquer tela:
 *  ?a=poll                       avisos pendentes do usuário agora
 *  ?a=ack&event=ID&mode=seen|snooze&minutes=N   dispensa ou adia o aviso atual do evento (só do próprio usuário)
 */

use GlpiPlugin\Gestaoeventos\Alerta;
use GlpiPlugin\Gestaoeventos\Evento;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) Session::getLoginUserID();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    return;
}
if (!Session::haveRight(Evento::$rightname, READ)) {
    http_response_code(403);
    echo json_encode(['error' => 'right']);
    return;
}

$action = (string) ($_GET['a'] ?? 'poll');
if ($action === 'ack') {
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') === 'cross-site') {
        http_response_code(403);
        echo json_encode(['error' => 'origin']);
        return;
    }
    $ev = new Evento();
    if ($ev->getFromDB((int) ($_GET['event'] ?? 0)) && $ev->canViewItem()) {
        Alerta::ack($uid, $ev->getID(), ($_GET['mode'] ?? '') === 'snooze' ? 'snooze' : 'seen', (int) ($_GET['minutes'] ?? 60));
    }
    echo json_encode(['ok' => true]);
    return;
}
echo json_encode(['now' => time(), 'poll' => Alerta::settings()['poll'], 'alerts' => Alerta::due($uid)], JSON_UNESCAPED_UNICODE);
