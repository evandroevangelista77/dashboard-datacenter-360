<?php

namespace GlpiPlugin\Ciclo;

use Plugin;
use Throwable;

/**
 * Avisos do Ciclo no sino, no pop-up e no canal do Teams, pelo plugin "Avisos".
 * Quem executa a fase também recebe o chamado da fase (e o e-mail do chamado).
 */
class Avisos
{
    public static function send(Ciclo $c, string $event, array $users, string $title, string $message, string $level = 'info', array $facts = [], bool $teams = true): void
    {
        if (!Plugin::isPluginActive('avisos') || !class_exists(\GlpiPlugin\Avisos\Aviso::class)) {
            return;
        }
        $f = $c->fields;
        try {
            \GlpiPlugin\Avisos\Aviso::send([
                'source' => 'ciclo', 'event' => $event, 'users' => $users, 'title' => $title, 'message' => $message, 'level' => $level,
                'url' => Ciclo::getFormURLWithID($c->getID()), 'item' => [Ciclo::class, $c->getID()], 'teams' => $teams,
                'facts' => ['Ciclo' => Ciclo::code($c->getID()) . ' · ' . $f['name'], 'Cliente' => (string) $f['customer'],
                    'Tipo de venda' => Ciclo::SALE_TYPES[$f['sale_type']][0] ?? '', 'Dias no ciclo' => Menu::days(Ciclo::cycleSeconds($f))] + $facts,
            ]);
        } catch (Throwable $e) {
            trigger_error('Ciclo: aviso não enviado: ' . $e->getMessage(), E_USER_WARNING);
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

    /** GP e OTC do Ciclo: acompanham todas as movimentações. */
    public static function followers(Ciclo $c): array
    {
        return array_values(array_filter([(int) $c->fields['users_id_gp'], (int) $c->fields['users_id_otc']]));
    }
}
