<?php

namespace GlpiPlugin\Gestaoeventos;

use Config;
use Html;
use Session;

/**
 * Avisos de contagem regressiva dos eventos: pop-up para cada responsável (e som) nos momentos configurados
 * antes do início, contagem regressiva no pop-up do Painel e aviso no sino/Teams pela ação automática.
 * Há uma regra padrão e, se quiser, uma regra própria por evento (momentos, quem recebe, som, repetição).
 * O servidor decide quem recebe e o que está pendente; o navegador toca o som e mostra o pop-up (avisos.js).
 */
class Alerta
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_alertas';
    public const ACOES = 'glpi_plugin_gestaoeventos_alertas_acoes';
    public const ENVIOS = 'glpi_plugin_gestaoeventos_alertas_envios';

    public const SOUNDS = ['none' => 'Sem som', 'chime' => 'Sino suave (chime)', 'beep' => 'Bip duplo', 'bell' => 'Sineta', 'alarm' => 'Alarme (urgente)'];
    public const ROLES = [
        'owner' => 'Dono do evento', 'gp' => 'Gerente do projeto (GP)', 'requester' => 'Solicitante', 'area' => 'Área da etapa atual',
        'approvers' => 'Aprovadores com decisão pendente', 'marketing' => 'Marketing (grupo do briefing)',
    ];
    public const DEFAULTS = [
        'is_active' => 1, 'offsets' => '30d,14d,7d,3d,1d,2h,0', 'roles' => 'owner,gp,requester,area', 'extra_users' => '', 'sound' => 'chime', 'volume' => 70,
        'repeat_sec' => 0, 'popup' => 1, 'dashboard' => 1, 'notify' => 1,
    ];

    public static function getTypeName($nb = 0)
    {
        return 'Avisos de contagem regressiva';
    }

    // ------------------------------------------------------------------ regras

    /** "30d,14d,12h,30m,0" → segundos antes do início, do maior para o menor. */
    public static function parseOffsets(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) as $p) {
            if (!preg_match('/^(\d{1,4})\s*([dhm]?)$/i', $p, $m)) {
                continue;
            }
            $n = (int) $m[1];
            $out[] = $n === 0 ? 0 : $n * ['d' => 86400, 'h' => 3600, 'm' => 60, '' => 86400][strtolower($m[2])];
        }
        rsort($out);
        return array_values(array_unique($out));
    }

    public static function offsetLabel(int $sec): string
    {
        if ($sec <= 0) {
            return 'no início do evento';
        }
        if ($sec % 86400 === 0) {
            return ($sec / 86400) . ' dia(s) antes';
        }
        if ($sec % 3600 === 0) {
            return ($sec / 3600) . ' hora(s) antes';
        }
        return round($sec / 60) . ' minuto(s) antes';
    }

    /** Regra valendo para o evento: a própria (se ativa), senão a padrão, senão a de fábrica. */
    public static function rule(int $eventoId = 0): array
    {
        global $DB;
        foreach ([$eventoId, 0] as $id) {
            if ($id === 0 && $eventoId === 0 && false) {
                continue;
            }
            $r = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $id, 'is_active' => 1], 'LIMIT' => 1])->current();
            if ($r) {
                return $r + ['custom' => $id > 0];
            }
        }
        return self::DEFAULTS + ['plugin_gestaoeventos_eventos_id' => 0, 'custom' => false];
    }

    public static function hasCustom(int $eventoId): bool
    {
        return (bool) countElementsInTable(self::TABLE, ['plugin_gestaoeventos_eventos_id' => $eventoId]);
    }

    public static function save(int $eventoId, array $in): void
    {
        global $DB;
        $roles = array_values(array_intersect(array_keys(self::ROLES), (array) ($in['roles'] ?? [])));
        $offs = self::parseOffsets((string) ($in['offsets'] ?? ''));
        $data = [
            'plugin_gestaoeventos_eventos_id' => $eventoId, 'is_active' => 1, 'roles' => implode(',', $roles),
            'offsets' => implode(',', array_map(fn ($s) => $s === 0 ? '0' : ($s % 86400 === 0 ? ($s / 86400) . 'd' : ($s % 3600 === 0 ? ($s / 3600) . 'h' : round($s / 60) . 'm')), $offs)),
            'extra_users' => implode(',', array_filter(array_map('intval', (array) ($in['extra_users'] ?? [])))),
            'sound' => isset(self::SOUNDS[$in['sound'] ?? '']) ? $in['sound'] : 'chime', 'volume' => max(5, min(100, (int) ($in['volume'] ?? 70))),
            'repeat_sec' => max(0, min(3600, (int) ($in['repeat_sec'] ?? 0))), 'popup' => empty($in['popup']) ? 0 : 1, 'dashboard' => empty($in['dashboard']) ? 0 : 1, 'notify' => empty($in['notify']) ? 0 : 1,
            'date_mod' => date('Y-m-d H:i:s'),
        ];
        if (countElementsInTable(self::TABLE, ['plugin_gestaoeventos_eventos_id' => $eventoId])) {
            $DB->update(self::TABLE, $data, ['plugin_gestaoeventos_eventos_id' => $eventoId]);
        } else {
            $DB->insert(self::TABLE, $data);
        }
    }

    public static function reset(int $eventoId): void
    {
        global $DB;
        if ($eventoId > 0) {
            $DB->delete(self::TABLE, ['plugin_gestaoeventos_eventos_id' => $eventoId]);
        }
    }

    // ------------------------------------------------------------------ tempo e destinatários

    public static function settings(): array
    {
        $c = Config::getConfigurationValues('plugin:gestaoeventos', ['avisos_dash_on', 'avisos_dash_days', 'avisos_dash_max', 'avisos_start_time', 'avisos_poll']);
        return [
            'dash_on' => ($c['avisos_dash_on'] ?? '1') === '1', 'dash_days' => max(1, (int) ($c['avisos_dash_days'] ?? 45)), 'dash_max' => max(1, (int) ($c['avisos_dash_max'] ?? 8)),
            'start_time' => preg_match('/^\d{2}:\d{2}$/', (string) ($c['avisos_start_time'] ?? '')) ? $c['avisos_start_time'] : '08:00', 'poll' => max(20, min(600, (int) ($c['avisos_poll'] ?? 60))),
        ];
    }

    public static function startTs(array $c, string $time = '08:00'): int
    {
        return (int) strtotime($c['date_start'] . ' ' . $time . ':00');
    }

    public static function endTs(array $c): int
    {
        $end = $c['date_finish'] && $c['date_finish'] >= $c['date_start'] ? $c['date_finish'] : $c['date_start'];
        return (int) strtotime($end . ' 23:59:59');
    }

    /** ids dos usuários que recebem os avisos do evento conforme a regra. */
    public static function recipients(array $c, array $rule): array
    {
        $roles = array_filter(explode(',', (string) $rule['roles']));
        $ids = [];
        foreach (['owner' => 'users_id_owner', 'gp' => 'users_id_gp', 'requester' => 'users_id_requester'] as $role => $col) {
            if (in_array($role, $roles, true)) {
                $ids[] = (int) $c[$col];
            }
        }
        if (in_array('area', $roles, true) && ($p = Fase::active((int) $c['id']))) {
            $ids = array_merge($ids, Avisos::members((int) $p['groups_id']));
        }
        if (in_array('marketing', $roles, true)) {
            $ids = array_merge($ids, Avisos::members((int) (Etapa::all()['briefing']['groups_id'] ?? 0)));
        }
        if (in_array('approvers', $roles, true)) {
            global $DB;
            foreach ($DB->request(['FROM' => Aprovacao::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => (int) $c['id'], 'status' => 'pending']]) as $a) {
                $ids = array_merge($ids, Aprovacao::approverIds($a));
            }
        }
        $ids = array_merge($ids, array_map('intval', array_filter(explode(',', (string) $rule['extra_users']))));
        return array_values(array_unique(array_filter($ids)));
    }

    /** Eventos em curso ou futuros (enviados, não cancelados) que o usuário enxerga. */
    private static function events(): array
    {
        global $DB;
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $where = ['is_deleted' => 0, 'is_draft' => 0, 'status' => Evento::OPEN, ['NOT' => ['date_start' => null]], ['date_finish' => ['>=', date('Y-m-d', strtotime($now))]]];
        if (Session::getLoginUserID()) { // na ação automática não há usuário: vale para todas as entidades
            $where += getEntitiesRestrictCriteria(Evento::getTable(), 'entities_id', '', true);
            if ($scope = Acesso::scope()) {
                $where[] = $scope;
            }
        }
        return iterator_to_array($DB->request(['FROM' => Evento::getTable(), 'WHERE' => $where, 'ORDER' => 'date_start']), false);
    }

    private static function acted(int $user, int $evento, int $off): bool
    {
        global $DB;
        $r = $DB->request(['FROM' => self::ACOES, 'WHERE' => ['users_id' => $user, 'plugin_gestaoeventos_eventos_id' => $evento, 'offset_sec' => $off], 'LIMIT' => 1])->current();
        return $r && ($r['action'] === 'seen' || ($r['until'] && $r['until'] > ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'))));
    }

    /** Avisos pendentes do usuário agora: um por evento, o do momento mais próximo já alcançado e ainda não dispensado. */
    public static function due(int $user): array
    {
        $set = self::settings();
        $now = strtotime($_SESSION['glpi_currenttime'] ?? 'now');
        $out = [];
        foreach (self::events() as $c) {
            $rule = self::rule((int) $c['id']);
            if (!(int) $rule['popup']) {
                continue;
            }
            $start = self::startTs($c, $set['start_time']);
            if ($now > self::endTs($c)) {
                continue;
            }
            $crossed = array_filter(self::parseOffsets((string) $rule['offsets']), fn ($o) => $start - $o <= $now);
            if (!$crossed) {
                continue;
            }
            $off = min($crossed);
            if (!in_array($user, self::recipients($c, $rule), true) || self::acted($user, (int) $c['id'], $off)) {
                continue;
            }
            $p = Fase::active((int) $c['id']);
            $out[] = [
                'id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'url' => Evento::getFormURLWithID((int) $c['id']), 'city' => (string) $c['city'],
                'start' => $start, 'end' => self::endTs($c), 'offset' => $off, 'label' => self::offsetLabel($off), 'sound' => $rule['sound'], 'volume' => (int) $rule['volume'], 'repeat' => (int) $rule['repeat_sec'],
                'phase' => $p ? Fase::name($p['code']) : '', 'late' => $p ? Fase::isLate($p) : false, 'approval' => Evento::APPROVAL[$c['approval_status']][0] ?? '',
                'pending' => countElementsInTable(Aprovacao::getTable(), ['plugin_gestaoeventos_eventos_id' => (int) $c['id'], 'status' => 'pending']),
            ];
        }
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);
        return $out;
    }

    /** Dispensa ("seen") ou adia ("snooze") o aviso atual do evento para o usuário. */
    public static function ack(int $user, int $eventoId, string $mode, int $minutes = 60): void
    {
        global $DB;
        $set = self::settings();
        $c = $DB->request(['FROM' => Evento::getTable(), 'WHERE' => ['id' => $eventoId], 'LIMIT' => 1])->current();
        if (!$c || !$c['date_start']) {
            return;
        }
        $now = strtotime($_SESSION['glpi_currenttime'] ?? 'now');
        $start = self::startTs($c, $set['start_time']);
        $rule = self::rule($eventoId);
        $crossed = array_filter(self::parseOffsets((string) $rule['offsets']), fn ($o) => $start - $o <= $now);
        foreach ($crossed as $o) {
            if ($mode === 'snooze' && $o !== min($crossed)) {
                continue;
            }
            $DB->delete(self::ACOES, ['users_id' => $user, 'plugin_gestaoeventos_eventos_id' => $eventoId, 'offset_sec' => $o]);
            $DB->insert(self::ACOES, ['users_id' => $user, 'plugin_gestaoeventos_eventos_id' => $eventoId, 'offset_sec' => $o, 'action' => $mode === 'snooze' ? 'snooze' : 'seen',
                'until' => $mode === 'snooze' ? date('Y-m-d H:i:s', $now + max(1, min(1440, $minutes)) * 60) : null, 'date' => date('Y-m-d H:i:s', $now)]);
        }
    }

    /** Eventos que começam dentro da janela do pop-up do Painel, com o que importa para a contagem regressiva. */
    public static function upcoming(): array
    {
        $set = self::settings();
        $now = strtotime($_SESSION['glpi_currenttime'] ?? 'now');
        $out = [];
        foreach (self::events() as $c) {
            $rule = self::rule((int) $c['id']);
            $start = self::startTs($c, $set['start_time']);
            if (!(int) $rule['dashboard'] || $start - $now > $set['dash_days'] * 86400 || $now > self::endTs($c)) {
                continue;
            }
            $ev = new Evento();
            $ev->getFromDB((int) $c['id']);
            $p = Fase::active((int) $c['id']);
            $out[] = ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'url' => Evento::getFormURLWithID((int) $c['id']), 'city' => (string) $c['city'], 'type' => Evento::TYPES[$c['event_type']][0] ?? '',
                'start' => $start, 'end' => self::endTs($c), 'phase' => $p ? Fase::name($p['code']) : '', 'phaseLate' => $p ? Fase::isLate($p) : false,
                'approval' => Evento::APPROVAL[$c['approval_status']][0] ?? '', 'approvalColor' => Evento::APPROVAL[$c['approval_status']][1] ?? '#64748b', 'risk' => Evento::semaforo($ev),
                'gp' => getUserName((int) $c['users_id_gp']), 'dateFmt' => Menu::date($c['date_start']) . ($c['date_finish'] && $c['date_finish'] !== $c['date_start'] ? ' a ' . Menu::date($c['date_finish']) : '')];
        }
        return array_slice($out, 0, $set['dash_max']);
    }

    // ------------------------------------------------------------------ aviso no sino e no Teams (ação automática)

    public static function cronNotify(): int
    {
        global $DB;
        $set = self::settings();
        $now = time();
        $sent = 0;
        foreach (self::events() as $c) {
            $rule = self::rule((int) $c['id']);
            if (!(int) $rule['notify']) {
                continue;
            }
            $start = self::startTs($c, $set['start_time']);
            $crossed = array_filter(self::parseOffsets((string) $rule['offsets']), fn ($o) => $start - $o <= $now);
            if (!$crossed || $now > self::endTs($c)) {
                continue;
            }
            $off = min($crossed);
            if (countElementsInTable(self::ENVIOS, ['plugin_gestaoeventos_eventos_id' => (int) $c['id'], 'offset_sec' => $off])) {
                continue;
            }
            foreach ($crossed as $o) {
                if (!countElementsInTable(self::ENVIOS, ['plugin_gestaoeventos_eventos_id' => (int) $c['id'], 'offset_sec' => $o])) {
                    $DB->insert(self::ENVIOS, ['plugin_gestaoeventos_eventos_id' => (int) $c['id'], 'offset_sec' => $o, 'date' => date('Y-m-d H:i:s', $now)]);
                }
            }
            $ev = new Evento();
            if (!$ev->getFromDB((int) $c['id'])) {
                continue;
            }
            $left = max(0, $start - $now);
            $txt = $left >= 86400 ? floor($left / 86400) . ' dia(s)' : ($left >= 3600 ? floor($left / 3600) . ' hora(s)' : max(1, floor($left / 60)) . ' minuto(s)');
            Avisos::send($ev, 'evento_contagem', self::recipients($c, $rule), $left > 0 ? "Faltam $txt para {$c['name']}" : "{$c['name']} começa agora", self::offsetLabel($off) . ' · ' . Menu::date($c['date_start']) . ($c['city'] ? ' · ' . $c['city'] : '') . '.',
                $left <= 86400 ? 'danger' : ($left <= 7 * 86400 ? 'warning' : 'info'), ['Faltam' => $left > 0 ? $txt : 'começou']);
            $sent++;
        }
        return $sent;
    }

    // ------------------------------------------------------------------ telas

    /** Formulário da regra (padrão ou de um evento). */
    public static function form(int $eventoId, string $action, string $hidden, string $submit, bool $edit = true): string
    {
        $e = [Menu::class, 'e'];
        $r = self::rule($eventoId);
        if ($eventoId > 0 && !$r['custom']) {
            $r = self::rule(0);
        }
        $dis = $edit ? '' : 'disabled';
        $roles = array_filter(explode(',', (string) $r['roles']));
        $extra = array_filter(array_map('intval', explode(',', (string) $r['extra_users'])));
        $h = "<form method='post' action='" . $e($action) . "' class='card-body row g-3'>$hidden";
        $h .= "<div class='col-md-6'><label class='form-label'>Quando avisar (antes do início do evento)</label><input class='form-control' name='al[offsets]' value='" . $e($r['offsets']) . "' $dis>"
            . "<div class='form-hint'>Separe por vírgula: <code>30d</code> dias, <code>12h</code> horas, <code>30m</code> minutos, <code>0</code> no início. Ex.: 30d,14d,7d,3d,1d,2h,0</div></div>";
        $h .= "<div class='col-md-3'><label class='form-label'>Som</label><select class='form-select' name='al[sound]' $dis>" . Menu::options(self::SOUNDS, $r['sound']) . "</select></div>"
            . "<div class='col-md-3'><label class='form-label'>Volume</label><input type='range' min='5' max='100' step='5' class='form-range' name='al[volume]' value='" . (int) $r['volume'] . "' $dis>"
            . "<button type='button' class='btn btn-sm btn-outline-secondary' onclick=\"GEAvisos.testSound(this.form['al[sound]'].value, this.form['al[volume]'].value)\"><i class='ti ti-volume me-1'></i>Testar o som</button></div>";
        $h .= "<div class='col-12'><label class='form-label'>Quem recebe o pop-up</label><div class='d-flex flex-wrap gap-3'>";
        foreach (self::ROLES as $k => $label) {
            $h .= "<label class='form-check'><input class='form-check-input' type='checkbox' name='al[roles][]' value='$k'" . (in_array($k, $roles, true) ? ' checked' : '') . " $dis><span class='form-check-label'>" . $e($label) . "</span></label>";
        }
        $h .= "</div></div>";
        $h .= "<div class='col-md-6'><label class='form-label'>Outras pessoas (além das funções acima)</label><select class='form-select' name='al[extra_users][]' multiple size='5' $dis>" . Menu::options(Registro::users(), '') . "</select>";
        if ($extra) {
            $h .= "<div class='form-hint'>Já incluídas: " . $e(implode(', ', array_map(fn ($u) => Registro::users()[$u] ?? getUserName($u), $extra))) . ". Salvar de novo substitui a lista pela seleção acima (Ctrl/Cmd para várias).</div>";
        }
        $h .= "</div><div class='col-md-6'><div class='row g-3'>"
            . "<div class='col-md-6'><label class='form-label'>Repetir o som a cada (segundos)</label><input type='number' min='0' max='3600' class='form-control' name='al[repeat_sec]' value='" . (int) $r['repeat_sec'] . "' $dis><div class='form-hint'>0 = toca uma vez. Repete até a pessoa dispensar ou adiar.</div></div>"
            . "<div class='col-12 d-flex flex-column gap-2'>"
            . "<label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='al[popup]' value='1'" . ($r['popup'] ? ' checked' : '') . " $dis><span class='form-check-label'>Pop-up em qualquer tela do GLPI</span></label>"
            . "<label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='al[dashboard]' value='1'" . ($r['dashboard'] ? ' checked' : '') . " $dis><span class='form-check-label'>Aparecer no pop-up de próximos eventos do Painel</span></label>"
            . "<label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='al[notify]' value='1'" . ($r['notify'] ? ' checked' : '') . " $dis><span class='form-check-label'>Avisar também no sino e no Teams (plugin Avisos)</span></label></div></div></div>";
        $h .= "<div class='col-12 d-flex flex-wrap gap-2 align-items-center'>" . ($edit ? $submit : '')
            . "<button type='button' class='btn btn-outline-secondary' onclick=\"GEAvisos.testPopup(this.form['al[sound]'].value, this.form['al[volume]'].value)\"><i class='ti ti-bell-ringing me-1'></i>Testar o pop-up</button></div>";
        return $h . Html::closeForm(false);
    }

    /** Aba "Avisos" do evento: herdar o padrão ou personalizar, e a agenda dos avisos. */
    public static function tab(Evento $ev, bool $edit): void
    {
        $e = [Menu::class, 'e'];
        $id = $ev->getID();
        $c = $ev->fields;
        $custom = self::hasCustom($id);
        $rule = self::rule($id);
        $set = self::settings();
        $action = Evento::getFormURL();
        $back = Evento::tabUrl($id, 'avisos');
        $hidden = "<input type='hidden' name='id' value='$id'><input type='hidden' name='_back' value='" . $e($back) . "'>";
        echo "<div class='ge ge-tab'><div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-bell-ringing me-2'></i>Avisos de contagem regressiva</h3>"
            . "<div class='card-actions small'>" . ($custom ? "<span class='badge bg-purple-lt'>regra própria deste evento</span>" : "<span class='badge bg-secondary-lt'>usando a regra padrão</span>") . "</div></div>";
        if (!$c['date_start'] || $c['is_draft']) {
            echo "<div class='card-body text-secondary'>Os avisos começam depois que o briefing é enviado (com a data de início definida).</div></div></div>";
            return;
        }
        $submit = "<button class='btn btn-primary' name='alerta_save' value='1'><i class='ti ti-device-floppy me-1'></i>" . ($custom ? 'Salvar a regra do evento' : 'Personalizar para este evento') . "</button>"
            . ($custom ? "<button class='btn btn-outline-danger' name='alerta_reset' value='1'><i class='ti ti-restore me-1'></i>Voltar ao padrão</button>" : '');
        echo "<div class='card-body border-bottom small text-secondary'>Cada responsável recebe um <b>pop-up com som</b> nos momentos abaixo, em qualquer tela do GLPI em que estiver, e pode <b>dispensar</b> ou <b>adiar</b>. "
            . ($custom ? 'Este evento tem regra própria.' : 'Editar e salvar aqui cria uma regra própria só para este evento; o padrão fica em Alçadas e regras → Avisos.') . "</div>";
        echo self::form($id, $action, $hidden, $submit, $edit);
        echo "</div>";

        // agenda dos avisos
        $start = self::startTs($c, $set['start_time']);
        $now = strtotime($_SESSION['glpi_currenttime']);
        $who = array_map(fn ($u) => Registro::users()[$u] ?? getUserName($u), self::recipients($c, $rule));
        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-calendar-time me-2'></i>Agenda dos avisos</h3><div class='card-actions small text-secondary'>início do evento: <b>" . $e(date('d/m/Y H:i', $start)) . "</b></div></div>"
            . "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Momento</th><th>Dispara em</th><th>Situação</th></tr></thead><tbody>";
        foreach (self::parseOffsets((string) $rule['offsets']) as $o) {
            $t = $start - $o;
            echo "<tr><td>" . $e(ucfirst(self::offsetLabel($o))) . "</td><td>" . $e(date('d/m/Y H:i', $t)) . "</td><td>" . ($t <= $now ? "<span class='badge bg-green-lt'>já disparou</span>"
                : "<span class='badge bg-blue-lt'>faltam " . ($t - $now >= 86400 ? floor(($t - $now) / 86400) . ' dia(s)' : floor(($t - $now) / 3600) . ' hora(s)') . "</span>") . "</td></tr>";
        }
        echo "</tbody></table></div><div class='card-footer small text-secondary'><b>Recebem agora:</b> " . ($who ? $e(implode(', ', $who)) : 'ninguém (confira as funções e o GP, o dono e a área da etapa)') . "</div></div></div>";
    }

    /** Configuração padrão na página de administração (inclui o pop-up do Painel). */
    public static function showAdmin(): void
    {
        $e = [Menu::class, 'e'];
        global $DB;
        $set = self::settings();
        $action = Menu::url('/front/admin.php') . '?tab=avisos';
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-bell-ringing me-2'></i>Regra padrão dos avisos</h3></div>";
        echo self::form(0, $action, '', "<button class='btn btn-primary' name='save_avisos' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar regra padrão</button>");
        echo "</div>";
        echo "<form method='post' action='" . $e($action) . "' class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-layout-dashboard me-2'></i>Pop-up de próximos eventos no Painel</h3></div><div class='card-body row g-3 align-items-end'>"
            . "<div class='col-12'><label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='avisos_dash_on' value='1'" . ($set['dash_on'] ? ' checked' : '') . "><span class='form-check-label'>Abrir o pop-up com a contagem regressiva ao entrar no Painel (uma vez por dia por pessoa)</span></label></div>"
            . "<div class='col-md-3'><label class='form-label'>Eventos nos próximos (dias)</label><input type='number' min='1' max='365' class='form-control' name='avisos_dash_days' value='" . $set['dash_days'] . "'></div>"
            . "<div class='col-md-3'><label class='form-label'>Quantidade máxima na lista</label><input type='number' min='1' max='30' class='form-control' name='avisos_dash_max' value='" . $set['dash_max'] . "'></div>"
            . "<div class='col-md-3'><label class='form-label'>Hora de início padrão do evento</label><input type='time' class='form-control' name='avisos_start_time' value='" . $e($set['start_time']) . "'><div class='form-hint'>O briefing só tem a data; a contagem usa esta hora.</div></div>"
            . "<div class='col-md-3'><label class='form-label'>Verificar avisos a cada (segundos)</label><input type='number' min='20' max='600' class='form-control' name='avisos_poll' value='" . $set['poll'] . "'></div>"
            . "</div><div class='card-footer'><button class='btn btn-primary' name='save_avisos_dash' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div>" . Html::closeForm(false) . "</form>";
        $rows = iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => ['plugin_gestaoeventos_eventos_id' => ['>', 0]]]), false);
        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-adjustments-alt me-2'></i>Eventos com regra própria</h3><div class='card-actions small text-secondary'>" . count($rows) . "</div></div><div class='table-responsive'><table class='table table-sm card-table table-vcenter'>"
            . "<thead><tr><th>Evento</th><th>Momentos</th><th>Som</th><th>Quem recebe</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            $ev = new Evento();
            if (!$ev->getFromDB((int) $r['plugin_gestaoeventos_eventos_id'])) {
                continue;
            }
            echo "<tr><td><a href='" . $e(Evento::tabUrl($ev->getID(), 'avisos')) . "'><b>" . $e($ev->fields['code']) . "</b> " . $e($ev->fields['name']) . "</a></td><td class='small'>" . $e($r['offsets']) . "</td><td class='small'>" . $e(self::SOUNDS[$r['sound']] ?? '')
                . "</td><td class='small'>" . $e(implode(', ', array_map(fn ($k) => self::ROLES[$k] ?? $k, array_filter(explode(',', (string) $r['roles']))))) . "</td></tr>";
        }
        echo $rows ? '' : "<tr><td colspan='4' class='text-secondary small'>Todos os eventos usam a regra padrão. Personalize na aba Avisos de cada evento.</td></tr>";
        echo "</tbody></table></div></div>";
    }

    public static function saveDash(array $post): void
    {
        Config::setConfigurationValues('plugin:gestaoeventos', [
            'avisos_dash_on' => empty($post['avisos_dash_on']) ? '0' : '1', 'avisos_dash_days' => (string) max(1, min(365, (int) ($post['avisos_dash_days'] ?? 45))),
            'avisos_dash_max' => (string) max(1, min(30, (int) ($post['avisos_dash_max'] ?? 8))), 'avisos_start_time' => preg_match('/^\d{2}:\d{2}$/', (string) ($post['avisos_start_time'] ?? '')) ? $post['avisos_start_time'] : '08:00',
            'avisos_poll' => (string) max(20, min(600, (int) ($post['avisos_poll'] ?? 60))),
        ]);
    }
}
