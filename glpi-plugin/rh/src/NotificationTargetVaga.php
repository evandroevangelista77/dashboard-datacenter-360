<?php

namespace GlpiPlugin\Rh;

use NotificationTarget;
use ProfileRight;
use Profile_User;
use User;

/**
 * Destinatários e dados dos avisos da requisição de vaga.
 */
class NotificationTargetVaga extends NotificationTarget
{
    public const REQUESTER = 4001;
    public const MANAGER   = 4002;
    public const RECRUITER = 4003;
    public const BOARD     = 4004;

    public function getEvents()
    {
        return [
            'rh_vaga_gestor'    => 'RH: vaga aguardando o gestor',
            'rh_vaga_diretoria' => 'RH: vaga aguardando a diretoria',
            'rh_vaga_aprovada'  => 'RH: vaga aprovada',
            'rh_vaga_reprovada' => 'RH: vaga reprovada',
            'rh_vaga_andamento' => 'RH: andamento da vaga (para o solicitante)',
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::REQUESTER, 'Solicitante da vaga');
        $this->addTarget(self::MANAGER, 'Gestor da área (1ª aprovação)');
        $this->addTarget(self::RECRUITER, 'Recrutador');
        $this->addTarget(self::BOARD, 'Diretoria (quem tem o direito de aprovar vagas)');
    }

    public function addSpecificTargets($data, $options)
    {
        $field = [self::REQUESTER => 'users_id_requester', self::MANAGER => 'users_id_manager', self::RECRUITER => 'users_id_recruiter'][$data['items_id']] ?? null;
        if ($field) {
            self::addUser($this, (int) ($this->obj->fields[$field] ?? 0));
        } elseif ((int) $data['items_id'] === self::BOARD) {
            foreach (self::usersWithRight(Vaga::$rightname, Vaga::APPROVE_BOARD) as $uid) {
                self::addUser($this, $uid);
            }
        }
    }

    public static function addUser(NotificationTarget $target, int $uid): void
    {
        $u = new User();
        if ($uid && $u->getFromDB($uid) && $u->fields['is_active']) {
            $target->addToRecipientsList(['users_id' => $uid, 'language' => $u->fields['language'] ?: '', 'email' => $u->getDefaultEmail()]);
        }
    }

    /** Usuários ativos com um perfil que tem o direito. */
    public static function usersWithRight(string $right, int $bit): array
    {
        global $DB;
        $profiles = [];
        foreach ($DB->request(['FROM' => ProfileRight::getTable(), 'WHERE' => ['name' => $right]]) as $r) {
            if ((int) $r['rights'] & $bit) {
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
        $this->data['##vaga.name##'] = (string) $f['name'];
        $this->data['##vaga.code##'] = 'VG-' . str_pad((string) $f['id'], 4, '0', STR_PAD_LEFT);
        $this->data['##vaga.cargo##'] = \Dropdown::getDropdownName(Cargo::getTable(), (int) $f['plugin_rh_cargos_id'], false, true, false, '');
        $this->data['##vaga.departamento##'] = \Dropdown::getDropdownName(Departamento::getTable(), (int) $f['plugin_rh_departamentos_id'], false, true, false, '');
        $this->data['##vaga.positions##'] = (string) $f['positions'];
        $this->data['##vaga.contract##'] = Vaga::CONTRACTS[$f['contract']] ?? '';
        $this->data['##vaga.workmode##'] = Vaga::WORKMODES[$f['workmode']] ?? '';
        $this->data['##vaga.reason##'] = (Vaga::REASONS[$f['reason']] ?? '') . ($f['replaced_name'] ? ' (' . $f['replaced_name'] . ')' : '');
        $this->data['##vaga.salary##'] = (float) $f['salary_max'] ? Menu::money((float) $f['salary_min']) . ' a ' . Menu::money((float) $f['salary_max']) : 'a combinar';
        $this->data['##vaga.requester##'] = (string) getUserName((int) $f['users_id_requester']);
        $this->data['##vaga.manager##'] = (string) getUserName((int) $f['users_id_manager']);
        $this->data['##vaga.status##'] = Vaga::STATUSES[(int) $f['status']][0] ?? '';
        $this->data['##vaga.comment##'] = (string) ($options['comment'] ?? $this->options['comment'] ?? '') ?: '—';
        $this->data['##vaga.step##'] = (string) ($options['step'] ?? $this->options['step'] ?? '');
        $this->data['##vaga.step_detail##'] = (string) ($options['detail'] ?? $this->options['detail'] ?? '');
        $this->data['##vaga.url##'] = self::absolute(Vaga::getFormURLWithID((int) $f['id']));
        $this->data['##vaga.approvals_url##'] = self::absolute(Menu::url('/front/aprovacoes.php'));
    }

    public function getTags()
    {
        $tags = ['name' => 'Título', 'code' => 'Código', 'cargo' => 'Cargo', 'departamento' => 'Departamento', 'positions' => 'Posições', 'contract' => 'Contratação',
            'workmode' => 'Modalidade', 'reason' => 'Motivo', 'salary' => 'Faixa salarial', 'requester' => 'Solicitante', 'manager' => 'Gestor', 'status' => 'Status',
            'comment' => 'Comentário da decisão', 'step' => 'Andamento (título)', 'step_detail' => 'Andamento (detalhe)', 'url' => 'Link da vaga', 'approvals_url' => 'Link de Minhas aprovações'];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => "vaga.$tag", 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }

    public static function absolute(string $path): string
    {
        global $CFG_GLPI;
        $root = rtrim((string) $CFG_GLPI['root_doc'], '/');
        return rtrim((string) $CFG_GLPI['url_base'], '/') . ($root !== '' && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path);
    }
}
