<?php

use GlpiPlugin\Reembolso\Centrocusto;
use GlpiPlugin\Reembolso\Lote;
use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Money;
use GlpiPlugin\Reembolso\NotificationTargetReembolso;
use GlpiPlugin\Reembolso\Reembolso;
use GlpiPlugin\Reembolso\Tipodespesa;

Session::checkRight('config', UPDATE);

global $DB, $CFG_GLPI;
$id = fn ($k) => (string) max(0, (int) ($_POST[$k] ?? 0));

if (isset($_POST['save'])) {
    $rate = Money::parse((string) ($_POST['km_rate'] ?? ''));
    if ($rate <= 0 || $rate > 50) {
        Session::addMessageAfterRedirect('Valor por KM inválido.', false, ERROR);
    } else {
        Config::setConfigurationValues('plugin:reembolso', [
            'km_rate'           => (string) $rate,
            'route_enabled'     => empty($_POST['route_enabled']) ? '0' : '1',
            'route_country'     => preg_match('/^[a-z]{2}(,[a-z]{2})*$/', strtolower((string) ($_POST['route_country'] ?? ''))) ? strtolower($_POST['route_country']) : '',
            'require_receipt'   => empty($_POST['require_receipt']) ? '0' : '1',
            'night_start'       => Money::hhmm((string) ($_POST['night_start'] ?? ''), '22:00'),
            'night_end'         => Money::hhmm((string) ($_POST['night_end'] ?? ''), '05:00'),
            'business_start'    => Money::hhmm((string) ($_POST['business_start'] ?? ''), '08:00'),
            'business_end'      => Money::hhmm((string) ($_POST['business_end'] ?? ''), '18:00'),
            'lote_period'       => isset(Lote::PERIODS[$_POST['lote_period'] ?? '']) ? $_POST['lote_period'] : 'mensal',
            'tech_groups_id'    => $id('tech_groups_id'),
            'manager_users_id'  => $id('manager_users_id'),
            'manager_groups_id' => $id('manager_groups_id'),
            'otc_users_id'      => $id('otc_users_id'),
            'otc_groups_id'     => $id('otc_groups_id'),
            'finance_users_id'  => $id('finance_users_id'),
            'finance_groups_id' => $id('finance_groups_id'),
            'advance_account_days' => (string) max(1, min(90, (int) ($_POST['advance_account_days'] ?? 5))),
        ]);
        // gestor de cada centro de custo (usuário e/ou grupo)
        foreach ((array) ($_POST['centro'] ?? []) as $cid => $row) {
            $DB->update(Centrocusto::getTable(), ['users_id_manager' => max(0, (int) ($row['user'] ?? 0)), 'groups_id_manager' => max(0, (int) ($row['group'] ?? 0))], ['id' => (int) $cid]);
        }
        Session::addMessageAfterRedirect('Configuração salva. A esteira vale para os lotes que chegarem a cada etapa daqui em diante.', false, INFO);
    }
    Html::back();
}

// periodicidade e gestor da área por técnico
if (isset($_POST['save_tecnicos'])) {
    $row2data = fn (array $r) => ['period' => isset(Lote::PERIODS[$r['period'] ?? '']) ? $r['period'] : 'mensal',
        'users_id_manager' => max(0, (int) ($r['manager'] ?? 0)), 'groups_id_manager' => max(0, (int) ($r['manager_group'] ?? 0)), 'date_mod' => date('Y-m-d H:i:s')];
    foreach ((array) ($_POST['tec'] ?? []) as $uid => $row) {
        if (!empty($row['remove'])) {
            $DB->delete('glpi_plugin_reembolso_tecnicos', ['users_id' => (int) $uid]);
            continue;
        }
        $DB->updateOrInsert('glpi_plugin_reembolso_tecnicos', $row2data((array) $row), ['users_id' => (int) $uid]);
    }
    if ($new = (int) ($_POST['new']['users_id'] ?? 0)) {
        $DB->updateOrInsert('glpi_plugin_reembolso_tecnicos', $row2data((array) $_POST['new']), ['users_id' => $new]);
    }
    Session::addMessageAfterRedirect('Técnicos salvos. A periodicidade e o gestor valem para os próximos lotes.', false, INFO);
    Html::back();
}

$c = Money::config();
Html::header('Reembolso de despesas', '', Menu::SECTOR, 'config');
echo Menu::css();
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$action = $e(Menu::url('/front/config.form.php'));
$check = fn ($v) => $v ? ' checked' : '';

// ---------------------------------------------- campos de usuário e grupo do GLPI
$userField = fn (string $name, int $value, string $label = 'Usuário', string $empty = '—') => "<div class='col-md-6'><label class='form-label mb-1'>$label</label>"
    . User::dropdown(['name' => $name, 'value' => $value, 'right' => 'all', 'display' => false, 'width' => '100%', 'emptylabel' => $empty]) . "</div>";
$groupField = fn (string $name, int $value, string $label = 'Grupo', string $empty = '—') => "<div class='col-md-6'><label class='form-label mb-1'>$label</label>"
    . Group::dropdown(['name' => $name, 'value' => $value, 'display' => false, 'width' => '100%', 'emptylabel' => $empty]) . "</div>";
$pair = fn (string $u, int $uv, string $g, int $gv, string $hint = '') => "<div class='row g-2'>" . $userField($u, $uv) . $groupField($g, $gv) . "</div>" . ($hint ? "<div class='form-hint mt-1'>$hint</div>" : '');

// ---------------------------------------------- situação de cada etapa
$people = fn (int $u, int $g) => Lote::people($u, $g);
$tecs = array_unique(array_merge(
    Lote::people(0, $c['tech_groups_id']),
    array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => 'users_id', 'DISTINCT' => true, 'FROM' => Reembolso::getTable(), 'WHERE' => ['is_deleted' => 0]])), 'users_id')),
    array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_plugin_reembolso_tecnicos'])), 'users_id'))
));
$noMail = array_filter($tecs, fn ($u) => !countElementsInTable('glpi_useremails', ['users_id' => $u]));
$noBoss = array_filter($tecs, fn ($u) => !Lote::techSettings($u)['source']);
$centros = iterator_to_array($DB->request(['FROM' => Centrocusto::getTable(), 'ORDER' => ['code', 'name']]), false);
$noCenterBoss = array_filter($centros, fn ($r) => !$people((int) $r['users_id_manager'], (int) $r['groups_id_manager']));
$approvers = array_map('getUserName', NotificationTargetReembolso::approvers());
$names = fn (array $ids) => $e(implode(', ', array_map('getUserName', array_slice(array_values($ids), 0, 4))) . (count($ids) > 4 ? ' e mais ' . (count($ids) - 4) : ''));
$chip = fn (bool $ok, string $text) => "<span class='rb-flow-chip " . ($ok ? 'ok' : 'warn') . "'><i class='ti " . ($ok ? 'ti-circle-check' : 'ti-alert-triangle') . " me-1'></i>$text</span>";
$who = function (int $u, int $g) use ($people, $chip, $names) {
    $p = $people($u, $g);
    return $chip((bool) $p, $p ? 'recebem: ' . $names($p) : 'ninguém definido: fica com quem tem o direito Aprovar');
};
$step = fn (int $n, string $icon, string $title, string $receives, string $does, string $config, string $status) => "<div class='rb-flow-step'><div class='rb-flow-num'><i class='ti $icon'></i><b>$n</b></div>"
    . "<div class='rb-flow-body'><div class='rb-flow-title'>$title</div><div class='rb-flow-receives'><i class='ti ti-inbox me-1'></i>$receives</div><div class='rb-flow-does'>$does</div>"
    . ($config ? "<div class='rb-flow-config'>$config</div>" : '') . "<div class='rb-flow-status'>$status</div></div></div>";
$mail = (bool) ($CFG_GLPI['use_notifications'] ?? false) && (bool) ($CFG_GLPI['notifications_mailing'] ?? false);
$teams = Plugin::isPluginActive('avisos');

echo "<div class='rb'>" . Menu::title('Configuração de reembolsos', 'config') . "<form method='post' action='$action' class='card' style='max-width:1040px'>";
echo "<div class='card-body'>";
echo "<div class='mb-3'><label class='form-label'>Valor por KM rodado (R$)</label><input class='form-control' style='max-width:180px' name='km_rate' value='" . number_format($c['km_rate'], 2, ',', '') . "' required>";
echo "<div class='form-hint'>Usado nas despesas por quilometragem (KM × valor). Despesas já lançadas mantêm o valor da época.</div></div>";
echo "<label class='form-check form-switch mb-1'><input class='form-check-input' type='checkbox' name='route_enabled' value='1'" . $check($c['route_enabled']) . "><span class='form-check-label'>Calcular o KM pela rota entre origem e destino (OpenStreetMap)</span></label>";
echo "<div class='form-hint mb-3'>O servidor do GLPI consulta os serviços públicos Nominatim e OSRM. Sem internet, o KM é informado pelo odômetro.</div>";
echo "<div class='mb-3'><label class='form-label'>Países da busca de endereços</label><input class='form-control' style='max-width:180px' name='route_country' value='" . $e($c['route_country']) . "'>";
echo "<div class='form-hint'>Códigos ISO separados por vírgula (ex.: br). Vazio = qualquer país.</div></div>";
echo "<label class='form-check form-switch mb-1'><input class='form-check-input' type='checkbox' name='require_receipt' value='1'" . $check($c['require_receipt']) . "><span class='form-check-label'>Exigir comprovante anexado para as despesas de tipos que pedem comprovante</span></label>";
echo "<hr><h4 class='mb-2'><i class='ti ti-clock me-1'></i>Horários</h4><div class='row g-3 mb-1'>"
    . "<div class='col-auto'><label class='form-label'><i class='ti ti-briefcase me-1'></i>Horário comercial (seg. a sex.)</label><div class='d-flex gap-2 align-items-center'>"
    . "<input type='time' class='form-control' name='business_start' value='" . $e($c['business_start']) . "'><span class='text-secondary'>às</span><input type='time' class='form-control' name='business_end' value='" . $e($c['business_end']) . "'></div></div>"
    . "<div class='col-auto'><label class='form-label'><i class='ti ti-moon-stars me-1'></i>Adicional noturno</label><div class='d-flex gap-2 align-items-center'>"
    . "<input type='time' class='form-control' name='night_start' value='" . $e($c['night_start']) . "'><span class='text-secondary'>às</span><input type='time' class='form-control' name='night_end' value='" . $e($c['night_end']) . "'></div></div></div>"
    . "<div class='form-hint mb-3'>Com o início e o fim do atendimento, a solicitação mostra as horas comerciais, as fora do horário comercial (inclui fim de semana) e as de adicional noturno, também em colunas na lista de reembolsos. "
    . "Despesa lançada com horário na faixa noturna sai destacada na solicitação, no lote e no PDF. O valor não muda.</div>";
echo "<hr><h4 class='mb-2'><i class='ti ti-cash-banknote me-1'></i>Adiantamentos</h4><div class='row g-3 align-items-end mb-1'>"
    . "<div class='col-auto'><label class='form-label'>Prazo da prestação de contas</label><div class='input-group' style='max-width:200px'><input type='number' min='1' max='90' class='form-control' name='advance_account_days' value='" . (int) $c['advance_account_days'] . "'><span class='input-group-text'>dia(s)</span></div></div>"
    . "<div class='col'><div class='form-hint'>Contados do fim previsto do atendimento. O adiantamento segue a mesma esteira de aprovação do lote (abaixo); depois de pago, o técnico informa o gasto e anexa os comprovantes, e o financeiro confere e acerta o saldo.</div></div></div>";

// ---------------------------------------------- lotes: quando fecham e a esteira de aprovação
echo "<hr><h4 class='mb-1'><i class='ti ti-stack-2 me-1'></i>Lotes</h4>";
echo "<div class='row g-3 align-items-end mb-3'><div class='col-auto'><label class='form-label'>Quando o lote fecha (padrão)</label>" . Dropdown::showFromArray('lote_period', Lote::PERIODS, ['value' => $c['lote_period'], 'display' => false]) . "</div>"
    . "<div class='col'><div class='form-hint'>Diário (todo dia), semanal (domingo), quinzenal (dias 15 e último) ou mensal. Cada técnico pode ter a sua, na tabela <b>Técnicos</b> abaixo. Ao fechar, as solicitações do técnico viram um lote em PDF.</div></div></div>";
echo "<div class='rb-flow-head'>Esteira de aprovação do lote</div><div class='rb-flow'>";

echo $step(1, 'ti-signature', 'Técnico (parceiro)', 'Recebe o lote em PDF por e-mail quando o período fecha',
    'Confere as despesas, inclusive as de adicional noturno, <b>aprova e assina digitalmente</b>. Pode devolver se algo estiver errado.',
    "<div class='row g-2'>" . $groupField('tech_groups_id', $c['tech_groups_id'], 'Grupo dos técnicos (parceiros)') . "</div>"
    . "<div class='form-hint mt-1'>Os técnicos do grupo aparecem na tabela <b>Técnicos</b>, para definir a periodicidade e o gestor. "
    . (Lote::libreSignReady() ? 'A assinatura é pelo <b>LibreSign</b>: o link chega no e-mail do técnico.' : 'O <b>LibreSign não está configurado</b> (Configurar → Plugins → Assinatura digital): enquanto isso, o técnico aprova no GLPI.') . "</div>",
    $chip((bool) $tecs, count($tecs) . ' técnico(s)') . ' ' . $chip(Lote::libreSignReady(), Lote::libreSignReady() ? 'LibreSign pronto' : 'sem LibreSign')
    . ' ' . $chip(!$noMail, $noMail ? count($noMail) . ' sem e-mail: ' . $names($noMail) : 'todos com e-mail'));

echo $step(2, 'ti-user-check', 'Gestor da área', 'Recebe o lote <b>assinado pelo técnico</b>',
    'Aprova ou devolve ao técnico com o motivo.',
    $pair('manager_users_id', $c['manager_users_id'], 'manager_groups_id', $c['manager_groups_id'],
        'Vale para quem não tem gestor próprio. Ordem: o gestor definido para o técnico na tabela <b>Técnicos</b>, o <i>Responsável</i> do usuário no GLPI e, por último, este padrão.'),
    $chip(!$noBoss, $noBoss ? count($noBoss) . ' técnico(s) sem gestor: ' . $names($noBoss) : 'todos os técnicos têm gestor'));

$rowsC = '';
foreach ($centros as $r) {
    $cid = (int) $r['id'];
    $rowsC .= "<tr><td class='text-nowrap'><b>" . $e($r['code']) . "</b> " . $e($r['name']) . "</td>"
        . "<td>" . User::dropdown(['name' => "centro[$cid][user]", 'value' => (int) $r['users_id_manager'], 'right' => 'all', 'display' => false, 'width' => '100%', 'emptylabel' => '—']) . "</td>"
        . "<td>" . Group::dropdown(['name' => "centro[$cid][group]", 'value' => (int) $r['groups_id_manager'], 'display' => false, 'width' => '100%', 'emptylabel' => '—']) . "</td></tr>";
}
echo $step(3, 'ti-building-bank', 'Gestor do centro de custo', 'Recebe o lote <b>aprovado pelo gestor da área</b>',
    'Cada gestor aprova as despesas do seu centro de custo; com vários centros no lote, segue quando todos aprovarem.',
    "<div class='table-responsive'><table class='table table-sm rb-flow-table mb-0'><thead><tr><th>Centro de custo</th><th>Usuário gestor</th><th>Grupo gestor</th></tr></thead><tbody>$rowsC</tbody></table></div>",
    $chip(!$noCenterBoss, $noCenterBoss ? count($noCenterBoss) . ' de ' . count($centros) . ' centro(s) sem gestor' : 'todos os centros têm gestor'));

echo $step(4, 'ti-file-check', 'OTC', 'Recebe o lote <b>aprovado pelos gestores dos centros de custo</b>',
    'Confere e aprova, ou devolve ao técnico.',
    $pair('otc_users_id', $c['otc_users_id'], 'otc_groups_id', $c['otc_groups_id'], 'O usuário e todos os membros do grupo podem aprovar.'),
    $who($c['otc_users_id'], $c['otc_groups_id']));

echo $step(5, 'ti-cash', 'Financeiro', 'Recebe o lote <b>aprovado pelo OTC</b>',
    'Efetua o pagamento e <b>anexa o comprovante</b>. Ao anexar, o reembolso termina: todos os envolvidos recebem <b>pop-up, e-mail e Teams</b> com o aviso de fim, e o técnico recebe o OK final.',
    $pair('finance_users_id', $c['finance_users_id'], 'finance_groups_id', $c['finance_groups_id'], 'O usuário e todos os membros do grupo podem registrar o pagamento.'),
    $who($c['finance_users_id'], $c['finance_groups_id'])
    . ' ' . $chip($teams, $teams ? 'pop-up e Teams (plugin Avisos)' : 'sem o plugin Avisos: sem pop-up e Teams')
    . ' ' . $chip($mail, $mail ? 'e-mails do GLPI ligados' : 'e-mails do GLPI desligados (Configurar → Notificações)'));

echo "</div><div class='form-hint mt-2'>Em qualquer etapa o lote pode voltar ao técnico, com o motivo; ele corrige e reenvia, e um novo PDF é gerado para assinar. Etapa sem ninguém definido fica com quem tem o direito <i>Aprovar</i>"
    . ($approvers ? ' (hoje: ' . $e(implode(', ', $approvers)) . ')' : '') . ".</div>";
echo "</div><div class='card-footer d-flex gap-2'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>";
echo "<a class='btn btn-ghost-secondary' href='" . $e(Tipodespesa::getSearchURL()) . "'><i class='ti ti-receipt me-1'></i>Tipos de despesa</a>";
echo "<a class='btn btn-ghost-secondary' href='" . $e(Centrocusto::getSearchURL()) . "'><i class='ti ti-building-bank me-1'></i>Centros de custo</a></div>";
Html::closeForm();

// ---------------------------------------------- técnicos: periodicidade do lote e gestor da área
$rows = array_fill_keys($tecs, null);
foreach ($DB->request(['FROM' => 'glpi_plugin_reembolso_tecnicos']) as $r) {
    $rows[(int) $r['users_id']] = $r;
}
$src = ['tecnico' => 'definido aqui', 'responsavel' => 'Responsável no GLPI', 'padrao' => 'padrão da etapa', '' => 'sem gestor'];
echo "<form method='post' action='$action' class='card mt-3' style='max-width:1040px'><div class='card-header'><h3 class='card-title'><i class='ti ti-users me-2'></i>Técnicos</h3>"
    . "<div class='card-actions small text-secondary'>periodicidade do lote e gestor da área de cada técnico</div></div>";
echo "<table class='table table-vcenter card-table rb-tec-table'><thead><tr><th style='width:24%'>Técnico</th><th style='width:20%'>Periodicidade</th><th>Gestor da área (usuário e/ou grupo)</th></tr></thead><tbody>";
$managerCell = fn (string $uName, int $uVal, string $gName, int $gVal, string $note) => "<div class='rb-tec-pair'>"
    . "<div>" . User::dropdown(['name' => $uName, 'value' => $uVal, 'right' => 'all', 'display' => false, 'width' => '100%', 'emptylabel' => 'Usuário…']) . "</div>"
    . "<div>" . Group::dropdown(['name' => $gName, 'value' => $gVal, 'display' => false, 'width' => '100%', 'emptylabel' => 'Grupo…']) . "</div></div>"
    . ($note ? "<div class='rb-tec-note'>$note</div>" : '');
foreach ($rows as $uid => $r) {
    $set = Lote::techSettings($uid);
    echo "<tr><td><b>" . $e(getUserName($uid)) . "</b>"
        . ($r ? "<label class='form-check mb-0 mt-1 small'><input class='form-check-input' type='checkbox' name='tec[$uid][remove]' value='1'><span class='form-check-label text-secondary'>voltar ao padrão</span></label>" : "<div class='rb-tec-note'>usa o padrão</div>") . "</td>"
        . "<td>" . Dropdown::showFromArray("tec[$uid][period]", Lote::PERIODS, ['value' => $set['period'], 'display' => false, 'width' => '100%'])
        . "<div class='rb-tec-note'>fecha em " . date('d/m/Y', strtotime(Reembolso::nextClose($set['period']))) . "</div></td>"
        . "<td>" . $managerCell("tec[$uid][manager]", (int) ($r['users_id_manager'] ?? 0), "tec[$uid][manager_group]", (int) ($r['groups_id_manager'] ?? 0),
            'Hoje: <b>' . $e(Lote::whoLabel($set['manager'], $set['manager_group'], 'ninguém')) . '</b> · ' . $e($src[$set['source']])) . "</td></tr>";
}
echo "<tr class='rb-tec-new'><td>" . User::dropdown(['name' => 'new[users_id]', 'right' => 'all', 'display' => false, 'width' => '100%', 'emptylabel' => 'Adicionar técnico…']) . "</td>"
    . "<td>" . Dropdown::showFromArray('new[period]', Lote::PERIODS, ['value' => $c['lote_period'], 'display' => false, 'width' => '100%']) . "</td>"
    . "<td>" . $managerCell('new[manager]', 0, 'new[manager_group]', 0, '') . "</td></tr>";
echo "</tbody></table><div class='card-footer'><button class='btn btn-primary' name='save_tecnicos' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar técnicos</button></div>";
Html::closeForm();
echo "</div>";
Html::footer();
