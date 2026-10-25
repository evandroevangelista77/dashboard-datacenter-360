<?php

namespace GlpiPlugin\Rh;

use CommonGLPI;
use Session;

/**
 * Dashboard de RH: vagas, funil, tempo de contratação, origem dos candidatos e férias.
 */
class Dashboard extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Dashboard RH';
    }

    public static function canView(): bool
    {
        return Vaga::canView() || Feria::canView();
    }

    /**
     * Funil de conversão: quantos candidatos CHEGARAM a cada etapa (etapa atual
     * ou posterior, inclusive quem saiu ali), quantos estão nela agora e quantos
     * saíram (reprovados/desistentes), e a % que avançou de uma etapa para a outra.
     * Escopo: vagas abertas (padrão), todas as vagas abertas no período ou uma vaga.
     */
    private static function funnel(array $vw, string $scope, string $from, int $months): string
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $keys = array_keys(Candidatura::STAGES);
        $where = $vw;
        $title = 'vagas abertas';
        if ($scope === 'all') {
            $where[] = ['v.date_open' => ['>=', $from]];
            $title = "vagas abertas nos últimos $months meses";
        } elseif (ctype_digit($scope)) {
            $where['v.id'] = (int) $scope;
        } else {
            $scope = 'open';
            $where['v.status'] = Vaga::OPEN;
        }
        // vagas para o filtro (abertas ou abertas no período)
        $options = ['open' => 'Todas as vagas abertas', 'all' => "Vagas abertas nos últimos $months meses"];
        foreach ($DB->request(['SELECT' => ['v.id', 'v.name', 'v.status'], 'FROM' => Vaga::getTable() . ' AS v',
            'WHERE' => array_merge($vw, [['OR' => ['v.status' => Vaga::OPEN, 'v.date_open' => ['>=', $from]]]]), 'ORDER' => ['v.status', 'v.name']]) as $v) {
            $options[(string) $v['id']] = 'VG-' . $v['id'] . ' · ' . $v['name'] . ((int) $v['status'] === Vaga::OPEN ? '' : ' (' . mb_strtolower(Vaga::STATUSES[(int) $v['status']][0]) . ')');
            if ($scope === (string) $v['id']) {
                $title = $v['name'];
            }
        }

        $reached = $active = $out = array_fill_keys($keys, 0);
        foreach ($DB->request([
            'SELECT' => ['c.stage', 'c.status'], 'FROM' => Candidatura::getTable() . ' AS c',
            'INNER JOIN' => [Vaga::getTable() . ' AS v' => ['ON' => ['v' => 'id', 'c' => 'plugin_rh_vagas_id']]],
            'WHERE' => $where,
        ]) as $r) {
            $idx = array_search($r['stage'], $keys, true);
            if ($idx === false) {
                continue;
            }
            for ($k = 0; $k <= $idx; $k++) {
                $reached[$keys[$k]]++;
            }
            if (in_array($r['status'], ['rejected', 'withdrew'], true)) {
                $out[$r['stage']]++;
            } else {
                $active[$r['stage']]++;
            }
        }
        $total = $reached[$keys[0]];
        $hired = $reached['contratado'];
        // maior perda: a etapa em que mais gente SAIU (reprovado/desistente), proporcionalmente
        $worst = null;
        foreach ($keys as $k) {
            if ($out[$k] && $reached[$k]) {
                $rate = $out[$k] / $reached[$k];
                if ($worst === null || $rate > $worst[1]) {
                    $worst = [$k, $rate];
                }
            }
        }
        $people = fn (int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);

        $select = "<form method='get' class='m-0'><input type='hidden' name='months' value='$months'><select class='form-select form-select-sm' name='funil' onchange='this.form.submit()' style='max-width:320px'>";
        foreach ($options as $val => $label) {
            $select .= "<option value='" . $e($val) . "'" . ((string) $val === $scope ? ' selected' : '') . ">" . $e($label) . "</option>";
        }
        $select .= "</select>" . \Html::closeForm(false);

        $html = "<div class='card h-100 rh-funnel-card'><div class='card-header flex-wrap gap-2'><h3 class='card-title'><i class='ti ti-filter me-2'></i>Funil de seleção"
            . "<span class='text-secondary fw-normal ms-2 small'>· " . $e($title) . "</span></h3><div class='card-actions'>$select</div></div><div class='card-body'>";
        if (!$total) {
            return $html . "<div class='rh-empty' style='height:240px'>Nenhum candidato neste recorte.</div></div></div>";
        }
        $chip = fn ($label, $value, $sub = '', $cls = '') => "<div class='rh-fn-chip $cls'><span>$label</span><b>$value</b>" . ($sub ? "<small>$sub</small>" : '') . "</div>";
        $html .= "<div class='rh-fn-chips'>"
            . $chip('Candidatos', $total, 'entraram no processo')
            . $chip('Contratados', $hired, str_replace('.', ',', (string) round(100 * $hired / $total, 1)) . '% de conversão total', 'ok')
            . $chip('Em andamento', array_sum($active) - $active['contratado'], 'ainda no funil')
            . $chip('Saíram', array_sum($out), 'reprovados ou desistentes', 'warn')
            . ($worst ? $chip('Maior perda', Candidatura::STAGES[$worst[0]][0], round(100 * $worst[1]) . '% dos que chegaram saíram ali', 'bad') : '')
            . "</div><div class='rh-funnel'>";
        foreach ($keys as $i => $k) {
            [$name, $color, $icon] = Candidatura::STAGES[$k];
            if ($i) {
                $prev = $reached[$keys[$i - 1]];
                $rate = $prev ? round(100 * $reached[$k] / $prev) : 0;
                $html .= "<div class='rh-fn-conv'><i class='ti ti-arrow-down'></i><b>$rate%</b> avançaram de " . $e(Candidatura::STAGES[$keys[$i - 1]][0]) . "</div>";
            }
            $isWorst = $worst && $worst[0] === $k;
            $w = max(14, round(100 * $reached[$k] / $total));
            $pct = round(100 * $reached[$k] / $total);
            $html .= "<div class='rh-fn-row" . ($isWorst ? ' worst' : '') . "'><div class='rh-fn-label'><i class='ti $icon' style='color:$color'></i>" . $e($name) . "</div>"
                . "<div class='rh-fn-track'><div class='rh-fn-bar' style='--w:$w%;--c:$color' title='" . $e("$name: {$reached[$k]} chegaram ($pct% do total)") . "'><b>{$reached[$k]}</b><span>$pct%</span></div></div>"
                . "<div class='rh-fn-side'>" . ($k === 'contratado'
                    ? "<span class='act'>{$reached[$k]} contratado(s)</span>"
                    : "<span class='act'>{$active[$k]} em andamento</span>" . ($out[$k] ? "<span class='out'>" . $people($out[$k], 'saiu', 'saíram') . ($isWorst ? " <em>maior perda</em>" : '') . "</span>" : '')) . "</div></div>";
        }
        return $html . "</div><div class='small text-secondary mt-2'>Cada barra mostra quantos candidatos <b>chegaram</b> à etapa (estão nela, avançaram ou saíram ali). A % entre as barras é quanto avançou de uma etapa para a outra.</div></div></div>";
    }

    /** Origem dos candidatos: rosca com o total e ranking com logotipo, participação e contratações. */
    private static function sources(array $sources): string
    {
        $e = [Menu::class, 'e'];
        $total = array_sum(array_column($sources, 'all'));
        $hired = array_sum(array_column($sources, 'hired'));
        $html = "<div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-chart-donut me-2'></i>Origem dos candidatos</h3>"
            . "<div class='card-actions small text-secondary'>$total candidato(s) · $hired contratado(s)</div></div><div class='card-body'>";
        if (!$total) {
            return $html . "<div class='rh-empty' style='height:240px'>Sem candidatos ainda.</div></div></div>";
        }
        $best = null;
        foreach ($sources as $k => $v) {
            if ($v['hired'] && ($best === null || $v['hired'] / $v['all'] > $sources[$best]['hired'] / $sources[$best]['all'])) {
                $best = $k;
            }
        }
        $html .= "<div class='rh-src'><div class='rh-src-list'>";
        foreach ($sources as $k => $v) {
            [$icon, $color] = Candidato::SOURCE_META[$k];
            $pct = round(100 * $v['all'] / $total);
            $rate = $v['all'] ? round(100 * $v['hired'] / $v['all']) : 0;
            $html .= "<div class='rh-src-row' style='--c:$color'><span class='rh-src-logo'><i class='ti $icon'></i></span>"
                . "<div class='rh-src-main'><div class='rh-src-top'><b>" . $e(Candidato::SOURCES[$k]) . "</b>" . ($k === $best ? "<span class='rh-src-best' title='Maior taxa de contratação'><i class='ti ti-trophy'></i> melhor conversão</span>" : '')
                . "<span class='rh-src-num'>{$v['all']} <small>$pct%</small></span></div>"
                . "<div class='rh-src-bar'><i style='width:$pct%'></i></div>"
                . "<div class='rh-src-sub'>" . ($v['hired'] ? "<i class='ti ti-user-check'></i> {$v['hired']} contratado(s) · $rate% de conversão" : "<span>nenhuma contratação ainda</span>") . "</div></div></div>";
        }
        return $html . "</div></div></div></div>";
    }

    public static function show(array $get): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $months = max(1, min(36, (int) ($get['months'] ?? 12)));
        $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $rh = Session::haveRight(Vaga::$rightname, Vaga::READALL);
        $me = (int) Session::getLoginUserID();

        // vagas visíveis
        $vw = ['v.is_deleted' => 0];
        if (!$rh) {
            $vw[] = ['OR' => ['v.users_id_requester' => $me, 'v.users_id_manager' => $me, 'v.users_id_recruiter' => $me]];
        }
        $byStatus = array_fill_keys(array_keys(Vaga::STATUSES), 0);
        $statusVagas = [];
        $openVagas = [];
        foreach ($DB->request(['SELECT' => ['v.id', 'v.name', 'v.positions', 'v.status', 'd.name AS dept'], 'FROM' => Vaga::getTable() . ' AS v',
            'LEFT JOIN' => [Departamento::getTable() . ' AS d' => ['ON' => ['d' => 'id', 'v' => 'plugin_rh_departamentos_id']]], 'WHERE' => $vw, 'ORDER' => 'v.name']) as $r) {
            $byStatus[(int) $r['status']]++;
            $statusVagas[(int) $r['status']][] = 'VG-' . $r['id'] . ' ' . $r['name'];
            if ((int) $r['status'] === Vaga::OPEN) {
                $openVagas[(int) $r['id']] = ['name' => (string) $r['name'], 'dept' => $r['dept'] ?: 'Sem departamento', 'positions' => (int) $r['positions'], 'active' => 0, 'hired' => 0];
            }
        }

        // funil (candidaturas das vagas visíveis) e contratações
        $funnel = array_fill_keys(array_keys(Candidatura::STAGES), 0);
        $sources = [];
        $hires = [];
        $hireNames = [];
        $entries = [];
        $ttf = [];
        $closed = ['rejected' => 0, 'withdrew' => 0];
        foreach ($DB->request([
            'SELECT' => ['c.stage', 'c.status', 'c.date_hired', 'c.date_creation', 'c.plugin_rh_candidatos_id', 'c.plugin_rh_vagas_id', 'v.date_open', 'v.status AS vstatus', 'v.name AS vaga', 'p.source', 'p.name'],
            'FROM' => Candidatura::getTable() . ' AS c',
            'INNER JOIN' => [Vaga::getTable() . ' AS v' => ['ON' => ['v' => 'id', 'c' => 'plugin_rh_vagas_id']], Candidato::getTable() . ' AS p' => ['ON' => ['p' => 'id', 'c' => 'plugin_rh_candidatos_id']]],
            'WHERE' => $vw,
        ]) as $r) {
            if (isset($closed[$r['status']])) {
                $closed[$r['status']]++;
            } elseif ((int) $r['vstatus'] === Vaga::OPEN || $r['status'] === 'hired') {
                $funnel[$r['stage']]++;
            }
            // origem: candidatos únicos (e quantos deles foram contratados)
            $src = isset(Candidato::SOURCES[$r['source']]) ? $r['source'] : 'outros';
            $sources[$src]['all'][(int) $r['plugin_rh_candidatos_id']] = true;
            if ($r['status'] === 'hired') {
                $sources[$src]['hired'][(int) $r['plugin_rh_candidatos_id']] = true;
            }
            $vid = (int) $r['plugin_rh_vagas_id'];
            if (isset($openVagas[$vid])) {
                $r['status'] === 'active' && $openVagas[$vid]['active']++;
                $r['status'] === 'hired' && $openVagas[$vid]['hired']++;
            }
            if ($r['date_creation'] && $r['date_creation'] >= $from) {
                $entries[substr($r['date_creation'], 0, 7)] = ($entries[substr($r['date_creation'], 0, 7)] ?? 0) + 1;
            }
            if ($r['status'] === 'hired' && $r['date_hired'] && $r['date_hired'] >= $from) {
                $m = substr($r['date_hired'], 0, 7);
                $hires[$m] = ($hires[$m] ?? 0) + 1;
                $hireNames[$m][] = $r['name'] . ' — ' . $r['vaga'];
                if ($r['date_open']) {
                    $ttf[] = (strtotime($r['date_hired']) - strtotime($r['date_open'])) / DAY_TIMESTAMP;
                }
            }
        }
        $sources = array_map(fn ($x) => ['all' => count($x['all'] ?? []), 'hired' => count($x['hired'] ?? [])], $sources);
        uasort($sources, fn ($a, $b) => $b['all'] <=> $a['all']);
        $mn = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        $mf = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $hireSeries = ['labels' => [], 'titles' => [], 'hires' => [], 'entries' => [], 'names' => []];
        for ($d = strtotime($from); $d <= time(); $d = strtotime('+1 month', $d)) {
            $k = date('Y-m', $d);
            $hireSeries['labels'][] = $mn[(int) date('n', $d) - 1] . '/' . date('y', $d);
            $hireSeries['titles'][] = $mf[(int) date('n', $d) - 1] . ' de ' . date('Y', $d);
            $hireSeries['hires'][] = $hires[$k] ?? 0;
            $hireSeries['entries'][] = $entries[$k] ?? 0;
            $hireSeries['names'][] = $hireNames[$k] ?? [];
        }
        $hireSeries['avg'] = round(array_sum($hireSeries['hires']) / max(1, count($hireSeries['hires'])), 1);
        // departamentos com vagas abertas
        $depts = [];
        foreach ($openVagas as $v) {
            $d = &$depts[$v['dept']];
            $d['name'] = $v['dept'];
            $d['positions'] = ($d['positions'] ?? 0) + max(0, $v['positions'] - $v['hired']);
            $d['active'] = ($d['active'] ?? 0) + $v['active'];
            $d['hired'] = ($d['hired'] ?? 0) + $v['hired'];
            $d['vagas'][] = $v;
            unset($d);
        }
        uasort($depts, fn ($a, $b) => [$b['positions'], $b['active']] <=> [$a['positions'], $a['active']]);
        $totalVagas = array_sum($byStatus);

        // admissões e férias
        $admWhere = ['is_deleted' => 0, 'status' => Admissao::PROGRESS] + ($rh ? [] : ['users_id_manager' => $me]);
        $admissions = countElementsInTable(Admissao::getTable(), $admWhere);
        $fw = ['is_deleted' => 0] + (Session::haveRight(Feria::$rightname, Feria::READALL) ? [] : [['OR' => ['users_id' => $me, 'users_id_manager' => $me]]]);
        $today = date('Y-m-d');
        $onVacation = countElementsInTable(Feria::getTable(), $fw + ['status' => Feria::APPROVED, 'date_begin' => ['<=', $today], 'date_end' => ['>=', $today]]);
        $vacPending = countElementsInTable(Feria::getTable(), $fw + ['status' => Feria::PENDING]);
        $next30 = countElementsInTable(Feria::getTable(), $fw + ['status' => Feria::APPROVED, 'date_begin' => ['>', $today], ['date_begin' => ['<=', date('Y-m-d', strtotime('+30 days'))]]]);

        $kpi = fn ($label, $value, $sub, $color, $drawer) => "<div class='col-6 col-md-4 col-xl'>"
            . "<div class='rh-kpi oak-kpi-click' role='button' tabindex='0' data-drawer='$drawer' style='--c:$color'><div class='rh-kpi-label'>$label</div><div class='rh-kpi-value'>$value</div><div class='rh-kpi-sub'>$sub</div></div></div>";

        $filter = "<form method='get' class='d-flex gap-2 align-items-center m-0'><span class='small text-secondary'>Contratações dos últimos</span>"
            . (!empty($get['funil']) ? "<input type='hidden' name='funil' value='" . $e($get['funil']) . "'>" : '')
            . "<select class='form-select form-select-sm w-auto' name='months' onchange='this.form.submit()'>";
        foreach ([3, 6, 12, 24] as $m) {
            $filter .= "<option value='$m'" . ($m === $months ? ' selected' : '') . ">$m meses</option>";
        }
        $filter .= "</select>" . \Html::closeForm(false);
        echo "<div class='rh'>" . Menu::title('Dashboard RH', 'dashboard', $filter);
        echo "<div class='row g-2 mb-3'>";
        echo $kpi('Vagas abertas', $byStatus[Vaga::OPEN], 'em seleção', '#3b82f6', 'rh-abertas');
        echo $kpi('Aguardando aprovação', $byStatus[Vaga::WAIT_MANAGER] + $byStatus[Vaga::WAIT_BOARD], $byStatus[Vaga::WAIT_MANAGER] . ' no gestor · ' . $byStatus[Vaga::WAIT_BOARD] . ' na diretoria', '#f59e0b', 'rh-aprovacao');
        echo $kpi('Candidatos em processo', array_sum($funnel) - $funnel['contratado'], $closed['rejected'] . ' reprovados · ' . $closed['withdrew'] . ' desistências', '#a855f7', 'rh-candidatos');
        echo $kpi('Contratações', array_sum($hires), "nos últimos $months meses", '#22c55e', 'rh-contratacoes');
        echo $kpi('Tempo médio de contratação', $ttf ? round(array_sum($ttf) / count($ttf)) . ' dias' : '—', 'da abertura da vaga à contratação', '#0ea5e9', 'rh-tempo');
        echo $kpi('Admissões em andamento', $admissions, 'checklists de onboarding', '#ec4899', 'rh-admissoes');
        echo $kpi('Férias', $onVacation . ' hoje', $vacPending . ' aguardando · ' . $next30 . ' nos próximos 30 dias', '#14b8a6', 'rh-ferias');
        echo "</div>";

        $data = [
            'hires'  => $hireSeries,
            'status' => array_map(fn ($k, $v) => ['name' => Vaga::STATUSES[$k][0], 'value' => $v, 'color' => Vaga::STATUSES[$k][1],
                'pct' => $totalVagas ? round(100 * $v / $totalVagas) : 0, 'vagas' => $statusVagas[$k] ?? []], array_keys($byStatus), $byStatus),
            'dept'   => array_values($depts),
        ];
        $card = fn ($id, $title, $sub, $cls = 'col-xl-6', $height = 0) => "<div class='col-12 $cls'><div class='card h-100'><div class='card-header'><h3 class='card-title'>$title</h3>"
            . "<div class='card-actions small text-secondary'>$sub</div></div><div class='card-body'><div class='rh-chart' id='$id'" . ($height ? " style='height:{$height}px'" : '') . "></div></div></div></div>";
        echo "<div class='row g-3'>";
        echo "<div class='col-12 col-xl-7'>" . self::funnel($vw, (string) ($get['funil'] ?? 'open'), $from, $months) . "</div>";
        echo "<div class='col-12 col-xl-5'>" . self::sources($sources) . "</div>";
        echo $card('rh-c-hires', '<i class="ti ti-user-plus me-2"></i>Contratações por mês', array_sum($hires) . ' contratação(ões) · média ' . str_replace('.', ',', (string) $hireSeries['avg']) . '/mês');
        echo $card('rh-c-status', '<i class="ti ti-briefcase-2 me-2"></i>Vagas por situação', $totalVagas . ' vaga(s) no total');
        echo $card('rh-c-dept', '<i class="ti ti-sitemap me-2"></i>Vagas abertas por departamento',
            count($depts) . ' departamento(s) · ' . array_sum(array_column($depts, 'positions')) . ' posição(ões) em aberto · ' . array_sum(array_column($depts, 'active')) . ' candidato(s) em processo',
            'col-xl-12', max(220, count($depts) * 70 + 70));
        echo "</div></div>";
        echo "<script type='application/json' id='rh-dash'>" . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
        echo self::drawers($vw, $from, $months, $admWhere, $fw);
        echo Menu::script('drawer.js');
        echo Menu::script('dashboard.js');
    }

    // ------------------------------------------------------------------ resumos dos cartões (painel lateral)

    /** Um <template> por cartão; o drawer.js abre o escolhido no painel lateral. */
    private static function drawers(array $vw, string $from, int $months, array $admWhere, array $fw): string
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $now = time();
        $days = fn ($d) => $d ? max(0, (int) floor(($now - strtotime($d)) / 86400)) : 0;
        $date = fn ($d) => $d ? date('d/m/Y', strtotime($d)) : '—';
        $vaga = fn ($id, $name) => "<a href='" . $e(Vaga::getFormURLWithID((int) $id)) . "'><b>VG-" . (int) $id . "</b></a><span class='oak-dr-name'>" . $e($name) . "</span>";
        $item = fn ($cls, $row, $sub, $extra = '') => "<div class='oak-dr-item $cls'><div class='oak-dr-row'>$row</div>" . ($sub !== '' ? "<div class='oak-dr-sub'>$sub</div>" : '') . "$extra</div>";
        $empty = fn ($t) => "<div class='oak-dr-empty'>$t</div>";
        $foot = fn ($label, $url, $icon = 'ti-list') => "<div class='oak-dr-foot'><a class='btn btn-outline-primary' href='" . $e($url) . "'><i class='ti $icon me-1'></i>$label</a></div>";
        $stats = fn (array $s) => "<div class='oak-dr-stats'>" . implode('', array_map(fn ($k, $v) => "<div><small>$k</small><b>$v</b></div>", array_keys($s), $s)) . "</div>";
        $out = [];

        // vagas abertas
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['v.id', 'v.name', 'v.positions', 'v.date_open', 'v.deadline', 'v.users_id_recruiter', 'd.name AS dept'], 'FROM' => Vaga::getTable() . ' AS v',
            'LEFT JOIN' => [Departamento::getTable() . ' AS d' => ['ON' => ['d' => 'id', 'v' => 'plugin_rh_departamentos_id']]],
            'WHERE' => $vw + ['v.status' => Vaga::OPEN], 'ORDER' => 'v.date_open',
        ]), false);
        $h = '';
        foreach ($rows as $r) {
            $c = array_count_values(array_column(iterator_to_array($DB->request(['SELECT' => 'status', 'FROM' => Candidatura::getTable(), 'WHERE' => ['plugin_rh_vagas_id' => $r['id']]]), false), 'status'));
            $hired = $c['hired'] ?? 0;
            $late = $r['deadline'] && strtotime($r['deadline']) < $now;
            $h .= $item($late ? 'bad' : '', $vaga($r['id'], $r['name']) . "<span class='oak-dr-val'>$hired/" . (int) $r['positions'] . "</span>",
                $e($r['dept'] ?: 'Sem departamento') . ' · aberta há ' . $days($r['date_open']) . ' dia(s) · ' . (int) ($c['active'] ?? 0) . ' em processo' . ($r['deadline'] ? ' · prazo ' . $date($r['deadline']) . ($late ? ' <b class="text-danger">vencido</b>' : '') : '')
                . ($r['users_id_recruiter'] ? ' · recrutador ' . $e(getUserName((int) $r['users_id_recruiter'])) : '') . " · <a href='" . $e(Menu::url('/front/pipeline.php?vaga=' . $r['id'])) . "'>funil</a>",
                "<div class='oak-dr-bar'><i style='width:" . ($r['positions'] ? min(100, round(100 * $hired / $r['positions'])) : 0) . "%;--oc:#22c55e'></i></div>");
        }
        $out['rh-abertas'] = ['Vagas abertas', 'ti-briefcase', '#3b82f6', "<p class='oak-dr-lead'>" . count($rows) . " vaga(s) em seleção, da mais antiga para a mais nova. A barra mostra contratados sobre posições.</p><div class='oak-dr-list'>" . ($h ?: $empty('Nenhuma vaga aberta.')) . "</div>" . $foot('Ver todas as vagas', Menu::url('/front/vaga.php'))];

        // aguardando aprovação
        $rows = iterator_to_array($DB->request(['FROM' => Vaga::getTable() . ' AS v', 'WHERE' => $vw + ['v.status' => [Vaga::WAIT_MANAGER, Vaga::WAIT_BOARD]], 'ORDER' => 'v.date_submit']), false);
        $h = '';
        foreach ($rows as $r) {
            $d = $days($r['date_submit']);
            $who = (int) $r['status'] === Vaga::WAIT_MANAGER ? 'gestor ' . getUserName((int) $r['users_id_manager']) : 'diretoria';
            $h .= $item($d > 3 ? 'bad' : 'warn', $vaga($r['id'], $r['name']) . "<span class='oak-dr-val'>$d d</span>",
                'Com <b>' . $e($who) . '</b> · enviada em ' . $date($r['date_submit']) . ' · solicitante ' . $e(getUserName((int) $r['users_id_requester'])));
        }
        $out['rh-aprovacao'] = ['Aguardando aprovação', 'ti-hourglass', '#f59e0b', "<p class='oak-dr-lead'>Requisições paradas esperando decisão, há quantos dias e com quem. Mais de 3 dias aparece em vermelho.</p><div class='oak-dr-list'>" . ($h ?: $empty('Nada esperando aprovação.')) . "</div>" . $foot('Minhas aprovações', Menu::url('/front/aprovacoes.php'), 'ti-checklist')];

        // candidatos em processo
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['c.stage', 'c.date_stage', 'c.rating', 'c.plugin_rh_vagas_id', 'p.name', 'v.name AS vaga'], 'FROM' => Candidatura::getTable() . ' AS c',
            'INNER JOIN' => [Vaga::getTable() . ' AS v' => ['ON' => ['v' => 'id', 'c' => 'plugin_rh_vagas_id']], Candidato::getTable() . ' AS p' => ['ON' => ['p' => 'id', 'c' => 'plugin_rh_candidatos_id']]],
            'WHERE' => $vw + ['c.status' => 'active', 'v.status' => Vaga::OPEN], 'ORDER' => 'c.date_stage',
        ]), false);
        $byStage = array_count_values(array_column($rows, 'stage'));
        $perStage = [];
        foreach (Candidatura::STAGES as $k => [$label]) {
            if (!empty($byStage[$k])) {
                $perStage[$label] = $byStage[$k];
            }
        }
        $h = $stats($perStage) . "<div class='oak-dr-title'>Parados há mais tempo na etapa</div><div class='oak-dr-list'>";
        foreach (array_slice($rows, 0, 30) as $r) {
            $d = $days($r['date_stage']);
            $h .= $item($d > 10 ? 'bad' : ($d > 5 ? 'warn' : ''), "<span class='oak-dr-name'><b>" . $e($r['name']) . "</b> · " . $e($r['vaga']) . "</span><span class='oak-dr-val'>$d d</span>",
                $e(Candidatura::STAGES[$r['stage']][0] ?? $r['stage']) . ((int) $r['rating'] ? ' · ' . str_repeat('★', (int) $r['rating']) : '') . " · <a href='" . $e(Menu::url('/front/pipeline.php?vaga=' . $r['plugin_rh_vagas_id'])) . "'>funil</a>");
        }
        $out['rh-candidatos'] = ['Candidatos em processo', 'ti-users', '#a855f7', "<p class='oak-dr-lead'>" . count($rows) . " candidato(s) nas vagas abertas, por etapa. Mais de 10 dias na mesma etapa aparece em vermelho.</p>" . ($rows ? $h . "</div>" : $empty('Nenhum candidato em processo.'))];

        // contratações e tempo de contratação
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['c.date_hired', 'c.plugin_rh_vagas_id', 'p.name', 'v.name AS vaga', 'v.date_open'], 'FROM' => Candidatura::getTable() . ' AS c',
            'INNER JOIN' => [Vaga::getTable() . ' AS v' => ['ON' => ['v' => 'id', 'c' => 'plugin_rh_vagas_id']], Candidato::getTable() . ' AS p' => ['ON' => ['p' => 'id', 'c' => 'plugin_rh_candidatos_id']]],
            'WHERE' => $vw + ['c.status' => 'hired', 'c.date_hired' => ['>=', $from]], 'ORDER' => 'c.date_hired DESC',
        ]), false);
        $h = '';
        $ttf = [];
        foreach ($rows as $r) {
            $t = $r['date_open'] ? max(0, (int) round((strtotime($r['date_hired']) - strtotime($r['date_open'])) / 86400)) : null;
            $t !== null && $ttf[] = $t;
            $h .= $item('good', "<span class='oak-dr-name'><b>" . $e($r['name']) . "</b> · " . $e($r['vaga']) . "</span><span class='oak-dr-val'>" . $date($r['date_hired']) . "</span>",
                $t !== null ? "contratado(a) $t dia(s) depois da abertura da vaga" : '');
        }
        $out['rh-contratacoes'] = ['Contratações', 'ti-user-plus', '#22c55e', "<p class='oak-dr-lead'>" . count($rows) . " contratação(ões) nos últimos $months meses, da mais recente.</p><div class='oak-dr-list'>" . ($h ?: $empty('Nenhuma contratação no período.')) . "</div>"];

        sort($ttf);
        $max = max(1, ...($ttf ?: [1]));
        $h = $ttf ? $stats(['Média' => round(array_sum($ttf) / count($ttf)) . ' d', 'Mediana' => $ttf[intdiv(count($ttf), 2)] . ' d', 'Mais rápida' => $ttf[0] . ' d', 'Mais lenta' => end($ttf) . ' d']) : '';
        $h .= "<div class='oak-dr-list'>";
        foreach ($rows as $r) {
            if (!$r['date_open']) {
                continue;
            }
            $t = max(0, (int) round((strtotime($r['date_hired']) - strtotime($r['date_open'])) / 86400));
            $h .= $item('', "<span class='oak-dr-name'><b>" . $e($r['name']) . "</b> · " . $e($r['vaga']) . "</span><span class='oak-dr-val'>$t d</span>", '', "<div class='oak-dr-bar'><i style='width:" . round(100 * $t / $max) . "%;--oc:#0ea5e9'></i></div>");
        }
        $out['rh-tempo'] = ['Tempo médio de contratação', 'ti-clock', '#0ea5e9', "<p class='oak-dr-lead'>Dias entre a abertura da vaga (aprovação da diretoria) e a contratação, nos últimos $months meses.</p>" . ($ttf ? $h . "</div>" : $empty('Sem contratações no período.'))];

        // admissões
        $h = '';
        $n = 0;
        foreach ($DB->request(['FROM' => Admissao::getTable(), 'WHERE' => $admWhere, 'ORDER' => 'start_date']) as $a) {
            $n++;
            $items = iterator_to_array($DB->request(['SELECT' => 'is_done', 'FROM' => 'glpi_plugin_rh_checklists', 'WHERE' => ['plugin_rh_admissaos_id' => $a['id']]]), false);
            $done = count(array_filter($items, fn ($x) => (int) $x['is_done']));
            $pct = $items ? round(100 * $done / count($items)) : 0;
            $soon = $a['start_date'] && strtotime($a['start_date']) - $now < 7 * 86400 && $pct < 100;
            $h .= $item($soon ? 'warn' : '', "<a href='" . $e(Admissao::getFormURLWithID((int) $a['id'])) . "'><b>" . $e($a['name']) . "</b></a><span class='oak-dr-name'></span><span class='oak-dr-val'>$pct%</span>",
                'Início ' . $date($a['start_date']) . " · $done de " . count($items) . ' itens do checklist' . ($a['tickets_id'] ? ' · chamado de TI #' . (int) $a['tickets_id'] : ' · chamado de TI não aberto') . ' · gestor ' . $e(getUserName((int) $a['users_id_manager'])),
                "<div class='oak-dr-bar'><i style='width:$pct%;--oc:#ec4899'></i></div>");
        }
        $out['rh-admissoes'] = ['Admissões em andamento', 'ti-id-badge-2', '#ec4899', "<p class='oak-dr-lead'>$n onboarding(s) em andamento. Começa em menos de 7 dias e o checklist não terminou: laranja.</p><div class='oak-dr-list'>" . ($h ?: $empty('Nenhuma admissão em andamento.')) . "</div>" . $foot('Ver admissões', Menu::url('/front/admissao.php'))];

        // férias: hoje, aguardando e próximos 30 dias
        $today = date('Y-m-d');
        $list = function (array $where, string $cls) use ($DB, $e, $item, $date) {
            $h = '';
            foreach ($DB->request(['FROM' => Feria::getTable(), 'WHERE' => $where, 'ORDER' => 'date_begin']) as $f) {
                $h .= $item($cls, "<a href='" . $e(Feria::getFormURLWithID((int) $f['id'])) . "'><b>" . $e(getUserName((int) $f['users_id'])) . "</b></a><span class='oak-dr-name'></span><span class='oak-dr-val'>" . (int) $f['days'] . " d</span>",
                    $date($f['date_begin']) . ' a ' . $date($f['date_end']) . ' · gestor ' . $e(getUserName((int) $f['users_id_manager'])));
            }
            return $h ?: "<div class='oak-dr-empty'>Ninguém.</div>";
        };
        $out['rh-ferias'] = ['Férias', 'ti-sun', '#14b8a6',
            "<div class='oak-dr-title'>De férias hoje</div><div class='oak-dr-list'>" . $list($fw + ['status' => Feria::APPROVED, 'date_begin' => ['<=', $today], 'date_end' => ['>=', $today]], 'good') . "</div>"
            . "<div class='oak-dr-title'>Aguardando o gestor</div><div class='oak-dr-list'>" . $list($fw + ['status' => Feria::PENDING], 'warn') . "</div>"
            . "<div class='oak-dr-title'>Começam nos próximos 30 dias</div><div class='oak-dr-list'>" . $list($fw + ['status' => Feria::APPROVED, 'date_begin' => ['>', $today], ['date_begin' => ['<=', date('Y-m-d', strtotime('+30 days'))]]], '') . "</div>"
            . $foot('Calendário de férias', Menu::url('/front/calendario.php'), 'ti-calendar-event')];

        $html = '';
        foreach ($out as $key => [$title, $icon, $color, $body]) {
            $html .= "<template id='dr-$key' data-title='" . $e($title) . "' data-icon='$icon' data-color='$color'>$body</template>";
        }
        return $html;
    }
}
