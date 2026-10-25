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
        $kpi = fn ($label, $value, $sub, $color = '') => "<div class='col-6 col-md-4 col-xl'><div class='rb-kpi' " . ($color ? "style='--c:$color'" : '') . "><div class='rb-kpi-label'>$label</div><div class='rb-kpi-value'>$value</div><div class='rb-kpi-sub'>$sub</div></div></div>";
        echo "<div class='row g-2 mb-3'>";
        echo $kpi('Total lançado', Money::format($k['total']), $k['expenses'] . ' despesa(s) em ' . $k['requests'] . ' solicitação(ões)', '#22d3ee');
        echo $kpi('Aguardando aprovação', Money::format($k['pending']), 'enviados ao aprovador', '#f59e0b');
        echo $kpi('Aprovado a pagar', Money::format($k['approved']), 'aprovado, sem pagamento', '#3b82f6');
        echo $kpi('Pago', Money::format($k['paid']), 'pagamento registrado', '#22c55e');
        echo $kpi('KM rodados', Money::km($k['km']), 'em despesas por quilometragem', '#a855f7');
        echo $kpi('Média por solicitação', Money::format($k['average']), $k['techs'] . ' técnico(s)' . ($k['over'] ? " · <span class='text-warning'>{$k['over']} acima do limite</span>" : ''), '#94a3b8');
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
            'type'   => array_map(fn ($n, $v) => ['name' => $n, 'value' => round($v, 2)], array_keys($s['type']), $s['type']),
            'user'   => array_map(fn ($n, $v) => ['name' => $n, 'value' => round($v, 2)], array_keys($s['user']), $s['user']),
            'centro' => array_map(fn ($n, $v) => ['name' => $n, 'value' => round($v, 2)], array_keys($s['centro']), $s['centro']),
        ];
        $card = fn ($id, $title, $cls = 'col-xl-6') => "<div class='col-12 $cls'><div class='card h-100'><div class='card-header'><h3 class='card-title'>$title</h3></div><div class='card-body'><div class='rb-chart' id='$id'></div></div></div></div>";
        echo "<div class='row g-3 mb-3'>";
        echo $card('rb-c-month', '<i class="ti ti-calendar-stats me-2"></i>Evolução mensal por status', 'col-xl-8');
        echo $card('rb-c-type', '<i class="ti ti-chart-donut me-2"></i>Por tipo de despesa', 'col-xl-4');
        echo $card('rb-c-user', '<i class="ti ti-user me-2"></i>Por técnico');
        echo $card('rb-c-centro', '<i class="ti ti-building-bank me-2"></i>Por centro de custo');
        echo "</div>";
        echo "<script type='application/json' id='rb-dash'>" . json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
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
}
