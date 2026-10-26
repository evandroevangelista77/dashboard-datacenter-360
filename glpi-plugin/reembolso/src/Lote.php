<?php

namespace GlpiPlugin\Reembolso;

use CommonDBTM;
use CronTask;
use Document;
use Document_Item;
use Group_User;
use Html;
use Log;
use Plugin;
use RuntimeException;
use Session;
use Throwable;
use User;

/**
 * Lote de reembolsos: tudo o que um técnico lançou num período (diário, semanal,
 * quinzenal ou mensal) vira um PDF único, que ele recebe por e-mail para conferir e
 * assinar no LibreSign. Depois o lote passa pelo gestor da área, pelo gestor de cada
 * centro de custo, pelo OTC e chega ao financeiro, que paga e anexa o comprovante.
 * Qualquer etapa pode devolver o lote ao técnico, com o motivo.
 */
class Lote extends CommonDBTM
{
    public static string $rightname = 'plugin_reembolso';
    public bool $dohistory = true;

    public const SIGN     = 1;
    public const MANAGER  = 2;
    public const CENTER   = 3;
    public const OTC      = 4;
    public const FINANCE  = 5;
    public const PAID     = 6;
    public const RETURNED = 7;
    public const CANCELED = 8;

    /** status => [rótulo, cor, ícone, quem age] */
    public const STATUSES = [
        self::SIGN     => ['Aguardando o técnico', '#a855f7', 'ti-signature', 'Técnico'],
        self::MANAGER  => ['Gestor da área', '#f59e0b', 'ti-user-check', 'Gestor da área'],
        self::CENTER   => ['Gestor do centro de custo', '#f97316', 'ti-building-bank', 'Gestor do centro de custo'],
        self::OTC      => ['OTC', '#0ea5e9', 'ti-file-check', 'OTC'],
        self::FINANCE  => ['Financeiro (a pagar)', '#3b82f6', 'ti-cash', 'Financeiro'],
        self::PAID     => ['Pago', '#22c55e', 'ti-circle-check', ''],
        self::RETURNED => ['Devolvido ao técnico', '#ef4444', 'ti-arrow-back-up', 'Técnico'],
        self::CANCELED => ['Cancelado', '#64748b', 'ti-ban', ''],
    ];

    /** etapa do status => chave gravada nas decisões */
    public const STEPS = [self::SIGN => 'tecnico', self::MANAGER => 'gestor', self::CENTER => 'centro', self::OTC => 'otc', self::FINANCE => 'financeiro'];

    public const PERIODS = ['diario' => 'Diário', 'semanal' => 'Semanal', 'quinzenal' => 'Quinzenal', 'mensal' => 'Mensal'];

    /** Dados de exemplo e testes: não pede assinatura no LibreSign nem manda avisos (e-mail, sino, Teams). */
    public static bool $quiet = false;

    /** Tabela das decisões, a chave do item nela e o nome do item nas mensagens (o Adiantamento troca). */
    protected static string $stepsTable = 'glpi_plugin_reembolso_lotes_etapas';
    protected static string $stepsFk = 'plugin_reembolso_lotes_id';
    protected static string $noun = 'Lote';

    /** Etapas em que o item ainda espera alguém (painel "Esperando você"). */
    protected const OPEN = [self::SIGN, self::MANAGER, self::CENTER, self::OTC, self::FINANCE, self::RETURNED];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Lotes de reembolso' : 'Lote de reembolso';
    }

    public static function getIcon()
    {
        return 'ti ti-stack-2';
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_reembolso_lotes';
    }

    public static function code(int $id): string
    {
        return 'LT-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    protected static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    // ------------------------------------------------------------------ direitos

    public static function canView(): bool
    {
        return Reembolso::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return Reembolso::canView();
    }

    public function canViewItem(): bool
    {
        $me = (int) Session::getLoginUserID();
        return Session::haveRight(Reembolso::$rightname, Reembolso::READALL) || (int) $this->fields['users_id'] === $me
            || in_array($me, $this->involved(), true) || $this->canAct();
    }

    public function canUpdateItem(): bool
    {
        return $this->canAct() || Reembolso::isApprover();
    }

    /** Lotes em que a pessoa decidiu alguma etapa (para a busca de quem não vê todos). */
    public static function idsFor(int $uid): array
    {
        global $DB;
        $ids = array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => static::$stepsFk, 'DISTINCT' => true,
            'FROM' => static::$stepsTable, 'WHERE' => ['users_id' => $uid]])), static::$stepsFk));
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => static::getTable(), 'WHERE' => ['is_deleted' => 0, 'status' => [self::CENTER, self::OTC, self::FINANCE]]]) as $r) {
            $l = new static();
            if ($l->getFromDB((int) $r['id']) && in_array($uid, $l->stepUsers(), true)) {
                $ids[] = (int) $r['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    // ------------------------------------------------------------------ quem aprova

    /**
     * Periodicidade e gestor da área do técnico: o definido para ele na configuração
     * (usuário ou grupo), senão o "Responsável" do usuário no GLPI, senão o padrão da etapa.
     */
    public static function techSettings(int $uid): array
    {
        global $DB;
        $row = $DB->request(['FROM' => 'glpi_plugin_reembolso_tecnicos', 'WHERE' => ['users_id' => $uid]])->current();
        $user = (int) ($row['users_id_manager'] ?? 0);
        $group = (int) ($row['groups_id_manager'] ?? 0);
        $source = $user || $group ? 'tecnico' : '';
        if (!$source) {
            $u = new User();
            $user = $u->getFromDB($uid) ? (int) ($u->fields['users_id_supervisor'] ?? 0) : 0;
            $source = $user ? 'responsavel' : '';
        }
        if (!$source) {
            $cfg = Money::config();
            [$user, $group] = [$cfg['manager_users_id'], $cfg['manager_groups_id']];
            $source = $user || $group ? 'padrao' : '';
        }
        $period = (string) ($row['period'] ?? '');
        return ['period' => isset(self::PERIODS[$period]) ? $period : Money::config()['lote_period'], 'manager' => $user, 'manager_group' => $group, 'source' => $source];
    }

    protected static function groupMembers(int $gid): array
    {
        global $DB;
        if (!$gid) {
            return [];
        }
        return array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => 'users_id', 'FROM' => Group_User::getTable(), 'WHERE' => ['groups_id' => $gid]])), 'users_id'));
    }

    /** Pessoas de uma etapa: o usuário escolhido mais os membros do grupo escolhido. */
    public static function people(int $user, int $group): array
    {
        return array_values(array_unique(array_filter(array_merge([$user], self::groupMembers($group)))));
    }

    /** "Fulano · grupo OTC" (rótulo de quem responde por uma etapa). */
    public static function whoLabel(int $user, int $group, string $empty = 'quem aprova reembolsos'): string
    {
        $parts = array_filter([$user ? getUserName($user) : '', $group ? 'grupo ' . \Dropdown::getDropdownName('glpi_groups', $group, false, true, false, '') : '']);
        return $parts ? implode(' · ', $parts) : $empty;
    }

    /** Centros de custo do lote => [user, group, users] do gestor (users vazio = sem gestor cadastrado). */
    public function centers(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['r.plugin_reembolso_centrocustos_id AS c', 'cc.users_id_manager AS m', 'cc.groups_id_manager AS g'], 'FROM' => Reembolso::getTable() . ' AS r',
            'LEFT JOIN' => [Centrocusto::getTable() . ' AS cc' => ['ON' => ['cc' => 'id', 'r' => 'plugin_reembolso_centrocustos_id']]],
            'WHERE' => ['r.plugin_reembolso_lotes_id' => $this->getID(), 'r.is_deleted' => 0]]) as $r) {
            $out[(int) $r['c']] = ['user' => (int) $r['m'], 'group' => (int) $r['g'], 'users' => self::people((int) $r['m'], (int) $r['g'])];
        }
        return $out;
    }

    /** Decisões da rodada atual (a rodada sobe a cada reenvio depois de uma devolução). */
    public function decisions(?string $step = null): array
    {
        global $DB;
        $where = [static::$stepsFk => $this->getID(), 'round' => $this->round()];
        if ($step) {
            $where['step'] = $step;
        }
        return iterator_to_array($DB->request(['FROM' => static::$stepsTable, 'WHERE' => $where, 'ORDER' => 'id']), false);
    }

    public function round(): int
    {
        global $DB;
        return max(1, (int) ($DB->request(['SELECT' => ['MAX' => 'round AS r'], 'FROM' => static::$stepsTable, 'WHERE' => [static::$stepsFk => $this->getID(), 'decision' => 'reenviado']])->current()['r'] ?? 0) + 1);
    }

    /** Centros que ainda esperam o gestor nesta rodada. */
    public function pendingCenters(): array
    {
        $done = array_map(fn ($d) => (int) $d['plugin_reembolso_centrocustos_id'], array_filter($this->decisions('centro'), fn ($d) => $d['decision'] === 'aprovado'));
        return array_diff_key($this->centers(), array_flip($done));
    }

    /** Quem pode agir na etapa atual (sem ninguém definido: quem tem o direito "Aprovar"). */
    public function stepUsers(): array
    {
        $status = (int) $this->fields['status'];
        $cfg = Money::config();
        $approvers = NotificationTargetReembolso::approvers();
        $pending = $status === self::CENTER ? $this->pendingCenters() : [];
        $users = match ($status) {
            self::SIGN, self::RETURNED => [(int) $this->fields['users_id']],
            self::MANAGER => self::people((int) $this->fields['users_id_area_manager'], (int) ($this->fields['groups_id_area_manager'] ?? 0)),
            self::CENTER  => array_merge([], ...array_values(array_map(fn ($c) => $c['users'], $pending))),
            self::OTC     => self::people($cfg['otc_users_id'], $cfg['otc_groups_id']),
            self::FINANCE => self::people($cfg['finance_users_id'], $cfg['finance_groups_id']),
            default       => [],
        };
        if ($status === self::CENTER && array_filter($pending, fn ($c) => !$c['users'])) {
            $users = array_merge($users, $approvers); // centro sem gestor: quem aprova reembolsos decide
        }
        if (!$users && in_array($status, [self::MANAGER, self::CENTER, self::OTC, self::FINANCE], true)) {
            $users = $approvers;
        }
        return array_values(array_unique(array_map('intval', $users)));
    }

    public function canAct(): bool
    {
        $status = (int) $this->fields['status'];
        if (!isset(self::STEPS[$status]) && $status !== self::RETURNED) {
            return false;
        }
        $me = (int) Session::getLoginUserID();
        if (in_array($status, [self::SIGN, self::RETURNED], true)) {
            return $me === (int) $this->fields['users_id'] || Reembolso::isApprover();
        }
        return in_array($me, $this->stepUsers(), true) || Reembolso::isApprover();
    }

    /** Todos os envolvidos: técnico, gestor da área, gestores dos centros e quem decidiu alguma etapa. */
    public function involved(): array
    {
        global $DB;
        $ids = array_merge([(int) $this->fields['users_id']], self::people((int) $this->fields['users_id_area_manager'], (int) ($this->fields['groups_id_area_manager'] ?? 0)));
        foreach ($this->centers() as $c) {
            $ids = array_merge($ids, $c['users']);
        }
        foreach ($DB->request(['SELECT' => 'users_id', 'DISTINCT' => true, 'FROM' => static::$stepsTable, 'WHERE' => [static::$stepsFk => $this->getID()]]) as $r) {
            $ids[] = (int) $r['users_id'];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    // ------------------------------------------------------------------ períodos e geração

    /** Último dia do período já fechado, conforme a periodicidade (hoje ainda não fechou). */
    public static function cutoff(string $period, ?int $now = null): string
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        return match ($period) {
            'diario'    => date('Y-m-d', strtotime("$today -1 day")),
            'semanal'   => date('Y-m-d', strtotime('last sunday', $now + 86400)), // a semana fecha no domingo
            'quinzenal' => (int) date('j', $now) > 15 ? date('Y-m-15', $now) : date('Y-m-t', strtotime('first day of last month', $now)),
            default     => date('Y-m-t', strtotime('first day of last month', $now)),
        };
    }

    /** Solicitações do técnico prontas para o lote (rascunho, fora de lote, com despesas) até a data. */
    private static function eligible(int $uid, ?string $until): array
    {
        global $DB;
        $where = ['users_id' => $uid, 'is_deleted' => 0, 'plugin_reembolso_lotes_id' => 0, 'status' => [Reembolso::DRAFT, Reembolso::REFUSED]];
        if ($until) {
            $where['date_end'] = ['<=', $until];
        }
        return iterator_to_array($DB->request(['FROM' => Reembolso::getTable(), 'WHERE' => $where, 'ORDER' => ['date_begin', 'id']]), false);
    }

    /**
     * Fecha o lote do técnico com as solicitações prontas. $force ignora o fim do período
     * (botão "Gerar o lote agora"). Solicitações sem centro de custo, sem despesa ou sem o
     * comprovante exigido ficam de fora e o técnico é avisado.
     * @return array{0:?int,1:string[]} id do lote e o que ficou de fora
     */
    public static function generate(int $uid, bool $force = false): array
    {
        $cfg = self::techSettings($uid);
        $rows = self::eligible($uid, $force ? null : self::cutoff($cfg['period']));
        $types = Tipodespesa::all();
        $ok = [];
        $left = [];
        foreach ($rows as $r) {
            $exp = Despesa::forReembolso((int) $r['id']);
            $code = Reembolso::code((int) $r['id']);
            $rb = new Reembolso();
            $rb->getFromDB((int) $r['id']);
            if (!$exp) {
                continue; // solicitação vazia: nem avisa
            }
            if (!(int) $r['plugin_reembolso_centrocustos_id']) {
                $left[] = "$code: sem centro de custo";
                continue;
            }
            $needs = array_filter($exp, fn ($e) => $types[(int) $e['plugin_reembolso_tipodespesas_id']]['receipt'] ?? false);
            if ($needs && Money::config()['require_receipt'] && !$rb->countDocuments()) {
                $left[] = "$code: falta o comprovante";
                continue;
            }
            if (array_filter($exp, fn ($e) => !$e['time'])) {
                $left[] = "$code: despesa sem horário";
                continue;
            }
            $ok[] = $r;
        }
        if ($left && !self::$quiet) {
            Avisos::send(['event' => 'rb_lote_pendencia', 'users' => [$uid], 'include_self' => true, 'level' => 'warning', 'teams' => false,
                'title' => 'Reembolsos fora do lote', 'message' => 'Corrija para entrarem no próximo lote: ' . implode('; ', $left) . '.',
                'url' => Reembolso::getSearchURL()]);
        }
        if (!$ok) {
            return [null, $left];
        }
        $lote = new static();
        $id = (int) $lote->add([
            'users_id' => $uid, 'users_id_area_manager' => $cfg['manager'], 'groups_id_area_manager' => $cfg['manager_group'], 'period' => $force ? 'manual' : $cfg['period'],
            'date_begin' => min(array_column($ok, 'date_begin')), 'date_end' => max(array_column($ok, 'date_end')),
            'entities_id' => (int) $ok[0]['entities_id'], 'status' => self::SIGN,
            'date_creation' => $_SESSION['glpi_currenttime'], 'date_mod' => $_SESSION['glpi_currenttime'],
        ]);
        global $DB;
        $DB->update(Reembolso::getTable(), ['plugin_reembolso_lotes_id' => $id, 'status' => Reembolso::SUBMITTED, 'date_submit' => $_SESSION['glpi_currenttime']], ['id' => array_column($ok, 'id')]);
        $lote->getFromDB($id);
        $lote->update(['id' => $id, 'name' => static::code($id) . ' · ' . getUserName($uid)]);
        $lote->getFromDB($id);
        $lote->prepare();
        return [$id, $left];
    }

    /** Totais, PDF e pedido de assinatura (na criação e a cada reenvio). */
    protected function prepare(): void
    {
        global $DB;
        $DB->update(static::getTable(), $this->totals() + ['last_error' => null], ['id' => $this->getID()]);
        $this->getFromDB($this->getID());
        $pdf = $this->buildPdf();
        $docId = $this->attach($this->pdfName(), $pdf, $this->pdfComment());
        $DB->update(static::getTable(), ['documents_id_pdf' => $docId, 'documents_id_signed' => 0, 'sign_uuid' => null, 'sign_file_id' => 0, 'date_signed' => null], ['id' => $this->getID()]);
        $this->getFromDB($this->getID());
        $this->requestSignature($pdf);
        $this->notify('sign');
    }

    /** Totais gravados no lote antes do PDF. */
    protected function totals(): array
    {
        $total = $night = 0.0;
        $nightCount = $count = 0;
        foreach ($this->expenses() as $e) {
            $total += (float) $e['amount'];
            $count++;
            if ((int) $e['is_night']) {
                $night += (float) $e['amount'];
                $nightCount++;
            }
        }
        return ['total' => round($total, 2), 'night_total' => round($night, 2), 'night_count' => $nightCount, 'expense_count' => $count];
    }

    protected function buildPdf(): string
    {
        return Pdf::lote($this);
    }

    protected function pdfName(): string
    {
        return static::code($this->getID()) . '-reembolsos-' . date('Ymd-His') . '.pdf';
    }

    protected function pdfComment(): string
    {
        return 'Lote de reembolsos para conferência e assinatura do técnico';
    }

    /** Título e instrução do pedido de assinatura no LibreSign. */
    protected function signRequest(string $name): array
    {
        return [static::code($this->getID()) . ' - Reembolsos de ' . $name, 'Confira as despesas do lote, inclusive as de adicional noturno, e assine para seguir para aprovação.'];
    }

    /** Refaz só o PDF de conferência (o anterior é apagado); não mexe na assinatura nem avisa ninguém. */
    public function rebuildPdf(): int
    {
        global $DB;
        $old = (int) $this->fields['documents_id_pdf'];
        $docId = $this->attach($this->pdfName(), $this->buildPdf(), $this->pdfComment());
        $DB->update(static::getTable(), ['documents_id_pdf' => $docId], ['id' => $this->getID()]);
        if ($old) {
            (new Document())->delete(['id' => $old], true);
        }
        $this->getFromDB($this->getID());
        return $docId;
    }

    /** Despesas do lote com a solicitação, o tipo e o centro de custo. */
    public function expenses(): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'SELECT' => ['d.*', 'r.id AS rid', 'r.plugin_reembolso_centrocustos_id AS centro', 'r.tickets_id', 'r.address', 't.name AS tipo'],
            'FROM' => Despesa::getTable() . ' AS d',
            'INNER JOIN' => [Reembolso::getTable() . ' AS r' => ['ON' => ['r' => 'id', 'd' => 'plugin_reembolso_reembolsos_id']]],
            'LEFT JOIN' => [Tipodespesa::getTable() . ' AS t' => ['ON' => ['t' => 'id', 'd' => 'plugin_reembolso_tipodespesas_id']]],
            'WHERE' => ['r.plugin_reembolso_lotes_id' => $this->getID(), 'r.is_deleted' => 0],
            'ORDER' => ['d.date', 'd.time', 'd.id'],
        ]), false);
    }

    public function requests(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => Reembolso::getTable(), 'WHERE' => ['plugin_reembolso_lotes_id' => $this->getID(), 'is_deleted' => 0], 'ORDER' => ['date_begin', 'id']]), false);
    }

    /** Grava um arquivo como documento do GLPI ligado ao lote. */
    public function attach(string $name, string $content, string $comment): int
    {
        $prefix = uniqid('', true) . '_';
        $tmp = GLPI_TMP_DIR . '/' . $prefix . $name;
        file_put_contents($tmp, $content);
        $doc = new Document();
        $id = (int) $doc->add([
            'name' => $name, 'comment' => $comment, 'entities_id' => (int) $this->fields['entities_id'], 'is_recursive' => 1,
            '_filename' => [$prefix . $name], '_prefix_filename' => [$prefix], 'itemtype' => static::class, 'items_id' => $this->getID(),
        ]);
        @unlink($tmp);
        if (!$id) {
            throw new RuntimeException("O GLPI não gravou o arquivo $name.");
        }
        return $id;
    }

    // ------------------------------------------------------------------ assinatura (LibreSign)

    public static function libreSignReady(): bool
    {
        return Plugin::isPluginActive('assinatura') && class_exists(\GlpiPlugin\Assinatura\LibreSignClient::class)
            && \GlpiPlugin\Assinatura\Settings::isConfigured();
    }

    /** Pede a assinatura do técnico no LibreSign (o próprio LibreSign envia o e-mail com o PDF). */
    protected function requestSignature(string $pdf): void
    {
        if (!self::libreSignReady() || self::$quiet) {
            return; // sem LibreSign: o técnico confere e aprova no próprio GLPI
        }
        try {
            $u = new User();
            $u->getFromDB((int) $this->fields['users_id']);
            $email = (string) $u->getDefaultEmail();
            if ($email === '') {
                throw new RuntimeException('o técnico não tem e-mail cadastrado no GLPI');
            }
            $client = new \GlpiPlugin\Assinatura\LibreSignClient();
            [$title, $what] = $this->signRequest($u->getFriendlyName());
            $file = $client->requestSignature($title, $pdf, [[
                'identifyMethods' => [['method' => 'email', 'value' => $email]],
                'displayName' => $u->getFriendlyName(),
                'description' => $what,
                'notify' => 1,
            ]]);
            if (empty($file['uuid'])) {
                throw new RuntimeException('o LibreSign não devolveu o identificador do documento');
            }
            try {
                $client->addVisibleSignature($file['uuid'], (int) $file['id'], (int) ($file['signers'][0]['signRequestId'] ?? 0), max(1, preg_match_all('#/Type\s*/Page(?![a-z])#', $pdf)));
            } catch (Throwable $e) {
                // sem o campo visível a assinatura digital continua válida
            }
            global $DB;
            $DB->update(static::getTable(), ['sign_uuid' => $file['uuid'], 'sign_file_id' => (int) ($file['id'] ?? 0)], ['id' => $this->getID()]);
            $this->getFromDB($this->getID());
        } catch (Throwable $e) {
            global $DB;
            $DB->update(static::getTable(), ['last_error' => 'Assinatura no LibreSign não solicitada: ' . $e->getMessage()], ['id' => $this->getID()]);
            $this->getFromDB($this->getID());
        }
    }

    /** Confere no LibreSign; assinado, anexa o PDF assinado e segue para o gestor da área. */
    public function syncSignature(): bool
    {
        if ((int) $this->fields['status'] !== self::SIGN || !$this->fields['sign_uuid'] || !self::libreSignReady()) {
            return false;
        }
        try {
            $client = new \GlpiPlugin\Assinatura\LibreSignClient();
            $data = $client->validate((string) $this->fields['sign_uuid']);
            $signers = $data['signers'] ?? [];
            $all = $signers !== [] && count(array_filter($signers, fn ($s) => !empty($s['signed']))) === count($signers);
            if (!$all && (int) ($data['status'] ?? 0) !== 3) {
                return false;
            }
            $pdf = $client->downloadPdf((string) $this->fields['sign_uuid']);
            $docId = $this->attach(static::code($this->getID()) . '-assinado-' . date('Ymd-His') . '.pdf', $pdf, static::$noun . ' assinado digitalmente pelo técnico (LibreSign)');
            $signed = $signers[0]['signed'] ?? null;
            global $DB;
            $DB->update(static::getTable(), ['documents_id_signed' => $docId, 'date_signed' => $signed ? date('Y-m-d H:i:s', strtotime($signed)) : $_SESSION['glpi_currenttime'], 'last_error' => null], ['id' => $this->getID()]);
            $this->getFromDB($this->getID());
            $this->record('tecnico', (int) $this->fields['users_id'], 'aprovado', static::$noun . ' conferido e assinado digitalmente no LibreSign.');
            $this->advance();
            return true;
        } catch (Throwable $e) {
            global $DB;
            $DB->update(static::getTable(), ['last_error' => 'LibreSign: ' . $e->getMessage()], ['id' => $this->getID()]);
            return false;
        }
    }

    // ------------------------------------------------------------------ fluxo

    protected function record(string $step, int $uid, string $decision, string $comment = '', int $centro = 0): void
    {
        global $DB;
        $DB->insert(static::$stepsTable, [
            static::$stepsFk => $this->getID(), 'step' => $step, 'plugin_reembolso_centrocustos_id' => $centro,
            'users_id' => $uid, 'decision' => $decision, 'comment' => $comment, 'round' => $this->round(), 'date' => $_SESSION['glpi_currenttime'],
        ]);
    }

    /** Atualiza as solicitações de reembolso que estão no lote. */
    protected function linked(array $set): void
    {
        global $DB;
        $DB->update(Reembolso::getTable(), $set, ['plugin_reembolso_lotes_id' => $this->getID()]);
    }

    protected function setStatus(int $status): void
    {
        $this->update(['id' => $this->getID(), 'status' => $status, 'date_mod' => $_SESSION['glpi_currenttime'], '_workflow' => true]);
        $this->getFromDB($this->getID());
    }

    /** Próxima etapa da cadeia: técnico → gestor da área → gestor do centro de custo → OTC → financeiro. */
    protected function advance(): void
    {
        global $DB;
        $next = match ((int) $this->fields['status']) {
            self::SIGN    => self::MANAGER,
            self::MANAGER => self::CENTER,
            self::CENTER  => $this->pendingCenters() ? self::CENTER : self::OTC,
            self::OTC     => self::FINANCE,
            default       => (int) $this->fields['status'],
        };
        if ($next !== (int) $this->fields['status']) {
            $this->setStatus($next);
            if ($next === self::FINANCE) {
                $this->linked(['status' => Reembolso::APPROVED, 'date_approval' => $_SESSION['glpi_currenttime']]);
            }
        }
        $this->notify('approve');
    }

    /**
     * Ações: sign (técnico confere e aprova no GLPI), contest (técnico devolve), approve,
     * return (devolver ao técnico), pay (financeiro, com comprovante), resend (reenviar
     * depois de corrigir) e cancel.
     */
    public function act(string $action, array $in, array $files = []): string
    {
        $status = (int) $this->fields['status'];
        $me = (int) Session::getLoginUserID();
        $comment = trim((string) ($in['comment'] ?? ''));
        $n = static::$noun;
        $o = 'o ' . mb_strtolower($n);
        if (!$this->canAct() && $action !== 'cancel') {
            throw new RuntimeException("Esta etapa d$o não está com você.");
        }
        switch ($action) {
            case 'sign':
                if ($status !== self::SIGN) {
                    throw new RuntimeException("O " . mb_strtolower($n) . " não espera a conferência do técnico.");
                }
                if (empty($in['confirm'])) {
                    throw new RuntimeException('Marque que conferiu as despesas, inclusive as de adicional noturno.');
                }
                $this->record('tecnico', $me, 'aprovado', $comment ?: 'Lote conferido e aprovado no GLPI.');
                $this->advance();
                return "$n aprovado. Ele seguiu para o gestor da área.";

            case 'contest':
            case 'return':
                if (!isset(self::STEPS[$status])) {
                    throw new RuntimeException("Não é possível devolver $o nesta etapa.");
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o motivo da devolução.');
                }
                $this->record(self::STEPS[$status], $me, 'devolvido', $comment);
                $this->cancelSignature();
                global $DB;
                $this->linked(['status' => Reembolso::REFUSED, 'approval_comment' => $comment]);
                $this->setStatus(self::RETURNED);
                $this->notify('return', $comment);
                return "$n devolvido ao técnico para correção.";

            case 'approve':
                if (!in_array($status, [self::MANAGER, self::CENTER, self::OTC], true)) {
                    throw new RuntimeException("O " . mb_strtolower($n) . " não espera aprovação nesta etapa.");
                }
                if ($status === self::CENTER) {
                    // o gestor aprova os seus centros; quem tem "Aprovar" e não é gestor de nenhum decide pelos demais
                    $pending = $this->pendingCenters();
                    $mine = array_keys(array_filter($pending, fn ($c) => in_array($me, $c['users'], true)));
                    if (!$mine) {
                        $mine = array_keys(array_filter($pending, fn ($c) => Reembolso::isApprover() || (!$c['users'] && in_array($me, NotificationTargetReembolso::approvers(), true))));
                    }
                    if (!$mine) {
                        throw new RuntimeException("Nenhum centro de custo deste " . mb_strtolower($n) . " espera a sua aprovação.");
                    }
                    foreach ($mine as $centro) {
                        $this->record('centro', $me, 'aprovado', $comment, (int) $centro);
                    }
                } else {
                    $this->record(self::STEPS[$status], $me, 'aprovado', $comment);
                }
                $this->advance();
                return (int) $this->fields['status'] === $status ? "Aprovação registrada. O " . mb_strtolower($n) . " espera os outros gestores de centro de custo." : "$n aprovado e enviado para a próxima etapa.";

            case 'pay':
                if ($status !== self::FINANCE) {
                    throw new RuntimeException('Só ' . (static::$noun === 'Lote' ? 'um lote aprovado' : 'um ' . mb_strtolower(static::$noun) . ' aprovado') . ' pelo OTC pode ser pago.');
                }
                $file = $files['receipt'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    throw new RuntimeException('Anexe o comprovante do pagamento.');
                }
                if ($file['size'] > 20 * 1048576) {
                    throw new RuntimeException('O comprovante passa de 20 MB.');
                }
                $name = preg_replace('/[^\w.\- ]+/u', '_', basename((string) $file['name'])) ?: 'comprovante.pdf';
                return $this->pay($name, (string) file_get_contents($file['tmp_name']), $in);

            case 'resend':
                if ($status !== self::RETURNED) {
                    throw new RuntimeException('Só um lote devolvido pode ser reenviado.');
                }
                if (!$this->requests()) {
                    throw new RuntimeException('O lote não tem mais solicitações.');
                }
                $this->record('tecnico', $me, 'reenviado', $comment ?: 'Lote corrigido e reenviado.');
                global $DB;
                $this->linked(['status' => Reembolso::SUBMITTED, 'approval_comment' => '']);
                $this->setStatus(self::SIGN);
                $this->prepare();
                return 'Lote reenviado: um novo PDF foi gerado para conferência e assinatura.';

            case 'cancel':
                if (!Reembolso::isApprover() || in_array($status, [self::PAID, self::CANCELED], true)) {
                    throw new RuntimeException('Não é possível cancelar este lote.');
                }
                if ($comment === '') {
                    throw new RuntimeException('Informe o motivo do cancelamento.');
                }
                $this->record(self::STEPS[$status] ?? 'tecnico', $me, 'cancelado', $comment);
                $this->cancelSignature();
                global $DB;
                $this->linked(['status' => Reembolso::DRAFT, 'plugin_reembolso_lotes_id' => 0, 'date_submit' => null]);
                $this->setStatus(self::CANCELED);
                return 'Lote cancelado. As solicitações voltaram a rascunho e entram no próximo lote.';
        }
        throw new RuntimeException('Ação desconhecida.');
    }

    /** Registra o pagamento com o comprovante: lote e solicitações pagos, todos avisados. */
    public function pay(string $name, string $content, array $in): string
    {
        global $DB;
        if ((int) $this->fields['status'] !== self::FINANCE) {
            throw new RuntimeException('Só ' . (static::$noun === 'Lote' ? 'um lote aprovado' : 'um ' . mb_strtolower(static::$noun) . ' aprovado') . ' pelo OTC pode ser pago.');
        }
        if ($content === '') {
            throw new RuntimeException('Anexe o comprovante do pagamento.');
        }
        $me = (int) Session::getLoginUserID();
        $comment = trim((string) ($in['comment'] ?? ''));
        $docId = $this->attach('comprovante-' . static::code($this->getID()) . '-' . $name, $content, 'Comprovante do pagamento do lote');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['date_payment'] ?? '')) ? $in['date_payment'] : date('Y-m-d');
        $ref = mb_substr(trim((string) ($in['payment_reference'] ?? '')), 0, 255);
        $DB->update(static::getTable(), ['documents_id_receipt' => $docId, 'date_payment' => $date, 'payment_reference' => $ref], ['id' => $this->getID()]);
        $this->linked(['status' => Reembolso::PAID, 'date_payment' => $date, 'payment_reference' => trim(static::code($this->getID()) . ' ' . $ref)]);
        $this->record('financeiro', $me, 'pago', $comment ?: ($ref ? "Pagamento: $ref" : 'Pagamento registrado.'));
        $this->setStatus(self::PAID);
        $this->notify('pay');
        return 'Pagamento registrado. Todos os envolvidos foram avisados.';
    }

    protected function cancelSignature(): void
    {
        if ($this->fields['sign_file_id'] && !$this->fields['documents_id_signed'] && self::libreSignReady()) {
            try {
                (new \GlpiPlugin\Assinatura\LibreSignClient())->delete((int) $this->fields['sign_file_id']);
            } catch (Throwable $e) {
            }
        }
    }

    // ------------------------------------------------------------------ avisos

    /** E-mail (notificações do GLPI) e sino, pop-up e Teams (plugin Avisos). */
    protected function notify(string $what, string $comment = ''): void
    {
        if (self::$quiet) {
            return;
        }
        $code = static::code($this->getID());
        $tech = (int) $this->fields['users_id'];
        $techName = (string) getUserName($tech);
        $total = Money::format((float) $this->fields['total']);
        $url = self::getFormURLWithID($this->getID());
        $status = (int) $this->fields['status'];
        $night = (int) $this->fields['night_count'] ? ' · ' . (int) $this->fields['night_count'] . ' despesa(s) de adicional noturno (' . Money::format((float) $this->fields['night_total']) . ')' : '';
        $facts = ['Lote' => $code, 'Técnico' => $techName, 'Período' => self::periodLabel($this->fields), 'Total' => $total, 'Etapa' => static::STATUSES[$status][0] ?? ''];
        $send = fn (array $users, string $title, string $message, string $level, string $event) => Avisos::send([
            'event' => $event, 'users' => $users, 'title' => $title, 'message' => $message, 'level' => $level, 'url' => $url, 'item' => [static::class, $this->getID()], 'facts' => $facts,
        ]);
        switch ($what) {
            case 'sign':
                \NotificationEvent::raiseEvent('lote_assinar', $this);
                $send([$tech], "Confira e assine o lote $code", "Seus reembolsos de " . self::periodLabel($this->fields) . " ($total$night) estão num PDF para conferência"
                    . (self::libreSignReady() && $this->fields['sign_uuid'] ? '. O LibreSign enviou o link de assinatura para o seu e-mail.' : '. Abra o lote, confira e aprove.'), 'warning', 'lote_assinar');
                break;
            case 'approve':
                if ($status === self::FINANCE || in_array($status, [self::MANAGER, self::CENTER, self::OTC], true)) {
                    \NotificationEvent::raiseEvent('lote_aprovar', $this);
                    $status === self::FINANCE
                        ? $send($this->stepUsers(), "Lote $code aprovado: registrar o pagamento", "$techName · $total$night. Pague e anexe o comprovante no lote.", 'warning', 'lote_aprovar')
                        : $send($this->stepUsers(), "Lote $code espera a sua aprovação", "$techName · $total$night · etapa: " . static::STATUSES[$status][3] . '.', 'warning', 'lote_aprovar');
                }
                break;
            case 'return':
                \NotificationEvent::raiseEvent('lote_devolvido', $this);
                $send([$tech], "Lote $code devolvido", "Motivo: $comment. Corrija as solicitações e reenvie o lote.", 'danger', 'lote_devolvido');
                break;
            case 'pay':
                \NotificationEvent::raiseEvent('lote_pago', $this);
                $others = array_values(array_diff($this->involved(), [$tech]));
                Avisos::send(['event' => 'lote_pago', 'users' => $others, 'include_self' => true, 'level' => 'success', 'url' => $url, 'item' => [static::class, $this->getID()], 'facts' => $facts,
                    'title' => "Reembolso $code finalizado com sucesso", 'message' => "O lote de $techName ($total) foi pago e o comprovante está anexado."]);
                Avisos::send(['event' => 'lote_ok', 'users' => [$tech], 'include_self' => true, 'level' => 'success', 'url' => $url, 'item' => [static::class, $this->getID()], 'teams' => false,
                    'title' => "OK final: reembolso $code pago", 'message' => "Seu reembolso de $total foi pago" . ($this->fields['date_payment'] ? ' em ' . date('d/m/Y', strtotime((string) $this->fields['date_payment'])) : '') . '. O comprovante está no lote.']);
                break;
        }
    }

    public static function periodLabel(array $f): string
    {
        if (!$f['date_begin']) {
            return '—';
        }
        $a = date('d/m/Y', strtotime((string) $f['date_begin']));
        $b = $f['date_end'] ? date('d/m/Y', strtotime((string) $f['date_end'])) : $a;
        return $a === $b ? $a : "$a a $b";
    }

    // ------------------------------------------------------------------ ações automáticas

    public static function cronInfo($name)
    {
        return match ($name) {
            'reembolsolotes' => ['description' => 'Gera os lotes de reembolso dos técnicos no fim do período'],
            'reembolsoassinaturas' => ['description' => 'Confere no LibreSign as assinaturas dos lotes'],
            default => [],
        };
    }

    /** Para cada técnico com solicitação pronta, fecha o lote do período que terminou. */
    public static function cronReembolsolotes(CronTask $task): int
    {
        global $DB;
        $done = 0;
        foreach ($DB->request(['SELECT' => 'users_id', 'DISTINCT' => true, 'FROM' => Reembolso::getTable(),
            'WHERE' => ['is_deleted' => 0, 'plugin_reembolso_lotes_id' => 0, 'status' => [Reembolso::DRAFT, Reembolso::REFUSED]]]) as $r) {
            try {
                [$id] = self::generate((int) $r['users_id']);
                if ($id) {
                    $done++;
                    $task->log('Lote ' . static::code($id) . ' gerado para ' . getUserName((int) $r['users_id']));
                }
            } catch (Throwable $e) {
                $task->log('Falha no lote de ' . getUserName((int) $r['users_id']) . ': ' . $e->getMessage());
            }
        }
        $task->addVolume($done);
        return $done ? 1 : 0;
    }

    public static function cronReembolsoassinaturas(CronTask $task): int
    {
        global $DB;
        $done = 0;
        foreach ([self::class, Adiantamento::class] as $class) {
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => $class::getTable(), 'WHERE' => ['status' => self::SIGN, 'is_deleted' => 0, ['NOT' => ['sign_uuid' => null]]]]) as $r) {
                $l = new $class();
                if ($l->getFromDB((int) $r['id']) && $l->syncSignature()) {
                    $done++;
                }
            }
        }
        $task->addVolume($done);
        return $done ? 1 : 0;
    }

    // ------------------------------------------------------------------ GLPI

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Log::class, $ong, $options);
        return $ong;
    }

    public static function getSearchURL($full = true)
    {
        return $full ? Menu::url('/front/lote.php') : '/plugins/reembolso/front/lote.php';
    }

    public static function getFormURL($full = true)
    {
        return $full ? Menu::url('/front/lote.form.php') : '/plugins/reembolso/front/lote.form.php';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        return ['title' => 'Lotes de reembolso', 'page' => self::getSearchURL(false), 'icon' => self::getIcon()];
    }

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Lote', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'name' => 'Técnico', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 4, 'table' => $t, 'field' => 'status', 'name' => 'Etapa', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'date_begin', 'name' => 'Início do período', 'datatype' => 'date'],
            ['id' => 6, 'table' => $t, 'field' => 'date_end', 'name' => 'Fim do período', 'datatype' => 'date'],
            ['id' => 7, 'table' => $t, 'field' => 'total', 'name' => 'Total', 'datatype' => 'decimal'],
            ['id' => 8, 'table' => $t, 'field' => 'night_count', 'name' => 'Despesas de adicional noturno', 'datatype' => 'number'],
            ['id' => 9, 'table' => $t, 'field' => 'period', 'name' => 'Periodicidade', 'datatype' => 'specific', 'massiveaction' => false],
            ['id' => 10, 'table' => $t, 'field' => 'date_payment', 'name' => 'Pago em', 'datatype' => 'date'],
            ['id' => 11, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_area_manager', 'name' => 'Gestor da área', 'datatype' => 'dropdown', 'right' => 'all'],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        return match ($field) {
            'status' => static::badge((int) ($values[$field] ?? 0)),
            'period' => self::e(self::PERIODS[$values[$field]] ?? ($values[$field] === 'manual' ? 'Gerado na hora' : '')),
            default => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'status') {
            return \Dropdown::showFromArray($name, array_map(fn ($s) => $s[0], static::STATUSES), ['value' => $values[$field] ?? '', 'display' => false] + $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public static function badge(int $status): string
    {
        [$label, $color, $icon] = static::STATUSES[$status] ?? ['?', '#64748b', 'ti-help'];
        return "<span class='badge rb-status' style='--c:$color'><i class='ti $icon me-1'></i>" . self::e($label) . "</span>";
    }

    // ------------------------------------------------------------------ tela do lote

    public function showForm($ID, array $options = [])
    {
        $e = [static::class, 'e'];
        $f = $this->fields;
        $status = (int) $f['status'];
        $code = static::code($this->getID());
        $action = $e(self::getFormURL());
        $hidden = "<input type='hidden' name='id' value='" . (int) $this->getID() . "'>";

        echo "<div class='rb'>";
        echo "<div class='rb-head card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'>";
        echo "<div class='rb-head-icon'><i class='ti ti-stack-2'></i></div><div class='me-auto'>";
        echo "<div class='text-secondary small text-uppercase fw-bold'>Lote de reembolsos · " . $e(self::PERIODS[$f['period']] ?? 'gerado na hora') . "</div>";
        echo "<h2 class='m-0'>$code · " . $e(getUserName((int) $f['users_id'])) . "</h2>";
        echo "<div class='text-secondary small mt-1'>" . $e(self::periodLabel($f)) . ' · ' . count($this->requests()) . ' solicitação(ões) · ' . (int) $f['expense_count'] . ' despesa(s)</div>';
        echo "<div class='mt-1'>" . static::badge($status) . "</div></div>";
        echo "<div class='rb-lote-kpis'><div><small>Total</small><b>" . Money::format((float) $f['total']) . "</b></div>"
            . "<div class='night'><small>Adicional noturno</small><b>" . (int) $f['night_count'] . " · " . Money::format((float) $f['night_total']) . "</b></div></div>";
        echo "</div>";

        // as etapas
        $steps = [self::SIGN => 'Técnico confere e assina', self::MANAGER => 'Gestor da área', self::CENTER => 'Gestor do centro de custo', self::OTC => 'OTC', self::FINANCE => 'Financeiro paga', self::PAID => 'Pago'];
        $cur = $status === self::RETURNED ? self::SIGN : $status;
        // quem está em cada etapa
        $cfg = Money::config();
        $centerWho = array_unique(array_map(fn ($c) => self::whoLabel($c['user'], $c['group']), $this->centers()));
        $who = [
            self::SIGN    => getUserName((int) $f['users_id']),
            self::MANAGER => self::whoLabel((int) $f['users_id_area_manager'], (int) ($f['groups_id_area_manager'] ?? 0)),
            self::CENTER  => implode(', ', $centerWho) ?: 'quem aprova reembolsos',
            self::OTC     => self::whoLabel($cfg['otc_users_id'], $cfg['otc_groups_id']),
            self::FINANCE => self::whoLabel($cfg['finance_users_id'], $cfg['finance_groups_id']),
            self::PAID    => $f['date_payment'] ? Html::convDate($f['date_payment']) : '',
        ];
        echo "<div class='rb-steps rb-steps-lote'>";
        foreach ($steps as $k => $label) {
            $cls = $status === self::CANCELED ? '' : ($k < $cur || $status === self::PAID ? 'done' : ($k === $cur ? ($status === self::RETURNED ? 'refused' : 'current') : ''));
            echo "<div class='rb-step $cls'><span></span><div>" . $e($status === self::RETURNED && $k === self::SIGN ? 'Devolvido ao técnico' : $label) . "<small>" . $e($who[$k] ?? '') . "</small></div></div>";
        }
        echo "</div></div>";

        if ($f['last_error'] && $status === self::SIGN) {
            echo "<div class='alert alert-warning'><i class='ti ti-alert-triangle me-1'></i>" . $e($f['last_error']) . "</div>";
        }

        echo "<div class='row g-3'><div class='col-xl-8'>";
        $this->showExpenses();
        echo "</div><div class='col-xl-4'>";
        $this->showActions($action, $hidden);
        $this->showFiles();
        $this->showTimeline();
        echo "</div></div></div>";
        return true;
    }

    protected function showActions(string $action, string $hidden): void
    {
        $e = [static::class, 'e'];
        $status = (int) $this->fields['status'];
        $who = $this->stepUsers();
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-git-pull-request me-2'></i>Etapa atual</h3></div><div class='card-body'>";
        echo "<div class='mb-2'>" . static::badge($status) . "</div>";
        if (isset(self::STEPS[$status]) || $status === self::RETURNED) {
            echo "<div class='small text-secondary mb-3'>Com: " . ($who ? $e(implode(', ', array_map('getUserName', $who))) : 'quem aprova reembolsos') . "</div>";
        }
        $return = "<form method='post' action='$action' class='mt-3'>$hidden<label class='form-label'>Devolver ao técnico</label><textarea class='form-control mb-2' name='comment' rows='2' placeholder='Motivo (obrigatório)'></textarea>"
            . "<button class='btn btn-outline-danger w-100' name='lote_action' value='%s'><i class='ti ti-arrow-back-up me-1'></i>%s</button>" . Html::closeForm(false);

        if (!$this->canAct()) {
            echo "<div class='text-secondary small'>" . ($status === self::PAID ? 'Lote pago em ' . Html::convDate($this->fields['date_payment']) . ($this->fields['payment_reference'] ? ' · ' . $e($this->fields['payment_reference']) : '') . '.' : 'Nenhuma ação sua nesta etapa.') . "</div>";
        } elseif ($status === self::SIGN) {
            if ($this->fields['sign_uuid']) {
                echo "<div class='alert alert-info py-2 small'><i class='ti ti-mail me-1'></i>O PDF foi enviado ao e-mail do técnico pelo LibreSign. Assinado lá, o lote segue sozinho (o GLPI confere a cada 10 minutos)."
                    . "<form method='post' action='$action' class='mt-2'>$hidden<button class='btn btn-sm btn-outline-primary' name='lote_action' value='check'><i class='ti ti-refresh me-1'></i>Conferir a assinatura agora</button>" . Html::closeForm(false) . "</div>";
            }
            echo "<form method='post' action='$action'>$hidden<label class='form-check'><input class='form-check-input' type='checkbox' name='confirm' value='1'>"
                . "<span class='form-check-label'>Conferi as despesas do lote, inclusive as de adicional noturno, e estão corretas.</span></label>"
                . "<input class='form-control my-2' name='comment' maxlength='500' placeholder='Comentário (opcional)'>"
                . "<button class='btn btn-success w-100' name='lote_action' value='sign'><i class='ti ti-signature me-1'></i>" . ($this->fields['sign_uuid'] ? 'Aprovar sem a assinatura do LibreSign' : 'Aprovar o lote') . "</button>" . Html::closeForm(false);
            printf($return, 'contest', 'Algo está errado: devolver');
        } elseif (in_array($status, [self::MANAGER, self::CENTER, self::OTC], true)) {
            if ($status === self::CENTER) {
                $pending = $this->pendingCenters();
                echo "<div class='small mb-2'>Centros de custo aguardando: " . $e(implode(', ', array_map(fn ($c) => Centrocusto::label((int) $c), array_keys($pending)))) . "</div>";
            }
            echo "<form method='post' action='$action'>$hidden<input class='form-control mb-2' name='comment' maxlength='500' placeholder='Comentário (opcional)'>"
                . "<button class='btn btn-success w-100' name='lote_action' value='approve'><i class='ti ti-circle-check me-1'></i>Aprovar</button>" . Html::closeForm(false);
            printf($return, 'return', 'Devolver ao técnico');
        } elseif ($status === self::FINANCE) {
            echo "<form method='post' action='$action' enctype='multipart/form-data'>$hidden"
                . "<label class='form-label required'>Comprovante do pagamento</label><input type='file' class='form-control mb-2' name='receipt' required>"
                . "<div class='row g-2'><div class='col-6'><label class='form-label'>Data</label><input type='date' class='form-control' name='date_payment' value='" . date('Y-m-d') . "'></div>"
                . "<div class='col-6'><label class='form-label'>Referência</label><input class='form-control' name='payment_reference' maxlength='255' placeholder='PIX, TED, folha…'></div></div>"
                . "<button class='btn btn-success w-100 mt-2' name='lote_action' value='pay'><i class='ti ti-cash me-1'></i>Registrar o pagamento</button>" . Html::closeForm(false);
            printf($return, 'return', 'Devolver ao técnico');
        } elseif ($status === self::RETURNED) {
            echo "<div class='small mb-2'>Corrija as solicitações do lote (elas estão liberadas para edição) e reenvie. Um novo PDF é gerado para conferência e assinatura.</div>"
                . "<form method='post' action='$action'>$hidden<input class='form-control mb-2' name='comment' maxlength='500' placeholder='O que foi corrigido'>"
                . "<button class='btn btn-primary w-100' name='lote_action' value='resend'><i class='ti ti-send me-1'></i>Reenviar o lote</button>" . Html::closeForm(false);
        }
        if (Reembolso::isApprover() && !in_array($status, [self::PAID, self::CANCELED], true)) {
            echo "<details class='mt-3 small'><summary class='text-secondary'>Cancelar " . $e('o ' . mb_strtolower(static::$noun)) . "</summary><form method='post' action='$action' class='mt-2'>$hidden"
                . "<input class='form-control mb-2' name='comment' placeholder='Motivo (obrigatório)'><button class='btn btn-sm btn-ghost-danger' name='lote_action' value='cancel' onclick=\"return confirm('Confirma o cancelamento?')\">Cancelar " . $e('o ' . mb_strtolower(static::$noun)) . "</button>" . Html::closeForm(false) . "</details>";
        }
        echo "</div></div>";
    }

    private function showExpenses(): void
    {
        $e = [static::class, 'e'];
        $rows = $this->expenses();
        $night = array_filter($rows, fn ($r) => (int) $r['is_night']);
        $cfg = Money::config();
        if ($night) {
            echo "<div class='card mb-3 rb-night-card'><div class='card-header'><h3 class='card-title'><i class='ti ti-moon-stars me-2'></i>Adicional noturno</h3>"
                . "<div class='card-actions small text-secondary'>despesas das " . $e($cfg['night_start']) . " às " . $e($cfg['night_end']) . "</div></div><div class='card-body'>"
                . "<div class='rb-night-sum'><b>" . count($night) . "</b> despesa(s) no horário noturno, somando <b>" . Money::format(array_sum(array_map(fn ($r) => (float) $r['amount'], $night))) . "</b>. Elas aparecem destacadas na lista e no PDF.</div></div></div>";
        }
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-list-details me-2'></i>Solicitações e despesas</h3></div>";
        echo "<div class='table-responsive'><table class='table table-vcenter card-table rb-table'><thead><tr><th>Data e hora</th><th>Solicitação</th><th>Tipo</th><th>Descrição</th><th class='text-end'>Valor</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            $isNight = (int) $r['is_night'];
            echo "<tr" . ($isNight ? " class='rb-night-row'" : '') . "><td class='text-nowrap'>" . Html::convDate($r['date']) . ' ' . $e(substr((string) $r['time'], 0, 5))
                . ($isNight ? " <span class='badge rb-night-badge'><i class='ti ti-moon-stars me-1'></i>noturno</span>" : '') . "</td>"
                . "<td class='text-nowrap'><a href='" . $e(Reembolso::getFormURLWithID((int) $r['rid'])) . "'>" . Reembolso::code((int) $r['rid']) . "</a>"
                . ((int) $r['tickets_id'] ? " · <a href='" . $e(\Ticket::getFormURLWithID((int) $r['tickets_id'])) . "'>chamado nº " . (int) $r['tickets_id'] . "</a>" : " · <span class='text-warning'>sem chamado</span>")
                . "<div class='small text-secondary'>" . $e(Centrocusto::label((int) $r['centro'])) . "</div></td>"
                . "<td>" . $e($r['tipo']) . "</td><td>" . $e($r['description']) . ((float) $r['distance'] ? "<div class='small text-secondary'>" . Money::km((float) $r['distance']) . "</div>" : '') . "</td>"
                . "<td class='text-end text-nowrap fw-bold'>" . Money::format((float) $r['amount']) . "</td></tr>";
        }
        echo "</tbody><tfoot><tr><th colspan='4' class='text-end'>Total do lote</th><th class='text-end text-nowrap rb-total-sm'>" . Money::format((float) $this->fields['total']) . "</th></tr></tfoot></table></div></div>";
    }

    protected function showFiles(): void
    {
        $e = [static::class, 'e'];
        $doc = function (int $id, string $label, string $icon) use ($e) {
            if (!$id) {
                return '';
            }
            $d = new Document();
            if (!$d->getFromDB($id)) {
                return '';
            }
            $url = \Html::getPrefixedUrl('/front/document.send.php?docid=' . $id . '&itemtype=' . rawurlencode(static::class) . '&items_id=' . $this->getID());
            return "<a class='rb-lote-file' href='" . $e($url) . "' target='_blank' rel='noopener'><i class='ti $icon'></i><span><b>" . $e($label) . "</b><small>" . $e($d->fields['filename']) . "</small></span><i class='ti ti-arrow-up-right'></i></a>";
        };
        $f = $this->fields;
        $files = $doc((int) $f['documents_id_pdf'], 'PDF para conferência', 'ti-file-type-pdf')
            . $doc((int) $f['documents_id_signed'], 'PDF assinado pelo técnico' . ($f['date_signed'] ? ' em ' . Html::convDateTime($f['date_signed']) : ''), 'ti-signature')
            . $doc((int) $f['documents_id_receipt'], 'Comprovante do pagamento', 'ti-receipt');
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-files me-2'></i>Arquivos do lote</h3></div><div class='card-body d-flex flex-column gap-2'>"
            . ($files ?: "<span class='text-secondary small'>Nenhum arquivo ainda.</span>") . "</div></div>";
    }

    protected function showTimeline(): void
    {
        global $DB;
        $e = [static::class, 'e'];
        $labels = ['tecnico' => 'Técnico', 'gestor' => 'Gestor da área', 'centro' => 'Gestor do centro de custo', 'otc' => 'OTC', 'financeiro' => 'Financeiro', 'prestacao' => 'Prestação de contas'];
        $icons = ['aprovado' => ['ti-circle-check', '#22c55e'], 'devolvido' => ['ti-arrow-back-up', '#ef4444'], 'pago' => ['ti-cash', '#22c55e'], 'reenviado' => ['ti-send', '#3b82f6'], 'cancelado' => ['ti-ban', '#64748b'],
            'enviado' => ['ti-send', '#3b82f6'], 'prestado' => ['ti-receipt-tax', '#6366f1'], 'encerrado' => ['ti-circle-check', '#22c55e']];
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-timeline me-2'></i>Aprovações</h3></div><div class='card-body'><ul class='rb-lote-tl'>";
        echo "<li><i class='ti " . static::getIcon() . "' style='color:#a855f7'></i><div><b>" . $e(static::$noun === 'Lote' ? 'Lote gerado' : static::$noun . ' criado') . "</b><small>" . Html::convDateTime($this->fields['date_creation']) . "</small></div></li>";
        foreach ($DB->request(['FROM' => static::$stepsTable, 'WHERE' => [static::$stepsFk => $this->getID()], 'ORDER' => 'id']) as $d) {
            [$icon, $color] = $icons[$d['decision']] ?? ['ti-point', '#64748b'];
            echo "<li><i class='ti $icon' style='color:$color'></i><div><b>" . $e(($labels[$d['step']] ?? $d['step']) . ' · ' . $d['decision']) . "</b>"
                . ((int) $d['plugin_reembolso_centrocustos_id'] ? "<small>" . $e(Centrocusto::label((int) $d['plugin_reembolso_centrocustos_id'])) . "</small>" : '')
                . "<small>" . $e(getUserName((int) $d['users_id'])) . ' · ' . Html::convDateTime($d['date']) . "</small>" . ($d['comment'] ? "<span>" . $e($d['comment']) . "</span>" : '') . "</div></li>";
        }
        echo "</ul></div></div>";
    }

    /** Lotes que esperam a pessoa (painel no topo da lista). */
    public static function pendingFor(int $uid): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => static::getTable(), 'WHERE' => ['is_deleted' => 0, 'status' => static::OPEN], 'ORDER' => 'id']) as $r) {
            $l = new static();
            if ($l->getFromDB((int) $r['id']) && ($l->canAct() && ((int) $l->fields['users_id'] === $uid || in_array($uid, $l->stepUsers(), true)))) {
                $out[] = $l;
            }
        }
        return $out;
    }
}
