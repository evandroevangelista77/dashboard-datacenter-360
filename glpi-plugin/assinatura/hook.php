<?php

use GlpiPlugin\Assinatura\Settings;
use GlpiPlugin\Assinatura\SignRequest;

function plugin_assinatura_install(): bool
{
    global $DB;

    $table = SignRequest::getTable();
    if (!$DB->tableExists($table)) {
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $DB->doQuery(
            "CREATE TABLE `$table` (
                `id` int $sign NOT NULL AUTO_INCREMENT,
                `tickets_id` int $sign NOT NULL DEFAULT 0,
                `users_id` int $sign NOT NULL DEFAULT 0 COMMENT 'assinante no GLPI',
                `users_id_requester` int $sign NOT NULL DEFAULT 0 COMMENT 'quem solicitou',
                `signer_name` varchar(255) NOT NULL DEFAULT '',
                `signer_email` varchar(255) NOT NULL DEFAULT '',
                `source` varchar(20) NOT NULL DEFAULT 'ticket_pdf' COMMENT 'ticket_pdf | document',
                `documents_id_source` int $sign NOT NULL DEFAULT 0,
                `documents_id_signed` int $sign NOT NULL DEFAULT 0,
                `file_uuid` varchar(64) NOT NULL DEFAULT '',
                `file_id` int $sign NOT NULL DEFAULT 0 COMMENT 'id do arquivo no LibreSign',
                `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending | signed | canceled | error',
                `message` text,
                `last_error` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_signed` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `tickets_id` (`tickets_id`),
                KEY `status` (`status`),
                KEY `file_uuid` (`file_uuid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC"
        );
    }

    // valores padrão (o que já existir é mantido)
    $current = Config::getConfigurationValues(Settings::CONTEXT);
    $defaults = [
        'nextcloud_url'   => '',
        'username'        => '',
        'app_password'    => '',
        'default_message' => 'Por favor, assine o documento referente a este chamado.',
        'accept_validation' => '1',
    ];
    Config::setConfigurationValues(Settings::CONTEXT, array_diff_key($defaults, $current));

    CronTask::register(SignRequest::class, 'syncsignatures', 300, [
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Sincroniza o status das assinaturas pendentes no LibreSign',
    ]);

    return true;
}

function plugin_assinatura_uninstall(): bool
{
    global $DB;

    $DB->dropTable(SignRequest::getTable(), true);
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Config::getConfigurationValues(Settings::CONTEXT)));
    CronTask::unregister('assinatura');

    return true;
}
