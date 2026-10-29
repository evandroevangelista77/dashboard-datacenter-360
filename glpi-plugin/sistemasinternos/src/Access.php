<?php

namespace GlpiPlugin\Sistemasinternos;

use Session;

/**
 * "Conforme os acessos": que hostgroups do Zabbix o perfil ativo pode gerenciar.
 *
 * A conta de serviço do Zabbix deve ter escrita só nos grupos do departamento;
 * aqui o GLPI restringe ainda mais, por perfil (aba Sistemas Internos 360 do perfil).
 * Perfil sem grupos definidos usa os grupos do departamento da configuração.
 */
class Access
{
    public const TABLE = 'glpi_plugin_sistemasinternos_profilegroups';

    /** Grupos definidos para o perfil: id → nome (vazio = usa os do departamento). */
    public static function profileGroups(int $profileId): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['profiles_id' => $profileId], 'ORDER' => 'name']) as $r) {
            $out[(string) $r['groupid']] = (string) $r['name'];
        }
        return $out;
    }

    public static function setProfileGroups(int $profileId, array $groups): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['profiles_id' => $profileId]);
        foreach ($groups as $id => $name) {
            if (ctype_digit((string) $id)) {
                $DB->insert(self::TABLE, ['profiles_id' => $profileId, 'groupid' => (string) $id, 'name' => mb_substr((string) $name, 0, 255)]);
            }
        }
    }

    /** IDs dos grupos que o perfil ativo pode gerenciar. */
    public static function allowedGroupIds(): array
    {
        static $cache = null;
        if ($cache === null) {
            $pid = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
            $own = array_keys(self::profileGroups($pid));
            $cache = array_map('strval', $own ?: Settings::ids('groups'));
        }
        return $cache;
    }

    /** Os grupos permitidos com o nome atual no Zabbix. */
    public static function allowedGroups(): array
    {
        $all = Zabbix::allGroups();
        $out = [];
        foreach (self::allowedGroupIds() as $id) {
            $out[$id] = $all[$id] ?? "Grupo $id (sem acesso da conta de serviço)";
        }
        asort($out);
        return $out;
    }

    /** Pode ver/editar: o host está em pelo menos um grupo permitido. */
    public static function canSee(array $host): bool
    {
        return (bool) array_intersect(array_column(Zabbix::hostGroups($host), 'groupid'), self::allowedGroupIds());
    }

    /** Pode excluir/desativar sem afetar outros departamentos: todos os grupos do host são permitidos. */
    public static function ownsAll(array $host): bool
    {
        $ids = array_column(Zabbix::hostGroups($host), 'groupid');
        return $ids && !array_diff($ids, self::allowedGroupIds());
    }

    public static function can(int $right): bool
    {
        return Session::haveRight(Menu::RIGHT_HOST, $right);
    }

    /** Templates que podem ser vinculados (lista da configuração; vazia = todos os que a conta enxerga). */
    public static function allowedTemplates(): array
    {
        $all = Zabbix::allTemplates();
        $ids = Settings::ids('templates');
        return $ids ? array_intersect_key($all, array_flip($ids)) : $all;
    }
}
