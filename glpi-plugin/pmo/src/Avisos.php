<?php

namespace GlpiPlugin\Pmo;

use Plugin;
use Throwable;

/**
 * Avisos do PMO no sino, no pop-up e no canal do Teams, pelo plugin "Avisos"
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
            \GlpiPlugin\Avisos\Aviso::send($a + ['source' => 'pmo']);
        } catch (Throwable $e) {
            trigger_error('PMO: aviso não enviado: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /** Gerente do projeto. */
    public static function manager(int $projectId): array
    {
        global $DB;
        $row = $DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_projects', 'WHERE' => ['id' => $projectId]])->current();
        return $row && $row['users_id'] ? [(int) $row['users_id']] : [];
    }

    /** Gerente e equipe do projeto (usuários e membros dos grupos da equipe). */
    public static function projectPeople(int $projectId): array
    {
        return array_values(array_unique(array_merge(self::manager($projectId), self::team('glpi_projectteams', 'projects_id', $projectId))));
    }

    /** Equipe da tarefa (usuários e grupos). */
    public static function taskPeople(int $taskId): array
    {
        return self::team('glpi_projecttaskteams', 'projecttasks_id', $taskId);
    }

    private static function team(string $table, string $field, int $id): array
    {
        global $DB;
        $users = [];
        $groups = [];
        foreach ($DB->request(['SELECT' => ['itemtype', 'items_id'], 'FROM' => $table, 'WHERE' => [$field => $id]]) as $r) {
            if ($r['itemtype'] === 'User') {
                $users[] = (int) $r['items_id'];
            } elseif ($r['itemtype'] === 'Group') {
                $groups[] = (int) $r['items_id'];
            }
        }
        if ($groups) {
            foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $groups]]) as $r) {
                $users[] = (int) $r['users_id'];
            }
        }
        return array_values(array_unique($users));
    }
}
