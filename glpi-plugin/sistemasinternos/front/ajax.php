<?php

/**
 * API dos alertas (JSON) para o js/alertas.js.
 *   GET  ?action=poll        → alertas novos, resumo do sinalizador e preferências
 *   GET  ?action=test&key=K  → alerta de teste (configuração)
 *   POST {action: mute, minutes} → silencia som e voz por N minutos (0 = religar)
 */

use GlpiPlugin\Sistemasinternos\Alerts;
use GlpiPlugin\Sistemasinternos\Menu;

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$uid = (int) Session::getLoginUserID();
session_write_close(); // não segura a sessão enquanto consulta o Zabbix

try {
    if (!Session::haveRight(Menu::RIGHT_PAINEL, READ)) {
        echo json_encode(['ok' => false, 'disabled' => true]);
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        if (($in['action'] ?? '') !== 'mute') {
            throw new RuntimeException('Ação desconhecida.');
        }
        Alerts::mute($uid, max(0, min(1440, (int) ($in['minutes'] ?? 0))));
        echo json_encode(['ok' => true]);
        return;
    }
    switch ($_GET['action'] ?? '') {
        case 'poll':
            echo json_encode(Alerts::poll($uid), JSON_UNESCAPED_UNICODE);
            break;
        case 'test':
            Session::checkRight(Menu::RIGHT_CONFIG, UPDATE);
            echo json_encode(['ok' => true, 'item' => Alerts::test((string) ($_GET['key'] ?? ''))], JSON_UNESCAPED_UNICODE);
            break;
        default:
            throw new RuntimeException('Ação desconhecida.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
