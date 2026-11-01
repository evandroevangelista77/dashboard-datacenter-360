<?php

/**
 * Painel de satisfação: total de respostas, % de positivas, neutras, negativas e sem resposta,
 * nota média, NPS, evolução, origem, categoria e entidade, com a variação contra o período anterior.
 * Cada cartão e cada barra abre as pesquisas num painel lateral.
 */

use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Painel;
use GlpiPlugin\Satisfacao\Relatorio;
use GlpiPlugin\Satisfacao\Survey;

Session::checkRight(Menu::$rightname, READ);

$date = fn (?string $v, string $default) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : $default;
$filters = [
    'from'     => $date($_GET['from'] ?? null, date('Y-m-d', strtotime('-5 months', strtotime(date('Y-m-01'))))),
    'to'       => $date($_GET['to'] ?? null, date('Y-m-d')),
    'origin'   => array_key_exists($_GET['origin'] ?? '', Survey::ORIGINS) ? $_GET['origin'] : '',
    'entity'   => ($_GET['entity'] ?? '') === '' ? null : (int) $_GET['entity'],
    'category' => ($_GET['category'] ?? '') === '' ? null : (int) $_GET['category'],
];
if ($filters['from'] > $filters['to']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}

// período anterior, do mesmo tamanho, para a variação dos indicadores
$span = max(1, (int) round((strtotime($filters['to']) - strtotime($filters['from'])) / DAY_TIMESTAMP) + 1);
$prevTo = date('Y-m-d', strtotime($filters['from'] . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime("$prevTo -" . ($span - 1) . ' days'));

$d = Painel::data($filters);
$prev = Painel::data(['from' => $prevFrom, 'to' => $prevTo] + $filters);
$questions = Painel::questions($d['rows']);
$e = fn ($v) => Menu::e($v);
$num = fn ($v) => str_replace('.', ',', (string) $v);
$br = fn (string $ymd) => date('d/m/Y', strtotime($ymd));

/** Variação contra o período anterior: verde quando melhora, vermelho quando piora. */
$delta = function ($cur, $before, bool $higherIsBetter, string $unit = '') use ($prev, $num): string {
    if ($cur === null || $before === null || !$prev['total']) {
        return "<span class='sf-delta none'>sem período anterior</span>";
    }
    $diff = round($cur - $before, 1);
    if ($diff == 0) {
        return "<span class='sf-delta flat'>= igual ao anterior</span>";
    }
    return "<span class='sf-delta " . (($diff > 0) === $higherIsBetter ? 'up' : 'down') . "'>" . ($diff > 0 ? '▲ +' : '▼ −') . $num(abs($diff)) . "$unit vs. anterior</span>";
};

// ---------------------------------------------------------------- filtros
$select = function (string $name, string $label, array $options, $current) use ($e): string {
    $html = "<div><label class='form-label small mb-0'>" . $e($label) . "</label><select class='form-select form-select-sm' name='$name' onchange='this.form.submit()'><option value=''>Todas</option>";
    foreach ($options as $value => $text) {
        $html .= "<option value='" . $e($value) . "'" . ($current !== null && (string) $current === (string) $value ? ' selected' : '') . '>' . $e($text) . '</option>';
    }
    return $html . '</select></div>';
};
$filter = "<form method='get' class='d-flex flex-wrap gap-2 align-items-end m-0' action='" . $e(Menu::url('/front/painel.php')) . "'>"
    . "<div><label class='form-label small mb-0'>De</label><input type='date' class='form-control form-control-sm' name='from' value='" . $e($filters['from']) . "' onchange='this.form.submit()'></div>"
    . "<div><label class='form-label small mb-0'>Até</label><input type='date' class='form-control form-control-sm' name='to' value='" . $e($filters['to']) . "' onchange='this.form.submit()'></div>"
    . $select('origin', 'Origem', Survey::ORIGINS, $filters['origin'])
    . $select('entity', 'Entidade', $d['entities'], $filters['entity'])
    . $select('category', 'Categoria', $d['categories'], $filters['category'])
    . '</form>';

// relatório do mês em PDF: o mês escolhido aqui e os filtros do painel
$hidden = '';
foreach (['origin' => $filters['origin'], 'entity' => $filters['entity'], 'category' => $filters['category']] as $name => $value) {
    if ($value !== null && $value !== '') {
        $hidden .= "<input type='hidden' name='$name' value='" . $e($value) . "'>";
    }
}
$reportForm = "<form method='get' class='d-flex gap-1 align-items-end m-0' action='" . $e(Menu::url('/front/relatorio.php')) . "'>"
    . "<div><label class='form-label small mb-0'>Relatório do mês</label><input type='month' class='form-control form-control-sm' name='month' value='" . $e(Relatorio::defaultMonth($filters['to'])) . "' required></div>$hidden"
    . "<button class='btn btn-sm btn-outline-secondary' type='submit' title='Baixar o relatório do mês em PDF'>📄 PDF</button></form>";

Html::requireJs('charts');
Menu::header('Painel CSAT');
echo "<div class='sf'>";
echo Menu::title('Painel CSAT · satisfação', 'Como os solicitantes e os clientes avaliam o atendimento. Clique em um cartão ou em uma barra para ver as pesquisas.',
    $filter . $reportForm . Menu::button('Perguntas', Menu::url('/front/pergunta.php'), 'ti-message-question', 'btn-outline-secondary'));
echo "<div class='sf-period'><span>📅 " . $br($filters['from']) . ' a ' . $br($filters['to']) . "</span><span class='text-secondary'>comparado com " . $br($prevFrom) . ' a ' . $br($prevTo) . "</span></div>";

if (!$d['total']) {
    echo "<div class='card'><div class='card-body sf-empty'><span class='sf-emoji-xl'>📭</span>Nenhuma pesquisa enviada no período e nos filtros escolhidos.</div></div></div>";
    Html::footer();
    return;
}

// ---------------------------------------------------------------- resumo: respostas, humores e nota média
$moods = Painel::MOODS;
$face = $d['stars'] !== null ? Survey::FACES[max(1, min(5, (int) round($d['stars'])))] : '⭐';
$faceName = $d['stars'] !== null ? Survey::FACE_NAMES[max(1, min(5, (int) round($d['stars'])))] : 'sem avaliações';

echo "<div class='row g-3 mb-3'><div class='col-xl-8'><div class='card sf-hero h-100'><div class='card-body'>";
echo "<div class='sf-hero-top'><button type='button' class='sf-ring' data-list='answered' style='--p:" . (float) $d['rate'] . "' title='Ver as pesquisas respondidas'><b>" . $num($d['rate']) . "%</b><span>responderam</span></button>"
    . "<div class='sf-hero-main'><div class='sf-hero-label'>Total de respostas</div>"
    . "<div class='sf-hero-value'>{$d['answered']}<small> de {$d['total']} pesquisa(s) enviada(s)</small></div>"
    . $delta($d['rate'], $prev['rate'], true, ' pp') . "</div></div>";
echo "<div class='sf-stack' role='group' aria-label='Distribuição das pesquisas'>";
foreach (['positive', 'neutral', 'negative', 'none'] as $key) {
    if ($d['mood'][$key]) {
        echo "<button type='button' class='sf-stack-seg' data-list='mood:$key' style='--c:{$moods[$key][2]};flex:{$d['mood'][$key]}' title='" . $e("{$moods[$key][0]}: {$d['mood'][$key]} (" . $num($d['pct'][$key]) . "%)") . "'></button>";
    }
}
echo "</div><div class='sf-moods'>";
foreach (['positive' => true, 'neutral' => true, 'negative' => false, 'none' => false] as $key => $higherIsBetter) {
    [$label, $emoji, $color] = $moods[$key];
    echo "<button type='button' class='sf-mood' data-list='mood:$key' style='--c:$color'>"
        . "<span class='sf-mood-head'><span class='sf-mood-emoji'>$emoji</span>" . $e($label) . "</span>"
        . "<span class='sf-mood-pct'>" . $num($d['pct'][$key]) . "%</span>"
        . "<span class='sf-mood-n'>{$d['mood'][$key]} pesquisa(s)</span>"
        . $delta($d['pct'][$key], $prev['pct'][$key], $higherIsBetter, ' pp') . "</button>";
}
echo "</div></div></div></div>";

echo "<div class='col-xl-4'><div class='card sf-chart-card h-100'><div class='card-body'>"
    . "<button type='button' class='sf-score' data-list='rated'><span class='sf-score-face'>$face</span><span><span class='sf-hero-label'>Nota média</span>"
    . "<span class='sf-score-value'>" . ($d['stars'] === null ? '—' : $num($d['stars']) . '<small> / 5</small>') . "</span>"
    . "<span class='sf-score-name'>" . $e($faceName) . " · {$d['starsN']} avaliação(ões)</span>" . $delta($d['stars'], $prev['stars'], true) . "</span></button>"
    . "<div class='sf-chart' id='sf-c-dist' style='height:150px'></div></div></div></div></div>";

// ---------------------------------------------------------------- indicadores
$zone = function (?int $nps): array {
    return match (true) {
        $nps === null => ['sem respostas', '#94a3b8'],
        $nps >= 75    => ['zona de excelência', '#22c55e'],
        $nps >= 50    => ['zona de qualidade', '#84cc16'],
        $nps >= 0     => ['zona de aperfeiçoamento', '#f59e0b'],
        default       => ['zona crítica', '#ef4444'],
    };
};
[$zoneName, $zoneColor] = $zone($d['nps']);
$withComment = count(array_filter($d['rows'], fn ($r) => $r['c'] !== ''));
$kpi = fn (string $list, string $emoji, string $label, string $value, string $sub, string $extra, string $color) =>
    "<div class='col-6 col-xl-3'><button type='button' class='sf-kpi' style='--c:$color' data-list='$list'>"
    . "<div class='sf-kpi-top'><span class='sf-kpi-label'>" . $e($label) . "</span><span class='sf-kpi-emoji'>$emoji</span></div>"
    . "<div class='sf-kpi-value'>$value</div><div class='sf-kpi-sub'>$sub</div>$extra</button></div>";
echo "<div class='row g-3 mb-3'>";
echo $kpi('all', '📨', 'Pesquisas enviadas', (string) $d['total'], 'no período', $delta($d['total'], $prev['total'], true), '#3b82f6');
echo $kpi('nps', '📈', 'NPS', $d['nps'] === null ? '—' : ($d['nps'] > 0 ? '+' : '') . $d['nps'],
    "<span class='sf-zone' style='--c:$zoneColor'>" . $e($zoneName) . "</span>", $delta($d['nps'], $prev['nps'], true), '#a855f7');
echo $kpi('answered', '⏱️', 'Tempo até responder', $d['days'] === null ? '—' : $num($d['days']) . ' dias', 'média das respondidas', $delta($d['days'], $prev['days'], false, ' d'), '#0ea5e9');
echo $kpi('comments', '💬', 'Comentários', (string) $withComment, 'pesquisas com comentário', '', '#14b8a6');
echo "</div>";

// ---------------------------------------------------------------- gráficos
$card = fn (string $id, string $emoji, string $title, string $sub, string $cls, int $h) =>
    "<div class='$cls'><div class='card h-100 sf-chart-card'><div class='card-header'><div><h3 class='card-title'>$emoji $title</h3><div class='small text-secondary'>$sub</div></div></div>"
    . "<div class='card-body'><div class='sf-chart' id='$id' style='height:{$h}px'></div></div></div></div>";
echo "<div class='row g-3 mb-3'>";
echo $card('sf-c-month', '📅', 'Evolução por mês', 'Pesquisas por humor (barras) e nota média (linha). Clique em um mês.', 'col-xl-8', 330);
echo $card('sf-c-origin', '🧭', 'Por origem', 'Chamados, projetos, eventos e ciclos. Clique em uma barra.', 'col-xl-4', 330);
echo "</div>";

$rank = [];
if ($d['cats']) {
    $rank[] = $card('sf-c-cat', '🏷️', 'Nota média por categoria', 'Categorias dos chamados, da mais frequente para a menos.', 'col-xl-' . ($d['ents'] ? '6' : '12'), max(220, count($d['cats']) * 34 + 50));
}
if ($d['ents']) {
    $rank[] = $card('sf-c-ent', '🏢', 'Nota média por entidade', 'Entidades com pesquisas no período.', 'col-xl-' . ($d['cats'] ? '6' : '12'), max(220, count($d['ents']) * 34 + 50));
}
if ($rank) {
    echo "<div class='row g-3 mb-3'>" . implode('', $rank) . "</div>";
}

// ---------------------------------------------------------------- tabelas
echo "<div class='row g-3 mb-3'><div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>🧩 Por pergunta</h3></div><div class='table-responsive'>"
    . "<table class='table table-sm card-table table-vcenter'><thead><tr><th>Pergunta</th><th class='text-end'>Respostas</th><th class='text-end'>Média</th><th style='width:34%'>Positivo</th></tr></thead><tbody>";
foreach ($questions as $q) {
    $bar = $q['good'] === null ? "<span class='text-secondary small'>—</span>"
        : "<div class='sf-pct" . ($q['good'] < 60 ? ' bad' : '') . "'><i style='width:{$q['good']}%'></i><span>{$q['good']}%</span></div>";
    echo "<tr><td>" . $e($q['name']) . ($q['active'] ? '' : " <span class='badge bg-secondary-lt'>desativada</span>") . "</td><td class='text-end'>{$q['n']}</td>"
        . "<td class='text-end'>" . ($q['avg'] === null ? '—' : $num($q['avg'])) . ($q['nps'] !== null ? " <span class='text-secondary small'>NPS " . ($q['nps'] > 0 ? '+' : '') . $q['nps'] . '</span>' : '') . "</td><td>$bar</td></tr>";
}
echo "</tbody></table></div></div></div>";

$comments = array_values(array_filter($d['rows'], fn ($r) => $r['c'] !== ''));
echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>💬 Comentários</h3><div class='card-actions small text-secondary'>" . count($comments) . "</div></div><div class='sf-comments'>";
if (!$comments) {
    echo "<div class='sf-empty small'>Nenhum comentário no período.</div>";
}
foreach (array_slice($comments, 0, 8) as $r) {
    echo "<div class='sf-comment " . $e($r['m']) . "'><div class='sf-comment-head'><span class='sf-comment-face'>" . ($r['r'] !== null ? Survey::FACES[max(1, min(5, (int) round($r['r'])))] : $moods[$r['m']][1]) . "</span>"
        . ($r['u'] ? "<a href='" . $e($r['u']) . "'>" . $e($r['n']) . '</a>' : '<b>' . $e($r['n']) . '</b>') . "<span class='text-secondary small'>" . $e(date('d/m/Y', strtotime($r['a'] ?? $r['s']))) . "</span></div>"
        . "<div class='sf-comment-text'>" . nl2br($e($r['c'])) . "</div></div>";
}
echo "</div></div></div></div>";

// ---------------------------------------------------------------- painel lateral e dados
echo "<div class='sf-drawer-backdrop' hidden></div><aside class='sf-drawer' aria-hidden='true' role='dialog' aria-labelledby='sf-drawer-title'>"
    . "<div class='sf-drawer-head'><span class='sf-drawer-icon'></span><h3 id='sf-drawer-title'></h3><button type='button' class='btn-close' aria-label='Fechar'></button></div>"
    . "<div class='sf-drawer-body'></div></aside>";

$payload = [
    'rows'    => array_map(fn ($r) => array_diff_key($r, ['rs' => 1, 'ns' => 1]), $d['rows']),
    'moods'   => $moods,
    'faces'   => Survey::FACES,
    'names'   => Survey::FACE_NAMES,
    'dist'    => $d['dist'],
    'months'  => $d['months'],
    'origins' => $d['origins'],
    'cats'    => $d['cats'],
    'ents'    => $d['ents'],
];
echo "<script type='application/json' id='sf-data'>" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>";
echo Menu::script('painel.js');
echo "</div>";
Html::footer();
