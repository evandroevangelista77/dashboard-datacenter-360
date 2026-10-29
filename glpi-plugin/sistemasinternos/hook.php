<?php

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;

/**
 * Cria as tabelas (grupos do Zabbix por perfil, auditoria e estado dos alertas
 * de cada usuário), os direitos e a configuração padrão.
 */
function plugin_sistemasinternos_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    $tables = [
        // hostgroups do Zabbix que cada perfil pode gerenciar (sem linhas = grupos do departamento)
        'glpi_plugin_sistemasinternos_profilegroups' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `profiles_id` int $sign NOT NULL DEFAULT 0,
            `groupid` varchar(20) NOT NULL,
            `name` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `profile_group` (`profiles_id`, `groupid`)",
        // tudo o que foi feito nos hosts do Zabbix pelo GLPI, com o antes e o depois
        'glpi_plugin_sistemasinternos_logs' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `action` varchar(20) NOT NULL,
            `hostid` varchar(20) DEFAULT NULL,
            `host` varchar(255) DEFAULT NULL,
            `result` varchar(10) NOT NULL DEFAULT 'ok',
            `message` text,
            `before` longtext,
            `after` longtext,
            `ip` varchar(64) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `hostid` (`hostid`),
            KEY `date_creation` (`date_creation`)",
        // o que cada usuário já recebeu de alerta (para avisar só do que é novo) e se silenciou
        'glpi_plugin_sistemasinternos_seens' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `last_eventid` bigint NOT NULL DEFAULT 0,
            `last_recoveryid` bigint NOT NULL DEFAULT 0,
            `last_tickets_id` int $sign NOT NULL DEFAULT 0,
            `last_logs_id` int $sign NOT NULL DEFAULT 0,
            `sla_alerted` text,
            `muted_until` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // direitos: painel (ler), hosts (ler, incluir, editar, excluir) e configuração
    // atualização: cria só os direitos que ainda não existem (repetir dá chave duplicada)
    $newRights = array_values(array_filter(Menu::RIGHTS, fn ($r) => !countElementsInTable(ProfileRight::getTable(), ['name' => $r])));
    if ($newRights) {
        ProfileRight::addProfileRights($newRights);
    }
    $full = [
        Menu::RIGHT_PAINEL => READ,
        Menu::RIGHT_HOST   => READ | CREATE | UPDATE | PURGE,
        Menu::RIGHT_CONFIG => UPDATE,
    ];
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
        $pid = (int) $row['id'];
        $isAdmin = (ProfileRight::getProfileRights($pid, ['config'])['config'] ?? 0) & UPDATE;
        foreach (Menu::RIGHTS as $right) {
            $current = (int) ($DB->request(['SELECT' => ['rights'], 'FROM' => ProfileRight::getTable(), 'WHERE' => ['profiles_id' => $pid, 'name' => $right]])->current()['rights'] ?? 0);
            if ($current) {
                continue; // atualização: mantém o que o administrador já definiu
            }
            $value = $isAdmin ? $full[$right] : ($right === Menu::RIGHT_CONFIG ? 0 : READ);
            $DB->update(ProfileRight::getTable(), ['rights' => $value], ['profiles_id' => $pid, 'name' => $right]);
        }
    }

    Settings::installDefaults();

    // perfil da sessão atual passa a enxergar o menu sem precisar relogar
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        foreach (ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], Menu::RIGHTS) as $name => $value) {
            $_SESSION['glpiactiveprofile'][$name] = (int) $value;
        }
    }
    unset($_SESSION['glpimenu']);

    return true;
}

function plugin_sistemasinternos_uninstall(): bool
{
    global $DB;

    foreach (['profilegroups', 'logs', 'seens'] as $t) {
        $DB->dropTable("glpi_plugin_sistemasinternos_$t", true);
    }
    ProfileRight::deleteProfileRights(Menu::RIGHTS);
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Settings::DEFAULTS));
    unset($_SESSION['glpimenu']);

    return true;
}
