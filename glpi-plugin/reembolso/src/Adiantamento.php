<?php

namespace GlpiPlugin\Reembolso;

use Document_Item;
use Html;
use RuntimeException;
use Session;
use Ticket;
use User;

/**
 * Adiantamento de despesas: antes do atendimento, o técnico pede o valor das despesas
 * previstas. As regras são as do reembolso: o pedido vira um PDF que o técnico assina
 * no LibreSign e passa pelo gestor da área, pelo gestor do centro de custo, pelo OTC e
 * pelo financeiro, que paga e anexa o comprovante. Depois do atendimento o técnico
 * presta contas (gasto real e comprovantes) e o financeiro encerra, acertando o saldo.
 */
class Adiantamento extends Lote
{
    public const DRAFT   = 9;
    public const ACCOUNT = 10;
    public const CLOSED  = 11;

    /** status => [rótulo, cor, ícone, quem age] */
    public const STATUSES = [
        self::DRAFT    => ['Rascunho', '#94a3b8', 'ti-pencil', 'Técnico'],
        self::SIGN     => ['Aguardando o técnico', '#a855f7', 'ti-signature', 'Técnico'],
        self::MANAGER  => ['Gestor da área', '#f59e0b', 'ti-user-check', 'Gestor da área'],
        self::CENTER   => ['Gestor do centro de custo', '#f97316', 'ti-building-bank', 'Gestor do centro de custo'],
        self::OTC      => ['OTC', '#0ea5e9', 'ti-file-check', 'OTC'],
        self::FINANCE  => ['Financeiro (a pagar)', '#3b82f6', 'ti-cash', 'Financeiro'],
        self::PAID     => ['Pago · prestar contas', '#14b8a6', 'ti-cash-banknote', 'Técnico'],
        self::ACCOUNT  => ['Prestação de contas em conferência', '#6366f1', 'ti-receipt-tax', 'Financeiro'],
        self::CLOSED   => ['Encerrado', '#22c55e', 'ti-circle-check', ''],
        self::RETURNED => ['Devolvido ao técnico', '#ef4444', 'ti-arrow-back-up', 'Técnico'],
        self::CANCELED => ['Cancelado', '#64748b', 'ti-ban', ''],
    ];

    protected const OPEN = [self::DRAFT, self::SIGN, self::MANAGER, self::CENTER, self::OTC, self::FINANCE, self::RETURNED, self::PAID, self::ACCOUNT];

    protected static string $stepsTable = 'glpi_plugin_reembolso_adiantamentos_etapas';
    protected static string $stepsFk = 'plugin_reembolso_adiantamentos_id';
    protected static string $noun = 'Adiantamento';

    public const ITEMS = 'glpi_plugin_reembolso_adiantamentos_itens';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Adiantamentos' : 'Adiantamento';
    }

    public static function getIcon()
    {
        return 'ti ti-cash-banknote';
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_reembolso_adiantamentos';
    }

    public static function code(int $id): string
    {
        return 'AD-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public static function canCreate(): bool
    {
        return Reembolso::canCreate();
    }

    public function canViewItem(): bool
    {
        return (int) ($this->fields['users_id_recipient'] ?? 0) === (int) Session::getLoginUserID() || parent::canViewItem();
    }

    public function canUpdateItem(): bool
    {
        return $this->canAct() || Reembolso::isApprover();
    }

    /** O técnico (ou quem aprova) edita os dados e as despesas previstas no rascunho e quando o pedido volta. */
    public function isEditable(): bool
    {
        $me = (int) Session::getLoginUserID();
        return in_array((int) $this->fields['status'], [self::DRAFT, self::RETURNED], true)
            && ($me === (int) $this->fields['users_id'] || $me === (int) $this->fields['users_id_recipient'] || Reembolso::isApprover());
    }

    // ------------------------------------------------------------------ quem age

    /** O pedido tem um centro de custo só. */
    public function centers(): array
    {
        $cid = (int) ($this->fields['plugin_reembolso_centrocustos_id'] ?? 0);
        $c = new Centrocusto();
        if (!$cid || !$c->getFromDB($cid)) {
            return [];
        }
        $user = (int) $c->fields['users_id_manager'];
        $group = (int) $c->fields['groups_id_manager'];
        return [$cid => ['user' => $user, 'group' => $group, 'users' => self::people($user, $group)]];
    }

    public function stepUsers(): array
    {
        $cfg = Money::config();
        return match ((int) $this->fields['status']) {
            self::DRAFT, self::PAID => [(int) $this->fields['users_id']],
            self::ACCOUNT => self::people($cfg['finance_users_id'], $cfg['finance_groups_id']) ?: NotificationTargetReembolso::approvers(),
            self::CLOSED => [],
            default => parent::stepUsers(),
        };
    }

    public function canAct(): bool
    {
        $status = (int) $this->fields['status'];
        $me = (int) Session::getLoginUserID();
        return match ($status) {
            self::DRAFT, self::PAID => $me === (int) $this->fields['users_id'] || $me === (int) $this->fields['users_id_recipient'] || Reembolso::isApprover(),
            self::ACCOUNT => in_array($me, $this->stepUsers(), true) || Reembolso::isApprover(),
            self::CLOSED => false,
            default => parent::canAct(),
        };
    }

    /** Até quando o técnico presta contas: fim do atendimento + prazo da configuração. */
    public function deadline(): string
    {
        $end = (string) ($this->fields['date_end'] ?: $this->fields['date_begin'] ?: date('Y-m-d'));
        return date('Y-m-d', strtotime($end . ' +' . Money::config()['advance_account_days'] . ' days'));
    }

    public function isLate(): bool
    {
        return (int) $this->fields['status'] === self::PAID && date('Y-m-d') > $this->deadline();
    }

    // ------------------------------------------------------------------ despesas previstas

    public function items(): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'SELECT' => ['i.*', 't.name AS tipo'],
            'FROM' => self::ITEMS . ' AS i',
            'LEFT JOIN' => [Tipodespesa::getTable() . ' AS t' => ['ON' => ['t' => 'id', 'i' => 'plugin_reembolso_tipodespesas_id']]],
            'WHERE' => ['i.plugin_reembolso_adiantamentos_id' => $this->getID()],
            'ORDER' => 'i.id',
        ]), false);
    }

    public function addItem(array $in): void
    {
        global $DB;
        $types = Tipodespesa::all();
        $type = $types[(int) ($in['plugin_reembolso_tipodespesas_id'] ?? 0)] ?? null;
        if (!$type) {
            throw new RuntimeException('Escolha o tipo de despesa.');
        }
        $qty = Money::parse((string) ($in['quantity'] ?? '1')) ?: 1.0;
        $unit = trim((string) ($in['unit_value'] ?? '')) === '' && $type['mileage'] ? Money::config()['km_rate'] : Money::parse((string) ($in['unit_value'] ?? ''));
        if ($unit <= 0) {
            throw new RuntimeException('Informe o valor unitário da despesa prevista.');
        }
        if ($type['max'] > 0 && !$type['mileage'] && $unit > $type['max']) {
            throw new RuntimeException('O valor de ' . $type['name'] . ' passa do limite de ' . Money::format($type['max']) . ' por despesa.');
        }
        $DB->insert(self::ITEMS, [
            'plugin_reembolso_adiantamentos_id' => $this->getID(), 'plugin_reembolso_tipodespesas_id' => $type['id'],
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255), 'quantity' => $qty, 'unit_value' => $unit,
            'amount' => round($qty * $unit, 2), 'date_creation' => $_SESSION['glpi_currenttime'],
        ]);
        $this->recalc();
    }

    public function deleteItem(int $id): void
    {
        global $DB;
        $DB->delete(self::ITEMS, ['id' => $id, 'plugin_reembolso_adiantamentos_id' => $this->getID()]);
        $this->recalc();
    }

    public function recalc(): void
    {
        global $DB;
        $DB->update(self::getTable(), $this->totals(), ['id' => $this->getID()]);
        $this->getFromDB($this->getID());
    }

    /** Valor solicitado e quantidade de despesas; noturno = horas do atendimento na faixa noturna. */
    protected function totals(): array
    {
        $items = $this->items();
        return ['total' => round(array_sum(array_map(fn ($i) => (float) $i['amount'], $items)), 2), 'expense_count' => count($items),
            'night_count' => (int) $this->fields['minutes_night'] > 0 ? 1 : 0, 'night_total' => 0];
    }

    protected function buildPdf(): string
    {
        return Pdf::adiantamento($this);
    }

    protected function pdfName(): string
    {
        return self::code($this->getID()) . '-adiantamento-' . date('Ymd-His') . '.pdf';
    }

    protected function pdfComment(): string
    {
        return 'Pedido de adiantamento para conferência e assinatura do técnico';
    }

    protected function signRequest(string $name): array
    {
        return [self::code($this->getID()) . ' - Adiantamento de ' . $name, 'Confira o pedido de adiantamento e o termo de responsabilidade e assine para seguir para aprovação.'];
    }

    protected function linked(array $set): void
    {
        // o adiantamento não junta solicitações de reembolso
    }

    // ------------------------------------------------------------------ cadastro

    public function prepareInputForAdd($input)
    {
        $me = (int) Session::getLoginUserID();
        $input['users_id'] = Reembolso::isApprover() ? ((int) ($input['users_id'] ?? 0) ?: $me) : $me;
        $input['users_id_recipient'] = $me;
        $input['status'] = self::DRAFT;
        $input['total'] = 0;
        $input['entities_id'] ??= $_SESSION['glpiactive_entity'] ?? 0;
        $input['date_creation'] = $input['date_mod'] = $_SESSION['glpi_currenttime'];
        return $this->validateHeader($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (empty($input['_workflow'])) {
            if (!$this->isEditable()) {
                Session::addMessageAfterRedirect('Este adiantamento não pode mais ser alterado.', false, ERROR);
                return false;
            }
            foreach (['status', 'total', 'night_total', 'night_count', 'expense_count', 'documents_id_pdf', 'documents_id_signed', 'documents_id_receipt', 'sign_uuid', 'sign_file_id',
                'date_signed', 'date_payment', 'payment_reference', 'spent_total', 'balance', 'date_account', 'account_comment', 'settle_reference', 'date_closed', 'users_id_recipient'] as $k) {
                unset($input[$k]);
            }
            if (!Reembolso::isApprover()) {
                unset($input['users_id']);
            }
        }
        return $this->validateHeader($input);
    }

    /** Horário (hh:mm), datas coerentes e as horas comerciais, fora do horário e noturnas do atendimento previsto. */
    private function validateHeader(array $input)
    {
        foreach (['time_begin', 'time_end'] as $k) {
            if (array_key_exists($k, $input)) {
                $input[$k] = preg_match('/^([01]\d|2[0-3]):[0-5]\d(:\d\d)?$/', (string) $input[$k]) ? substr((string) $input[$k], 0, 5) . ':00' : null;
            }
        }
        foreach (['date_begin', 'date_end', 'date_needed'] as $k) {
            if (array_key_exists($k, $input) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input[$k])) {
                $input[$k] = null;
            }
        }
        $f = $input + ($this->fields ?? []);
        if (!empty($f['date_begin']) && !empty($f['date_end']) && $f['date_end'] < $f['date_begin']) {
            Session::addMessageAfterRedirect('O fim do atendimento não pode ser antes do início.', false, ERROR);
            return false;
        }
        if (!empty($f['time_begin']) && !empty($f['time_end']) && ($f['date_begin'] ?? '') === ($f['date_end'] ?? '') && $f['time_end'] <= $f['time_begin']) {
            Session::addMessageAfterRedirect('O fim do atendimento precisa ser depois do início: se ele passa da meia-noite, ajuste a data do fim.', false, ERROR);
            return false;
        }
        if (array_intersect_key($input, array_flip(['date_begin', 'date_end', 'time_begin', 'time_end']))) {
            $h = Money::hours($f['date_begin'] ?? null, $f['time_begin'] ?? null, $f['date_end'] ?? null, $f['time_end'] ?? null);
            $input['minutes_business'] = $h['business'] ?? 0;
            $input['minutes_outside'] = $h['outside'] ?? 0;
            $input['minutes_night'] = $h['night'] ?? 0;
            $input['night_count'] = ($h['night'] ?? 0) > 0 ? 1 : 0;
        }
        return $input;
    }

    public function post_addItem()
    {
        global $DB;
        $name = self::code($this->getID()) . ' · ' . getUserName((int) $this->fields['users_id']);
        $DB->update(self::getTable(), ['name' => $name], ['id' => $this->getID()]);
        $this->fields['name'] = $name;
        parent::post_addItem();
    }

    public function cleanDBonPurge()
    {
        global $DB;
        $DB->delete(self::ITEMS, ['plugin_reembolso_adiantamentos_id' => $this->getID()]);
        $DB->delete(static::$stepsTable, [static::$stepsFk => $this->getID()]);
    }

    // ------------------------------------------------------------------ fluxo

    public function act(string $action, array $in, array $files = []): string
    {
        global $DB;
        $status = (int) $this->fields['status'];
        $me = (int) Session::getLoginUserID();
        $comment = trim((string) ($in['comment'] ?? ''));
        switch ($action) {
            case 'submit':
            case 'resend':
                if (!$this->canAct() || !in_array($status, [self::DRAFT, self::RETURNED], true)) {
                    throw new RuntimeException('Este adiantamento não está em rascunho nem devolvido.');
                }
                $missing = array_filter([
                    !(int) $this->fields['plugin_reembolso_centrocustos_id'] ? 'o centro de custo' : '',
                    !$this->fields['date_begin'] || !$this->fields['date_end'] ? 'as datas do atendimento' : '',
                    !$this->fields['time_begin'] || !$this->fields['time_end'] ? 'o horário do atendimento' : '',
                    !$this->items() ? 'pelo menos uma despesa prevista' : '',
                ]);
                if ($missing) {
                    throw new RuntimeException('Antes de enviar, informe ' . implode(', ', $missing) . '.');
                }
                if ($status === self::DRAFT) {
                    $cfg = self::techSettings((int) $this->fields['users_id']);
                    $DB->update(self::getTable(), ['users_id_area_manager' => $cfg['manager'], 'groups_id_area_manager' => $cfg['manager_group'], 'date_submit' => $_SESSION['glpi_currenttime']], ['id' => $this->getID()]);
                    $this->getFromDB($this->getID());
                    $this->record('tecnico', $me, 'enviado', $comment ?: 'Pedido de adiantamento enviado para aprovação.');
                } else {
                    $this->record('tecnico', $me, 'reenviado', $comment ?: 'Pedido corrigido e reenviado.');
                }
                $this->setStatus(self::SIGN);
                $this->prepare();
                return 'Pedido enviado: o PDF foi gerado para o técnico conferir e assinar.';

            case 'account':
                if (!$this->canAct() || $status !== self::PAID) {
                    throw new RuntimeException('A prestação de contas é feita depois do pagamento do adiantamento.');
                }
                $spent = Money::parse((string) ($in['spent_total'] ?? ''));
                if ($spent <= 0 && empty($in['nothing_spent'])) {
                    throw new RuntimeException('Informe quanto foi gasto (ou marque que nada foi gasto).');
                }
                $added = 0;
                foreach ($this->uploads($files['proofs'] ?? null) as [$name, $content]) {
                    $this->attach('prestacao-' . self::code($this->getID()) . '-' . $name, $content, 'Prestação de contas: comprovante de despesa');
                    $added++;
                }
                if (!$added && $spent > 0 && !$this->accountDocs()) {
                    throw new RuntimeException('Anexe os comprovantes das despesas.');
                }
                $balance = round((float) $this->fields['total'] - $spent, 2);
                $DB->update(self::getTable(), ['spent_total' => $spent, 'balance' => $balance, 'date_account' => $_SESSION['glpi_currenttime'], 'account_comment' => mb_substr($comment, 0, 2000)], ['id' => $this->getID()]);
                $this->getFromDB($this->getID());
                $this->record('prestacao', $me, 'prestado', 'Gasto ' . Money::format($spent) . ' · ' . self::balanceLabel($balance) . ($comment ? " · $comment" : ''));
                $this->setStatus(self::ACCOUNT);
                $this->notify('account');
                return 'Prestação de contas enviada ao financeiro.';

            case 'reaccount':
                if (!$this->canAct() || $status !== self::ACCOUNT) {
                    throw new RuntimeException('Não há prestação de contas em conferência.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o que precisa ser corrigido na prestação de contas.');
                }
                $this->record('prestacao', $me, 'devolvido', $comment);
                $this->setStatus(self::PAID);
                $this->notify('reaccount', $comment);
                return 'Prestação de contas devolvida ao técnico.';

            case 'close':
                if (!$this->canAct() || $status !== self::ACCOUNT) {
                    throw new RuntimeException('Só uma prestação de contas em conferência pode ser encerrada.');
                }
                $balance = (float) $this->fields['balance'];
                $files = $this->uploads($files['settle'] ?? null);
                if (abs($balance) >= 0.01 && !$files && empty($in['settle_reference'])) {
                    throw new RuntimeException('Informe a referência ou anexe o comprovante do acerto do saldo (' . self::balanceLabel($balance) . ').');
                }
                foreach ($files as [$name, $content]) {
                    $this->attach('acerto-' . self::code($this->getID()) . '-' . $name, $content, 'Acerto do saldo do adiantamento');
                }
                $DB->update(self::getTable(), ['settle_reference' => mb_substr(trim((string) ($in['settle_reference'] ?? '')), 0, 255), 'date_closed' => $_SESSION['glpi_currenttime']], ['id' => $this->getID()]);
                $this->getFromDB($this->getID());
                $this->record('financeiro', $me, 'encerrado', $comment ?: 'Prestação de contas conferida. ' . self::balanceLabel($balance) . '.');
                $this->setStatus(self::CLOSED);
                $this->notify('close');
                return 'Adiantamento encerrado. Todos os envolvidos foram avisados.';

            case 'cancel':
                if (!Reembolso::isApprover() && !($status === self::DRAFT && $this->canAct())) {
                    throw new RuntimeException('Não é possível cancelar este adiantamento.');
                }
                if (in_array($status, [self::PAID, self::ACCOUNT, self::CLOSED, self::CANCELED], true)) {
                    throw new RuntimeException('Um adiantamento pago não pode ser cancelado: encerre com a prestação de contas.');
                }
                if ($comment === '' && $status !== self::DRAFT) {
                    throw new RuntimeException('Informe o motivo do cancelamento.');
                }
                $this->record(self::STEPS[$status] ?? 'tecnico', $me, 'cancelado', $comment ?: 'Rascunho cancelado.');
                $this->cancelSignature();
                $this->setStatus(self::CANCELED);
                return 'Adiantamento cancelado.';
        }
        return parent::act($action, $in, $files);
    }

    /** Arquivos enviados num campo múltiplo: [[nome, conteúdo], ...]. */
    private function uploads(?array $f): array
    {
        if (!$f || !isset($f['name'])) {
            return [];
        }
        $out = [];
        foreach ((array) $f['name'] as $i => $name) {
            $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
            $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
            $size = is_array($f['size']) ? $f['size'][$i] : $f['size'];
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                throw new RuntimeException("O arquivo $name não chegou ao servidor.");
            }
            if ($size > 20 * 1048576) {
                throw new RuntimeException("O arquivo $name passa de 20 MB.");
            }
            $out[] = [preg_replace('/[^\w.\- ]+/u', '_', basename((string) $name)) ?: 'comprovante.pdf', (string) file_get_contents($tmp)];
        }
        return $out;
    }

    /** "Técnico devolve R$ 50,00" / "Empresa paga R$ 30,00 ao técnico" / "Sem saldo". */
    public static function balanceLabel(float $balance): string
    {
        return match (true) {
            $balance >= 0.01 => 'Técnico devolve ' . Money::format($balance),
            $balance <= -0.01 => 'Empresa paga ' . Money::format(-$balance) . ' ao técnico',
            default => 'Sem saldo a acertar',
        };
    }

    /** Documentos da prestação de contas e do acerto (os que não são o PDF, o assinado nem o comprovante). */
    public function accountDocs(): array
    {
        global $DB;
        $skip = array_filter([(int) $this->fields['documents_id_pdf'], (int) $this->fields['documents_id_signed'], (int) $this->fields['documents_id_receipt']]);
        $out = [];
        foreach ($DB->request(['SELECT' => ['d.id', 'd.filename', 'd.comment'], 'FROM' => 'glpi_documents AS d',
            'INNER JOIN' => [Document_Item::getTable() . ' AS di' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE' => ['di.itemtype' => self::class, 'di.items_id' => $this->getID()] + ($skip ? ['NOT' => ['d.id' => $skip]] : []), 'ORDER' => 'd.id']) as $d) {
            $out[] = $d;
        }
        return $out;
    }

    // ------------------------------------------------------------------ avisos

    protected function notify(string $what, string $comment = ''): void
    {
        if (self::$quiet) {
            return;
        }
        $code = self::code($this->getID());
        $tech = (int) $this->fields['users_id'];
        $techName = (string) getUserName($tech);
        $total = Money::format((float) $this->fields['total']);
        $url = self::getFormURLWithID($this->getID());
        $status = (int) $this->fields['status'];
        $facts = ['Adiantamento' => $code, 'Técnico' => $techName, 'Atendimento' => Pdf::when($this->fields), 'Valor' => $total, 'Etapa' => self::STATUSES[$status][0] ?? ''];
        $send = fn (array $users, string $title, string $message, string $level, string $event, bool $teams = true) => Avisos::send([
            'event' => $event, 'users' => $users, 'title' => $title, 'message' => $message, 'level' => $level, 'url' => $url, 'item' => [self::class, $this->getID()], 'facts' => $facts, 'teams' => $teams,
        ]);
        switch ($what) {
            case 'sign':
                \NotificationEvent::raiseEvent('ad_assinar', $this);
                $send([$tech], "Confira e assine o adiantamento $code", "Seu pedido de adiantamento ($total) está num PDF para conferência"
                    . (self::libreSignReady() && $this->fields['sign_uuid'] ? '. O LibreSign enviou o link de assinatura para o seu e-mail.' : '. Abra o pedido, confira e aprove.'), 'warning', 'ad_assinar');
                break;
            case 'approve':
                if (in_array($status, [self::MANAGER, self::CENTER, self::OTC, self::FINANCE], true)) {
                    \NotificationEvent::raiseEvent('ad_aprovar', $this);
                    $status === self::FINANCE
                        ? $send($this->stepUsers(), "Adiantamento $code aprovado: registrar o pagamento", "$techName · $total. Pague e anexe o comprovante no pedido.", 'warning', 'ad_aprovar')
                        : $send($this->stepUsers(), "Adiantamento $code espera a sua aprovação", "$techName · $total · etapa: " . self::STATUSES[$status][3] . '.', 'warning', 'ad_aprovar');
                }
                break;
            case 'return':
                \NotificationEvent::raiseEvent('ad_devolvido', $this);
                $send([$tech], "Adiantamento $code devolvido", "Motivo: $comment. Corrija o pedido e reenvie.", 'danger', 'ad_devolvido');
                break;
            case 'pay':
                \NotificationEvent::raiseEvent('ad_pago', $this);
                $send(array_values(array_diff($this->involved(), [$tech])), "Adiantamento $code pago", "O adiantamento de $techName ($total) foi pago e o comprovante está anexado.", 'success', 'ad_pago');
                $send([$tech], "OK: adiantamento $code pago", "Você recebeu $total" . ($this->fields['date_payment'] ? ' em ' . date('d/m/Y', strtotime((string) $this->fields['date_payment'])) : '')
                    . '. Preste contas com os comprovantes até ' . date('d/m/Y', strtotime($this->deadline())) . '.', 'success', 'ad_ok', false);
                break;
            case 'account':
                \NotificationEvent::raiseEvent('ad_prestacao', $this);
                $send($this->stepUsers(), "Prestação de contas do adiantamento $code", "$techName gastou " . Money::format((float) $this->fields['spent_total']) . " de $total · " . self::balanceLabel((float) $this->fields['balance']) . '.', 'warning', 'ad_prestacao');
                break;
            case 'reaccount':
                $send([$tech], "Prestação de contas do adiantamento $code devolvida", "Motivo: $comment. Corrija e envie de novo.", 'danger', 'ad_prestacao_devolvida', false);
                break;
            case 'close':
                \NotificationEvent::raiseEvent('ad_encerrado', $this);
                $send(array_values(array_diff($this->involved(), [$tech])), "Adiantamento $code finalizado com sucesso", "A prestação de contas de $techName foi conferida. " . self::balanceLabel((float) $this->fields['balance']) . '.', 'success', 'ad_encerrado');
                $send([$tech], "OK final: adiantamento $code encerrado", 'Sua prestação de contas foi conferida pelo financeiro. ' . self::balanceLabel((float) $this->fields['balance']) . '.', 'success', 'ad_ok_final', false);
                break;
        }
    }

    // ------------------------------------------------------------------ GLPI

    public static function getSearchURL($full = true)
    {
        return $full ? Menu::url('/front/adiantamento.php') : '/plugins/reembolso/front/adiantamento.php';
    }

    public static function getFormURL($full = true)
    {
        return $full ? Menu::url('/front/adiantamento.form.php') : '/plugins/reembolso/front/adiantamento.form.php';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $menu = ['title' => 'Adiantamentos', 'page' => self::getSearchURL(false), 'icon' => self::getIcon()];
        if (self::canCreate()) {
            $menu['links']['add'] = self::getFormURL(false);
        }
        return $menu;
    }

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Adiantamento', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'name' => 'Técnico', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 4, 'table' => $t, 'field' => 'status', 'name' => 'Etapa', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'date_begin', 'name' => 'Início do atendimento', 'datatype' => 'date'],
            ['id' => 6, 'table' => $t, 'field' => 'date_end', 'name' => 'Fim do atendimento', 'datatype' => 'date'],
            ['id' => 7, 'table' => $t, 'field' => 'total', 'name' => 'Valor solicitado', 'datatype' => 'decimal'],
            ['id' => 8, 'table' => Centrocusto::getTable(), 'field' => 'name', 'linkfield' => 'plugin_reembolso_centrocustos_id', 'name' => 'Centro de custo', 'datatype' => 'dropdown'],
            ['id' => 9, 'table' => 'glpi_tickets', 'field' => 'id', 'linkfield' => 'tickets_id', 'name' => 'Chamado', 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 10, 'table' => $t, 'field' => 'date_payment', 'name' => 'Pago em', 'datatype' => 'date'],
            ['id' => 11, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_area_manager', 'name' => 'Gestor da área', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 12, 'table' => $t, 'field' => 'spent_total', 'name' => 'Gasto (prestação de contas)', 'datatype' => 'decimal'],
            ['id' => 13, 'table' => $t, 'field' => 'balance', 'name' => 'Saldo', 'datatype' => 'specific', 'massiveaction' => false],
            ['id' => 14, 'table' => $t, 'field' => 'date_needed', 'name' => 'Valor necessário até', 'datatype' => 'date'],
            ['id' => 15, 'table' => $t, 'field' => 'minutes_night', 'name' => 'Horas de adicional noturno', 'datatype' => 'specific', 'massiveaction' => false],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        return match ($field) {
            'balance' => self::e(self::balanceLabel((float) ($values[$field] ?? 0))),
            'minutes_night' => (int) ($values[$field] ?? 0) ? "<span class='badge rb-night-badge'><i class='ti ti-moon-stars me-1'></i>" . Money::minutes((int) $values[$field]) . '</span>' : '—',
            default => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    // ------------------------------------------------------------------ tela

    public function showForm($ID, array $options = [])
    {
        $e = [self::class, 'e'];
        $new = $this->isNewID($ID);
        if ($new) {
            $this->getEmpty();
            $this->fields['users_id'] = (int) Session::getLoginUserID();
            $this->fields['entities_id'] = (int) ($_SESSION['glpiactive_entity'] ?? 0);
            $this->fields['status'] = self::DRAFT;
        }
        $f = $this->fields;
        $status = (int) $f['status'];
        $edit = $new || $this->isEditable();
        $dis = $edit ? '' : 'disabled';
        $action = $e(self::getFormURL());
        $hidden = "<input type='hidden' name='id' value='" . (int) $this->getID() . "'>";

        echo "<div class='rb'>";
        echo "<div class='rb-head card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'>";
        echo "<div class='rb-head-icon rb-head-advance'><i class='ti ti-cash-banknote'></i></div><div class='me-auto'>";
        echo "<div class='text-secondary small text-uppercase fw-bold'>Adiantamento de despesas</div>";
        echo "<h2 class='m-0'>" . ($new ? 'Novo adiantamento' : self::code($this->getID()) . ' · ' . $e(getUserName((int) $f['users_id']))) . "</h2>";
        echo "<div class='text-secondary small rb-subtitle mt-1'>" . $e(Menu::SUBTITLES[$new ? 'adiantamento_new' : 'adiantamento_form']) . "</div>";
        if (!$new) {
            echo "<div class='mt-1 d-flex flex-wrap gap-1'>" . self::badge($status) . ($this->isLate() ? "<span class='badge bg-red-lt'><i class='ti ti-alarm me-1'></i>Prestação de contas atrasada</span>" : '') . '</div>';
        }
        echo "</div>";
        if (!$new) {
            $spent = in_array($status, [self::ACCOUNT, self::CLOSED], true);
            echo "<div class='rb-lote-kpis'><div><small>Solicitado</small><b>" . Money::format((float) $f['total']) . "</b></div>"
                . ($spent ? "<div><small>Gasto</small><b>" . Money::format((float) $f['spent_total']) . "</b></div><div class='" . ((float) $f['balance'] >= 0.01 ? 'night' : '') . "'><small>Saldo</small><b>" . $e(self::balanceLabel((float) $f['balance'])) . '</b></div>'
                    : "<div class='night'><small>Prestar contas até</small><b>" . Html::convDate($this->deadline()) . '</b></div>')
                . '</div>';
        }
        echo "</div>";
        if (!$new) {
            $steps = [self::SIGN => 'Técnico assina', self::MANAGER => 'Gestor da área', self::CENTER => 'Gestor do centro de custo', self::OTC => 'OTC', self::FINANCE => 'Financeiro paga',
                self::PAID => 'Prestação de contas', self::ACCOUNT => 'Financeiro confere', self::CLOSED => 'Encerrado'];
            $order = array_keys($steps);
            $cur = match ($status) {
                self::DRAFT, self::RETURNED => self::SIGN,
                default => $status,
            };
            $pos = array_search($cur, $order, true);
            $cfg = Money::config();
            $who = [
                self::SIGN => getUserName((int) $f['users_id']),
                self::MANAGER => $f['users_id_area_manager'] || $f['groups_id_area_manager'] ? self::whoLabel((int) $f['users_id_area_manager'], (int) $f['groups_id_area_manager']) : 'definido no envio',
                self::CENTER => implode(', ', array_map(fn ($c) => self::whoLabel($c['user'], $c['group']), $this->centers())) ?: 'quem aprova reembolsos',
                self::OTC => self::whoLabel($cfg['otc_users_id'], $cfg['otc_groups_id']),
                self::FINANCE => self::whoLabel($cfg['finance_users_id'], $cfg['finance_groups_id']),
                self::PAID => 'até ' . Html::convDate($this->deadline()),
                self::ACCOUNT => self::whoLabel($cfg['finance_users_id'], $cfg['finance_groups_id']),
                self::CLOSED => $f['date_closed'] ? Html::convDate($f['date_closed']) : '',
            ];
            echo "<div class='rb-steps rb-steps-lote'>";
            foreach ($order as $i => $k) {
                $cls = in_array($status, [self::CANCELED, self::DRAFT], true) || $pos === false ? '' : ($i < $pos || $status === self::CLOSED ? 'done' : ($i === $pos ? ($status === self::RETURNED ? 'refused' : 'current') : ''));
                echo "<div class='rb-step $cls'><span></span><div>" . $e($status === self::RETURNED && $k === self::SIGN ? 'Devolvido ao técnico' : $steps[$k]) . "<small>" . $e($who[$k] ?? '') . "</small></div></div>";
            }
            echo "</div>";
        }
        echo "</div>";

        if (!$new && $f['last_error'] && $status === self::SIGN) {
            echo "<div class='alert alert-warning'><i class='ti ti-alert-triangle me-1'></i>" . $e($f['last_error']) . "</div>";
        }
        if ($status === self::RETURNED && ($last = $this->decisions()) && end($last)['decision'] === 'devolvido') {
            echo "<div class='alert alert-danger'><b>Devolvido por " . $e(getUserName((int) end($last)['users_id'])) . ':</b> ' . nl2br($e(end($last)['comment'])) . '</div>';
        }

        echo "<div class='row g-3'><div class='" . ($new ? 'col-12' : 'col-xl-8') . "'>";
        // dados do atendimento
        echo "<form method='post' action='$action' class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-id-badge-2 me-2'></i>Dados do atendimento</h3></div><div class='card-body'><div class='row g-3'>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label required'>Técnico</label>"
            . User::dropdown(['name' => 'users_id', 'value' => (int) $f['users_id'], 'right' => 'all', 'entity' => (int) $f['entities_id'], 'display' => false, 'width' => '100%', 'disabled' => !$edit || !Reembolso::isApprover()]) . "</div>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label required'>Centro de custo</label>"
            . Centrocusto::dropdown(['name' => 'plugin_reembolso_centrocustos_id', 'value' => (int) $f['plugin_reembolso_centrocustos_id'], 'entity' => (int) $f['entities_id'], 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label'>Chamado do atendimento</label>"
            . Ticket::dropdown(['name' => 'tickets_id', 'value' => (int) $f['tickets_id'], 'entity' => (int) $f['entities_id'], 'entity_sons' => true, 'display' => false, 'width' => '100%', 'displaywith' => ['id'], 'disabled' => !$edit])
            . ((int) $f['tickets_id'] ? "<div class='form-hint'><a href='" . $e(Ticket::getFormURLWithID((int) $f['tickets_id'])) . "'><i class='ti ti-external-link me-1'></i>Abrir o chamado</a></div>" : '') . "</div>";
        echo "<div class='col-md-6 col-xl-4'><label class='form-label'>Local do atendimento</label><input class='form-control' name='address' maxlength='255' placeholder='Ex.: Av. Paulista, 1000 - São Paulo/SP' value='" . $e($f['address']) . "' $dis></div>";
        $hm = fn ($v) => $v ? substr((string) $v, 0, 5) : '';
        foreach ([['begin', 'Início previsto'], ['end', 'Fim previsto']] as [$k, $label]) {
            echo "<div class='col-md-6 col-xl-4'><label class='form-label required'>$label</label><div class='input-group'>"
                . "<input type='date' class='form-control' name='date_$k' value='" . $e($f["date_$k"]) . "' $dis>"
                . "<input type='time' class='form-control' name='time_$k' value='" . $e($hm($f["time_$k"] ?? '')) . "' style='max-width:120px' $dis></div></div>";
        }
        $c = Money::config();
        echo "<div class='col-md-12 col-xl-8'><label class='form-label'>Horas previstas</label><div class='rb-hours' data-rb-hours"
            . " data-bs='" . $e($c['business_start']) . "' data-be='" . $e($c['business_end']) . "' data-ns='" . $e($c['night_start']) . "' data-ne='" . $e($c['night_end']) . "'>"
            . Reembolso::hoursBadges((int) $f['minutes_business'], (int) $f['minutes_outside'], (int) $f['minutes_night'], (bool) ($f['time_begin'] && $f['time_end'])) . "</div></div>";
        echo Html::scriptBlock(Reembolso::hoursScript());
        echo "<div class='col-md-6 col-xl-4'><label class='form-label'>Preciso do valor até</label><input type='date' class='form-control' name='date_needed' value='" . $e($f['date_needed']) . "' $dis></div>";
        echo "<div class='col-12'><label class='form-label'>Justificativa</label><textarea class='form-control' rows='2' name='comment' placeholder='Para que é o adiantamento: cliente, deslocamento, hospedagem…' $dis>" . $e($f['comment']) . "</textarea></div>";
        echo "</div></div>";
        if ($edit) {
            echo "<div class='card-footer d-flex gap-2'>";
            if ($new) {
                echo "<input type='hidden' name='entities_id' value='" . (int) $f['entities_id'] . "'><button class='btn btn-primary' name='add' value='1'><i class='ti ti-arrow-right me-1'></i>Criar e lançar as despesas previstas</button>";
            } else {
                echo "$hidden<button class='btn btn-primary' name='update' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar dados</button>";
            }
            echo "</div>";
        }
        Html::closeForm();

        if ($new) {
            echo "<div class='card mb-3'><div class='card-body d-flex gap-3 align-items-start'><i class='ti ti-route fs-1 text-primary'></i><div>"
                . 'Depois de criar, lance as <b>despesas previstas</b> (tipo, quantidade e valor) e clique em <b>Enviar para aprovação</b>. '
                . 'O pedido segue a mesma esteira do reembolso: o técnico assina o PDF, aprovam o gestor da área, o gestor do centro de custo e o OTC, e o financeiro paga com o comprovante. '
                . 'Depois do atendimento, o técnico presta contas com os comprovantes até <b>' . (int) $c['advance_account_days'] . ' dia(s)</b> após o fim.</div></div></div>';
            echo "</div></div></div>";
            return true;
        }

        $this->showItems($edit, $action, $hidden);
        if (in_array($status, [self::PAID, self::ACCOUNT, self::CLOSED], true)) {
            $this->showAccount();
        }
        echo "</div><div class='col-xl-4'>";
        $this->showActions($action, $hidden);
        $this->showFiles();
        $this->showTimeline();
        echo "</div></div></div>";
        return true;
    }

    private function showItems(bool $edit, string $action, string $hidden): void
    {
        $e = [self::class, 'e'];
        $items = $this->items();
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-list-details me-2'></i>Despesas previstas</h3>"
            . "<div class='card-actions small text-secondary'>" . count($items) . ' item(ns)</div></div>';
        echo "<div class='table-responsive'><table class='table table-vcenter card-table rb-table'><thead><tr><th>Tipo</th><th>Descrição</th><th class='text-end'>Qtd.</th><th class='text-end'>Valor unit.</th><th class='text-end'>Total</th>"
            . ($edit ? '<th></th>' : '') . '</tr></thead><tbody>';
        if (!$items) {
            echo "<tr><td colspan='6' class='text-secondary text-center py-4'>Nenhuma despesa prevista ainda.</td></tr>";
        }
        foreach ($items as $x) {
            echo '<tr><td>' . $e($x['tipo']) . '</td><td>' . $e($x['description']) . "</td><td class='text-end'>" . rtrim(rtrim(number_format((float) $x['quantity'], 2, ',', '.'), '0'), ',') . '</td>'
                . "<td class='text-end text-nowrap'>" . Money::format((float) $x['unit_value']) . "</td><td class='text-end text-nowrap fw-bold'>" . Money::format((float) $x['amount']) . '</td>'
                . ($edit ? "<td class='text-end'><form method='post' action='$action' class='d-inline'>$hidden<button class='btn btn-sm btn-ghost-danger' name='item_delete' value='" . (int) $x['id'] . "' title='Remover'><i class='ti ti-trash'></i></button>" . Html::closeForm(false) . '</td>' : '')
                . '</tr>';
        }
        echo "</tbody><tfoot><tr><th colspan='4' class='text-end'>Valor solicitado</th><th class='text-end text-nowrap rb-total-sm'>" . Money::format((float) $this->fields['total']) . '</th>' . ($edit ? '<th></th>' : '') . '</tr></tfoot></table></div>';
        if ($edit) {
            $opts = '';
            foreach (Tipodespesa::all() as $t) {
                $opts .= "<option value='{$t['id']}' data-km='" . (int) $t['mileage'] . "'>" . $e($t['name']) . ($t['max'] > 0 && !$t['mileage'] ? ' (até ' . Money::format($t['max']) . ')' : '') . '</option>';
            }
            echo "<form method='post' action='$action' class='card-footer'>$hidden<div class='row g-2 align-items-end rb-ad-add'>"
                . "<div class='col-md-3'><label class='form-label'>Tipo</label><select class='form-select' name='plugin_reembolso_tipodespesas_id' required><option value=''>Escolha…</option>$opts</select></div>"
                . "<div class='col-md-4'><label class='form-label'>Descrição</label><input class='form-control' name='description' maxlength='255' placeholder='Ex.: 2 diárias de hotel em Campinas'></div>"
                . "<div class='col-6 col-md-1'><label class='form-label'>Qtd.</label><input class='form-control' name='quantity' value='1' inputmode='decimal'></div>"
                . "<div class='col-6 col-md-2'><label class='form-label'>Valor unit.</label><input class='form-control' name='unit_value' inputmode='decimal' placeholder='0,00'></div>"
                . "<div class='col-md-2'><button class='btn btn-primary w-100' name='item_add' value='1'><i class='ti ti-plus me-1'></i>Adicionar</button></div></div>"
                . "<div class='form-hint mt-1'>Quilometragem: em Qtd. informe os KM; sem valor unitário, vale o valor por KM da configuração (" . Money::format(Money::config()['km_rate']) . ').</div>'
                . Html::closeForm(false);
        }
        echo '</div>';
    }

    private function showAccount(): void
    {
        $e = [self::class, 'e'];
        $f = $this->fields;
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-receipt-tax me-2'></i>Prestação de contas</h3>"
            . "<div class='card-actions small text-secondary'>prazo: " . Html::convDate($this->deadline()) . '</div></div><div class=\'card-body\'>';
        if (!$f['date_account']) {
            echo "<div class='text-secondary'>" . ($this->isLate() ? "<span class='text-danger fw-bold'>Atrasada.</span> " : '') . 'O técnico ainda não prestou contas deste adiantamento.</div></div></div>';
            return;
        }
        $bal = (float) $f['balance'];
        echo "<div class='rb-ad-account'><div><small>Adiantado</small><b>" . Money::format((float) $f['total']) . "</b></div><div><small>Gasto</small><b>" . Money::format((float) $f['spent_total']) . '</b></div>'
            . "<div class='" . ($bal >= 0.01 ? 'give' : ($bal <= -0.01 ? 'get' : 'zero')) . "'><small>Saldo</small><b>" . $e(self::balanceLabel($bal)) . '</b></div></div>'
            . "<div class='small text-secondary mt-2'>Enviada em " . Html::convDateTime($f['date_account']) . ($f['account_comment'] ? ' · ' . $e($f['account_comment']) : '') . '</div>'
            . ($f['settle_reference'] ? "<div class='small mt-1'><b>Acerto:</b> " . $e($f['settle_reference']) . '</div>' : '');
        echo '</div></div>';
    }

    protected function showActions(string $action, string $hidden): void
    {
        $status = (int) $this->fields['status'];
        if (!in_array($status, [self::DRAFT, self::SIGN, self::RETURNED, self::PAID, self::ACCOUNT, self::CLOSED, self::CANCELED], true)) {
            parent::showActions($action, $hidden);
            return;
        }
        $e = [self::class, 'e'];
        $who = $this->stepUsers();
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-git-pull-request me-2'></i>Etapa atual</h3></div><div class='card-body'>";
        echo "<div class='mb-2'>" . self::badge($status) . '</div>';
        if ($who && !in_array($status, [self::CLOSED, self::CANCELED], true)) {
            echo "<div class='small text-secondary mb-3'>Com: " . $e(implode(', ', array_map('getUserName', $who))) . '</div>';
        }
        $f = $this->fields;
        if (!$this->canAct()) {
            echo "<div class='text-secondary small'>" . match ($status) {
                self::CLOSED => 'Encerrado em ' . Html::convDateTime($f['date_closed']) . '. ' . $e(self::balanceLabel((float) $f['balance'])) . '.',
                self::CANCELED => 'Adiantamento cancelado.',
                default => 'Nenhuma ação sua nesta etapa.',
            } . '</div>';
        } elseif ($status === self::DRAFT || $status === self::RETURNED) {
            echo "<div class='small mb-2'>" . ($status === self::DRAFT ? 'Confira os dados e as despesas previstas e envie. Um PDF é gerado para o técnico conferir e assinar.' : 'Corrija os dados ou as despesas previstas e reenvie. Um novo PDF é gerado para conferência e assinatura.') . '</div>'
                . "<form method='post' action='$action'>$hidden<input class='form-control mb-2' name='comment' maxlength='500' placeholder='" . ($status === self::DRAFT ? 'Comentário (opcional)' : 'O que foi corrigido') . "'>"
                . "<button class='btn btn-primary w-100' name='lote_action' value='" . ($status === self::DRAFT ? 'submit' : 'resend') . "'><i class='ti ti-send me-1'></i>" . ($status === self::DRAFT ? 'Enviar para aprovação' : 'Reenviar o pedido') . '</button>' . Html::closeForm(false);
            if ($status === self::DRAFT) {
                echo "<form method='post' action='$action' class='mt-2'>$hidden<button class='btn btn-sm btn-ghost-danger' name='lote_action' value='cancel' onclick=\"return confirm('Cancelar este rascunho?')\"><i class='ti ti-ban me-1'></i>Cancelar o rascunho</button>" . Html::closeForm(false);
            }
        } elseif ($status === self::SIGN) {
            if ($f['sign_uuid']) {
                echo "<div class='alert alert-info py-2 small'><i class='ti ti-mail me-1'></i>O PDF foi enviado ao e-mail do técnico pelo LibreSign. Assinado lá, o pedido segue sozinho (o GLPI confere a cada 10 minutos)."
                    . "<form method='post' action='$action' class='mt-2'>$hidden<button class='btn btn-sm btn-outline-primary' name='lote_action' value='check'><i class='ti ti-refresh me-1'></i>Conferir a assinatura agora</button>" . Html::closeForm(false) . '</div>';
            }
            echo "<form method='post' action='$action'>$hidden<label class='form-check'><input class='form-check-input' type='checkbox' name='confirm' value='1'>"
                . "<span class='form-check-label'>Conferi o pedido e aceito o termo de responsabilidade (prestar contas até " . Html::convDate($this->deadline()) . ').</span></label>'
                . "<input class='form-control my-2' name='comment' maxlength='500' placeholder='Comentário (opcional)'>"
                . "<button class='btn btn-success w-100' name='lote_action' value='sign'><i class='ti ti-signature me-1'></i>" . ($f['sign_uuid'] ? 'Aprovar sem a assinatura do LibreSign' : 'Aprovar o pedido') . '</button>' . Html::closeForm(false)
                . "<form method='post' action='$action' class='mt-3'>$hidden<label class='form-label'>Algo está errado</label><textarea class='form-control mb-2' name='comment' rows='2' placeholder='Motivo (obrigatório)'></textarea>"
                . "<button class='btn btn-outline-danger w-100' name='lote_action' value='contest'><i class='ti ti-arrow-back-up me-1'></i>Devolver para correção</button>" . Html::closeForm(false);
        } elseif ($status === self::PAID) {
            echo "<div class='small mb-2'>Informe o total gasto no atendimento e anexe os comprovantes. O saldo (o que sobrou ou faltou) é acertado pelo financeiro.</div>"
                . "<form method='post' action='$action' enctype='multipart/form-data'>$hidden"
                . "<label class='form-label required'>Total gasto</label><div class='input-group mb-2'><span class='input-group-text'>R$</span><input class='form-control' name='spent_total' inputmode='decimal' placeholder='0,00'></div>"
                . "<label class='form-check mb-2'><input class='form-check-input' type='checkbox' name='nothing_spent' value='1'><span class='form-check-label'>Não gastei nada (devolvo o valor todo)</span></label>"
                . "<label class='form-label'>Comprovantes</label><input type='file' class='form-control mb-2' name='proofs[]' multiple>"
                . "<input class='form-control mb-2' name='comment' maxlength='500' placeholder='Observações (opcional)'>"
                . "<button class='btn btn-primary w-100' name='lote_action' value='account'><i class='ti ti-receipt-tax me-1'></i>Enviar a prestação de contas</button>" . Html::closeForm(false);
        } elseif ($status === self::ACCOUNT) {
            $bal = (float) $f['balance'];
            echo "<div class='rb-ad-balance " . ($bal >= 0.01 ? 'give' : ($bal <= -0.01 ? 'get' : 'zero')) . "'><small>Saldo a acertar</small><b>" . $e(self::balanceLabel($bal)) . '</b></div>'
                . "<form method='post' action='$action' enctype='multipart/form-data' class='mt-2'>$hidden"
                . (abs($bal) >= 0.01 ? "<label class='form-label'>Comprovante do acerto</label><input type='file' class='form-control mb-2' name='settle[]' multiple>"
                    . "<input class='form-control mb-2' name='settle_reference' maxlength='255' placeholder='Referência do acerto (PIX, desconto em folha…)'>" : '')
                . "<input class='form-control mb-2' name='comment' maxlength='500' placeholder='Comentário (opcional)'>"
                . "<button class='btn btn-success w-100' name='lote_action' value='close'><i class='ti ti-circle-check me-1'></i>Conferir e encerrar</button>" . Html::closeForm(false)
                . "<form method='post' action='$action' class='mt-3'>$hidden<label class='form-label'>Devolver a prestação de contas</label><textarea class='form-control mb-2' name='comment' rows='2' placeholder='O que precisa ser corrigido (obrigatório)'></textarea>"
                . "<button class='btn btn-outline-danger w-100' name='lote_action' value='reaccount'><i class='ti ti-arrow-back-up me-1'></i>Devolver ao técnico</button>" . Html::closeForm(false);
        }
        if (Reembolso::isApprover() && in_array($status, [self::SIGN, self::RETURNED], true)) {
            echo "<details class='mt-3 small'><summary class='text-secondary'>Cancelar o adiantamento</summary><form method='post' action='$action' class='mt-2'>$hidden"
                . "<input class='form-control mb-2' name='comment' placeholder='Motivo (obrigatório)'><button class='btn btn-sm btn-ghost-danger' name='lote_action' value='cancel' onclick=\"return confirm('Confirma o cancelamento?')\">Cancelar o adiantamento</button>" . Html::closeForm(false) . '</details>';
        }
        echo '</div></div>';
    }

    protected function showFiles(): void
    {
        parent::showFiles();
        $docs = $this->accountDocs();
        if (!$docs) {
            return;
        }
        $e = [self::class, 'e'];
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-paperclip me-2'></i>Comprovantes da prestação de contas</h3></div><div class='card-body d-flex flex-column gap-2'>";
        foreach ($docs as $d) {
            $url = Html::getPrefixedUrl('/front/document.send.php?docid=' . (int) $d['id'] . '&itemtype=' . rawurlencode(self::class) . '&items_id=' . $this->getID());
            echo "<a class='rb-lote-file' href='" . $e($url) . "' target='_blank' rel='noopener'><i class='ti ti-file-invoice'></i><span><b>" . $e($d['comment'] ?: 'Comprovante') . '</b><small>' . $e($d['filename']) . "</small></span><i class='ti ti-arrow-up-right'></i></a>";
        }
        echo '</div></div>';
    }
}
