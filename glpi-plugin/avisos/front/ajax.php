<?php

/**
 * API do sino e do pop-up (JSON). Cada usuário só lê e marca os próprios avisos.
 *   GET  ?action=poll  → não lidos, os últimos avisos e os novos para virar pop-up
 *   POST {action: read, id} | {action: read_all}
 */

use GlpiPlugin\Avisos\Aviso;

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');
$uid = (int) Session::getLoginUserID();
$cfg = Config::getConfigurationValues('plugin:avisos', ['popup', 'poll_seconds']);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        switch ($in['action'] ?? '') {
            case 'read':
                Aviso::markRead($uid, (int) ($in['id'] ?? 0));
                break;
            case 'read_all':
                Aviso::markRead($uid);
                break;
            default:
                throw new RuntimeException('Ação desconhecida.');
        }
        echo json_encode(['ok' => true, 'unread' => Aviso::unreadCount($uid)]);
        return;
    }
    if (($_GET['action'] ?? '') !== 'poll') {
        throw new RuntimeException('Ação desconhecida.');
    }
    echo json_encode([
        'ok'      => true,
        'unread'  => Aviso::unreadCount($uid),
        'latest'  => Aviso::latest($uid, 8),
        'new'     => ($cfg['popup'] ?? '1') ? Aviso::takeNew($uid) : [],
        'poll'    => max(20, (int) ($cfg['poll_seconds'] ?? 60)),
        'page'    => $CFG_GLPI['root_doc'] . '/plugins/avisos/front/avisos.php',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
