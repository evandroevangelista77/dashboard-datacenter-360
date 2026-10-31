<?php

namespace GlpiPlugin\Gestaoeventos;

use Plugin;
use Throwable;

/**
 * Avisos do evento no sino, no pop-up e no canal do Teams, pelo plugin "Avisos".
 * Quem executa a etapa também recebe o chamado da etapa (e o e-mail do chamado).
 */
class Avisos
{
    public static function send(Evento $ev, string $event, array $users, string $title, string $message, string $level = 'info', array $facts = [], bool $teams = true): void
    {
        $users = array_values(array_unique(array_filter(array_map('intval', $users))));
        if (!$users || !Plugin::isPluginActive('avisos') || !class_exists(\GlpiPlugin\Avisos\Aviso::class)) {
            return;
        }
        $f = $ev->fields;
        try {
            \GlpiPlugin\Avisos\Aviso::send([
                'source' => 'gestaoeventos', 'event' => $event, 'users' => $users, 'title' => $title, 'message' => $message, 'level' => $level,
                'url' => Evento::getFormURLWithID($ev->getID()), 'item' => [Evento::class, $ev->getID()], 'teams' => $teams,
                'facts' => ['Evento' => $f['code'] . ' · ' . $f['name'], 'Tipo' => Evento::TYPES[$f['event_type']][0] ?? '',
                    'Data' => Menu::date($f['date_start']), 'Etapa' => $f['current_phase'] ? Fase::name((string) $f['current_phase']) : '—'] + $facts,
            ]);
        } catch (Throwable $e) {
            trigger_error('Gestão de Eventos: aviso não enviado: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /** Membros de um grupo do GLPI. */
    public static function members(int $groupId): array
    {
        global $DB;
        if (!$groupId) {
            return [];
        }
        return array_map(fn ($r) => (int) $r['users_id'], iterator_to_array($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $groupId]]), false));
    }

    /** Dono, GP e solicitante: acompanham todas as movimentações. */
    public static function followers(Evento $ev): array
    {
        return array_values(array_unique(array_filter([(int) $ev->fields['users_id_gp'], (int) $ev->fields['users_id_owner'], (int) $ev->fields['users_id_requester']])));
    }

    /** Usuários com o direito de decidir (perfil com "Aprovar"), para alçadas sem grupo. */
    public static function approvers(): array
    {
        global $DB;
        $profiles = [];
        foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => Evento::$rightname]]) as $r) {
            if ((int) $r['rights'] & Evento::APPROVE) {
                $profiles[] = (int) $r['profiles_id'];
            }
        }
        if (!$profiles) {
            return [];
        }
        $ids = [];
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_profiles_users', 'WHERE' => ['profiles_id' => $profiles]]) as $r) {
            $ids[] = (int) $r['users_id'];
        }
        return array_values(array_unique($ids));
    }
}
