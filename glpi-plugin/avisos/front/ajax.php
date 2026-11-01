<?php

/**
 * API do sino e do pop-up (JSON). Cada usuário só lê e marca os próprios avisos.
 *   GET  ?action=poll&source=S  → não lidos, os últimos avisos e os novos para virar pop-up, só do plugin S
 *   POST {action: read, id} | {action: read_all, source}
 * Cada plugin (rh, pmo, reembolso, ciclo) só enxerga os próprios avisos: o sino fica na área do plugin e
 * a API sem plugin válido devolve vazio.
 */

use GlpiPlugin\Avisos\Aviso;
use GlpiPlugin\Avisos\Canal;

Session::checkLoginUser();

global $CFG_GLPI;

header('Content-Type: application/json; charset=UTF-8');
$uid = (int) Session::getLoginUserID();
$sourceIn = (string) ($_GET['source'] ?? '');
$cfg = Config::getConfigurationValues('plugin:avisos', ['popup', 'poll_seconds']);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        switch ($in['action'] ?? '') {
            case 'read':
                Aviso::markRead($uid, (int) ($in['id'] ?? 0));
                break;
            case 'read_all':
                $src = (string) ($in['source'] ?? '');
                if (!isset(Canal::SOURCES[$src])) {
                    throw new RuntimeException('Plugin desconhecido.');
                }
                Aviso::markRead($uid, null, $src);
                break;
            default:
                throw new RuntimeException('Ação desconhecida.');
        }
        echo json_encode(['ok' => true, 'unread' => Aviso::unreadCount($uid, (string) ($in['source'] ?? ''))]);
        return;
    }
    if (($_GET['action'] ?? '') !== 'poll') {
        throw new RuntimeException('Ação desconhecida.');
    }
    if (!isset(Canal::SOURCES[$sourceIn])) {
        throw new RuntimeException('Informe o plugin (source).');
    }
    echo json_encode([
        'ok'      => true,
        'source'  => $sourceIn,
        'unread'  => Aviso::unreadCount($uid, $sourceIn),
        'latest'  => Aviso::latest($uid, 8, false, $sourceIn),
        'new'     => ($cfg['popup'] ?? '1') ? Aviso::takeNew($uid, $sourceIn) : [],
        'poll'    => max(20, (int) ($cfg['poll_seconds'] ?? 60)),
        'page'    => $CFG_GLPI['root_doc'] . '/plugins/avisos/front/avisos.php?source=' . $sourceIn,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
