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
            `date_begin` date DEFAULT NULL,
            `date_end` date DEFAULT NULL,
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
            `entities_id` int $sign NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`)",
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
    ], $current));

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

    return true;
}

function plugin_reembolso_uninstall(): bool
{
    global $DB;

    foreach (['reembolsos', 'despesas', 'tipodespesas', 'centrocustos', 'geocaches'] as $t) {
        $DB->dropTable("glpi_plugin_reembolso_$t", true);
    }
    $DB->delete('glpi_documents_items', ['itemtype' => Reembolso::class]);
    $DB->delete('glpi_logs', ['itemtype' => Reembolso::class]);
    ProfileRight::deleteProfileRights([Reembolso::$rightname]);
    \GlpiPlugin\Reembolso\Notifications::uninstall();
    Config::deleteConfigurationValues('plugin:reembolso', ['km_rate', 'route_enabled', 'route_country', 'require_receipt']);
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
    return [];
}
