<?php

/**
 * Painel do eNPS (clima): eNPS, promotores, neutros e detratores, participação, distribuição das notas,
 * evolução por campanha, grupos (a partir de 5 respostas) e comentários anônimos.
 */

use GlpiPlugin\Satisfacao\Enps;
use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Relatorio;
use GlpiPlugin\Satisfacao\Survey;

Session::checkRight(Menu::$rightname, READ);

global $DB;
$date = fn (?string $v, string $default) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : $default;
$from = $date($_GET['from'] ?? null, date('Y-m-d', strtotime('-11 months', strtotime(date('Y-m-01')))));
$to = $date($_GET['to'] ?? null, date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$campaign = (int) ($_GET['campaign'] ?? 0);

$d = Enps::data($from, $to, $campaign);
$e = fn ($v) => Menu::e($v);
$num = fn ($v) => str_replace('.', ',', (string) $v);
$br = fn (string $ymd) => date('d/m/Y', strtotime($ymd));

$options = "<option value=''>Todas do período</option>";
foreach ($DB->request(['SELECT' => ['id', 'name', 'date_sent'], 'FROM' => 'glpi_plugin_satisfacao_campaigns', 'WHERE' => ['status' => [1, 2]], 'ORDER' => ['date_sent DESC']]) as $c) {
    $options .= "<option value='" . (int) $c['id'] . "'" . ($campaign === (int) $c['id'] ? ' selected' : '') . '>' . $e($c['name']) . ' · ' . $e($br(substr($c['date_sent'], 0, 10))) . '</option>';
}
$filter = "<form method='get' class='d-flex flex-wrap gap-2 align-items-end m-0' action='" . $e(Menu::url('/front/enps.php')) . "'>"
    . "<div><label class='form-label small mb-0'>De</label><input type='date' class='form-control form-control-sm' name='from' value='" . $e($from) . "' onchange='this.form.submit()'></div>"
    . "<div><label class='form-label small mb-0'>Até</label><input type='date' class='form-control form-control-sm' name='to' value='" . $e($to) . "' onchange='this.form.submit()'></div>"
    . "<div><label class='form-label small mb-0'>Campanha</label><select class='form-select form-select-sm' name='campaign' onchange='this.form.submit()'>$options</select></div></form>";

$actions = $filter;
if (Session::haveRight(Menu::$rightname, UPDATE)) {
    $actions .= Menu::button('Campanhas', Menu::url('/front/campanha.php'), 'ti-send', 'btn-outline-secondary');
}
$actions .= Menu::button('Relatório do mês (PDF)', Menu::url('/front/relatorio.php?month=' . Relatorio::defaultMonth($to)), 'ti-file-type-pdf', 'btn-outline-secondary');

Html::requireJs('charts');
Menu::header('Painel e-NPS');
echo "<div class='sf'>";
echo Menu::title('Painel e-NPS · clima da equipe', 'O quanto a equipe recomendaria a empresa como lugar para trabalhar. As respostas são anônimas: o painel nunca mostra quem respondeu o quê.', $actions);

if (!$d['campaigns']) {
    echo "<div class='card'><div class='card-body sf-empty'><span class='sf-emoji-xl'>🧭</span>Nenhuma campanha de eNPS enviada no período."
        . (Session::haveRight(Menu::$rightname, UPDATE) ? " <a href='" . $e(Menu::url('/front/campanha.php')) . "'>Criar uma campanha</a>" : '') . "</div></div></div>";
    Html::footer();
    return;
}

[$zoneName, $zoneColor] = Enps::zone($d['enps']);
$tiles = [
    ['promoter', '😍', 'Promotores', 'notas 9 e 10', '#22c55e'],
    ['passive', '😐', 'Neutros', 'notas 7 e 8', '#f59e0b'],
    ['detractor', '🙁', 'Detratores', 'notas de 0 a 6', '#ef4444'],
];
echo "<div class='row g-3 mb-3'><div class='col-xl-8'><div class='card sf-hero h-100'><div class='card-body'>";
echo "<div class='sf-hero-top'><div class='sf-ring' style='--p:" . (float) $d['rate'] . "'><b>" . $num($d['rate']) . "%</b><span>participaram</span></div>"
    . "<div class='sf-hero-main'><div class='sf-hero-label'>eNPS</div><div class='sf-hero-value'>" . ($d['enps'] === null ? '—' : ($d['enps'] > 0 ? '+' : '') . $d['enps'])
    . "<small> {$d['n']} resposta(s) de {$d['sent']} enviada(s)</small></div><span class='sf-zone' style='--c:$zoneColor'>" . $e($zoneName) . "</span></div></div>";
echo "<div class='sf-stack'>";
foreach ($tiles as [$key, , , , $color]) {
    if ($d[$key]) {
        echo "<span class='sf-stack-seg' style='--c:$color;flex:{$d[$key]}'></span>";
    }
}
echo "</div><div class='sf-moods'>";
foreach ($tiles as [$key, $emoji, $label, $hint, $color]) {
    $p = 'p' . ucfirst($key);
    echo "<div class='sf-mood' style='--c:$color;cursor:default'><span class='sf-mood-head'><span class='sf-mood-emoji'>$emoji</span>" . $e($label) . "</span>"
        . "<span class='sf-mood-pct'>" . $num($d[$p]) . "%</span><span class='sf-mood-n'>{$d[$key]} pessoa(s) · " . $e($hint) . "</span></div>";
}
echo "</div></div></div></div>";
echo "<div class='col-xl-4'><div class='card sf-chart-card h-100'><div class='card-body'><div class='sf-card-title'>📊 Distribuição das notas</div>"
    . "<div class='sf-chart' id='sf-e-dist' style='height:230px'></div></div></div></div></div>";

echo "<div class='row g-3 mb-3'>";
echo "<div class='col-xl-8'><div class='card h-100 sf-chart-card'><div class='card-header'><div><h3 class='card-title'>📅 Evolução por campanha</h3><div class='small text-secondary'>eNPS de cada campanha (linha) e respostas (barras).</div></div></div>"
    . "<div class='card-body'><div class='sf-chart' id='sf-e-evo' style='height:300px'></div></div></div></div>";
echo "<div class='col-xl-4'><div class='card h-100'><div class='card-header'><h3 class='card-title'>🗂️ Campanhas</h3></div><div class='table-responsive'><table class='table table-sm card-table table-vcenter'>"
    . "<thead><tr><th>Campanha</th><th class='text-end'>Particip.</th><th class='text-end'>eNPS</th></tr></thead><tbody>";
foreach (array_reverse($d['campaigns']) as $c) {
    echo "<tr><td>" . $e($c['name']) . "<div class='small text-secondary'>" . $e($br($c['date'])) . "</div></td><td class='text-end'>" . $num($c['rate']) . "%<div class='small text-secondary'>{$c['answered']}/{$c['sent']}</div></td>"
        . "<td class='text-end fw-bold'>" . ($c['enps'] === null ? '—' : ($c['enps'] > 0 ? '+' : '') . $c['enps']) . "</td></tr>";
}
echo "</tbody></table></div></div></div></div>";

echo "<div class='row g-3 mb-3'>";
echo "<div class='col-xl-6'><div class='card h-100 sf-chart-card'><div class='card-header'><div><h3 class='card-title'>🏢 eNPS por grupo</h3><div class='small text-secondary'>Só grupos com " . Survey::ENPS_MIN_GROUP . " respostas ou mais, para preservar o anonimato"
    . ($d['hiddenGroups'] ? " ({$d['hiddenGroups']} oculto(s))" : '') . ".</div></div></div><div class='card-body'>"
    . ($d['groups'] ? "<div class='sf-chart' id='sf-e-grp' style='height:" . max(200, count($d['groups']) * 36 + 50) . "px'></div>" : "<div class='sf-empty small'>Nenhum grupo atingiu o mínimo de respostas.</div>")
    . "</div></div></div>";
echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>💬 Comentários anônimos</h3><div class='card-actions small text-secondary'>" . count($d['comments']) . "</div></div><div class='sf-comments'>";
if (!$d['comments']) {
    echo "<div class='sf-empty small'>Nenhum comentário no período.</div>";
}
$faces = ['promoter' => '😍', 'passive' => '😐', 'detractor' => '🙁'];
$cls = ['promoter' => 'positive', 'passive' => 'neutral', 'detractor' => 'negative'];
foreach (array_slice($d['comments'], 0, 12) as $c) {
    echo "<div class='sf-comment {$cls[$c['k']]}'><div class='sf-comment-head'><span class='sf-comment-face'>{$faces[$c['k']]}</span><b>Nota {$c['s']}</b>"
        . "<span class='text-secondary small'>" . $e($c['campaign']) . ' · ' . $e($br($c['d'])) . "</span></div><div class='sf-comment-text'>" . nl2br($e($c['c'])) . "</div></div>";
}
echo "</div></div></div></div>";

$payload = ['dist' => $d['dist'], 'campaigns' => $d['campaigns'], 'groups' => $d['groups']];
echo "<script type='application/json' id='sf-enps-data'>" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>";
echo Menu::script('enps.js');
echo "</div>";
Html::footer();
