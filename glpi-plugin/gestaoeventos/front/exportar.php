<?php

/** Exportação CSV dos relatórios e do demonstrativo financeiro de um evento (mesmas consultas da tela). */

use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Relatorios;

Session::checkRight(Evento::$rightname, READ);
if (!GlpiPlugin\Gestaoeventos\Acesso::canView('exportar')) {
    Html::displayRightError();
}

try {
    if (!empty($_GET['id'])) {
        $evento = new Evento();
        if (!$evento->getFromDB((int) $_GET['id']) || !$evento->canViewItem()) {
            Html::displayNotFoundError();
        }
        [$title, $headers, $rows] = Relatorios::statement($evento);
    } else {
        [$title, $headers, $rows] = Relatorios::data((string) ($_GET['tipo'] ?? 'eventos'), $_GET);
    }
} catch (RuntimeException $e) {
    Html::displayRightError();
}
Relatorios::csv($title, $headers, $rows);
