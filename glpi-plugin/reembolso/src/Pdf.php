<?php

namespace GlpiPlugin\Reembolso;

/**
 * PDFs do lote e do adiantamento (TCPDF, que já vem com o GLPI). Cabeçalho e rodapé
 * em todas as páginas (PdfDoc), indicadores no topo, seções numeradas, o fluxo de
 * aprovação com quem decide cada etapa e, na última página, o termo e o quadro onde
 * o LibreSign desenha a assinatura do técnico.
 */
class Pdf
{
    private const INK = '#0f172a';
    private const MUTED = '#64748b';
    private const LINE = '#e2e8f0';
    private const ACCENT = '#0e7490';
    private const NIGHT = '#6d28d9';
    private const NIGHT_BG = '#f3effe';

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    /** "Chamado nº 123 - título" (ou o aviso de que a solicitação não tem chamado). */
    public static function ticket(int $id): string
    {
        if (!$id) {
            return "<span style='color:#b45309'><b>Sem chamado</b></span>";
        }
        $t = new \Ticket();
        $name = $t->getFromDB($id) ? (string) $t->fields['name'] : '';
        return "<b>Chamado nº $id</b>" . ($name !== '' ? "<br><span style='color:" . self::MUTED . "'>" . self::e(mb_strimwidth($name, 0, 60, '…')) . '</span>' : '');
    }

    /** Data e hora do atendimento: "24/10/2026 21:00 a 25/10/2026 01:30". */
    public static function when(array $r): string
    {
        if (empty($r['date_begin'])) {
            return '—';
        }
        $a = date('d/m/Y', strtotime((string) $r['date_begin'])) . ($r['time_begin'] ? ' ' . substr((string) $r['time_begin'], 0, 5) : '');
        $sameDay = $r['date_end'] === $r['date_begin'];
        $b = ($sameDay ? '' : date('d/m/Y', strtotime((string) $r['date_end']))) . ($r['time_end'] ? ' ' . substr((string) $r['time_end'], 0, 5) : '');
        return $a . (trim($b) !== '' ? ' a ' . trim($b) : '');
    }

    /** Horas comerciais, fora do horário e noturnas de um atendimento, em uma linha curta. */
    private static function hours(array $r): string
    {
        if (empty($r['time_begin']) || empty($r['time_end'])) {
            return "<span style='color:" . self::MUTED . "'>sem horário</span>";
        }
        $out = 'Com. ' . Money::minutes((int) $r['minutes_business']) . '<br>Fora ' . Money::minutes((int) $r['minutes_outside']);
        if ((int) $r['minutes_night']) {
            $out .= "<br><span style='color:" . self::NIGHT . "'><b>Not. " . Money::minutes((int) $r['minutes_night']) . '</b></span>';
        }
        return $out;
    }

    // ------------------------------------------------------------------ blocos

    private static function doc(string $kind, string $title, string $right, string $rightSub, string $author): PdfDoc
    {
        $pdf = new PdfDoc();
        $pdf->docKind = $kind;
        $pdf->docTitle = $title;
        $pdf->docRight = $right;
        $pdf->docRightSub = $rightSub;
        $pdf->docFoot = 'Gerado pelo GLPI em ' . date('d/m/Y H:i') . ' · ' . $title . ' · documento para conferência e assinatura digital';
        $pdf->SetAuthor($author);
        $pdf->SetTitle("$title - $author");
        $pdf->AddPage();
        return $pdf;
    }

    /** Indicadores: [rótulo, valor, detalhe, cor]. */
    private static function kpis(array $items): string
    {
        $w = round(100 / count($items), 2);
        $html = "<table cellspacing='0' cellpadding='0'><tr>";
        foreach ($items as $i => [$label, $value, $sub, $color]) {
            $html .= "<td width='$w%'><table cellpadding='5'><tr><td style='background-color:#f8fafc;border-left:1.6px solid $color" . ($i ? ';' : ';') . "'>"
                . "<span style='font-size:6.5px;color:$color'><b>" . self::e(mb_strtoupper($label)) . "</b></span><br>"
                . "<span style='font-size:12.5px;color:" . self::INK . "'><b>$value</b></span><br>"
                . "<span style='font-size:7px;color:" . self::MUTED . "'>$sub</span></td><td width='4'></td></tr></table></td>";
        }
        return $html . '</tr></table>';
    }

    /** Pares rótulo/valor em colunas. */
    private static function facts(array $pairs, int $cols = 4): string
    {
        $w = round(100 / $cols, 2);
        $html = "<table cellpadding='4' style='border-top:0.3px solid " . self::LINE . ";border-bottom:0.3px solid " . self::LINE . "'>";
        foreach (array_chunk($pairs, $cols, true) as $row) {
            $html .= '<tr>';
            foreach ($row as $label => $value) {
                $html .= "<td width='$w%'><span style='font-size:6.5px;color:" . self::MUTED . "'>" . self::e(mb_strtoupper($label)) . "</span><br><b>$value</b></td>";
            }
            $html .= str_repeat("<td width='$w%'></td>", $cols - count($row)) . '</tr>';
        }
        return $html . '</table>';
    }

    private static function section(int $n, string $title, string $hint = ''): string
    {
        return "<br><table cellpadding='0'><tr><td width='5%' style='font-size:11px;color:" . self::ACCENT . "'><b>" . sprintf('%02d', $n) . "</b></td>"
            . "<td width='95%' style='font-size:11px;color:" . self::INK . "'><b>" . self::e($title) . "</b>"
            . ($hint !== '' ? "<br><span style='font-size:7.5px;color:" . self::MUTED . "'>" . self::e($hint) . '</span>' : '') . "</td></tr></table>"
            . "<table cellpadding='0'><tr><td style='border-bottom:0.6px solid " . self::INK . ";font-size:2px'>&nbsp;</td></tr></table><br>";
    }

    /** Cabeçalho escuro de tabela: [título, largura %, alinhamento]. */
    private static function thead(array $cols): string
    {
        $html = "<thead><tr style='background-color:" . self::INK . ";color:#ffffff'>";
        foreach ($cols as [$label, $w, $align]) {
            $html .= "<th width='$w%' align='$align' style='font-size:7px'><b>" . self::e(mb_strtoupper($label)) . '</b></th>';
        }
        return $html . '</tr></thead>';
    }

    private static function note(string $html, bool $night): string
    {
        return "<table cellpadding='6'><tr><td style='background-color:" . ($night ? self::NIGHT_BG : '#f8fafc') . ';border-left:1.6px solid ' . ($night ? self::NIGHT : '#94a3b8') . "'>$html</td></tr></table>";
    }

    /** Fluxo de aprovação: quem responde por cada etapa e o que já foi decidido nesta rodada. */
    private static function flow(Lote $item, bool $advance): string
    {
        $f = $item->fields;
        $cfg = Money::config();
        $status = (int) $f['status'];
        $done = [];
        foreach ($item->decisions() as $d) {
            if (in_array($d['decision'], ['aprovado', 'pago'], true)) {
                $done[$d['step'] . ':' . (int) $d['plugin_reembolso_centrocustos_id']] = $d;
            }
        }
        $rows = [['tecnico', 0, 'Técnico', $advance ? 'Confere e assina o pedido' : 'Confere e assina o lote', (string) getUserName((int) $f['users_id']), Lote::SIGN],
            ['gestor', 0, 'Gestor da área', 'Aprova', Lote::whoLabel((int) $f['users_id_area_manager'], (int) ($f['groups_id_area_manager'] ?? 0)), Lote::MANAGER]];
        foreach ($item->centers() as $cid => $c) {
            $rows[] = ['centro', $cid, 'Gestor do centro de custo', Centrocusto::label((int) $cid), Lote::whoLabel($c['user'], $c['group']), Lote::CENTER];
        }
        $rows[] = ['otc', 0, 'OTC', 'Valida', Lote::whoLabel($cfg['otc_users_id'], $cfg['otc_groups_id']), Lote::OTC];
        $rows[] = ['financeiro', 0, 'Financeiro', 'Paga e anexa o comprovante', Lote::whoLabel($cfg['finance_users_id'], $cfg['finance_groups_id']), Lote::FINANCE];

        $html = "<table cellpadding='4' style='border-bottom:0.3px solid " . self::LINE . "'>" . self::thead([['Etapa', 5, 'center'], ['Responsável pela etapa', 47, 'left'], ['Quem responde', 26, 'left'], ['Situação', 22, 'left']]) . '<tbody>';
        foreach ($rows as $i => [$step, $cid, $label, $what, $who, $st]) {
            $d = $done["$step:$cid"] ?? null;
            if ($d) {
                $state = "<span style='color:#15803d'><b>" . ($d['decision'] === 'pago' ? 'Pago' : 'Aprovado') . '</b></span><br>' . date('d/m/Y H:i', strtotime((string) $d['date'])) . '<br>' . self::e(getUserName((int) $d['users_id']));
            } elseif ($st === $status || ($status === Lote::RETURNED && $st === Lote::SIGN)) {
                $state = "<span style='color:#b45309'><b>" . ($status === Lote::RETURNED ? 'Devolvido' : 'Em andamento') . '</b></span>';
            } else {
                $state = "<span style='color:" . self::MUTED . "'>Pendente</span>";
            }
            $html .= '<tr' . ($i % 2 ? " style='background-color:#f8fafc'" : '') . "><td width='5%' align='center' style='color:" . self::ACCENT . "'><b>" . ($i + 1) . "</b></td>"
                . "<td width='47%'><b>" . self::e($label) . "</b><br><span style='color:" . self::MUTED . "'>" . self::e($what) . "</span></td>"
                . "<td width='26%'>" . self::e($who) . "</td><td width='22%'>$state</td></tr>";
        }
        return $html . '</tbody></table>';
    }

    // ------------------------------------------------------------------ lote

    public static function lote(Lote $lote): string
    {
        $e = [self::class, 'e'];
        $f = $lote->fields;
        $code = Lote::code($lote->getID());
        $tech = (string) getUserName((int) $f['users_id']);
        $cfg = Money::config();
        $byReq = [];
        foreach ($lote->expenses() as $r) {
            $byReq[(int) $r['rid']][] = $r;
        }
        $reqs = [];
        foreach ($lote->requests() as $r) {
            $reqs[(int) $r['id']] = $r;
        }
        $nightWin = $e($cfg['night_start']) . ' às ' . $e($cfg['night_end']);

        $pdf = self::doc('Lote de reembolso de despesas', $code, $tech, Lote::periodLabel($f), $tech);
        $html = self::kpis([
            ['Total do lote', Money::format((float) $f['total']), 'a pagar ao técnico', self::ACCENT],
            ['Atendimentos', (string) count($reqs), count(array_filter($reqs, fn ($r) => (int) $r['tickets_id'])) . ' com chamado', '#0f766e'],
            ['Despesas', (string) (int) $f['expense_count'], 'lançadas com data e hora', '#475569'],
            ['Adicional noturno', (int) $f['night_count'] . ' · ' . Money::format((float) $f['night_total']), "despesas das $nightWin", self::NIGHT],
        ]) . '<br><br>'
            . self::facts([
                'Técnico' => $e($tech),
                'Gestor da área' => $e(Lote::whoLabel((int) $f['users_id_area_manager'], (int) ($f['groups_id_area_manager'] ?? 0))),
                'Período' => $e(Lote::periodLabel($f)),
                'Periodicidade' => $e(Lote::PERIODS[$f['period']] ?? 'Gerado na hora'),
            ]);
        if ((int) $f['night_count']) {
            $html .= '<br><br>' . self::note("<span style='color:" . self::NIGHT . "'><b>&#9790; Adicional noturno</b></span><br><b>" . (int) $f['night_count'] . "</b> despesa(s) no horário das $nightWin, somando <b>"
                . Money::format((float) $f['night_total']) . '</b>. Elas aparecem destacadas em lilás na seção 2.', true);
        }

        // 1. atendimentos: chamado, data e hora, horas, centro de custo e valor
        $html .= self::section(1, 'Atendimentos do lote', 'Um atendimento (solicitação de reembolso) por linha, com o chamado, a data e hora e o valor.')
            . "<table cellpadding='4' style='border-bottom:0.3px solid " . self::LINE . "'>"
            . self::thead([['Chamado', 29, 'left'], ['Data e hora', 19, 'left'], ['Horas', 13, 'left'], ['Centro de custo · local', 24, 'left'], ['Valor', 15, 'right']]) . '<tbody>';
        $i = 0;
        foreach ($reqs as $rid => $r) {
            $items = $byReq[$rid] ?? [];
            $sub = array_sum(array_map(fn ($x) => (float) $x['amount'], $items));
            $html .= '<tr' . ($i++ % 2 ? " style='background-color:#f8fafc'" : '') . "><td width='29%'>" . self::ticket((int) $r['tickets_id']) . "<br><span style='color:" . self::ACCENT . "'>" . Reembolso::code($rid) . '</span></td>'
                . "<td width='19%'>" . self::when($r) . "</td><td width='13%' style='font-size:7.5px'>" . self::hours($r) . '</td>'
                . "<td width='24%'>" . $e(Centrocusto::label((int) $r['plugin_reembolso_centrocustos_id'])) . "<br><span style='color:" . self::MUTED . "'>" . $e($r['address'] ?: '—') . '</span></td>'
                . "<td width='15%' align='right'><b>" . Money::format($sub) . "</b><br><span style='color:" . self::MUTED . "'>" . count($items) . ' despesa(s)</span></td></tr>';
        }
        $html .= "<tr><td width='85%' align='right' style='border-top:0.6px solid " . self::INK . "'><b>Total do lote</b></td><td width='15%' align='right' style='border-top:0.6px solid " . self::INK . ";font-size:10px'><b>"
            . Money::format((float) $f['total']) . '</b></td></tr></tbody></table>';

        // 2. despesas de cada atendimento
        $html .= self::section(2, 'Despesas de cada atendimento', 'Despesas com data e hora; as do horário de adicional noturno estão destacadas.');
        foreach ($byReq as $rid => $items) {
            $r = $reqs[$rid] ?? [];
            $sub = array_sum(array_map(fn ($x) => (float) $x['amount'], $items));
            $html .= "<table cellpadding='5' nobr='true'><tr style='background-color:#f1f5f9'><td width='75%'>" . self::ticket((int) ($r['tickets_id'] ?? 0)) . " &nbsp;·&nbsp; <span style='color:" . self::ACCENT . "'><b>" . Reembolso::code($rid) . '</b></span>'
                . "<br><span style='color:" . self::MUTED . "'>" . $e(Centrocusto::label((int) ($r['plugin_reembolso_centrocustos_id'] ?? 0))) . ' · ' . self::when($r) . '</span></td>'
                . "<td width='25%' align='right'><span style='font-size:6.5px;color:" . self::MUTED . "'>SUBTOTAL</span><br><b>" . Money::format($sub) . '</b></td></tr></table>'
                . "<table cellpadding='4' style='border-bottom:0.3px solid " . self::LINE . "'><thead><tr style='color:" . self::MUTED . "'><th width='17%' style='font-size:6.5px;border-bottom:0.3px solid #cbd5e1'><b>DATA E HORA</b></th>"
                . "<th width='22%' style='font-size:6.5px;border-bottom:0.3px solid #cbd5e1'><b>TIPO</b></th><th width='45%' style='font-size:6.5px;border-bottom:0.3px solid #cbd5e1'><b>DESCRIÇÃO</b></th>"
                . "<th width='16%' align='right' style='font-size:6.5px;border-bottom:0.3px solid #cbd5e1'><b>VALOR</b></th></tr></thead><tbody>";
            foreach ($items as $x) {
                $night = (int) $x['is_night'];
                $detail = $e($x['description']) . ((float) $x['distance'] ? "<br><span style='color:" . self::MUTED . "'>" . Money::km((float) $x['distance'])
                    . (trim($x['origin'] . $x['destination']) ? ' · ' . $e(trim($x['origin'] . ' → ' . $x['destination'], ' →')) : '') . '</span>' : '');
                $html .= '<tr' . ($night ? " style='background-color:" . self::NIGHT_BG . "'" : '') . "><td width='17%'>" . date('d/m/Y', strtotime((string) $x['date'])) . ' <b>' . substr((string) $x['time'], 0, 5) . '</b>'
                    . ($night ? "<br><span style='color:" . self::NIGHT . ";font-size:6.5px'><b>&#9790; ADICIONAL NOTURNO</b></span>" : '') . '</td>'
                    . "<td width='22%'>" . $e($x['tipo']) . "</td><td width='45%'>$detail</td><td width='16%' align='right'>" . Money::format((float) $x['amount']) . '</td></tr>';
            }
            $html .= '</tbody></table><br><br>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        // 3 e 4: fluxo, termo e assinatura na última página
        $pdf->AddPage();
        $term = self::section(3, 'Fluxo de aprovação', 'Depois da assinatura do técnico, o lote segue esta ordem; qualquer etapa pode devolvê-lo com o motivo.') . self::flow($lote, false)
            . self::section(4, 'Termo de conferência')
            . "<p style='text-align:justify;line-height:1.6'>Eu, <b>" . $e($tech) . '</b>, declaro que conferi as <b>' . (int) $f['expense_count'] . '</b> despesa(s) dos <b>' . count($reqs) . "</b> atendimento(s) deste lote <b>$code</b>, "
            . 'referentes ao período de <b>' . $e(Lote::periodLabel($f)) . '</b>, no valor total de <b>' . Money::format((float) $f['total']) . '</b>, e que elas correspondem aos meus atendimentos'
            . ((int) $f['night_count'] ? ', inclusive as <b>' . (int) $f['night_count'] . "</b> despesa(s) no horário de adicional noturno ($nightWin), que somam <b>" . Money::format((float) $f['night_total']) . '</b>' : '')
            . '.</p>';
        $pdf->writeHTML($term, true, false, true, false, '');
        $pdf->signatureBox($tech, 'Técnico · ' . $code);
        return $pdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ adiantamento

    public static function adiantamento(Adiantamento $ad): string
    {
        $e = [self::class, 'e'];
        $f = $ad->fields;
        $code = Adiantamento::code($ad->getID());
        $tech = (string) getUserName((int) $f['users_id']);
        $cfg = Money::config();
        $items = $ad->items();
        $hasTime = $f['time_begin'] && $f['time_end'];

        $pdf = self::doc('Pedido de adiantamento de despesas', $code, $tech, 'Atendimento: ' . self::when($f), $tech);
        $html = self::kpis([
            ['Valor solicitado', Money::format((float) $f['total']), 'adiantamento ao técnico', self::ACCENT],
            ['Despesas previstas', (string) count($items), count(array_unique(array_column($items, 'tipo'))) . ' tipo(s) de despesa', '#475569'],
            ['Horas previstas', $hasTime ? Money::minutes((int) $f['minutes_business'] + (int) $f['minutes_outside']) : '—', $hasTime ? Money::minutes((int) $f['minutes_outside']) . ' fora do horário comercial' : 'sem horário', '#0f766e'],
            ['Adicional noturno', $hasTime ? Money::minutes((int) $f['minutes_night']) : '—', 'das ' . $e($cfg['night_start']) . ' às ' . $e($cfg['night_end']), self::NIGHT],
        ]);

        $html .= self::section(1, 'Dados do atendimento')
            . self::facts([
                'Técnico' => $e($tech),
                'Centro de custo' => $e(Centrocusto::label((int) $f['plugin_reembolso_centrocustos_id']) ?: '—'),
                'Gestor da área' => $e(Lote::whoLabel((int) $f['users_id_area_manager'], (int) $f['groups_id_area_manager'])),
                'Chamado' => (int) $f['tickets_id'] ? 'nº ' . (int) $f['tickets_id'] : 'sem chamado',
                'Início' => $e($f['date_begin'] ? date('d/m/Y', strtotime((string) $f['date_begin'])) . ' ' . substr((string) $f['time_begin'], 0, 5) : '—'),
                'Fim' => $e($f['date_end'] ? date('d/m/Y', strtotime((string) $f['date_end'])) . ' ' . substr((string) $f['time_end'], 0, 5) : '—'),
                'Valor necessário até' => $e($f['date_needed'] ? date('d/m/Y', strtotime((string) $f['date_needed'])) : '—'),
                'Prestar contas até' => $e(date('d/m/Y', strtotime($ad->deadline()))),
            ]);
        if ((int) $f['tickets_id']) {
            $html .= '<br><br>' . self::note(self::ticket((int) $f['tickets_id']) . ($f['address'] ? "<br><span style='color:" . self::MUTED . "'>Local: " . $e($f['address']) . '</span>' : ''), false);
        }
        if ((int) $f['minutes_night']) {
            $html .= '<br><br>' . self::note("<span style='color:" . self::NIGHT . "'><b>&#9790; Atendimento com adicional noturno</b></span><br>" . Money::minutes((int) $f['minutes_night'])
                . ' previstas no horário das ' . $e($cfg['night_start']) . ' às ' . $e($cfg['night_end']) . '.', true);
        }
        if (trim((string) $f['comment']) !== '') {
            $html .= "<br><br><span style='font-size:6.5px;color:" . self::MUTED . "'>JUSTIFICATIVA</span><br>" . nl2br($e($f['comment']));
        }

        $html .= self::section(2, 'Despesas previstas', 'Estimativa do técnico; o gasto real é comprovado na prestação de contas.')
            . "<table cellpadding='4' style='border-bottom:0.3px solid " . self::LINE . "'>"
            . self::thead([['Tipo de despesa', 24, 'left'], ['Descrição', 40, 'left'], ['Qtd.', 9, 'right'], ['Valor unit.', 13, 'right'], ['Total', 14, 'right']]) . '<tbody>';
        foreach ($items as $i => $x) {
            $html .= '<tr' . ($i % 2 ? " style='background-color:#f8fafc'" : '') . "><td width='24%'>" . $e($x['tipo']) . "</td><td width='40%'>" . $e($x['description']) . '</td>'
                . "<td width='9%' align='right'>" . rtrim(rtrim(number_format((float) $x['quantity'], 2, ',', '.'), '0'), ',') . "</td><td width='13%' align='right'>" . Money::format((float) $x['unit_value']) . '</td>'
                . "<td width='14%' align='right'><b>" . Money::format((float) $x['amount']) . '</b></td></tr>';
        }
        $html .= "<tr><td width='86%' align='right' style='border-top:0.6px solid " . self::INK . "'><b>Valor solicitado</b></td><td width='14%' align='right' style='border-top:0.6px solid " . self::INK . ";font-size:10px'><b>"
            . Money::format((float) $f['total']) . '</b></td></tr></tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->AddPage();
        $term = self::section(3, 'Fluxo de aprovação', 'Depois da assinatura do técnico, o pedido segue esta ordem; qualquer etapa pode devolvê-lo com o motivo.') . self::flow($ad, true)
            . self::section(4, 'Termo de responsabilidade')
            . "<p style='text-align:justify;line-height:1.6'>Eu, <b>" . $e($tech) . '</b>, solicito o adiantamento <b>' . $code . '</b> no valor de <b>' . Money::format((float) $f['total']) . '</b> para as despesas do atendimento '
            . ((int) $f['tickets_id'] ? 'do chamado <b>nº ' . (int) $f['tickets_id'] . '</b> ' : '') . 'previsto para <b>' . $e(self::when($f)) . '</b>. '
            . 'Comprometo-me a usar o valor somente nessas despesas e a prestar contas com os comprovantes até <b>' . date('d/m/Y', strtotime($ad->deadline())) . '</b>, '
            . 'devolvendo o saldo que não for usado. Se o gasto passar do adiantado, a diferença é paga na conferência da prestação de contas.</p>';
        $pdf->writeHTML($term, true, false, true, false, '');
        $pdf->signatureBox($tech, 'Técnico · ' . $code);
        return $pdf->Output('', 'S');
    }
}
