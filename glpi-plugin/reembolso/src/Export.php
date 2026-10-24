<?php

namespace GlpiPlugin\Reembolso;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Planilha XLSX do dashboard (PhpSpreadsheet, que já vem com o GLPI):
 * Resumo, Despesas (detalhadas, com filtro e total) e Solicitações.
 */
class Export
{
    private const BRL = '"R$" #,##0.00';
    private const HEAD = '0E7490';

    public static function build(array $f): Spreadsheet
    {
        $rows = Report::rows($f);
        $s = Report::summary($rows);
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('GLPI - Reembolso de despesas')->setTitle('Reembolsos ' . $f['from'] . ' a ' . $f['to']);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        self::summarySheet($book->getActiveSheet(), $f, $s);
        self::expensesSheet($book->createSheet(), $rows);
        self::requestsSheet($book->createSheet(), $rows);
        $book->setActiveSheetIndex(0);
        return $book;
    }

    public static function send(array $f): void
    {
        $book = self::build($f);
        $name = 'reembolsos-' . str_replace('-', '', $f['from']) . '-' . str_replace('-', '', $f['to']) . '.xlsx';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: max-age=0, no-store');
        (new Xlsx($book))->save('php://output');
        exit;
    }

    private static function summarySheet(Worksheet $ws, array $f, array $s): void
    {
        $ws->setTitle('Resumo');
        $k = $s['kpi'];
        $ws->setCellValue('A1', 'Reembolso de despesas');
        $ws->getStyle('A1')->getFont()->setSize(16)->setBold(true)->getColor()->setRGB(self::HEAD);
        $ws->setCellValue('A2', 'Período: ' . date('d/m/Y', strtotime($f['from'])) . ' a ' . date('d/m/Y', strtotime($f['to'])) . ' · gerado em ' . date('d/m/Y H:i'));
        $filters = [];
        if ($f['users']) {
            $filters[] = 'técnico: ' . getUserName($f['users']);
        }
        if ($f['centro']) {
            $filters[] = 'centro de custo: ' . Centrocusto::label($f['centro']);
        }
        if ($f['tipo']) {
            $filters[] = 'tipo: ' . (Tipodespesa::all()[$f['tipo']]['name'] ?? '');
        }
        if ($f['status']) {
            $filters[] = 'status: ' . Reembolso::STATUSES[$f['status']][0];
        }
        $ws->setCellValue('A3', $filters ? 'Filtros: ' . implode(' · ', $filters) : 'Sem filtros (todas as despesas visíveis do período)');
        $ws->getStyle('A2:A3')->getFont()->getColor()->setRGB('64748B');

        $line = 5;
        $kpis = [
            ['Total lançado (sem recusados)', $k['total'], true], ['Rascunho', $k['draft'], true], ['Aguardando aprovação', $k['pending'], true],
            ['Aprovado a pagar', $k['approved'], true], ['Pago', $k['paid'], true], ['Recusado (fora do total)', $k['refused'], true],
            ['KM rodados', $k['km'], false], ['Solicitações', $k['requests'], false], ['Despesas', $k['expenses'], false],
            ['Média por solicitação', $k['average'], true], ['Despesas acima do limite do tipo', $k['over'], false],
        ];
        self::header($ws, $line, ['Indicador', 'Valor']);
        foreach ($kpis as [$label, $value, $money]) {
            $line++;
            $ws->setCellValue("A$line", $label);
            $ws->setCellValue("B$line", $value);
            $ws->getStyle("B$line")->getNumberFormat()->setFormatCode($money ? self::BRL : '#,##0.0');
        }
        $ws->getStyle("A5:B$line")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        $col = 4; // D: tabelas lado a lado
        foreach (['Por tipo de despesa' => $s['type'], 'Por técnico' => $s['user'], 'Por centro de custo' => $s['centro']] as $title => $data) {
            $c1 = Coordinate::stringFromColumnIndex($col);
            $c2 = Coordinate::stringFromColumnIndex($col + 1);
            $ws->setCellValue("{$c1}5", $title);
            $ws->getStyle("{$c1}5")->getFont()->setBold(true)->getColor()->setRGB(self::HEAD);
            $r = 6;
            self::header($ws, $r, ['Item', 'Valor'], $col);
            foreach ($data as $name => $value) {
                $r++;
                $ws->setCellValue("$c1$r", $name);
                $ws->setCellValue("$c2$r", round($value, 2));
                $ws->getStyle("$c2$r")->getNumberFormat()->setFormatCode(self::BRL);
            }
            $r++;
            $ws->setCellValue("$c1$r", 'Total');
            $ws->setCellValue("$c2$r", "=SUM({$c2}7:$c2" . ($r - 1) . ')');
            $ws->getStyle("$c1$r:$c2$r")->getFont()->setBold(true);
            $ws->getStyle("$c2$r")->getNumberFormat()->setFormatCode(self::BRL);
            $ws->getColumnDimension($c1)->setWidth(38);
            $ws->getColumnDimension($c2)->setWidth(16);
            $col += 3;
        }

        $r = $line + 3;
        $ws->setCellValue("A$r", 'Por mês (sem recusados)');
        $ws->getStyle("A$r")->getFont()->setBold(true)->getColor()->setRGB(self::HEAD);
        $r++;
        self::header($ws, $r, ['Mês', 'Rascunho', 'Aguardando', 'Aprovado', 'Pago', 'Total']);
        foreach (Report::months($f['from'], $f['to']) as $m) {
            $r++;
            $v = $s['month'][$m] ?? [];
            $ws->fromArray([date('m/Y', strtotime("$m-01")), $v[Reembolso::DRAFT] ?? 0, $v[Reembolso::SUBMITTED] ?? 0, $v[Reembolso::APPROVED] ?? 0, $v[Reembolso::PAID] ?? 0], null, "A$r");
            $ws->setCellValue("F$r", "=SUM(B$r:E$r)");
            $ws->getStyle("B$r:F$r")->getNumberFormat()->setFormatCode(self::BRL);
        }
        $ws->getColumnDimension('A')->setWidth(34);
        $ws->getColumnDimension('B')->setWidth(18);
        foreach (['C', 'E', 'F'] as $c) {
            if ($ws->getColumnDimension($c)->getWidth() < 14) {
                $ws->getColumnDimension($c)->setWidth(14);
            }
        }
    }

    private static function expensesSheet(Worksheet $ws, array $rows): void
    {
        $ws->setTitle('Despesas');
        $cols = ['Solicitação', 'Status', 'Técnico', 'Centro de custo', 'Localização', 'Local do atendimento', 'Chamado', 'Data', 'Tipo de despesa', 'Descrição',
            'Origem', 'Destino', 'Ida e volta', 'KM inicial', 'KM final', 'KM rodado', 'Valor por KM', 'Valor (R$)', 'Acima do limite'];
        self::header($ws, 1, $cols);
        $r = 1;
        foreach ($rows as $x) {
            $r++;
            $ws->fromArray([
                $x['code'], Reembolso::STATUSES[$x['status']][0] ?? '', $x['tecnico'], $x['centro_label'], (string) $x['location'], (string) $x['address'],
                $x['tickets_id'] ? '#' . $x['tickets_id'] . ' ' . $x['ticket'] : '', null, (string) $x['tipo'], (string) $x['description'],
                $x['is_mileage'] ? (string) $x['origin'] : '', $x['is_mileage'] ? (string) $x['destination'] : '', $x['is_mileage'] ? ($x['round_trip'] ? 'Sim' : 'Não') : '',
                $x['km_start'] === null ? null : (float) $x['km_start'], $x['km_end'] === null ? null : (float) $x['km_end'],
                $x['is_mileage'] ? $x['distance'] : null, $x['is_mileage'] ? (float) $x['km_rate'] : null, $x['amount'], $x['over'] ? 'Sim' : '',
            ], null, "A$r", true);
            $ws->setCellValue("H$r", XlsDate::PHPToExcel(strtotime($x['date'] . ' 12:00:00')));
        }
        $last = max(2, $r);
        $ws->getStyle("H2:H$last")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle("N2:P$last")->getNumberFormat()->setFormatCode('#,##0.0');
        $ws->getStyle("Q2:R$last")->getNumberFormat()->setFormatCode(self::BRL);
        $total = $r + 1;
        $ws->setCellValue("Q$total", 'Total');
        $ws->setCellValue("R$total", $r >= 2 ? "=SUBTOTAL(9,R2:R$r)" : 0);
        $ws->setCellValue("P$total", $r >= 2 ? "=SUBTOTAL(9,P2:P$r)" : 0);
        $ws->getStyle("P$total:R$total")->getFont()->setBold(true);
        $ws->getStyle("R$total")->getNumberFormat()->setFormatCode(self::BRL);
        $ws->getStyle("P$total")->getNumberFormat()->setFormatCode('#,##0.0');
        self::finish($ws, count($cols), $r, [12, 20, 22, 26, 22, 26, 26, 11, 30, 34, 28, 28, 10, 10, 10, 10, 12, 14, 12]);
    }

    private static function requestsSheet(Worksheet $ws, array $rows): void
    {
        global $DB;
        $ws->setTitle('Solicitações');
        $cols = ['Solicitação', 'Status', 'Técnico', 'Centro de custo', 'Chamado', 'Período: início', 'Período: fim', 'Despesas no filtro', 'Total no filtro (R$)', 'Total da solicitação (R$)', 'Aprovado por', 'Data da aprovação', 'Data do pagamento', 'Referência do pagamento'];
        self::header($ws, 1, $cols);
        $ids = array_values(array_unique(array_map(fn ($x) => (int) $x['reembolso'], $rows)));
        $sum = $count = [];
        foreach ($rows as $x) {
            $sum[$x['reembolso']] = ($sum[$x['reembolso']] ?? 0) + $x['amount'];
            $count[$x['reembolso']] = ($count[$x['reembolso']] ?? 0) + 1;
        }
        $r = 1;
        if ($ids) {
            foreach ($DB->request(['FROM' => Reembolso::getTable(), 'WHERE' => ['id' => $ids], 'ORDER' => 'id']) as $q) {
                $r++;
                $ws->fromArray([
                    $q['name'], Reembolso::STATUSES[(int) $q['status']][0] ?? '', (string) getUserName((int) $q['users_id']), Centrocusto::label((int) $q['plugin_reembolso_centrocustos_id']),
                    $q['tickets_id'] ? '#' . $q['tickets_id'] : '', null, null, $count[$q['id']] ?? 0, round($sum[$q['id']] ?? 0, 2), (float) $q['total'],
                    $q['users_id_approver'] ? (string) getUserName((int) $q['users_id_approver']) : '', null, null, (string) $q['payment_reference'],
                ], null, "A$r", true);
                foreach (['F' => $q['date_begin'], 'G' => $q['date_end'], 'L' => $q['date_approval'], 'M' => $q['date_payment']] as $c => $d) {
                    if ($d) {
                        $ws->setCellValue("$c$r", XlsDate::PHPToExcel(strtotime($d)));
                    }
                }
            }
        }
        $last = max(2, $r);
        $ws->getStyle("F2:G$last")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle("L2:L$last")->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        $ws->getStyle("M2:M$last")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle("I2:J$last")->getNumberFormat()->setFormatCode(self::BRL);
        self::finish($ws, count($cols), $r, [12, 20, 22, 26, 10, 12, 12, 10, 16, 18, 22, 16, 14, 26]);
    }

    private static function header(Worksheet $ws, int $row, array $titles, int $firstCol = 1): void
    {
        foreach ($titles as $i => $t) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($firstCol + $i) . $row, $t);
        }
        $range = Coordinate::stringFromColumnIndex($firstCol) . $row . ':' . Coordinate::stringFromColumnIndex($firstCol + count($titles) - 1) . $row;
        $style = $ws->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEAD);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    /** Cabeçalho fixo, filtro automático, larguras e linhas alternadas. */
    private static function finish(Worksheet $ws, int $ncols, int $lastRow, array $widths): void
    {
        $lastCol = Coordinate::stringFromColumnIndex($ncols);
        $ws->freezePane('A2');
        $ws->setAutoFilter("A1:$lastCol" . max(1, $lastRow));
        $ws->getRowDimension(1)->setRowHeight(30);
        foreach ($widths as $i => $w) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }
        for ($r = 3; $r <= $lastRow; $r += 2) {
            $ws->getStyle("A$r:$lastCol$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        }
    }
}
