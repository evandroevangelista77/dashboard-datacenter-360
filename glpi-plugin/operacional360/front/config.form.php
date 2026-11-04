<?php

/**
 * Configuração do Operacional 360 (Configurar → Plugins → Operacional 360).
 * Só para quem pode configurar o GLPI (administrador): conexão com o Zabbix, severidades,
 * metas de solução, o mapa entidade → host group → severidades e os alertas do navegador.
 */

use GlpiPlugin\Operacional360\Glpi;
use GlpiPlugin\Operacional360\Settings;
use GlpiPlugin\Operacional360\Zabbix;

Session::checkRight('config', UPDATE);

/** Host groups do Zabbix (id => nome) ou null quando o Zabbix não responde. */
function op360_hostgroups(): ?array
{
    try {
        $out = [];
        foreach (Zabbix::call('hostgroup.get', ['output' => ['groupid', 'name'], 'sortfield' => 'name']) ?? [] as $g) {
            $out[(string) $g['groupid']] = (string) $g['name'];
        }
        return $out;
    } catch (Throwable) {
        return null;
    }
}

if (isset($_POST['save'])) {
    Session::checkCSRF($_POST);

    $values = [
        'zabbix_url'       => trim((string) ($_POST['zabbix_url'] ?? '')),
        'zabbix_token'     => trim((string) ($_POST['zabbix_token'] ?? '')),
        'zabbix_verify'    => isset($_POST['zabbix_verify']) ? '1' : '0',
        'zabbix_front'     => trim((string) ($_POST['zabbix_front'] ?? '')),
        'glpi_front'       => trim((string) ($_POST['glpi_front'] ?? '')),
        'severities'       => implode(',', array_map('intval', (array) ($_POST['severities'] ?? []))) ?: '5,4,2',
        'sla_hours'        => trim((string) ($_POST['sla_hours'] ?? '')),
        'list_max'         => (string) max(1, min(5000, (int) ($_POST['list_max'] ?? 400))),
        'glpi_max_tickets' => (string) max(100, min(50000, (int) ($_POST['glpi_max_tickets'] ?? 8000))),
        'cache_seconds'    => (string) max(0, min(600, (int) ($_POST['cache_seconds'] ?? 20))),
        'entity_root'      => trim((string) ($_POST['entity_root'] ?? '')) ?: 'Root > NOC',
    ];

    // mapa: uma linha por entidade × host group, com as severidades que aparecem para ele
    $names = op360_hostgroups() ?? [];
    $map = [];
    foreach ((array) ($_POST['map'] ?? []) as $row) {
        if (!empty($row['delete'])) {
            continue;
        }
        $eid = (int) ($row['entity_id'] ?? 0);
        $gid = trim((string) ($row['group_id'] ?? ''));
        if ($eid <= 0 || !ctype_digit($gid) || !Settings::inEntityRoot(Glpi::entityName($eid))) {
            continue;
        }
        $map[] = [
            'entity_id'  => $eid,
            'group_id'   => $gid,
            'group_name' => $names[$gid] ?? mb_substr(trim((string) ($row['group_name'] ?? '')), 0, 255),
            'severities' => array_values(array_map('intval', (array) ($row['sev'] ?? []))),
        ];
    }
    $values['mapping'] = json_encode($map, JSON_UNESCAPED_UNICODE);

    // alertas do navegador: canais por tipo e preferências gerais
    $current = Settings::alerts();
    $tipos = [];
    foreach (Settings::ALERT_TYPES as $key => [, , , , , $tone]) {
        $tipos[$key] = [
            'channels' => array_values(array_intersect(array_keys(op360_channels()), (array) ($_POST['alert'][$key] ?? []))),
            'tone'     => $current['tipos'][$key]['tone'] ?? $tone,
        ];
    }
    $values['alerts'] = json_encode([
        'tipos'            => $tipos,
        'poll'             => max(10, min(300, (int) ($_POST['alerts_poll'] ?? 20))),
        'volume'           => max(0, min(100, (int) ($_POST['alerts_volume'] ?? 80))),
        'voice_rate'       => max(0.5, min(2.0, (float) str_replace(',', '.', (string) ($_POST['alerts_voice_rate'] ?? '1.05')))),
        'popup_seconds'    => max(5, min(120, (int) ($_POST['alerts_popup_seconds'] ?? 20))),
        'popup_max'        => max(1, min(20, (int) ($_POST['alerts_popup_max'] ?? 5))),
        'mute_minutes'     => max(1, min(1440, (int) ($_POST['alerts_mute_minutes'] ?? 60))),
        'sla_warn_minutes' => max(5, min(1440, (int) ($_POST['alerts_sla_warn_minutes'] ?? 60))),
        'quiet'            => $current['quiet'],
    ], JSON_UNESCAPED_UNICODE);

    Settings::save($values);
    Session::addMessageAfterRedirect('Configuração do Operacional 360 salva.', false, INFO);
    Html::back();
}

/** Canais disponíveis nos alertas do navegador. */
function op360_channels(): array
{
    return ['flag' => 'Sinalizador', 'sound' => 'Som', 'popup' => 'Pop-up', 'voice' => 'Voz', 'sticky' => 'Fica até fechar'];
}

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$cfg = Settings::all();
$sev = Settings::severities();
$groups = op360_hostgroups();
$alerts = Settings::alerts();
$entities = array_filter(Glpi::entities(), fn ($name) => Settings::inEntityRoot($name));
asort($entities, SORT_NATURAL | SORT_FLAG_CASE);

// Conexão com o Zabbix (teste de leitura pela versão da API)
$zbxStatus = '';
if (Settings::isConfigured()) {
    try {
        $zbxStatus = '<span class="text-success"><i class="ti ti-circle-check me-1"></i>Conectado à API do Zabbix, versão ' . $e(Zabbix::call('apiinfo.version')) . '.</span>';
    } catch (Throwable $ex) {
        $zbxStatus = '<span class="text-danger"><i class="ti ti-alert-circle me-1"></i>' . $e($ex->getMessage()) . '</span>';
    }
}

Html::header('Operacional 360 — configuração', $_SERVER['PHP_SELF'], 'config', 'plugins');
?>
<form method="post" action="<?= $e($_SERVER['PHP_SELF']) ?>" class="card">
  <input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>">
  <div class="card-header"><h3 class="card-title"><i class="ti ti-chart-dots-3 me-1"></i>Operacional 360 · configuração</h3></div>
  <div class="card-body">

    <h4 class="mt-2">1. Conexão com o Zabbix</h4>
    <p class="text-secondary small">Use uma conta de serviço do Zabbix com <b>somente leitura</b> nos host groups do mapa. O token fica cifrado no GLPI e nunca vai para o navegador.</p>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Endereço da API (api_jsonrpc.php)</label>
        <input class="form-control" name="zabbix_url" value="<?= $e($cfg['zabbix_url']) ?>" placeholder="https://zabbix.exemplo.com/api_jsonrpc.php"></div>
      <div class="col-md-6"><label class="form-label">Token da API</label>
        <input class="form-control" type="password" name="zabbix_token" value="" autocomplete="new-password"
               placeholder="<?= $cfg['zabbix_token'] !== '' ? 'já salvo: em branco mantém' : 'cole o token' ?>"></div>
      <div class="col-md-6"><label class="form-label">Endereço do Zabbix para os links <span class="text-secondary">(opcional)</span></label>
        <input class="form-control" name="zabbix_front" value="<?= $e($cfg['zabbix_front']) ?>" placeholder="https://zabbix.exemplo.com"></div>
      <div class="col-md-6"><label class="form-label">Endereço do GLPI para os links <span class="text-secondary">(opcional)</span></label>
        <input class="form-control" name="glpi_front" value="<?= $e($cfg['glpi_front']) ?>" placeholder="<?= $e(Settings::glpiFront()) ?>"></div>
      <div class="col-12">
        <label class="form-check"><input class="form-check-input" type="checkbox" name="zabbix_verify" value="1" <?= $cfg['zabbix_verify'] !== '0' ? 'checked' : '' ?>>
          <span class="form-check-label">Verificar o certificado HTTPS do Zabbix</span></label>
        <div class="mt-2"><?= $zbxStatus ?: '<span class="text-secondary">Salve a conexão para testar.</span>' ?></div>
      </div>
    </div>

    <hr class="my-4">
    <h4>2. Mapa: entidade → host group → severidades</h4>
    <p class="text-secondary small">Cada linha liga uma entidade do GLPI a um host group do Zabbix e diz quais severidades aparecem no painel dessa entidade para aquele host group. Um host group pode estar em mais de uma entidade. Os chamados vêm da entidade e das filhas.</p>
    <?php if ($groups === null): ?>
      <div class="alert alert-warning small">Não foi possível listar os host groups do Zabbix. Salve a conexão e volte a esta tela; até lá, informe o ID do host group.</div>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm table-vcenter">
        <thead><tr>
          <th>Entidade (GLPI)</th><th>Host group (Zabbix)</th>
          <?php foreach ($sev as $s): ?><th class="text-center"><?= $e(Settings::SEVERITY_LABELS[$s]) ?></th><?php endforeach; ?>
          <th class="text-center">Remover</th>
        </tr></thead>
        <tbody>
        <?php
        $rows = Settings::mapping();
        for ($i = 0; $i < count($rows) + 4; $i++):
            $r = $rows[$i] ?? ['entity_id' => 0, 'group_id' => '', 'group_name' => '', 'severities' => [5, 4, 2]];
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
        <div class="form-hint">Até três severidades têm cartão próprio no painel.</div>
      </div>
      <div class="col-md-6"><label class="form-label">Entidade raiz do painel</label>
        <input class="form-control" name="entity_root" value="<?= $e($cfg['entity_root']) ?>">
        <div class="form-hint">Só esta entidade e as abaixo dela aparecem no mapa (ex.: Root &gt; NOC).</div></div>
      <div class="col-md-6"><label class="form-label">Metas de solução por prioridade (horas)</label>
        <input class="form-control" name="sla_hours" value="<?= $e($cfg['sla_hours']) ?>">
        <div class="form-hint">Formato prioridade:horas, ex.: 6:1,5:2,4:4,3:8,2:24,1:48 (6 = Crítica).</div></div>
      <div class="col-md-3"><label class="form-label">Itens nas listas</label>
        <input class="form-control" type="number" min="1" max="5000" name="list_max" value="<?= $e($cfg['list_max']) ?>"></div>
      <div class="col-md-3"><label class="form-label">Máximo de chamados lidos</label>
        <input class="form-control" type="number" min="100" max="50000" name="glpi_max_tickets" value="<?= $e($cfg['glpi_max_tickets']) ?>"></div>
      <div class="col-md-3"><label class="form-label">Cache do Zabbix (segundos)</label>
        <input class="form-control" type="number" min="0" max="600" name="cache_seconds" value="<?= $e($cfg['cache_seconds']) ?>"></div>
    </div>

    <hr class="my-4">
    <h4>4. Alertas do navegador</h4>
    <p class="text-secondary small">Canais de cada tipo de alerta. Os alertas aparecem só dentro do painel Operacional 360.</p>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>Tipo</th>
          <?php foreach (op360_channels() as $ch => $label): ?><th class="text-center"><?= $e($label) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach (Settings::ALERT_TYPES as $key => [$label]): ?>
          <tr><td><?= $e($label) ?></td>
            <?php foreach (op360_channels() as $ch => $_label): ?>
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
