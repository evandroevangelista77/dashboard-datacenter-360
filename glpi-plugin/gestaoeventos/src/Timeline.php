<?php

namespace GlpiPlugin\Gestaoeventos;

/**
 * Linha do tempo por etapa (Gantt) e trilha de auditoria do evento. Cada passagem por uma etapa
 * vira uma barra (início → fim, duração contra o SLO, quem concluiu e o comentário); devoluções,
 * pulos, decisões, bloqueios e marcos aparecem em ordem cronológica.
 */
class Timeline
{
    public static function show(Evento $ev): void
    {
        $e = [Menu::class, 'e'];
        $phases = Fase::forEvento($ev->getID());
        $events = array_reverse(Historico::forEvento($ev->getID()));
        $now = $_SESSION['glpi_currenttime'];
        $f = $ev->fields;

        $entries = [];
        $open = [];
        foreach ($events as $x) {
            $code = (string) $x['code'];
            switch ($x['action']) {
                case 'start':
                    $open[$code] = count($entries);
                    $entries[] = ['type' => 'run', 'code' => $code, 'start' => $x['date'], 'end' => null, 'outcome' => 'active', 'user' => 0, 'comment' => ''];
                    break;
                case 'done':
                case 'return':
                case 'skip':
                    if (!isset($open[$code])) {
                        if (!isset($phases[$code])) {
                            $entries[] = ['type' => 'mark', 'action' => $x['action'], 'code' => $code, 'date' => $x['date'], 'user' => (int) $x['users_id'], 'comment' => (string) $x['comment']];
                            break;
                        }
                        $open[$code] = count($entries);
                        $entries[] = ['type' => 'run', 'code' => $code, 'start' => $f['date_request'] ?: $x['date'], 'end' => null, 'outcome' => 'active', 'user' => 0, 'comment' => ''];
                    }
                    $k = $open[$code];
                    $entries[$k]['end'] = $x['date'];
                    $entries[$k]['outcome'] = $x['action'];
                    $entries[$k]['user'] = (int) $x['users_id'];
                    $entries[$k]['comment'] = (string) $x['comment'];
                    unset($open[$code]);
                    break;
                default:
                    $entries[] = ['type' => 'mark', 'action' => $x['action'], 'code' => $code, 'date' => $x['date'], 'user' => (int) $x['users_id'], 'comment' => (string) $x['comment']];
            }
        }
        $runs = array_values(array_filter($entries, fn ($x) => $x['type'] === 'run'));
        $doneRuns = count(array_filter($runs, fn ($x) => $x['outcome'] === 'done'));
        $returns = count(array_filter($runs, fn ($x) => $x['outcome'] === 'return'));
        $lateRuns = 0;
        foreach ($runs as $r) {
            $slo = (int) ($phases[$r['code']]['slo_days'] ?? 0);
            $secs = strtotime($r['end'] ?: $now) - strtotime($r['start']);
            $lateRuns += $slo && $secs > $slo * 86400 ? 1 : 0;
        }

        echo "<div class='ge ge-tab'><div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-timeline-event me-2'></i>Linha do tempo</h3>"
            . "<div class='card-actions small text-secondary'>" . Menu::days(Evento::cycleSeconds($f)) . " dias · $doneRuns etapa(s) concluída(s) · "
            . ($lateRuns ? "<span class='text-danger'>$lateRuns acima do SLO</span>" : 'todas no SLO') . ($returns ? " · <span class='text-danger'>$returns devolução(ões)</span>" : '') . "</div></div><div class='card-body'>";
        if (!$f['date_request']) {
            echo "<div class='text-secondary'>O fluxo ainda não começou: o briefing é um rascunho. A linha do tempo aparece depois do envio à triagem.</div></div></div>";
        } else {
            echo self::gantt($ev, $runs, $phases);
            echo "<details class='ge-tl-details'><summary><i class='ti ti-table me-1'></i>Detalhe de cada passagem (datas, duração, SLO, responsável e comentários)</summary>";
            echo "<div class='table-responsive mt-2'><table class='table table-sm table-vcenter ge-tl-table'><thead><tr><th>Etapa</th><th>Área</th><th>Início</th><th>Fim</th><th class='text-end'>Duração</th><th class='text-end'>SLO</th><th>Situação</th><th>Responsável</th><th>Comentário</th></tr></thead><tbody>";
            foreach (array_reverse($entries) as $x) {
                if ($x['type'] === 'mark') {
                    [$label, $icon, $color] = Historico::ACTIONS[$x['action']] ?? [$x['action'], 'ti-point', '#64748b'];
                    echo "<tr class='ge-tl-markrow'><td colspan='2'><i class='ti $icon me-1' style='color:$color'></i><b>" . $e($label) . "</b></td><td>" . Menu::date($x['date'], true) . "</td><td></td><td></td><td></td><td></td>"
                        . "<td>" . $e(getUserName($x['user'])) . "</td><td class='small'>" . $e($x['comment']) . "</td></tr>";
                    continue;
                }
                $p = $phases[$x['code']] ?? ['code' => $x['code'], 'groups_id' => 0, 'slo_days' => 0];
                $slo = (int) $p['slo_days'];
                $secs = max(0, strtotime($x['end'] ?: $now) - strtotime($x['start']));
                $late = $slo && $secs > $slo * 86400;
                [$label, $cls] = match ($x['outcome']) {
                    'done' => $late ? ['Fora do SLO', 'over'] : ['No SLO', 'ok'],
                    'return' => ['Devolvida', 'late'],
                    'skip' => ['Pulada', 'skip'],
                    default => $late ? ['Atrasada', 'late'] : ['Em andamento', 'now'],
                };
                $comment = $x['outcome'] === 'done' && $x['comment'] === 'Etapa concluída.' ? '' : $x['comment'];
                echo "<tr><td><b>" . $e(Fase::name($x['code'])) . "</b></td><td class='small'>" . $e(Fase::area($p)) . "</td>"
                    . "<td class='text-nowrap'>" . Menu::date($x['start'], true) . "</td><td class='text-nowrap'>" . ($x['end'] ? Menu::date($x['end'], true) : '<i>em andamento</i>') . "</td>"
                    . "<td class='text-end fw-bold'>" . Menu::days($secs) . "d</td><td class='text-end text-secondary'>" . ($slo ? $slo . 'd' : '—') . "</td>"
                    . "<td><em class='ge-g-chip $cls'>" . $e($label) . ($late && $x['outcome'] !== 'return' ? ' +' . Menu::days($secs - $slo * 86400) . 'd' : '') . "</em></td>"
                    . "<td class='small'>" . $e($x['user'] ? getUserName($x['user']) : '—') . "</td><td class='small'>" . nl2br($e($comment)) . "</td></tr>";
            }
            echo "</tbody></table></div></details></div></div>";
        }

        // trilha de auditoria completa (ações e bloqueios)
        $log = Historico::forEvento($ev->getID());
        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-shield-check me-2'></i>Trilha de auditoria</h3><div class='card-actions small text-secondary'>" . count($log) . " registro(s) · não editável</div></div>";
        echo "<div class='table-responsive' style='max-height:420px'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Etapa</th><th>Detalhe</th></tr></thead><tbody>";
        foreach (array_slice($log, 0, 300) as $x) {
            [$label, $icon, $color] = Historico::ACTIONS[$x['action']] ?? [$x['action'], 'ti-point', '#64748b'];
            echo "<tr><td class='text-nowrap small'>" . Menu::date($x['date'], true) . "</td><td class='small'>" . $e($x['users_id'] ? getUserName((int) $x['users_id']) : 'sistema') . "</td>"
                . "<td class='small text-nowrap'><i class='ti $icon me-1' style='color:$color'></i>" . $e($label) . "</td><td class='small'>" . $e($x['code'] ? Fase::name((string) $x['code']) : '—') . "</td><td class='small'>" . $e($x['comment']) . "</td></tr>";
        }
        echo "</tbody></table></div></div></div>";
        echo Menu::script('gestaoeventos.js');
    }

    /** Gantt do evento: uma linha por etapa, as passagens como barras no tempo, a cor conta como foi e o traço mostra o SLO. */
    private static function gantt(Evento $ev, array $runs, array $phases): string
    {
        $e = [Menu::class, 'e'];
        $f = $ev->fields;
        $now = strtotime($_SESSION['glpi_currenttime']);
        $from = strtotime($f['date_request']);
        $to = $f['date_end'] ? strtotime($f['date_end']) : $now;
        $span = max(86400, $to - $from);
        $pos = fn ($t) => round(100 * max(0, min($span, $t - $from)) / $span, 2);
        $byCode = [];
        foreach ($runs as $r) {
            $byCode[$r['code']][] = $r;
        }
        $ticks = '';
        $step = max(86400, (int) ceil($span / 6 / 86400) * 86400);
        for ($t = $from; $t < $to - $step / 3; $t += $step) {
            $ticks .= "<span style='left:" . $pos($t) . "%'>" . date('d/m', $t) . "</span>";
        }
        $ticks .= "<span class='end' style='left:100%'>" . date('d/m', $to) . "</span>";
        $today = $f['date_end'] ? '' : "<div class='ge-g-today' style='left:100%'><b>hoje</b></div>";

        $longest = ['', 0];
        $overTotal = 0;
        $n = 0;
        $h = "<div class='ge-g'>";
        $h .= "<div class='ge-g-row ge-g-axis'><div class='ge-g-label'></div><div class='ge-g-track'>$ticks</div></div>";
        foreach ($phases as $code => $p) {
            $list = $byCode[$code] ?? [];
            $slo = (int) $p['slo_days'];
            $total = array_sum(array_map(fn ($r) => max(0, strtotime($r['end'] ?: $_SESSION['glpi_currenttime']) - strtotime($r['start'])), $list));
            if ($total > $longest[1]) {
                $longest = [Fase::name($code), $total];
            }
            $late = $slo && $total > $slo * 86400;
            $overTotal += $late ? $total - $slo * 86400 : 0;
            $status = $p['status'];
            $chip = match (true) {
                $status === 'active' && $late => ['Atrasada', 'late'],
                $status === 'active' => ['Agora', 'now'],
                $status === 'done' && $late => ['Fora do SLO', 'over'],
                $status === 'done' => ['No SLO', 'ok'],
                $status === 'skipped' => ['Pulada', 'skip'],
                default => ['A fazer', 'todo'],
            };
            $n++;
            $h .= "<div class='ge-g-row" . ($status === 'pending' ? ' pending' : '') . "'><div class='ge-g-label'><span class='ge-g-num'>$n</span><div class='ge-g-text'><b>" . $e(Fase::name($code)) . "</b>"
                . "<span>" . $e(Fase::area($p)) . ($list ? ' · ' . Menu::days($total) . 'd' . ($slo ? " / $slo" : '') : ($slo ? " · SLO $slo d" : '')) . "</span>"
                . "</div><em class='ge-g-chip {$chip[1]}'>{$chip[0]}</em></div><div class='ge-g-track'>";
            foreach ($list as $r) {
                $s = strtotime($r['start']);
                $end = $r['end'] ? strtotime($r['end']) : $now;
                $secs = max(0, $end - $s);
                $runLate = $slo && $secs > $slo * 86400;
                $cls = match ($r['outcome']) {
                    'done' => $runLate ? 'over' : 'ok',
                    'return' => 'ret',
                    'skip' => 'skip',
                    default => $runLate ? 'late' : 'now',
                };
                $left = $pos($s);
                $width = max(0.8, $pos($end) - $left);
                $tip = json_encode([
                    'title' => Fase::name($code), 'area' => Fase::area($p),
                    'status' => ['ok' => 'Concluída no SLO', 'over' => 'Concluída fora do SLO', 'ret' => 'Devolvida', 'skip' => 'Pulada', 'now' => 'Em andamento', 'late' => 'Em andamento · atrasada'][$cls],
                    'cls' => $cls, 'from' => Menu::date($r['start'], true), 'to' => $r['end'] ? Menu::date($r['end'], true) : 'agora',
                    'days' => Menu::days($secs) . ' dia(s)', 'slo' => $slo ? "$slo dia(s)" : 'sem SLO',
                    'over' => $runLate ? '+' . Menu::days($secs - $slo * 86400) . ' dia(s)' : '',
                    'who' => $r['user'] ? getUserName($r['user']) : '', 'note' => $r['outcome'] === 'done' && $r['comment'] === 'Etapa concluída.' ? '' : $r['comment'],
                ], JSON_UNESCAPED_UNICODE);
                $h .= "<div class='ge-g-bar $cls' style='left:$left%;width:$width%' data-tip='" . $e($tip) . "'>"
                    . ($width > 7 ? "<span>" . Menu::days($secs) . "d</span>" : '') . ($r['outcome'] === 'return' ? "<i class='ti ti-arrow-back-up'></i>" : '') . "</div>";
                if ($slo && in_array($cls, ['over', 'late', 'now', 'ok'], true)) {
                    $sloAt = $pos($s + $slo * 86400);
                    if ($sloAt < 100) {
                        $h .= "<div class='ge-g-slo' style='left:$sloAt%' title='Prazo (SLO) de $slo dia(s)'></div>";
                    }
                }
            }
            $h .= "</div></div>";
        }
        $h .= "<div class='ge-g-row ge-g-foot'><div class='ge-g-label'></div><div class='ge-g-track'>$today</div></div></div>";

        $legend = "<div class='ge-g-legend'><span><i class='ok'></i>No SLO</span><span><i class='over'></i>Concluída fora do SLO</span><span><i class='now'></i>Em andamento</span>"
            . "<span><i class='late'></i>Atrasada</span><span><i class='ret'></i>Devolvida</span><span><i class='slo'></i>Prazo (SLO)</span><span class='text-secondary'>Passe o mouse nas barras para ver datas e motivos.</span></div>";
        $summary = "<div class='ge-g-summary'>"
            . "<div><small>Ciclo</small><b>" . Menu::days(Evento::cycleSeconds($f)) . " dias</b><span>" . date('d/m/Y', $from) . ' → ' . ($f['date_end'] ? date('d/m/Y', $to) : 'hoje') . "</span></div>"
            . "<div><small>Etapa mais longa</small><b>" . $e($longest[0] ?: '—') . "</b><span>" . ($longest[1] ? Menu::days($longest[1]) . ' dia(s)' : '') . "</span></div>"
            . "<div class='" . ($overTotal ? 'bad' : 'good') . "'><small>Tempo além do SLO</small><b>" . ($overTotal ? Menu::days($overTotal) . ' dia(s)' : 'nenhum') . "</b><span>somando as etapas</span></div>"
            . "<div class='" . ((int) $f['returns'] ? 'bad' : 'good') . "'><small>Devoluções</small><b>" . (int) $f['returns'] . "</b><span>retrabalho</span></div>"
            . "</div>";
        return $summary . $h . $legend;
    }
}
