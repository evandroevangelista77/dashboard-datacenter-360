<?php

namespace GlpiPlugin\Satisfacao;

use CommonDBTM;
use CommonITILActor;
use Config;
use NotificationEvent;
use Project;
use ProjectState;
use Ticket;
use Ticket_User;
use User;

/**
 * Uma pesquisa de satisfação por chamado ou por Ciclo Financeiro. É criada ao
 * fechar o chamado (ou concluir o ciclo), com um token que dá o link para responder
 * sem precisar do menu do GLPI. Cada item recebe no máximo uma pesquisa.
 */
class Survey extends CommonDBTM
{
    public static string $rightname = 'plugin_satisfacao';

    public const ANSWERS_TABLE = 'glpi_plugin_satisfacao_answers';
    public const ENPS_TABLE = 'glpi_plugin_satisfacao_enps_answers';

    /** Origem virtual das pesquisas de eNPS (clima): items_id é a campanha, e cada destinatário tem a sua pesquisa. */
    public const ENPS = 'eNPS';

    /** Grupos com menos respostas que isso não aparecem no painel do eNPS, para não identificar ninguém. */
    public const ENPS_MIN_GROUP = 5;

    public const SENT = 0;
    public const ANSWERED = 1;

    public const STATUSES = [
        self::SENT     => 'Enviada',
        self::ANSWERED => 'Respondida',
    ];

    /** Carinhas das notas de 1 a 5 (a página de resposta e o painel usam as mesmas). */
    public const FACES = [1 => '😡', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😍'];
    public const FACE_NAMES = [1 => 'Muito ruim', 2 => 'Ruim', 3 => 'Regular', 4 => 'Bom', 5 => 'Excelente'];

    /** Origem da pesquisa: a classe do item que a gerou. */
    public const ORIGINS = [
        Ticket::class                  => 'Chamado',
        Project::class                 => 'Projeto',
        PLUGIN_SATISFACAO_EVENTO_CLASS => 'Evento',
        PLUGIN_SATISFACAO_CICLO_CLASS  => 'Ciclo financeiro',
    ];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Pesquisas de satisfação' : 'Pesquisa de satisfação';
    }

    /** Gatilho de atualização: envia a pesquisa quando o item chega ao estado final e passa pelos filtros da configuração. */
    public static function onItemUpdate(CommonDBTM $item): void
    {
        $config = Config::getConfigurationValues('plugin:satisfacao');
        $updates = (array) $item->updates;

        if ($item instanceof Ticket) {
            if (!in_array('status', $updates, true)) {
                return;
            }
            $states = [Ticket::CLOSED];
            if (($config['on_solved'] ?? '0') === '1') {
                $states[] = Ticket::SOLVED;
            }
            if (in_array((int) $item->fields['status'], $states, true) && self::allowedTicket($item, $config)) {
                self::sendFor($item, self::requesterEmails((int) $item->getID()));
            }
            return;
        }

        if ($item instanceof Project) {
            if (in_array('projectstates_id', $updates, true) && ($config['project_enabled'] ?? '1') === '1' && self::inEntities($item, $config) && self::isFinishedState((int) $item->fields['projectstates_id'])) {
                self::sendFor($item, self::splitEmails((string) ($config['project_emails'] ?? '')));
            }
            return;
        }

        if (!in_array('status', $updates, true)) {
            return;
        }
        $status = (int) $item->fields['status'];
        if ($item::class === PLUGIN_SATISFACAO_EVENTO_CLASS && ($config['event_enabled'] ?? '1') === '1' && $status === PLUGIN_SATISFACAO_EVENTO_DONE && self::inEntities($item, $config)) {
            self::sendFor($item, self::splitEmails((string) ($config['event_emails'] ?? '')));
        } elseif ($item::class === PLUGIN_SATISFACAO_CICLO_CLASS && ($config['ciclo_enabled'] ?? '1') === '1' && $status === PLUGIN_SATISFACAO_CICLO_DONE && self::inEntities($item, $config)) {
            self::sendFor($item, self::splitEmails((string) ($config['cycle_emails'] ?? '')));
        }
    }

    /** Lista de ids guardada na configuração (separada por vírgula). */
    public static function idList(string $text): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $text))));
    }

    /** Filtro por entidade: lista vazia vale para todas. */
    private static function inEntities(CommonDBTM $item, array $config): bool
    {
        $entities = self::idList((string) ($config['entities'] ?? ''));
        return !$entities || in_array((int) ($item->fields['entities_id'] ?? 0), $entities, true);
    }

    /** Filtros do chamado: entidade, categoria e tipo de solução (lista vazia vale para todos). */
    private static function allowedTicket(Ticket $ticket, array $config): bool
    {
        global $DB;
        if (!self::inEntities($ticket, $config)) {
            return false;
        }
        $categories = self::idList((string) ($config['categories'] ?? ''));
        if ($categories && !in_array((int) $ticket->fields['itilcategories_id'], $categories, true)) {
            return false;
        }
        $solutions = self::idList((string) ($config['solutions'] ?? ''));
        if ($solutions) {
            $last = $DB->request([
                'SELECT' => ['solutiontypes_id'], 'FROM' => 'glpi_itilsolutions',
                'WHERE'  => ['itemtype' => Ticket::class, 'items_id' => $ticket->getID()], 'ORDER' => ['id DESC'], 'LIMIT' => 1,
            ])->current();
            if (!$last || !in_array((int) $last['solutiontypes_id'], $solutions, true)) {
                return false;
            }
        }
        return true;
    }

    /** O estado do projeto está marcado como "finalizado" no GLPI. */
    private static function isFinishedState(int $stateId): bool
    {
        $state = new ProjectState();
        return $stateId > 0 && $state->getFromDB($stateId) && (int) $state->fields['is_finished'] === 1;
    }

    /**
     * Cria a pesquisa do item e avisa os destinatários pelas notificações do GLPI
     * (Configurar → Notificações, evento "Pesquisa de satisfação"). Sem destinatário
     * ou sem perguntas ativas, não cria nada.
     */
    public static function sendFor(CommonDBTM $item, array $emails): void
    {
        $emails = array_values(array_unique(array_filter(array_map('trim', $emails))));
        if (!$emails || !$item->getID() || !Question::active()) {
            return;
        }
        if (countElementsInTable(self::getTable(), ['itemtype' => $item::class, 'items_id' => $item->getID()])) {
            return;
        }

        $survey = new self();
        $id = $survey->add([
            'entities_id' => (int) ($item->fields['entities_id'] ?? 0),
            'itemtype'    => $item::class,
            'items_id'    => $item->getID(),
            'name'        => mb_substr(self::titleOf($item), 0, 255),
            'recipients'  => implode(', ', $emails),
            'status'      => self::SENT,
            'date_sent'   => date('Y-m-d H:i:s'),
        ]);
        if (!$id || !$survey->getFromDB($id)) {
            return;
        }
        // com o envio desligado a pesquisa fica criada (aparece como "sem resposta" no painel), mas o e-mail não sai
        if ((Config::getConfigurationValues('plugin:satisfacao')['email_enabled'] ?? '1') === '1') {
            NotificationEvent::raiseEvent(Notifications::EVENT, $survey);
        }
    }

    /** Pesquisa dona do token, ou null se o token não existe ou está mal formado. */
    public static function byToken(string $token): ?self
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $survey = new self();
        return $survey->getFromDBByCrit(['token' => $token]) ? $survey : null;
    }

    /** Endereço da pesquisa para o e-mail (completo, pois o e-mail é lido fora do GLPI). */
    public function url(): string
    {
        return Menu::absolute('/front/responder.php?token=' . $this->fields['token']);
    }

    /**
     * Grava as respostas. Devolve a mensagem de erro, ou '' quando deu certo.
     * A pergunta vai junto da resposta, para o histórico não mudar ao editar a pergunta.
     */
    public function saveAnswers(array $input): string
    {
        global $DB;
        if ((int) $this->fields['status'] === self::ANSWERED) {
            return 'Esta pesquisa já foi respondida. Obrigado!';
        }

        $rows = [];
        foreach (Question::active() as $q) {
            $raw = $input['q' . $q['id']] ?? null;
            $score = null;
            $comment = null;
            switch ($q['type']) {
                case 'rating':
                    $score = is_numeric($raw) && (int) $raw >= 1 && (int) $raw <= 5 ? (int) $raw : null;
                    break;
                case 'nps':
                    $score = is_numeric($raw) && (int) $raw >= 0 && (int) $raw <= 10 ? (int) $raw : null;
                    break;
                case 'yesno':
                    $score = $raw === '1' ? 1 : ($raw === '0' ? 0 : null);
                    break;
                case 'text':
                    $comment = trim((string) $raw) !== '' ? mb_substr(trim((string) $raw), 0, 5000) : null;
                    break;
            }
            if ((int) $q['is_required'] && $score === null && $comment === null) {
                return 'Responda: ' . $q['name'];
            }
            if ($score !== null || $comment !== null) {
                $rows[] = [
                    'plugin_satisfacao_surveys_id' => $this->getID(),
                    'questions_id'                 => (int) $q['id'],
                    'question'                     => mb_substr((string) $q['name'], 0, 255),
                    'type'                         => $q['type'],
                    'score'                        => $score,
                    'comment'                      => $comment,
                ];
            }
        }

        foreach ($rows as $row) {
            $DB->insert(self::ANSWERS_TABLE, $row);
        }
        $this->update(['id' => $this->getID(), 'status' => self::ANSWERED, 'date_answered' => date('Y-m-d H:i:s')]);
        return '';
    }

    /**
     * Grava a resposta do eNPS. Ela é anônima: a nota, o comentário e o grupo vão para uma tabela
     * sem ligação com a pesquisa, e a pesquisa só marca que o destinatário já respondeu.
     */
    public function saveEnps(array $input): string
    {
        global $DB;
        if ((int) $this->fields['status'] === self::ANSWERED) {
            return 'Esta pesquisa já foi respondida. Obrigado!';
        }
        $campaign = new Campaign();
        if (!$campaign->getFromDB((int) $this->fields['items_id']) || (int) $campaign->fields['status'] !== Campaign::SENT) {
            return 'Esta campanha já foi encerrada.';
        }
        $score = $input['score'] ?? null;
        if (!is_numeric($score) || (int) $score < 0 || (int) $score > 10) {
            return 'Escolha uma nota de 0 a 10.';
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        $DB->insert(self::ENPS_TABLE, [
            'campaigns_id'  => $campaign->getID(),
            'score'         => (int) $score,
            'comment'       => $comment !== '' ? mb_substr($comment, 0, 5000) : null,
            'group_name'    => $this->fields['group_name'] ?: null,
            'date_answered' => date('Y-m-d'),
        ]);
        $this->update(['id' => $this->getID(), 'status' => self::ANSWERED]);
        return '';
    }

    public function prepareInputForAdd($input)
    {
        $input['token'] = $input['token'] ?? bin2hex(random_bytes(32));
        $input['date_creation'] = date('Y-m-d H:i:s');
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input['date_mod'] = date('Y-m-d H:i:s');
        return parent::prepareInputForUpdate($input);
    }

    /** Endereços de quem solicitou o chamado (com e-mail e ativos). */
    private static function requesterEmails(int $ticketId): array
    {
        global $DB;
        $emails = [];
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => Ticket_User::getTable(), 'WHERE' => ['tickets_id' => $ticketId, 'type' => CommonITILActor::REQUESTER]]) as $row) {
            $user = new User();
            if ((int) $row['users_id'] && $user->getFromDB((int) $row['users_id']) && $user->fields['is_active'] && $user->getDefaultEmail()) {
                $emails[] = $user->getDefaultEmail();
            }
        }
        return $emails;
    }

    /** @return string[] */
    public static function splitEmails(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $text) ?: [])));
    }

    private static function titleOf(CommonDBTM $item): string
    {
        $name = (string) ($item->fields['name'] ?? '');
        $label = self::ORIGINS[$item::class] ?? $item::getTypeName(1);
        if ($item instanceof Ticket) {
            return 'Chamado nº ' . $item->getID() . ($name !== '' ? ": $name" : '');
        }
        return "$label " . ($name !== '' ? $name : '#' . $item->getID());
    }
}
