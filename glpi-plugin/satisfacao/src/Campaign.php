<?php

namespace GlpiPlugin\Satisfacao;

use CommonDBTM;
use Config;
use NotificationEvent;
use Session;
use User;

/**
 * Campanha de eNPS (clima): a pergunta "de 0 a 10, quanto você recomendaria a empresa como lugar
 * para trabalhar" enviada aos usuários dos grupos escolhidos (e a e-mails extras). Cada destinatário
 * recebe o seu link; a resposta é anônima (ver Survey::saveEnps).
 */
class Campaign extends CommonDBTM
{
    public static string $rightname = 'plugin_satisfacao';

    public const DRAFT = 0;
    public const SENT = 1;
    public const CLOSED = 2;

    public const STATUSES = [
        self::DRAFT  => ['Rascunho', 'secondary'],
        self::SENT   => ['Aberta', 'green'],
        self::CLOSED => ['Encerrada', 'azure'],
    ];

    public const DEFAULT_QUESTION = 'Em uma escala de 0 a 10, qual a probabilidade de você recomendar a nossa empresa como um bom lugar para trabalhar?';
    public const DEFAULT_COMMENT = 'Qual o principal motivo da sua nota?';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Campanhas de eNPS' : 'Campanha de eNPS';
    }

    /** Destinatários: usuários ativos com e-mail dos grupos escolhidos, mais os e-mails extras. @return array<string, string> e-mail => grupo */
    public function recipients(): array
    {
        global $DB;
        $out = [];
        $groups = Survey::idList((string) $this->fields['groups']);
        if ($groups) {
            $names = [];
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $groups]]) as $g) {
                $names[(int) $g['id']] = (string) $g['completename'];
            }
            foreach ($DB->request(['SELECT' => ['users_id', 'groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $groups], 'ORDER' => ['groups_id']]) as $row) {
                $user = new User();
                if (!$user->getFromDB((int) $row['users_id']) || !$user->fields['is_active'] || $user->fields['is_deleted']) {
                    continue;
                }
                $email = mb_strtolower((string) $user->getDefaultEmail());
                if ($email !== '' && !isset($out[$email])) {
                    $out[$email] = $names[(int) $row['groups_id']] ?? '';
                }
            }
        }
        foreach (Survey::splitEmails((string) $this->fields['emails']) as $email) {
            $out[mb_strtolower($email)] ??= '';
        }
        return $out;
    }

    /** Cria a pesquisa de cada destinatário que ainda não tem e avisa por e-mail. Devolve quantas foram criadas. */
    public function send(): int
    {
        global $DB;
        if ((int) $this->fields['status'] === self::CLOSED) {
            return 0;
        }
        $have = [];
        foreach ($DB->request(['SELECT' => ['recipients'], 'FROM' => Survey::getTable(), 'WHERE' => ['itemtype' => Survey::ENPS, 'items_id' => $this->getID()]]) as $row) {
            $have[mb_strtolower((string) $row['recipients'])] = true;
        }
        $mail = (Config::getConfigurationValues('plugin:satisfacao')['email_enabled'] ?? '1') === '1';
        $created = 0;
        foreach ($this->recipients() as $email => $group) {
            if (isset($have[$email])) {
                continue;
            }
            $survey = new Survey();
            $id = $survey->add([
                'entities_id' => (int) $this->fields['entities_id'], 'itemtype' => Survey::ENPS, 'items_id' => $this->getID(),
                'name' => mb_substr((string) $this->fields['name'], 0, 255), 'recipients' => $email, 'group_name' => $group !== '' ? $group : null,
                'status' => Survey::SENT, 'date_sent' => date('Y-m-d H:i:s'),
            ]);
            if ($id && $survey->getFromDB($id)) {
                $created++;
                if ($mail) {
                    NotificationEvent::raiseEvent(Notifications::EVENT_ENPS, $survey);
                }
            }
        }
        $this->update(['id' => $this->getID(), 'status' => self::SENT, 'date_sent' => $this->fields['date_sent'] ?: date('Y-m-d H:i:s')]);
        return $created;
    }

    /** Reenvia o e-mail para quem ainda não respondeu. Devolve quantos lembretes saíram. */
    public function remind(): int
    {
        global $DB;
        if ((int) $this->fields['status'] !== self::SENT || (Config::getConfigurationValues('plugin:satisfacao')['email_enabled'] ?? '1') !== '1') {
            return 0;
        }
        $n = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Survey::getTable(), 'WHERE' => ['itemtype' => Survey::ENPS, 'items_id' => $this->getID(), 'status' => Survey::SENT]]) as $row) {
            $survey = new Survey();
            if ($survey->getFromDB((int) $row['id'])) {
                NotificationEvent::raiseEvent(Notifications::EVENT_ENPS, $survey);
                $n++;
            }
        }
        return $n;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->clean($input);
        if ($input === false) {
            return false;
        }
        $input['status'] = self::DRAFT;
        $input['date_creation'] = date('Y-m-d H:i:s');
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->clean($input);
        if ($input === false) {
            return false;
        }
        $input['date_mod'] = date('Y-m-d H:i:s');
        return parent::prepareInputForUpdate($input);
    }

    private function clean(array $input)
    {
        if (isset($input['name'])) {
            $input['name'] = mb_substr(trim((string) $input['name']), 0, 255);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect('Informe o nome da campanha.', false, ERROR);
                return false;
            }
        }
        foreach (['question' => self::DEFAULT_QUESTION, 'comment_question' => self::DEFAULT_COMMENT] as $field => $default) {
            if (array_key_exists($field, $input)) {
                $input[$field] = mb_substr(trim((string) $input[$field]), 0, 500) ?: $default;
            }
        }
        if (array_key_exists('groups', $input)) {
            $input['groups'] = implode(',', array_filter(array_map('intval', (array) $input['groups'])));
        }
        if (array_key_exists('emails', $input)) {
            $input['emails'] = implode(', ', Survey::splitEmails((string) $input['emails']));
        }
        return $input;
    }
}
