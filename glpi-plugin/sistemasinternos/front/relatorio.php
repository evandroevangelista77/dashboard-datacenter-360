<?php

/**
 * Relatório executivo de vulnerabilidades em PDF (para a diretoria).
 *   ?entity=ID  → só os ativos daquela entidade ("none" = sem cadastro no GLPI); em branco = todos
 *   ?view=1     → abre no navegador; sem isso, baixa o arquivo
 */

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Wazuh;
use GlpiPlugin\Sistemasinternos\WazuhReport;

Session::checkRight(Menu::RIGHT_PAINEL, READ);
if (!Wazuh::isConfigured()) {
    Html::displayErrorAndDie('A conexão com o Wazuh ainda não foi configurada (Sistemas Internos → Configurações → Wazuh).');
}
$entity = preg_match('/^(\d{1,9}|none)$/', (string) ($_GET['entity'] ?? '')) ? (string) $_GET['entity'] : '';
session_write_close();
@set_time_limit(180);

try {
    $pdf = WazuhReport::build($entity);
} catch (Throwable $e) {
    Html::displayErrorAndDie('Não foi possível gerar o relatório: ' . $e->getMessage());
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['view']) ? 'inline' : 'attachment') . '; filename="Relatorio-executivo-vulnerabilidades-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
exit;
