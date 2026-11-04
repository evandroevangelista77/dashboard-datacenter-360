<?php

/**
 * Configuração do NOC 360 (Configurar → Plugins → NOC 360), com as mesmas opções do Operacional 360:
 * conexão com o Zabbix, mapa entidade → host group → severidades, painel (severidades, entidade raiz,
 * quem vê, metas de solução, limites e cache) e alertas do navegador. Só para quem pode configurar o GLPI.
 *
 * A tela grava /var/lib/datacenter360/config.json, que o painel lê por cima do config.php do instalador.
 */

use GlpiPlugin\Datacenter360\Settings;

Session::checkRight('config', UPDATE);

/** Host groups do Zabbix (id => nome) ou null quando o Zabbix não responde. */
function dc360_hostgroups(): ?array
{
    try {
        $out = [];
        foreach (Settings::zabbix('hostgroup.get', ['output' => ['groupid', 'name'], 'sortfield' => 'name']) ?? [] as $g) {
            $out[(string) $g['groupid']] = (string) $g['name'];
        }
        return $out;
    } catch (Throwable) {
        return null;
    }
}

/** Entidades do GLPI (id => nome completo) da entidade raiz para baixo. */
function dc360_entities(): array
{
    global $DB;
    $out = [];
    foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'ORDER' => 'completename']) as $r) {
        if (Settings::inEntityRoot((string) $r['completename'])) {
            $out[(int) $r['id']] = (string) $r['completename'];
        }
    }
    return $out;
}

if (isset($_POST['save'])) {
    Session::checkCSRF($_POST);

    $sevs = array_values(array_unique(array_map('intval', (array) ($_POST['severities'] ?? []))));
    $values = [
        'zabbix_url'         => trim((string) ($_POST['zabbix_url'] ?? '')),
        'zabbix_token'       => trim((string) ($_POST['zabbix_token'] ?? '')),
        'zabbix_verify'      => isset($_POST['zabbix_verify']),
        'zabbix_front'       => rtrim(trim((string) ($_POST['zabbix_front'] ?? '')), '/'),
        'glpi_front'         => rtrim(trim((string) ($_POST['glpi_front'] ?? '')), '/'),
        'severities'         => $sevs ?: [5, 4, 2],
        'sla_hours'          => Settings::parseSlaHours((string) ($_POST['sla_hours'] ?? '')) ?: [6 => 1, 5 => 2, 4 => 4, 3 => 8, 2 => 24, 1 => 48],
        'list_max'           => max(1, min(5000, (int) ($_POST['list_max'] ?? 400))),
        'glpi_max_tickets'   => max(100, min(50000, (int) ($_POST['glpi_max_tickets'] ?? 8000))),
        'cache_ttl'          => max(0, min(600, (int) ($_POST['cache_seconds'] ?? 60))),
        'glpi_entity_prefix' => trim((string) ($_POST['entity_root'] ?? '')),
        'viewers'            => array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['viewers'] ?? []))))),
    ];

    // mapa: uma linha por entidade × host group, com as severidades que aparecem para ele
    $names = dc360_hostgroups() ?? [];
    $entities = dc360_entities();
    $map = [];
    foreach ((array) ($_POST['map'] ?? []) as $row) {
        if (!empty($row['delete'])) {
            continue;
        }
        $eid = (int) ($row['entity_id'] ?? 0);
        $gid = trim((string) ($row['group_id'] ?? ''));
        if ($eid <= 0 || !ctype_digit($gid) || !isset($entities[$eid])) {
            continue;
        }
        $map[] = [
            'entity_id'   => $eid,
            'entity_name' => $entities[$eid],
            'group_id'    => $gid,
            'group_name'  => $names[$gid] ?? mb_substr(trim((string) ($row['group_name'] ?? '')), 0, 255),
            'severities'  => array_values(array_map('intval', (array) ($row['sev'] ?? []))),
        ];
    }
    $values['mapa_entidades'] = $map;

    // alertas do navegador: canais por tipo e preferências gerais
    $current = Settings::alerts();
    $tipos = [];
    foreach (Settings::ALERT_TYPES as $key => [, , , , , $tone]) {
        $tipos[$key] = [
            'channels' => array_values(array_intersect(array_keys(Settings::CHANNELS), (array) ($_POST['alert'][$key] ?? []))),
            'tone'     => $current['tipos'][$key]['tone'] ?? $tone,
        ];
    }
    $values['alertas'] = [
        'tipos'            => $tipos,
        'poll'             => max(10, min(300, (int) ($_POST['alerts_poll'] ?? 20))),
        'volume'           => max(0, min(100, (int) ($_POST['alerts_volume'] ?? 80))),
        'voice_rate'       => max(0.5, min(2.0, (float) str_replace(',', '.', (string) ($_POST['alerts_voice_rate'] ?? '1.05')))),
        'popup_seconds'    => max(5, min(120, (int) ($_POST['alerts_popup_seconds'] ?? 20))),
        'popup_max'        => max(1, min(20, (int) ($_POST['alerts_popup_max'] ?? 5))),
        'mute_minutes'     => max(1, min(1440, (int) ($_POST['alerts_mute_minutes'] ?? 60))),
        'sla_warn_minutes' => max(5, min(1440, (int) ($_POST['alerts_sla_warn_minutes'] ?? 60))),
        'quiet'            => $current['quiet'],
    ];

    try {
        Settings::save($values);
        Session::addMessageAfterRedirect('Configuração do NOC 360 salva. O painel usa os novos valores em até 1 minuto.', false, INFO);
    } catch (Throwable $ex) {
        Session::addMessageAfterRedirect($ex->getMessage(), false, ERROR);
    }
    Html::back();
}

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$sev = Settings::severities();
$groups = dc360_hostgroups();
$alerts = Settings::alerts();
$viewers = Settings::viewerIds();
global $DB;
$users = [];
foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_deleted' => 0, 'is_active' => 1], 'ORDER' => 'name']) as $u) {
    $full = trim((string) $u['firstname'] . ' ' . (string) $u['realname']);
    $users[(int) $u['id']] = $u['name'] . ($full !== '' ? " ($full)" : '');
}
$entities = dc360_entities();
$dirOk = is_dir(dirname(Settings::FILE)) && is_writable(dirname(Settings::FILE));

// Conexão com o Zabbix (teste de leitura pela versão da API)
$zbxStatus = '';
if (Settings::isConfigured()) {
    try {
        $zbxStatus = '<span class="text-success"><i class="ti ti-circle-check me-1"></i>Conectado à API do Zabbix, versão ' . $e(Settings::zabbix('apiinfo.version')) . '.</span>';
    } catch (Throwable $ex) {
        $zbxStatus = '<span class="text-danger"><i class="ti ti-alert-circle me-1"></i>' . $e($ex->getMessage()) . '</span>';
    }
}

Html::header('NOC 360 — configuração', $_SERVER['PHP_SELF'], 'config', 'plugins');
?>
<form method="post" action="<?= $e($_SERVER['PHP_SELF']) ?>" class="card">
  <input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>">
  <div class="card-header"><h3 class="card-title"><i class="ti ti-building-broadcast-tower me-1"></i>NOC 360 · configuração</h3></div>
  <div class="card-body">
    <?php if (!$dirOk): ?>
      <div class="alert alert-danger small">A pasta <code><?= $e(dirname(Settings::FILE)) ?></code> não existe ou não é gravável pelo servidor web: esta tela não consegue salvar. Rode o instalador do painel (<code>instalar.sh</code>) para criá-la.</div>
    <?php endif; ?>

    <h4 class="mt-2">1. Conexão com o Zabbix</h4>
    <p class="text-secondary small">Use uma conta de serviço do Zabbix com <b>somente leitura</b> nos host groups do mapa. O token fica em arquivo no servidor, fora da pasta web, e nunca vai para o navegador. Em branco, vale o que está no <code>config.php</code> do instalador.</p>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Endereço da API (api_jsonrpc.php)</label>
        <input class="form-control" name="zabbix_url" value="<?= $e(Settings::get('zabbix_url', '')) ?>" placeholder="https://zabbix.exemplo.com/api_jsonrpc.php"></div>
      <div class="col-md-6"><label class="form-label">Token da API</label>
        <input class="form-control" type="password" name="zabbix_token" value="" autocomplete="new-password"
               placeholder="<?= Settings::hasToken() ? 'já salvo: em branco mantém' : 'cole o token' ?>"></div>
      <div class="col-md-6"><label class="form-label">Endereço do Zabbix para os links <span class="text-secondary">(opcional)</span></label>
        <input class="form-control" name="zabbix_front" value="<?= $e(Settings::get('zabbix_front', '')) ?>" placeholder="https://zabbix.exemplo.com"></div>
      <div class="col-md-6"><label class="form-label">Endereço do GLPI para os links <span class="text-secondary">(opcional)</span></label>
        <input class="form-control" name="glpi_front" value="<?= $e(Settings::get('glpi_front', '')) ?>" placeholder="https://glpi.exemplo.com"></div>
      <div class="col-12">
        <label class="form-check"><input class="form-check-input" type="checkbox" name="zabbix_verify" value="1" <?= Settings::get('zabbix_verify', true) !== false && Settings::get('zabbix_verify', true) !== '0' ? 'checked' : '' ?>>
          <span class="form-check-label">Verificar o certificado HTTPS do Zabbix</span></label>
        <div class="mt-2"><?= $zbxStatus ?: '<span class="text-secondary">Salve a conexão para testar.</span>' ?></div>
      </div>
    </div>

    <hr class="my-4">
    <h4>2. Mapa: entidade → host group → severidades</h4>
    <p class="text-secondary small">Cada linha liga uma entidade do GLPI (o cliente) a um host group do Zabbix e diz quais severidades aparecem no painel para aquele host group. Um host group pode estar em mais de uma entidade. Hosts de grupos sem linha aqui continuam sendo ligados ao cliente pelo nome do grupo, com as severidades do painel (seção 3).</p>
    <?php if ($groups === null): ?>
      <div class="alert alert-warning small">Não foi possível listar os host groups do Zabbix. Salve a conexão e volte a esta tela; até lá, informe o ID do host group.</div>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm table-vcenter">
        <thead><tr>
          <th>Entidade (GLPI)</th><th>Host group (Zabbix)</th>
          <?php foreach ($sev as $s): ?><th class="text-center"><?= $e(Settings::SEVERITY_LABELS[$s] ?? $s) ?></th><?php endforeach; ?>
          <th class="text-center">Remover</th>
        </tr></thead>
        <tbody>
        <?php
        $rows = Settings::mapping();
        for ($i = 0; $i < count($rows) + 4; $i++):
            $r = $rows[$i] ?? ['entity_id' => 0, 'group_id' => '', 'group_name' => '', 'severities' => $sev];
        ?>
          <tr>
            <td style="min-width:240px">
              <select class="form-select form-select-sm" name="map[<?= $i ?>][entity_id]">
                <option value="0">— escolha a entidade —</option>
                <?php foreach ($entities as $id => $name): ?>
                  <option value="<?= (int) $id ?>" <?= (int) $id === (int) $r['entity_id'] ? 'selected' : '' ?>><?= $e($name) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td style="min-width:240px">
              <?php if ($groups !== null): ?>
                <select class="form-select form-select-sm" name="map[<?= $i ?>][group_id]">
                  <option value="">— escolha o host group —</option>
                  <?php foreach ($groups as $gid => $gname): ?>
                    <option value="<?= $e($gid) ?>" <?= (string) $gid === (string) $r['group_id'] ? 'selected' : '' ?>><?= $e($gname) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input class="form-control form-control-sm" name="map[<?= $i ?>][group_id]" value="<?= $e($r['group_id']) ?>" placeholder="ID do host group">
                <input type="hidden" name="map[<?= $i ?>][group_name]" value="<?= $e($r['group_name']) ?>">
              <?php endif; ?>
            </td>
            <?php foreach ($sev as $s): ?>
              <td class="text-center"><input class="form-check-input" type="checkbox" name="map[<?= $i ?>][sev][]" value="<?= $s ?>" <?= in_array($s, $r['severities'], true) ? 'checked' : '' ?>></td>
            <?php endforeach; ?>
            <td class="text-center"><input class="form-check-input" type="checkbox" name="map[<?= $i ?>][delete]" value="1"></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <p class="text-secondary small">Para incluir mais linhas, salve: as linhas em branco aparecem de novo.</p>

    <hr class="my-4">
    <h4>3. Painel</h4>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Severidades exibidas nos cartões</label>
        <div class="d-flex flex-wrap gap-3">
          <?php foreach (Settings::SEVERITY_LABELS as $s => $label): ?>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="severities[]" value="<?= $s ?>" <?= in_array($s, $sev, true) ? 'checked' : '' ?>>
              <span class="form-check-label"><?= $e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="form-hint">Até três severidades têm cartão próprio no painel (ex.: Desastre, Alta e Média). O "Sem ACK" conta só Desastre e Alta.</div>
      </div>
      <div class="col-md-6"><label class="form-label">Entidade raiz do painel</label>
        <input class="form-control" name="entity_root" value="<?= $e(Settings::entityRoot()) ?>" placeholder="OAKMONT > TI > NOC">
        <div class="form-hint">Só esta entidade e as abaixo dela aparecem nos chamados e no mapa (ex.: OAKMONT &gt; TI &gt; NOC).</div></div>
      <div class="col-md-12"><label class="form-label">Quem pode ver o painel</label>
        <select class="form-select" name="viewers[]" multiple size="6">
          <?php foreach ($users as $uid => $uname): ?>
            <option value="<?= (int) $uid ?>" <?= in_array($uid, $viewers, true) ? 'selected' : '' ?>><?= $e($uname) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Só os usuários marcados veem o item do menu NOC e as telas do NOC 360 dentro do GLPI. Sem nenhum marcado, vale o direito do perfil (todos os perfis com acesso). Quem configura o GLPI continua podendo abrir tudo. O endereço direto do painel (<code>/datacenter360/</code>) segue a restrição de rede do Apache.</div></div>
      <div class="col-md-6"><label class="form-label">Metas de solução por prioridade (horas)</label>
        <input class="form-control" name="sla_hours" value="<?= $e(Settings::slaHoursText()) ?>">
        <div class="form-hint">Formato prioridade:horas, ex.: 6:1,5:2,4:4,3:8,2:24,1:48 (6 = Crítica). Vale para chamados sem prazo de solução do GLPI.</div></div>
      <div class="col-md-2"><label class="form-label">Itens nas listas</label>
        <input class="form-control" type="number" min="1" max="5000" name="list_max" value="<?= $e(Settings::get('list_max', 400)) ?>"></div>
      <div class="col-md-2"><label class="form-label">Máximo de chamados lidos</label>
        <input class="form-control" type="number" min="100" max="50000" name="glpi_max_tickets" value="<?= $e(Settings::get('glpi_max_tickets', 8000)) ?>"></div>
      <div class="col-md-2"><label class="form-label">Cache do Zabbix (s)</label>
        <input class="form-control" type="number" min="0" max="600" name="cache_seconds" value="<?= $e(Settings::get('cache_ttl', 60)) ?>"></div>
    </div>

    <hr class="my-4">
    <h4>4. Alertas do navegador</h4>
    <p class="text-secondary small">Canais de cada tipo de alerta. Os alertas aparecem só dentro do painel NOC 360.</p>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>Tipo</th>
          <?php foreach (Settings::CHANNELS as $ch => $label): ?><th class="text-center"><?= $e($label) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach (Settings::ALERT_TYPES as $key => [$label, $grupo]): ?>
          <tr><td><?= $e($label) ?> <span class="text-secondary small">· <?= $e($grupo) ?></span></td>
            <?php foreach (Settings::CHANNELS as $ch => $_label): ?>
              <td class="text-center"><input class="form-check-input" type="checkbox" name="alert[<?= $e($key) ?>][]" value="<?= $e($ch) ?>"
                  <?= in_array($ch, $alerts['tipos'][$key]['channels'], true) ? 'checked' : '' ?>></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label">Atualizar a cada (s)</label>
        <input class="form-control" type="number" min="10" max="300" name="alerts_poll" value="<?= (int) $alerts['poll'] ?>"></div>
      <div class="col-md-3"><label class="form-label">Volume (0 a 100)</label>
        <input class="form-control" type="number" min="0" max="100" name="alerts_volume" value="<?= (int) $alerts['volume'] ?>"></div>
      <div class="col-md-3"><label class="form-label">Velocidade da voz</label>
        <input class="form-control" name="alerts_voice_rate" value="<?= $e($alerts['voice_rate']) ?>"></div>
      <div class="col-md-3"><label class="form-label">Pop-up: segundos</label>
        <input class="form-control" type="number" min="5" max="120" name="alerts_popup_seconds" value="<?= (int) $alerts['popup_seconds'] ?>"></div>
      <div class="col-md-3"><label class="form-label">Pop-up: máximo na tela</label>
        <input class="form-control" type="number" min="1" max="20" name="alerts_popup_max" value="<?= (int) $alerts['popup_max'] ?>"></div>
      <div class="col-md-3"><label class="form-label">Silenciar por (min)</label>
        <input class="form-control" type="number" min="1" max="1440" name="alerts_mute_minutes" value="<?= (int) $alerts['mute_minutes'] ?>"></div>
      <div class="col-md-3"><label class="form-label">SLA: avisar com antecedência (min)</label>
        <input class="form-control" type="number" min="5" max="1440" name="alerts_sla_warn_minutes" value="<?= (int) $alerts['sla_warn_minutes'] ?>"></div>
    </div>
  </div>
  <div class="card-footer d-flex justify-content-end">
    <button type="submit" name="save" value="1" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Salvar</button>
  </div>
</form>
<?php
Html::footer();
