<?php

namespace GlpiPlugin\Gestaoeventos;

use Session;

/**
 * Painel de Eventos: indicadores (cada cartão abre o resumo num painel lateral), tempo de cada etapa
 * contra o SLO, onde os eventos estão agora, ciclo médio por mês contra a meta, Budget × EAC × Realizado,
 * etapas atrasadas, aprovações pendentes e o que está com a minha área. Os filtros valem para tudo.
 */
class Painel
{
    public static function show(array $get): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $fin = Evento::canFinance();
        $months = (int) ($get['months'] ?? 6);
        $months = in_array($months, [3, 6, 12, 24], true) ? $months : 6;
        $since = date('Y-m-d H:i:s', strtotime("-$months months"));
        $etapas = Etapa::all();
        $nowTs = strtotime($_SESSION['glpi_currenttime']);
        $today = date('Y-m-d', $nowTs);

        // ------------------------------------------------------------------ filtros (valem para cards, listas e gráficos)
        $fGp = (int) ($get['gp'] ?? 0);
        $fType = isset(Evento::TYPES[$get['type'] ?? '']) ? $get['type'] : '';
        $fPartner = trim((string) ($get['partner'] ?? ''));
        $fPhase = isset($etapas[$get['phase'] ?? '']) ? $get['phase'] : '';
        $where = ['is_deleted' => 0] + getEntitiesRestrictCriteria(Evento::getTable(), 'entities_id', '', true);
        if ($scope = Acesso::scope()) {
            $where[] = $scope;
        }
        if ($fGp) {
            $where['users_id_gp'] = $fGp;
        }
        if ($fType) {
            $where['event_type'] = $fType;
        }
        if ($fPartner !== '') {
            $where['partner'] = ['LIKE', "%$fPartner%"];
        }
        if ($fPhase) {
            $where['current_phase'] = $fPhase;
        }
        $eventos = iterator_to_array($DB->request(['FROM' => Evento::getTable(), 'WHERE' => $where]), false);
        $byId = array_column($eventos, null, 'id');
        $drafts = array_filter($eventos, fn ($c) => (int) $c['status'] === Evento::OPEN && $c['is_draft']);
        $open = array_filter($eventos, fn ($c) => (int) $c['status'] === Evento::OPEN && !$c['is_draft']);
        $done = array_filter($eventos, fn ($c) => (int) $c['status'] === Evento::DONE && $c['date_end'] >= $since);
        $cycleDays = array_map(fn ($c) => Evento::cycleSeconds($c) / 86400, $done);
        $avg = $cycleDays ? array_sum($cycleDays) / count($cycleDays) : 0;
        $meta = Etapa::meta();

        // ------------------------------------------------------------------ etapas
        $perPhase = [];
        $phaseList = [];
        $hit = [];
        $active = [];
        $faseRows = $byId ? iterator_to_array($DB->request(['FROM' => Fase::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => array_keys($byId)]]), false) : [];
        foreach ($faseRows as $f) {
            $c = $byId[$f['plugin_gestaoeventos_eventos_id']] ?? null;
            if (!$c) {
                continue;
            }
            if ($f['status'] === 'done' && $f['date_end'] >= $since) {
                $perPhase[$f['code']][] = Fase::elapsed($f) / 86400;
                $phaseList[$f['code']][] = ['code' => (string) $c['code'], 'name' => (string) $c['name'], 'url' => Evento::getFormURLWithID((int) $c['id']),
                    'days' => Menu::days(Fase::elapsed($f)), 'slo' => (int) $f['slo_days'], 'late' => Fase::isLate($f), 'end' => substr((string) $f['date_end'], 0, 10)];
                if ((int) $f['slo_days']) {
                    $hit[$f['code']][0] = ($hit[$f['code']][0] ?? 0) + (Fase::isLate($f) ? 0 : 1);
                    $hit[$f['code']][1] = ($hit[$f['code']][1] ?? 0) + 1;
                }
            }
            if ($f['status'] === 'active' && (int) $c['status'] === Evento::OPEN) {
                $active[] = $f + ['evento' => $c];
            }
        }
        $sloHit = [array_sum(array_column($hit, 0)), array_sum(array_column($hit, 1))];
        $late = array_values(array_filter($active, fn ($f) => Fase::isLate($f)));
        usort($late, fn ($a, $b) => (Fase::elapsed($b) - (int) $b['slo_days'] * 86400) <=> (Fase::elapsed($a) - (int) $a['slo_days'] * 86400));
        $warn = array_filter($active, fn ($f) => Fase::isWarn($f));
        $returnEvents = $byId ? iterator_to_array($DB->request(['FROM' => Historico::getTable(), 'WHERE' => ['action' => 'return', 'date' => ['>=', $since], 'plugin_gestaoeventos_eventos_id' => array_keys($byId)], 'ORDER' => 'date DESC']), false) : [];

        // ------------------------------------------------------------------ aprovações
        $approvals = $byId ? iterator_to_array($DB->request(['FROM' => Aprovacao::getTable(), 'WHERE' => ['status' => 'pending', 'plugin_gestaoeventos_eventos_id' => array_keys($byId)], 'ORDER' => 'date_due']), false) : [];
        $overdueAppr = array_filter($approvals, fn ($a) => Aprovacao::isOverdue($a));
        $me = (int) Session::getLoginUserID();
        $myAppr = [];
        foreach ($approvals as $a) {
            $ev = new Evento();
            if ($ev->getFromDB((int) $a['plugin_gestaoeventos_eventos_id']) && Aprovacao::whyNot($a, $ev) === null) {
                $myAppr[] = $a;
            }
        }

        // ------------------------------------------------------------------ risco e próximos eventos
        $risky = [];
        $upcoming = [];
        foreach ($open as $c) {
            $ev = new Evento();
            $ev->getFromDB((int) $c['id']);
            if ($why = Evento::semaforo($ev)) {
                $risky[] = [$c, $why];
            }
            if ($c['date_start'] && $c['date_start'] >= $today && $c['date_start'] <= date('Y-m-d', strtotime('+60 days', $nowTs))) {
                $upcoming[] = $c;
            }
        }
        usort($upcoming, fn ($a, $b) => strcmp((string) $a['date_start'], (string) $b['date_start']));

        // ------------------------------------------------------------------ financeiro
        $finRows = [];
        $tot = ['budget' => 0.0, 'eac' => 0.0, 'real' => 0.0, 'open' => 0.0, 'limit' => 0.0];
        if ($fin) {
            foreach (array_merge(array_values($open), array_values($done)) as $c) {
                $s = Financeiro::summary((int) $c['id']);
                $d = $s['despesa'];
                if (!$d['budget'] && !$d['eac'] && !$d['real']) {
                    continue;
                }
                $finRows[] = ['c' => $c, 'budget' => $s['limite'], 'eac' => $d['eac'], 'real' => $d['real'], 'open' => $d['open'], 'saldo' => $s['saldo'], 'receita' => $s['receita']['real']];
                $tot['budget'] += $s['limite'];
                $tot['eac'] += $d['eac'];
                $tot['real'] += $d['real'];
                $tot['open'] += $d['open'];
            }
            usort($finRows, fn ($a, $b) => $b['budget'] <=> $a['budget']);
        }

        // ------------------------------------------------------------------ dados dos gráficos
        $phase = ['names' => [], 'avg' => [], 'slo' => [], 'count' => [], 'hit' => [], 'list' => []];
        $now = ['names' => [], 'ok' => [], 'late' => [], 'list' => []];
        foreach ($etapas as $code => $et) {
            if (!$et['is_active']) {
                continue;
            }
            $list = $perPhase[$code] ?? [];
            $phase['names'][] = $et['name'];
            $phase['avg'][] = $list ? round(array_sum($list) / count($list), 1) : 0;
            $phase['slo'][] = (int) $et['slo_days'];
            $phase['count'][] = count($list);
            $phase['hit'][] = isset($hit[$code]) ? round(100 * $hit[$code][0] / $hit[$code][1]) : null;
            $phase['list'][] = $phaseList[$code] ?? [];
            $here = array_filter($active, fn ($f) => $f['code'] === $code);
            if ($here) {
                $now['names'][] = $et['name'];
                $now['ok'][] = count(array_filter($here, fn ($f) => !Fase::isLate($f)));
                $now['late'][] = count(array_filter($here, fn ($f) => Fase::isLate($f)));
            }
        }
        $monthly = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthly[date('Y-m', strtotime("first day of -$i month"))] = [];
        }
        foreach ($done as $c) {
            $k = substr((string) $c['date_end'], 0, 7);
            if (isset($monthly[$k])) {
                $monthly[$k][] = ['days' => round(Evento::cycleSeconds($c) / 86400), 'code' => (string) $c['code'], 'name' => (string) $c['name'], 'url' => Evento::getFormURLWithID((int) $c['id'])];
            }
        }
        $month = [
            'labels' => array_map(fn ($k) => ucfirst(['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][(int) substr($k, 5, 2) - 1]) . '/' . substr($k, 2, 2), array_keys($monthly)),
            'avg' => array_map(fn ($l) => $l ? round(array_sum(array_column($l, 'days')) / count($l)) : null, array_values($monthly)),
            'count' => array_map('count', array_values($monthly)),
            'list' => array_values($monthly),
            'meta' => $meta,
        ];
        $top = array_slice($finRows, 0, 10);
        $finChart = ['names' => array_map(fn ($r) => $r['c']['code'] . ' · ' . mb_strimwidth((string) $r['c']['name'], 0, 26, '…'), $top),
            'budget' => array_map(fn ($r) => round($r['budget'], 2), $top), 'eac' => array_map(fn ($r) => round($r['eac'], 2), $top), 'real' => array_map(fn ($r) => round($r['real'], 2), $top),
            'list' => array_map(fn ($r) => ['code' => (string) $r['c']['code'], 'name' => (string) $r['c']['name'], 'url' => Evento::getFormURLWithID((int) $r['c']['id']), 'open' => round($r['open'], 2), 'saldo' => round($r['saldo'], 2)], $top)];

        // ------------------------------------------------------------------ cabeçalho, filtros e cartões
        echo "<div class='ge'>";
        $gps = [];
        foreach ($DB->request(['SELECT' => ['users_id_gp'], 'DISTINCT' => true, 'FROM' => Evento::getTable(), 'WHERE' => ['is_deleted' => 0, 'users_id_gp' => ['>', 0]] + getEntitiesRestrictCriteria(Evento::getTable(), 'entities_id', '', true)]) as $r) {
            $gps[(int) $r['users_id_gp']] = getUserName((int) $r['users_id_gp']);
        }
        $filter = "<form method='get' class='d-flex flex-wrap gap-2 align-items-center m-0'><span class='small text-secondary'>Concluídos nos últimos</span><select class='form-select form-select-sm w-auto' name='months' onchange='this.form.submit()'>";
        foreach ([3, 6, 12, 24] as $m) {
            $filter .= "<option value='$m'" . ($m === $months ? ' selected' : '') . ">$m meses</option>";
        }
        $filter .= "</select><select class='form-select form-select-sm w-auto' name='type' onchange='this.form.submit()'><option value=''>Todos os tipos</option>" . Menu::options(Menu::labels(Evento::TYPES), $fType) . "</select>"
            . "<select class='form-select form-select-sm w-auto' name='gp' onchange='this.form.submit()'><option value='0'>Todos os GPs</option>" . Menu::options($gps, (string) $fGp) . "</select>"
            . "<select class='form-select form-select-sm w-auto' name='phase' onchange='this.form.submit()'><option value=''>Todas as etapas</option>" . Menu::options(array_map(fn ($x) => $x['name'], $etapas), $fPhase) . "</select>"
            . "<input class='form-control form-control-sm w-auto' name='partner' value='" . $e($fPartner) . "' placeholder='Fabricante / parceiro' onchange='this.form.submit()'>"
            . ($fGp || $fType || $fPartner !== '' || $fPhase ? "<a class='btn btn-sm btn-ghost-secondary' href='?months=$months'><i class='ti ti-x'></i></a>" : '')
            . "</form>" . "<button type='button' class='btn btn-outline-primary' id='ge-prox-open'><i class='ti ti-hourglass-high me-1'></i>Próximos eventos</button>" . Menu::button('Eventos', Evento::getSearchURL(), 'ti-list', 'btn-outline-secondary') . (Evento::canCreate() ? Menu::button('Novo evento', Evento::getFormURL()) : '');
        echo Menu::title('Painel de Eventos', 'painel', $filter);

        $kpi = fn ($key, $icon, $label, $value, $sub, $color) => "<div class='col-6 col-md-3'><button type='button' class='ge-kpi ge-kpi-btn' style='--c:$color' data-drawer='$key'>"
            . "<div class='ge-kpi-top'><span class='ge-kpi-label'>$label</span><i class='ti $icon'></i></div><div class='ge-kpi-value'>$value</div><div class='ge-kpi-sub'>$sub</div>"
            . "<div class='ge-kpi-more'>ver resumo <i class='ti ti-chevron-right'></i></div></button></div>";
        $dev = $tot['eac'] - $tot['budget'];
        echo "<div class='row g-3 mb-3'>";
        echo $kpi('andamento', 'ti-progress', 'Em andamento', count($open), count($late) . ' com etapa atrasada' . ($drafts ? ' · ' . count($drafts) . ' rascunho(s)' : ''), '#3b82f6');
        echo $kpi('concluidos', 'ti-circle-check', 'Concluídos', count($done), "nos últimos $months meses", '#22c55e');
        echo $kpi('ciclo', 'ti-clock-hour-4', 'Ciclo médio', $cycleDays ? number_format($avg, 0, ',', '.') . ' dias' : '—', "meta de $meta dias (soma dos SLOs)", '#14b8a6');
        echo $kpi('slo', 'ti-target-arrow', 'Etapas no SLO', $sloHit[1] ? round(100 * $sloHit[0] / $sloHit[1]) . '%' : '—', $sloHit[1] . ' etapa(s) com SLO concluídas' . ($warn ? ' · ' . count($warn) . ' perto do prazo' : ''), '#a855f7');
        echo $kpi('aprovacoes', 'ti-signature', 'Aprovações pendentes', count($approvals), count($overdueAppr) . ' vencida(s) · ' . count($myAppr) . ' comigo', '#f59e0b');
        echo $kpi('devolucoes', 'ti-arrow-back-up', 'Devoluções', count($returnEvents), 'retrabalho no período', '#ef4444');
        echo $kpi('risco', 'ti-alert-triangle', 'Em risco', count($risky), 'semáforo vermelho agora', '#f97316');
        echo $fin ? $kpi('financeiro', 'ti-cash', 'Budget × EAC', Menu::money($tot['eac']), 'Budget ' . Menu::money($tot['budget']) . ($dev > 0.005 ? ' · estouro de ' . Menu::money($dev) : ' · dentro do Budget'), $dev > 0.005 ? '#ef4444' : '#22c55e')
            : $kpi('proximos', 'ti-calendar-event', 'Próximos eventos', count($upcoming), 'nos próximos 60 dias', '#06b6d4');
        echo "</div>";

        // ------------------------------------------------------------------ gráficos
        $card = fn ($id, $icon, $title, $sub, $cls, $h) => "<div class='$cls'><div class='card h-100 ge-chart-card'><div class='card-header'><div><h3 class='card-title'><i class='ti $icon me-2'></i>$title</h3><div class='small text-secondary'>$sub</div></div></div>"
            . "<div class='card-body'><div class='ge-chart' id='$id' style='height:{$h}px'></div></div></div></div>";
        echo "<div class='row g-3 mb-3'>";
        echo $card('ge-c-phase', 'ti-hourglass', 'Tempo médio por etapa × SLO', 'Dias de cada etapa concluída no período. A faixa clara é o prazo (SLO); vermelho passou do prazo. Clique na etapa para ver os eventos.', 'col-xl-7', max(420, count($phase['names']) * 40 + 80));
        echo "<div class='col-xl-5'>" . self::pipeline($etapas, $active, count($open), count($late)) . "</div>";
        echo $card('ge-c-month', 'ti-chart-line', 'Ciclo médio por mês de conclusão', "Dias do envio do briefing ao aceite final, contra a meta de $meta dias", $fin ? 'col-xl-6' : 'col-12', 320);
        if ($fin) {
            echo $card('ge-c-fin', 'ti-cash', 'Budget × EAC × Realizado por evento', 'Os 10 maiores Budgets. Clique na barra para ver o saldo e abrir o evento.', 'col-xl-6', max(320, count($top) * 56 + 80));
        }
        echo "</div>";

        // ------------------------------------------------------------------ tabelas
        $mine = array_filter($active, fn ($f) => in_array($me, Avisos::members((int) $f['groups_id']), true) || in_array($me, [(int) $f['evento']['users_id_gp'], (int) $f['evento']['users_id_owner']], true));
        $table = function (string $title, string $icon, array $rows, string $empty) use ($e) {
            echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti $icon me-2'></i>$title</h3><div class='card-actions small text-secondary'>" . count($rows) . "</div></div>";
            if (!$rows) {
                echo "<div class='card-body text-secondary small'>$empty</div></div></div>";
                return;
            }
            echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Evento</th><th>Etapa</th><th>Área</th><th class='text-end'>Dias</th><th class='text-end'>SLO</th></tr></thead><tbody>";
            foreach (array_slice($rows, 0, 12) as $f) {
                $isLate = Fase::isLate($f);
                echo "<tr><td><a href='" . $e(Evento::getFormURLWithID((int) $f['evento']['id'])) . "'>" . $e($f['evento']['code']) . "</a> <span class='small text-secondary'>" . $e(mb_strimwidth((string) $f['evento']['name'], 0, 36, '…')) . "</span></td>"
                    . "<td>" . $e(Fase::name($f['code'])) . "</td><td class='small'>" . $e(Fase::area($f)) . "</td>"
                    . "<td class='text-end" . ($isLate ? ' text-danger fw-bold' : (Fase::isWarn($f) ? ' text-warning fw-bold' : '')) . "'>" . Menu::days(Fase::elapsed($f)) . "</td><td class='text-end text-secondary'>" . ((int) $f['slo_days'] ?: '—') . "</td></tr>";
            }
            echo "</tbody></table></div></div></div>";
        };
        echo "<div class='row g-3 mb-3'>";
        $table('Etapas atrasadas agora', 'ti-alarm', $late, 'Nenhuma etapa passou do SLO.');
        $table('Com a minha área ou comigo (GP/dono)', 'ti-user-check', array_values($mine), 'Nada com você agora.');
        echo "</div><div class='row g-3'>";
        echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-calendar-event me-2'></i>Próximos eventos (60 dias)</h3><div class='card-actions small text-secondary'>" . count($upcoming) . "</div></div>";
        echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Evento</th><th>Data</th><th>Etapa</th><th>Aprovação</th></tr></thead><tbody>";
        foreach (array_slice($upcoming, 0, 10) as $c) {
            echo "<tr><td><a href='" . $e(Evento::getFormURLWithID((int) $c['id'])) . "'>" . $e($c['code']) . "</a> <span class='small text-secondary'>" . $e(mb_strimwidth((string) $c['name'], 0, 34, '…')) . "</span></td><td class='small'>" . Menu::date($c['date_start']) . "</td>"
                . "<td class='small'>" . $e($c['current_phase'] ? Fase::name((string) $c['current_phase']) : '—') . "</td><td>" . Evento::badge(Evento::APPROVAL, (string) $c['approval_status']) . "</td></tr>";
        }
        echo $upcoming ? '' : "<tr><td colspan='4' class='text-secondary small'>Nenhum evento nos próximos 60 dias.</td></tr>";
        echo "</tbody></table></div></div></div>";
        echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-signature me-2'></i>Aprovações pendentes</h3><div class='card-actions small text-secondary'>" . count($approvals) . "</div></div>";
        echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Evento</th><th>Aprovação</th><th>Alçada</th><th>Prazo</th></tr></thead><tbody>";
        foreach (array_slice($approvals, 0, 10) as $a) {
            $c = $byId[$a['plugin_gestaoeventos_eventos_id']];
            echo "<tr><td><a href='" . $e(Evento::tabUrl((int) $c['id'], 'aprovacoes')) . "'>" . $e($c['code']) . "</a></td><td class='small'>" . $e(Aprovacao::KINDS[$a['kind']] ?? '') . ((float) $a['amount'] > 0 ? '<br>' . Menu::money((float) $a['amount']) : '') . "</td>"
                . "<td class='small'>" . $e($a['label']) . "</td><td class='small" . (Aprovacao::isOverdue($a) ? ' text-danger fw-bold' : '') . "'>" . Menu::date($a['date_due'], true) . "</td></tr>";
        }
        echo $approvals ? '' : "<tr><td colspan='4' class='text-secondary small'>Nenhuma aprovação pendente.</td></tr>";
        echo "</tbody></table></div></div></div></div>";

        // ------------------------------------------------------------------ resumos do painel lateral
        $link = fn (array $c) => "<a href='" . $e(Evento::getFormURLWithID((int) $c['id'])) . "'><b>" . $e($c['code']) . "</b></a>";
        $drawers = [];

        $rows = array_values($open);
        usort($rows, function ($a, $b) {
            $fa = Fase::active((int) $a['id']);
            $fb = Fase::active((int) $b['id']);
            return [(int) ($fb && Fase::isLate($fb)), $fb ? Fase::elapsed($fb) : 0] <=> [(int) ($fa && Fase::isLate($fa)), $fa ? Fase::elapsed($fa) : 0];
        });
        $h = "<p class='ge-dr-lead'>" . count($open) . " evento(s) em andamento, " . count($late) . " com a etapa atual além do SLO. Os atrasados aparecem primeiro.</p><div class='ge-dr-list'>";
        foreach ($rows as $c) {
            $f = Fase::active((int) $c['id']);
            $isLate = $f && Fase::isLate($f);
            $pct = $f && (int) $f['slo_days'] ? min(100, round(100 * Fase::elapsed($f) / ((int) $f['slo_days'] * 86400))) : 0;
            $h .= "<div class='ge-dr-item" . ($isLate ? ' bad' : '') . "'><div class='ge-dr-row'>" . $link($c) . "<span class='ge-dr-name'>" . $e($c['name']) . "</span><span class='ge-dr-val'>" . Menu::days(Evento::cycleSeconds($c)) . " d</span></div>"
                . "<div class='ge-dr-sub'>" . $e(Evento::TYPES[$c['event_type']][0] ?? '') . ' · ' . Menu::date($c['date_start']) . ' · ' . ($f ? 'em <b>' . $e(Fase::name($f['code'])) . '</b> (' . $e(Fase::area($f)) . ') há ' . Menu::days(Fase::elapsed($f)) . ' d' . ((int) $f['slo_days'] ? ' de ' . (int) $f['slo_days'] : '') : '') . "</div>"
                . ($f && (int) $f['slo_days'] ? "<div class='ge-dr-bar'><i style='width:$pct%'></i></div>" : '') . "</div>";
        }
        $drawers['andamento'] = ['Eventos em andamento', 'ti-progress', '#3b82f6', $h . (!$rows ? "<p class='text-secondary'>Nenhum evento em andamento.</p>" : '') . "</div>"];

        $rows = array_values($done);
        usort($rows, fn ($a, $b) => strcmp((string) $b['date_end'], (string) $a['date_end']));
        $h = "<p class='ge-dr-lead'>" . count($done) . " evento(s) concluído(s) nos últimos $months meses.</p><div class='ge-dr-list'>";
        foreach ($rows as $c) {
            $d = Evento::cycleSeconds($c) / 86400;
            $h .= "<div class='ge-dr-item" . ($d > $meta ? ' warn' : ' good') . "'><div class='ge-dr-row'>" . $link($c) . "<span class='ge-dr-name'>" . $e($c['name']) . "</span><span class='ge-dr-val'>" . round($d) . " d</span></div>"
                . "<div class='ge-dr-sub'>" . $e($c['city']) . ' · concluído em ' . Menu::date($c['date_end']) . ((int) $c['returns'] ? ' · ' . (int) $c['returns'] . ' devolução(ões)' : '') . "</div></div>";
        }
        $drawers['concluidos'] = ['Eventos concluídos', 'ti-circle-check', '#22c55e', $h . (!$rows ? "<p class='text-secondary'>Nenhum no período.</p>" : '') . "</div>"];

        $sorted = $cycleDays;
        sort($sorted);
        $median = $sorted ? $sorted[intdiv(count($sorted), 2)] : 0;
        $max = max(1, $meta, ...($sorted ?: [0]));
        $h = "<div class='ge-dr-stats'><div><small>Média</small><b>" . round($avg) . " d</b></div><div><small>Mediana</small><b>" . round($median) . " d</b></div>"
            . "<div><small>Menor</small><b>" . ($sorted ? round($sorted[0]) : 0) . " d</b></div><div><small>Maior</small><b>" . ($sorted ? round(end($sorted)) : 0) . " d</b></div></div>"
            . "<p class='ge-dr-lead'>Meta: <b>$meta dias</b> (a soma dos SLOs das etapas). Cada barra é um evento concluído; a linha marca a meta.</p><div class='ge-dr-list'>";
        foreach ($rows as $c) {
            $d = Evento::cycleSeconds($c) / 86400;
            $h .= "<div class='ge-dr-item'><div class='ge-dr-row'>" . $link($c) . "<span class='ge-dr-name'>" . $e($c['name']) . "</span><span class='ge-dr-val'>" . round($d) . " d</span></div>"
                . "<div class='ge-dr-bar big" . ($d > $meta ? ' bad' : '') . "'><i style='width:" . round(100 * $d / $max) . "%'></i><em style='left:" . round(100 * $meta / $max) . "%'></em></div></div>";
        }
        $drawers['ciclo'] = ['Ciclo médio', 'ti-clock-hour-4', '#14b8a6', $h . "</div>"];

        $h = "<p class='ge-dr-lead'>Quantas vezes cada etapa terminou dentro do SLO nos últimos $months meses. Etapas abaixo de 80% merecem atenção.</p><table class='table table-sm ge-dr-table'><thead><tr><th>Etapa</th><th class='text-end'>Médio</th><th class='text-end'>SLO</th><th style='width:38%'>No SLO</th></tr></thead><tbody>";
        foreach ($phase['names'] as $i => $name) {
            if (!$phase['count'][$i]) {
                continue;
            }
            $p = $phase['hit'][$i];
            $h .= "<tr><td>" . $e($name) . "</td><td class='text-end'>" . str_replace('.', ',', (string) $phase['avg'][$i]) . "</td><td class='text-end text-secondary'>" . ($phase['slo'][$i] ?: '—') . "</td>"
                . "<td>" . ($p === null ? '—' : "<div class='ge-dr-pct" . ($p < 80 ? ' bad' : '') . "'><i style='width:$p%'></i><span>$p%</span></div>") . "</td></tr>";
        }
        $drawers['slo'] = ['Etapas no SLO', 'ti-target-arrow', '#a855f7', $h . "</tbody></table>" . ($warn ? "<p class='ge-dr-lead mt-3'><b>" . count($warn) . "</b> etapa(s) em andamento já consumiram o percentual de alerta do SLO.</p>" : '')];

        $h = "<div class='ge-dr-stats'><div><small>Pendentes</small><b>" . count($approvals) . "</b></div><div class='" . ($overdueAppr ? 'bad' : '') . "'><small>Vencidas</small><b>" . count($overdueAppr) . "</b></div><div><small>Comigo</small><b>" . count($myAppr) . "</b></div></div><div class='ge-dr-list'>";
        foreach ($approvals as $a) {
            $c = $byId[$a['plugin_gestaoeventos_eventos_id']];
            $h .= "<div class='ge-dr-item" . (Aprovacao::isOverdue($a) ? ' bad' : '') . "'><div class='ge-dr-row'><a href='" . $e(Evento::tabUrl((int) $c['id'], 'aprovacoes')) . "'><b>" . $e($c['code']) . "</b></a><span class='ge-dr-name'>" . $e(Aprovacao::KINDS[$a['kind']] ?? '') . "</span>"
                . "<span class='ge-dr-val small'>" . ((float) $a['amount'] > 0 ? Menu::money((float) $a['amount']) : '') . "</span></div><div class='ge-dr-sub'>" . $e($a['title']) . ' · alçada ' . $e($a['label']) . ' · prazo ' . Menu::date($a['date_due'], true) . "</div></div>";
        }
        $drawers['aprovacoes'] = ['Aprovações pendentes', 'ti-signature', '#f59e0b', $h . (!$approvals ? "<p class='text-secondary'>Nenhuma aprovação pendente.</p>" : '') . "</div>"];

        $h = "<p class='ge-dr-lead'>" . count($returnEvents) . " devolução(ões) nos últimos $months meses: o evento voltou para uma etapa anterior para corrigir algo.</p><div class='ge-dr-list'>";
        foreach ($returnEvents as $ev) {
            $c = $byId[$ev['plugin_gestaoeventos_eventos_id']] ?? null;
            if (!$c) {
                continue;
            }
            [$to, $why] = preg_match('/^Para "([^"]+)": (.*)$/s', (string) $ev['comment'], $m) ? [$m[1], $m[2]] : ['', (string) $ev['comment']];
            $h .= "<div class='ge-dr-item bad'><div class='ge-dr-row'>" . $link($c) . "<span class='ge-dr-name'>" . $e($c['name']) . "</span><span class='ge-dr-val small'>" . Menu::date($ev['date']) . "</span></div>"
                . "<div class='ge-dr-sub'><b>" . $e(Fase::name((string) $ev['code'])) . "</b> <i class='ti ti-arrow-right'></i> <b>" . $e($to) . "</b> · por " . $e(getUserName((int) $ev['users_id'])) . "</div>"
                . "<div class='ge-dr-note'>" . $e($why) . "</div></div>";
        }
        $drawers['devolucoes'] = ['Devoluções', 'ti-arrow-back-up', '#ef4444', $h . (!$returnEvents ? "<p class='text-secondary'>Nenhuma devolução no período.</p>" : '') . "</div>"];

        $h = "<p class='ge-dr-lead'>Eventos com semáforo vermelho: etapa atrasada, risco alto aberto, marco crítico vencido ou aprovação vencida.</p><div class='ge-dr-list'>";
        foreach ($risky as [$c, $why]) {
            $h .= "<div class='ge-dr-item bad'><div class='ge-dr-row'>" . $link($c) . "<span class='ge-dr-name'>" . $e($c['name']) . "</span><span class='ge-dr-val small'>" . Menu::date($c['date_start']) . "</span></div><div class='ge-dr-sub'>" . $e(implode(' · ', $why)) . "</div></div>";
        }
        $drawers['risco'] = ['Eventos em risco', 'ti-alert-triangle', '#f97316', $h . (!$risky ? "<p class='text-secondary'>Nenhum evento em risco.</p>" : '') . "</div>"];

        if ($fin) {
            $h = "<div class='ge-dr-stats'><div><small>Budget</small><b>" . Menu::money($tot['budget']) . "</b></div><div class='" . ($dev > 0.005 ? 'bad' : '') . "'><small>EAC</small><b>" . Menu::money($tot['eac']) . "</b></div>"
                . "<div><small>Realizado</small><b>" . Menu::money($tot['real']) . "</b></div><div><small>Comprometido</small><b>" . Menu::money($tot['open']) . "</b></div></div>"
                . "<p class='ge-dr-lead'>Desvio EAC × Budget: <b class='" . ($dev > 0.005 ? 'text-danger' : 'text-success') . "'>" . ($dev > 0 ? '+' : '') . Menu::money($dev) . "</b>. Soma dos eventos filtrados (em andamento e concluídos no período).</p><div class='ge-dr-list'>";
            foreach ($finRows as $r) {
                $over = $r['eac'] - $r['budget'];
                $h .= "<div class='ge-dr-item" . ($over > 0.005 ? ' bad' : '') . "'><div class='ge-dr-row'>" . $link($r['c']) . "<span class='ge-dr-name'>" . $e($r['c']['name']) . "</span><span class='ge-dr-val'>" . Menu::money($r['eac']) . "</span></div>"
                    . "<div class='ge-dr-sub'>Budget " . Menu::money($r['budget']) . ' · realizado ' . Menu::money($r['real']) . ' · saldo ' . Menu::money($r['saldo']) . "</div></div>";
            }
            $drawers['financeiro'] = ['Budget × EAC × Realizado', 'ti-cash', $dev > 0.005 ? '#ef4444' : '#22c55e', $h . (!$finRows ? "<p class='text-secondary'>Sem lançamentos financeiros.</p>" : '') . "</div>"];
        } else {
            $h = "<p class='ge-dr-lead'>" . count($upcoming) . " evento(s) começam nos próximos 60 dias.</p><div class='ge-dr-list'>";
            foreach ($upcoming as $c) {
                $h .= "<div class='ge-dr-item'><div class='ge-dr-row'>" . $link($c) . "<span class='ge-dr-name'>" . $e($c['name']) . "</span><span class='ge-dr-val small'>" . Menu::date($c['date_start']) . "</span></div><div class='ge-dr-sub'>" . $e($c['city']) . ' · ' . $e($c['current_phase'] ? Fase::name((string) $c['current_phase']) : '') . "</div></div>";
            }
            $drawers['proximos'] = ['Próximos eventos', 'ti-calendar-event', '#06b6d4', $h . (!$upcoming ? "<p class='text-secondary'>Nenhum.</p>" : '') . "</div>"];
        }

        foreach ($drawers as $key => [$title, $icon, $color, $html]) {
            echo "<template id='ge-dr-$key' data-title='" . $e($title) . "' data-icon='$icon' data-color='$color'>$html</template>";
        }
        echo "<div class='ge-drawer-backdrop' hidden></div><aside class='ge-drawer' aria-hidden='true' role='dialog' aria-labelledby='ge-drawer-title'>"
            . "<div class='ge-drawer-head'><span class='ge-drawer-icon'><i class='ti'></i></span><h3 id='ge-drawer-title'></h3><button type='button' class='btn-close' aria-label='Fechar'></button></div>"
            . "<div class='ge-drawer-body'></div></aside>";

        echo "<script type='application/json' id='ge-data'>" . json_encode(['phase' => $phase, 'now' => $now, 'month' => $month, 'fin' => $fin ? $finChart : null], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "</script>";
        $avisos = Alerta::settings();
        echo "<script type='application/json' id='ge-prox-data'>" . json_encode(['now' => time(), 'days' => $avisos['dash_days'], 'auto' => $avisos['dash_on'], 'today' => date('Y-m-d'), 'user' => (int) Session::getLoginUserID(),
            'calendar' => Menu::url('/front/calendario.php'), 'events' => Alerta::upcoming()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "</script>";
        echo Menu::script('painel.js') . Menu::script('proximos.js');
        echo "</div>";
    }

    /**
     * Onde os eventos estão agora: a esteira das etapas (cada marca é uma etapa; o número em cima é
     * quantos eventos estão nela) e, embaixo, cada evento na sua etapa com os dias contra o SLO.
     */
    private static function pipeline(array $etapas, array $active, int $open, int $late): string
    {
        $e = [Menu::class, 'e'];
        $steps = array_values(array_filter($etapas, fn ($et) => $et['is_active']));
        $byCode = [];
        foreach ($active as $f) {
            $byCode[$f['code']][] = $f;
        }
        $h = "<div class='card h-100 ge-chart-card'><div class='card-header'><div><h3 class='card-title'><i class='ti ti-route me-2'></i>Onde os eventos estão agora</h3>"
            . "<div class='small text-secondary'>$open em andamento · " . ($late ? "<span class='text-danger fw-bold'>$late parado(s) além do SLO</span>" : 'todos dentro do SLO') . "</div></div></div><div class='card-body'>";
        if (!$active) {
            return $h . "<div class='ge-empty' style='height:240px'><i class='ti ti-route'></i>Nenhum evento em andamento.</div></div></div>";
        }
        $h .= "<div class='ge-pipe'><div class='ge-pipe-track'>";
        $n = count($steps);
        foreach ($steps as $i => $et) {
            $here = $byCode[$et['code']] ?? [];
            $bad = array_filter($here, fn ($f) => Fase::isLate($f));
            $cls = $here ? ($bad ? 'bad' : 'ok') : '';
            $h .= "<div class='ge-pipe-step $cls' title='" . $e(($i + 1) . '. ' . $et['name'] . ($here ? ' · ' . count($here) . ' evento(s)' : '')) . "' style='left:" . round(100 * $i / max(1, $n - 1), 2) . "%'>"
                . ($here ? "<b>" . count($here) . "</b>" : '') . "<i></i></div>";
        }
        $h .= "</div><div class='ge-pipe-ends'><span>Briefing</span><span>Compras e produção</span><span>Encerramento</span></div></div>";
        $h .= "<div class='ge-pipe-list'>";
        foreach ($steps as $i => $et) {
            $here = $byCode[$et['code']] ?? [];
            if (!$here) {
                continue;
            }
            usort($here, fn ($a, $b) => Fase::elapsed($b) <=> Fase::elapsed($a));
            $h .= "<div class='ge-pipe-group'><div class='ge-pipe-phase'><span class='ge-pipe-num'>" . ($i + 1) . "</span><b>" . $e($et['name']) . "</b><span class='text-secondary'>" . $e(Fase::area($here[0])) . "</span></div>";
            foreach ($here as $f) {
                $isLate = Fase::isLate($f);
                $slo = (int) $f['slo_days'];
                $secs = Fase::elapsed($f);
                $pct = $slo ? min(100, round(100 * $secs / ($slo * 86400))) : 0;
                $over = $slo && $isLate ? '+' . Menu::days($secs - $slo * 86400) . 'd além' : ($slo ? 'faltam ' . Menu::days(max(0, $slo * 86400 - $secs)) . 'd' : 'sem SLO');
                $h .= "<a class='ge-pipe-evento" . ($isLate ? ' bad' : '') . "' href='" . $e(Evento::getFormURLWithID((int) $f['evento']['id'])) . "'>"
                    . "<span class='ge-pipe-code'>" . $e($f['evento']['code']) . "</span><span class='ge-pipe-name'>" . $e($f['evento']['name']) . "<small>" . $e(Menu::date($f['evento']['date_start'])) . ' · ' . $e($f['evento']['city']) . "</small></span>"
                    . "<span class='ge-pipe-time'><b>" . Menu::days($secs) . "d</b>" . ($slo ? " / $slo" : '') . "<small>$over</small></span>"
                    . ($slo ? "<span class='ge-pipe-bar'><i style='width:$pct%'></i></span>" : '') . "</a>";
            }
            $h .= "</div>";
        }
        return $h . "</div></div></div>";
    }
}
