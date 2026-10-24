<?php

/**
 * API do formulário de despesas (JSON): salvar e excluir despesa e calcular rota.
 * CSRF: o GLPI 12 valida a origem da requisição (Sec-Fetch-Site).
 */

use GlpiPlugin\Reembolso\Despesa;
use GlpiPlugin\Reembolso\Reembolso;
use GlpiPlugin\Reembolso\Route;

Session::checkLoginUser();

$reply = function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
};

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Use POST.', 405);
    }
    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $reembolso = new Reembolso();
    if (!$reembolso->getFromDB((int) ($in['reembolso'] ?? 0)) || !$reembolso->canViewItem()) {
        throw new RuntimeException('Solicitação não encontrada ou sem permissão.', 403);
    }
    switch ($in['action'] ?? '') {
        case 'save':
            $reply(['ok' => true, 'id' => Despesa::save($reembolso, (array) ($in['data'] ?? []))]);
            return;
        case 'delete':
            Despesa::remove($reembolso, (int) ($in['id'] ?? 0));
            $reply(['ok' => true]);
            return;
        case 'route':
            if (!$reembolso->isEditable()) {
                throw new RuntimeException('Esta solicitação não pode mais ser alterada nesta etapa.', 403);
            }
            $reply(['ok' => true] + Route::distance((string) ($in['origin'] ?? ''), (string) ($in['destination'] ?? '')));
            return;
    }
    throw new RuntimeException('Ação desconhecida.', 400);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $reply(['ok' => false, 'error' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 400);
}
