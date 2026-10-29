<?php

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Painel;
use GlpiPlugin\Sistemasinternos\Settings;
use GlpiPlugin\Sistemasinternos\Zabbix;

Session::checkRight(Menu::RIGHT_PAINEL, READ);
Html::requireJs('charts');

Html::header('Sistemas Internos 360', '', Menu::SECTOR, 'painel');
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

$actions = "<button type='button' class='btn btn-ghost-light' data-si-refresh><i class='ti ti-refresh me-1'></i>Atualizar</button>";
$actions .= "<a class='btn btn-light' href='" . $e(Menu::url('/front/visao.php')) . "'><i class='ti ti-layout-dashboard me-1'></i>Visão 360 completa</a>";
if (Session::haveRight(Menu::RIGHT_HOST, READ)) {
    $actions .= "<a class='btn btn-light' href='" . $e(Menu::url('/front/hosts.php')) . "'><i class='ti ti-server-2 me-1'></i>Hosts do Zabbix</a>";
}
echo Menu::open('Resumo rápido', 'painel', $actions);

$z = ['hosts' => [], 'problems' => []];
if (!Settings::isConfigured()) {
    echo Menu::notConfigured();
} else {
    try {
        $z = Painel::zabbix();
    } catch (Throwable $ex) {
        echo Menu::error($ex->getMessage());
    }
}
$g = Painel::glpi();
$now = time();

// ------------------------------------------------------------------ indicadores
$hosts = $z['hosts'];
$active = array_filter($hosts, fn ($h) => $h['status'] === 0);
$down = array_filter($active, fn ($h) => $h['avail'] === 2);
$problems = $z['problems'];
$grave = array_filter($problems, fn ($p) => $p['severity'] >= 4);
$open = $g['open'];
$late = array_filter($open, fn ($t) => $t['sla'] === 'vencido');
$soon = array_filter($open, fn ($t) => $t['sla'] === 'vence');
$solved7 = array_sum(array_map(fn ($d) => $d['solved'], array_slice($g['days'], -7)));
$availPct = $active ? round(100 * (count($active) - count($down)) / count($active), 1) : null;

$kpi = fn (string $icon, string $label, string $value, string $sub, string $tone = '') => "<div class='col-6 col-md-4 col-xl-3'><div class='si360-kpi $tone'>"
    . "<span class='si360-kpi-ico'><i class='ti $icon'></i></span><div><div class='si360-kpi-label'>$label</div><div class='si360-kpi-value'>$value</div><div class='si360-kpi-sub'>$sub</div></div></div></div>";
echo "<div class='row g-3 mb-3'>";
echo $kpi('ti-activity-heartbeat', 'Disponibilidade agora', $availPct === null ? '—' : str_replace('.', ',', (string) $availPct) . '%', count($active) . ' host(s) monitorado(s)', $availPct !== null && $availPct < 99 ? 'warn' : 'ok');
echo $kpi('ti-plug-connected-x', 'Hosts indisponíveis', (string) count($down), count($hosts) - count($active) . ' desativado(s)', $down ? 'bad' : 'ok');
echo $kpi('ti-alert-triangle', 'Problemas ativos', (string) count($problems), count(array_filter($problems, fn ($p) => !$p['ack'])) . ' sem reconhecimento', $problems ? 'warn' : 'ok');
echo $kpi('ti-flame', 'Alta e desastre', (string) count($grave), 'problemas graves', $grave ? 'bad' : 'ok');
echo $kpi('ti-ticket', 'Chamados em aberto', (string) count($open), 'entidade do departamento');
echo $kpi('ti-hourglass-empty', 'SLA vencido', (string) count($late), 'chamados em aberto', $late ? 'bad' : 'ok');
echo $kpi('ti-hourglass-high', 'Vence em até 1 h', (string) count($soon), 'chamados em aberto', $soon ? 'warn' : 'ok');
echo $kpi('ti-circle-check', 'Solucionados em 7 dias', (string) $solved7, 'chamados da entidade', 'ok');
echo "</div>";

// ------------------------------------------------------------------ gráficos
$sev = array_fill(0, 6, 0);
foreach ($problems as $p) {
    $sev[$p['severity']]++;
}
$charts = [
    'sev'  => array_map(fn ($i) => ['name' => Zabbix::SEVERITIES[$i][0], 'value' => $sev[$i], 'color' => Zabbix::SEVERITIES[$i][1]], [5, 4, 3, 2, 1, 0]),
    'dayKeys' => array_keys($g['days']),
    'days' => ['labels' => array_map(fn ($d) => date('d/m', strtotime($d)), array_keys($g['days'])), 'opened' => array_column($g['days'], 'opened'), 'solved' => array_column($g['days'], 'solved')],
    'hosts' => ['ok' => count($active) - count($down), 'down' => count($down), 'off' => count($hosts) - count($active)],
];
// o que está por trás de cada ponto dos gráficos (clique abre a lista, com o link de cada alerta, host ou chamado)
$web = Zabbix::webUrl();
$charts['detail'] = [
    'problems' => array_map(fn ($p) => ['sev' => $p['severity'], 'sevName' => Zabbix::SEVERITIES[$p['severity']][0], 'color' => Zabbix::SEVERITIES[$p['severity']][1],
        'host' => $p['host'], 'name' => $p['name'], 'clock' => $p['clock'], 'ack' => (bool) $p['ack'],
        'url' => $web ? $web . 'tr_events.php?triggerid=' . rawurlencode((string) $p['triggerid']) . '&eventid=' . rawurlencode((string) $p['eventid']) : ''], $problems),
    'hosts' => array_map(fn ($h) => ['name' => $h['name'], 'ip' => $h['ip'], 'state' => $h['status'] === 1 ? 'off' : ($h['avail'] === 2 ? 'down' : 'ok'),
        'url' => $web ? $web . 'zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=' . rawurlencode((string) $h['id']) : ''], $hosts),
    'days' => array_map(fn ($d) => array_map(fn ($t) => $t + ['url' => Ticket::getFormURLWithID($t['id'])], $d['tickets']), array_values($g['days'])),
    'now' => $now,
];
echo "<div class='row g-3 mb-3'>"
    . "<div class='col-xl-4'><div class='card si360-card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-server me-2'></i>Estado dos hosts</h3></div><div class='card-body'><div class='si360-chart' id='si-c-hosts'></div></div></div></div>"
    . "<div class='col-xl-4'><div class='card si360-card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-alert-triangle me-2'></i>Problemas por severidade</h3></div><div class='card-body'><div class='si360-chart' id='si-c-sev'></div></div></div></div>"
    . "<div class='col-xl-4'><div class='card si360-card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-chart-line me-2'></i>Abertos × solucionados (14 dias)</h3></div><div class='card-body'><div class='si360-chart' id='si-c-days'></div></div></div></div>"
    . "</div><div class='si360-foot mb-3' style='margin-top:-.5rem'><i class='ti ti-pointer me-1'></i>Clique numa fatia, barra ou ponto dos gráficos para ver os alertas, hosts ou chamados, com o link de cada um.</div>";
echo "<script type='application/json' id='si-dash'>" . json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";

// ------------------------------------------------------------------ problemas ativos
echo "<div class='row g-3 mb-3'><div class='col-xl-7'><div class='card si360-card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-alert-triangle me-2'></i>Problemas ativos</h3>"
    . "<div class='card-actions small text-secondary'>" . count($problems) . " no total</div></div><div class='table-responsive si360-scroll'><table class='table table-vcenter card-table table-sm'>"
    . "<thead><tr><th>Severidade</th><th>Host</th><th>Problema</th><th>Duração</th><th></th></tr></thead><tbody>";
foreach (array_slice($problems, 0, 80) as $p) {
    [$sn, $sc] = Zabbix::SEVERITIES[$p['severity']];
    $link = $web ? "<a href='" . $e($web . 'tr_events.php?triggerid=' . rawurlencode((string) $p['triggerid']) . '&eventid=' . rawurlencode((string) $p['eventid'])) . "' target='_blank' rel='noopener' title='Abrir o alerta no Zabbix'><i class='ti ti-external-link'></i></a>" : '';
    echo "<tr><td><span class='si360-sev' style='--c:$sc'>" . $e($sn) . "</span></td><td class='text-nowrap'>" . $e($p['host']) . "</td><td>" . $e($p['name'])
        . ($p['ack'] ? " <i class='ti ti-check text-success' title='Reconhecido'></i>" : '') . "</td><td class='text-nowrap'>" . Painel::duration($now - $p['clock']) . "</td><td>$link</td></tr>";
}
if (!$problems) {
    echo "<tr><td colspan='5' class='text-center text-secondary py-4'><i class='ti ti-mood-happy me-1'></i>Nenhum problema ativo.</td></tr>";
}
echo "</tbody></table></div></div></div>";

// ------------------------------------------------------------------ hosts indisponíveis
echo "<div class='col-xl-5'><div class='card si360-card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-plug-connected-x me-2'></i>Hosts indisponíveis</h3></div>"
    . "<div class='table-responsive si360-scroll'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Host</th><th>Endereço</th></tr></thead><tbody>";
foreach ($down as $h) {
    echo "<tr><td><span class='si360-dot bad'></span>" . $e($h['name']) . "</td><td class='text-secondary'>" . $e($h['ip']) . "</td></tr>";
}
if (!$down) {
    echo "<tr><td colspan='2' class='text-center text-secondary py-4'><i class='ti ti-circle-check me-1'></i>Todos os hosts ativos respondem.</td></tr>";
}
echo "</tbody></table></div></div></div></div>";

// ------------------------------------------------------------------ chamados em aberto
$slaLabel = ['vencido' => ['SLA vencido', 'bad'], 'vence' => ['Vence em até 1 h', 'warn'], 'ok' => ['No prazo', 'ok'], 'sem' => ['Sem SLA', ''], 'prestador' => ['Prestador', '']];
$statusName = [1 => 'Novo', 2 => 'Em atendimento', 3 => 'Planejado', 4 => 'Pendente'];
usort($open, fn ($a, $b) => [array_search($a['sla'], ['vencido', 'vence', 'ok', 'sem', 'prestador']), $a['time_to_resolve']] <=> [array_search($b['sla'], ['vencido', 'vence', 'ok', 'sem', 'prestador']), $b['time_to_resolve']]);
echo "<div class='card si360-card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-ticket me-2'></i>Chamados em aberto da entidade</h3>"
    . "<div class='card-actions small text-secondary'>" . $e(Dropdown::getDropdownName('glpi_entities', (int) Settings::get('entities_id'))) . "</div></div>"
    . "<div class='table-responsive si360-scroll'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Chamado</th><th>Título</th><th>Situação</th><th>Técnico</th><th>Prazo de solução</th><th>SLA</th></tr></thead><tbody>";
foreach (array_slice($open, 0, 150) as $t) {
    [$sl, $st] = $slaLabel[$t['sla']];
    echo "<tr><td><a href='" . $e(Ticket::getFormURLWithID((int) $t['id'])) . "'>#" . (int) $t['id'] . "</a></td><td>" . $e($t['name']) . "</td><td>" . $e($statusName[(int) $t['status']] ?? '') . "</td>"
        . "<td>" . $e(implode(', ', $t['techs']) ?: '—') . "</td><td class='text-nowrap'>" . ($t['time_to_resolve'] ? $e(Html::convDateTime($t['time_to_resolve'])) : '—') . "</td>"
        . "<td><span class='si360-pill $st'>" . $e($sl) . "</span></td></tr>";
}
if (!$open) {
    echo "<tr><td colspan='6' class='text-center text-secondary py-4'>Nenhum chamado em aberto.</td></tr>";
}
echo "</tbody></table></div></div>";

echo "<div class='si360-foot'>Zabbix atualizado " . (isset($z['at']) ? 'às ' . date('H:i:s', $z['at']) : '—') . " · a página se atualiza sozinha a cada 2 minutos.</div>";
echo Menu::close();
echo Html::script(Menu::url('/js/painel.js'), ['version' => Menu::fileVersion('js/painel.js')], false);
Html::footer();
