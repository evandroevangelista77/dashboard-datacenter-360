<?php

namespace GlpiPlugin\Rh;

use Dropdown;
use NotificationEvent;
use Plugin;
use Throwable;

/**
 * Avisos do RH no sino, no pop-up e no canal do Teams, pelo plugin "Avisos"
 * (sem ele instalado, só o e-mail das notificações do GLPI continua).
 */
class Avisos
{
    public static function send(array $a): void
    {
        if (!Plugin::isPluginActive('avisos') || !class_exists(\GlpiPlugin\Avisos\Aviso::class)) {
            return;
        }
        try {
            \GlpiPlugin\Avisos\Aviso::send($a + ['source' => 'rh']);
        } catch (Throwable $e) {
            trigger_error('RH: aviso não enviado: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    public static function code(Vaga $v): string
    {
        return 'VG-' . str_pad((string) $v->getID(), 4, '0', STR_PAD_LEFT);
    }

    /** Aviso sobre uma vaga: dados da vaga no cartão do Teams e link para ela. */
    public static function vaga(Vaga $v, string $event, array $users, string $title, string $message, string $level = 'info', array $facts = []): void
    {
        $f = $v->fields;
        self::send([
            'event'   => $event,
            'users'   => $users,
            'title'   => $title,
            'message' => $message,
            'level'   => $level,
            'url'     => Vaga::getFormURLWithID($v->getID()),
            'item'    => [Vaga::class, $v->getID()],
            'facts'   => $facts + [
                'Vaga'         => self::code($v) . ' · ' . $f['name'],
                'Cargo'        => Dropdown::getDropdownName(Cargo::getTable(), (int) $f['plugin_rh_cargos_id'], false, true, false, ''),
                'Departamento' => Dropdown::getDropdownName(Departamento::getTable(), (int) $f['plugin_rh_departamentos_id'], false, true, false, ''),
                'Situação'     => Vaga::STATUSES[(int) $f['status']][0] ?? '',
                'Solicitante'  => (string) getUserName((int) $f['users_id_requester']),
            ],
        ]);
    }

    /**
     * Andamento da vaga para o solicitante: aviso e o e-mail "RH - Andamento da vaga".
     * Outras pessoas recebem só o aviso (o e-mail delas já sai pelas outras notificações).
     */
    public static function andamento(Vaga $v, string $event, string $step, string $detail, string $level = 'info', array $others = [], array $facts = []): void
    {
        $requester = (int) $v->fields['users_id_requester'];
        self::vaga($v, $event, array_merge([$requester], $others), $step, $detail, $level, $facts);
        NotificationEvent::raiseEvent('rh_vaga_andamento', $v, ['step' => $step, 'detail' => $detail]);
    }

    /** Pessoas da vaga: solicitante, gestor da área e recrutador. */
    public static function pessoas(Vaga $v): array
    {
        return array_values(array_filter(array_map('intval', [$v->fields['users_id_requester'], $v->fields['users_id_manager'], $v->fields['users_id_recruiter']])));
    }

    public static function diretoria(): array
    {
        return NotificationTargetVaga::usersWithRight(Vaga::$rightname, Vaga::APPROVE_BOARD);
    }
}
