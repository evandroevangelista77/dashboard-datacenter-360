<?php

namespace GlpiPlugin\Ciclo;

use Session;

/**
 * Painel do Ciclo: indicadores (cada cartão abre o resumo num painel lateral),
 * tempo de cada fase contra o SLO, onde os Ciclos estão agora, ciclo médio por
 * mês contra a meta, fases atrasadas e o que está com a minha área.
 */
class Painel
{
    public static function show(array $get): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $months = (int) ($get['months'] ?? 6);
        $months = in_array($months, [3, 6, 12, 24], true) ? $months : 6;
        $since = date('Y-m-d H:i:s', strtotime("-$months months"));
        $etapas = Etapa::all();
        $nowTs = strtotime($_SESSION['glpi_currenttime']);

        $ciclos = iterator_to_array($DB->request(['FROM' => Ciclo::getTable(), 'WHERE' => ['is_deleted' => 0]]), false);
        $byId = array_column($ciclos, null, 'id');
        $open = array_filter($ciclos, fn ($c) => (int) $c['status'] === Ciclo::OPEN);
        $done = array_filter($ciclos, fn ($c) => (int) $c['status'] === Ciclo::DONE && $c['date_end'] >= $since);
        $cycleDays = array_map(fn ($c) => Ciclo::cycleSeconds($c) / 86400, $done);
        $avg = $cycleDays ? array_sum($cycleDays) / count($cycleDays) : 0;
        // meta do Ciclo: a soma dos SLOs das fases (venda completa, hardware + serviço)
        $meta = array_sum(array_map(fn ($et) => $et['is_active'] ? (int) $et['slo_days'] : 0, $etapas));

        // fases
        $perPhase = [];
        $hit = [];
        $active = [];
        foreach ($DB->request(['FROM' => Fase::getTable()]) as $f) {
            $c = $byId[$f['plugin_ciclo_ciclos_id']] ?? null;
            if (!$c) {
                continue;
            }
            if ($f['status'] === 'done' && $f['date_end'] >= $since) {
                $perPhase[$f['code']][] = Fase::elapsed($f) / 86400;
                if ((int) $f['slo_days']) {
                    $hit[$f['code']][0] = ($hit[$f['code']][0] ?? 0) + (Fase::isLate($f) ? 0 : 1);
                    $hit[$f['code']][1] = ($hit[$f['code']][1] ?? 0) + 1;
                }
            }
            if ($f['status'] === 'active' && (int) $c['status'] === Ciclo::OPEN) {
                $active[] = $f + ['ciclo' => $c];
            }
        }
        $sloHit = [array_sum(array_column($hit, 0)), array_sum(array_column($hit, 1))];
        $late = array_values(array_filter($active, fn ($f) => Fase::isLate($f)));
        usort($late, fn ($a, $b) => (Fase::elapsed($b) - (int) $b['slo_days'] * 86400) <=> (Fase::elapsed($a) - (int) $a['slo_days'] * 86400));
        $returnEvents = iterator_to_array($DB->request(['FROM' => Evento::getTable(), 'WHERE' => ['action' => 'return', 'date' => ['>=', $since]], 'ORDER' => 'date DESC']), false);
        $parcelas = iterator_to_array($DB->request(['FROM' => Item::getTable(), 'WHERE' => ['kind' => 'parcela', 'date_done' => null], 'ORDER' => 'date_expected']), false);
        $parcelas = array_values(array_filter($parcelas, fn ($p) => isset($byId[$p['plugin_ciclo_ciclos_id']])));
        $receivable = array_sum(array_map(fn ($p) => (float) $p['amount'], $parcelas));
        $overdue = array_filter($parcelas, fn ($p) => $p['date_expected'] && strtotime($p['date_expected']) < strtotime('today', $nowTs));

        // ------------------------------------------------------------------ dados dos gráficos
        $phase = ['names' => [], 'avg' => [], 'slo' => [], 'count' => [], 'hit' => []];
        $now = ['names' => [], 'ok' => [], 'late' => [], 'list' => []];
        foreach ($etapas as $code => $et) {
            if ($code === 'aviso' || !$et['is_active']) {
                continue;
            }
            $list = $perPhase[$code] ?? [];
            $phase['names'][] = $et['name'];
            $phase['avg'][] = $list ? round(array_sum($list) / count($list), 1) : 0;
            $phase['slo'][] = (int) $et['slo_days'];
            $phase['count'][] = count($list);
            $phase['hit'][] = isset($hit[$code]) ? round(100 * $hit[$code][0] / $hit[$code][1]) : null;
            $here = array_filter($active, fn ($f) => $f['code'] === $code);
            if ($here) {
                $now['names'][] = $et['name'];
                $now['ok'][] = count(array_filter($here, fn ($f) => !Fase::isLate($f)));
                $now['late'][] = count(array_filter($here, fn ($f) => Fase::isLate($f)));
                $now['list'][] = array_values(array_map(fn ($f) => [
                    'code' => Ciclo::code((int) $f['ciclo']['id']), 'name' => (string) $f['ciclo']['name'],
                    'days' => Menu::days(Fase::elapsed($f)), 'slo' => (int) $f['slo_days'], 'late' => Fase::isLate($f),
                ], $here));
            }
        }
        $monthly = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthly[date('Y-m', strtotime("first day of -$i month"))] = [];
        }
        foreach ($done as $c) {
            $k = substr((string) $c['date_end'], 0, 7);
            if (isset($monthly[$k])) {
                $monthly[$k][] = ['days' => round(Ciclo::cycleSeconds($c) / 86400), 'code' => Ciclo::code((int) $c['id']), 'name' => (string) $c['name']];
            }
        }
        $month = [
            'labels' => array_map(fn ($k) => ucfirst(['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][(int) substr($k, 5, 2) - 1]) . '/' . substr($k, 2, 2), array_keys($monthly)),
            'avg' => array_map(fn ($l) => $l ? round(array_sum(array_column($l, 'days')) / count($l)) : null, array_values($monthly)),
            'count' => array_map('count', array_values($monthly)),
            'list' => array_values($monthly),
            'meta' => $meta,
        ];

        // ------------------------------------------------------------------ cabeçalho e cartões
        echo "<div class='cf'>";
        $filter = "<form method='get' class='d-flex gap-2 align-items-center m-0'><span class='small text-secondary'>Concluídos nos últimos</span><select class='form-select form-select-sm' name='months' onchange='this.form.submit()'>";
        foreach ([3, 6, 12, 24] as $m) {
            $filter .= "<option value='$m'" . ($m === $months ? ' selected' : '') . ">$m meses</option>";
        }
        $filter .= "</select></form>" . Menu::button('Ciclos', Ciclo::getSearchURL(), 'ti-list', 'btn-outline-secondary') . (Ciclo::canCreate() ? Menu::button('Novo Ciclo', Ciclo::getFormURL()) : '');
        echo Menu::title('Painel do Ciclo', 'painel', $filter);

        $kpi = fn ($key, $icon, $label, $value, $sub, $color) => "<div class='col-6 col-md-4 col-xl-2'><button type='button' class='cf-kpi cf-kpi-btn' style='--c:$color' data-drawer='$key'>"
            . "<div class='cf-kpi-top'><span class='cf-kpi-label'>$label</span><i class='ti $icon'></i></div><div class='cf-kpi-value'>$value</div><div class='cf-kpi-sub'>$sub</div>"
            . "<div class='cf-kpi-more'>ver resumo <i class='ti ti-chevron-right'></i></div></button></div>";
        echo "<div class='row g-3 mb-3'>";
        echo $kpi('andamento', 'ti-progress', 'Em andamento', count($open), count($late) . ' com fase atrasada', '#3b82f6');
        echo $kpi('concluidos', 'ti-circle-check', 'Concluídos', count($done), "nos últimos $months meses", '#22c55e');
        echo $kpi('ciclo', 'ti-clock-hour-4', 'Ciclo médio', $cycleDays ? number_format($avg, 0, ',', '.') . ' dias' : '—', "meta de $meta dias (soma dos SLOs)", '#14b8a6');
        echo $kpi('slo', 'ti-target-arrow', 'Fases no SLO', $sloHit[1] ? round(100 * $sloHit[0] / $sloHit[1]) . '%' : '—', $sloHit[1] . ' fase(s) com SLO concluídas', '#a855f7');
        echo $kpi('devolucoes', 'ti-arrow-back-up', 'Devoluções', count($returnEvents), 'retrabalho no período', '#ef4444');
        echo $kpi('receber', 'ti-cash', 'A receber', Menu::money($receivable), count($parcelas) . ' parcela(s)' . ($overdue ? ' · ' . count($overdue) . ' vencida(s)' : ''), '#f59e0b');
        echo "</div>";

        // ------------------------------------------------------------------ gráficos
        $card = fn ($id, $icon, $title, $sub, $cls, $h) => "<div class='$cls'><div class='card h-100 cf-chart-card'><div class='card-header'><div><h3 class='card-title'><i class='ti $icon me-2'></i>$title</h3><div class='small text-secondary'>$sub</div></div></div>"
            . "<div class='card-body'><div class='cf-chart' id='$id' style='height:{$h}px'></div></div></div></div>";
        echo "<div class='row g-3 mb-3'>";
        echo $card('cf-c-phase', 'ti-hourglass', 'Tempo médio por fase × SLO', 'Dias de cada fase concluída no período. A faixa clara é o prazo (SLO); vermelho passou do prazo.', 'col-xl-7', max(420, count($phase['names']) * 34 + 80));
        echo "<div class='col-xl-5'>" . self::pipeline($etapas, $active, count($open), count($late)) . "</div>";
        echo $card('cf-c-month', 'ti-chart-line', 'Ciclo médio por mês de conclusão', "Dias do aviso de fechamento ao recebimento, contra a meta de $meta dias", 'col-12', 300);
        echo "</div>";

        // ------------------------------------------------------------------ tabelas
        $mine = array_filter($active, fn ($f) => in_array((int) Session::getLoginUserID(), Avisos::members((int) $f['groups_id']), true)
            || in_array((int) Session::getLoginUserID(), [(int) $f['ciclo']['users_id_gp'], (int) $f['ciclo']['users_id_otc']], true));
        $table = function (string $title, string $icon, array $rows, string $empty) use ($e) {
            echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti $icon me-2'></i>$title</h3><div class='card-actions small text-secondary'>" . count($rows) . "</div></div>";
            if (!$rows) {
                echo "<div class='card-body text-secondary small'>$empty</div></div></div>";
                return;
            }
            echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Ciclo</th><th>Fase</th><th>Área</th><th class='text-end'>Dias</th><th class='text-end'>SLO</th></tr></thead><tbody>";
            foreach (array_slice($rows, 0, 12) as $f) {
                $isLate = Fase::isLate($f);
                echo "<tr><td><a href='" . $e(Ciclo::getFormURLWithID((int) $f['ciclo']['id'])) . "'>" . $e(Ciclo::code((int) $f['ciclo']['id'])) . "</a> <span class='small text-secondary'>" . $e(mb_strimwidth((string) $f['ciclo']['name'], 0, 40, '…')) . "</span></td>"
                    . "<td>" . $e(Fase::name($f['code'])) . "</td><td class='small'>" . $e(Fase::area($f)) . "</td>"
                    . "<td class='text-end" . ($isLate ? ' text-danger fw-bold' : '') . "'>" . Menu::days(Fase::elapsed($f)) . "</td><td class='text-end text-secondary'>" . ((int) $f['slo_days'] ?: '—') . "</td></tr>";
            }
            echo "</tbody></table></div></div></div>";
        };
        echo "<div class='row g-3'>";
        $table('Fases atrasadas agora', 'ti-alarm', $late, 'Nenhuma fase passou do SLO.');
        $table('Com a minha área ou comigo (GP/OTC)', 'ti-user-check', array_values($mine), 'Nada com você agora.');
        echo "</div>";

        // ------------------------------------------------------------------ resumos do painel lateral
        $link = fn (array $c) => "<a href='" . $e(Ciclo::getFormURLWithID((int) $c['id'])) . "'><b>" . $e(Ciclo::code((int) $c['id'])) . "</b></a>";
        $drawers = [];

        $rows = array_values($open);
        usort($rows, function ($a, $b) {
            $fa = Fase::active((int) $a['id']);
            $fb = Fase::active((int) $b['id']);
            return [(int) ($fb && Fase::isLate($fb)), $fb ? Fase::elapsed($fb) : 0] <=> [(int) ($fa && Fase::isLate($fa)), $fa ? Fase::elapsed($fa) : 0];
        });
        $h = "<p class='cf-dr-lead'>" . count($open) . " Ciclo(s) em andamento, " . count($late) . " com a fase atual além do SLO. Os atrasados aparecem primeiro.</p><div class='cf-dr-list'>";
        foreach ($rows as $c) {
            $f = Fase::active((int) $c['id']);
            $isLate = $f && Fase::isLate($f);
            $pct = $f && (int) $f['slo_days'] ? min(100, round(100 * Fase::elapsed($f) / ((int) $f['slo_days'] * 86400))) : 0;
            $h .= "<div class='cf-dr-item" . ($isLate ? ' bad' : '') . "'><div class='cf-dr-row'>" . $link($c) . "<span class='cf-dr-name'>" . $e($c['name']) . "</span><span class='cf-dr-val'>" . Menu::days(Ciclo::cycleSeconds($c)) . " d</span></div>"
                . "<div class='cf-dr-sub'>" . $e($c['customer']) . ' · ' . ($f ? 'em <b>' . $e(Fase::name($f['code'])) . '</b> (' . $e(Fase::area($f)) . ') há ' . Menu::days(Fase::elapsed($f)) . ' d' . ((int) $f['slo_days'] ? ' de ' . (int) $f['slo_days'] : '') : '') . "</div>"
                . ($f && (int) $f['slo_days'] ? "<div class='cf-dr-bar'><i style='width:$pct%'></i></div>" : '') . "</div>";
        }
        $drawers['andamento'] = ['Ciclos em andamento', 'ti-progress', '#3b82f6', $h . "</div>"];

        $rows = array_values($done);
        usort($rows, fn ($a, $b) => strcmp((string) $b['date_end'], (string) $a['date_end']));
        $h = "<p class='cf-dr-lead'>" . count($done) . " Ciclo(s) concluído(s) nos últimos $months meses.</p><div class='cf-dr-list'>";
        foreach ($rows as $c) {
            $d = Ciclo::cycleSeconds($c) / 86400;
            $h .= "<div class='cf-dr-item" . ($d > $meta ? ' warn' : ' good') . "'><div class='cf-dr-row'>" . $link($c) . "<span class='cf-dr-name'>" . $e($c['name']) . "</span><span class='cf-dr-val'>" . round($d) . " d</span></div>"
                . "<div class='cf-dr-sub'>" . $e($c['customer']) . ' · concluído em ' . Menu::date($c['date_end']) . ' · ' . Menu::money((float) $c['amount']) . ((int) $c['returns'] ? ' · ' . (int) $c['returns'] . ' devolução(ões)' : '') . "</div></div>";
        }
        $drawers['concluidos'] = ['Ciclos concluídos', 'ti-circle-check', '#22c55e', $h . (!$rows ? "<p class='text-secondary'>Nenhum no período.</p>" : '') . "</div>"];

        $sorted = $cycleDays;
        sort($sorted);
        $median = $sorted ? $sorted[intdiv(count($sorted), 2)] : 0;
        $max = max(1, $meta, ...($sorted ?: [0]));
        $h = "<div class='cf-dr-stats'><div><small>Média</small><b>" . round($avg) . " d</b></div><div><small>Mediana</small><b>" . round($median) . " d</b></div>"
            . "<div><small>Menor</small><b>" . ($sorted ? round($sorted[0]) : 0) . " d</b></div><div><small>Maior</small><b>" . ($sorted ? round(end($sorted)) : 0) . " d</b></div></div>"
            . "<p class='cf-dr-lead'>Meta: <b>$meta dias</b> (a soma dos SLOs das fases). Cada barra é um Ciclo concluído; a linha marca a meta.</p><div class='cf-dr-list'>";
        foreach ($rows as $c) {
            $d = Ciclo::cycleSeconds($c) / 86400;
            $h .= "<div class='cf-dr-item'><div class='cf-dr-row'>" . $link($c) . "<span class='cf-dr-name'>" . $e($c['name']) . "</span><span class='cf-dr-val'>" . round($d) . " d</span></div>"
                . "<div class='cf-dr-bar big" . ($d > $meta ? ' bad' : '') . "'><i style='width:" . round(100 * $d / $max) . "%'></i><em style='left:" . round(100 * $meta / $max) . "%'></em></div></div>";
        }
        $drawers['ciclo'] = ['Ciclo médio', 'ti-clock-hour-4', '#14b8a6', $h . "</div>"];

        $h = "<p class='cf-dr-lead'>Quantas vezes cada fase terminou dentro do SLO nos últimos $months meses. Fases abaixo de 80% merecem atenção.</p><table class='table table-sm cf-dr-table'><thead><tr><th>Fase</th><th class='text-end'>Médio</th><th class='text-end'>SLO</th><th style='width:38%'>No SLO</th></tr></thead><tbody>";
        foreach ($phase['names'] as $i => $name) {
            if (!$phase['count'][$i]) {
                continue;
            }
            $p = $phase['hit'][$i];
            $h .= "<tr><td>" . $e($name) . "</td><td class='text-end'>" . str_replace('.', ',', (string) $phase['avg'][$i]) . "</td><td class='text-end text-secondary'>" . ($phase['slo'][$i] ?: '—') . "</td>"
                . "<td>" . ($p === null ? '—' : "<div class='cf-dr-pct" . ($p < 80 ? ' bad' : '') . "'><i style='width:$p%'></i><span>$p%</span></div>") . "</td></tr>";
        }
        $drawers['slo'] = ['Fases no SLO', 'ti-target-arrow', '#a855f7', $h . "</tbody></table>"];

        $h = "<p class='cf-dr-lead'>" . count($returnEvents) . " devolução(ões) nos últimos $months meses: o Ciclo voltou para uma fase anterior para corrigir algo.</p><div class='cf-dr-list'>";
        foreach ($returnEvents as $ev) {
            $c = $byId[$ev['plugin_ciclo_ciclos_id']] ?? null;
            if (!$c) {
                continue;
            }
            [$to, $why] = preg_match('/^Para "([^"]+)": (.*)$/s', (string) $ev['comment'], $m) ? [$m[1], $m[2]] : ['', (string) $ev['comment']];
            $h .= "<div class='cf-dr-item bad'><div class='cf-dr-row'>" . $link($c) . "<span class='cf-dr-name'>" . $e($c['name']) . "</span><span class='cf-dr-val small'>" . Menu::date($ev['date']) . "</span></div>"
                . "<div class='cf-dr-sub'><b>" . $e(Fase::name((string) $ev['code'])) . "</b> <i class='ti ti-arrow-right'></i> <b>" . $e($to) . "</b> · por " . $e(getUserName((int) $ev['users_id'])) . "</div>"
                . "<div class='cf-dr-note'>" . $e($why) . "</div></div>";
        }
        $drawers['devolucoes'] = ['Devoluções', 'ti-arrow-back-up', '#ef4444', $h . (!$returnEvents ? "<p class='text-secondary'>Nenhuma devolução no período.</p>" : '') . "</div>"];

        $h = "<div class='cf-dr-stats'><div><small>Total a receber</small><b>" . Menu::money($receivable) . "</b></div><div class='" . ($overdue ? 'bad' : '') . "'><small>Vencido</small><b>" . Menu::money(array_sum(array_map(fn ($p) => (float) $p['amount'], $overdue))) . "</b></div></div><div class='cf-dr-list'>";
        foreach ($parcelas as $p) {
            $c = $byId[$p['plugin_ciclo_ciclos_id']];
            $days = $p['date_expected'] ? (int) floor((strtotime($p['date_expected']) - strtotime('today', $nowTs)) / 86400) : null;
            $when = $days === null ? 'sem vencimento' : ($days < 0 ? 'vencida há ' . -$days . ' dia(s)' : ($days === 0 ? 'vence hoje' : "vence em $days dia(s)"));
            $h .= "<div class='cf-dr-item" . ($days !== null && $days < 0 ? ' bad' : '') . "'><div class='cf-dr-row'>" . $link($c) . "<span class='cf-dr-name'>" . $e($c['name']) . "</span><span class='cf-dr-val'>" . Menu::money((float) $p['amount']) . "</span></div>"
                . "<div class='cf-dr-sub'>Parcela " . $e($p['description'] ?: '—') . ' · vencimento ' . Menu::date($p['date_expected']) . " · <b>$when</b></div></div>";
        }
        $drawers['receber'] = ['Valores a receber', 'ti-cash', '#f59e0b', $h . (!$parcelas ? "<p class='text-secondary'>Nada em aberto.</p>" : '') . "</div>"];

        foreach ($drawers as $key => [$title, $icon, $color, $html]) {
            echo "<template id='cf-dr-$key' data-title='" . $e($title) . "' data-icon='$icon' data-color='$color'>$html</template>";
        }
        echo "<div class='cf-drawer-backdrop' hidden></div><aside class='cf-drawer' aria-hidden='true' role='dialog' aria-labelledby='cf-drawer-title'>"
            . "<div class='cf-drawer-head'><span class='cf-drawer-icon'><i class='ti'></i></span><h3 id='cf-drawer-title'></h3><button type='button' class='btn-close' aria-label='Fechar'></button></div>"
            . "<div class='cf-drawer-body'></div></aside>";

        echo "<script type='application/json' id='cf-data'>" . json_encode(['phase' => $phase, 'now' => $now, 'month' => $month], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "</script>";
        echo Menu::script('painel.js');
        echo "</div>";
    }

    /**
     * Onde os Ciclos estão agora: a esteira das fases (cada marca é uma fase; o número
     * em cima é quantos Ciclos estão nela) e, embaixo, cada Ciclo na sua fase com os
     * dias na fase contra o SLO. Atrasados em vermelho.
     */
    private static function pipeline(array $etapas, array $active, int $open, int $late): string
    {
        $e = [Menu::class, 'e'];
        $steps = array_values(array_filter($etapas, fn ($et) => $et['code'] !== 'aviso' && $et['is_active']));
        $byCode = [];
        foreach ($active as $f) {
            $byCode[$f['code']][] = $f;
        }
        $h = "<div class='card h-100 cf-chart-card'><div class='card-header'><div><h3 class='card-title'><i class='ti ti-route me-2'></i>Onde os Ciclos estão agora</h3>"
            . "<div class='small text-secondary'>$open em andamento · " . ($late ? "<span class='text-danger fw-bold'>$late parado(s) além do SLO</span>" : 'todos dentro do SLO') . "</div></div></div><div class='card-body'>";
        if (!$active) {
            return $h . "<div class='cf-empty' style='height:240px'><i class='ti ti-route'></i>Nenhum Ciclo em andamento.</div></div></div>";
        }

        // esteira: uma marca por fase, com o número de Ciclos e a cor do pior caso
        $h .= "<div class='cf-pipe'><div class='cf-pipe-track'>";
        $n = count($steps);
        foreach ($steps as $i => $et) {
            $here = $byCode[$et['code']] ?? [];
            $bad = array_filter($here, fn ($f) => Fase::isLate($f));
            $cls = $here ? ($bad ? 'bad' : 'ok') : '';
            $h .= "<div class='cf-pipe-step $cls' title='" . $e(($i + 1) . '. ' . $et['name'] . ($here ? ' · ' . count($here) . ' Ciclo(s)' : '')) . "' style='left:" . round(100 * $i / max(1, $n - 1), 2) . "%'>"
                . ($here ? "<b>" . count($here) . "</b>" : '') . "<i></i></div>";
        }
        $h .= "</div><div class='cf-pipe-ends'><span>Validação</span><span>Compra e entrega</span><span>Recebimento</span></div></div>";

        // Ciclos agrupados pela fase, na ordem do processo
        $h .= "<div class='cf-pipe-list'>";
        foreach ($steps as $i => $et) {
            $here = $byCode[$et['code']] ?? [];
            if (!$here) {
                continue;
            }
            usort($here, fn ($a, $b) => Fase::elapsed($b) <=> Fase::elapsed($a));
            $h .= "<div class='cf-pipe-group'><div class='cf-pipe-phase'><span class='cf-pipe-num'>" . ($i + 1) . "</span><b>" . $e($et['name']) . "</b><span class='text-secondary'>" . $e(Fase::area($here[0])) . "</span></div>";
            foreach ($here as $f) {
                $isLate = Fase::isLate($f);
                $slo = (int) $f['slo_days'];
                $secs = Fase::elapsed($f);
                $pct = $slo ? min(100, round(100 * $secs / ($slo * 86400))) : 0;
                $over = $slo && $isLate ? '+' . Menu::days($secs - $slo * 86400) . 'd além' : ($slo ? 'faltam ' . Menu::days(max(0, $slo * 86400 - $secs)) . 'd' : 'sem SLO');
                $h .= "<a class='cf-pipe-ciclo" . ($isLate ? ' bad' : '') . "' href='" . $e(Ciclo::getFormURLWithID((int) $f['ciclo']['id'])) . "'>"
                    . "<span class='cf-pipe-code'>" . $e(Ciclo::code((int) $f['ciclo']['id'])) . "</span><span class='cf-pipe-name'>" . $e($f['ciclo']['name']) . "<small>" . $e($f['ciclo']['customer']) . "</small></span>"
                    . "<span class='cf-pipe-time'><b>" . Menu::days($secs) . "d</b>" . ($slo ? " / $slo" : '') . "<small>$over</small></span>"
                    . ($slo ? "<span class='cf-pipe-bar'><i style='width:$pct%'></i></span>" : '') . "</a>";
            }
            $h .= "</div>";
        }
        return $h . "</div></div></div>";
    }
}
