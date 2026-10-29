<?php

use GlpiPlugin\Sistemasinternos\Access;
use GlpiPlugin\Sistemasinternos\Host;
use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;
use GlpiPlugin\Sistemasinternos\Zabbix;

Session::checkRight(Menu::RIGHT_HOST, READ);

Html::header('Hosts do Zabbix', '', Menu::SECTOR, 'host');
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$form = Menu::url('/front/host.form.php');

$actions = Access::can(CREATE) ? "<a class='btn btn-light' href='" . $e($form) . "'><i class='ti ti-circle-plus me-1'></i>Incluir host</a>" : '';
echo Menu::open('Hosts do Zabbix', 'host', $actions);

if (!Settings::isConfigured()) {
    echo Menu::notConfigured() . Menu::close();
    Html::footer();
    return;
}
try {
    $hosts = Host::list();
    $groups = Access::allowedGroups();
} catch (Throwable $ex) {
    echo Menu::error($ex->getMessage()) . Menu::close();
    Html::footer();
    return;
}

$web = Zabbix::webUrl();
$total = count($hosts);
$off = count(array_filter($hosts, fn ($h) => (int) $h['status'] === 1));
$down = count(array_filter($hosts, fn ($h) => (int) $h['status'] === 0 && Host::availability($h) === 2));

echo "<div class='si360-chips mb-3'>"
    . "<span class='si360-chip'><i class='ti ti-server-2 me-1'></i>$total host(s)</span>"
    . "<span class='si360-chip ok'><i class='ti ti-player-play me-1'></i>" . ($total - $off) . " ativo(s)</span>"
    . "<span class='si360-chip'><i class='ti ti-player-pause me-1'></i>$off desativado(s)</span>"
    . "<span class='si360-chip bad'><i class='ti ti-plug-connected-x me-1'></i>$down indisponível(is)</span>"
    . "<span class='si360-chip'><i class='ti ti-folders me-1'></i>Grupos do seu perfil: " . $e(implode(', ', $groups)) . "</span></div>";

echo "<div class='card si360-card'><div class='card-header gap-2 flex-wrap'>"
    . "<input type='search' class='form-control' style='max-width:320px' placeholder='Buscar por nome, IP, grupo ou template…' data-si-filter='#si-hosts'>"
    . "<select class='form-select' style='max-width:200px' data-si-status='#si-hosts'><option value=''>Todas as situações</option><option value='on'>Ativos</option><option value='off'>Desativados</option><option value='down'>Indisponíveis</option><option value='prob'>Com problema</option></select>"
    . "<div class='card-actions small text-secondary'>A lista mostra só os grupos liberados para o seu perfil.</div></div>"
    . "<div class='table-responsive'><table class='table table-vcenter card-table table-sm' id='si-hosts'><thead><tr>"
    . "<th>Host</th><th>Interface</th><th>Grupos</th><th>Templates</th><th>Situação</th><th>Problemas</th><th class='text-end'>Ações</th></tr></thead><tbody>";

foreach ($hosts as $h) {
    $id = $e($h['hostid']);
    $main = Host::mainInterface($h);
    $avail = Host::availability($h);
    $enabled = (int) $h['status'] === 0;
    $if = $main ? (Zabbix::INTERFACES[(int) $main['type']][0] ?? '') . ' · ' . ((int) $main['useip'] ? $main['ip'] : $main['dns']) . ':' . $main['port'] : 'sem interface';
    $gNames = array_column(Zabbix::hostGroups($h), 'name');
    $tNames = array_column($h['parentTemplates'] ?? [], 'name');
    $computer = '';
    foreach ($h['tags'] ?? [] as $t) {
        if ($t['tag'] === 'glpi_computer' && ctype_digit($t['value'])) {
            $computer = " <a href='" . $e(Computer::getFormURLWithID((int) $t['value'])) . "' title='Ativo no GLPI'><i class='ti ti-device-desktop'></i></a>";
        }
    }
    $state = !$enabled ? "<span class='si360-pill'>Desativado</span>" : ($avail === 2 ? "<span class='si360-pill bad'>Indisponível</span>" : ($avail === 1 ? "<span class='si360-pill ok'>Disponível</span>" : "<span class='si360-pill'>Desconhecido</span>"));
    $prob = $h['problems'] ? "<span class='si360-sev' style='--c:" . Zabbix::SEVERITIES[$h['severity']][1] . "'>" . (int) $h['problems'] . "</span>" : "<span class='text-secondary'>—</span>";
    $status = !$enabled ? 'off' : 'on';
    $flags = trim(($avail === 2 && $enabled ? 'down ' : '') . ($h['problems'] ? 'prob' : ''));

    $btn = [];
    $btn[] = "<a class='btn btn-sm btn-ghost-primary' href='" . $e($form . '?id=' . rawurlencode($h['hostid'])) . "' title='" . (Access::can(UPDATE) ? 'Editar' : 'Ver') . "'><i class='ti ti-" . (Access::can(UPDATE) ? 'pencil' : 'eye') . "'></i></a>";
    if ($web) {
        $btn[] = "<a class='btn btn-sm btn-ghost-secondary' href='" . $e($web . 'zabbix.php?action=host.edit&hostid=' . rawurlencode($h['hostid'])) . "' target='_blank' rel='noopener' title='Abrir no Zabbix'><i class='ti ti-external-link'></i></a>";
    }
    echo "<tr data-status='$status' data-flags='$flags'><td><b>" . $e($h['name']) . "</b>$computer" . ($h['name'] !== $h['host'] ? "<div class='small text-secondary'>" . $e($h['host']) . "</div>" : '') . "</td>"
        . "<td class='text-nowrap small'>" . $e($if) . "</td><td class='small'>" . $e(implode(', ', $gNames)) . "</td>"
        . "<td class='small' title='" . $e(implode("\n", $tNames)) . "'>" . (count($tNames) ? $e($tNames[0]) . (count($tNames) > 1 ? ' <span class="text-secondary">+' . (count($tNames) - 1) . '</span>' : '') : '—') . "</td>"
        . "<td>$state</td><td>$prob</td><td class='text-end text-nowrap'>" . implode('', $btn) . "</td></tr>";
}
if (!$hosts) {
    echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Nenhum host nos grupos liberados para o seu perfil.</td></tr>";
}
echo "</tbody></table></div></div>";
echo Menu::close();
echo Html::script(Menu::url('/js/painel.js'), ['version' => Menu::fileVersion('js/painel.js')], false);
Html::footer();
