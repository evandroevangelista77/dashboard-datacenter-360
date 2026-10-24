<?php

/**
 * API do funil de seleção e do checklist de admissão (JSON).
 */

use GlpiPlugin\Rh\Admissao;
use GlpiPlugin\Rh\Candidatura;
use GlpiPlugin\Rh\Cargo;
use GlpiPlugin\Rh\Vaga;

Session::checkLoginUser();

$reply = function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
};

try {
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $in = $post ? (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []) : $_GET;
    switch ($in['action'] ?? '') {
        case 'pipeline':
            $reply(Candidatura::pipeline((int) ($in['vaga'] ?? 0)));
            return;
        case 'jobdesc':
            $cargo = new Cargo();
            if (!Vaga::canCreate() || !$cargo->getFromDB((int) ($in['cargo'] ?? 0))) {
                throw new RuntimeException('Cargo não encontrado.', 404);
            }
            $reply(['ok' => true] + $cargo->jobTextForVaga());
            return;
        case 'available':
            $reply(['ok' => true, 'list' => Candidatura::available((int) ($in['vaga'] ?? 0), trim((string) ($in['q'] ?? '')))]);
            return;
    }
    if (!$post) {
        throw new RuntimeException('Use POST.', 405);
    }
    switch ($in['action'] ?? '') {
        case 'add':
            $reply(['ok' => true, 'id' => Candidatura::addToVaga((int) $in['vaga'], (array) ($in['data'] ?? []))]);
            return;
        case 'move':
            $reply(['ok' => true] + Candidatura::move((int) $in['id'], (string) $in['stage'], (array) ($in['order'] ?? [])));
            return;
        case 'details':
            Candidatura::saveDetails((int) $in['id'], (array) ($in['data'] ?? []));
            $reply(['ok' => true]);
            return;
        case 'status':
            Candidatura::setStatus((int) $in['id'], (string) $in['status'], (string) ($in['comment'] ?? ''));
            $reply(['ok' => true]);
            return;
        case 'check':
            $adm = new Admissao();
            if (!$adm->getFromDB((int) ($in['admissao'] ?? 0)) || !$adm->canViewItem()) {
                throw new RuntimeException('Admissão não encontrada.', 403);
            }
            $adm->toggle((int) $in['item'], !empty($in['done']));
            $reply(['ok' => true]);
            return;
    }
    throw new RuntimeException('Ação desconhecida.', 400);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $reply(['ok' => false, 'error' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 400);
}
