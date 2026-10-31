<?php

namespace GlpiPlugin\Reembolso;

use CommonGLPI;
use Dropdown;
use Html;
use User;

/**
 * Dashboard de reembolsos: filtros, indicadores, gráficos, detalhamento e XLSX.
 */
class Dashboard extends CommonGLPI
{
    public static string $rightname = 'plugin_reembolso';

    public static function getTypeName($nb = 0)
    {
        return 'Dashboard de reembolsos';
    }

    public static function getIcon()
    {
        return 'ti ti-chart-bar';
    }

    public static function canView(): bool
    {
        return Reembolso::canView();
    }

    public static function getSearchURL($full = true)
    {
        return $full ? Menu::url('/front/dashboard.php') : '/plugins/reembolso/front/dashboard.php';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function show(array $get): void
    {
        $f = Report::filters($get);
        $rows = Report::rows($f);
        $s = Report::summary($rows);
        $k = $s['kpi'];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $query = http_build_query(array_intersect_key($f, array_flip(['from', 'to', 'users', 'centro', 'tipo', 'status'])));

        echo Menu::css();
        echo "<div class='rb'>";
        $actions = "<a class='btn btn-success' href='" . $e(Menu::url('/front/export.php') . '?' . $query) . "'><i class='ti ti-file-spreadsheet me-1'></i>Exportar XLSX</a>"
            . (Reembolso::canCreate() ? "<a class='btn btn-primary' href='" . $e(Reembolso::getFormURL()) . "'><i class='ti ti-plus me-1'></i>Nova solicitação</a>" : '');
        echo Menu::title('Dashboard de reembolsos', 'dashboard', $actions);

        // filtros
        echo "<form method='get' class='card mb-3'><div class='card-body row g-2 align-items-end'>";
        echo "<div class='col-6 col-md-2'><label class='form-label'>De</label><input type='date' class='form-control' name='from' value='{$f['from']}'></div>";
        echo "<div class='col-6 col-md-2'><label class='form-label'>Até</label><input type='date' class='form-control' name='to' value='{$f['to']}'></div>";
        if ($f['readall']) {
            echo "<div class='col-md-2'><label class='form-label'>Técnico</label>" . User::dropdown(['name' => 'users', 'value' => $f['users'], 'right' => 'all', 'display' => false, 'width' => '100%', 'display_emptychoice' => true, 'emptylabel' => 'Todos']) . "</div>";
        }
        echo "<div class='col-md-2'><label class='form-label'>Centro de custo</label>" . Centrocusto::dropdown(['name' => 'centro', 'value' => $f['centro'], 'display' => false, 'width' => '100%', 'emptylabel' => 'Todos']) . "</div>";
        echo "<div class='col-md-2'><label class='form-label'>Tipo de despesa</label>" . Tipodespesa::dropdown(['name' => 'tipo', 'value' => $f['tipo'], 'display' => false, 'width' => '100%', 'emptylabel' => 'Todos']) . "</div>";
        echo "<div class='col-md-2'><label class='form-label'>Status</label>" . Dropdown::showFromArray('status', [0 => 'Todos'] + array_map(fn ($x) => $x[0], Reembolso::STATUSES), ['value' => $f['status'], 'display' => false, 'width' => '100%']) . "</div>";
        echo "<div class='col-12 d-flex gap-2'><button class='btn btn-primary'><i class='ti ti-filter me-1'></i>Filtrar</button>";
        $presets = ['Este mês' => [date('Y-m-01'), date('Y-m-d')], 'Mês passado' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            'Últimos 6 meses' => [date('Y-m-01', strtotime('-5 months')), date('Y-m-d')], 'Este ano' => [date('Y-01-01'), date('Y-m-d')]];
        foreach ($presets as $label => [$a, $b]) {
            echo "<a class='btn btn-ghost-secondary btn-sm align-self-center' href='?" . $e(http_build_query(['from' => $a, 'to' => $b] + array_intersect_key($f, array_flip(['users', 'centro', 'tipo', 'status'])))) . "'>$label</a>";
        }
        echo "</div></div>";
        Html::closeForm();

        // indicadores
        $kpi = fn ($label, $value, $sub, $color, $drawer) => "<div class='col-6 col-md-4 col-xl'><div class='rb-kpi oak-kpi-click' role='button' tabindex='0' data-drawer='$drawer' style='--c:$color'><div class='rb-kpi-label'>$label</div><div class='rb-kpi-value'>$value</div><div class='rb-kpi-sub'>$sub</div></div></div>";
        echo "<div class='row g-2 mb-3'>";
        echo $kpi('Total lançado', Money::format($k['total']), $k['expenses'] . ' despesa(s) em ' . $k['requests'] . ' solicitação(ões)', '#22d3ee', 'rb-total');
        echo $kpi('Aguardando aprovação', Money::format($k['pending']), 'enviados ao aprovador', '#f59e0b', 'rb-pendente');
        echo $kpi('Aprovado a pagar', Money::format($k['approved']), 'aprovado, sem pagamento', '#3b82f6', 'rb-apagar');
        echo $kpi('Pago', Money::format($k['paid']), 'pagamento registrado', '#22c55e', 'rb-pago');
        echo $kpi('KM rodados', Money::km($k['km']), 'em despesas por quilometragem', '#a855f7', 'rb-km');
        echo $kpi('Média por solicitação', Money::format($k['average']), $k['techs'] . ' técnico(s)' . ($k['over'] ? " · <span class='text-warning'>{$k['over']} acima do limite</span>" : ''), '#94a3b8', 'rb-media');
        echo "</div>";
        if ($k['draft'] > 0 || $k['refused'] > 0) {
            echo "<div class='small text-secondary mb-3'>Fora dos totais acima: " . ($k['draft'] > 0 ? Money::format($k['draft']) . ' em rascunho' : '')
                . ($k['draft'] > 0 && $k['refused'] > 0 ? ' · ' : '') . ($k['refused'] > 0 ? Money::format($k['refused']) . ' recusado(s)' : '') . ". O rascunho entra no total lançado; o recusado não.</div>";
        }

        // gráficos
        $months = Report::months($f['from'], $f['to']);
        $series = [];
        foreach ([Reembolso::DRAFT, Reembolso::SUBMITTED, Reembolso::APPROVED, Reembolso::PAID] as $st) {
            $series[] = ['name' => Reembolso::STATUSES[$st][0], 'color' => Reembolso::STATUSES[$st][1], 'data' => array_map(fn ($m) => round($s['month'][$m][$st] ?? 0, 2), $months)];
        }
        $charts = [
            'months' => array_map(fn ($m) => ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][(int) substr($m, 5, 2) - 1] . '/' . substr($m, 2, 2), $months),
            'series' => $series,
            'keys'   => $months,
            'type'   => array_map(fn ($n, $v) => ['name' => $n, 'value' => round($v, 2)], array_keys($s['type']), $s['type']),
            'user'   => array_map(fn ($n, $v) => ['name' => $n, 'value' => round($v, 2)], array_keys($s['user']), $s['user']),
            'centro' => array_map(fn ($n, $v) => ['name' => $n, 'value' => round($v, 2)], array_keys($s['centro']), $s['centro']),
            // despesas do filtro (sem as recusadas), para o detalhe de cada barra no painel lateral
            'exp'    => array_values(array_map(fn ($r) => [
                'id' => (int) $r['reembolso'], 'code' => Reembolso::code((int) $r['reembolso']), 'url' => Reembolso::getFormURLWithID((int) $r['reembolso']),
                'tec' => $r['tecnico'], 'tipo' => $r['tipo'] ?: '—', 'centro' => $r['centro_label'] ?: 'Sem centro de custo', 'date' => substr((string) $r['date'], 0, 10),
                'desc' => (string) $r['description'], 'value' => round($r['amount'], 2), 'st' => $r['status'], 'over' => $r['over'],
            ], array_filter($rows, fn ($r) => $r['status'] !== Reembolso::REFUSED))),
            'statuses' => array_map(fn ($x) => ['name' => $x[0], 'color' => $x[1]], Reembolso::STATUSES),
        ];
        $card = fn ($id, $title, $sub, $cls = 'col-xl-6') => "<div class='col-12 $cls'><div class='card h-100'><div class='card-header'><h3 class='card-title'>$title</h3>"
            . "<div class='card-actions small text-secondary'>$sub</div></div><div class='card-body'><div class='rb-chart' id='$id'></div></div></div></div>";
        echo "<div class='row g-3 mb-3'>";
        echo $card('rb-c-month', '<i class="ti ti-calendar-stats me-2"></i>Evolução mensal por status', 'clique no mês para ver as despesas', 'col-xl-8');
        echo $card('rb-c-type', '<i class="ti ti-receipt me-2"></i>Por tipo de despesa', 'clique na barra para ver as despesas', 'col-xl-4');
        echo $card('rb-c-user', '<i class="ti ti-user me-2"></i>Por técnico', 'clique na barra para ver as despesas');
        echo $card('rb-c-centro', '<i class="ti ti-building-bank me-2"></i>Por centro de custo', 'clique na barra para ver as despesas');
        echo "</div>";
        echo "<div class='row g-3 mb-3'><div class='col-12'>" . self::pipeline($f) . "</div></div>";
        $ads = self::advances($f);
        echo self::hoursCard($rows);
        echo self::advanceCard($ads);
        echo "<div class='row g-3 mb-3'><div class='col-12'>" . self::advancePipeline($ads) . "</div></div>";
        echo "<script type='application/json' id='rb-dash'>" . json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
        echo self::drawers($rows) . self::extraDrawers($rows, $ads);
        echo Html::script(Menu::url('/js/drawer.js'), ['version' => Menu::fileVersion('js/drawer.js')], false);
        echo Html::script(Menu::url('/js/oakbars.js'), ['version' => Menu::fileVersion('js/oakbars.js')], false);
        echo Html::script(Menu::url('/js/dashboard.js'), ['version' => Menu::fileVersion('js/dashboard.js')], false);

        // detalhamento
        $shown = array_slice(array_reverse($rows), 0, 300);
        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-table me-2'></i>Despesas do período</h3>"
            . "<div class='card-actions small text-secondary'>" . count($rows) . " despesa(s)" . (count($rows) > 300 ? ' · mostrando as 300 mais recentes (o XLSX traz todas)' : '') . "</div></div>";
        if (!$rows) {
            echo "<div class='card-body text-secondary'>Nenhuma despesa no período e filtros escolhidos.</div></div></div>";
            return;
        }
        echo "<div class='table-responsive'><table class='table table-vcenter card-table table-hover rb-table'><thead><tr><th>Data</th><th>Solicitação</th><th>Técnico</th><th>Centro de custo</th><th>Tipo</th><th>Detalhe</th><th>Status</th><th class='text-end'>Valor</th></tr></thead><tbody>";
        foreach ($shown as $r) {
            $detail = $e($r['description']);
            if ($r['is_mileage']) {
                $detail .= ($detail ? ' · ' : '') . Money::km($r['distance']) . ($r['origin'] ? ' · ' . $e($r['origin'] . ' → ' . $r['destination']) : '');
            }
            echo "<tr><td class='text-nowrap'>" . Html::convDate($r['date']) . "</td>"
                . "<td><a href='" . $e(Reembolso::getFormURLWithID((int) $r['reembolso'])) . "'>" . $e($r['code']) . "</a></td>"
                . "<td>" . $e($r['tecnico']) . "</td><td>" . $e($r['centro_label']) . "</td><td>" . $e($r['tipo']) . "</td>"
                . "<td class='small'>$detail</td><td>" . Reembolso::statusBadge($r['status']) . "</td>"
                . "<td class='text-end text-nowrap fw-bold'>" . Money::format($r['amount']) . ($r['over'] ? " <i class='ti ti-alert-triangle text-warning' title='Acima do limite do tipo'></i>" : '') . "</td></tr>";
        }
        echo "</tbody></table></div></div></div>";
    }

    // ------------------------------------------------------------------ resumos dos cartões (painel lateral)

    /** Um <template> por cartão, com as mesmas despesas (e filtros) dos indicadores. */
    private static function drawers(array $rows): string
    {
        global $DB;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $date = fn ($d) => $d ? date('d/m/Y', strtotime($d)) : '—';
        $days = fn ($d) => $d ? max(0, (int) floor((time() - strtotime($d)) / 86400)) : 0;
        $item = fn ($cls, $row, $sub, $extra = '') => "<div class='oak-dr-item $cls'><div class='oak-dr-row'>$row</div>" . ($sub !== '' ? "<div class='oak-dr-sub'>$sub</div>" : '') . "$extra</div>";
        $empty = fn ($t) => "<div class='oak-dr-empty'>$t</div>";
        $stats = fn (array $s) => "<div class='oak-dr-stats'>" . implode('', array_map(fn ($k, $v) => "<div><small>$k</small><b>$v</b></div>", array_keys($s), $s)) . "</div>";

        // solicitações (cabeçalho) das despesas do filtro
        $req = [];
        foreach ($rows as $r) {
            if ($r['status'] === Reembolso::REFUSED) {
                continue;
            }
            $id = (int) $r['reembolso'];
            $req[$id] ??= ['id' => $id, 'tecnico' => $r['tecnico'], 'status' => $r['status'], 'amount' => 0.0, 'n' => 0, 'centro' => $r['centro_label'], 'over' => 0];
            $req[$id]['amount'] += $r['amount'];
            $req[$id]['n']++;
            $req[$id]['over'] += $r['over'] ? 1 : 0;
        }
        if ($req) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'date_submit', 'date_approval', 'date_payment', 'payment_reference', 'users_id_approver'], 'FROM' => Reembolso::getTable(), 'WHERE' => ['id' => array_keys($req)]]) as $h) {
                $req[(int) $h['id']] += $h;
            }
        }
        $link = fn ($r) => "<a href='" . $e(Reembolso::getFormURLWithID($r['id'])) . "'><b>" . $e(Reembolso::code($r['id'])) . "</b></a><span class='oak-dr-name'>" . $e($r['tecnico']) . "</span><span class='oak-dr-val'>" . Money::format($r['amount']) . "</span>";
        $byStatus = fn ($st) => array_values(array_filter($req, fn ($r) => $r['status'] === $st));
        $out = [];

        // total lançado
        $sum = fn ($st) => array_sum(array_column($byStatus($st), 'amount'));
        $h = "<div class='oak-dr-stats' style='grid-template-columns:repeat(2,1fr)'>" . implode('', array_map(fn ($l, $st) => "<div style='--sc:" . Reembolso::STATUSES[$st][1] . "'><small>$l</small><b>" . Money::format($sum($st)) . "</b></div>",
            ['Rascunho', 'Aguardando aprovação', 'Aprovado a pagar', 'Pago'], [Reembolso::DRAFT, Reembolso::SUBMITTED, Reembolso::APPROVED, Reembolso::PAID])) . "</div>"
            . "<div class='oak-dr-title'>Maiores solicitações do período</div><div class='oak-dr-list'>";
        $top = array_values($req);
        usort($top, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        foreach (array_slice($top, 0, 15) as $r) {
            [$label, $color] = Reembolso::STATUSES[$r['status']];
            $h .= "<div class='oak-dr-item' style='--oc:$color'><div class='oak-dr-row'>" . $link($r) . "</div><div class='oak-dr-sub'>" . $e($label) . ' · ' . $r['n'] . ' despesa(s) · ' . $e($r['centro'] ?: 'sem centro de custo') . "</div></div>";
        }
        $out['rb-total'] = ['Total lançado', 'ti-receipt-2', '#22d3ee', ($req ? $h . "</div>" : $empty('Nenhuma despesa no período.'))];

        // aguardando aprovação
        $list = $byStatus(Reembolso::SUBMITTED);
        usort($list, fn ($a, $b) => strcmp((string) $a['date_submit'], (string) $b['date_submit']));
        $h = '';
        foreach ($list as $r) {
            $d = $days($r['date_submit']);
            $h .= $item($d > 5 ? 'bad' : 'warn', $link($r), 'Enviada em ' . $date($r['date_submit']) . " · esperando há <b>$d dia(s)</b> · " . $r['n'] . ' despesa(s)' . ($r['over'] ? " · <span class='text-warning'>{$r['over']} acima do limite</span>" : ''));
        }
        $out['rb-pendente'] = ['Aguardando aprovação', 'ti-hourglass', '#f59e0b', "<p class='oak-dr-lead'>Solicitações enviadas e ainda sem decisão, da mais antiga. Mais de 5 dias aparece em vermelho.</p><div class='oak-dr-list'>" . ($h ?: $empty('Nada aguardando aprovação.')) . "</div>"];

        // aprovado a pagar
        $list = $byStatus(Reembolso::APPROVED);
        usort($list, fn ($a, $b) => strcmp((string) $a['date_approval'], (string) $b['date_approval']));
        $h = '';
        foreach ($list as $r) {
            $d = $days($r['date_approval']);
            $h .= $item($d > 10 ? 'bad' : '', $link($r), 'Aprovada em ' . $date($r['date_approval']) . ' por ' . $e(getUserName((int) ($r['users_id_approver'] ?? 0))) . " · a pagar há <b>$d dia(s)</b>");
        }
        $out['rb-apagar'] = ['Aprovado a pagar', 'ti-cash', '#3b82f6', "<p class='oak-dr-lead'>Aprovados sem pagamento registrado. Mais de 10 dias aparece em vermelho.</p><div class='oak-dr-list'>" . ($h ?: $empty('Nada a pagar.')) . "</div>"];

        // pago
        $list = $byStatus(Reembolso::PAID);
        usort($list, fn ($a, $b) => strcmp((string) $b['date_payment'], (string) $a['date_payment']));
        $h = '';
        foreach ($list as $r) {
            $h .= $item('good', $link($r), 'Pago em ' . $date($r['date_payment']) . ($r['payment_reference'] ? ' · ' . $e($r['payment_reference']) : ''));
        }
        $out['rb-pago'] = ['Pago', 'ti-circle-check', '#22c55e', "<p class='oak-dr-lead'>Pagamentos registrados no período, do mais recente.</p><div class='oak-dr-list'>" . ($h ?: $empty('Nenhum pagamento no período.')) . "</div>"];

        // KM rodados: por técnico e os maiores trajetos
        $km = [];
        $trips = [];
        foreach ($rows as $r) {
            if (!$r['is_mileage'] || $r['status'] === Reembolso::REFUSED) {
                continue;
            }
            $km[$r['tecnico']]['km'] = ($km[$r['tecnico']]['km'] ?? 0) + $r['distance'];
            $km[$r['tecnico']]['amount'] = ($km[$r['tecnico']]['amount'] ?? 0) + $r['amount'];
            $trips[] = $r;
        }
        uasort($km, fn ($a, $b) => $b['km'] <=> $a['km']);
        usort($trips, fn ($a, $b) => $b['distance'] <=> $a['distance']);
        $max = max(1, ...array_column($km, 'km') ?: [1]);
        $h = "<div class='oak-dr-title'>Por técnico</div><div class='oak-dr-list'>";
        foreach ($km as $name => $x) {
            $h .= $item('', "<span class='oak-dr-name'><b>" . $e($name) . "</b></span><span class='oak-dr-val'>" . Money::km($x['km']) . "</span>", Money::format($x['amount']) . ' em quilometragem', "<div class='oak-dr-bar'><i style='width:" . round(100 * $x['km'] / $max) . "%;--oc:#a855f7'></i></div>");
        }
        $h .= "</div><div class='oak-dr-title'>Maiores trajetos</div><div class='oak-dr-list'>";
        foreach (array_slice($trips, 0, 10) as $t) {
            $h .= $item('', "<span class='oak-dr-name'>" . $e($t['origin'] || $t['destination'] ? ($t['origin'] ?: '?') . ' → ' . ($t['destination'] ?: '?') : 'Pelo odômetro' . ($t['km_end'] ? ': ' . number_format((float) $t['km_start'], 0, ',', '.') . ' → ' . number_format((float) $t['km_end'], 0, ',', '.') . ' km' : '')) . "</span><span class='oak-dr-val'>" . Money::km($t['distance']) . "</span>",
                $e($t['tecnico']) . ' · ' . $date($t['date']) . ($t['round_trip'] ? ' · ida e volta' : '') . ' · ' . Money::format($t['amount']));
        }
        $out['rb-km'] = ['KM rodados', 'ti-road', '#a855f7', ($km ? $h . "</div>" : $empty('Nenhuma despesa por quilometragem no período.'))];

        // média por solicitação: por técnico e despesas acima do limite
        $tec = [];
        foreach ($req as $r) {
            $tec[$r['tecnico']]['n'] = ($tec[$r['tecnico']]['n'] ?? 0) + 1;
            $tec[$r['tecnico']]['amount'] = ($tec[$r['tecnico']]['amount'] ?? 0) + $r['amount'];
        }
        uasort($tec, fn ($a, $b) => ($b['amount'] / $b['n']) <=> ($a['amount'] / $a['n']));
        $h = "<div class='oak-dr-title'>Média por técnico</div><table class='table table-sm oak-dr-table'><thead><tr><th>Técnico</th><th class='text-end'>Solicitações</th><th class='text-end'>Total</th><th class='text-end'>Média</th></tr></thead><tbody>";
        foreach ($tec as $name => $x) {
            $h .= "<tr><td>" . $e($name) . "</td><td class='text-end'>{$x['n']}</td><td class='text-end'>" . Money::format($x['amount']) . "</td><td class='text-end fw-bold'>" . Money::format($x['amount'] / $x['n']) . "</td></tr>";
        }
        $h .= "</tbody></table>";
        $over = array_filter($rows, fn ($r) => $r['over'] && $r['status'] !== Reembolso::REFUSED);
        if ($over) {
            $h .= "<div class='oak-dr-title'>Despesas acima do limite do tipo</div><div class='oak-dr-list'>";
            foreach ($over as $r) {
                $h .= $item('warn', "<span class='oak-dr-name'><b>" . $e($r['tipo']) . "</b> · " . $e($r['tecnico']) . "</span><span class='oak-dr-val'>" . Money::format($r['amount']) . "</span>",
                    'Limite ' . Money::format((float) $r['max_amount']) . ' · ' . $date($r['date']) . " · <a href='" . $e(Reembolso::getFormURLWithID((int) $r['reembolso'])) . "'>" . $e(Reembolso::code((int) $r['reembolso'])) . "</a>");
            }
            $h .= "</div>";
        }
        $out['rb-media'] = ['Média por solicitação', 'ti-calculator', '#94a3b8', ($tec ? $h : $empty('Nenhuma solicitação no período.'))];

        $html = '';
        foreach ($out as $key => [$title, $icon, $color, $body]) {
            $html .= "<template id='dr-$key' data-title='" . $e($title) . "' data-icon='$icon' data-color='$color'>$body</template>";
        }
        return $html;
    }

    // ------------------------------------------------------------------ horas e adicional noturno

    /** Atendimentos (solicitações) das despesas do filtro, sem os recusados, com as horas. */
    private static function attendances(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['status'] === Reembolso::REFUSED) {
                continue;
            }
            $id = (int) $r['reembolso'];
            $out[$id] ??= ['id' => $id, 'tecnico' => $r['tecnico'], 'tickets_id' => (int) $r['tickets_id'], 'when' => Pdf::when($r),
                'biz' => (int) $r['minutes_business'], 'out' => (int) $r['minutes_outside'], 'night' => (int) $r['minutes_night'],
                'has' => (bool) ($r['time_begin'] && $r['time_end']), 'nexp' => 0, 'nval' => 0.0];
            if ((int) $r['is_night']) {
                $out[$id]['nexp']++;
                $out[$id]['nval'] += $r['amount'];
            }
        }
        return $out;
    }

    /** Cartão "Horas dos atendimentos e adicional noturno": totais e uma barra empilhada por técnico. */
    private static function hoursCard(array $rows): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $att = self::attendances($rows);
        $sum = fn ($k) => array_sum(array_column($att, $k));
        $c = Money::config();
        $tec = [];
        foreach ($att as $a) {
            $t = &$tec[$a['tecnico']];
            $t['biz'] = ($t['biz'] ?? 0) + $a['biz'];
            $t['out'] = ($t['out'] ?? 0) + $a['out'];
            $t['night'] = ($t['night'] ?? 0) + $a['night'];
            $t['nexp'] = ($t['nexp'] ?? 0) + $a['nexp'];
            $t['nval'] = ($t['nval'] ?? 0) + $a['nval'];
            $t['n'] = ($t['n'] ?? 0) + 1;
            unset($t);
        }
        uasort($tec, fn ($a, $b) => ($b['night'] <=> $a['night']) ?: (($b['out'] + $b['biz']) <=> ($a['out'] + $a['biz'])));
        $max = max(1, ...array_values(array_map(fn ($t) => $t['biz'] + $t['out'], $tec)) ?: [1]);
        $kpi = fn ($label, $value, $sub, $color, $drawer = '') => "<div class='col-6 col-xl-3'><div class='rb-kpi" . ($drawer ? " oak-kpi-click' role='button' tabindex='0' data-drawer='$drawer'" : "'") . " style='--c:$color'><div class='rb-kpi-label'>$label</div><div class='rb-kpi-value'>$value</div><div class='rb-kpi-sub'>$sub</div></div></div>";
        $noTime = count(array_filter($att, fn ($a) => !$a['has']));
        $h = "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-moon-stars me-2'></i>Horas dos atendimentos e adicional noturno</h3>"
            . "<div class='card-actions small text-secondary'>comercial: seg. a sex. " . $e($c['business_start'] . '–' . $c['business_end']) . ' · noturno: ' . $e($c['night_start'] . '–' . $c['night_end']) . "</div></div><div class='card-body'>"
            . "<div class='row g-2 mb-3'>"
            . $kpi('Horas comerciais', Money::minutes($sum('biz')), count($att) . ' atendimento(s)', '#0ea5e9')
            . $kpi('Fora do horário comercial', Money::minutes($sum('out')), 'inclui fins de semana', '#f97316')
            . $kpi('Horas de adicional noturno', Money::minutes($sum('night')), count(array_filter($att, fn ($a) => $a['night'] > 0)) . ' atendimento(s) com noturno', '#8b5cf6', 'rb-noturno')
            . $kpi('Despesas noturnas', $sum('nexp') . ' · ' . Money::format($sum('nval')), 'lançadas no horário noturno', '#6d28d9', 'rb-noturno')
            . '</div>';
        if (!$tec) {
            return $h . "<div class='text-secondary'>Nenhum atendimento no período.</div></div></div>";
        }
        $h .= "<div class='rb-hbar-legend'><span class='biz'>Comercial</span><span class='out'>Fora do horário</span><span class='night'>Adicional noturno (dentro das horas fora do horário)</span></div><div class='rb-hbars'>";
        foreach ($tec as $name => $t) {
            $w = fn ($m) => round(100 * $m / $max, 2);
            $h .= "<div class='rb-hbar'><div class='rb-hbar-name'>" . $e($name) . "<small>" . $t['n'] . ' atendimento(s)' . ($t['nexp'] ? ' · ' . $t['nexp'] . ' despesa(s) noturna(s)' : '') . "</small></div>"
                . "<div class='rb-hbar-track'><i class='biz' style='width:" . $w($t['biz']) . "%'></i><i class='out' style='width:" . $w($t['out']) . "%'></i>"
                . ($t['night'] ? "<b class='night' style='left:" . $w($t['biz']) . "%;width:" . $w($t['night']) . "%'></b>" : '') . "</div>"
                . "<div class='rb-hbar-val'>" . Money::minutes($t['biz'] + $t['out']) . ($t['night'] ? "<span class='night'><i class='ti ti-moon-stars'></i> " . Money::minutes($t['night']) . '</span>' : '') . '</div></div>';
        }
        return $h . '</div>' . ($noTime ? "<div class='small text-secondary mt-2'>$noTime atendimento(s) sem horário de início e fim ficam fora das horas.</div>" : '') . '</div></div>';
    }

    // ------------------------------------------------------------------ adiantamentos

    /** Adiantamentos do filtro (período pelo início do atendimento; em aberto aparecem sempre). */
    private static function advances(array $f): array
    {
        global $DB;
        $t = Adiantamento::getTable();
        $where = ['is_deleted' => 0, ['NOT' => ['status' => Adiantamento::CANCELED]], ['OR' => [
            ['AND' => [['date_begin' => ['>=', $f['from']]], ['date_begin' => ['<=', $f['to']]]]],
            ['status' => [Adiantamento::DRAFT, Adiantamento::SIGN, Adiantamento::MANAGER, Adiantamento::CENTER, Adiantamento::OTC, Adiantamento::FINANCE, Adiantamento::RETURNED, Adiantamento::PAID, Adiantamento::ACCOUNT]],
        ]]] + (new \DbUtils())->getEntitiesRestrictCriteria($t, '', '', true);
        if (!$f['readall']) {
            $me = (int) \Session::getLoginUserID();
            $where[] = ['OR' => ['users_id' => $me, 'users_id_recipient' => $me]];
        } elseif ($f['users']) {
            $where['users_id'] = $f['users'];
        }
        if ($f['centro']) {
            $where['plugin_reembolso_centrocustos_id'] = $f['centro'];
        }
        $out = [];
        foreach ($DB->request(['FROM' => $t, 'WHERE' => $where, 'ORDER' => 'id']) as $r) {
            $a = new Adiantamento();
            $a->getFromDB((int) $r['id']);
            $out[] = $a;
        }
        return $out;
    }

    private static function advanceCard(array $ads): string
    {
        $by = fn (array $st) => array_filter($ads, fn ($a) => in_array((int) $a->fields['status'], $st, true));
        $total = fn (array $list, string $k = 'total') => array_sum(array_map(fn ($a) => (float) $a->fields[$k], $list));
        $approving = $by([Adiantamento::SIGN, Adiantamento::MANAGER, Adiantamento::CENTER, Adiantamento::OTC, Adiantamento::FINANCE, Adiantamento::RETURNED]);
        $paid = $by([Adiantamento::PAID, Adiantamento::ACCOUNT, Adiantamento::CLOSED]);
        $open = $by([Adiantamento::PAID]);
        $late = array_filter($open, fn ($a) => $a->isLate());
        $accounted = $by([Adiantamento::ACCOUNT, Adiantamento::CLOSED]);
        $give = array_sum(array_map(fn ($a) => max(0, (float) $a->fields['balance']), $accounted));
        $get = array_sum(array_map(fn ($a) => max(0, -(float) $a->fields['balance']), $accounted));
        $use = $total($accounted) > 0 ? round(100 * $total($accounted, 'spent_total') / $total($accounted)) : 0;
        $kpi = fn ($label, $value, $sub, $color) => "<div class='col-6 col-md-4 col-xl'><div class='rb-kpi oak-kpi-click' role='button' tabindex='0' data-drawer='rb-ad' style='--c:$color'><div class='rb-kpi-label'>$label</div><div class='rb-kpi-value'>$value</div><div class='rb-kpi-sub'>$sub</div></div></div>";
        return "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-cash-banknote me-2'></i>Adiantamentos</h3>"
            . "<div class='card-actions'><a class='btn btn-sm btn-outline-primary' href='" . htmlspecialchars(Adiantamento::getSearchURL(), ENT_QUOTES) . "'>Ver todos</a></div></div><div class='card-body'><div class='row g-2'>"
            . $kpi('Em aprovação', Money::format($total($approving)), count($approving) . ' pedido(s) na esteira', '#f59e0b')
            . $kpi('Adiantado (pago)', Money::format($total($paid)), count($paid) . ' adiantamento(s)', '#14b8a6')
            . $kpi('Prestação de contas pendente', count($open) . ' · ' . Money::format($total($open)), $late ? "<span class='text-danger fw-bold'>" . count($late) . ' atrasada(s)</span>' : 'nenhuma atrasada', $late ? '#ef4444' : '#6366f1')
            . $kpi('Uso do adiantado', $accounted ? $use . '%' : '—', $accounted ? Money::format($total($accounted, 'spent_total')) . ' gastos de ' . Money::format($total($accounted)) : 'sem prestação de contas', '#0ea5e9')
            . $kpi('Saldo a acertar', Money::format($give) . ' / ' . Money::format($get), 'técnicos devolvem / empresa complementa', '#94a3b8')
            . '</div></div></div>';
    }

    /** "Onde os adiantamentos estão agora": pedidos em aberto pela etapa, com os dias parados. */
    private static function advancePipeline(array $ads): string
    {
        $groups = [
            'Rascunho' => [[Adiantamento::DRAFT, Adiantamento::RETURNED], 'Técnico', 3],
            'Assinatura do técnico' => [[Adiantamento::SIGN], 'Técnico', 2],
            'Gestores' => [[Adiantamento::MANAGER, Adiantamento::CENTER], 'Gestor da área / centro', 2],
            'OTC' => [[Adiantamento::OTC], 'OTC', 2],
            'A pagar' => [[Adiantamento::FINANCE], 'Financeiro', 2],
            'Prestação de contas' => [[Adiantamento::PAID], 'Técnico', 0],
            'Conferência' => [[Adiantamento::ACCOUNT], 'Financeiro', 3],
        ];
        $steps = [];
        foreach ($groups as $name => [$st, $area, $limit]) {
            $items = [];
            foreach ($ads as $a) {
                $f = $a->fields;
                if (!in_array((int) $f['status'], $st, true)) {
                    continue;
                }
                $isAccount = (int) $f['status'] === Adiantamento::PAID;
                $since = $isAccount ? ($f['date_end'] ?: $f['date_mod']) : $f['date_mod'];
                $items[] = ['code' => Adiantamento::code($a->getID()), 'name' => getUserName((int) $f['users_id']) . ' · ' . Money::format((float) $f['total']),
                    'sub' => ((int) $f['status'] === Adiantamento::RETURNED ? 'devolvido · ' : '') . ($isAccount ? 'prestar contas até ' . date('d/m/Y', strtotime($a->deadline())) : 'atendimento ' . strip_tags(Pdf::when($f))),
                    'url' => Adiantamento::getFormURLWithID($a->getID()), 'days' => max(0, (time() - strtotime((string) $since)) / 86400),
                    'limit' => $isAccount ? Money::config()['advance_account_days'] : $limit, 'bad' => $isAccount ? $a->isLate() : null];
            }
            $steps[] = ['name' => $name, 'area' => $area, 'items' => array_map(fn ($i) => array_filter($i, fn ($v) => $v !== null), $items)];
        }
        return Esteira::card(['title' => 'Onde os adiantamentos estão agora', 'icon' => 'ti-cash-banknote', 'steps' => $steps, 'ends' => ['Pedido', 'Aprovação', 'Prestação de contas'],
            'empty' => 'Nenhum adiantamento em aberto.']);
    }

    /** Painéis laterais do adicional noturno e dos adiantamentos. */
    private static function extraDrawers(array $rows, array $ads): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $item = fn ($cls, $row, $sub) => "<div class='oak-dr-item $cls'><div class='oak-dr-row'>$row</div>" . ($sub !== '' ? "<div class='oak-dr-sub'>$sub</div>" : '') . '</div>';
        $empty = fn ($t) => "<div class='oak-dr-empty'>$t</div>";

        // adicional noturno: atendimentos com horas noturnas e as despesas lançadas no horário
        $att = array_filter(self::attendances($rows), fn ($a) => $a['night'] > 0 || $a['nexp'] > 0);
        usort($att, fn ($a, $b) => $b['night'] <=> $a['night']);
        $h = "<p class='oak-dr-lead'>Atendimentos com horas na faixa noturna e despesas lançadas nesse horário (destacadas no lote e no PDF).</p><div class='oak-dr-list'>";
        foreach ($att as $a) {
            $h .= $item('', "<a href='" . $e(Reembolso::getFormURLWithID($a['id'])) . "'><b>" . Reembolso::code($a['id']) . "</b></a><span class='oak-dr-name'>" . $e($a['tecnico']) . "</span><span class='oak-dr-val'>" . Money::minutes($a['night']) . '</span>',
                ($a['tickets_id'] ? 'chamado nº ' . $a['tickets_id'] . ' · ' : '') . $e(strip_tags($a['when'])) . ($a['nexp'] ? ' · ' . $a['nexp'] . ' despesa(s) noturna(s), ' . Money::format($a['nval']) : ''));
        }
        $html = "<template id='dr-rb-noturno' data-title='Adicional noturno' data-icon='ti-moon-stars' data-color='#8b5cf6'>" . ($att ? $h . '</div>' : $empty('Nenhum atendimento com adicional noturno no período.')) . '</template>';

        // adiantamentos por etapa
        $h = "<p class='oak-dr-lead'>Adiantamentos do período e os que estão em aberto, pela etapa.</p><div class='oak-dr-list'>";
        usort($ads, fn ($a, $b) => (int) $a->fields['status'] <=> (int) $b->fields['status']);
        foreach ($ads as $a) {
            $f = $a->fields;
            [$label, $color] = Adiantamento::STATUSES[(int) $f['status']];
            $sub = $e($label) . ((int) $f['tickets_id'] ? ' · chamado nº ' . (int) $f['tickets_id'] : '');
            if (in_array((int) $f['status'], [Adiantamento::ACCOUNT, Adiantamento::CLOSED], true)) {
                $sub .= ' · gasto ' . Money::format((float) $f['spent_total']) . ' · ' . $e(Adiantamento::balanceLabel((float) $f['balance']));
            } elseif ((int) $f['status'] === Adiantamento::PAID) {
                $sub .= ' · prestar contas até ' . date('d/m/Y', strtotime($a->deadline()));
            }
            $h .= "<div class='oak-dr-item " . ($a->isLate() ? 'bad' : '') . "' style='--oc:$color'><div class='oak-dr-row'><a href='" . $e(Adiantamento::getFormURLWithID($a->getID())) . "'><b>" . Adiantamento::code($a->getID()) . "</b></a>"
                . "<span class='oak-dr-name'>" . $e(getUserName((int) $f['users_id'])) . "</span><span class='oak-dr-val'>" . Money::format((float) $f['total']) . "</span></div><div class='oak-dr-sub'>$sub</div></div>";
        }
        return $html . "<template id='dr-rb-ad' data-title='Adiantamentos' data-icon='ti-cash-banknote' data-color='#14b8a6'>" . ($ads ? $h . '</div>' : $empty('Nenhum adiantamento no período.')) . '</template>';
    }

    /** Prazo de referência de cada etapa, em dias: para enviar, para aprovar e para pagar. */
    public const PIPE_DAYS = [Reembolso::DRAFT => 5, Reembolso::SUBMITTED => 3, Reembolso::APPROVED => 5];

    /** "Onde os reembolsos estão agora": as solicitações em aberto, pela etapa, com os dias parados nela. */
    private static function pipeline(array $f): string
    {
        global $DB;
        $where = ['is_deleted' => 0, 'status' => array_keys(self::PIPE_DAYS)] + (new \DbUtils())->getEntitiesRestrictCriteria(Reembolso::getTable(), '', '', true);
        if (!$f['readall']) {
            $me = (int) \Session::getLoginUserID();
            $where[] = ['OR' => ['users_id' => $me, 'users_id_recipient' => $me]];
        }
        $areas = [Reembolso::DRAFT => 'Técnico', Reembolso::SUBMITTED => 'Aprovador', Reembolso::APPROVED => 'Financeiro'];
        $steps = [];
        foreach (self::PIPE_DAYS as $st => $days) {
            $steps[$st] = ['name' => Reembolso::STATUSES[$st][0], 'area' => $areas[$st], 'items' => []];
        }
        foreach ($DB->request(['FROM' => Reembolso::getTable(), 'WHERE' => $where, 'ORDER' => 'id']) as $r) {
            $st = (int) $r['status'];
            $since = [Reembolso::DRAFT => $r['date_creation'], Reembolso::SUBMITTED => $r['date_submit'], Reembolso::APPROVED => $r['date_approval']][$st] ?: $r['date_creation'];
            $steps[$st]['items'][] = [
                'code' => Reembolso::code((int) $r['id']), 'name' => getUserName((int) $r['users_id']) . ' · ' . Money::format((float) $r['total']),
                'sub' => 'atendimento de ' . date('d/m', strtotime((string) $r['date_begin'])) . ' a ' . date('d/m/Y', strtotime((string) $r['date_end'])),
                'url' => Reembolso::getFormURLWithID((int) $r['id']), 'days' => max(0, (time() - strtotime((string) $since)) / 86400), 'limit' => self::PIPE_DAYS[$st],
            ];
        }
        return Esteira::card(['title' => 'Onde os reembolsos estão agora', 'icon' => 'ti-route', 'steps' => $steps, 'ends' => ['Lançamento', 'Aprovação', 'Pagamento'],
            'empty' => 'Nenhuma solicitação em aberto.']);
    }
}
