<?php

namespace GlpiPlugin\Rh;

use Html;
use NotificationTarget;

/**
 * Destinatários e dados dos avisos de férias.
 */
class NotificationTargetFeria extends NotificationTarget
{
    public const EMPLOYEE  = 4101;
    public const MANAGER   = 4102;
    public const APPROVERS = 4103;

    public function getEvents()
    {
        return [
            'rh_ferias_pendente' => 'RH: férias aguardando o gestor',
            'rh_ferias_decisao'  => 'RH: férias aprovadas ou reprovadas',
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::EMPLOYEE, 'Colaborador');
        $this->addTarget(self::MANAGER, 'Gestor que aprova');
        $this->addTarget(self::APPROVERS, 'Quem aprova férias de qualquer colaborador');
    }

    public function addSpecificTargets($data, $options)
    {
        switch ((int) $data['items_id']) {
            case self::EMPLOYEE:
                NotificationTargetVaga::addUser($this, (int) $this->obj->fields['users_id']);
                break;
            case self::MANAGER:
                NotificationTargetVaga::addUser($this, (int) $this->obj->fields['users_id_manager']);
                break;
            case self::APPROVERS:
                foreach (NotificationTargetVaga::usersWithRight(Feria::$rightname, Feria::APPROVE) as $uid) {
                    NotificationTargetVaga::addUser($this, $uid);
                }
                break;
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        $f = $this->obj->fields;
        $this->data['##ferias.colaborador##'] = (string) getUserName((int) $f['users_id']);
        $this->data['##ferias.manager##'] = (string) getUserName((int) $f['users_id_manager']);
        $this->data['##ferias.periodo##'] = Html::convDate($f['date_begin']) . ' a ' . Html::convDate($f['date_end']);
        $this->data['##ferias.days##'] = (string) $f['days'];
        $this->data['##ferias.sell##'] = (int) $f['sell_days'] ? $f['sell_days'] . ' dia(s)' : 'não';
        $this->data['##ferias.status##'] = Feria::STATUSES[(int) $f['status']][0] ?? '';
        $this->data['##ferias.note##'] = (string) $f['comment'] ?: '—';
        $this->data['##ferias.comment##'] = (string) ($options['comment'] ?? $this->options['comment'] ?? '') ?: '—';
        $this->data['##ferias.url##'] = NotificationTargetVaga::absolute(Feria::getFormURLWithID((int) $f['id']));
        $this->data['##ferias.approvals_url##'] = NotificationTargetVaga::absolute(Menu::url('/front/aprovacoes.php'));
    }

    public function getTags()
    {
        $tags = ['colaborador' => 'Colaborador', 'manager' => 'Gestor', 'periodo' => 'Período', 'days' => 'Dias', 'sell' => 'Abono', 'status' => 'Status',
            'note' => 'Observação do pedido', 'comment' => 'Comentário da decisão', 'url' => 'Link do pedido', 'approvals_url' => 'Link de Minhas aprovações'];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => "ferias.$tag", 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }
}
