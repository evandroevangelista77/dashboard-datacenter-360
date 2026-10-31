<?php

namespace GlpiPlugin\Gestaoeventos;

use CommonDBTM;
use Config;
use Dropdown;
use Glpi\RichText\RichText;
use Html;
use Log;
use Project;
use Session;
use User;

/**
 * Um evento: do briefing ao encerramento. O tempo do ciclo vai do envio do briefing à triagem
 * até o aceite final. Fase do fluxo (etapa), estado do evento, status do projeto e status de
 * aprovação são coisas distintas e ficam em campos distintos (RN-07).
 */
class Evento extends CommonDBTM
{
    public static string $rightname = 'plugin_gestaoeventos';
    public bool $dohistory = true;

    /** Direitos além dos padrões: decidir aprovações de alçada sem grupo e ver/editar o financeiro. */
    public const APPROVE = 1024;
    public const FINANCE = 2048;

    public const OPEN     = 1;
    public const DONE     = 2;
    public const CANCELED = 3;

    public const STATUSES = [
        self::OPEN     => ['Em andamento', '#3b82f6', 'ti-progress'],
        self::DONE     => ['Concluído', '#22c55e', 'ti-circle-check'],
        self::CANCELED => ['Cancelado', '#94a3b8', 'ti-ban'],
    ];

    /** tipo => [nome, ...] */
    public const TYPES = [
        'evento'       => ['Evento de Marketing'],
        'participacao' => ['Participação em evento'],
        'parceiro'     => ['Evento de parceiro / fabricante'],
        'interno'      => ['Iniciativa interna'],
        'comercial'    => ['Agenda comercial'],
    ];

    public const FORMATS = ['presencial' => 'Presencial', 'hibrido' => 'Híbrido', 'remoto' => 'Remoto'];
    public const PRIORITIES = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'];

    /** Estado da aprovação (separado da etapa e do status do projeto). */
    public const APPROVAL = [
        'rascunho'     => ['Rascunho', '#94a3b8'],
        'triagem'      => ['Em triagem', '#8b5cf6'],
        'em_aprovacao' => ['Em aprovação', '#f59e0b'],
        'aprovado'     => ['Aprovado', '#22c55e'],
        'rejeitado'    => ['Rejeitado', '#ef4444'],
        'postergado'   => ['Postergado', '#64748b'],
    ];

    /** Os seis status do projeto (S1). */
    public const PROJECT_STATUS = [
        'nao_iniciado' => ['Não iniciado', '#94a3b8'],
        'planejamento' => ['Em planejamento', '#3b82f6'],
        'execucao'     => ['Em execução', '#14b8a6'],
        'risco'        => ['Em risco', '#ef4444'],
        'concluido'    => ['Concluído', '#22c55e'],
        'cancelado'    => ['Cancelado', '#64748b'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Eventos' : 'Evento';
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-event';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::APPROVE] = ['short' => 'Aprovar', 'long' => 'Aprovar (decide alçadas sem grupo definido)'];
        $values[self::FINANCE] = ['short' => 'Financeiro', 'long' => 'Ver e editar Budget, EAC, Realizado e viagens'];
        $values[Acesso::RESTRICT] = ['short' => 'Só envolvidos', 'long' => 'Ver só os eventos em que está envolvido (solicitante, dono, GP, grupo da etapa ou aprovador)'];
        return $values;
    }

    public static function absolute(string $path): string
    {
        global $CFG_GLPI;
        $root = (string) $CFG_GLPI['root_doc'];
        return rtrim((string) $CFG_GLPI['url_base'], '/') . ($root !== '' && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path);
    }

    public static function canFinance(): bool
    {
        return Acesso::canView('financeiro');
    }

    /** O usuário atual é do Marketing: está no grupo da etapa de briefing ou edita eventos. */
    public static function isMarketing(): bool
    {
        if (Session::haveRight(self::$rightname, UPDATE)) {
            return true;
        }
        $g = (int) (Etapa::all()['briefing']['groups_id'] ?? 0);
        return $g > 0 && in_array((int) Session::getLoginUserID(), Avisos::members($g), true);
    }

    /** Segundos do ciclo: do envio do briefing à triagem até o fim (ou até agora). */
    public static function cycleSeconds(array $f): int
    {
        $start = $f['date_request'] ?? null;
        if (!$start) {
            return 0;
        }
        $end = $f['date_end'] ?: ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        return max(0, strtotime($end) - strtotime($start));
    }

    /** Endereço do evento já na aba pedida (aprovacoes, planejamento…). */
    public static function tabUrl(int $id, string $tab): string
    {
        $n = array_search($tab, Abas::TABS, true);
        return self::getFormURLWithID($id) . ($n ? '&forcetab=' . rawurlencode(Abas::class . '$' . $n) : '');
    }

    // ------------------------------------------------------------------ regras de dados

    /** O que ainda falta no briefing para poder enviá-lo (campos obrigatórios do gate). */
    public static function briefingMissing(array $f): array
    {
        $need = [
            'name' => 'nome do evento', 'requesting_area' => 'área demandante', 'objective' => 'objetivo', 'scope' => 'escopo', 'city' => 'cidade',
            'date_start' => 'data de início', 'date_finish' => 'data de término',
        ];
        $out = [];
        foreach ($need as $k => $label) {
            if (trim((string) ($f[$k] ?? '')) === '') {
                $out[] = $label;
            }
        }
        if (!(int) ($f['users_id_owner'] ?? 0)) {
            $out[] = 'dono do evento';
        }
        if ((int) ($f['audience'] ?? 0) <= 0) {
            $out[] = 'público estimado';
        }
        if ((float) ($f['budget_estimated'] ?? 0) <= 0) {
            $out[] = 'orçamento previsto';
        }
        return $out ? ['Preencha no briefing: ' . implode(', ', $out) . '.'] : [];
    }

    /** Antecedência até o evento: dias, mínimo configurado, modo e se está curta. Agenda comercial não entra (D-08 pendente). */
    public static function leadInfo(array $f, ?string $from = null): ?array
    {
        if (empty($f['date_start']) || ($f['event_type'] ?? '') === 'comercial') {
            return null;
        }
        $cfg = Config::getConfigurationValues('plugin:gestaoeventos', ['lead_days', 'lead_mode']);
        $min = (int) ($cfg['lead_days'] ?? 60);
        $start = $from ?: (!empty($f['date_request']) ? substr((string) $f['date_request'], 0, 10) : date('Y-m-d', strtotime($_SESSION['glpi_currenttime'] ?? 'now')));
        $days = (int) floor((strtotime((string) $f['date_start']) - strtotime($start)) / 86400);
        return ['days' => $days, 'min' => $min, 'mode' => (string) ($cfg['lead_mode'] ?? 'exception'), 'short' => $min > 0 && $days < $min];
    }

    /** Status do projeto calculado do estado atual (inclui "em risco" pelo semáforo). */
    public static function projectStatus(self $ev): string
    {
        $f = $ev->fields;
        if ((int) $f['status'] === self::CANCELED) {
            return 'cancelado';
        }
        if ((int) $f['status'] === self::DONE) {
            return 'concluido';
        }
        $phase = (string) $f['current_phase'];
        if ($phase === '' || in_array($phase, ['briefing', 'avaliacao'], true)) {
            return 'nao_iniciado';
        }
        if (self::semaforo($ev)) {
            return 'risco';
        }
        return in_array($phase, ['execucao', 'encerramento'], true) ? 'execucao' : 'planejamento';
    }

    /** Motivos do semáforo vermelho: etapa atrasada, risco alto aberto, marco crítico vencido, aprovação vencida, EAC estourado. */
    public static function semaforo(self $ev): array
    {
        global $DB;
        $id = $ev->getID();
        $why = [];
        if (($a = Fase::active($id)) && Fase::isLate($a)) {
            $why[] = 'etapa "' . Fase::name($a['code']) . '" atrasada';
        }
        foreach (Registro::forEvento($id, 'risco') as $r) {
            $prob = (string) $r['category'];
            $impact = (string) $r['subcategory'];
            $high = ($prob === 'alta' && $impact !== 'baixa') || ($impact === 'alta' && $prob !== 'baixa');
            if ($high && in_array($r['status'], ['aberto', 'mitigando', 'ocorreu'], true)) {
                $why[] = 'risco alto aberto: ' . $r['title'];
                break;
            }
        }
        $today = date('Y-m-d', strtotime($_SESSION['glpi_currenttime'] ?? 'now'));
        if (Registro::countKind($id, 'marco', ['is_critical' => 1, 'date_due' => ['<', $today], 'status' => ['a_fazer', 'andamento', 'bloqueado']])) {
            $why[] = 'marco crítico vencido';
        }
        if (countElementsInTable(Aprovacao::getTable(), ['plugin_gestaoeventos_eventos_id' => $id, 'status' => 'pending', 'date_due' => ['<', $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')]])) {
            $why[] = 'aprovação vencida';
        }
        return $why;
    }

    public static function syncProjectStatus(self $ev): void
    {
        global $DB;
        $st = self::projectStatus($ev);
        if ($st !== $ev->fields['project_status']) {
            $DB->update(self::getTable(), ['project_status' => $st], ['id' => $ev->getID()]);
            $ev->fields['project_status'] = $st;
        }
    }

    // ------------------------------------------------------------------ abas

    /** Perfis marcados como "só envolvidos" não abrem eventos em que não atuam (nem por URL). */
    public function canViewItem(): bool
    {
        if (!parent::canViewItem()) {
            return false;
        }
        return $this->isNewItem() || !Acesso::restricted() || Acesso::involved($this->fields);
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Abas::class, $ong, $options);
        $this->addStandardTab(Documentos::class, $ong, $options);
        $this->addStandardTab(Log::class, $ong, $options);
        return $ong;
    }

    /** Quem edita os dados: quem tem o direito, o dono, o GP e o solicitante, ou quem executa a etapa atual. */
    public function canEditData(): bool
    {
        if ($this->isNewItem()) {
            return self::canCreate();
        }
        if (!$this->canViewItem()) {
            return false;
        }
        if ((int) $this->fields['status'] !== self::OPEN) {
            return Session::haveRight(self::$rightname, UPDATE);
        }
        $me = (int) Session::getLoginUserID();
        return Session::haveRight(self::$rightname, UPDATE)
            || in_array($me, [(int) $this->fields['users_id_requester'], (int) $this->fields['users_id_owner'], (int) $this->fields['users_id_gp']], true)
            || Fase::canAct($this, Fase::active($this->getID()));
    }

    // ------------------------------------------------------------------ busca

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Evento', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'code', 'name' => 'Código', 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 4, 'table' => $t, 'field' => 'event_type', 'name' => 'Tipo', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
            ['id' => 5, 'table' => $t, 'field' => 'status', 'name' => __('Status'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 6, 'table' => $t, 'field' => 'current_phase', 'name' => 'Etapa atual', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'approval_status', 'name' => 'Aprovação', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'project_status', 'name' => 'Status do projeto', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 9, 'table' => $t, 'field' => 'date_start', 'name' => 'Início do evento', 'datatype' => 'date'],
            ['id' => 10, 'table' => $t, 'field' => 'date_finish', 'name' => 'Fim do evento', 'datatype' => 'date'],
            ['id' => 11, 'table' => $t, 'field' => 'city', 'name' => 'Cidade', 'datatype' => 'string'],
            ['id' => 12, 'table' => $t, 'field' => 'partner', 'name' => 'Fabricante / parceiro', 'datatype' => 'string'],
            ['id' => 13, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_gp', 'name' => 'GP', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 14, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_owner', 'name' => 'Dono do evento', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 15, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_requester', 'name' => 'Solicitante', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 16, 'table' => $t, 'field' => 'cc_number', 'name' => 'Centro de custo', 'datatype' => 'string'],
            ['id' => 17, 'table' => $t, 'field' => 'budget_estimated', 'name' => 'Orçamento previsto', 'datatype' => 'decimal'],
            ['id' => 18, 'table' => $t, 'field' => 'requesting_area', 'name' => 'Área demandante', 'datatype' => 'string'],
            ['id' => 19, 'table' => $t, 'field' => 'returns', 'name' => 'Devoluções', 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 20, 'table' => $t, 'field' => 'date_end', 'name' => 'Concluído em', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 21, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        $v = $values[$field] ?? '';
        return match ($field) {
            'event_type' => Menu::e(self::TYPES[$v][0] ?? ''),
            'status' => self::statusBadge((int) $v),
            'current_phase' => $v ? Menu::e(Fase::name((string) $v)) : '—',
            'approval_status' => self::badge(self::APPROVAL, (string) $v),
            'project_status' => self::badge(self::PROJECT_STATUS, (string) $v),
            default => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        $list = match ($field) {
            'event_type' => Menu::labels(self::TYPES),
            'status' => Menu::labels(self::STATUSES),
            'current_phase' => array_map(fn ($e) => $e['name'], Etapa::all()),
            'approval_status' => Menu::labels(self::APPROVAL),
            'project_status' => Menu::labels(self::PROJECT_STATUS),
            default => null,
        };
        if ($list !== null) {
            return Dropdown::showFromArray($name, $list, ['value' => $values[$field] ?? '', 'display' => false] + $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public static function badge(array $map, string $key): string
    {
        [$label, $color] = $map[$key] ?? [$key, '#64748b'];
        return "<span class='badge ge-badge' style='--c:$color'>" . Menu::e($label) . "</span>";
    }

    public static function statusBadge(int $s): string
    {
        [$label, $color, $icon] = self::STATUSES[$s] ?? ['?', '#64748b', 'ti-help'];
        return "<span class='badge ge-badge' style='--c:$color'><i class='ti $icon me-1'></i>" . Menu::e($label) . "</span>";
    }

    // ------------------------------------------------------------------ gravação

    public function prepareInputForAdd($input)
    {
        $input['entities_id'] ??= $_SESSION['glpiactive_entity'] ?? 0;
        $input['is_recursive'] ??= 1;
        $input['status'] = self::OPEN;
        $input['is_draft'] = 1;
        $input['approval_status'] = 'rascunho';
        $input['project_status'] = 'nao_iniciado';
        $input['users_id_requester'] = Session::getLoginUserID();
        $input['users_id_owner'] = !empty($input['users_id_owner']) ? $input['users_id_owner'] : Session::getLoginUserID();
        unset($input['code'], $input['date_request'], $input['current_phase'], $input['budget_approved']);
        return $this->clean($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (empty($input['_workflow'])) {
            unset($input['status'], $input['current_phase'], $input['returns'], $input['date_end'], $input['approval_status'], $input['project_status'], $input['is_draft'], $input['date_request'], $input['code'],
                $input['triage_status'], $input['triage_comment'], $input['users_id_triage'], $input['date_triage'], $input['budget_approved'], $input['budget_version'], $input['exception_status'],
                $input['ready_gp_users'], $input['ready_gp_date'], $input['ready_mkt_users'], $input['ready_mkt_date'], $input['accepted_users'], $input['accepted_date'], $input['users_id_requester']);
            // dados financeiros e de projeto: só quem pode
            if (!self::canFinance() && empty($this->fields['is_draft'])) {
                unset($input['budget_estimated'], $input['sponsorship_estimated']);
            }
        }
        return $this->clean($input);
    }

    private function clean(array $input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect('Informe o nome do evento.', false, ERROR);
            return false;
        }
        if (isset($input['event_type']) && !isset(self::TYPES[$input['event_type']])) {
            $input['event_type'] = 'evento';
        }
        if (isset($input['format']) && !isset(self::FORMATS[$input['format']])) {
            $input['format'] = 'presencial';
        }
        if (isset($input['commercial_priority']) && !isset(self::PRIORITIES[$input['commercial_priority']])) {
            $input['commercial_priority'] = null;
        }
        foreach (['budget_estimated', 'sponsorship_estimated'] as $money) {
            if (isset($input[$money])) {
                $input[$money] = max(0, Menu::parseMoney((string) $input[$money]));
            }
        }
        if (isset($input['audience'])) {
            $input['audience'] = max(0, (int) $input['audience']);
        }
        foreach (['date_start', 'date_finish', 'date_critical'] as $date) {
            if (array_key_exists($date, $input)) {
                $input[$date] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input[$date]) ? $input[$date] : null;
            }
        }
        $start = $input['date_start'] ?? $this->fields['date_start'] ?? null;
        $end = $input['date_finish'] ?? $this->fields['date_finish'] ?? null;
        if ($start && $end && $end < $start) {
            Session::addMessageAfterRedirect('A data de término não pode ser anterior à de início.', false, ERROR);
            return false;
        }
        foreach (['participation_url', 'sharepoint_url'] as $url) {
            if (!empty($input[$url]) && !preg_match('#^https?://#i', (string) $input[$url])) {
                $input[$url] = 'https://' . ltrim((string) $input[$url], '/');
            }
        }
        if (!empty($input['projects_id']) && (int) $input['projects_id'] !== (int) ($this->fields['projects_id'] ?? 0)) {
            if ($other = Projeto::usedBy((int) $input['projects_id'], (int) ($this->fields['id'] ?? 0))) {
                Session::addMessageAfterRedirect('Este projeto já está ligado ao evento ' . $other['code'] . ' · ' . $other['name'] . '. Cada evento tem o seu projeto: escolha outro ou crie um novo na aba Planejamento.', false, ERROR);
                return false;
            }
        }
        if (isset($input['venue_defined'])) {
            $input['venue_defined'] = (int) (bool) $input['venue_defined'];
        }
        return $input;
    }

    public function post_addItem()
    {
        global $DB;
        parent::post_addItem();
        $code = 'EV-' . date('Y') . '-' . str_pad((string) $this->getID(), 4, '0', STR_PAD_LEFT);
        $DB->update(self::getTable(), ['code' => $code], ['id' => $this->getID()]);
        $this->getFromDB($this->getID());
        Historico::record($this->getID(), null, 'open', 'Evento criado como rascunho.');
        Fase::build($this);
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        if ((int) $this->fields['status'] !== self::OPEN) {
            return;
        }
        if (in_array('event_type', $this->updates, true)) {
            Fase::sync($this);
        }
        if (!empty($this->input['_workflow'])) {
            return;
        }
        $changed = array_intersect($this->updates, ['date_start', 'date_finish', 'scope', 'objective', 'budget_estimated', 'sponsorship_estimated']);
        if ($changed) {
            Historico::record($this->getID(), $this->fields['current_phase'], 'change', 'Briefing alterado: ' . implode(', ', $changed) . '.');
        }
        // alteração de custo, prazo ou escopo depois da aprovação reabre a decisão (nova versão; a anterior fica preservada)
        if ($this->fields['approval_status'] === 'aprovado' && $changed) {
            $tol = (float) (Config::getConfigurationValues('plugin:gestaoeventos', ['tolerance_pct'])['tolerance_pct'] ?? 5);
            $old = (float) ($this->oldvalues['budget_estimated'] ?? 0);
            $costMoved = in_array('budget_estimated', $changed, true) && $old > 0 && abs((float) $this->fields['budget_estimated'] - $old) / $old * 100 > $tol;
            $other = array_diff($changed, ['budget_estimated', 'sponsorship_estimated']);
            if ($costMoved || $other) {
                Aprovacao::request($this, 'revisao', 'Alteração após a aprovação (' . implode(', ', $changed) . ')', (float) $this->fields['budget_estimated'], 0,
                    'Custo, prazo ou escopo mudou depois da aprovação formal; a decisão anterior fica preservada.');
                Historico::record($this->getID(), null, 'approval', 'Aprovação reaberta por alteração acima da tolerância.');
            }
        }
        Evento::syncProjectStatus($this);
    }

    public function cleanDBonPurge()
    {
        global $DB;
        foreach ([Fase::getTable(), Registro::getTable(), Historico::getTable(), Aprovacao::getTable(), Viajante::getTable(), Cotacao::getTable(), Documentos::TABLE] as $t) {
            $DB->delete($t, ['plugin_gestaoeventos_eventos_id' => $this->getID()]);
        }
    }

    // ------------------------------------------------------------------ formulário

    public function showForm($ID, array $options = [])
    {
        $new = $this->isNewItem();
        if ($new) {
            $this->getEmpty();
            $this->fields['event_type'] = 'evento';
            $this->fields['format'] = 'presencial';
            $this->fields['users_id_requester'] = Session::getLoginUserID();
            $this->fields['users_id_owner'] = Session::getLoginUserID();
            if ($copy = (int) ($_GET['copy'] ?? 0)) {
                $src = new self();
                if ($src->getFromDB($copy) && $src->canViewItem()) {
                    $keep = ['event_type', 'requesting_area', 'objective', 'scope', 'audience', 'partner', 'commercial_priority', 'target_audience', 'city', 'venue', 'address', 'venue_defined', 'format',
                        'need_structure', 'need_coffee', 'need_meals', 'need_travel', 'need_lodging', 'need_transport', 'need_gifts', 'need_av', 'need_comm', 'need_suppliers', 'budget_estimated', 'sponsorship_estimated', 'cc_description', 'users_id_gp'];
                    foreach ($keep as $k) {
                        $this->fields[$k] = $src->fields[$k];
                    }
                    $this->fields['name'] = $src->fields['name'] . ' (cópia)';
                }
            }
        }
        $f = $this->fields;
        $e = [Menu::class, 'e'];
        $edit = $this->canEditData();
        $locked = !$new && !$f['is_draft'] && !Session::haveRight(self::$rightname, UPDATE) && !in_array((int) Session::getLoginUserID(), [(int) $f['users_id_owner'], (int) $f['users_id_gp']], true);
        $dis = $edit && !$locked ? '' : 'disabled';
        $action = $e(self::getFormURL());
        $phases = $new ? [] : Fase::forEvento($this->getID());
        $active = $new ? null : Fase::active($this->getID());

        echo "<div class='ge'>";
        // ---------------------------------------------- cabeçalho
        echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'><div class='ge-head-icon'><i class='ti ti-calendar-event'></i></div><div class='me-auto'>"
            . "<div class='ge-crumb'>" . ($new ? 'Novo evento' : $e($f['code'])) . "</div><h2 class='m-0'>" . ($new ? (!empty($f['name']) ? $e($f['name']) : 'Briefing de entrada') : $e($f['name'])) . "</h2>"
            . ($new ? "<div class='text-secondary small ge-subtitle'>Preencha o briefing e salve como rascunho; só depois de completo ele é enviado à triagem do Marketing e o relógio do fluxo começa.</div>"
                : "<div class='small text-secondary mt-1'>" . self::statusBadge((int) $f['status']) . ' ' . self::badge(self::APPROVAL, (string) $f['approval_status']) . ' ' . self::badge(self::PROJECT_STATUS, (string) $f['project_status'])
                    . ' · ' . $e(self::TYPES[$f['event_type']][0] ?? '') . ' · ' . $e(Menu::date($f['date_start'])) . ($f['date_finish'] && $f['date_finish'] !== $f['date_start'] ? ' a ' . $e(Menu::date($f['date_finish'])) : '')
                    . ($f['city'] ? ' · ' . $e($f['city']) : '') . '</div>')
            . "</div>";
        if (!$new) {
            $late = $active && Fase::isLate($active);
            $risk = self::semaforo($this);
            foreach ([
                ['Dias no ciclo', $f['date_request'] ? Menu::days(self::cycleSeconds($f)) : '—', (int) $f['status'] === self::DONE ? 'ciclo concluído' : ($f['date_request'] ? 'desde o envio do briefing' : 'ainda rascunho')],
                ['Etapa atual', $active ? Fase::name($active['code']) : '—', $active ? Menu::days(Fase::elapsed($active)) . ' dia(s) nesta etapa' . ($late ? ' · atrasada' : '') : ''],
                ['Devoluções', (int) $f['returns'], 'retrabalho'],
                ['Orçamento previsto', (float) $f['budget_estimated'] ? Menu::money((float) $f['budget_estimated']) : '—', $risk ? 'Em risco: ' . implode('; ', array_slice($risk, 0, 2)) : 'semáforo verde'],
            ] as [$label, $value, $sub]) {
                echo "<div class='ge-stat'><div class='ge-stat-label'>$label</div><div class='ge-stat-value'>" . $e($value) . "</div><div class='ge-stat-sub" . (($late && $label === 'Etapa atual') || ($risk && $label === 'Orçamento previsto') ? ' text-danger' : '') . "'>" . $e($sub) . "</div></div>";
            }
        }
        echo "</div>";
        if (!$new) {
            echo self::stepper($phases);
        }
        echo "</div>";

        if (!$new) {
            $this->showCurrentPhase($active, $phases);
            echo Dicas::card($this);
        }

        // ---------------------------------------------- briefing
        $user = fn ($name, $label, $col = 'col-md-4') => "<div class='$col'><label class='form-label'>$label</label>" . User::dropdown(['name' => $name, 'value' => (int) $f[$name], 'right' => 'all', 'display' => false, 'width' => '100%', 'disabled' => $dis !== '']) . "</div>";
        $date = fn ($name, $label, $col = 'col-md-3') => "<div class='$col'><label class='form-label'>$label</label><input type='date' class='form-control' name='$name' value='" . $e($f[$name] ? substr((string) $f[$name], 0, 10) : '') . "' $dis></div>";
        $text = fn ($name, $label, $col = 'col-md-4', $ph = '') => "<div class='$col'><label class='form-label'>$label</label><input class='form-control' name='$name' maxlength='255' placeholder='" . $e($ph) . "' value='" . $e($f[$name]) . "' $dis></div>";
        $money = fn ($name, $label, $col = 'col-md-3') => "<div class='$col'><label class='form-label'>$label</label><input class='form-control' name='$name' value='" . ((float) $f[$name] ? number_format((float) $f[$name], 2, ',', '.') : '') . "' $dis></div>";
        $area = fn ($name, $label, $col = 'col-md-6', $rows = 2) => "<div class='$col'><label class='form-label'>$label</label><textarea class='form-control' rows='$rows' name='$name' $dis>" . $e($f[$name]) . "</textarea></div>";
        $select = fn ($name, $label, $list, $col = 'col-md-3') => "<div class='$col'><label class='form-label'>$label</label>" . Dropdown::showFromArray($name, $list, ['value' => (string) $f[$name], 'display' => false, 'width' => '100%', 'disabled' => $dis !== '']) . "</div>";
        $section = fn ($title, $icon, $phase = '') => "<div class='col-12 ge-section'><i class='ti $icon me-1'></i>$title" . ($phase ? " <span class='text-secondary fw-normal'>· etapa " . $e(Fase::name($phase)) . "</span>" : '') . "</div>";

        echo "<form method='post' action='$action' class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-file-description me-2'></i>" . ($new ? 'Briefing de entrada' : 'Briefing e dados do evento') . "</h3>"
            . "<div class='card-actions small text-secondary'>" . ($locked ? 'Somente leitura: o briefing foi enviado.' : '') . "</div></div><div class='card-body row g-3'>";
        echo $section('Identificação', 'ti-id', 'briefing');
        echo "<div class='col-md-6'><label class='form-label required'>Nome do evento</label><input class='form-control' name='name' maxlength='150' required placeholder='Ex.: Feira de Tecnologia 2026' value='" . $e($f['name']) . "' $dis></div>";
        echo $select('event_type', 'Tipo', Menu::labels(self::TYPES), 'col-md-3');
        echo $text('requesting_area', 'Área demandante', 'col-md-3', 'Ex.: Comercial');
        echo $user('users_id_owner', 'Dono do evento (responsável estratégico)');
        echo $user('users_id_gp', 'Gerente do projeto (GP)');
        echo $text('partner', 'Fabricante / parceiro', 'col-md-4', 'se houver');
        echo $select('commercial_priority', 'Prioridade comercial', ['' => '—'] + self::PRIORITIES, 'col-md-3');
        echo $text('target_audience', 'Público-alvo', 'col-md-5', 'Ex.: CIOs e gestores de TI do setor público');
        echo $section('Objetivo', 'ti-target-arrow');
        echo $area('objective', 'Objetivo (resultado esperado)');
        echo $area('scope', 'Escopo previsto');
        echo "<div class='col-md-3'><label class='form-label'>Público estimado (pessoas)</label><input type='number' min='0' class='form-control' name='audience' value='" . (int) $f['audience'] . "' $dis></div>";
        echo $section('Data e local', 'ti-map-pin');
        echo $date('date_start', 'Início do evento');
        echo $date('date_finish', 'Término do evento');
        echo $date('date_critical', 'Prazo crítico (marco importante)');
        echo $select('format', 'Formato', self::FORMATS);
        echo $text('city', 'Cidade', 'col-md-3');
        echo $text('venue', 'Local', 'col-md-4', 'hotel, centro de convenções…');
        echo $text('address', 'Endereço', 'col-md-3');
        echo "<div class='col-md-2 d-flex align-items-end'><label class='form-check form-switch mb-2'><input type='hidden' name='venue_defined' value='0'><input class='form-check-input' type='checkbox' name='venue_defined' value='1'" . ($f['venue_defined'] ? ' checked' : '') . " $dis><span class='form-check-label'>Local confirmado</span></label></div>";
        echo $section('Necessidades', 'ti-list-details');
        foreach (['need_structure' => 'Estrutura necessária', 'need_coffee' => 'Coffee break / happy hour', 'need_meals' => 'Almoço / jantar com clientes', 'need_travel' => 'Viagens', 'need_lodging' => 'Hospedagem',
            'need_transport' => 'Transporte e logística', 'need_gifts' => 'Brindes (descrição e quantidade)', 'need_av' => 'Audiovisual e tecnologia', 'need_comm' => 'Comunicação e materiais de Marketing', 'need_suppliers' => 'Fornecedores previstos'] as $k => $label) {
            echo $area($k, $label, 'col-md-6', 2);
        }
        echo $section('Orçamento e aprovação', 'ti-building-bank', 'avaliacao');
        if ($new || self::canFinance() || (int) $f['users_id_requester'] === (int) Session::getLoginUserID() || (int) $f['users_id_owner'] === (int) Session::getLoginUserID() || $f['is_draft']) {
            echo $money('budget_estimated', 'Orçamento previsto (R$)');
            echo $money('sponsorship_estimated', 'Patrocínio previsto (R$)');
        }
        echo $text('cc_number', 'Centro de custo', 'col-md-3', 'validado pelo Financeiro');
        echo $text('cc_description', 'Descrição do centro de custo', 'col-md-3');
        echo "<div class='col-md-8'><label class='form-label'>Contrato / confirmação de participação (link)</label><input class='form-control' name='participation_url' maxlength='500' placeholder='https://… (ou anexe em Documentos, etapa Avaliação)' value='" . $e($f['participation_url']) . "' $dis></div>";
        if (!$new) {
            echo $section('Projeto e documentos', 'ti-folders', 'planejamento');
            echo "<div class='col-md-6'><label class='form-label'>Projeto no GLPI</label>" . Project::dropdown(['name' => 'projects_id', 'value' => (int) $f['projects_id'], 'display' => false, 'width' => '100%', 'disabled' => $dis !== '', 'used' => Projeto::usedIds($this->getID())])
                . ((int) $f['projects_id'] ? "<div class='form-hint'><a href='" . $e(Project::getFormURLWithID((int) $f['projects_id'])) . "'>abrir o projeto</a></div>" : '') . "</div>";
            echo "<div class='col-md-6'><label class='form-label'>Pasta SharePoint do evento</label><input class='form-control' name='sharepoint_url' maxlength='500' placeholder='https://…' value='" . $e($f['sharepoint_url']) . "' $dis></div>";
        }
        echo "<div class='col-12'><label class='form-label'>Observações</label><textarea class='form-control' rows='2' name='comment' $dis>" . $e($f['comment']) . "</textarea></div>";
        echo "</div>";
        if ($edit && !$locked) {
            echo "<div class='card-footer d-flex gap-2'>";
            if ($new) {
                echo "<button class='btn btn-primary' name='add' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar rascunho</button>";
            } else {
                echo "<input type='hidden' name='id' value='" . (int) $this->getID() . "'><button class='btn btn-primary' name='update' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar dados</button>"
                    . (Acesso::canView('dossie') ? "<a class='btn btn-outline-primary' target='_blank' href='" . $e(Menu::url('/front/dossie.php') . '?id=' . $this->getID()) . "' title='PDF completo com tudo sobre o evento'><i class='ti ti-file-type-pdf me-1'></i>Dossiê executivo (PDF)</a>" : '')
                    . "<a class='btn btn-outline-secondary' href='" . $e(self::getFormURL() . '?id=0&copy=' . $this->getID()) . "' title='Cria um rascunho com este briefing, sem aprovações'><i class='ti ti-copy me-1'></i>Duplicar briefing</a>";
                if (Session::haveRight(self::$rightname, PURGE)) {
                    echo "<button class='btn btn-ghost-danger ms-auto' name='purge' value='1' onclick=\"return confirm('Excluir o evento e tudo o que está ligado a ele?')\"><i class='ti ti-trash'></i></button>";
                }
            }
            echo "</div>";
        }
        Html::closeForm();
        echo "</div>";
        return true;
    }

    /** Linha das etapas: concluída, atual (atrasada em vermelho), a fazer ou pulada, com dias e SLO. */
    private static function stepper(array $phases): string
    {
        $e = [Menu::class, 'e'];
        $h = "<div class='ge-steps'>";
        $i = 0;
        foreach ($phases as $p) {
            $i++;
            $late = Fase::isLate($p);
            $cls = $p['status'] . (($late && in_array($p['status'], ['active', 'done'], true)) || Fase::isWarn($p) ? ' late' : '');
            $days = in_array($p['status'], ['active', 'done'], true) || (int) $p['seconds'] ? Menu::days(Fase::elapsed($p)) . 'd' : '';
            $icon = match ($p['status']) { 'done' => 'ti-check', 'active' => 'ti-player-play', 'skipped' => 'ti-player-skip-forward', default => '' };
            $h .= "<div class='ge-step $cls' title='" . $e(Fase::name($p['code']) . ' · ' . Fase::area($p) . ' · ' . (Fase::STATUSES[$p['status']][0] ?? '') . ($days ? " · $days" : '') . ((int) $p['slo_days'] ? ' (SLO ' . $p['slo_days'] . 'd)' : '') . ((int) $p['returns'] ? ' · ' . $p['returns'] . ' devolução(ões)' : '')) . "'>"
                . "<span class='ge-step-dot'>" . ($icon ? "<i class='ti $icon'></i>" : $i) . "</span><span class='ge-step-name'>" . $e(Fase::name($p['code'])) . "</span>"
                . "<span class='ge-step-meta'>" . $e($days ?: Fase::area($p)) . ((int) $p['slo_days'] && $days ? ' / ' . (int) $p['slo_days'] . 'd' : '') . ((int) $p['returns'] ? " <i class='ti ti-arrow-back-up text-danger'></i>" . (int) $p['returns'] : '') . "</span></div>";
        }
        return $h . "</div>";
    }

    private function showCurrentPhase(?array $p, array $phases): void
    {
        $e = [Menu::class, 'e'];
        $action = $e(self::getFormURL());
        $f = $this->fields;
        $hid = "<input type='hidden' name='id' value='" . (int) $this->getID() . "'>";
        if ((int) $f['status'] === self::OPEN && $f['is_draft']) {
            // rascunho: envio à triagem
            $missing = self::briefingMissing($f);
            $lead = self::leadInfo($f, date('Y-m-d', strtotime($_SESSION['glpi_currenttime'])));
            echo "<div class='card mb-3 ge-current'><div class='card-header'><h3 class='card-title'><i class='ti ti-pencil me-2'></i>Rascunho</h3>"
                . ($f['triage_status'] === 'devolvido' ? "<div class='card-actions small text-danger'>devolvido pela triagem</div>" : '') . "</div><div class='card-body'>";
            if ($f['triage_status'] === 'devolvido' && $f['triage_comment']) {
                echo "<div class='alert alert-danger py-2'><b>Devolvido pelo Marketing:</b> " . $e($f['triage_comment']) . "</div>";
            }
            echo "<p class='mb-2'>O briefing ainda não foi enviado: enquanto for rascunho ele não entra no fluxo, não tem SLO e não pode ser aprovado.</p>";
            echo $missing ? "<div class='alert alert-warning py-2 mb-3'><b>Para enviar:</b> " . $e(implode(' ', $missing)) . "</div>" : "<div class='alert alert-success py-2 mb-3'><i class='ti ti-check me-1'></i>Briefing completo.</div>";
            if ($lead && $lead['short']) {
                echo "<div class='alert alert-" . ($lead['mode'] === 'block' ? 'danger' : 'warning') . " py-2 mb-3'><i class='ti ti-alarm me-1'></i>Antecedência de <b>{$lead['days']} dia(s)</b>, abaixo do mínimo de {$lead['min']}. "
                    . ($lead['mode'] === 'block' ? 'Ajuste a data do evento para enviar.' : ($lead['mode'] === 'exception' ? 'Envie com a justificativa: a exceção vai para aprovação.' : 'Atenção: segue sem bloqueio.')) . "</div>";
            }
            if ($this->canEditData()) {
                echo "<form method='post' action='$action' class='row g-2 align-items-end'>$hid";
                if ($lead && $lead['short'] && $lead['mode'] === 'exception') {
                    echo "<div class='col-md-8'><label class='form-label'>Justificativa da exceção de antecedência</label><input class='form-control' name='exception_reason' maxlength='1000' value='" . $e($f['exception_reason']) . "' placeholder='Por que o evento não pôde ser solicitado com antecedência?'></div>";
                }
                echo "<div class='col-auto'><button class='btn btn-success' name='workflow' value='submit'" . ($missing || ($lead && $lead['short'] && $lead['mode'] === 'block') ? ' disabled' : '') . "><i class='ti ti-send me-1'></i>Enviar à triagem do Marketing</button></div>" . Html::closeForm(false);
            }
            echo "</div></div>";
            return;
        }
        if (!$p) {
            echo "<div class='card mb-3'><div class='card-body d-flex align-items-center gap-3'>" . self::statusBadge((int) $f['status'])
                . "<div class='me-auto'>" . ((int) $f['status'] === self::DONE ? 'Evento concluído em ' . Menu::date($f['date_end'], true) . ', com ' . Menu::days(self::cycleSeconds($f)) . ' dias.' : 'Evento cancelado: ' . $e($f['close_comment'])) . "</div>";
            if (Session::haveRight(self::$rightname, UPDATE)) {
                echo "<form method='post' action='$action' class='m-0'>$hid<button class='btn btn-outline-secondary' name='workflow' value='reopen'><i class='ti ti-refresh me-1'></i>Reabrir</button>" . Html::closeForm(false);
            }
            echo "</div></div>";
            return;
        }
        $etapa = Etapa::all()[$p['code']] ?? [];
        $missing = Fase::missing($this, $p);
        $can = Fase::canAct($this, $p);
        $late = Fase::isLate($p);
        $warn = Fase::isWarn($p);
        $ticket = (int) $p['tickets_id'];
        echo "<div class='card mb-3 ge-current" . ($late ? ' late' : '') . "'><div class='card-header'><h3 class='card-title'><i class='ti ti-player-play me-2'></i>Etapa atual: " . $e(Fase::name($p['code'])) . "</h3>"
            . "<div class='card-actions small text-secondary'>" . $e(Fase::area($p)) . ' · há ' . Menu::days(Fase::elapsed($p)) . ' dia(s)' . ((int) $p['slo_days'] ? ' · SLO ' . (int) $p['slo_days'] . ' dia(s)' : '')
            . ($late ? " · <b class='text-danger'>atrasada</b>" : ($warn ? " · <b class='text-warning'>perto do prazo</b>" : '')) . ($ticket ? " · <a href='" . $e(\Ticket::getFormURLWithID($ticket)) . "'>chamado #$ticket</a>" : '') . "</div></div><div class='card-body'>";
        echo "<p class='mb-2'>" . $e($etapa['description'] ?? '') . "</p>";
        echo Abas::phaseGuide($this, $p);
        if ($missing) {
            echo "<div class='alert alert-warning py-2 mb-3'><b>Para concluir:</b><ul class='mb-0'>" . implode('', array_map(fn ($m) => '<li>' . $e($m) . '</li>', $missing)) . "</ul></div>";
        } else {
            echo "<div class='alert alert-success py-2 mb-3'><i class='ti ti-check me-1'></i>Tudo o que a etapa pede está preenchido.</div>";
        }
        if ($can) {
            $before = array_filter($phases, fn ($x) => $x['position'] < $p['position'] && in_array($x['status'], ['done', 'skipped'], true));
            if ($p['code'] === 'briefing' && Fase::canTriage($p)) {
                echo "<form method='post' action='$action' class='row g-2 align-items-end mb-2'>$hid<div class='col-md-6'><label class='form-label'>Triagem do Marketing</label>"
                    . "<input class='form-control' name='comment' maxlength='1000' placeholder='Observação (obrigatória para devolver ao solicitante)'></div>"
                    . "<div class='col-auto d-flex gap-1'><button class='btn btn-outline-success' name='workflow' value='triage_accept'" . ($f['triage_status'] === 'aceito' ? ' disabled' : '') . "><i class='ti ti-filter-check me-1'></i>Aceitar triagem</button>"
                    . "<button class='btn btn-outline-danger' name='workflow' value='triage_return'><i class='ti ti-arrow-back-up me-1'></i>Devolver ao solicitante</button></div>" . Html::closeForm(false);
            }
            if ($p['code'] === 'prontidao') {
                echo "<form method='post' action='$action' class='d-flex flex-wrap gap-2 mb-2'>$hid"
                    . "<button class='btn btn-outline-primary' name='workflow' value='ready_gp'" . ($f['ready_gp_date'] ? ' disabled' : '') . "><i class='ti ti-user-check me-1'></i>Aceite do GP" . ($f['ready_gp_date'] ? ' · ' . $e(Menu::date($f['ready_gp_date'])) : '') . "</button>"
                    . "<button class='btn btn-outline-primary' name='workflow' value='ready_mkt'" . ($f['ready_mkt_date'] ? ' disabled' : '') . "><i class='ti ti-user-check me-1'></i>Aceite do Marketing" . ($f['ready_mkt_date'] ? ' · ' . $e(Menu::date($f['ready_mkt_date'])) : '') . "</button>" . Html::closeForm(false);
            }
            if ($p['code'] === 'encerramento') {
                echo "<form method='post' action='$action' class='mb-2'>$hid<button class='btn btn-outline-primary' name='workflow' value='accept'" . ($f['accepted_date'] ? ' disabled' : '') . "><i class='ti ti-signature me-1'></i>Aceite formal do dono do evento"
                    . ($f['accepted_date'] ? ' · ' . $e(getUserName((int) $f['accepted_users'])) . ' em ' . $e(Menu::date($f['accepted_date'])) : '') . "</button>" . Html::closeForm(false);
            }
            echo "<form method='post' action='$action' class='row g-2 align-items-end'>$hid";
            echo "<div class='col-md-5'><label class='form-label'>Comentário / motivo</label><input class='form-control' name='comment' maxlength='1000' placeholder='Obrigatório para devolver ou pular'></div>";
            echo "<div class='col-auto'><button class='btn btn-success' name='workflow' value='conclude'" . ($missing ? ' disabled' : '') . "><i class='ti ti-check me-1'></i>Concluir etapa</button></div>";
            if ($before) {
                echo "<div class='col-md-3'><label class='form-label'>Devolver para</label><select class='form-select' name='target'>";
                foreach (array_reverse($before, true) as $code => $x) {
                    echo "<option value='" . $e($code) . "'>" . $e(Fase::name($code) . ' (' . Fase::area($x) . ')') . "</option>";
                }
                echo "</select></div><div class='col-auto'><button class='btn btn-outline-danger' name='workflow' value='return'><i class='ti ti-arrow-back-up me-1'></i>Devolver</button></div>";
            }
            echo "<div class='col-auto ms-auto d-flex gap-2'><button class='btn btn-ghost-secondary' name='workflow' value='comment' title='Registrar comentário na linha do tempo'><i class='ti ti-message'></i></button>";
            if (Session::haveRight(self::$rightname, UPDATE)) {
                echo "<button class='btn btn-ghost-secondary' name='workflow' value='skip' title='Pular esta etapa (com motivo)'><i class='ti ti-player-skip-forward'></i></button>";
            }
            echo "<button class='btn btn-ghost-danger' name='workflow' value='cancel' title='Cancelar o evento (com motivo)' onclick=\"return confirm('Cancelar este evento? Aprovações pendentes são encerradas e o histórico fica registrado.')\"><i class='ti ti-ban'></i></button>";
            echo "</div>" . Html::closeForm(false);
        } else {
            echo "<div class='text-secondary small'>Quem executa esta etapa é o grupo <b>" . $e(Fase::area($p)) . "</b>.</div>";
        }
        echo "</div></div>";
    }
}
