<?php

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;
use GlpiPlugin\Sistemasinternos\Wazuh;

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
        // resumo operacional (book mensal) da Visão 360: cada gravação é uma linha, a mais recente vale
        'glpi_plugin_sistemasinternos_books' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `what` varchar(100) DEFAULT NULL,
            `data` longtext,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `date_creation` (`date_creation`)",
        // Wazuh: cada vulnerabilidade vista (quando apareceu e quando sumiu = corrigida), para o tempo de correção
        'glpi_plugin_sistemasinternos_wzvulns' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `vkey` char(40) NOT NULL,
            `agent_id` varchar(20) NOT NULL,
            `agent_name` varchar(255) DEFAULT NULL,
            `cve` varchar(64) DEFAULT NULL,
            `package` varchar(255) DEFAULT NULL,
            `version` varchar(128) DEFAULT NULL,
            `severity` varchar(16) NOT NULL DEFAULT '-',
            `score` decimal(3,1) DEFAULT NULL,
            `first_seen` datetime NOT NULL,
            `last_seen` datetime NOT NULL,
            `resolved_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `vkey` (`vkey`),
            KEY `agent_id` (`agent_id`),
            KEY `resolved_at` (`resolved_at`),
            KEY `last_seen` (`last_seen`)",
        // foto diária dos totais de vulnerabilidades (evolução)
        'glpi_plugin_sistemasinternos_wzsnaps' => "
            `day` date NOT NULL,
            `total` int $sign NOT NULL DEFAULT 0,
            `critical` int $sign NOT NULL DEFAULT 0,
            `high` int $sign NOT NULL DEFAULT 0,
            `medium` int $sign NOT NULL DEFAULT 0,
            `low` int $sign NOT NULL DEFAULT 0,
            `unrated` int $sign NOT NULL DEFAULT 0,
            `assets` int $sign NOT NULL DEFAULT 0,
            `date_mod` datetime DEFAULT NULL,
            PRIMARY KEY (`day`)",
        // chamados abertos a partir do Wazuh (chave estável: evita abrir o mesmo duas vezes)
        'glpi_plugin_sistemasinternos_wztickets' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `tkey` varchar(16) NOT NULL,
            `kind` varchar(10) NOT NULL,
            `agent_id` varchar(20) NOT NULL,
            `ref` varchar(255) DEFAULT NULL,
            `tickets_id` int $sign NOT NULL DEFAULT 0,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `date_creation` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tkey` (`tkey`),
            KEY `agent_id` (`agent_id`),
            KEY `tickets_id` (`tickets_id`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // 1.4.0: marca do último alerta de vulnerabilidade nova (por usuário)
    if (!$DB->fieldExists('glpi_plugin_sistemasinternos_seens', 'last_wzvuln_id')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_sistemasinternos_seens` ADD `last_wzvuln_id` int $sign NOT NULL DEFAULT 0");
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
    // 1.4.0: sincronização horária das vulnerabilidades do Wazuh
    CronTask::register(Wazuh::class, 'wazuhsync', HOUR_TIMESTAMP, ['comment' => 'Sincroniza as vulnerabilidades do Wazuh', 'mode' => CronTask::MODE_EXTERNAL, 'state' => CronTask::STATE_WAITING]);
    // 1.2.0: a Visão 360 roda dentro do GLPI, sem o servidor do SI 360 à parte
    Config::deleteConfigurationValues(Settings::CONTEXT, ['painel_url']);

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

    foreach (['profilegroups', 'logs', 'seens', 'books', 'wzvulns', 'wzsnaps', 'wztickets'] as $t) {
        $DB->dropTable("glpi_plugin_sistemasinternos_$t", true);
    }
    CronTask::unregister('sistemasinternos');
    ProfileRight::deleteProfileRights(Menu::RIGHTS);
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Settings::DEFAULTS));
    unset($_SESSION['glpimenu']);

    return true;
}
