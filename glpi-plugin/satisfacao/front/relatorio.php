<?php

/** Relatório mensal em PDF (gráficos e análise do mês). Respeita a origem, a entidade e a categoria escolhidas no painel. */

use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Relatorio;
use GlpiPlugin\Satisfacao\Survey;

Session::checkRight(Menu::$rightname, READ);

$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : Relatorio::defaultMonth();
$filters = [
    'origin'   => array_key_exists($_GET['origin'] ?? '', Survey::ORIGINS) ? $_GET['origin'] : '',
    'entity'   => ($_GET['entity'] ?? '') === '' ? null : (int) $_GET['entity'],
    'category' => ($_GET['category'] ?? '') === '' ? null : (int) $_GET['category'],
];

$pdf = Relatorio::build($month, $filters);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
// ?view=1 abre o PDF no navegador; sem isso, baixa o arquivo
header('Content-Disposition: ' . (isset($_GET['view']) ? 'inline' : 'attachment') . '; filename="Relatorio-satisfacao-' . $month . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
exit;
