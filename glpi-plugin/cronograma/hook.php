<?php

function plugin_cronograma_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // vínculo tarefa do MS Project (UID) ↔ tarefa do GLPI, para reimportar sem duplicar
    if (!$DB->tableExists('glpi_plugin_cronograma_tasks')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_cronograma_tasks` (
                `id` int $sign NOT NULL AUTO_INCREMENT,
                `projects_id` int $sign NOT NULL DEFAULT 0,
                `projecttasks_id` int $sign NOT NULL DEFAULT 0,
                `ms_uid` int NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_uid` (`projects_id`, `ms_uid`),
                KEY `projecttasks_id` (`projecttasks_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC"
        );
    }

    if (!$DB->tableExists('glpi_plugin_cronograma_imports')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_cronograma_imports` (
                `id` int $sign NOT NULL AUTO_INCREMENT,
                `projects_id` int $sign NOT NULL DEFAULT 0,
                `users_id` int $sign NOT NULL DEFAULT 0,
                `documents_id` int $sign NOT NULL DEFAULT 0,
                `filename` varchar(255) NOT NULL DEFAULT '',
                `ms_project_name` varchar(255) NOT NULL DEFAULT '',
                `created` int NOT NULL DEFAULT 0,
                `updated` int NOT NULL DEFAULT 0,
                `removed` int NOT NULL DEFAULT 0,
                `links` int NOT NULL DEFAULT 0,
                `team` int NOT NULL DEFAULT 0,
                `warnings` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `projects_id` (`projects_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC"
        );
    }

    // caminho do Java e do MPXJ (conversão do .mpp); ajustável em glpi_configs
    $current = Config::getConfigurationValues('plugin:cronograma');
    Config::setConfigurationValues('plugin:cronograma', array_diff_key([
        'java'      => '/usr/bin/java',
        'classpath' => '/opt/mpxj/mpxj.jar:/opt/mpxj/lib/*',
    ], $current));

    return true;
}

function plugin_cronograma_uninstall(): bool
{
    global $DB;

    // as tarefas importadas continuam no projeto; só os vínculos e o histórico saem
    $DB->dropTable('glpi_plugin_cronograma_tasks', true);
    $DB->dropTable('glpi_plugin_cronograma_imports', true);
    Config::deleteConfigurationValues('plugin:cronograma', ['java', 'classpath']);

    return true;
}
