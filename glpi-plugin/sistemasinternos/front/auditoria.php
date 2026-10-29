<?php

use GlpiPlugin\Sistemasinternos\Audit;
use GlpiPlugin\Sistemasinternos\Menu;

Session::checkRight(Menu::RIGHT_HOST, READ);

global $DB;
Html::header('Auditoria dos hosts', '', Menu::SECTOR, 'auditoria');
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
echo Menu::open('Auditoria dos hosts', 'auditoria');

$q = trim((string) ($_GET['q'] ?? ''));
$action = (string) ($_GET['acao'] ?? '');
$user = (int) ($_GET['users_id'] ?? 0);
$focus = (int) ($_GET['id'] ?? 0);
$where = [];
if ($q !== '') {
    $where[] = ['OR' => [['host' => ['LIKE', "%$q%"]], ['hostid' => $q]]];
}
if (isset(Audit::ACTIONS[$action])) {
    $where['action'] = $action;
}
if ($user) {
    $where['users_id'] = $user;
}
if ($focus) {
    $where = ['id' => $focus];
}

$self = Menu::url('/front/auditoria.php');
echo "<form method='get' action='" . $e($self) . "' class='card si360-card mb-3'><div class='card-body d-flex flex-wrap gap-2 align-items-end'>"
    . "<div><label class='form-label'>Host</label><input class='form-control' name='q' value='" . $e($q) . "' placeholder='nome ou ID'></div>"
    . "<div><label class='form-label'>Ação</label><select class='form-select' name='acao'><option value=''>Todas</option>"
    . implode('', array_map(fn ($k, $a) => "<option value='$k'" . ($k === $action ? ' selected' : '') . ">{$a[0]}</option>", array_keys(Audit::ACTIONS), Audit::ACTIONS)) . "</select></div>"
    . "<div style='min-width:240px'><label class='form-label'>Quem</label>" . User::dropdown(['name' => 'users_id', 'value' => $user, 'right' => 'all', 'display' => false, 'width' => '100%', 'emptylabel' => 'Todos']) . "</div>"
    . "<button class='btn btn-primary'><i class='ti ti-filter me-1'></i>Filtrar</button>"
    . ($q !== '' || $action || $user || $focus ? "<a class='btn btn-ghost-secondary' href='" . $e($self) . "'>Limpar</a>" : '')
    . "</div></form>";

echo "<div class='card si360-card'><div class='table-responsive'><table class='table table-vcenter card-table table-sm'><thead><tr>"
    . "<th>Quando</th><th>Quem</th><th>Ação</th><th>Host</th><th>Resultado</th><th>O que mudou</th></tr></thead><tbody>";
$n = 0;
foreach ($DB->request(['FROM' => Audit::TABLE, 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => 300]) as $r) {
    $n++;
    [$label, $icon, $tone] = Audit::ACTIONS[$r['action']] ?? [$r['action'], 'ti-point', 'secondary'];
    $before = json_decode((string) $r['before'], true);
    $after = json_decode((string) $r['after'], true);
    $diff = $r['action'] === 'delete' ? [] : Audit::diff($before, $after);
    $changes = '';
    if ($r['result'] !== 'ok') {
        $changes = "<span class='text-danger'>" . $e($r['message']) . "</span>";
    } elseif ($r['action'] === 'create') {
        $changes = implode('<br>', array_map(fn ($d) => "<b>" . $e($d[0]) . ":</b> " . $e($d[2]), array_filter($diff, fn ($d) => $d[2] !== '')));
    } elseif ($r['action'] === 'delete') {
        $changes = "<span class='text-secondary'>" . $e(($before['Interface'] ?? '') . ' · ' . implode(', ', (array) ($before['Grupos'] ?? []))) . "</span>";
    } else {
        $changes = implode('<br>', array_map(fn ($d) => "<b>" . $e($d[0]) . ":</b> <s class='text-secondary'>" . $e($d[1] ?: '—') . "</s> → " . $e($d[2] ?: '—'), $diff)) ?: "<span class='text-secondary'>sem diferença</span>";
    }
    $hostLink = $r['action'] !== 'delete' && $r['hostid'] ? "<a href='" . $e(Menu::url('/front/host.form.php?id=' . rawurlencode((string) $r['hostid']))) . "'>" . $e($r['host']) . "</a>" : $e($r['host']);
    echo "<tr" . ($focus === (int) $r['id'] ? " class='si360-focus'" : '') . "><td class='text-nowrap'>" . $e(Html::convDateTime($r['date_creation'])) . "</td>"
        . "<td>" . $e(getUserName((int) $r['users_id'])) . "<div class='small text-secondary'>" . $e($r['ip']) . "</div></td>"
        . "<td><span class='badge bg-$tone-lt'><i class='ti $icon me-1'></i>" . $e($label) . "</span></td><td>$hostLink</td>"
        . "<td>" . ($r['result'] === 'ok' ? "<span class='si360-pill ok'>ok</span>" : "<span class='si360-pill bad'>erro</span>") . "</td><td class='small'>$changes</td></tr>";
}
if (!$n) {
    echo "<tr><td colspan='6' class='text-center text-secondary py-4'>Nada registrado com esses filtros.</td></tr>";
}
echo "</tbody></table></div><div class='card-footer small text-secondary'>Mostra os últimos 300 registros.</div></div>";
echo Menu::close();
Html::footer();
