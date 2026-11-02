<?php

/**
 * Dados do Wazuh (JSON) para as telas Vulnerabilidades e Segurança.
 *   GET  ?p=vuln/exec | vuln/assets | vuln/asset | vuln/ops | vuln/alerts | sec/overview | sec/alerts | sec/inventory (+ filtros)
 *   POST ?p=ticket  {kind: vuln|alert, agent, ref}  → abre o chamado, sem duplicar
 */

use GlpiPlugin\Sistemasinternos\Denied;
use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\NotFound;
use GlpiPlugin\Sistemasinternos\Wazuh;
use GlpiPlugin\Sistemasinternos\WazuhSec;
use GlpiPlugin\Sistemasinternos\WazuhVuln;

Session::checkLoginUser();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$json = function (int $status, mixed $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
};

if (!Session::haveRight(Menu::RIGHT_PAINEL, READ)) {
    $json(403, ['erro' => 'Sem direito de ver o painel.']);
    return;
}
$path = (string) ($_GET['p'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$post = $isPost ? (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []) : [];
if (!$isPost) {
    session_write_close(); // a tela faz várias chamadas ao mesmo tempo
}
@set_time_limit(180);

try {
    if (!Wazuh::isConfigured()) {
        $json(503, ['erro' => 'A conexão com o Wazuh ainda não foi configurada (Sistemas Internos → Configurações → Wazuh).', 'config' => true]);
        return;
    }
    if ($isPost) {
        if ($path !== 'ticket') {
            throw new NotFound('Rota desconhecida.');
        }
        $json(200, WazuhVuln::openTicket((string) ($post['kind'] ?? ''), (string) ($post['agent'] ?? ''), (string) ($post['ref'] ?? '')));
        return;
    }
    if (str_starts_with($path, 'vuln/')) {
        Wazuh::syncIfStale(); // histórico e tempo de correção: atualiza depois de responder
        $json(200, WazuhVuln::route($path, $_GET));
    } elseif (str_starts_with($path, 'sec/')) {
        $json(200, WazuhSec::route($path, $_GET));
    } else {
        throw new NotFound('Rota desconhecida.');
    }
} catch (NotFound $e) {
    $json(404, ['erro' => $e->getMessage()]);
} catch (Denied $e) {
    $json(403, ['erro' => $e->getMessage()]);
} catch (Throwable $e) {
    $json(502, ['erro' => $e->getMessage()]);
}
