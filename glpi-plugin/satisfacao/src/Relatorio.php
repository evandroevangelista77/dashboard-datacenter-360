<?php

namespace GlpiPlugin\Satisfacao;

/**
 * Relatório mensal executivo em PDF.
 *
 * Página 1 é o sumário executivo e se sustenta sozinha: a situação geral do mês, os quatro números que
 * importam, as conclusões, os pontos de atenção, os destaques e as recomendações. Depois vêm os
 * indicadores com a variação, a evolução, a análise por segmento, a voz do cliente, o e-NPS (quando
 * houve campanha) e a metodologia, para quem quiser conferir como cada número foi calculado.
 */
class Relatorio
{
    private const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const GREEN = '#22c55e';
    private const AMBER = '#f59e0b';
    private const RED = '#ef4444';
    private const GRAY = '#94a3b8';

    /** O PDF do mês, pronto para entregar. @param array{origin?: string, entity?: ?int, category?: ?int} $filters */
    public static function build(string $month, array $filters = []): string
    {
        return self::document($month, $filters)->Output('', 'S');
    }

    /** Último mês fechado (o padrão dos botões): o mês em andamento ainda não tem números que valham um relatório. */
    public static function defaultMonth(?string $reference = null): string
    {
        $month = $reference ? date('Y-m', strtotime($reference)) : date('Y-m');
        return $month >= date('Y-m') ? date('Y-m', strtotime('first day of last month')) : $month;
    }

    /** O documento do mês, com todas as páginas desenhadas. */
    public static function document(string $month, array $filters = []): ReportDoc
    {
        $first = "$month-01";
        $last = date('Y-m-t', strtotime($first));
        $prevFirst = date('Y-m-01', strtotime("$first -1 month"));
        $prevLast = date('Y-m-t', strtotime($prevFirst));
        $filters += ['origin' => '', 'entity' => null, 'category' => null];
        $partial = $month === date('Y-m');

        $cur = Painel::data(['from' => $first, 'to' => $last] + $filters);
        $prev = Painel::data(['from' => $prevFirst, 'to' => $prevLast] + $filters);
        $months = Painel::data(['from' => date('Y-m-01', strtotime("$first -5 months")), 'to' => $last] + $filters)['months'];
        $questions = Painel::questions($cur['rows']);
        $enps = Enps::data($first, $last);
        $enpsPrev = Enps::data($prevFirst, $prevLast);

        $label = ucfirst(self::MONTHS[(int) substr($month, 5, 2) - 1]) . ' de ' . substr($month, 0, 4);
        $scope = self::scope($cur, $filters);
        $pdf = new ReportDoc();
        $pdf->cover = true;
        $pdf->docTitle = 'Satisfação e clima';
        $pdf->docSub = $label . ($partial ? ' · mês em andamento (dados até ' . date('d/m') . ')' : '');
        $pdf->docMeta = ucfirst($scope) . ' · gerado em ' . date('d/m/Y H:i');
        $pdf->docFoot = "Relatório executivo CSAT / e-NPS · $label · uso interno";
        $pdf->SetTitle("Relatório executivo de satisfação · $label");
        $pdf->SetSubject('CSAT e e-NPS');
        $pdf->SetKeywords('CSAT, NPS, e-NPS, satisfação');
        $pdf->AddPage();

        $a = self::analysis($cur, $prev, $questions, $enps, $enpsPrev, $label);
        self::executive($pdf, $cur, $prev, $a, $enps, $partial, $months);

        if (!$cur['total'] && !$enps['campaigns']) {
            $pdf->AddPage();
            self::methodology($pdf, $filters, $cur);
            return $pdf;
        }

        if ($cur['total']) {
            $pdf->AddPage();
            $y = self::indicators($pdf, $cur, $prev, $a);
            $y = self::charts($pdf, $cur, $months, $y);

            $pdf->AddPage();
            $y = self::segments($pdf, $cur);
            self::voice($pdf, $cur, $questions, $y);
        }
        if ($enps['campaigns']) {
            $pdf->AddPage();
            self::enpsPage($pdf, $enps, $enpsPrev, $a);
        }
        $pdf->AddPage();
        self::methodology($pdf, $filters, $cur);
        return $pdf;
    }

    public static function monthLabel(string $month): string
    {
        return ucfirst(self::MONTHS[(int) substr($month, 5, 2) - 1]) . '-' . substr($month, 0, 4);
    }

    // ------------------------------------------------------------------ análise (o que o executivo precisa ler)

    /**
     * Veredito, manchete, conclusões, pontos de atenção, destaques e recomendações do mês.
     * O CSAT é a % de respostas positivas entre quem respondeu (a definição usual do indicador).
     *
     * @return array<string, mixed>
     */
    private static function analysis(array $d, array $prev, array $questions, array $enps, array $enpsPrev, string $label): array
    {
        $csat = $d['answered'] ? round(100 * $d['mood']['positive'] / $d['answered'], 1) : null;
        $csatPrev = $prev['answered'] ? round(100 * $prev['mood']['positive'] / $prev['answered'], 1) : null;
        $change = $csat !== null && $csatPrev !== null ? round($csat - $csatPrev, 1) : null;

        $verdict = match (true) {
            $csat === null => ['Sem dados', self::GRAY],
            $csat >= 80    => ['Excelente', '#16a34a'],
            $csat >= 65    => ['Boa', '#65a30d'],
            $csat >= 50    => ['Atenção', self::AMBER],
            default        => ['Crítica', self::RED],
        };
        $headline = 'Sem pesquisas respondidas em ' . $label . '.';
        if ($csat !== null) {
            $headline = 'CSAT de ' . self::fmt($csat) . '% entre as ' . $d['answered'] . ' resposta(s)';
            $headline .= $change === null ? '.' : ($change == 0 ? ', igual ao mês anterior.' : ', ' . ($change > 0 ? 'alta' : 'queda') . ' de ' . self::fmt(abs($change)) . ' pp sobre o mês anterior.');
        } elseif ($enps['campaigns'] && $enps['enps'] !== null) {
            $headline = 'e-NPS de ' . ($enps['enps'] > 0 ? '+' : '') . $enps['enps'] . ' (' . Enps::zone($enps['enps'])[0] . ') em ' . $label . '.';
            $verdict = [ucfirst(Enps::zone($enps['enps'])[0]), Enps::zone($enps['enps'])[1]];
        }

        $conclusions = [];
        $attention = [];
        $highlights = [];
        $actions = [];

        if ($d['total']) {
            $volume = "Foram enviadas <b>{$d['total']}</b> pesquisa(s) e <b>{$d['answered']}</b> respondida(s) (<b>" . self::fmt($d['rate']) . '%</b> de resposta)';
            if ($prev['total']) {
                $diff = $d['total'] - $prev['total'];
                $volume .= $diff == 0 ? ', o mesmo volume do mês anterior' : ', ' . ($diff > 0 ? 'alta' : 'queda') . ' de ' . abs($diff) . ' sobre o mês anterior (' . $prev['total'] . ')';
            }
            $conclusions[] = $volume . '.';
        }
        if ($csat !== null) {
            $conclusions[] = '<b>' . self::fmt($csat) . '%</b> dos respondentes ficaram satisfeitos (nota 4 ou 5); <b>' . self::fmt(100 * $d['mood']['negative'] / $d['answered']) . '%</b> foram negativas'
                . ($d['stars'] !== null ? ', e a nota média por estrelas foi <b>' . self::fmt($d['stars'], 2) . ' de 5</b>' : '') . '.';
        }
        if ($d['nps'] !== null) {
            $conclusions[] = 'O NPS de recomendação foi <b>' . ($d['nps'] > 0 ? '+' : '') . $d['nps'] . '</b>, na ' . Enps::zone($d['nps'])[0] . ' (' . $d['npsN'] . ' resposta(s)).';
        }
        if (!$conclusions) {
            $conclusions[] = 'Não houve pesquisa enviada em ' . $label . ' nos filtros escolhidos. Os números aparecem assim que houver respostas.';
        }

        // segmentos com amostra mínima
        $cats = array_values(array_filter($d['cats'], fn ($r) => $r['avg'] !== null && $r['n'] >= 3));
        usort($cats, fn ($x, $y) => $y['avg'] <=> $x['avg']);
        $ents = array_values(array_filter($d['ents'], fn ($r) => $r['avg'] !== null && $r['n'] >= 3));
        usort($ents, fn ($x, $y) => $y['avg'] <=> $x['avg']);
        $worstOrigin = null;
        foreach ($d['origins'] as $o) {
            $n = $o['positive'] + $o['neutral'] + $o['negative'];
            if ($n >= 3 && $o['negative'] > 0 && ($worstOrigin === null || $o['negative'] / $n > $worstOrigin[1])) {
                $worstOrigin = [$o['label'], $o['negative'] / $n];
            }
        }
        $scored = array_values(array_filter($questions, fn ($q) => $q['good'] !== null && $q['n'] >= 3));
        usort($scored, fn ($x, $y) => $x['good'] <=> $y['good']);

        // pontos de atenção
        if ($change !== null && $change <= -5) {
            $attention[] = 'O CSAT <b>caiu ' . self::fmt(abs($change)) . ' pp</b> em relação ao mês anterior (' . self::fmt($csatPrev) . '% → ' . self::fmt($csat) . '%).';
        }
        if ($d['answered'] && $d['mood']['negative'] / $d['answered'] >= 0.2) {
            $attention[] = '<b>' . $d['mood']['negative'] . ' resposta(s) negativa(s)</b> (' . self::fmt(100 * $d['mood']['negative'] / $d['answered']) . '% das respondidas).';
        }
        if ($d['total'] && $d['rate'] < 50) {
            $attention[] = 'A taxa de resposta (<b>' . self::fmt($d['rate']) . '%</b>) está baixa e reduz a confiança nos números.';
        }
        if (count($cats) >= 2 && end($cats)['avg'] < 3.5) {
            $attention[] = 'Categoria com pior nota: <b>' . htmlspecialchars(end($cats)['label']) . '</b> (' . self::fmt(end($cats)['avg'], 2) . ').';
        }
        if (count($ents) >= 2 && end($ents)['avg'] < 3.5) {
            $attention[] = 'Entidade com pior nota: <b>' . htmlspecialchars(end($ents)['label']) . '</b> (' . self::fmt(end($ents)['avg'], 2) . ').';
        }
        if ($worstOrigin) {
            $attention[] = 'Origem com mais respostas negativas: <b>' . htmlspecialchars($worstOrigin[0]) . '</b> (' . self::fmt(100 * $worstOrigin[1], 0) . '%).';
        }
        if (count($scored) >= 2 && $scored[0]['good'] < 60) {
            $attention[] = 'Pergunta com menor aprovação: “' . htmlspecialchars(mb_strimwidth($scored[0]['name'], 0, 56, '…')) . '” (' . $scored[0]['good'] . '% positivo).';
        }
        if ($enps['campaigns'] && $enps['enps'] !== null && $enps['enps'] < 0) {
            $attention[] = 'No e-NPS há <b>mais detratores que promotores</b> (' . self::fmt($enps['pDetractor']) . '% contra ' . self::fmt($enps['pPromoter']) . '%).';
        }
        $weakGroups = array_values(array_filter($enps['groups'], fn ($g) => $g['enps'] < 0));
        if ($weakGroups) {
            $attention[] = 'Grupo(s) com e-NPS negativo: <b>' . htmlspecialchars(implode(', ', array_column(array_slice($weakGroups, 0, 3), 'label'))) . '</b>.';
        }

        // destaques
        if ($change !== null && $change >= 3) {
            $highlights[] = 'O CSAT <b>subiu ' . self::fmt($change) . ' pp</b> sobre o mês anterior (' . self::fmt($csatPrev) . '% → ' . self::fmt($csat) . '%).';
        }
        if ($d['total'] && $d['rate'] >= 70) {
            $highlights[] = 'Taxa de resposta de <b>' . self::fmt($d['rate']) . '%</b>: boa representatividade dos números.';
        }
        if (count($cats) >= 2 && $cats[0]['avg'] >= 4) {
            $highlights[] = 'Melhor categoria: <b>' . htmlspecialchars($cats[0]['label']) . '</b> (' . self::fmt($cats[0]['avg'], 2) . ').';
        }
        if (count($ents) >= 2 && $ents[0]['avg'] >= 4) {
            $highlights[] = 'Melhor entidade: <b>' . htmlspecialchars($ents[0]['label']) . '</b> (' . self::fmt($ents[0]['avg'], 2) . ').';
        }
        if ($d['days'] !== null && $d['days'] <= 2) {
            $highlights[] = 'Respostas rápidas: em média <b>' . self::fmt($d['days']) . ' dia(s)</b> depois do envio.';
        }
        if ($enps['campaigns'] && $enps['enps'] !== null && $enps['enps'] >= 50) {
            $highlights[] = 'e-NPS de <b>' . ($enps['enps'] > 0 ? '+' : '') . $enps['enps'] . '</b>, na ' . Enps::zone($enps['enps'])[0] . '.';
        }
        $praise = count(array_filter($d['rows'], fn ($r) => $r['m'] === 'positive' && $r['c'] !== ''));
        if ($praise) {
            $highlights[] = '<b>' . $praise . ' elogio(s)</b> registrado(s) em comentários (página “Voz do cliente”).';
        }

        if (!$attention) {
            $attention[] = $d['total'] || $enps['campaigns'] ? 'Nenhum ponto crítico identificado neste mês.' : 'Sem dados para avaliar.';
        }
        if (!$highlights) {
            $highlights[] = $d['total'] || $enps['campaigns'] ? 'Nenhum destaque acima da meta neste mês.' : 'Sem dados para avaliar.';
        }

        // recomendações
        if ($d['mood']['negative']) {
            $actions[] = 'Dar retorno aos clientes das <b>' . $d['mood']['negative'] . ' pesquisa(s) negativa(s)</b> e registrar a causa (lista em “Voz do cliente”).';
        }
        if (count($cats) >= 2 && end($cats)['avg'] < 3.5) {
            $actions[] = 'Revisar o atendimento da categoria <b>' . htmlspecialchars(end($cats)['label']) . '</b> com o time responsável.';
        } elseif (count($ents) >= 2 && end($ents)['avg'] < 3.5) {
            $actions[] = 'Alinhar com a entidade <b>' . htmlspecialchars(end($ents)['label']) . '</b> o que está gerando a insatisfação.';
        }
        if ($d['total'] && $d['rate'] < 50) {
            $actions[] = 'Aumentar a participação: reforçar o convite por e-mail e rever o momento do envio.';
        }
        if ($weakGroups || ($enps['campaigns'] && $enps['enps'] !== null && $enps['enps'] < 0)) {
            $actions[] = 'Promover conversas de escuta nos grupos com e-NPS mais baixo e acompanhar na próxima campanha.';
        }
        if (!$actions) {
            $actions[] = 'Manter o acompanhamento mensal e a meta de resposta; nenhum ajuste urgente.';
        }

        $caveats = [];
        if ($d['answered'] && $d['answered'] < 30) {
            $caveats[] = 'Amostra pequena (' . $d['answered'] . ' resposta(s)): interprete os percentuais com cautela.';
        }
        return compact('csat', 'csatPrev', 'change', 'verdict', 'headline', 'conclusions', 'attention', 'highlights', 'actions', 'caveats');
    }

    // ------------------------------------------------------------------ páginas

    /** Página 1: o sumário executivo. */
    private static function executive(ReportDoc $pdf, array $d, array $prev, array $a, array $enps, bool $partial, array $months): void
    {
        $y = ReportDoc::COVER_TOP;
        if ($partial) {
            $y = $pdf->paragraph($y - 3, '<span style="color:#b45309"><b>Mês em andamento:</b> os números são parciais e podem mudar até o fim do mês.</span>', 7.5) + 2;
        }
        $empty = !$d['total'] && !$enps['campaigns'];
        if ($empty) {
            $y = $pdf->banner($y, 'Sem dados no período', 'Nenhuma pesquisa foi enviada neste mês nos filtros escolhidos.', self::GRAY);
            $last = self::lastMonthWithData();
            $y = $pdf->paragraph($y, 'Este relatório se preenche quando há pesquisas enviadas no mês.'
                . ($last ? ' O último mês com pesquisas foi <b>' . htmlspecialchars(ucfirst(self::MONTHS[(int) substr($last, 5, 2) - 1]) . ' de ' . substr($last, 0, 4)) . '</b>: gere o relatório desse mês no painel.' : ''), 9) + 4;
            if ($months && $y + 64 <= ReportDoc::BOTTOM) {
                $y = $pdf->section($y, 'Tendência dos últimos 6 meses', 'Pesquisas por humor (colunas) e nota média de 0 a 5 (linha).');
                $pdf->trend(ReportDoc::X, $y, ReportDoc::W, 46, $months, ['positive' => self::GREEN, 'neutral' => self::AMBER, 'negative' => self::RED, 'none' => self::GRAY]);
            }
            return;
        }
        $y = $pdf->banner($y, 'Situação geral: ' . $a['verdict'][0], $a['headline'], $a['verdict'][1]);

        // os quatro números
        $w = (ReportDoc::W - 9) / 4;
        $cards = [
            ['CSAT', $a['csat'] === null ? '—' : self::fmt($a['csat']) . '%', 'entre os respondentes', '#22c55e', self::delta($a['csat'], $a['csatPrev'], true, ' pp', $prev['answered'])],
            ['Nota média', $d['stars'] === null ? '—' : self::fmt($d['stars'], 2) . ' / 5', $d['starsN'] . ' avaliação(ões) por estrelas', '#14b8a6', self::delta($d['stars'], $prev['stars'], true, '', $prev['total'])],
            ['NPS', $d['nps'] === null ? '—' : ($d['nps'] > 0 ? '+' : '') . $d['nps'], Enps::zone($d['nps'])[0], '#a855f7', self::delta($d['nps'], $prev['nps'], true, '', $prev['total'])],
            ['Taxa de resposta', $d['total'] ? self::fmt($d['rate']) . '%' : '—', "{$d['answered']} de {$d['total']} enviadas", '#6366f1', self::delta($d['rate'], $prev['rate'], true, ' pp', $prev['total'])],
        ];
        if ($d['total']) {
            foreach ($cards as $i => [$l, $v, $s, $c, [$dt, $dc]]) {
                $pdf->card(ReportDoc::X + $i * ($w + 3), $y, $w, 32, $l, $v, $s, $c, $dt, $dc, true);
            }
            $y += 35;
        }

        if ($enps['campaigns'] && $enps['enps'] !== null) {
            [$zone, $zoneColor] = Enps::zone($enps['enps']);
            $pdf->fill($zoneColor);
            $pdf->Rect(ReportDoc::X, $y, 1.2, 8, 'F');
            $y = $pdf->paragraph($y, '<b>e-NPS (clima da equipe): ' . ($enps['enps'] > 0 ? '+' : '') . $enps['enps'] . '</b> · ' . $zone . ' · participação de ' . self::fmt($enps['rate']) . '% (' . $enps['answered'] . ' de ' . $enps['sent'] . ')',
                8.5, ReportDoc::X + 4, ReportDoc::W - 4) + 6;
        }

        $y = $pdf->section($y + 1, 'Conclusões do mês');
        $y = $pdf->bullets($y, $a['conclusions']) + 1;

        // atenção e destaques lado a lado
        $colW = (ReportDoc::W - 8) / 2;
        $y = $pdf->ensure($y, 40);
        $yl = self::columnTitle($pdf, ReportDoc::X, $y, $colW, 'Pontos de atenção', self::RED);
        $yl = $pdf->bulletsAt(ReportDoc::X, $yl, $colW, $a['attention'], self::RED);
        $yr = self::columnTitle($pdf, ReportDoc::X + $colW + 8, $y, $colW, 'Destaques', self::GREEN);
        $yr = $pdf->bulletsAt(ReportDoc::X + $colW + 8, $yr, $colW, $a['highlights'], self::GREEN);
        $y = max($yl, $yr) + 2;

        $y = $pdf->section($y, 'Recomendações');
        foreach ($a['actions'] as $i => $action) {
            $y = $pdf->ensure($y, 9);
            $pdf->fill(ReportDoc::ACCENT);
            $pdf->RoundedRect(ReportDoc::X, $y + 0.4, 5, 5, 1.2, '1111', 'F');
            $pdf->SetFont('dejavusans', 'B', 7.5);
            $pdf->ink('#ffffff');
            $pdf->SetXY(ReportDoc::X, $y + 0.4);
            $pdf->Cell(5, 5, (string) ($i + 1), 0, 0, 'C');
            $y = $pdf->paragraph($y, $action, 8.5, ReportDoc::X + 8, ReportDoc::W - 8) + 2;
        }
        foreach ($a['caveats'] as $caveat) {
            $y = $pdf->paragraph($y + 1, '<span style="color:#64748b"><i>' . htmlspecialchars($caveat) . '</i></span>', 7.5);
        }
        if ($months && $y + 64 <= ReportDoc::BOTTOM) {
            $y = $pdf->section($y + 3, 'Tendência dos últimos 6 meses', 'Pesquisas por humor (colunas) e nota média de 0 a 5 (linha).');
            $pdf->trend(ReportDoc::X, $y, ReportDoc::W, 42, $months, ['positive' => self::GREEN, 'neutral' => self::AMBER, 'negative' => self::RED, 'none' => self::GRAY]);
        }
    }

    /** Último mês (AAAA-MM) com pesquisa de CSAT enviada, ou null. */
    private static function lastMonthWithData(): ?string
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['date_sent'], 'FROM' => Survey::getTable(), 'WHERE' => ['NOT' => ['itemtype' => Survey::ENPS]], 'ORDER' => ['date_sent DESC'], 'LIMIT' => 1])->current();
        return $row ? substr((string) $row['date_sent'], 0, 7) : null;
    }

    private static function columnTitle(ReportDoc $pdf, float $x, float $y, float $w, string $title, string $color): float
    {
        $pdf->SetXY($x, $y);
        $pdf->SetFont('dejavusans', 'B', 9.5);
        $pdf->ink(ReportDoc::INK);
        $pdf->Cell($w, 5.5, $title, 0, 0, 'L');
        $pdf->fill($color);
        $pdf->Rect($x, $y + 6, $w, 0.7, 'F');
        return $y + 9;
    }

    /** Página 2: os indicadores do mês contra o mês anterior. */
    private static function indicators(ReportDoc $pdf, array $d, array $prev, array $a): float
    {
        $y = $pdf->section(ReportDoc::TOP - 1, 'Indicadores do mês', 'Comparação com o mês anterior, no mesmo escopo.');
        $row = fn (string $name, string $cur, string $before, string $delta) => ['<b>' . $name . '</b>', $cur, '<span style="color:#64748b">' . $before . '</span>', $delta];
        $dl = fn ($c, $p, bool $higher, string $unit = '') => self::deltaHtml($c, $p, $higher, $unit, $prev['total']);
        $none = fn ($v) => $v === null ? '—' : self::fmt($v);
        $rows = [
            $row('Pesquisas enviadas', (string) $d['total'], (string) $prev['total'], $dl($d['total'], $prev['total'], true)),
            $row('Respostas', (string) $d['answered'], (string) $prev['answered'], $dl($d['answered'], $prev['answered'], true)),
            $row('Taxa de resposta', self::fmt($d['rate']) . '%', self::fmt($prev['rate']) . '%', $dl($d['rate'], $prev['rate'], true, ' pp')),
            $row('CSAT (satisfeitos entre os respondentes)', $a['csat'] === null ? '—' : self::fmt($a['csat']) . '%', $a['csatPrev'] === null ? '—' : self::fmt($a['csatPrev']) . '%', $dl($a['csat'], $a['csatPrev'], true, ' pp')),
            $row('Positivas (das enviadas)', self::fmt($d['pct']['positive']) . '%', self::fmt($prev['pct']['positive']) . '%', $dl($d['pct']['positive'], $prev['pct']['positive'], true, ' pp')),
            $row('Neutras (das enviadas)', self::fmt($d['pct']['neutral']) . '%', self::fmt($prev['pct']['neutral']) . '%', $dl($d['pct']['neutral'], $prev['pct']['neutral'], true, ' pp')),
            $row('Negativas (das enviadas)', self::fmt($d['pct']['negative']) . '%', self::fmt($prev['pct']['negative']) . '%', $dl($d['pct']['negative'], $prev['pct']['negative'], false, ' pp')),
            $row('Sem resposta (das enviadas)', self::fmt($d['pct']['none']) . '%', self::fmt($prev['pct']['none']) . '%', $dl($d['pct']['none'], $prev['pct']['none'], false, ' pp')),
            $row('Nota média (1 a 5)', $d['stars'] === null ? '—' : self::fmt($d['stars'], 2), $prev['stars'] === null ? '—' : self::fmt($prev['stars'], 2), $dl($d['stars'], $prev['stars'], true)),
            $row('NPS de recomendação', $d['nps'] === null ? '—' : ($d['nps'] > 0 ? '+' : '') . $d['nps'], $prev['nps'] === null ? '—' : ($prev['nps'] > 0 ? '+' : '') . $prev['nps'], $dl($d['nps'], $prev['nps'], true)),
            $row('Tempo médio até responder', $d['days'] === null ? '—' : self::fmt($d['days']) . ' d', $prev['days'] === null ? '—' : self::fmt($prev['days']) . ' d', $dl($d['days'], $prev['days'], false, ' d')),
        ];
        return $pdf->table($y, ['Indicador', 'Mês', 'Mês anterior', 'Variação'], $rows, [42, 16, 18, 24], ['left', 'right', 'right', 'right']);
    }

    /** Evolução, distribuição das notas (continua a página 2). */
    private static function charts(ReportDoc $pdf, array $d, array $months, float $y): float
    {
        $colors = ['positive' => self::GREEN, 'neutral' => self::AMBER, 'negative' => self::RED, 'none' => self::GRAY];
        $y = $pdf->section($y + 1, 'Evolução dos últimos 6 meses', 'Pesquisas por humor (colunas) e nota média de 0 a 5 (linha verde-água).');
        if ($months) {
            $y = $pdf->ensure($y, 70);
            $pdf->trend(ReportDoc::X, $y, ReportDoc::W, 56, $months, $colors);
            $y = $pdf->legend(ReportDoc::X + 4, $y + 58, [['Positivas', self::GREEN], ['Neutras', self::AMBER], ['Negativas', self::RED], ['Sem resposta', self::GRAY], ['Nota média', '#0d9488']]) + 2;
        }

        $y = $pdf->section($y + 1, 'Distribuição das notas', 'Avaliações por estrelas no mês (1 = muito ruim, 5 = excelente).');
        $y = $pdf->ensure($y, 52);
        $bars = [];
        foreach ([1, 2, 3, 4, 5] as $n) {
            $bars[] = [$n . ' · ' . Survey::FACE_NAMES[$n], (int) ($d['dist'][$n] ?? 0), ['#ef4444', '#f97316', '#f59e0b', '#84cc16', '#22c55e'][$n - 1]];
        }
        $pdf->columns(ReportDoc::X, $y, ReportDoc::W, 46, $bars);
        return $y + 50;
    }

    /** Página 3: análise por origem, categoria e entidade. */
    private static function segments(ReportDoc $pdf, array $d): float
    {
        $colors = ['positive' => self::GREEN, 'neutral' => self::AMBER, 'negative' => self::RED, 'none' => self::GRAY];
        $y = $pdf->section(ReportDoc::TOP - 1, 'Análise por segmento', 'Onde a satisfação está melhor e onde precisa de atenção.');

        $y = $pdf->section($y, 'Por origem', 'Chamados, projetos, eventos e ciclos: pesquisas por humor.');
        if ($d['origins']) {
            $max = max(array_map(fn ($o) => $o['positive'] + $o['neutral'] + $o['negative'] + $o['none'], $d['origins']));
            $y = $pdf->legend(ReportDoc::X, $y, [['Positivas', self::GREEN], ['Neutras', self::AMBER], ['Negativas', self::RED], ['Sem resposta', self::GRAY]]);
            foreach ($d['origins'] as $o) {
                $total = $o['positive'] + $o['neutral'] + $o['negative'] + $o['none'];
                $answered = $o['positive'] + $o['neutral'] + $o['negative'];
                $y = $pdf->ensure($y, 9);
                $pdf->SetFont('dejavusans', '', 7.5);
                $pdf->ink(ReportDoc::INK);
                $pdf->SetXY(ReportDoc::X, $y);
                $pdf->Cell(40, 6, $o['label'], 0, 0, 'L');
                $pdf->stacked(ReportDoc::X + 42, $y + 1, (ReportDoc::W - 82) * $total / $max, 4.4, array_map(fn ($k) => [$o[$k], $colors[$k]], array_keys($colors)));
                $pdf->SetFont('dejavusans', 'B', 7.5);
                $pdf->SetXY(ReportDoc::X + ReportDoc::W - 38, $y);
                $pdf->Cell(38, 6, $total . ' env. · CSAT ' . ($answered ? self::fmt(100 * $o['positive'] / $answered, 0) . '%' : '—'), 0, 0, 'R');
                $y += 8;
            }
        } else {
            $y = $pdf->paragraph($y, '<span style="color:#64748b">Sem pesquisas no mês.</span>') + 2;
        }

        foreach ([['cats', 'Nota média por categoria', 'Categorias dos chamados, da mais frequente para a menos.'], ['ents', 'Nota média por entidade', 'Entidades com pesquisas no mês.']] as [$key, $title, $hint]) {
            if (!$d[$key]) {
                continue;
            }
            $y = $pdf->section($y + 2, $title, $hint);
            $rows = array_map(fn ($r) => [$r['label'], (float) ($r['avg'] ?? 0), $r['avg'] === null ? '—' : self::fmt($r['avg'], 2) . ' · ' . $r['n'],
                $r['avg'] === null ? self::GRAY : ($r['avg'] >= 4 ? self::GREEN : ($r['avg'] >= 3 ? self::AMBER : self::RED))], $d[$key]);
            $y = $pdf->hbars(ReportDoc::X, $y, ReportDoc::W, $rows, 5) + 1;
            $pdf->SetFont('dejavusans', '', 6.8);
            $pdf->ink(ReportDoc::MUTED);
            $pdf->SetXY(ReportDoc::X, $y);
            $pdf->Cell(ReportDoc::W, 4, 'Nota média de 1 a 5 · quantidade de pesquisas. Verde a partir de 4, âmbar de 3 a 4, vermelho abaixo de 3.', 0, 1, 'L');
            $y += 5;
        }
        return $y;
    }

    /** Página 4: a voz do cliente (negativas, elogios) e o desempenho por pergunta. */
    private static function voice(ReportDoc $pdf, array $d, array $questions, float $y): void
    {
        $negative = array_values(array_filter($d['rows'], fn ($r) => $r['m'] === 'negative'));
        $y = $pdf->section($y + 3, 'Voz do cliente · pontos de atenção', count($negative) . ' pesquisa(s) negativa(s) no mês, da mais recente para a mais antiga.');
        if (!$negative) {
            $y = $pdf->paragraph($y, '<span style="color:#64748b">Nenhuma pesquisa negativa no mês.</span>') + 2;
        }
        foreach (array_slice($negative, 0, 10) as $r) {
            $y = $pdf->ensure($y, 12);
            $note = $r['r'] !== null ? 'nota ' . self::fmt($r['r']) : ($r['p'] !== null ? 'recomendação ' . $r['p'] : 'resposta negativa');
            $line = '<b>' . htmlspecialchars($r['n']) . '</b> <span style="color:#64748b">· ' . htmlspecialchars($r['o']) . ' · ' . $note . ($r['e'] !== '' ? ' · ' . htmlspecialchars($r['e']) : '') . '</span>'
                . ($r['c'] !== '' ? '<br><i style="color:#b91c1c">“' . htmlspecialchars(mb_strimwidth($r['c'], 0, 200, '…')) . '”</i>' : '');
            $y = self::marked($pdf, $y, $line, self::RED) + 2.2;
        }

        $praise = array_values(array_filter($d['rows'], fn ($r) => $r['m'] === 'positive' && $r['c'] !== ''));
        $y = $pdf->section($y + 3, 'Voz do cliente · elogios', count($praise) . ' pesquisa(s) positiva(s) com comentário.');
        if (!$praise) {
            $y = $pdf->paragraph($y, '<span style="color:#64748b">Nenhum comentário positivo no mês.</span>') + 2;
        }
        foreach (array_slice($praise, 0, 6) as $r) {
            $y = $pdf->ensure($y, 11);
            $y = self::marked($pdf, $y, '<b>' . htmlspecialchars($r['n']) . '</b><br><i style="color:#15803d">“' . htmlspecialchars(mb_strimwidth($r['c'], 0, 200, '…')) . '”</i>', self::GREEN) + 2.2;
        }

        $y = $pdf->section($y + 3, 'Desempenho por pergunta', 'Média das notas e % de respostas positivas de cada pergunta.');
        $rows = array_map(fn ($q) => [
            htmlspecialchars($q['name']), (string) $q['n'], $q['avg'] === null ? '—' : self::fmt($q['avg'], 2),
            $q['good'] === null ? '—' : $q['good'] . '%' . ($q['nps'] !== null ? ' · NPS ' . ($q['nps'] > 0 ? '+' : '') . $q['nps'] : ''),
        ], $questions);
        $y = $pdf->ensure($y, 12 + 7 * count($rows));
        $pdf->table($y, ['Pergunta', 'Respostas', 'Média', 'Positivo'], $rows, [52, 14, 14, 20], ['left', 'right', 'right', 'right']);
    }

    private static function enpsPage(ReportDoc $pdf, array $e, array $prev, array $a): void
    {
        $y = $pdf->section(ReportDoc::TOP - 1, 'e-NPS · clima da equipe', 'Respostas anônimas das campanhas enviadas no mês. Grupos com menos de ' . Survey::ENPS_MIN_GROUP . ' respostas não aparecem.');
        [$zone, $zoneColor] = Enps::zone($e['enps']);
        $y = $pdf->banner($y, 'e-NPS · ' . $zone, ($e['enps'] === null ? 'Sem respostas.' : 'e-NPS de ' . ($e['enps'] > 0 ? '+' : '') . $e['enps'] . ' com ' . $e['n'] . ' resposta(s) e participação de ' . self::fmt($e['rate']) . '%.'), $zoneColor);
        $w = (ReportDoc::W - 9) / 4;
        $cards = [
            ['e-NPS', $e['enps'] === null ? '—' : ($e['enps'] > 0 ? '+' : '') . $e['enps'], $zone, '#6366f1', self::delta($e['enps'], $prev['enps'], true, '', $prev['n'])],
            ['Promotores', self::fmt($e['pPromoter']) . '%', $e['promoter'] . ' pessoa(s) · 9 e 10', self::GREEN, ['', ReportDoc::MUTED]],
            ['Neutros', self::fmt($e['pPassive']) . '%', $e['passive'] . ' pessoa(s) · 7 e 8', self::AMBER, ['', ReportDoc::MUTED]],
            ['Detratores', self::fmt($e['pDetractor']) . '%', $e['detractor'] . ' pessoa(s) · 0 a 6', self::RED, ['', ReportDoc::MUTED]],
        ];
        foreach ($cards as $i => [$l, $v, $s, $c, [$dt, $dc]]) {
            $pdf->card(ReportDoc::X + $i * ($w + 3), $y, $w, 26, $l, $v, $s, $c, $dt, $dc);
        }
        $y += 29;
        $pdf->stacked(ReportDoc::X, $y, ReportDoc::W, 5, [[$e['promoter'], self::GREEN], [$e['passive'], self::AMBER], [$e['detractor'], self::RED]]);
        $y = $pdf->legend(ReportDoc::X, $y + 7, [["Participação: {$e['answered']} de {$e['sent']} (" . self::fmt($e['rate']) . '%)', ReportDoc::ACCENT]]) + 2;

        $y = $pdf->section($y, 'Distribuição das notas', 'De 0 a 10: vermelho = detratores, âmbar = neutros, verde = promotores.');
        $bars = [];
        foreach ($e['dist'] as $score => $n) {
            $bars[] = [(string) $score, $n, $score >= 9 ? self::GREEN : ($score >= 7 ? self::AMBER : self::RED)];
        }
        $pdf->columns(ReportDoc::X, $y, ReportDoc::W, 38, $bars);
        $y += 42;

        if ($e['groups']) {
            $y = $pdf->section($y + 1, 'e-NPS por grupo', 'Barra à direita do centro = e-NPS positivo; à esquerda = negativo.');
            $rows = array_map(fn ($g) => [$g['label'], (float) $g['enps'], ($g['enps'] > 0 ? '+' : '') . $g['enps'] . ' · ' . $g['n'], $g['enps'] >= 50 ? self::GREEN : ($g['enps'] >= 0 ? self::AMBER : self::RED)], $e['groups']);
            $y = $pdf->hbars(ReportDoc::X, $y, ReportDoc::W, $rows, 100, 52, 7, true) + 3;
        }
        if ($e['comments']) {
            $y = $pdf->section($y + 1, 'Comentários anônimos', 'Os mais recentes do mês.');
            foreach (array_slice($e['comments'], 0, 6) as $c) {
                if ($y + 12 > ReportDoc::BOTTOM) {
                    break;
                }
                $color = $c['s'] >= 9 ? self::GREEN : ($c['s'] >= 7 ? self::AMBER : self::RED);
                $y = self::marked($pdf, $y, '<b>Nota ' . $c['s'] . '</b> <span style="color:#64748b">· ' . htmlspecialchars($c['campaign']) . '</span><br><i>“' . htmlspecialchars(mb_strimwidth($c['c'], 0, 220, '…')) . '”</i>', $color) + 2.2;
            }
        }
    }

    /** Última página: de onde vêm os números e como cada indicador é calculado. */
    private static function methodology(ReportDoc $pdf, array $filters, array $d): void
    {
        $y = $pdf->section(ReportDoc::TOP - 1, 'Metodologia e glossário', 'Como ler este relatório.');
        $items = [
            ['Fonte', 'Pesquisas enviadas por e-mail pelo GLPI quando um chamado é fechado, um projeto é finalizado ou um evento ou ciclo é concluído (CSAT), e campanhas de clima enviadas à equipe (e-NPS). Cada chamado, projeto, evento ou ciclo recebe uma única pesquisa.'],
            ['Escopo deste relatório', self::scope($d, $filters) . '. A comparação é com o mês anterior, no mesmo escopo.'],
            ['Humor da pesquisa', 'Pela nota em estrelas: 4 e 5 = positiva, 3 = neutra, 1 e 2 = negativa. Sem estrelas, vale a recomendação (9 e 10 = positiva, 7 e 8 = neutra, 0 a 6 = negativa) e, depois, o sim ou não. Pesquisa sem resposta fica à parte.'],
            ['CSAT', 'Percentual de respostas positivas entre as pesquisas respondidas. As demais proporções (positivas, neutras, negativas e sem resposta) são calculadas sobre as pesquisas enviadas.'],
            ['Taxa de resposta', 'Pesquisas respondidas dividido pelas enviadas no mês. Tempo até responder: média de dias entre o envio e a resposta.'],
            ['Nota média', 'Média das notas em estrelas (1 a 5) de todas as avaliações do mês.'],
            ['NPS', 'Percentual de promotores (notas 9 e 10) menos o de detratores (0 a 6) na pergunta de recomendação, de −100 a +100. Zonas: de 75 em diante, excelência; de 50 a 74, qualidade; de 0 a 49, aperfeiçoamento; abaixo de 0, crítica.'],
            ['e-NPS', 'O mesmo cálculo aplicado à pergunta “o quanto você recomendaria a empresa como lugar para trabalhar”. A resposta é anônima: não há ligação entre a nota e quem respondeu, e grupos com menos de ' . Survey::ENPS_MIN_GROUP . ' respostas não são exibidos.'],
            ['Limitações', 'Amostras pequenas variam muito de um mês para o outro; segmentos só entram nos destaques com 3 pesquisas ou mais. Mês em andamento é parcial. Os textos de análise são gerados automaticamente a partir dos números e devem ser lidos junto dos gráficos.'],
        ];
        foreach ($items as [$term, $text]) {
            $y = $pdf->ensure($y, 14);
            $pdf->fill(ReportDoc::ACCENT);
            $pdf->Rect(ReportDoc::X, $y + 0.5, 1, 4, 'F');
            $pdf->SetXY(ReportDoc::X + 3, $y);
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->ink(ReportDoc::INK);
            $pdf->Cell(60, 5, $term, 0, 1, 'L');
            $y = $pdf->paragraph($y + 5, htmlspecialchars($text), 8, ReportDoc::X + 3, ReportDoc::W - 3) + 3.5;
        }
    }

    // ------------------------------------------------------------------ pequenos ajudantes

    /** Texto com a barra colorida ao lado, do tamanho do próprio texto. Devolve o Y de depois dele. */
    private static function marked(ReportDoc $pdf, float $y, string $html, string $color): float
    {
        $end = $pdf->paragraph($y, $html, 7.8, ReportDoc::X + 3, ReportDoc::W - 3);
        $pdf->fill($color);
        $pdf->Rect(ReportDoc::X, $y, 1, max(4, $end - $y - 0.4), 'F');
        return $end;
    }

    private static function scope(array $d, array $filters): string
    {
        $parts = [];
        if (($filters['origin'] ?? '') !== '') {
            $parts[] = 'origem: ' . (Survey::ORIGINS[$filters['origin']] ?? $filters['origin']);
        }
        if (($filters['entity'] ?? null) !== null) {
            $parts[] = 'entidade: ' . ($d['entities'][$filters['entity']] ?? $filters['entity']);
        }
        if (($filters['category'] ?? null) !== null) {
            $parts[] = 'categoria: ' . ($d['categories'][$filters['category']] ?? $filters['category']);
        }
        return $parts ? implode(' · ', $parts) : 'todas as origens, entidades e categorias';
    }

    private static function fmt($v, int $dec = 1): string
    {
        $text = number_format((float) $v, $dec, ',', '');
        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /** Variação contra o mês anterior: [texto, cor]. $hasPrev é a base do mês anterior (0 = sem base). */
    private static function delta($cur, $before, bool $higherIsBetter, string $unit, $hasPrev): array
    {
        if ($cur === null || $before === null || !$hasPrev) {
            return ['sem mês ant.', ReportDoc::MUTED];
        }
        $diff = round($cur - $before, 1);
        if ($diff == 0) {
            return ['= igual ao mês ant.', ReportDoc::MUTED];
        }
        return [($diff > 0 ? '▲ +' : '▼ −') . self::fmt(abs($diff)) . $unit . ' vs. mês ant.', ($diff > 0) === $higherIsBetter ? '#16a34a' : '#dc2626'];
    }

    /** A mesma variação, em HTML colorido para as células da tabela. */
    private static function deltaHtml($cur, $before, bool $higherIsBetter, string $unit, $hasPrev): string
    {
        if ($cur === null || $before === null || !$hasPrev) {
            return '<span style="color:#94a3b8">—</span>';
        }
        $diff = round($cur - $before, 1);
        if ($diff == 0) {
            return '<span style="color:#64748b">= igual</span>';
        }
        $color = ($diff > 0) === $higherIsBetter ? '#16a34a' : '#dc2626';
        return '<span style="color:' . $color . '"><b>' . ($diff > 0 ? '▲ +' : '▼ −') . self::fmt(abs($diff)) . $unit . '</b></span>';
    }
}
