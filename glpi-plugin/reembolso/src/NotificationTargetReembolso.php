<?php

namespace GlpiPlugin\Reembolso;

use Dropdown;
use NotificationTarget;
use ProfileRight;
use Profile_User;
use User;

/**
 * Destinatários e dados dos e-mails do reembolso.
 */
class NotificationTargetReembolso extends NotificationTarget
{
    public const TECH      = 5001;
    public const RECIPIENT = 5002;
    public const APPROVERS = 5003;

    public function getEvents()
    {
        return [
            'rb_enviado'  => 'Reembolso: enviado para aprovação',
            'rb_aprovado' => 'Reembolso: aprovado',
            'rb_recusado' => 'Reembolso: recusado',
            'rb_pago'     => 'Reembolso: pagamento registrado',
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TECH, 'Técnico do reembolso');
        $this->addTarget(self::RECIPIENT, 'Quem lançou a solicitação');
        $this->addTarget(self::APPROVERS, 'Quem aprova reembolsos (direito "Aprovar")');
    }

    public function addSpecificTargets($data, $options)
    {
        $field = [self::TECH => 'users_id', self::RECIPIENT => 'users_id_recipient'][$data['items_id']] ?? null;
        if ($field) {
            $this->addUser((int) ($this->obj->fields[$field] ?? 0));
        } elseif ((int) $data['items_id'] === self::APPROVERS) {
            foreach (self::approvers() as $uid) {
                $this->addUser($uid);
            }
        }
    }

    private function addUser(int $uid): void
    {
        $u = new User();
        if ($uid && $u->getFromDB($uid) && $u->fields['is_active']) {
            $this->addToRecipientsList(['users_id' => $uid, 'language' => $u->fields['language'] ?: '', 'email' => $u->getDefaultEmail()]);
        }
    }

    /** Usuários ativos com um perfil que tem o direito de aprovar reembolsos. */
    public static function approvers(): array
    {
        global $DB;
        $profiles = [];
        foreach ($DB->request(['FROM' => ProfileRight::getTable(), 'WHERE' => ['name' => Reembolso::$rightname]]) as $r) {
            if ((int) $r['rights'] & Reembolso::APPROVE) {
                $profiles[] = (int) $r['profiles_id'];
            }
        }
        if (!$profiles) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['pu.users_id'], 'DISTINCT' => true, 'FROM' => Profile_User::getTable() . ' AS pu',
            'INNER JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'pu' => 'users_id']]],
            'WHERE' => ['pu.profiles_id' => $profiles, 'u.is_active' => 1, 'u.is_deleted' => 0],
        ]) as $r) {
            $out[] = (int) $r['users_id'];
        }
        return $out;
    }

    public function addDataForTemplate($event, $options = [])
    {
        $f = $this->obj->fields;
        $this->data['##reembolso.code##'] = Reembolso::code((int) $f['id']);
        $this->data['##reembolso.name##'] = (string) $f['name'];
        $this->data['##reembolso.tecnico##'] = (string) getUserName((int) $f['users_id']);
        $this->data['##reembolso.centro##'] = Dropdown::getDropdownName(Centrocusto::getTable(), (int) $f['plugin_reembolso_centrocustos_id'], false, true, false, '');
        $this->data['##reembolso.total##'] = Money::format((float) $f['total']);
        $this->data['##reembolso.periodo##'] = $f['date_begin'] ? date('d/m/Y', strtotime($f['date_begin'])) . ($f['date_end'] && $f['date_end'] !== $f['date_begin'] ? ' a ' . date('d/m/Y', strtotime($f['date_end'])) : '') : '—';
        $this->data['##reembolso.status##'] = Reembolso::STATUSES[(int) $f['status']][0] ?? '';
        $this->data['##reembolso.approver##'] = $f['users_id_approver'] ? (string) getUserName((int) $f['users_id_approver']) : '—';
        $this->data['##reembolso.comment##'] = (string) $f['approval_comment'] ?: '—';
        $this->data['##reembolso.payment##'] = $f['date_payment'] ? date('d/m/Y', strtotime($f['date_payment'])) . ($f['payment_reference'] ? ' · ' . $f['payment_reference'] : '') : '—';
        $this->data['##reembolso.url##'] = Avisos::absolute(Reembolso::getFormURLWithID((int) $f['id']));
    }

    public function getTags()
    {
        $tags = ['code' => 'Código', 'name' => 'Título', 'tecnico' => 'Técnico', 'centro' => 'Centro de custo', 'total' => 'Total', 'periodo' => 'Período',
            'status' => 'Situação', 'approver' => 'Quem decidiu', 'comment' => 'Comentário da decisão', 'payment' => 'Pagamento (data e referência)', 'url' => 'Link da solicitação'];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => "reembolso.$tag", 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }
}
