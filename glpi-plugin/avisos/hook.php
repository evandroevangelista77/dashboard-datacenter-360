<?php

use GlpiPlugin\Avisos\Aviso;
use GlpiPlugin\Avisos\Canal;
use GlpiPlugin\Avisos\Teams;

/** Tabelas, canais do Teams (um por plugin), configuração e a ação automática da fila. */
function plugin_avisos_install(): bool
{
    global $DB;
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    $tables = [
        // um aviso por destinatário; is_shown = já apareceu como pop-up
        Aviso::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `users_id` int $sign NOT NULL DEFAULT 0,
            `source` varchar(30) NOT NULL DEFAULT '', `event` varchar(60) NOT NULL DEFAULT '', `level` varchar(10) NOT NULL DEFAULT 'info',
            `title` varchar(255) DEFAULT NULL, `message` text, `url` varchar(500) DEFAULT NULL,
            `itemtype` varchar(100) DEFAULT NULL, `items_id` int $sign NOT NULL DEFAULT 0,
            `is_shown` tinyint NOT NULL DEFAULT 0, `is_read` tinyint NOT NULL DEFAULT 0, `date_read` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `user_unread` (`users_id`, `is_read`), KEY `date_creation` (`date_creation`), KEY `item` (`itemtype`, `items_id`)",
        Canal::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `source` varchar(30) NOT NULL DEFAULT '', `name` varchar(255) DEFAULT NULL,
            `webhook_url` text, `is_active` tinyint NOT NULL DEFAULT 0,
            `date_last` timestamp NULL DEFAULT NULL, `last_status` varchar(20) DEFAULT NULL, `last_error` text, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `source` (`source`)",
        // mensagens para o Teams: enviadas na hora e, se falhar, reenviadas pela ação automática
        Teams::QUEUE => "`id` int $sign NOT NULL AUTO_INCREMENT, `source` varchar(30) NOT NULL DEFAULT '', `payload` longtext,
            `status` varchar(10) NOT NULL DEFAULT 'pending', `attempts` int NOT NULL DEFAULT 0, `last_error` text,
            `date_creation` timestamp NULL DEFAULT NULL, `date_sent` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `status` (`status`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    $now = date('Y-m-d H:i:s');
    foreach (Canal::SOURCES as $source => [$name]) {
        if (!countElementsInTable(Canal::getTable(), ['source' => $source])) {
            $DB->insert(Canal::getTable(), ['source' => $source, 'name' => $name, 'is_active' => 0, 'date_mod' => $now]);
        }
    }
    // 1.1.1: o canal "Financeiro (Reembolso)" passou a se chamar "Reembolso"
    $DB->update(Canal::getTable(), ['name' => Canal::SOURCES['reembolso'][0]], ['source' => 'reembolso', 'name' => 'Financeiro (Reembolso)']);

    $current = Config::getConfigurationValues('plugin:avisos');
    Config::setConfigurationValues('plugin:avisos', array_diff_key([
        'popup'          => '1',  // mostrar pop-up dos avisos novos
        'poll_seconds'   => '60', // de quanto em quanto tempo a tela procura avisos novos
        'retention_days' => '180',
    ], $current));

    CronTask::register(Teams::class, 'avisosteams', MINUTE_TIMESTAMP * 5, [
        'comment' => 'Avisos: reenvia ao Teams as mensagens que falharam e apaga avisos antigos',
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);
    return true;
}

function plugin_avisos_uninstall(): bool
{
    global $DB;
    foreach ([Aviso::getTable(), Canal::getTable(), Teams::QUEUE] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }
    Config::deleteConfigurationValues('plugin:avisos', ['popup', 'poll_seconds', 'retention_days']);
    CronTask::unregister('avisos');
    return true;
}
