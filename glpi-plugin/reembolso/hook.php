<?php

use GlpiPlugin\Reembolso\Centrocusto;
use GlpiPlugin\Reembolso\Reembolso;
use GlpiPlugin\Reembolso\Tipodespesa;

function plugin_reembolso_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    $tables = [
        // solicitação de reembolso (cabeçalho); status: 1 rascunho, 2 enviado, 3 aprovado, 4 recusado, 5 pago
        'glpi_plugin_reembolso_reembolsos' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `entities_id` int $sign NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `users_id_recipient` int $sign NOT NULL DEFAULT 0,
            `plugin_reembolso_centrocustos_id` int $sign NOT NULL DEFAULT 0,
            `locations_id` int $sign NOT NULL DEFAULT 0,
            `address` varchar(255) DEFAULT NULL,
            `tickets_id` int $sign NOT NULL DEFAULT 0,
            `plugin_reembolso_lotes_id` int $sign NOT NULL DEFAULT 0,
            `date_begin` date DEFAULT NULL,
            `time_begin` time DEFAULT NULL,
            `date_end` date DEFAULT NULL,
            `time_end` time DEFAULT NULL,
            `minutes_business` int NOT NULL DEFAULT 0,
            `minutes_outside` int NOT NULL DEFAULT 0,
            `minutes_night` int NOT NULL DEFAULT 0,
            `status` tinyint NOT NULL DEFAULT 1,
            `total` decimal(12,2) NOT NULL DEFAULT 0,
            `comment` text,
            `date_submit` timestamp NULL DEFAULT NULL,
            `users_id_approver` int $sign NOT NULL DEFAULT 0,
            `date_approval` timestamp NULL DEFAULT NULL,
            `approval_comment` text,
            `date_payment` date DEFAULT NULL,
            `payment_reference` varchar(255) DEFAULT NULL,
            `is_deleted` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `tickets_id` (`tickets_id`),
            KEY `plugin_reembolso_centrocustos_id` (`plugin_reembolso_centrocustos_id`),
            KEY `is_deleted` (`is_deleted`)",
        // despesas da solicitação
        'glpi_plugin_reembolso_despesas' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `plugin_reembolso_reembolsos_id` int $sign NOT NULL DEFAULT 0,
            `plugin_reembolso_tipodespesas_id` int $sign NOT NULL DEFAULT 0,
            `date` date DEFAULT NULL,
            `time` time DEFAULT NULL,
            `is_night` tinyint NOT NULL DEFAULT 0,
            `description` varchar(255) DEFAULT NULL,
            `origin` varchar(255) DEFAULT NULL,
            `destination` varchar(255) DEFAULT NULL,
            `round_trip` tinyint NOT NULL DEFAULT 0,
            `km_start` decimal(10,1) DEFAULT NULL,
            `km_end` decimal(10,1) DEFAULT NULL,
            `distance` decimal(10,1) NOT NULL DEFAULT 0,
            `km_rate` decimal(8,4) NOT NULL DEFAULT 0,
            `amount` decimal(12,2) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_reembolso_reembolsos_id` (`plugin_reembolso_reembolsos_id`),
            KEY `plugin_reembolso_tipodespesas_id` (`plugin_reembolso_tipodespesas_id`),
            KEY `date` (`date`)",
        'glpi_plugin_reembolso_tipodespesas' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `comment` text,
            `is_mileage` tinyint NOT NULL DEFAULT 0,
            `max_amount` decimal(12,2) NOT NULL DEFAULT 0,
            `requires_receipt` tinyint NOT NULL DEFAULT 1,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`)",
        'glpi_plugin_reembolso_centrocustos' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `code` varchar(50) DEFAULT NULL,
            `users_id_manager` int $sign NOT NULL DEFAULT 0,
            `groups_id_manager` int $sign NOT NULL DEFAULT 0,
            `entities_id` int $sign NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`)",
        // lote de reembolsos de um técnico num período: PDF, assinatura do técnico e aprovações
        // status: 1 técnico, 2 gestor da área, 3 gestor do centro de custo, 4 OTC, 5 financeiro, 6 pago, 7 devolvido, 8 cancelado
        'glpi_plugin_reembolso_lotes' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `entities_id` int $sign NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `users_id_area_manager` int $sign NOT NULL DEFAULT 0,
            `groups_id_area_manager` int $sign NOT NULL DEFAULT 0,
            `period` varchar(10) DEFAULT NULL,
            `date_begin` date DEFAULT NULL,
            `date_end` date DEFAULT NULL,
            `status` tinyint NOT NULL DEFAULT 1,
            `total` decimal(12,2) NOT NULL DEFAULT 0,
            `night_total` decimal(12,2) NOT NULL DEFAULT 0,
            `night_count` int NOT NULL DEFAULT 0,
            `expense_count` int NOT NULL DEFAULT 0,
            `documents_id_pdf` int $sign NOT NULL DEFAULT 0,
            `documents_id_signed` int $sign NOT NULL DEFAULT 0,
            `documents_id_receipt` int $sign NOT NULL DEFAULT 0,
            `sign_uuid` varchar(64) DEFAULT NULL,
            `sign_file_id` int NOT NULL DEFAULT 0,
            `date_signed` timestamp NULL DEFAULT NULL,
            `last_error` text,
            `date_payment` date DEFAULT NULL,
            `payment_reference` varchar(255) DEFAULT NULL,
            `comment` text,
            `is_deleted` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `users_id_area_manager` (`users_id_area_manager`)",
        // decisões de cada etapa do lote (step: tecnico, gestor, centro, otc, financeiro)
        'glpi_plugin_reembolso_lotes_etapas' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `plugin_reembolso_lotes_id` int $sign NOT NULL DEFAULT 0,
            `step` varchar(20) NOT NULL,
            `plugin_reembolso_centrocustos_id` int $sign NOT NULL DEFAULT 0,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `decision` varchar(20) NOT NULL,
            `comment` text,
            `round` int NOT NULL DEFAULT 1,
            `date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `lote` (`plugin_reembolso_lotes_id`),
            KEY `users_id` (`users_id`)",
        // adiantamento: pedido antes do atendimento, mesma esteira do lote e prestação de contas
        // status: 9 rascunho, 1 técnico, 2 gestor da área, 3 centro de custo, 4 OTC, 5 financeiro, 6 pago (prestar contas), 10 prestação em conferência, 11 encerrado, 7 devolvido, 8 cancelado
        'glpi_plugin_reembolso_adiantamentos' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `entities_id` int $sign NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `users_id_recipient` int $sign NOT NULL DEFAULT 0,
            `users_id_area_manager` int $sign NOT NULL DEFAULT 0,
            `groups_id_area_manager` int $sign NOT NULL DEFAULT 0,
            `plugin_reembolso_centrocustos_id` int $sign NOT NULL DEFAULT 0,
            `tickets_id` int $sign NOT NULL DEFAULT 0,
            `address` varchar(255) DEFAULT NULL,
            `period` varchar(10) DEFAULT NULL,
            `date_begin` date DEFAULT NULL,
            `time_begin` time DEFAULT NULL,
            `date_end` date DEFAULT NULL,
            `time_end` time DEFAULT NULL,
            `minutes_business` int NOT NULL DEFAULT 0,
            `minutes_outside` int NOT NULL DEFAULT 0,
            `minutes_night` int NOT NULL DEFAULT 0,
            `date_needed` date DEFAULT NULL,
            `status` tinyint NOT NULL DEFAULT 9,
            `total` decimal(12,2) NOT NULL DEFAULT 0,
            `night_total` decimal(12,2) NOT NULL DEFAULT 0,
            `night_count` int NOT NULL DEFAULT 0,
            `expense_count` int NOT NULL DEFAULT 0,
            `documents_id_pdf` int $sign NOT NULL DEFAULT 0,
            `documents_id_signed` int $sign NOT NULL DEFAULT 0,
            `documents_id_receipt` int $sign NOT NULL DEFAULT 0,
            `sign_uuid` varchar(64) DEFAULT NULL,
            `sign_file_id` int NOT NULL DEFAULT 0,
            `date_signed` timestamp NULL DEFAULT NULL,
            `last_error` text,
            `date_submit` timestamp NULL DEFAULT NULL,
            `date_payment` date DEFAULT NULL,
            `payment_reference` varchar(255) DEFAULT NULL,
            `spent_total` decimal(12,2) NOT NULL DEFAULT 0,
            `balance` decimal(12,2) NOT NULL DEFAULT 0,
            `date_account` timestamp NULL DEFAULT NULL,
            `account_comment` text,
            `settle_reference` varchar(255) DEFAULT NULL,
            `date_closed` timestamp NULL DEFAULT NULL,
            `comment` text,
            `is_deleted` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `tickets_id` (`tickets_id`),
            KEY `plugin_reembolso_centrocustos_id` (`plugin_reembolso_centrocustos_id`)",
        'glpi_plugin_reembolso_adiantamentos_itens' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `plugin_reembolso_adiantamentos_id` int $sign NOT NULL DEFAULT 0,
            `plugin_reembolso_tipodespesas_id` int $sign NOT NULL DEFAULT 0,
            `description` varchar(255) DEFAULT NULL,
            `quantity` decimal(10,2) NOT NULL DEFAULT 1,
            `unit_value` decimal(12,2) NOT NULL DEFAULT 0,
            `amount` decimal(12,2) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `adiantamento` (`plugin_reembolso_adiantamentos_id`)",
        'glpi_plugin_reembolso_adiantamentos_etapas' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `plugin_reembolso_adiantamentos_id` int $sign NOT NULL DEFAULT 0,
            `step` varchar(20) NOT NULL,
            `plugin_reembolso_centrocustos_id` int $sign NOT NULL DEFAULT 0,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `decision` varchar(20) NOT NULL,
            `comment` text,
            `round` int NOT NULL DEFAULT 1,
            `date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `adiantamento` (`plugin_reembolso_adiantamentos_id`),
            KEY `users_id` (`users_id`)",
        // periodicidade do lote e gestor da área de cada técnico (sem linha = padrão da configuração)
        'glpi_plugin_reembolso_tecnicos' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `period` varchar(10) NOT NULL DEFAULT 'mensal',
            `users_id_manager` int $sign NOT NULL DEFAULT 0,
            `groups_id_manager` int $sign NOT NULL DEFAULT 0,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`)",
        // endereços já localizados no mapa (evita consultar o OpenStreetMap de novo)
        'glpi_plugin_reembolso_geocaches' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `query` varchar(255) NOT NULL,
            `lat` decimal(10,7) NOT NULL,
            `lon` decimal(10,7) NOT NULL,
            `label` varchar(255) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `query` (`query`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }
    // 1.2.0: horário e adicional noturno nas despesas, lote, gestor do centro de custo
    $migrations = [
        'glpi_plugin_reembolso_despesas'     => ['time' => 'time DEFAULT NULL AFTER `date`', 'is_night' => 'tinyint NOT NULL DEFAULT 0 AFTER `time`'],
        'glpi_plugin_reembolso_reembolsos'   => ['plugin_reembolso_lotes_id' => "int $sign NOT NULL DEFAULT 0 AFTER `tickets_id`",
            'time_begin' => 'time DEFAULT NULL AFTER `date_begin`', 'time_end' => 'time DEFAULT NULL AFTER `date_end`',
            'minutes_business' => 'int NOT NULL DEFAULT 0 AFTER `time_end`', 'minutes_outside' => 'int NOT NULL DEFAULT 0 AFTER `minutes_business`', 'minutes_night' => 'int NOT NULL DEFAULT 0 AFTER `minutes_outside`'],
        'glpi_plugin_reembolso_centrocustos' => ['users_id_manager' => "int $sign NOT NULL DEFAULT 0 AFTER `code`", 'groups_id_manager' => "int $sign NOT NULL DEFAULT 0 AFTER `users_id_manager`"],
        'glpi_plugin_reembolso_lotes'        => ['groups_id_area_manager' => "int $sign NOT NULL DEFAULT 0 AFTER `users_id_area_manager`"],
        'glpi_plugin_reembolso_tecnicos'     => ['groups_id_manager' => "int $sign NOT NULL DEFAULT 0 AFTER `users_id_manager`"],
    ];
    foreach ($migrations as $table => $fields) {
        foreach ($fields as $field => $type) {
            if (!$DB->fieldExists($table, $field)) {
                $DB->doQuery("ALTER TABLE `$table` ADD `$field` $type");
            }
        }
    }

    $now = date('Y-m-d H:i:s');
    if (!countElementsInTable(Tipodespesa::getTable())) {
        foreach (Tipodespesa::DEFAULTS as [$name, $km, $max, $receipt]) {
            $DB->insert(Tipodespesa::getTable(), [
                'name' => $name, 'is_mileage' => $km, 'max_amount' => $max, 'requires_receipt' => $receipt,
                'date_creation' => $now, 'date_mod' => $now,
            ]);
        }
    }
    if (!countElementsInTable(Centrocusto::getTable())) {
        foreach (Centrocusto::DEFAULTS as [$code, $name]) {
            $DB->insert(Centrocusto::getTable(), [
                'name' => $name, 'code' => $code, 'entities_id' => 0, 'is_recursive' => 1,
                'date_creation' => $now, 'date_mod' => $now,
            ]);
        }
    }

    // valor por KM e cálculo da rota pelo OpenStreetMap (ajustáveis em Configurar → Plugins)
    $current = Config::getConfigurationValues('plugin:reembolso');
    Config::setConfigurationValues('plugin:reembolso', array_diff_key([
        'km_rate'         => '1.20',
        'route_enabled'   => '1',
        'route_country'   => 'br',
        'require_receipt' => '1',
        // adicional noturno (horário da despesa) e lotes
        'night_start'     => '22:00',
        'night_end'       => '05:00',
        'business_start'  => '08:00',
        'business_end'    => '18:00',
        'lote_period'     => 'mensal',
        'tech_groups_id'  => '0',
        'manager_users_id' => '0',
        'manager_groups_id' => '0',
        'otc_users_id'    => '0',
        'otc_groups_id'   => '0',
        'finance_users_id' => '0',
        'finance_groups_id' => '0',
        'advance_account_days' => '5',
    ], $current));

    // horas das solicitações que já têm horário de início e fim (1.2.0)
    foreach ($DB->request(['FROM' => Reembolso::getTable(), 'WHERE' => ['NOT' => ['time_begin' => null, 'time_end' => null], 'minutes_business' => 0, 'minutes_outside' => 0]]) as $r) {
        $h = \GlpiPlugin\Reembolso\Money::hours($r['date_begin'], $r['time_begin'], $r['date_end'], $r['time_end']);
        if ($h) {
            $DB->update(Reembolso::getTable(), ['minutes_business' => $h['business'], 'minutes_outside' => $h['outside'], 'minutes_night' => $h['night']], ['id' => $r['id']]);
        }
    }

    // colunas de horas na lista de reembolsos (padrão para todos)
    foreach ([20, 21, 22] as $num) {
        if (!countElementsInTable('glpi_displaypreferences', ['itemtype' => Reembolso::class, 'num' => $num, 'users_id' => 0])) {
            $rank = (int) ($DB->request(['SELECT' => ['MAX' => 'rank AS r'], 'FROM' => 'glpi_displaypreferences', 'WHERE' => ['itemtype' => Reembolso::class, 'users_id' => 0]])->current()['r'] ?? 0);
            $DB->insert('glpi_displaypreferences', ['itemtype' => Reembolso::class, 'num' => $num, 'rank' => $rank + 1, 'users_id' => 0]);
        }
    }

    // colunas padrão da lista de adiantamentos
    if (!countElementsInTable('glpi_displaypreferences', ['itemtype' => \GlpiPlugin\Reembolso\Adiantamento::class, 'users_id' => 0])) {
        foreach ([3, 4, 9, 5, 8, 7, 12, 13, 15] as $rank => $num) {
            $DB->insert('glpi_displaypreferences', ['itemtype' => \GlpiPlugin\Reembolso\Adiantamento::class, 'num' => $num, 'rank' => $rank + 1, 'users_id' => 0]);
        }
    }

    // fecha os lotes no fim de cada período e acompanha as assinaturas no LibreSign
    CronTask::register(\GlpiPlugin\Reembolso\Lote::class, 'reembolsolotes', HOUR_TIMESTAMP, [
        'comment' => 'Reembolso: gera os lotes dos técnicos no fim do período (diário, semanal, quinzenal ou mensal)',
        'mode' => CronTask::MODE_INTERNAL, 'state' => CronTask::STATE_WAITING,
    ]);
    CronTask::register(\GlpiPlugin\Reembolso\Lote::class, 'reembolsoassinaturas', 10 * MINUTE_TIMESTAMP, [
        'comment' => 'Reembolso: confere no LibreSign se o técnico já assinou o lote',
        'mode' => CronTask::MODE_INTERNAL, 'state' => CronTask::STATE_WAITING,
    ]);

    // direitos: técnicos lançam os próprios; quem administra a configuração vê tudo e aprova
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Reembolso::$rightname])) {
        ProfileRight::addProfileRights([Reembolso::$rightname]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $rights = ($config & UPDATE)
                ? READ | CREATE | UPDATE | PURGE | Reembolso::READALL | Reembolso::APPROVE
                : READ | CREATE | UPDATE;
            $DB->update(ProfileRight::getTable(), ['rights' => $rights], ['profiles_id' => $row['id'], 'name' => Reembolso::$rightname]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Reembolso::$rightname]);
        $_SESSION['glpiactiveprofile'][Reembolso::$rightname] = (int) ($rights[Reembolso::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    // e-mails do fluxo (enviado, aprovado, recusado e pago), em Configurar → Notificações
    \GlpiPlugin\Reembolso\Notifications::install();
    \GlpiPlugin\Reembolso\Notifications::upgradeLoteTemplates();

    return true;
}

function plugin_reembolso_uninstall(): bool
{
    global $DB;

    foreach (['reembolsos', 'despesas', 'tipodespesas', 'centrocustos', 'geocaches', 'lotes', 'lotes_etapas', 'tecnicos', 'adiantamentos', 'adiantamentos_itens', 'adiantamentos_etapas'] as $t) {
        $DB->dropTable("glpi_plugin_reembolso_$t", true);
    }
    $DB->delete('glpi_documents_items', ['itemtype' => [Reembolso::class, \GlpiPlugin\Reembolso\Lote::class, \GlpiPlugin\Reembolso\Adiantamento::class]]);
    CronTask::unregister('reembolso');
    $DB->delete('glpi_logs', ['itemtype' => Reembolso::class]);
    ProfileRight::deleteProfileRights([Reembolso::$rightname]);
    \GlpiPlugin\Reembolso\Notifications::uninstall();
    Config::deleteConfigurationValues('plugin:reembolso', ['km_rate', 'route_enabled', 'route_country', 'require_receipt', 'night_start', 'night_end', 'lote_period', 'tech_groups_id', 'manager_users_id', 'manager_groups_id', 'otc_users_id', 'otc_groups_id', 'finance_users_id', 'finance_groups_id', 'business_start', 'business_end', 'advance_account_days']);
    unset($_SESSION['glpimenu']);

    return true;
}

/** Listas suspensas do plugin em Configurar → Listas suspensas. */
function plugin_reembolso_getDropdown(): array
{
    return [
        Tipodespesa::class => Tipodespesa::getTypeName(2),
        Centrocusto::class => Centrocusto::getTypeName(2),
    ];
}

/** Na busca, quem não tem "ver todos" vê só os próprios reembolsos. */
function plugin_reembolso_addDefaultWhere($itemtype)
{
    if ($itemtype === Reembolso::class && !Session::haveRight(Reembolso::$rightname, Reembolso::READALL)) {
        $uid = (int) Session::getLoginUserID();
        return ['OR' => [
            'glpi_plugin_reembolso_reembolsos.users_id'           => $uid,
            'glpi_plugin_reembolso_reembolsos.users_id_recipient' => $uid,
        ]];
    }
    // lotes: o técnico vê os seus; quem aprova vê os que passaram (ou estão) com ele
    if ($itemtype === \GlpiPlugin\Reembolso\Lote::class && !Session::haveRight(Reembolso::$rightname, Reembolso::READALL)) {
        $uid = (int) Session::getLoginUserID();
        return ['OR' => [
            'glpi_plugin_reembolso_lotes.users_id'              => $uid,
            'glpi_plugin_reembolso_lotes.users_id_area_manager' => $uid,
            'glpi_plugin_reembolso_lotes.id'                    => \GlpiPlugin\Reembolso\Lote::idsFor($uid) ?: [0],
        ]];
    }
    // adiantamentos: o técnico (ou quem lançou) vê os seus; quem aprova vê os que passaram (ou estão) com ele
    if ($itemtype === \GlpiPlugin\Reembolso\Adiantamento::class && !Session::haveRight(Reembolso::$rightname, Reembolso::READALL)) {
        $uid = (int) Session::getLoginUserID();
        return ['OR' => [
            'glpi_plugin_reembolso_adiantamentos.users_id'              => $uid,
            'glpi_plugin_reembolso_adiantamentos.users_id_recipient'    => $uid,
            'glpi_plugin_reembolso_adiantamentos.users_id_area_manager' => $uid,
            'glpi_plugin_reembolso_adiantamentos.id'                    => \GlpiPlugin\Reembolso\Adiantamento::idsFor($uid) ?: [0],
        ]];
    }
    return [];
}
