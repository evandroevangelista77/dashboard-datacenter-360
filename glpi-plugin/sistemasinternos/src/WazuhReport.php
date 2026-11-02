<?php

namespace GlpiPlugin\Sistemasinternos;

/**
 * Relatório executivo de vulnerabilidades em PDF, para a diretoria: situação, indicadores, panorama,
 * riscos críticos, ativos mais expostos, plano de correção (P1 e P2), detalhe por ativo e metodologia.
 */
class WazuhReport
{
    private const SEV = ['Critical' => ['Crítica', '#dc2626'], 'High' => ['Alta', '#ea580c'], 'Medium' => ['Média', '#d97706'], 'Low' => ['Baixa', '#2563eb'], '-' => ['Sem classificação', '#94a3b8']];
    private const SEV_KEYS = ['Critical', 'High', 'Medium', 'Low', '-'];
    private const RED = '#dc2626';
    private const AMBER = '#d97706';
    private const GREEN = '#16a34a';
    private const BLUE = '#3769B2';

    private static function n(float|int $v): string
    {
        return number_format((float) $v, 0, ',', '.');
    }

    private static function d(float|int $v): string
    {
        return number_format((float) $v, 1, ',', '.');
    }

    private static function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }

    private static function sevCell(string $k): string
    {
        return '<span style="color:' . self::SEV[$k][1] . '"><b>' . self::SEV[$k][0] . '</b></span>';
    }

    private static function respOf(?array $c): string
    {
        return $c ? trim(implode(' · ', array_filter([$c['tech'] ?? '', $c['tech_group'] ?? '', empty($c['tech']) && empty($c['tech_group']) ? ($c['owner'] ?? '') : '']))) : '';
    }

    public static function build(string $entity = ''): string
    {
        $q = ['entity' => $entity];
        $x = WazuhVuln::route('vuln/exec', $q);
        $assets = WazuhVuln::route('vuln/assets', $q)['assets'];
        $ops = WazuhVuln::route('vuln/ops', ['sev' => 'Low', 'entity' => $entity])['rows'];
        $entityName = 'Todas as entidades';
        if ($entity !== '') {
            foreach (Wazuh::entities() as $e) {
                if ((string) $e['id'] === $entity) {
                    $entityName = $e['name'];
                }
            }
        }
        $title = Settings::get('title') ?: 'Sistemas Internos';

        $pdf = new WazuhPdf();
        $pdf->docTitle = 'Vulnerabilidades dos ativos';
        $pdf->docSub = $title . ' · ' . $entityName;
        $pdf->docMeta = 'Gerado em ' . (new \DateTime('now', new \DateTimeZone(Wazuh::tz())))->format('d/m/Y \à\s H:i') . ' · Fonte: Wazuh (detector de vulnerabilidades) · Escopo: ' . $entityName;
        $pdf->docFoot = 'Relatório gerado pelo GLPI (Sistemas Internos 360). Confidencial · uso interno.';
        $pdf->SetTitle('Relatório executivo de vulnerabilidades');
        $pdf->AddPage();

        self::summary($pdf, $x, $assets, $ops);
        $y = self::panorama($pdf, $x, $entity !== '');
        $y = self::risks($pdf, $x, $assets, $y);
        $y = self::plan($pdf, $ops, $y);
        $y = self::perAsset($pdf, $assets, $y);
        self::method($pdf, $y);
        return $pdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ 1. resumo executivo

    private static function summary(WazuhPdf $pdf, array $x, array $assets, array $ops): void
    {
        $sev = $x['sev'];
        $pct = $x['assets'] ? (int) round(100 * $x['crit_assets'] / $x['assets']) : 0;
        if ($sev['Critical'] > 0 && $pct >= 50) {
            [$label, $color] = ['Situação: crítica', self::RED];
        } elseif ($sev['Critical'] > 0) {
            [$label, $color] = ['Situação: alta', '#ea580c'];
        } elseif ($sev['High'] > 0) {
            [$label, $color] = ['Situação: elevada', self::AMBER];
        } elseif ($x['total'] > 0) {
            [$label, $color] = ['Situação: moderada', self::BLUE];
        } else {
            [$label, $color] = ['Situação: sem vulnerabilidades', self::GREEN];
        }
        $text = $x['total'] === 0 ? 'O Wazuh não encontrou vulnerabilidades nos ativos monitorados.'
            : self::n($sev['Critical']) . ' vulnerabilidade(s) crítica(s) e ' . self::n($sev['High']) . ' alta(s) em ' . $x['crit_assets'] . ' de ' . $x['assets'] . ' ativo(s) vulnerável(is) (' . $pct . '% com risco crítico). '
                . ($x['crit_old'] > 0 ? self::n($x['crit_old']) . ' crítica(s) estão abertas há mais de 30 dias.' : 'Nenhuma crítica está aberta há mais de 30 dias.');
        $y = $pdf->banner(72, $label, $text, $color);

        // indicadores
        $fixed = $x['mttr']['rows'] ?? [];
        $fixedN = array_sum(array_column($fixed, 'fixed'));
        $avg = $fixedN ? array_sum(array_map(fn ($r) => $r['avg_days'] * $r['fixed'], $fixed)) / $fixedN : null;
        $cards = [
            ['Vulnerabilidades', self::n($x['total']), self::n($x['cves']) . ' CVEs distintos', self::BLUE],
            ['Ativos vulneráveis', $x['assets'] . ' de ' . $x['agents_total'], 'ativos monitorados', self::AMBER],
            ['Críticas', self::n($sev['Critical']), 'em ' . $x['crit_assets'] . ' ativo(s)', self::RED],
            ['Altas', self::n($sev['High']), 'severidade alta', '#ea580c'],
            ['Críticas > 30 dias', self::n($x['crit_old']), 'sem correção', $x['crit_old'] ? self::RED : self::GREEN],
            ['Tempo médio de correção', $avg === null ? '—' : self::d($avg) . ' d', $avg === null ? 'sem correções medidas' : $fixedN . ' correção(ões)', self::BLUE],
            ['Corrigidas em 30 dias', self::n($x['mttr']['fixed30'] ?? 0), 'saíram do Wazuh', self::GREEN],
            ['Sem classificação', self::n($sev['-']), 'ainda sem pontuação', '#94a3b8'],
        ];
        $w = (WazuhPdf::W - 9) / 4;
        foreach ($cards as $i => $c) {
            $pdf->card(WazuhPdf::X + ($i % 4) * ($w + 3), $y + intdiv($i, 4) * 29, $w, 26, ...$c);
        }
        $y += 62;

        // mensagens-chave e recomendações, geradas dos dados
        $p1 = array_values(array_filter($ops, fn ($r) => $r['level'] === 'P1'));
        $p1free = array_filter($p1, fn ($r) => !$r['ticket']);
        $nofix = array_filter($ops, fn ($r) => in_array($r['level'], ['P1', 'P2'], true) && $r['fix'] === '');
        $noowner = array_filter($assets, fn ($a) => $a['total'] && !self::respOf($a['computer']));
        $nocomp = array_filter($assets, fn ($a) => $a['total'] && !$a['computer']);
        $best = $ops;
        usort($best, fn ($a, $b) => $b['cves'] <=> $a['cves']);
        $key = [];
        if ($x['pkgs']) {
            $top = $x['pkgs'][0];
            $key[] = 'O pacote <b>' . self::h($top['name']) . '</b> concentra <b>' . self::n($top['count']) . '</b> vulnerabilidades (' . round(100 * $top['count'] / max(1, $x['total'])) . '% do total).';
        }
        $key[] = 'A faixa de CVSS mais frequente é <b>' . self::topCvss($x['cvss']) . '</b>; <b>' . self::n($sev['Critical'] + $sev['High']) . '</b> vulnerabilidades são críticas ou altas.';
        if ($sev['-'] > 0) {
            $key[] = '<b>' . self::n($sev['-']) . '</b> vulnerabilidades ainda não têm pontuação (CVSS) no Wazuh e não entram nas prioridades.';
        }
        $y = $pdf->section($y, 'Mensagens-chave');
        $y = $pdf->bullets($y, $key);

        $rec = [];
        if ($p1free) {
            $rec[] = 'Abrir chamado para os <b>' . count($p1free) . ' pacote(s) P1</b> ainda sem chamado (lista no <i>Plano de correção</i>): o GLPI abre o chamado sem duplicar.';
        }
        if ($best && $best[0]['fix'] !== '') {
            $rec[] = 'Maior ganho: atualizar <b>' . self::h($best[0]['package']) . '</b> em <b>' . self::h($best[0]['asset']) . '</b> para <b>≥ ' . self::h($best[0]['fix']) . '</b> elimina <b>' . self::n($best[0]['cves']) . '</b> vulnerabilidades de uma vez.';
        }
        if ($nofix) {
            $rec[] = '<b>' . count($nofix) . ' pacote(s) P1/P2</b> não têm correção publicada pelo fornecedor: aplicar mitigação (restringir acesso, desativar o recurso) e acompanhar os avisos.';
        }
        if ($x['crit_old'] > 0) {
            $rec[] = 'Definir prazo para as <b>' . self::n($x['crit_old']) . '</b> críticas abertas há mais de 30 dias e acompanhar semanalmente.';
        }
        if ($noowner) {
            $rec[] = 'Definir responsável (técnico ou grupo técnico) em <b>' . count($noowner) . ' ativo(s)</b> com vulnerabilidades: sem dono, o chamado não é atribuído.';
        }
        if ($nocomp) {
            $rec[] = 'Cadastrar no GLPI <b>' . count($nocomp) . ' ativo(s)</b> que o Wazuh monitora e o inventário não conhece (nome ou IP iguais aos do agente).';
        }
        $rec[] = 'Reavaliar este relatório após cada ciclo de correção: o tempo de correção passa a ser medido a partir da primeira vulnerabilidade que sai do Wazuh.';
        $y = $pdf->section($y + 2, 'Recomendações');
        $pdf->bullets($y, $rec, self::GREEN);
    }

    private static function topCvss(array $cvss): string
    {
        $best = ['count' => -1, 'from' => 0];
        foreach ($cvss as $b) {
            if ($b['count'] > $best['count']) {
                $best = $b;
            }
        }
        return $best['count'] > 0 ? ($best['from'] >= 10 ? '10' : (int) $best['from'] . '–' . ((int) $best['from'] + 1)) : '—';
    }

    // ------------------------------------------------------------------ 2. panorama

    private static function panorama(WazuhPdf $pdf, array $x, bool $scoped): float
    {
        $pdf->AddPage();
        $y = $pdf->section(WazuhPdf::TOP, 'Panorama', 'Como as vulnerabilidades se distribuem hoje.');
        $sev = $x['sev'];
        $total = max(1, array_sum($sev));

        // severidade
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->ink(WazuhPdf::INK);
        $pdf->SetXY(WazuhPdf::X, $y);
        $pdf->Cell(90, 5, 'Por severidade', 0, 1, 'L');
        $pdf->stacked(WazuhPdf::X, $y + 7, WazuhPdf::W, 9, array_map(fn ($k) => [$sev[$k], self::SEV[$k][1]], self::SEV_KEYS));
        $lx = WazuhPdf::X;
        $ly = $y + 19;
        foreach (self::SEV_KEYS as $k) {
            $pdf->fill(self::SEV[$k][1]);
            $pdf->Rect($lx, $ly + 1, 3, 3, 'F');
            $pdf->SetFont('dejavusans', 'B', 7.5);
            $pdf->ink(WazuhPdf::INK);
            $pdf->SetXY($lx + 4.5, $ly);
            $pdf->Cell(34, 5, ($k === '-' ? 'Sem class.' : self::SEV[$k][0]) . ': ' . self::n($sev[$k]), 0, 0, 'L');
            $pdf->SetFont('dejavusans', '', 6.6);
            $pdf->ink(WazuhPdf::MUTED);
            $pdf->SetXY($lx + 4.5, $ly + 4.4);
            $pdf->Cell(34, 4, self::d(100 * $sev[$k] / $total) . '% do total', 0, 0, 'L');
            $lx += 36.4;
        }
        $y += 36;

        // CVSS
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->ink(WazuhPdf::INK);
        $pdf->SetXY(WazuhPdf::X, $y);
        $pdf->Cell(90, 5, 'Distribuição por CVSS (pontuação base)', 0, 1, 'L');
        $bands = array_map(fn ($b) => [$b['from'] >= 10 ? '10' : (int) $b['from'] . '–' . ((int) $b['from'] + 1), $b['count'], $b['from'] >= 9 ? self::RED : ($b['from'] >= 7 ? '#ea580c' : ($b['from'] >= 4 ? self::AMBER : self::BLUE))], $x['cvss']);
        $pdf->columns(WazuhPdf::X, $y + 6, WazuhPdf::W, 48, $bands);
        $y += 58;

        // idade
        $y = $pdf->ensure($y, 50);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->ink(WazuhPdf::INK);
        $pdf->SetXY(WazuhPdf::X, $y);
        $pdf->Cell(120, 5, 'Idade das vulnerabilidades (tempo desde a detecção)', 0, 1, 'L');
        $order = ['Até 7 dias' => 0, '8 a 30 dias' => 1, '31 a 90 dias' => 2, 'Mais de 90 dias' => 3];
        $age = $x['age'];
        usort($age, fn ($a, $b) => ($order[$a['name']] ?? 9) <=> ($order[$b['name']] ?? 9));
        $rows = array_map(fn ($a) => [$a['name'], array_map(fn ($k) => $a['sev'][$k], self::SEV_KEYS), array_sum($a['sev'])], $age);
        $y = $pdf->hstack(WazuhPdf::X, $y + 7, WazuhPdf::W, $rows, array_map(fn ($k) => self::SEV[$k][1], self::SEV_KEYS), 8) + 4;

        // evolução
        $y = $pdf->ensure($y, 70);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->ink(WazuhPdf::INK);
        $pdf->SetXY(WazuhPdf::X, $y);
        $pdf->Cell(120, 5, 'Evolução (foto diária guardada pelo GLPI)', 0, 1, 'L');
        $snaps = $scoped ? [] : $x['snaps'];
        if (count($snaps) >= 2) {
            $labels = array_map(fn ($s) => date('d/m', strtotime($s['day'])), $snaps);
            $pdf->lines(WazuhPdf::X, $y + 6, WazuhPdf::W, 50, $labels, [['Críticas', self::RED, array_column($snaps, 'Critical')], ['Altas', '#ea580c', array_column($snaps, 'High')], ['Médias', self::AMBER, array_column($snaps, 'Medium')], ['Total', self::BLUE, array_column($snaps, 'total')]]);
            $y = $pdf->legend(WazuhPdf::X + 14, $y + 58, [['Total', self::BLUE], ['Críticas', self::RED], ['Altas', '#ea580c'], ['Médias', self::AMBER]]) + 2;
        } else {
            $y = $pdf->paragraph($y + 7, '<span style="color:#64748b">' . ($scoped ? 'A evolução é calculada para o ambiente inteiro; gere o relatório sem filtro de entidade para vê-la.' : 'O histórico começa agora: o GLPI guarda uma foto por dia e a evolução aparece a partir da segunda foto.') . '</span>', 8) + 4;
        }

        // tempo de correção
        $y = $pdf->section($y + 2, 'Tempo de correção', 'Cada vulnerabilidade que some do Wazuh conta como corrigida (janela de 180 dias).');
        $mt = $x['mttr']['rows'] ?? [];
        $rows = [];
        foreach (['Critical', 'High', 'Medium', 'Low'] as $k) {
            if (isset($mt[$k])) {
                $rows[] = [self::sevCell($k), self::n($mt[$k]['fixed']), self::d($mt[$k]['avg_days']), self::d($mt[$k]['max_days'])];
            }
        }
        if ($rows) {
            return $pdf->table($y, ['Severidade', 'Corrigidas', 'Média (dias)', 'Maior (dias)'], $rows, [34, 22, 22, 22], ['L', 'R', 'R', 'R']);
        }
        return $pdf->paragraph($y, '<span style="color:#64748b">Ainda sem correções observadas' . (($x['mttr']['since'] ?? null) ? ' (medição desde ' . date('d/m/Y', strtotime($x['mttr']['since'])) . ')' : '') . '.</span>', 8) + 3;
    }

    // ------------------------------------------------------------------ 3. riscos críticos e ativos expostos

    private static function risks(WazuhPdf $pdf, array $x, array $assets, float $y): float
    {
        $y = $pdf->section($y + 4, 'Riscos críticos', 'As vulnerabilidades críticas de maior pontuação e há quanto tempo estão abertas.', 30 + 7.2 * min(10, max(1, count($x['top_crit']))));
        $rows = array_map(fn ($c) => ['<b>' . self::h($c['cve']) . '</b>', self::d($c['score']), self::h($c['package']), (string) $c['assets'], ($c['days'] ?? '—') . ' d'], $x['top_crit']);
        $y = $pdf->table($y, ['CVE', 'CVSS', 'Pacote afetado', 'Ativos', 'Aberta há'], $rows ?: [['Nenhuma vulnerabilidade crítica.', '', '', '', '']], [22, 10, 40, 10, 18], ['L', 'R', 'L', 'R', 'R']);

        $y = $pdf->section($y + 2, 'Ativos mais expostos', 'Responsável, entidade e chamados relacionados.', 30 + 10 * min(8, max(1, count($assets))));
        $rows = [];
        foreach (array_slice($assets, 0, 15) as $a) {
            $c = $a['computer'];
            $tk = array_filter($a['tickets'], fn ($t) => $t['open']);
            $rows[] = ['<b>' . self::h($a['name']) . '</b><br><span style="color:#64748b">' . self::h($a['ip']) . '</span>', self::h($c['entity'] ?? 'Sem cadastro no GLPI'), self::h(self::respOf($c) ?: '— sem responsável —'),
                '<span style="color:' . self::SEV['Critical'][1] . '"><b>' . self::n($a['sev']['Critical']) . '</b></span>', self::n($a['sev']['High']), self::n($a['sev']['Medium']), self::n($a['sev']['Low']),
                $tk ? implode(', ', array_map(fn ($t) => '#' . $t['id'], array_slice($tk, 0, 3))) : '—'];
        }
        return $pdf->table($y, ['Ativo', 'Entidade', 'Responsável', 'Crít.', 'Alta', 'Méd.', 'Baixa', 'Chamados'], $rows ?: [['Sem ativos.', '', '', '', '', '', '', '']], [20, 18, 18, 7, 8, 8, 8, 13], ['L', 'L', 'L', 'R', 'R', 'R', 'R', 'L']);
    }

    // ------------------------------------------------------------------ 4. plano de correção

    private static function plan(WazuhPdf $pdf, array $ops, float $y): float
    {
        $y = $pdf->section($y + 4, 'Plano de correção', 'Pacotes P1 (críticos) e P2 (altos), por ordem de prioridade: o que atualizar e para qual versão.', 36 + 10 * min(8, max(1, count(array_filter($ops, fn ($r) => in_array($r['level'], ['P1', 'P2'], true))))));
        $rows = [];
        foreach ($ops as $r) {
            if (!in_array($r['level'], ['P1', 'P2'], true)) {
                continue;
            }
            $col = $r['level'] === 'P1' ? self::RED : '#ea580c';
            $rows[] = ['<span style="color:' . $col . '"><b>' . $r['level'] . '</b></span>', '<b>' . self::h(preg_replace('/-\d{1,3}(\.\d{1,3}){3}$/', '', $r['asset'])) . '</b>', self::h($r['package']) . '<br><span style="color:#64748b">' . self::h($r['version']) . '</span>',
                self::n($r['cves']), self::d($r['score']), $r['fix'] !== '' ? '<span style="color:#16a34a"><b>≥ ' . self::h(mb_strimwidth($r['fix'], 0, 20, '…')) . '</b></span>' : '<span style="color:#64748b">sem correção</span>', (string) $r['days'] . ' d',
                $r['ticket'] ? '#' . $r['ticket']['id'] : '<span style="color:#dc2626">sem chamado</span>'];
        }
        $total = count($rows);
        $rows = array_slice($rows, 0, 40);
        $y = $pdf->table($y, ['Prior.', 'Ativo', 'Pacote / versão atual', 'CVEs', 'CVSS', 'Atualizar para', 'Dias', 'Chamado'], $rows ?: [['Nenhum pacote P1 ou P2.', '', '', '', '', '', '', '']], [6, 18, 23, 7, 7, 20, 6, 13], ['L', 'L', 'L', 'R', 'R', 'L', 'R', 'L']);
        if ($total > 40) {
            $y = $pdf->paragraph($y, '<span style="color:#64748b">Mostrando os 40 primeiros de ' . $total . ' pacotes P1/P2. A lista completa está em Vulnerabilidades → Operacional.</span>', 7.5) + 2;
        }
        return $y;
    }

    // ------------------------------------------------------------------ 5. detalhe por ativo

    private static function perAsset(WazuhPdf $pdf, array $assets, float $y): float
    {
        $list = array_slice(array_filter($assets, fn ($a) => $a['total'] > 0), 0, 8);
        if (!$list) {
            return $y;
        }
        $pdf->AddPage();
        $y = WazuhPdf::TOP;
        $y = $pdf->section($y,'Detalhe por ativo', 'Os ' . count($list) . ' ativos mais expostos: situação, pacotes que mais pesam e as vulnerabilidades mais graves.');
        foreach ($list as $a) {
            $d = WazuhVuln::route('vuln/asset', ['id' => $a['id']]);
            $c = $a['computer'];
            $y = $pdf->ensure($y, 78);
            // cabeçalho do ativo
            $pdf->fill('#eef2f8');
            $pdf->RoundedRect(WazuhPdf::X, $y, WazuhPdf::W, 21, 2, '1111', 'F');
            $pdf->SetXY(WazuhPdf::X + 3, $y + 1.8);
            $pdf->SetFont('dejavusans', 'B', 9.5);
            $pdf->ink(WazuhPdf::INK);
            $pdf->Cell(110, 5, $a['name'], 0, 2, 'L');
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->ink(WazuhPdf::MUTED);
            $pdf->Cell(150, 4, $a['ip'] . ' · ' . $a['os'] . ' · ' . ($c ? $c['entity'] : 'Sem cadastro no GLPI') . ' · Responsável: ' . (self::respOf($c) ?: 'não definido'), 0, 2, 'L');
            $pdf->Cell(150, 4, 'Computador no GLPI: ' . ($c ? $c['name'] : '—') . ' · ' . self::n($a['total']) . ' vulnerabilidade(s) · ' . $a['packages'] . ' pacote(s) afetado(s)', 0, 0, 'L');
            $pdf->stacked(WazuhPdf::X + 3, $y + 16.8, WazuhPdf::W - 6, 2, array_map(fn ($k) => [$a['sev'][$k], self::SEV[$k][1]], self::SEV_KEYS));
            $y += 24;
            // pacotes
            $rows = [];
            foreach (array_slice($d['packages'], 0, 6) as $p) {
                $rows[] = [self::h($p['name']) . '<br><span style="color:#64748b">' . self::h($p['version']) . '</span>',
                    '<span style="color:#dc2626"><b>' . $p['sev']['Critical'] . '</b></span> / ' . $p['sev']['High'] . ' / ' . $p['sev']['Medium'] . ' / ' . $p['sev']['Low'], self::d($p['score']), ($p['days'] ?? '—') . ' d',
                    !empty($p['ticket']) ? '#' . $p['ticket']['id'] : '—'];
            }
            $y = $pdf->table($y, ['Pacote / versão', 'Crít. / Alta / Méd. / Baixa', 'CVSS máx.', 'Aberta há', 'Chamado'], $rows ?: [['Sem pacotes.', '', '', '', '']], [38, 28, 12, 12, 10], ['L', 'L', 'R', 'R', 'L'], 7.2);
            // vulnerabilidades mais graves
            $rows = [];
            foreach (array_slice($d['items'], 0, 4) as $v) {
                $rows[] = ['<b>' . self::h($v['cve']) . '</b>', self::sevCell($v['severity']), $v['score'] > 0 ? self::d($v['score']) : '—', self::h($v['package']),
                    $v['fix'] !== '' ? '≥ ' . self::h($v['fix']) : '<span style="color:#64748b">sem correção</span>'];
            }
            if ($rows) {
                $y = $pdf->table($y - 2, ['Mais graves', 'Severidade', 'CVSS', 'Pacote', 'Correção'], $rows, [22, 15, 9, 33, 21], ['L', 'L', 'R', 'L', 'L'], 7.2);
            }
            $y += 2;
        }
        return $y;
    }

    // ------------------------------------------------------------------ 6. metodologia

    private static function method(WazuhPdf $pdf, float $y): void
    {
        $y = $pdf->ensure($y + 4, 90);
        $y = $pdf->section($y, 'Metodologia e glossário');
        $pdf->bullets($y, [
            '<b>Fonte:</b> o detector de vulnerabilidades do Wazuh compara os pacotes instalados em cada ativo com bases públicas (NVD, avisos dos fabricantes). O GLPI lê o resultado a cada minuto e guarda o histórico a cada hora.',
            '<b>Severidade:</b> a do Wazuh, pela pontuação CVSS: crítica (9,0 a 10), alta (7,0 a 8,9), média (4,0 a 6,9) e baixa (abaixo de 4,0). “Sem classificação” é o que ainda não foi pontuado.',
            '<b>Prioridade (P1 a P4):</b> pela maior severidade do pacote em um ativo: P1 crítica, P2 alta, P3 média, P4 baixa. Dentro de cada nível, ordena por CVSS, quantidade de CVEs e idade.',
            '<b>Corrigida:</b> a vulnerabilidade que deixa de aparecer no Wazuh depois de atualizar o pacote. O tempo de correção é o intervalo entre a detecção e esse desaparecimento.',
            '<b>Ativos sem cadastro:</b> o agente é ligado ao computador do GLPI pelo nome ou pelo IP; sem ligação, o ativo aparece sem entidade e sem responsável.',
            '<b>Limites:</b> o relatório reflete o instante da geração e só cobre ativos com agente do Wazuh instalado e conectado.',
        ]);
    }
}
