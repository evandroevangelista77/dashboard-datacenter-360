<?php

use GlpiPlugin\Ciclo\Ciclo;
use GlpiPlugin\Ciclo\Etapa;
use GlpiPlugin\Ciclo\Evento;
use GlpiPlugin\Ciclo\Fase;
use GlpiPlugin\Ciclo\Item;
use GlpiPlugin\Ciclo\Regras;

/** Tabelas, fases padrão, direitos e a ação automática de atrasos. */
function plugin_ciclo_install(): bool
{
    global $DB;
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $int = "int $sign NOT NULL DEFAULT 0";
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    $tables = [
        // a venda: do aviso de fechamento ao recebimento; status: 1 em andamento, 2 concluído, 3 cancelado
        Ciclo::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0,
            `customer` varchar(255) DEFAULT NULL, `opportunity` varchar(100) DEFAULT NULL, `sale_type` varchar(20) NOT NULL DEFAULT 'hw_servico',
            `amount` decimal(14,2) NOT NULL DEFAULT 0, `date_closing` datetime DEFAULT NULL, `date_signed` date DEFAULT NULL,
            `users_id_commercial` $int, `users_id_otc` $int, `users_id_gp` $int,
            `cc_number` varchar(100) DEFAULT NULL, `cc_description` varchar(255) DEFAULT NULL,
            `date_transfer` date DEFAULT NULL, `cubage` varchar(255) DEFAULT NULL, `transport_mode` varchar(20) DEFAULT NULL, `transport_cost` decimal(14,2) NOT NULL DEFAULT 0,
            `date_delivery` date DEFAULT NULL, `projects_id` $int, `date_accept` date DEFAULT NULL,
            `status` tinyint NOT NULL DEFAULT 1, `current_phase` varchar(30) DEFAULT NULL, `returns` int NOT NULL DEFAULT 0,
            `date_end` datetime DEFAULT NULL, `close_comment` text, `comment` text, `is_deleted` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `entities_id` (`entities_id`), KEY `status` (`status`), KEY `current_phase` (`current_phase`), KEY `opportunity` (`opportunity`)",
        // configuração das fases
        Etapa::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `code` varchar(30) NOT NULL, `position` int NOT NULL DEFAULT 0, `name` varchar(255) DEFAULT NULL,
            `area` varchar(100) DEFAULT NULL, `groups_id` $int, `applies` varchar(20) NOT NULL DEFAULT 'all', `slo_days` int NOT NULL DEFAULT 0,
            `description` text, `requirement` varchar(30) DEFAULT NULL, `is_active` tinyint NOT NULL DEFAULT 1, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `code` (`code`)",
        // fases de cada Ciclo; status: pending, active, done, skipped
        Fase::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_ciclo_ciclos_id` $int, `code` varchar(30) NOT NULL, `position` int NOT NULL DEFAULT 0,
            `status` varchar(10) NOT NULL DEFAULT 'pending', `groups_id` $int, `slo_days` int NOT NULL DEFAULT 0,
            `date_start` datetime DEFAULT NULL, `date_end` datetime DEFAULT NULL, `seconds` int NOT NULL DEFAULT 0, `returns` int NOT NULL DEFAULT 0,
            `users_id_done` $int, `tickets_id` $int, `late_notified` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), UNIQUE KEY `ciclo_code` (`plugin_ciclo_ciclos_id`, `code`), KEY `status` (`status`)",
        // itens da venda: pedido do Power Automate, RM, pedido ao fornecedor, NF de entrada, NF de saída, parcela
        Item::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_ciclo_ciclos_id` $int, `kind` varchar(20) NOT NULL,
            `number` varchar(100) DEFAULT NULL, `description` varchar(255) DEFAULT NULL, `supplier` varchar(255) DEFAULT NULL, `category` varchar(10) DEFAULT NULL,
            `amount` decimal(14,2) NOT NULL DEFAULT 0, `date_expected` date DEFAULT NULL, `date_done` date DEFAULT NULL, `status` varchar(10) DEFAULT NULL,
            `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `ciclo_kind` (`plugin_ciclo_ciclos_id`, `kind`)",
        // linha do tempo: início, conclusão, devolução e pulo de fase, comentários
        Evento::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_ciclo_ciclos_id` $int, `code` varchar(30) DEFAULT NULL, `action` varchar(20) NOT NULL,
            `comment` text, `users_id` $int, `date` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `ciclo` (`plugin_ciclo_ciclos_id`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    $pos = 0;
    foreach (Etapa::DEFAULTS as $code => [$name, $area, $applies, $slo, $description, $requirement]) {
        $pos += 10;
        if (!countElementsInTable(Etapa::getTable(), ['code' => $code])) {
            $DB->insert(Etapa::getTable(), [
                'code' => $code, 'position' => $pos, 'name' => $name, 'area' => $area, 'applies' => $applies, 'slo_days' => $slo,
                'description' => $description, 'requirement' => $requirement, 'is_active' => 1, 'date_mod' => date('Y-m-d H:i:s'),
            ]);
        } else {
            // a ordem e a regra de cada fase vêm do processo; nome, grupo, SLO e ativa continuam os configurados
            $DB->update(Etapa::getTable(), ['position' => $pos, 'applies' => $applies, 'requirement' => $requirement], ['code' => $code]);
        }
    }

    $current = Config::getConfigurationValues('plugin:ciclo');
    Config::setConfigurationValues('plugin:ciclo', array_diff_key(['tickets' => '1'], $current));

    // direitos: ver para todos da interface padrão; abrir e mover fases para quem administra a configuração
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Ciclo::$rightname])) {
        ProfileRight::addProfileRights([Ciclo::$rightname]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? READ | CREATE | UPDATE | PURGE : READ], ['profiles_id' => $row['id'], 'name' => Ciclo::$rightname]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Ciclo::$rightname]);
        $_SESSION['glpiactiveprofile'][Ciclo::$rightname] = (int) ($rights[Ciclo::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    CronTask::register(Regras::class, 'cicloatrasos', HOUR_TIMESTAMP, [
        'comment' => 'Ciclo Financeiro: avisa as fases que passaram do SLO',
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);
    return true;
}

function plugin_ciclo_uninstall(): bool
{
    global $DB;
    foreach ([Ciclo::getTable(), Etapa::getTable(), Fase::getTable(), Item::getTable(), Evento::getTable()] as $table) {
        $DB->dropTable($table, true);
    }
    $DB->delete('glpi_documents_items', ['itemtype' => Ciclo::class]);
    $DB->delete('glpi_logs', ['itemtype' => Ciclo::class]);
    ProfileRight::deleteProfileRights([Ciclo::$rightname]);
    Config::deleteConfigurationValues('plugin:ciclo', ['tickets']);
    CronTask::unregister('ciclo');
    unset($_SESSION['glpimenu']);
    return true;
}
