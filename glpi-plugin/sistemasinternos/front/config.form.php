<?php

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;
use GlpiPlugin\Sistemasinternos\Zabbix;

Session::checkRight(Menu::RIGHT_CONFIG, UPDATE);

$self = Menu::url('/front/config.form.php');
$hhmm = fn ($v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : '';
$ids = fn ($v) => implode(',', array_filter(array_map('strval', (array) $v), 'ctype_digit'));
$tab = preg_replace('/[^a-z]/', '', (string) ($_POST['tab'] ?? $_GET['tab'] ?? 'conexao'));

if (isset($_POST['save'])) {
    $url = trim((string) ($_POST['zabbix_url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://.+/api_jsonrpc\.php$#', $url)) {
        Session::addMessageAfterRedirect('O endereço da API do Zabbix deve começar com http(s):// e terminar em api_jsonrpc.php.', false, ERROR);
        Html::redirect("$self?tab=$tab");
    }
    $alerts = [];
    foreach (array_keys(Settings::ALERTS) as $key) {
        $alerts[$key] = [
            'channels' => array_values(array_intersect(array_keys((array) ($_POST['alert'][$key] ?? [])), array_keys(Settings::CHANNELS))),
            'tone'     => isset(Settings::TONES[$_POST['tone'][$key] ?? '']) ? $_POST['tone'][$key] : 'bip',
        ];
    }
    Settings::save([
        'zabbix_url'     => $url,
        'zabbix_token'   => trim((string) ($_POST['zabbix_token'] ?? '')),
        'zabbix_verify'  => empty($_POST['zabbix_verify']) ? '0' : '1',
        'groups'         => $ids($_POST['groups'] ?? []),
        'entities_id'    => (string) max(0, (int) ($_POST['entities_id'] ?? 0)),
        'title'          => mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 80) ?: 'Sistemas Internos',
        'si_theme'       => ($_POST['si_theme'] ?? '') === 'original' ? 'original' : 'azul',
        'escalation_name'  => mb_substr(trim((string) ($_POST['escalation_name'] ?? '')), 0, 120),
        'escalation_phone' => mb_substr(trim((string) ($_POST['escalation_phone'] ?? '')), 0, 40),
        'templates'      => $ids($_POST['templates'] ?? []),
        'auto_tag'       => mb_substr(trim((string) ($_POST['auto_tag'] ?? '')), 0, 255),
        'require_desc'   => empty($_POST['require_desc']) ? '0' : '1',
        'prestadores'    => mb_substr(trim((string) ($_POST['prestadores'] ?? '')), 0, 2000),
        'alerts'         => json_encode($alerts),
        'poll_seconds'   => (string) max(15, min(600, (int) ($_POST['poll_seconds'] ?? 30))),
        'popup_seconds'  => (string) max(5, min(300, (int) ($_POST['popup_seconds'] ?? 20))),
        'popup_max'      => (string) max(1, min(10, (int) ($_POST['popup_max'] ?? 5))),
        'popup_position' => ($_POST['popup_position'] ?? '') === 'left' ? 'left' : 'right',
        'everywhere'     => empty($_POST['everywhere']) ? '0' : '1',
        'quiet_start'    => $hhmm($_POST['quiet_start'] ?? ''),
        'quiet_end'      => $hhmm($_POST['quiet_end'] ?? ''),
        'voice_rate'     => (string) max(0.6, min(1.6, (float) str_replace(',', '.', (string) ($_POST['voice_rate'] ?? '1.05')))),
        'volume'         => (string) max(0, min(100, (int) ($_POST['volume'] ?? 70))),
        'mute_minutes'   => (string) max(5, min(1440, (int) ($_POST['mute_minutes'] ?? 60))),
    ]);
    foreach (['groups', 'templates', 'version', 'proxies'] as $k) {
        Zabbix::forget($k);
    }
    Session::addMessageAfterRedirect('Configuração salva. Os alertas novos valem a partir da próxima consulta de cada navegador.', false, INFO);
    Html::redirect("$self?tab=$tab");
}

// ------------------------------------------------------------------ teste da conexão
$test = null;
if (isset($_POST['test'])) {
    $tab = 'conexao';
    $test = [];
    try {
        foreach (['version'] as $k) {
            Zabbix::forget($k);
        }
        $test[] = ['ok', 'Versão da API do Zabbix: ' . Zabbix::call('apiinfo.version')];
        $groups = Zabbix::call('hostgroup.get', ['output' => ['groupid']]);
        $test[] = ['ok', 'Token aceito: a conta de serviço enxerga ' . count($groups) . ' grupo(s) de hosts.'];
        try {
            $me = Zabbix::call('user.checkAuthentication', ['token' => Settings::get('zabbix_token')]);
            $type = (int) ($me['type'] ?? 0);
            $test[] = [$type >= 2 ? 'ok' : 'warn', 'Usuário ' . ($me['username'] ?? $me['alias'] ?? '?') . ' · tipo ' . ([1 => 'Usuário', 2 => 'Admin', 3 => 'Super admin'][$type] ?? $type)
                . ($type >= 2 ? '' : ': para incluir, editar e excluir hosts o papel precisa ser do tipo Admin, com escrita nos grupos do departamento.') . ($type === 3 ? ' (super admin alcança tudo: prefira um Admin só com os grupos do departamento).' : '')];
        } catch (Throwable) {
            $test[] = ['warn', 'Não foi possível ler o tipo do usuário da conta de serviço (Zabbix anterior ao 6.4?).'];
        }
        $dept = Settings::ids('groups');
        if ($dept) {
            $n = Zabbix::call('host.get', ['groupids' => $dept, 'countOutput' => true]);
            $test[] = ['ok', "$n host(s) nos grupos do departamento."];
        }
    } catch (Throwable $ex) {
        $test[] = ['bad', $ex->getMessage()];
    }
}

Html::header('Configurações · Sistemas Internos 360', '', Menu::SECTOR, 'config');
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$c = Settings::all();
$check = fn ($v) => $v ? ' checked' : '';
// sem conexão com o Zabbix: as listas não aparecem, mas os valores gravados são mantidos
$hidden = fn (string $name, array $values) => implode('', array_map(fn ($v) => "<input type='hidden' name='{$name}[]' value='" . $e($v) . "'>", $values));
echo Menu::open('Configurações', 'config');

$groups = $templates = [];
$zerr = '';
if (Settings::isConfigured()) {
    try {
        $groups = Zabbix::allGroups();
        $templates = Zabbix::allTemplates();
    } catch (Throwable $ex) {
        $zerr = $ex->getMessage();
    }
}

$tabs = ['conexao' => ['Conexão com o Zabbix', 'ti-plug-connected'], 'departamento' => ['Departamento', 'ti-building-community'], 'hosts' => ['Cadastro de hosts', 'ti-server-2'],
    'alertas' => ['Alertas', 'ti-alert-hexagon'], 'notificacoes' => ['Notificações', 'ti-bell-ringing']];
echo "<form method='post' action='" . $e($self) . "' class='card si360-card' style='max-width:1180px'><input type='hidden' name='tab' value='" . $e($tab) . "' data-si-tabinput>";
echo "<div class='card-header'><ul class='nav nav-tabs card-header-tabs' data-si-tabs>";
foreach ($tabs as $k => [$label, $icon]) {
    echo "<li class='nav-item'><a href='#si-tab-$k' class='nav-link" . ($k === $tab ? ' active' : '') . "' data-si-tab='$k'><i class='ti $icon me-1'></i>$label</a></li>";
}
echo "</ul></div><div class='card-body'>";

// ------------------------------------------------------------------ conexão
echo "<div class='si360-tab' id='si-tab-conexao'" . ($tab === 'conexao' ? '' : ' hidden') . ">";
if ($test) {
    echo "<div class='si360-test mb-3'>" . implode('', array_map(fn ($t) => "<div class='si360-test-row {$t[0]}'><i class='ti " . ['ok' => 'ti-circle-check', 'warn' => 'ti-alert-triangle', 'bad' => 'ti-circle-x'][$t[0]] . " me-2'></i>" . $e($t[1]) . "</div>", $test)) . "</div>";
}
if ($zerr) {
    echo Menu::error($zerr);
}
echo "<div class='row g-3'>"
    . "<div class='col-md-8'><label class='form-label'>Endereço da API do Zabbix</label><input class='form-control' name='zabbix_url' value='" . $e($c['zabbix_url']) . "' placeholder='https://zabbix.empresa.com.br/zabbix/api_jsonrpc.php'></div>"
    . "<div class='col-md-4 d-flex align-items-end'><label class='form-check form-switch mb-2'><input class='form-check-input' type='checkbox' name='zabbix_verify' value='1'" . $check($c['zabbix_verify'] !== '0') . "><span class='form-check-label'>Validar o certificado HTTPS</span></label></div>"
    . "<div class='col-md-8'><label class='form-label'>Token da conta de serviço</label><input type='password' class='form-control' name='zabbix_token' autocomplete='new-password' placeholder='" . ($c['zabbix_token'] !== '' ? '•••••••• (em branco = manter o atual)' : 'cole o token gerado no Zabbix') . "'>"
    . "<div class='form-hint'>Guardado criptografado no GLPI e nunca enviado ao navegador.</div></div>"
    . "<div class='col-md-4 d-flex align-items-end'><button class='btn btn-outline-primary' name='test' value='1' formnovalidate><i class='ti ti-plug-connected me-1'></i>Testar a conexão</button></div>"
    . "</div>";
echo "<div class='si360-howto mt-3'><b><i class='ti ti-list-check me-1'></i>Como criar a conta de serviço no Zabbix</b><ol class='mb-0 mt-1'>"
    . "<li><b>Usuários → Grupos de usuários → Criar</b>: <code>glpi-sistemas-internos</code>, sem acesso ao frontend, com <b>Leitura e escrita</b> só nos hostgroups do departamento e <b>Leitura</b> nos grupos de templates.</li>"
    . "<li><b>Usuários → Papéis</b>: um papel do tipo <b>Admin</b> com acesso à API (pode limitar aos métodos <code>host.*</code>, <code>hostgroup.get</code>, <code>template.get</code>, <code>usermacro.*</code>, <code>problem.get</code>, <code>trigger.get</code>, <code>item.get</code>, <code>proxy.get</code>, <code>user.checkAuthentication</code>).</li>"
    . "<li><b>Usuários → Usuários → Criar</b>: <code>glpi-si360</code>, no grupo e com o papel acima.</li>"
    . "<li><b>Usuários → Tokens de API → Criar</b> para esse usuário e cole o token aqui. Teste a conexão.</li></ol>"
    . "<div class='mt-1 text-secondary'>Assim, mesmo um erro no GLPI não alcança outros departamentos: o Zabbix recusa.</div></div>";
echo "</div>";

// ------------------------------------------------------------------ departamento
echo "<div class='si360-tab' id='si-tab-departamento'" . ($tab === 'departamento' ? '' : ' hidden') . "><div class='row g-3'>"
    . "<div class='col-md-6'><label class='form-label'>Nome do departamento</label><input class='form-control' name='title' value='" . $e($c['title']) . "'></div>"
    . "<div class='col-md-6'><label class='form-label'>Entidade do GLPI (inclui as filhas)</label>" . Entity::dropdown(['name' => 'entities_id', 'value' => (int) $c['entities_id'], 'display' => false, 'width' => '100%', 'entity' => -1]) . "</div>"
    . "<div class='col-12'><label class='form-label'>Grupos do Zabbix do departamento</label>"
    . ($groups ? Dropdown::showFromArray('groups', $groups, ['multiple' => true, 'values' => Settings::ids('groups'), 'display' => false, 'width' => '100%'])
        : "<input class='form-control' value='" . $e($c['groups']) . "' disabled>" . $hidden('groups', Settings::ids('groups')))
    . "<div class='form-hint'>Somados no painel e nos alertas. Também são os grupos que os perfis podem gerenciar, a menos que o perfil tenha grupos próprios (Administração → Perfis → aba Sistemas Internos 360).</div></div>"
    . "<div class='col-12'><label class='form-label'>Prestadores de serviço</label><input class='form-control' name='prestadores' value='" . $e($c['prestadores']) . "' placeholder='Nome do técnico, Outro técnico'>"
    . "<div class='form-hint'>Técnicos do GLPI (separados por vírgula) cujos chamados ficam fora do SLA do departamento e não disparam alerta de SLA.</div></div>"
    . "<div class='col-md-6'><label class='form-label'>Acionamento: quem atende</label><input class='form-control' name='escalation_name' value='" . $e($c['escalation_name']) . "' placeholder='Nome do responsável'></div>"
    . "<div class='col-md-6'><label class='form-label'>Acionamento: telefone</label><input class='form-control' name='escalation_phone' value='" . $e($c['escalation_phone']) . "' placeholder='11 99999-9999'>"
    . "<div class='form-hint'>Na Visão 360, alertas Alta e Desastre pedem para acionar esta pessoa. Em branco, sem acionamento.</div></div>"
    . "<div class='col-md-6'><label class='form-label'>Cor do dashboard</label><select class='form-select' name='si_theme'>"
    . "<option value='azul'" . ($c['si_theme'] !== 'original' ? ' selected' : '') . ">Azul do departamento (#3769B2), árvore e marca-d'água em azul</option>"
    . "<option value='original'" . ($c['si_theme'] === 'original' ? ' selected' : '') . ">Original (verde), como o SI 360 antes do GLPI</option></select>"
    . "<div class='form-hint'>Só a cor muda: o layout e as funções são os mesmos.</div></div>"
    . "</div></div>";

// ------------------------------------------------------------------ cadastro de hosts
echo "<div class='si360-tab' id='si-tab-hosts'" . ($tab === 'hosts' ? '' : ' hidden') . "><div class='row g-3'>"
    . "<div class='col-12'><label class='form-label'>Templates permitidos no cadastro</label>"
    . ($templates ? Dropdown::showFromArray('templates', $templates, ['multiple' => true, 'values' => Settings::ids('templates'), 'display' => false, 'width' => '100%'])
        : $hidden('templates', Settings::ids('templates')) . "<div class='text-secondary'>Configure a conexão para escolher os templates.</div>")
    . "<div class='form-hint'>Vazio = todos os templates que a conta de serviço enxerga. Templates de fora da lista já vinculados a um host são mantidos.</div></div>"
    . "<div class='col-md-6'><label class='form-label'>Tag automática</label><input class='form-control' name='auto_tag' value='" . $e($c['auto_tag']) . "' placeholder='departamento=Sistemas Internos'>"
    . "<div class='form-hint'>Todo host incluído ou editado pelo GLPI recebe esta tag (formato <code>tag=valor</code>). Vazio = nenhuma.</div></div>"
    . "<div class='col-md-6 d-flex align-items-end'><label class='form-check form-switch mb-2'><input class='form-check-input' type='checkbox' name='require_desc' value='1'" . $check($c['require_desc'] === '1') . "><span class='form-check-label'>Exigir descrição do host</span></label></div>"
    . "<div class='col-12'><div class='si360-howto'><b><i class='ti ti-shield-lock me-1'></i>Quem pode o quê</b><ul class='mb-0 mt-1'>"
    . "<li><b>Ver</b>, <b>Incluir</b>, <b>Editar</b> (também ativar e desativar) e <b>Excluir definitivamente</b> são direitos do perfil: Administração → Perfis → aba <i>Sistemas Internos 360</i>.</li>"
    . "<li>Só aparecem os hosts dos grupos liberados para o perfil. Desativar e excluir exigem que <b>todos</b> os grupos do host sejam do perfil, para não afetar outros departamentos.</li>"
    . "<li>Excluir pede para digitar o nome técnico do host. Tudo fica na <b>Auditoria dos hosts</b>, com o antes e o depois.</li></ul></div></div>"
    . "</div></div>";

// ------------------------------------------------------------------ alertas (matriz)
$alerts = Settings::alerts();
echo "<div class='si360-tab' id='si-tab-alertas'" . ($tab === 'alertas' ? '' : ' hidden') . ">"
    . "<p class='text-secondary'>Para cada tipo de alerta, escolha como ele chega: <b>sinalizador</b> no topo da tela (contador com o que pede atenção), <b>som</b>, <b>voz</b> e <b>pop-up</b> no rodapé da página. "
    . "<i>Fica até fechar</i> mantém o pop-up na tela até alguém fechar. Use <b>Testar</b> para ver e ouvir.</p>"
    . "<div class='table-responsive'><table class='table table-vcenter table-sm si360-matrix'><thead><tr><th>Alerta</th>";
foreach (Settings::CHANNELS as [$label, $icon]) {
    echo "<th class='text-center'><i class='ti $icon d-block mb-1'></i>$label</th>";
}
echo "<th>Som</th><th></th></tr></thead><tbody>";
$lastGroup = '';
foreach (Settings::ALERTS as $key => [$label, $group, $icon, $color]) {
    if ($group !== $lastGroup) {
        echo "<tr class='si360-matrix-group'><td colspan='" . (count(Settings::CHANNELS) + 3) . "'>" . $e($group) . "</td></tr>";
        $lastGroup = $group;
    }
    echo "<tr><td><span class='si360-alert-ico' style='--c:$color'><i class='ti $icon'></i></span>" . $e($label) . "</td>";
    foreach (array_keys(Settings::CHANNELS) as $ch) {
        echo "<td class='text-center'><input class='form-check-input' type='checkbox' name='alert[$key][$ch]' value='1'" . $check(in_array($ch, $alerts[$key]['channels'], true)) . "></td>";
    }
    echo "<td>" . Dropdown::showFromArray("tone[$key]", Settings::TONES, ['value' => $alerts[$key]['tone'], 'display' => false, 'width' => '140px']) . "</td>"
        . "<td><button type='button' class='btn btn-sm btn-ghost-primary' data-si-test='$key'><i class='ti ti-player-play me-1'></i>Testar</button></td></tr>";
}
echo "</tbody></table></div><div class='form-hint'>Desligue <i>Ativo</i> para ignorar o tipo por completo. O sinalizador conta só problemas ainda ativos e chamados com SLA vencendo ou vencido.</div></div>";

// ------------------------------------------------------------------ notificações
echo "<div class='si360-tab' id='si-tab-notificacoes'" . ($tab === 'notificacoes' ? '' : ' hidden') . "><div class='row g-3'>"
    . "<div class='col-md-4'><label class='form-label'>Procurar alertas novos a cada</label><div class='input-group'><input type='number' min='15' max='600' class='form-control' name='poll_seconds' value='" . (int) $c['poll_seconds'] . "'><span class='input-group-text'>segundos</span></div></div>"
    . "<div class='col-md-4'><label class='form-label'>Pop-up some depois de</label><div class='input-group'><input type='number' min='5' max='300' class='form-control' name='popup_seconds' value='" . (int) $c['popup_seconds'] . "'><span class='input-group-text'>segundos</span></div>"
    . "<div class='form-hint'>Menos os marcados como <i>Fica até fechar</i>.</div></div>"
    . "<div class='col-md-4'><label class='form-label'>Pop-ups ao mesmo tempo</label><input type='number' min='1' max='10' class='form-control' name='popup_max' value='" . (int) $c['popup_max'] . "'></div>"
    . "<div class='col-md-4'><label class='form-label'>Posição do pop-up no rodapé</label><select class='form-select' name='popup_position'><option value='right'" . ($c['popup_position'] !== 'left' ? ' selected' : '') . ">Canto inferior direito</option><option value='left'" . ($c['popup_position'] === 'left' ? ' selected' : '') . ">Canto inferior esquerdo</option></select></div>"
    . "<div class='col-md-4'><label class='form-label'>Volume do som</label><input type='range' class='form-range' min='0' max='100' name='volume' value='" . (int) $c['volume'] . "'></div>"
    . "<div class='col-md-4'><label class='form-label'>Velocidade da voz</label><input type='number' step='0.05' min='0.6' max='1.6' class='form-control' name='voice_rate' value='" . $e($c['voice_rate']) . "'></div>"
    . "<div class='col-md-4'><label class='form-label'>Silêncio (sem som e voz)</label><div class='d-flex gap-2 align-items-center'><input type='time' class='form-control' name='quiet_start' value='" . $e($c['quiet_start']) . "'><span class='text-secondary'>às</span><input type='time' class='form-control' name='quiet_end' value='" . $e($c['quiet_end']) . "'></div>"
    . "<div class='form-hint'>Ex.: 22:00 às 06:00. O pop-up e o sinalizador continuam.</div></div>"
    . "<div class='col-md-4'><label class='form-label'>Botão \"Silenciar\" do sinalizador</label><div class='input-group'><input type='number' min='5' max='1440' class='form-control' name='mute_minutes' value='" . (int) $c['mute_minutes'] . "'><span class='input-group-text'>minutos</span></div></div>"
    . "<div class='col-md-4 d-flex align-items-end'><label class='form-check form-switch mb-2'><input class='form-check-input' type='checkbox' name='everywhere' value='1'" . $check($c['everywhere'] !== '0') . "><span class='form-check-label'>Alertar em todas as telas do GLPI</span></label></div>"
    . "<div class='col-12'><div class='si360-howto'><b><i class='ti ti-users me-1'></i>Quem recebe</b><div class='mt-1'>Todos os perfis com o direito <i>Painel 360 e alertas</i> (Administração → Perfis → aba Sistemas Internos 360). "
    . "Cada pessoa só recebe o que é novo desde a última consulta do navegador dela e pode silenciar som e voz pelo sinalizador. "
    . "Navegadores só tocam som depois do primeiro clique na página: até lá, o sinalizador pisca.</div></div></div>"
    . "</div></div>";

echo "</div><div class='card-footer d-flex gap-2'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div>";
Html::closeForm();
echo Menu::close();
echo Html::script(Menu::url('/js/painel.js'), ['version' => Menu::fileVersion('js/painel.js')], false);
Html::footer();
