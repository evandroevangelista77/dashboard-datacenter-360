<?php

use GlpiPlugin\Sistemasinternos\Access;
use GlpiPlugin\Sistemasinternos\Host;
use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;
use GlpiPlugin\Sistemasinternos\Zabbix;

Session::checkRight(Menu::RIGHT_HOST, READ);

$id = preg_replace('/\D/', '', (string) ($_REQUEST['id'] ?? ''));
$list = Menu::url('/front/hosts.php');
$self = Menu::url('/front/host.form.php');
$error = '';
$current = null;

if (Settings::isConfigured() && $id !== '') {
    try {
        $current = Host::find($id);
    } catch (Throwable $ex) {
        Session::addMessageAfterRedirect($ex->getMessage(), false, ERROR);
        Html::redirect($list);
    }
}

// ------------------------------------------------------------------ ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save'])) {
            $hostid = Host::save($_POST, $current);
            Session::addMessageAfterRedirect($current ? 'Host atualizado no Zabbix.' : 'Host incluído no Zabbix.', false, INFO);
            Html::redirect($self . '?id=' . rawurlencode($hostid));
        }
        if ($current && isset($_POST['toggle'])) {
            $enable = (int) $current['status'] === 1;
            Host::setStatus($current, $enable);
            Session::addMessageAfterRedirect($enable ? 'Host ativado: o Zabbix volta a monitorá-lo.' : 'Host desativado: continua cadastrado, mas sem coleta nem alertas.', false, INFO);
            Html::redirect($self . '?id=' . rawurlencode($current['hostid']));
        }
        if ($current && isset($_POST['purge'])) {
            Host::delete($current, (string) ($_POST['confirm'] ?? ''));
            Session::addMessageAfterRedirect('Host ' . $current['name'] . ' excluído do Zabbix.', false, INFO);
            Html::redirect($list);
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$isNew = $current === null;
Html::header('Hosts do Zabbix', '', Menu::SECTOR, 'host');
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$canEdit = Access::can($isNew ? CREATE : UPDATE);

$actions = "<a class='btn btn-ghost-light' href='" . $e($list) . "'><i class='ti ti-arrow-left me-1'></i>Voltar à lista</a>";
if (!$isNew && ($web = Zabbix::webUrl())) {
    $actions .= "<a class='btn btn-light' href='" . $e($web . 'zabbix.php?action=host.edit&hostid=' . rawurlencode($current['hostid'])) . "' target='_blank' rel='noopener'><i class='ti ti-external-link me-1'></i>Abrir no Zabbix</a>";
}
echo Menu::open($isNew ? 'Incluir host' : $current['name'], 'host_form', $actions);

if (!Settings::isConfigured()) {
    echo Menu::notConfigured() . Menu::close();
    Html::footer();
    return;
}
if ($isNew && !$canEdit) {
    echo Menu::error('Seu perfil não pode incluir hosts.') . Menu::close();
    Html::footer();
    return;
}
if ($error) {
    echo Menu::error($error);
}

try {
    $groups = Access::allowedGroups();
    $templates = Access::allowedTemplates();
    $proxies = Zabbix::proxies();
} catch (Throwable $ex) {
    echo Menu::error($ex->getMessage()) . Menu::close();
    Html::footer();
    return;
}

// ------------------------------------------------------------------ valores do formulário (do host ou do que foi digitado)
$main = $current ? Host::mainInterface($current) : null;
$tags = [];
$computer = 0;
foreach ($current['tags'] ?? [] as $t) {
    if ($t['tag'] === 'glpi_computer') {
        $computer = (int) $t['value'];
    } else {
        $tags[] = [$t['tag'], $t['value']];
    }
}
$v = [
    'host'        => $current['host'] ?? '',
    'name'        => $current['name'] ?? '',
    'description' => $current['description'] ?? '',
    'status'      => $current ? (int) $current['status'] === 0 : true,
    'groups'      => $current ? array_column(Zabbix::hostGroups($current), 'groupid') : array_slice(array_keys($groups), 0, 1),
    'templates'   => $current ? array_column($current['parentTemplates'] ?? [], 'templateid') : [],
    'if_type'     => (int) ($main['type'] ?? 1),
    'if_useip'    => (int) ($main['useip'] ?? 1),
    'if_ip'       => $main['ip'] ?? '',
    'if_dns'      => $main['dns'] ?? '',
    'if_port'     => $main['port'] ?? '',
    'snmp'        => $main['details'] ?? [],
    'proxyid'     => (string) ($current['proxyid'] ?? $current['proxy_hostid'] ?? '0'),
    'tags'        => $tags,
    'macros'      => array_map(fn ($m) => [$m['macro'], $m['value'], (int) ($m['type'] ?? 0)], $current['macros'] ?? []),
    'computers_id' => $computer,
];
if ($error && isset($_POST['save'])) {
    $p = $_POST;
    $v = array_merge($v, [
        'host' => (string) ($p['host'] ?? ''), 'name' => (string) ($p['name'] ?? ''), 'description' => (string) ($p['description'] ?? ''),
        'status' => !empty($p['status']), 'groups' => (array) ($p['groups'] ?? []), 'templates' => (array) ($p['templates'] ?? []),
        'if_type' => (int) ($p['if_type'] ?? 1), 'if_useip' => (int) ($p['if_useip'] ?? 1), 'if_ip' => (string) ($p['if_ip'] ?? ''),
        'if_dns' => (string) ($p['if_dns'] ?? ''), 'if_port' => (string) ($p['if_port'] ?? ''), 'proxyid' => (string) ($p['proxyid'] ?? '0'),
        'snmp' => ['version' => (int) ($p['snmp_version'] ?? 2), 'community' => (string) ($p['snmp_community'] ?? ''), 'securityname' => (string) ($p['snmp_securityname'] ?? ''),
            'securitylevel' => (int) ($p['snmp_securitylevel'] ?? 0), 'authprotocol' => (int) ($p['snmp_authprotocol'] ?? 1), 'privprotocol' => (int) ($p['snmp_privprotocol'] ?? 1)],
        'tags' => array_map(null, (array) ($p['tag_name'] ?? []), (array) ($p['tag_value'] ?? [])),
        'macros' => array_map(fn ($n, $val) => [$n, $val, 0], (array) ($p['macro_name'] ?? []), (array) ($p['macro_value'] ?? [])),
        'computers_id' => (int) ($p['computers_id'] ?? 0),
    ]);
}
$dis = $canEdit ? '' : ' disabled';

// grupos e templates de fora do alcance do perfil (preservados, só informados)
$otherGroups = $current ? array_diff_key(array_column(Zabbix::hostGroups($current), 'name', 'groupid'), $groups) : [];
$otherTemplates = $current ? array_diff_key(array_column($current['parentTemplates'] ?? [], 'name', 'templateid'), $templates) : [];

echo "<form method='post' action='" . $e($self) . "' class='si360-form' autocomplete='off'>";
if (!$isNew) {
    echo "<input type='hidden' name='id' value='" . $e($current['hostid']) . "'>";
}
echo "<div class='row g-3'><div class='col-xl-8'>";

// identificação
echo "<div class='card si360-card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-id me-2'></i>Identificação</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-md-6'><label class='form-label required'>Nome técnico</label><input class='form-control' name='host' maxlength='128' required value='" . $e($v['host']) . "'$dis>"
    . "<div class='form-hint'>Igual ao <code>Hostname</code> do agente. Sem acentos.</div></div>"
    . "<div class='col-md-6'><label class='form-label'>Nome visível</label><input class='form-control' name='name' maxlength='128' value='" . $e($v['name']) . "'$dis>"
    . "<div class='form-hint'>Como aparece nos painéis e alertas. Vazio = nome técnico.</div></div>"
    . "<div class='col-12'><label class='form-label" . (Settings::get('require_desc') === '1' ? ' required' : '') . "'>Descrição</label><textarea class='form-control' name='description' rows='2'$dis>" . $e($v['description']) . "</textarea></div>"
    . "<div class='col-md-6'><label class='form-label'>Ativo do GLPI (opcional)</label>"
    . Computer::dropdown(['name' => 'computers_id', 'value' => $v['computers_id'], 'display' => false, 'width' => '100%', 'entity' => -1, 'emptylabel' => '—'])
    . "<div class='form-hint'>Guarda o vínculo na tag <code>glpi_computer</code>.</div></div>"
    . "<div class='col-md-6 d-flex align-items-end'><label class='form-check form-switch mb-2'><input class='form-check-input' type='checkbox' name='status' value='1'" . ($v['status'] ? ' checked' : '') . "$dis>"
    . "<span class='form-check-label'>Monitorado (ativo)</span></label></div>"
    . "</div></div></div>";

// interface
$type = $v['if_type'];
$snmp = $v['snmp'];
$opt = fn (array $opts, $sel) => implode('', array_map(fn ($k, $l) => "<option value='" . $e($k) . "'" . ((string) $k === (string) $sel ? ' selected' : '') . ">" . $e($l) . "</option>", array_keys($opts), $opts));
echo "<div class='card si360-card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-plug me-2'></i>Interface de coleta</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-md-3'><label class='form-label'>Tipo</label><select class='form-select' name='if_type' data-si-iftype$dis>"
    . $opt(array_map(fn ($i) => $i[0], Zabbix::INTERFACES), $type) . "</select></div>"
    . "<div class='col-md-3'><label class='form-label'>Conectar por</label><select class='form-select' name='if_useip'$dis>" . $opt(['1' => 'IP', '0' => 'DNS'], $v['if_useip']) . "</select></div>"
    . "<div class='col-md-3'><label class='form-label'>IP</label><input class='form-control' name='if_ip' value='" . $e($v['if_ip']) . "' placeholder='10.0.0.10'$dis></div>"
    . "<div class='col-md-3'><label class='form-label'>Porta</label><input class='form-control' name='if_port' value='" . $e($v['if_port']) . "' placeholder='" . Zabbix::INTERFACES[$type][1] . "' data-si-port$dis></div>"
    . "<div class='col-md-6'><label class='form-label'>DNS</label><input class='form-control' name='if_dns' value='" . $e($v['if_dns']) . "' placeholder='servidor.dominio.local'$dis></div>";
if ($proxies) {
    echo "<div class='col-md-6'><label class='form-label'>Monitorado por</label><select class='form-select' name='proxyid'$dis>" . $opt(['0' => 'Zabbix server'] + $proxies, $v['proxyid']) . "</select></div>";
}
echo "</div><div class='row g-3 mt-1' data-si-snmp" . ($type === 2 ? '' : ' hidden') . ">"
    . "<div class='col-md-3'><label class='form-label'>Versão SNMP</label><select class='form-select' name='snmp_version' data-si-snmpver$dis>" . $opt(['1' => 'SNMPv1', '2' => 'SNMPv2c', '3' => 'SNMPv3'], $snmp['version'] ?? 2) . "</select></div>"
    . "<div class='col-md-9' data-si-v12><label class='form-label'>Comunidade</label><input class='form-control' name='snmp_community' value='" . $e($snmp['community'] ?? '{$SNMP_COMMUNITY}') . "'$dis></div>"
    . "<div class='col-md-3' data-si-v3><label class='form-label'>Nome de segurança</label><input class='form-control' name='snmp_securityname' value='" . $e($snmp['securityname'] ?? '') . "'$dis></div>"
    . "<div class='col-md-3' data-si-v3><label class='form-label'>Nível</label><select class='form-select' name='snmp_securitylevel'$dis>" . $opt(['0' => 'noAuthNoPriv', '1' => 'authNoPriv', '2' => 'authPriv'], $snmp['securitylevel'] ?? 0) . "</select></div>"
    . "<div class='col-md-3' data-si-v3><label class='form-label'>Autenticação</label><select class='form-select' name='snmp_authprotocol'$dis>" . $opt(['0' => 'MD5', '1' => 'SHA1', '2' => 'SHA224', '3' => 'SHA256', '4' => 'SHA384', '5' => 'SHA512'], $snmp['authprotocol'] ?? 1) . "</select></div>"
    . "<div class='col-md-3' data-si-v3><label class='form-label'>Frase-senha de autenticação</label><input type='password' class='form-control' name='snmp_authpassphrase' autocomplete='new-password' placeholder='" . ($isNew ? '' : 'em branco = manter') . "'$dis></div>"
    . "<div class='col-md-3' data-si-v3><label class='form-label'>Privacidade</label><select class='form-select' name='snmp_privprotocol'$dis>" . $opt(['0' => 'DES', '1' => 'AES128', '2' => 'AES192', '3' => 'AES256', '4' => 'AES192C', '5' => 'AES256C'], $snmp['privprotocol'] ?? 1) . "</select></div>"
    . "<div class='col-md-3' data-si-v3><label class='form-label'>Frase-senha de privacidade</label><input type='password' class='form-control' name='snmp_privpassphrase' autocomplete='new-password' placeholder='" . ($isNew ? '' : 'em branco = manter') . "'$dis></div>"
    . "</div>";
if (!$isNew && count($current['interfaces'] ?? []) > 1) {
    echo "<div class='form-hint mt-2'><i class='ti ti-info-circle me-1'></i>O host tem " . count($current['interfaces']) . " interfaces: aqui você edita a principal; as outras ficam como estão.</div>";
}
echo "</div></div>";

// tags e macros
$rows = function (string $kind, array $list) use ($e, $dis) {
    $html = '';
    foreach ($list ?: [['', '', 0]] as $r) {
        $secret = $kind === 'macro' && (int) ($r[2] ?? 0) !== 0;
        $html .= "<div class='si360-kv'" . ($secret ? " title='Macro secreta ou de cofre: mantida, edite no Zabbix'" : '') . ">"
            . "<input class='form-control' name='{$kind}_name[]' value='" . $e($r[0] ?? '') . "' placeholder='" . ($kind === 'macro' ? '{$NOME}' : 'tag') . "'" . ($secret ? ' readonly' : '') . "$dis>"
            . "<input class='form-control' name='{$kind}_value[]' value='" . $e($secret ? '••••••' : ($r[1] ?? '')) . "' placeholder='valor'" . ($secret ? ' readonly' : '') . "$dis>"
            . ($dis ? '' : "<button type='button' class='btn btn-ghost-danger btn-icon' data-si-kv-del title='Remover'><i class='ti ti-x'></i></button>") . "</div>";
    }
    return $html;
};
echo "<div class='row g-3'><div class='col-md-6'><div class='card si360-card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-tags me-2'></i>Tags</h3>"
    . ($dis ? '' : "<div class='card-actions'><button type='button' class='btn btn-sm btn-ghost-primary' data-si-kv-add='tag'><i class='ti ti-plus me-1'></i>Tag</button></div>") . "</div>"
    . "<div class='card-body' data-si-kv='tag'>" . $rows('tag', $v['tags']) . "</div>"
    . (($auto = Settings::autoTag()) ? "<div class='card-footer small text-secondary'>Todo host cadastrado pelo GLPI recebe <code>" . $e($auto['tag'] . '=' . $auto['value']) . "</code>.</div>" : '') . "</div></div>";
echo "<div class='col-md-6'><div class='card si360-card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-variable me-2'></i>Macros</h3>"
    . ($dis ? '' : "<div class='card-actions'><button type='button' class='btn btn-sm btn-ghost-primary' data-si-kv-add='macro'><i class='ti ti-plus me-1'></i>Macro</button></div>") . "</div>"
    . "<div class='card-body' data-si-kv='macro'>" . $rows('macro', $v['macros']) . "</div>"
    . "<div class='card-footer small text-secondary'>Só macros de texto. Secretas e de cofre ficam como estão (edite no Zabbix).</div></div></div></div>";

echo "</div><div class='col-xl-4'>";

// grupos e templates
echo "<div class='card si360-card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-folders me-2'></i>Grupos</h3></div><div class='card-body'>"
    . Dropdown::showFromArray('groups', $groups, ['multiple' => true, 'values' => $v['groups'], 'display' => false, 'width' => '100%', 'disabled' => !$canEdit])
    . "<div class='form-hint mt-1'>Só os grupos liberados para o seu perfil.</div>"
    . ($otherGroups ? "<div class='si360-keep mt-2'><i class='ti ti-lock me-1'></i>Também está em: <b>" . $e(implode(', ', $otherGroups)) . "</b> (mantidos).</div>" : '')
    . "</div></div>";
echo "<div class='card si360-card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-template me-2'></i>Templates</h3></div><div class='card-body'>"
    . Dropdown::showFromArray('templates', $templates, ['multiple' => true, 'values' => $v['templates'], 'display' => false, 'width' => '100%', 'disabled' => !$canEdit])
    . "<div class='form-hint mt-1'>" . (Settings::ids('templates') ? 'Templates aprovados na configuração.' : 'Todos os templates que a conta de serviço enxerga.') . " Ao remover um template, os itens dele são apagados do host.</div>"
    . ($otherTemplates ? "<div class='si360-keep mt-2'><i class='ti ti-lock me-1'></i>Vinculados fora da lista: <b>" . $e(implode(', ', $otherTemplates)) . "</b> (mantidos).</div>" : '')
    . "</div></div>";

if ($canEdit) {
    echo "<div class='card si360-card mb-3'><div class='card-body d-grid gap-2'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>" . ($isNew ? 'Incluir no Zabbix' : 'Salvar no Zabbix') . "</button>"
        . "<div class='form-hint text-center'>A alteração vale na hora e fica registrada na auditoria.</div></div></div>";
}
echo "</div></div>";
Html::closeForm();

// ativar/desativar e excluir
if (!$isNew && (Access::can(UPDATE) || Host::canPurge())) {
    $owns = Access::ownsAll($current);
    $enabled = (int) $current['status'] === 0;
    echo "<div class='card si360-card si360-danger mt-1'><div class='card-header'><h3 class='card-title'><i class='ti ti-alert-octagon me-2'></i>Monitoramento e exclusão</h3></div><div class='card-body'><div class='row g-3'>";
    if (Access::can(UPDATE)) {
        echo "<div class='col-md-6'><form method='post' action='" . $e($self) . "'><input type='hidden' name='id' value='" . $e($current['hostid']) . "'>"
            . "<p class='mb-2'>" . ($enabled ? 'Desativar para a coleta e os alertas pararem, sem perder o histórico.' : 'O host está desativado: ative para voltar a coletar e alertar.') . "</p>"
            . "<button class='btn btn-" . ($enabled ? 'warning' : 'success') . "' name='toggle' value='1'" . (!$enabled || $owns ? '' : ' disabled title="O host está em grupos de outros departamentos"') . ">"
            . "<i class='ti ti-player-" . ($enabled ? 'pause' : 'play') . " me-1'></i>" . ($enabled ? 'Desativar' : 'Ativar') . "</button>";
        Html::closeForm();
        echo "</div>";
    }
    if (Host::canPurge()) {
        echo "<div class='col-md-6'><form method='post' action='" . $e($self) . "'><input type='hidden' name='id' value='" . $e($current['hostid']) . "'>"
            . "<p class='mb-2'>Excluir apaga o host <b>e todo o histórico</b> dele no Zabbix. Para confirmar, digite o nome técnico <code>" . $e($current['host']) . "</code>.</p>"
            . "<div class='input-group'><input class='form-control' name='confirm' placeholder='" . $e($current['host']) . "' autocomplete='off'" . ($owns ? '' : ' disabled') . ">"
            . "<button class='btn btn-danger' name='purge' value='1'" . ($owns ? '' : ' disabled') . "><i class='ti ti-trash me-1'></i>Excluir</button></div>"
            . ($owns ? '' : "<div class='form-hint mt-1'>O host também está em grupos de outros departamentos e não pode ser excluído por aqui.</div>");
        Html::closeForm();
        echo "</div>";
    }
    echo "</div></div></div>";
}

echo Menu::close();
echo "<script type='application/json' id='si-ports'>" . json_encode(array_map(fn ($i) => $i[1], Zabbix::INTERFACES)) . "</script>";
echo Html::script(Menu::url('/js/painel.js'), ['version' => Menu::fileVersion('js/painel.js')], false);
Html::footer();
