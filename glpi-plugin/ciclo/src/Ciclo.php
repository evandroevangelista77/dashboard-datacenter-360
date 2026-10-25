<?php

namespace GlpiPlugin\Ciclo;

use CommonDBTM;
use Document_Item;
use Dropdown;
use Html;
use Log;
use Project;
use Session;
use User;

/**
 * Um Ciclo: a venda do aviso de fechamento até o recebimento dos valores.
 * O tempo do Ciclo vai da data do aviso de fechamento até a data em que a
 * última fase (Recebimento dos valores) é concluída.
 */
class Ciclo extends CommonDBTM
{
    public static string $rightname = 'plugin_ciclo';
    public bool $dohistory = true;

    public const OPEN     = 1;
    public const DONE     = 2;
    public const CANCELED = 3;

    public const STATUSES = [
        self::OPEN     => ['Em andamento', '#3b82f6', 'ti-progress'],
        self::DONE     => ['Concluído', '#22c55e', 'ti-circle-check'],
        self::CANCELED => ['Cancelado', '#94a3b8', 'ti-ban'],
    ];

    /** tipo => [nome, características que decidem as fases] */
    public const SALE_TYPES = [
        'hw'         => ['Hardware', ['fisico']],
        'sw'         => ['Software / licença', []],
        'servico'    => ['Serviço', ['servico']],
        'hw_servico' => ['Hardware + serviço', ['fisico', 'servico']],
        'sw_servico' => ['Software + serviço', ['servico']],
    ];

    public const TRANSPORT = ['cotacao' => 'Transportadora (cotação aprovada)', 'motorista' => 'Motorista próprio', 'cliente' => 'Retirada pelo cliente'];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Ciclos' : 'Ciclo';
    }

    public static function getIcon()
    {
        return 'ti ti-recycle';
    }

    public static function code(int $id): string
    {
        return 'CF-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public static function absolute(string $path): string
    {
        global $CFG_GLPI;
        $root = (string) $CFG_GLPI['root_doc'];
        return rtrim((string) $CFG_GLPI['url_base'], '/') . ($root !== '' && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path);
    }

    /** Segundos do Ciclo: do aviso de fechamento até o fim (ou até agora). */
    public static function cycleSeconds(array $f): int
    {
        $start = $f['date_closing'] ?: $f['date_creation'];
        if (!$start) {
            return 0;
        }
        $end = $f['date_end'] ?: ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        return max(0, strtotime($end) - strtotime($start));
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Document_Item::class, $ong, $options);
        $this->addStandardTab(Log::class, $ong, $options);
        return $ong;
    }

    /** Quem edita os dados: quem tem o direito, o GP e o OTC do Ciclo, ou quem executa a fase atual. */
    public function canEditData(): bool
    {
        if ($this->isNewItem()) {
            return self::canCreate();
        }
        if ((int) $this->fields['status'] !== self::OPEN) {
            return Session::haveRight(self::$rightname, UPDATE);
        }
        $me = (int) Session::getLoginUserID();
        return Session::haveRight(self::$rightname, UPDATE) || in_array($me, [(int) $this->fields['users_id_gp'], (int) $this->fields['users_id_otc']], true)
            || Fase::canAct($this, Fase::active($this->getID()));
    }

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Venda / projeto', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'customer', 'name' => 'Cliente', 'datatype' => 'string'],
            ['id' => 4, 'table' => $t, 'field' => 'opportunity', 'name' => 'Oportunidade (CRM)', 'datatype' => 'string'],
            ['id' => 5, 'table' => $t, 'field' => 'sale_type', 'name' => 'Tipo de venda', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
            ['id' => 6, 'table' => $t, 'field' => 'status', 'name' => __('Status'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'current_phase', 'name' => 'Fase atual', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'amount', 'name' => 'Valor da venda', 'datatype' => 'decimal'],
            ['id' => 9, 'table' => $t, 'field' => 'date_closing', 'name' => 'Aviso de fechamento', 'datatype' => 'datetime'],
            ['id' => 10, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_gp', 'name' => 'GP', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 11, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_otc', 'name' => 'OTC', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 12, 'table' => $t, 'field' => 'cc_number', 'name' => 'Centro de custo', 'datatype' => 'string'],
            ['id' => 13, 'table' => $t, 'field' => 'returns', 'name' => 'Devoluções', 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 14, 'table' => $t, 'field' => 'date_end', 'name' => 'Concluído em', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        return match ($field) {
            'sale_type' => Menu::e(self::SALE_TYPES[$values[$field]][0] ?? ''),
            'status' => self::statusBadge((int) ($values[$field] ?? 0)),
            'current_phase' => $values[$field] ? Menu::e(Fase::name((string) $values[$field])) : '—',
            default => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        $list = match ($field) {
            'sale_type' => array_map(fn ($t) => $t[0], self::SALE_TYPES),
            'status' => array_map(fn ($s) => $s[0], self::STATUSES),
            'current_phase' => array_map(fn ($e) => $e['name'], Etapa::all()),
            default => null,
        };
        if ($list !== null) {
            return Dropdown::showFromArray($name, $list, ['value' => $values[$field] ?? '', 'display' => false] + $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function prepareInputForAdd($input)
    {
        $input['entities_id'] ??= $_SESSION['glpiactive_entity'] ?? 0;
        $input['is_recursive'] ??= 1;
        $input['status'] = self::OPEN;
        $input['date_closing'] = $this->dateTime($input['date_closing'] ?? '') ?: $_SESSION['glpi_currenttime'];
        $input['users_id_commercial'] ??= Session::getLoginUserID();
        return $this->clean($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (empty($input['_workflow'])) {
            unset($input['status'], $input['current_phase'], $input['returns'], $input['date_end']);
        }
        if (array_key_exists('date_closing', $input)) {
            $input['date_closing'] = $this->dateTime((string) $input['date_closing']) ?: $this->fields['date_closing'];
        }
        return $this->clean($input);
    }

    private function dateTime(string $v): ?string
    {
        $v = str_replace('T', ' ', trim($v));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return "$v 09:00:00";
        }
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v) ? substr($v . ':00', 0, 19) : null;
    }

    private function clean(array $input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect('Informe a venda / projeto.', false, ERROR);
            return false;
        }
        if (isset($input['sale_type']) && !isset(self::SALE_TYPES[$input['sale_type']])) {
            $input['sale_type'] = 'hw_servico';
        }
        if (isset($input['transport_mode']) && !isset(self::TRANSPORT[$input['transport_mode']])) {
            $input['transport_mode'] = null;
        }
        foreach (['amount', 'transport_cost'] as $money) {
            if (isset($input[$money])) {
                $input[$money] = max(0, Menu::parseMoney((string) $input[$money]));
            }
        }
        foreach (['date_signed', 'date_transfer', 'date_delivery', 'date_accept'] as $date) {
            if (array_key_exists($date, $input)) {
                $input[$date] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input[$date]) ? $input[$date] : null;
            }
        }
        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        Evento::record($this->getID(), null, 'open', 'Aviso de fechamento' . ($this->fields['opportunity'] ? ' da oportunidade ' . $this->fields['opportunity'] : '') . '.');
        Fase::build($this);
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        if (in_array('sale_type', $this->updates, true) && (int) $this->fields['status'] === self::OPEN) {
            Fase::sync($this);
        }
    }

    public function cleanDBonPurge()
    {
        global $DB;
        foreach ([Fase::getTable(), Item::getTable(), Evento::getTable()] as $t) {
            $DB->delete($t, ['plugin_ciclo_ciclos_id' => $this->getID()]);
        }
    }

    public static function statusBadge(int $s): string
    {
        [$label, $color, $icon] = self::STATUSES[$s] ?? ['?', '#64748b', 'ti-help'];
        return "<span class='badge cf-badge' style='--c:$color'><i class='ti $icon me-1'></i>" . Menu::e($label) . "</span>";
    }

    // ------------------------------------------------------------------ formulário

    public function showForm($ID, array $options = [])
    {
        $new = $this->isNewItem();
        if ($new) {
            $this->getEmpty();
            $this->fields['sale_type'] = 'hw_servico';
            $this->fields['users_id_commercial'] = Session::getLoginUserID();
            $this->fields['date_closing'] = $_SESSION['glpi_currenttime'];
        }
        $f = $this->fields;
        $e = [Menu::class, 'e'];
        $edit = $this->canEditData();
        $dis = $edit ? '' : 'disabled';
        $action = $e(self::getFormURL());
        $phases = $new ? [] : Fase::forCiclo($this->getID());
        $active = $new ? null : Fase::active($this->getID());

        echo "<div class='cf'>";
        // ---------------------------------------------- cabeçalho
        echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'><div class='cf-head-icon'><i class='ti ti-recycle'></i></div><div class='me-auto'>"
            . "<div class='cf-crumb'>" . ($new ? 'Novo Ciclo' : $e(self::code($this->getID()))) . "</div><h2 class='m-0'>" . ($new ? 'Aviso de fechamento' : $e($f['name'])) . "</h2>"
            . ($new ? "<div class='text-secondary small cf-subtitle'>Registre a venda fechada. O Ciclo começa na data do aviso e já segue para a validação da documentação pelo OTC.</div>"
                : "<div class='small text-secondary mt-1'>" . self::statusBadge((int) $f['status']) . ' · ' . $e($f['customer']) . ' · ' . $e(self::SALE_TYPES[$f['sale_type']][0] ?? '') . ($f['opportunity'] ? ' · oportunidade ' . $e($f['opportunity']) : '') . '</div>')
            . "</div>";
        if (!$new) {
            $late = array_filter($phases, fn ($p) => $p['status'] === 'active' && Fase::isLate($p));
            foreach ([
                ['Dias no ciclo', Menu::days(self::cycleSeconds($f)), (int) $f['status'] === self::DONE ? 'ciclo concluído' : 'desde o aviso de fechamento'],
                ['Fase atual', $active ? Fase::name($active['code']) : '—', $active ? Menu::days(Fase::elapsed($active)) . ' dia(s) nesta fase' . ($late ? ' · atrasada' : '') : ''],
                ['Devoluções', (int) $f['returns'], 'retrabalho'],
                ['Valor', (float) $f['amount'] ? Menu::money((float) $f['amount']) : '—', ''],
            ] as [$label, $value, $sub]) {
                echo "<div class='cf-stat'><div class='cf-stat-label'>$label</div><div class='cf-stat-value'>" . $e($value) . "</div><div class='cf-stat-sub" . ($late && $label === 'Fase atual' ? ' text-danger' : '') . "'>" . $e($sub) . "</div></div>";
            }
        }
        echo "</div>";
        if (!$new) {
            echo self::stepper($phases);
        }
        echo "</div>";

        if (!$new) {
            $this->showCurrentPhase($active, $phases);
        }

        // ---------------------------------------------- dados do Ciclo
        $user = fn ($name, $label) => "<div class='col-md-4'><label class='form-label'>$label</label>" . User::dropdown(['name' => $name, 'value' => (int) $f[$name], 'right' => 'all', 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        $date = fn ($name, $label, $col = 'col-md-3') => "<div class='$col'><label class='form-label'>$label</label><input type='date' class='form-control' name='$name' value='" . $e($f[$name] ? substr((string) $f[$name], 0, 10) : '') . "' $dis></div>";
        $text = fn ($name, $label, $col = 'col-md-4', $ph = '') => "<div class='$col'><label class='form-label'>$label</label><input class='form-control' name='$name' maxlength='255' placeholder='" . $e($ph) . "' value='" . $e($f[$name]) . "' $dis></div>";
        $money = fn ($name, $label, $col = 'col-md-3') => "<div class='$col'><label class='form-label'>$label</label><input class='form-control' name='$name' value='" . ((float) $f[$name] ? number_format((float) $f[$name], 2, ',', '.') : '') . "' $dis></div>";
        $section = fn ($title, $icon, $phase = '') => "<div class='col-12 cf-section'><i class='ti $icon me-1'></i>$title" . ($phase ? " <span class='text-secondary fw-normal'>· fase " . $e(Fase::name($phase)) . "</span>" : '') . "</div>";

        echo "<form method='post' action='$action' class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-file-description me-2'></i>" . ($new ? 'Dados da venda' : 'Dados do Ciclo') . "</h3></div><div class='card-body row g-3'>";
        echo $section('Venda', 'ti-briefcase', 'aviso');
        echo "<div class='col-md-6'><label class='form-label required'>Venda / projeto</label><input class='form-control' name='name' maxlength='255' required placeholder='Ex.: Datacenter Prefeitura X - expansão de storage' value='" . $e($f['name']) . "' $dis></div>";
        echo $text('customer', 'Cliente', 'col-md-6');
        echo $text('opportunity', 'Oportunidade no CRM', 'col-md-3', 'nº da oportunidade');
        echo "<div class='col-md-3'><label class='form-label'>Tipo de venda</label>" . Dropdown::showFromArray('sale_type', array_map(fn ($t) => $t[0], self::SALE_TYPES), ['value' => $f['sale_type'], 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        echo $money('amount', 'Valor da venda (R$)');
        echo "<div class='col-md-3'><label class='form-label'>Aviso de fechamento em</label><input type='datetime-local' class='form-control' name='date_closing' value='" . $e($f['date_closing'] ? date('Y-m-d\TH:i', strtotime($f['date_closing'])) : '') . "' $dis></div>";
        echo $date('date_signed', 'Contrato assinado em');
        echo $user('users_id_commercial', 'Comercial');
        echo $user('users_id_otc', 'OTC');
        echo $user('users_id_gp', 'Gerente de projeto (GP)');
        if (!$new) {
            $fisico = in_array('fisico', self::SALE_TYPES[$f['sale_type']][1] ?? [], true);
            echo $section('Centro de custo', 'ti-building-bank', 'centro_custo');
            echo $text('cc_number', 'Número', 'col-md-3');
            echo $text('cc_description', 'Descrição', 'col-md-9');
            echo $section('Transfermeeting', 'ti-users', 'transfer');
            echo $date('date_transfer', 'Realizada em');
            if ($fisico) {
                echo $section('Separação e transporte', 'ti-truck', 'expedicao');
                echo $text('cubage', 'Cubagem / volumes', 'col-md-4', 'ex.: 3 volumes, 0,8 m³, 120 kg');
                echo "<div class='col-md-5'><label class='form-label'>Transporte</label>" . Dropdown::showFromArray('transport_mode', ['' => '—'] + self::TRANSPORT, ['value' => (string) $f['transport_mode'], 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
                echo $money('transport_cost', 'Custo do frete (R$)');
            }
            echo $section('Entrega, implantação e aceite', 'ti-flag', '');
            echo $date('date_delivery', 'Entregue ao cliente em');
            if (in_array('servico', self::SALE_TYPES[$f['sale_type']][1] ?? [], true)) {
                echo "<div class='col-md-6'><label class='form-label'>Projeto de implantação (PMO)</label>" . Project::dropdown(['name' => 'projects_id', 'value' => (int) $f['projects_id'], 'display' => false, 'width' => '100%', 'disabled' => !$edit])
                    . ((int) $f['projects_id'] ? "<div class='form-hint'><a href='" . $e(Project::getFormURLWithID((int) $f['projects_id'])) . "'>abrir o projeto</a></div>" : '') . "</div>";
            }
            echo $date('date_accept', 'Aceite do cliente em');
        }
        echo "<div class='col-12'><label class='form-label'>Observações</label><textarea class='form-control' rows='2' name='comment' $dis>" . $e($f['comment']) . "</textarea></div>";
        echo "</div>";
        if ($edit) {
            echo "<div class='card-footer d-flex gap-2'>";
            if ($new) {
                echo "<button class='btn btn-primary' name='add' value='1'><i class='ti ti-flag me-1'></i>Abrir Ciclo</button>";
            } else {
                echo "<input type='hidden' name='id' value='" . (int) $this->getID() . "'><button class='btn btn-primary' name='update' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar dados</button>";
                if (Session::haveRight(self::$rightname, PURGE)) {
                    echo "<button class='btn btn-ghost-danger ms-auto' name='purge' value='1' onclick=\"return confirm('Excluir o Ciclo, as fases e os itens?')\"><i class='ti ti-trash'></i></button>";
                }
            }
            echo "</div>";
        }
        Html::closeForm();

        if (!$new) {
            $this->showItems($edit);
            $this->showTimeline();
        }
        echo "</div>";
        return true;
    }

    /** Linha das fases: concluída, atual (atrasada em vermelho), a fazer ou pulada, com dias e SLO. */
    private static function stepper(array $phases): string
    {
        $e = [Menu::class, 'e'];
        $h = "<div class='cf-steps'>";
        $i = 0;
        foreach ($phases as $p) {
            $i++;
            $late = Fase::isLate($p);
            $cls = $p['status'] . ($late && in_array($p['status'], ['active', 'done'], true) ? ' late' : '');
            $days = in_array($p['status'], ['active', 'done'], true) || (int) $p['seconds'] ? Menu::days(Fase::elapsed($p)) . 'd' : '';
            $icon = match ($p['status']) { 'done' => 'ti-check', 'active' => 'ti-player-play', 'skipped' => 'ti-player-skip-forward', default => '' };
            $h .= "<div class='cf-step $cls' title='" . $e(Fase::name($p['code']) . ' · ' . Fase::area($p) . ' · ' . (Fase::STATUSES[$p['status']][0] ?? '') . ($days ? " · $days" : '') . ((int) $p['slo_days'] ? ' (SLO ' . $p['slo_days'] . 'd)' : '') . ((int) $p['returns'] ? ' · ' . $p['returns'] . ' devolução(ões)' : '')) . "'>"
                . "<span class='cf-step-dot'>" . ($icon ? "<i class='ti $icon'></i>" : $i) . "</span><span class='cf-step-name'>" . $e(Fase::name($p['code'])) . "</span>"
                . "<span class='cf-step-meta'>" . $e($days ?: Fase::area($p)) . ((int) $p['slo_days'] && $days ? ' / ' . (int) $p['slo_days'] . 'd' : '') . ((int) $p['returns'] ? " <i class='ti ti-arrow-back-up text-danger'></i>" . (int) $p['returns'] : '') . "</span></div>";
        }
        return $h . "</div>";
    }

    private function showCurrentPhase(?array $p, array $phases): void
    {
        $e = [Menu::class, 'e'];
        $action = $e(self::getFormURL());
        $f = $this->fields;
        if (!$p) {
            echo "<div class='card mb-3'><div class='card-body d-flex align-items-center gap-3'>" . self::statusBadge((int) $f['status'])
                . "<div class='me-auto'>" . ((int) $f['status'] === self::DONE ? 'Ciclo concluído em ' . Menu::date($f['date_end'], true) . ', com ' . Menu::days(self::cycleSeconds($f)) . ' dias.' : 'Ciclo cancelado: ' . $e($f['close_comment'])) . "</div>";
            if (Session::haveRight(self::$rightname, UPDATE)) {
                echo "<form method='post' action='$action' class='m-0'><input type='hidden' name='id' value='" . (int) $this->getID() . "'><button class='btn btn-outline-secondary' name='workflow' value='reopen'><i class='ti ti-refresh me-1'></i>Reabrir</button>" . Html::closeForm(false);
            }
            echo "</div></div>";
            return;
        }
        $etapa = Etapa::all()[$p['code']] ?? [];
        $missing = Fase::missing($this, $p);
        $can = Fase::canAct($this, $p);
        $late = Fase::isLate($p);
        $ticket = (int) $p['tickets_id'];
        echo "<div class='card mb-3 cf-current" . ($late ? ' late' : '') . "'><div class='card-header'><h3 class='card-title'><i class='ti ti-player-play me-2'></i>Fase atual: " . $e(Fase::name($p['code'])) . "</h3>"
            . "<div class='card-actions small text-secondary'>" . $e(Fase::area($p)) . ' · há ' . Menu::days(Fase::elapsed($p)) . ' dia(s)' . ((int) $p['slo_days'] ? ' · SLO ' . (int) $p['slo_days'] . ' dia(s)' : '')
            . ($late ? " · <b class='text-danger'>atrasada</b>" : '') . ($ticket ? " · <a href='" . $e(\Ticket::getFormURLWithID($ticket)) . "'>chamado #$ticket</a>" : '') . "</div></div><div class='card-body'>";
        echo "<p class='mb-2'>" . $e($etapa['description'] ?? '') . "</p>";
        if ($missing) {
            echo "<div class='alert alert-warning py-2 mb-3'><b>Para concluir:</b><ul class='mb-0'>" . implode('', array_map(fn ($m) => '<li>' . $e($m) . '</li>', $missing)) . "</ul></div>";
        } else {
            echo "<div class='alert alert-success py-2 mb-3'><i class='ti ti-check me-1'></i>Tudo o que a fase pede está preenchido.</div>";
        }
        if ($can) {
            $before = array_filter($phases, fn ($x) => $x['position'] < $p['position'] && in_array($x['status'], ['done', 'skipped'], true));
            echo "<form method='post' action='$action' class='row g-2 align-items-end'><input type='hidden' name='id' value='" . (int) $this->getID() . "'>";
            echo "<div class='col-md-5'><label class='form-label'>Comentário / motivo</label><input class='form-control' name='comment' maxlength='1000' placeholder='Obrigatório para devolver ou pular'></div>";
            echo "<div class='col-auto'><button class='btn btn-success' name='workflow' value='conclude'" . ($missing ? ' disabled' : '') . "><i class='ti ti-check me-1'></i>Concluir fase</button></div>";
            if ($before) {
                echo "<div class='col-md-3'><label class='form-label'>Devolver para</label><select class='form-select' name='target'>";
                foreach (array_reverse($before, true) as $code => $x) {
                    echo "<option value='" . $e($code) . "'>" . $e(Fase::name($code) . ' (' . Fase::area($x) . ')') . "</option>";
                }
                echo "</select></div><div class='col-auto'><button class='btn btn-outline-danger' name='workflow' value='return'><i class='ti ti-arrow-back-up me-1'></i>Devolver</button></div>";
            }
            echo "<div class='col-auto ms-auto d-flex gap-2'><button class='btn btn-ghost-secondary' name='workflow' value='comment' title='Registrar comentário na linha do tempo'><i class='ti ti-message'></i></button>";
            if (Session::haveRight(self::$rightname, UPDATE)) {
                echo "<button class='btn btn-ghost-secondary' name='workflow' value='skip' title='Pular esta fase (com motivo)'><i class='ti ti-player-skip-forward'></i></button>"
                    . "<button class='btn btn-ghost-danger' name='workflow' value='cancel' title='Cancelar o Ciclo (com motivo)' onclick=\"return confirm('Cancelar este Ciclo?')\"><i class='ti ti-ban'></i></button>";
            }
            echo "</div>" . Html::closeForm(false);
        } else {
            echo "<div class='text-secondary small'>Quem executa esta fase é o grupo <b>" . $e(Fase::area($p)) . "</b>.</div>";
        }
        echo "</div></div>";
    }

    private function showItems(bool $edit): void
    {
        $e = [Menu::class, 'e'];
        $action = $e(self::getFormURL());
        $traits = self::SALE_TYPES[$this->fields['sale_type']][1] ?? [];
        $phases = Fase::forCiclo($this->getID());
        echo "<div class='row g-3 mb-3'>";
        foreach (Item::KINDS as $kind => [$title, $icon, $phase, $cols]) {
            if (!isset($phases[$phase]) && !in_array($kind, ['pedido_pa', 'rm', 'pedido', 'parcela'], true)) {
                continue; // tipo de item de uma fase que não vale para esta venda
            }
            $rows = Item::forCiclo($this->getID(), $kind);
            $total = array_sum(array_map(fn ($r) => (float) $r['amount'], $rows));
            echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti $icon me-2'></i>" . $e($title) . "</h3>"
                . "<div class='card-actions small text-secondary'>" . count($rows) . ($total ? ' · ' . Menu::money($total) : '') . ' · fase ' . $e(Fase::name($phase)) . "</div></div>";
            echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter cf-items'><thead><tr>";
            foreach ($cols as $label) {
                echo "<th>" . $e($label) . "</th>";
            }
            echo "<th></th></tr></thead><tbody>";
            foreach ($rows as $r) {
                echo "<tr>";
                foreach (array_keys($cols) as $field) {
                    $v = Item::show($kind, $field, $r);
                    $cls = $field === 'status' ? ($r['status'] === 'ok' ? 'text-success fw-bold' : ($r['status'] === 'nok' ? 'text-danger fw-bold' : 'text-warning')) : '';
                    echo "<td class='$cls'>" . $e($v) . "</td>";
                }
                echo "<td class='text-end text-nowrap'>";
                if ($edit) {
                    $quick = [];
                    if (isset(Item::STATUSES[$kind]) && $r['status'] !== 'ok') {
                        $quick[] = ['status', 'ok', 'ti-check', 'Marcar OK'];
                    }
                    if (isset(Item::STATUSES[$kind]) && $r['status'] !== 'nok') {
                        $quick[] = ['status', 'nok', 'ti-x', 'Marcar NOK'];
                    }
                    if (isset($cols['date_done']) && !$r['date_done']) {
                        $quick[] = ['date_done', date('Y-m-d'), 'ti-calendar-check', $kind === 'parcela' ? 'Recebida hoje' : ($kind === 'nf_saida' ? 'Emitida hoje' : 'Hoje')];
                    }
                    foreach ($quick as [$field, $value, $qi, $tip]) {
                        echo "<form method='post' action='$action' class='d-inline'><input type='hidden' name='id' value='" . (int) $this->getID() . "'><input type='hidden' name='item[id]' value='" . (int) $r['id'] . "'><input type='hidden' name='item[kind]' value='$kind'>"
                            . "<input type='hidden' name='item[$field]' value='" . $e($value) . "'><button class='btn btn-sm btn-ghost-secondary px-1' name='item_save' value='1' title='$tip'><i class='ti $qi'></i></button>" . Html::closeForm(false);
                    }
                    echo "<form method='post' action='$action' class='d-inline'><input type='hidden' name='id' value='" . (int) $this->getID() . "'><input type='hidden' name='item_id' value='" . (int) $r['id'] . "'>"
                        . "<button class='btn btn-sm btn-ghost-danger px-1' name='item_delete' value='1' title='Remover' onclick=\"return confirm('Remover este item?')\"><i class='ti ti-trash'></i></button>" . Html::closeForm(false);
                }
                echo "</td></tr>";
            }
            if (!$rows) {
                echo "<tr><td colspan='" . (count($cols) + 1) . "' class='text-secondary small'>Nenhum item ainda.</td></tr>";
            }
            echo "</tbody></table></div>";
            if ($edit) {
                echo "<form method='post' action='$action' class='card-footer cf-add row g-2 align-items-end'><input type='hidden' name='id' value='" . (int) $this->getID() . "'><input type='hidden' name='item[kind]' value='$kind'>";
                foreach ($cols as $field => $label) {
                    $col = in_array($field, ['description', 'supplier'], true) ? 'col-md-4' : 'col-md-2';
                    $input = match ($field) {
                        'date_expected', 'date_done' => "<input type='date' class='form-control form-control-sm' name='item[$field]'>",
                        'status' => "<select class='form-select form-select-sm' name='item[$field]'>" . implode('', array_map(fn ($k, $v) => "<option value='$k'>" . $e($v) . "</option>", array_keys(Item::STATUSES[$kind]), Item::STATUSES[$kind])) . "</select>",
                        'category' => "<select class='form-select form-select-sm' name='item[$field]'>" . implode('', array_map(fn ($k, $v) => "<option value='$k'" . ($k === (in_array('fisico', $traits, true) ? 'hw' : 'sw') ? ' selected' : '') . ">" . $e($v) . "</option>", array_keys(Item::CATEGORIES), Item::CATEGORIES)) . "</select>",
                        default => "<input class='form-control form-control-sm' name='item[$field]' maxlength='255'>",
                    };
                    echo "<div class='$col'><label class='form-label small mb-0'>" . $e($label) . "</label>$input</div>";
                }
                echo "<div class='col-auto'><button class='btn btn-sm btn-outline-primary' name='item_save' value='1'><i class='ti ti-plus me-1'></i>Adicionar</button></div>" . Html::closeForm(false);
            }
            echo "</div></div>";
        }
        echo "</div>";
    }

    /**
     * Linha do tempo por fase: cada passagem por uma fase vira um bloco (início → fim,
     * duração contra o SLO, quem concluiu e o comentário); devoluções, pulos e marcos
     * do Ciclo aparecem no meio, em ordem cronológica.
     */
    private function showTimeline(): void
    {
        $e = [Menu::class, 'e'];
        $phases = Fase::forCiclo($this->getID());
        $events = array_reverse(Evento::forCiclo($this->getID()));
        $now = $_SESSION['glpi_currenttime'];

        // monta as passagens pelas fases a partir dos eventos
        $entries = [];
        $open = [];
        foreach ($events as $ev) {
            $code = (string) $ev['code'];
            switch ($ev['action']) {
                case 'start':
                    $open[$code] = count($entries);
                    $entries[] = ['type' => 'run', 'code' => $code, 'start' => $ev['date'], 'end' => null, 'outcome' => 'active', 'user' => 0, 'comment' => ''];
                    break;
                case 'done':
                case 'return':
                case 'skip':
                    if (!isset($open[$code])) { // fase sem início registrado (o aviso de fechamento)
                        $open[$code] = count($entries);
                        $entries[] = ['type' => 'run', 'code' => $code, 'start' => $this->fields['date_closing'] ?: $ev['date'], 'end' => null, 'outcome' => 'active', 'user' => 0, 'comment' => ''];
                    }
                    $k = $open[$code];
                    $entries[$k]['end'] = $ev['date'];
                    $entries[$k]['outcome'] = $ev['action'];
                    $entries[$k]['user'] = (int) $ev['users_id'];
                    $entries[$k]['comment'] = (string) $ev['comment'];
                    unset($open[$code]);
                    break;
                default: // aberto, comentário, concluído, cancelado, reaberto
                    $entries[] = ['type' => 'mark', 'action' => $ev['action'], 'code' => $code, 'date' => $ev['date'], 'user' => (int) $ev['users_id'], 'comment' => (string) $ev['comment']];
            }
        }

        $runs = array_filter($entries, fn ($x) => $x['type'] === 'run');
        $doneRuns = count(array_filter($runs, fn ($x) => $x['outcome'] === 'done'));
        $returns = count(array_filter($runs, fn ($x) => $x['outcome'] === 'return'));
        $lateRuns = 0;
        foreach ($runs as $r) {
            $slo = (int) ($phases[$r['code']]['slo_days'] ?? 0);
            $secs = strtotime($r['end'] ?: $now) - strtotime($r['start']);
            $lateRuns += $slo && $secs > $slo * 86400 ? 1 : 0;
        }

        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-timeline-event me-2'></i>Linha do tempo</h3>"
            . "<div class='card-actions small text-secondary'>" . Menu::days(self::cycleSeconds($this->fields)) . " dias · $doneRuns fase(s) concluída(s) · "
            . ($lateRuns ? "<span class='text-danger'>$lateRuns acima do SLO</span>" : 'todas no SLO') . ($returns ? " · <span class='text-danger'>$returns devolução(ões)</span>" : '') . "</div></div>";
        echo "<div class='card-body'>";
        echo $this->gantt($runs, $phases, $entries);
        // detalhe em tabela: uma linha por passagem, da mais recente para a mais antiga
        echo "<details class='cf-tl-details'><summary><i class='ti ti-table me-1'></i>Detalhe de cada passagem (datas, duração, SLO, responsável e comentários)</summary>";
        echo "<div class='table-responsive mt-2'><table class='table table-sm table-vcenter cf-tl-table'><thead><tr><th>Fase</th><th>Área</th><th>Início</th><th>Fim</th><th class='text-end'>Duração</th><th class='text-end'>SLO</th><th>Situação</th><th>Responsável</th><th>Comentário</th></tr></thead><tbody>";
        foreach (array_reverse($entries) as $x) {
            if ($x['type'] === 'mark') {
                [$label, $icon, $color] = Evento::ACTIONS[$x['action']] ?? [$x['action'], 'ti-point', '#64748b'];
                echo "<tr class='cf-tl-markrow'><td colspan='2'><i class='ti $icon me-1' style='color:$color'></i><b>" . $e($label) . "</b></td><td>" . Menu::date($x['date'], true) . "</td><td></td><td></td><td></td><td></td>"
                    . "<td>" . $e(getUserName($x['user'])) . "</td><td class='small'>" . $e($x['comment']) . "</td></tr>";
                continue;
            }
            $p = $phases[$x['code']] ?? ['code' => $x['code'], 'groups_id' => 0, 'slo_days' => 0];
            $slo = (int) $p['slo_days'];
            $secs = max(0, strtotime($x['end'] ?: $now) - strtotime($x['start']));
            $late = $slo && $secs > $slo * 86400;
            [$label, $cls] = match ($x['outcome']) {
                'done' => $late ? ['Fora do SLO', 'over'] : ['No SLO', 'ok'],
                'return' => ['Devolvida', 'late'],
                'skip' => ['Pulada', 'skip'],
                default => $late ? ['Atrasada', 'late'] : ['Em andamento', 'now'],
            };
            $comment = $x['outcome'] === 'done' && $x['comment'] === 'Fase concluída.' ? '' : $x['comment'];
            echo "<tr><td><b>" . $e(Fase::name($x['code'])) . "</b></td><td class='small'>" . $e(Fase::area($p)) . "</td>"
                . "<td class='text-nowrap'>" . Menu::date($x['start'], true) . "</td><td class='text-nowrap'>" . ($x['end'] ? Menu::date($x['end'], true) : '<i>em andamento</i>') . "</td>"
                . "<td class='text-end fw-bold'>" . Menu::days($secs) . "d</td><td class='text-end text-secondary'>" . ($slo ? $slo . 'd' : '—') . "</td>"
                . "<td><em class='cf-g-chip $cls'>" . $e($label) . ($late && $x['outcome'] !== 'return' ? ' +' . Menu::days($secs - $slo * 86400) . 'd' : '') . "</em></td>"
                . "<td class='small'>" . $e($x['user'] ? getUserName($x['user']) : '—') . "</td><td class='small'>" . nl2br($e($comment)) . "</td></tr>";
        }
        echo "</tbody></table></div></details>";
        echo "</div></div>";
        echo Menu::script('ciclo.js');
    }

    /**
     * Gantt do Ciclo: uma linha por fase, as passagens como barras no tempo (do aviso
     * de fechamento até hoje ou o fim), a cor conta como foi e o traço mostra o SLO.
     */
    private function gantt(array $runs, array $phases, array $entries): string
    {
        $e = [Menu::class, 'e'];
        $now = strtotime($_SESSION['glpi_currenttime']);
        $from = strtotime($this->fields['date_closing'] ?: $this->fields['date_creation']);
        $to = $this->fields['date_end'] ? strtotime($this->fields['date_end']) : $now;
        $span = max(86400, $to - $from);
        $pos = fn ($t) => round(100 * max(0, min($span, $t - $from)) / $span, 2);

        $byCode = [];
        foreach ($runs as $r) {
            $byCode[$r['code']][] = $r;
        }
        // marcas do eixo: começo, fim e datas intermediárias a cada ~1/6 do período
        $ticks = '';
        $step = max(86400, (int) ceil($span / 6 / 86400) * 86400);
        for ($t = $from; $t < $to - $step / 3; $t += $step) {
            $ticks .= "<span style='left:" . $pos($t) . "%'>" . date('d/m', $t) . "</span>";
        }
        $ticks .= "<span class='end' style='left:100%'>" . date('d/m', $to) . "</span>";
        $today = $this->fields['date_end'] ? '' : "<div class='cf-g-today' style='left:100%'><b>hoje</b></div>";

        // a maior fase e a soma acima do SLO, para o resumo
        $longest = ['', 0];
        $overTotal = 0;
        $h = "<div class='cf-g'>";
        $h .= "<div class='cf-g-row cf-g-axis'><div class='cf-g-label'></div><div class='cf-g-track'>$ticks</div></div>";
        foreach ($phases as $code => $p) {
            $list = $byCode[$code] ?? [];
            $slo = (int) $p['slo_days'];
            $total = array_sum(array_map(fn ($r) => max(0, strtotime($r['end'] ?: $_SESSION['glpi_currenttime']) - strtotime($r['start'])), $list));
            if ($total > $longest[1]) {
                $longest = [Fase::name($code), $total];
            }
            $late = $slo && $total > $slo * 86400;
            $overTotal += $late ? $total - $slo * 86400 : 0;
            $status = $p['status'];
            $chip = match (true) {
                $status === 'active' && $late => ["Atrasada", 'late'],
                $status === 'active' => ['Agora', 'now'],
                $status === 'done' && $late => ['Fora do SLO', 'over'],
                $status === 'done' => ['No SLO', 'ok'],
                $status === 'skipped' => ['Pulada', 'skip'],
                default => ['A fazer', 'todo'],
            };
            $n = ($n ?? 0) + 1;
            $h .= "<div class='cf-g-row" . ($status === 'pending' ? ' pending' : '') . "'><div class='cf-g-label'><span class='cf-g-num'>$n</span><div class='cf-g-text'><b>" . $e(Fase::name($code)) . "</b>"
                . "<span>" . $e(Fase::area($p)) . ($list ? ' · ' . Menu::days($total) . 'd' . ($slo ? " / $slo" : '') : ($slo ? " · SLO $slo d" : '')) . "</span>"
                . "</div><em class='cf-g-chip {$chip[1]}'>{$chip[0]}</em></div><div class='cf-g-track'>";
            foreach ($list as $r) {
                $s = strtotime($r['start']);
                $end = $r['end'] ? strtotime($r['end']) : $now;
                $secs = max(0, $end - $s);
                $runLate = $slo && $secs > $slo * 86400;
                $cls = match ($r['outcome']) {
                    'done' => $runLate ? 'over' : 'ok',
                    'return' => 'ret',
                    'skip' => 'skip',
                    default => $runLate ? 'late' : 'now',
                };
                $left = $pos($s);
                $width = max(0.8, $pos($end) - $left);
                $tip = json_encode([
                    'title' => Fase::name($code), 'area' => Fase::area($p),
                    'status' => ['ok' => 'Concluída no SLO', 'over' => 'Concluída fora do SLO', 'ret' => 'Devolvida', 'skip' => 'Pulada', 'now' => 'Em andamento', 'late' => 'Em andamento · atrasada'][$cls],
                    'cls' => $cls, 'from' => Menu::date($r['start'], true), 'to' => $r['end'] ? Menu::date($r['end'], true) : 'agora',
                    'days' => Menu::days($secs) . ' dia(s)', 'slo' => $slo ? "$slo dia(s)" : 'sem SLO',
                    'over' => $runLate ? '+' . Menu::days($secs - $slo * 86400) . ' dia(s)' : '',
                    'who' => $r['user'] ? getUserName($r['user']) : '', 'note' => $r['outcome'] === 'done' && $r['comment'] === 'Fase concluída.' ? '' : $r['comment'],
                ], JSON_UNESCAPED_UNICODE);
                $h .= "<div class='cf-g-bar $cls' style='left:$left%;width:$width%' data-tip='" . $e($tip) . "'>"
                    . ($width > 7 ? "<span>" . Menu::days($secs) . "d</span>" : '') . ($r['outcome'] === 'return' ? "<i class='ti ti-arrow-back-up'></i>" : '') . "</div>";
                // traço do SLO a partir do início da passagem
                if ($slo && in_array($cls, ['over', 'late', 'now', 'ok'], true)) {
                    $sloAt = $pos($s + $slo * 86400);
                    if ($sloAt < 100) {
                        $h .= "<div class='cf-g-slo' style='left:$sloAt%' title='Prazo (SLO) de $slo dia(s)'></div>";
                    }
                }
            }
            $h .= "</div></div>";
        }
        $h .= "<div class='cf-g-row cf-g-foot'><div class='cf-g-label'></div><div class='cf-g-track'>$today</div></div></div>";

        $legend = "<div class='cf-g-legend'><span><i class='ok'></i>No SLO</span><span><i class='over'></i>Concluída fora do SLO</span><span><i class='now'></i>Em andamento</span>"
            . "<span><i class='late'></i>Atrasada</span><span><i class='ret'></i>Devolvida</span><span><i class='slo'></i>Prazo (SLO)</span><span class='text-secondary'>Passe o mouse nas barras para ver datas e motivos.</span></div>";
        $summary = "<div class='cf-g-summary'>"
            . "<div><small>Ciclo</small><b>" . Menu::days(self::cycleSeconds($this->fields)) . " dias</b><span>" . date('d/m/Y', $from) . ' → ' . ($this->fields['date_end'] ? date('d/m/Y', $to) : 'hoje') . "</span></div>"
            . "<div><small>Fase mais longa</small><b>" . $e($longest[0] ?: '—') . "</b><span>" . ($longest[1] ? Menu::days($longest[1]) . ' dia(s)' : '') . "</span></div>"
            . "<div class='" . ($overTotal ? 'bad' : 'good') . "'><small>Tempo além do SLO</small><b>" . ($overTotal ? Menu::days($overTotal) . ' dia(s)' : 'nenhum') . "</b><span>somando as fases</span></div>"
            . "<div class='" . ((int) $this->fields['returns'] ? 'bad' : 'good') . "'><small>Devoluções</small><b>" . (int) $this->fields['returns'] . "</b><span>retrabalho</span></div>"
            . "</div>";
        return $summary . $h . $legend;
    }
}
