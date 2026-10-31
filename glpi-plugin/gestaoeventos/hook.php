<?php

use GlpiPlugin\Gestaoeventos\Alcada;
use GlpiPlugin\Gestaoeventos\Alerta;
use GlpiPlugin\Gestaoeventos\Aprovacao;
use GlpiPlugin\Gestaoeventos\Cotacao;
use GlpiPlugin\Gestaoeventos\Documentos;
use GlpiPlugin\Gestaoeventos\Etapa;
use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Fase;
use GlpiPlugin\Gestaoeventos\Historico;
use GlpiPlugin\Gestaoeventos\Parametro;
use GlpiPlugin\Gestaoeventos\Regras;
use GlpiPlugin\Gestaoeventos\Registro;
use GlpiPlugin\Gestaoeventos\Viajante;

/** Tabelas, etapas padrão, alçadas, parâmetros de viagem, direitos e a ação automática de atrasos. */
function plugin_gestaoeventos_install(): bool
{
    global $DB;
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $int = "int $sign NOT NULL DEFAULT 0";
    $money = 'decimal(14,2) NOT NULL DEFAULT 0';
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
    $ev = 'plugin_gestaoeventos_eventos_id';

    $tables = [
        // o evento: briefing + estado do fluxo. status: 1 em andamento, 2 concluído, 3 cancelado
        Evento::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `code` varchar(30) DEFAULT NULL, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0,
            `event_type` varchar(20) NOT NULL DEFAULT 'evento', `status` tinyint NOT NULL DEFAULT 1, `is_draft` tinyint NOT NULL DEFAULT 1,
            `approval_status` varchar(15) NOT NULL DEFAULT 'rascunho', `project_status` varchar(15) NOT NULL DEFAULT 'nao_iniciado',
            `triage_status` varchar(10) NOT NULL DEFAULT 'pendente', `triage_comment` text, `users_id_triage` $int, `date_triage` datetime DEFAULT NULL,
            `current_phase` varchar(30) DEFAULT NULL, `returns` int NOT NULL DEFAULT 0,
            `users_id_requester` $int, `users_id_owner` $int, `users_id_gp` $int, `requesting_area` varchar(100) DEFAULT NULL,
            `objective` mediumtext, `scope` mediumtext, `audience` int NOT NULL DEFAULT 0, `partner` varchar(255) DEFAULT NULL, `commercial_priority` varchar(10) DEFAULT NULL, `target_audience` text,
            `date_start` date DEFAULT NULL, `date_finish` date DEFAULT NULL, `city` varchar(150) DEFAULT NULL, `venue` varchar(255) DEFAULT NULL, `address` varchar(255) DEFAULT NULL,
            `venue_defined` tinyint NOT NULL DEFAULT 0, `format` varchar(12) NOT NULL DEFAULT 'presencial',
            `need_structure` text, `need_coffee` text, `need_meals` text, `need_travel` text, `need_lodging` text, `need_transport` text, `need_gifts` text, `need_av` text, `need_comm` text, `need_suppliers` text,
            `budget_estimated` $money, `sponsorship_estimated` $money, `cc_number` varchar(100) DEFAULT NULL, `cc_description` varchar(255) DEFAULT NULL,
            `participation_url` varchar(500) DEFAULT NULL, `date_critical` date DEFAULT NULL,
            `budget_approved` $money, `budget_version` int NOT NULL DEFAULT 1, `exception_reason` text, `exception_status` varchar(10) NOT NULL DEFAULT 'none',
            `projects_id` $int, `sharepoint_url` varchar(500) DEFAULT NULL,
            `ready_gp_users` $int, `ready_gp_date` datetime DEFAULT NULL, `ready_mkt_users` $int, `ready_mkt_date` datetime DEFAULT NULL,
            `accepted_users` $int, `accepted_date` datetime DEFAULT NULL,
            `date_request` datetime DEFAULT NULL, `date_end` datetime DEFAULT NULL, `close_comment` text, `comment` text, `is_deleted` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `code` (`code`), KEY `entities_id` (`entities_id`), KEY `status` (`status`), KEY `current_phase` (`current_phase`), KEY `date_start` (`date_start`)",
        // configuração das etapas (nome, grupo, SLO, alerta, relógio)
        Etapa::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `code` varchar(30) NOT NULL, `position` int NOT NULL DEFAULT 0, `name` varchar(255) DEFAULT NULL,
            `area` varchar(100) DEFAULT NULL, `groups_id` $int, `applies` varchar(20) NOT NULL DEFAULT 'all', `slo_days` int NOT NULL DEFAULT 0, `warn_pct` int NOT NULL DEFAULT 80,
            `clock_from` varchar(12) NOT NULL DEFAULT 'activation', `description` text, `requirement` varchar(30) DEFAULT NULL, `is_active` tinyint NOT NULL DEFAULT 1, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `code` (`code`)",
        // etapas de cada evento; status: pending, active, done, skipped. late_notified: 0 nada, 1 alerta de prazo, 2 atrasada
        Fase::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `$ev` $int, `code` varchar(30) NOT NULL, `position` int NOT NULL DEFAULT 0,
            `status` varchar(10) NOT NULL DEFAULT 'pending', `groups_id` $int, `slo_days` int NOT NULL DEFAULT 0, `warn_pct` int NOT NULL DEFAULT 80,
            `date_start` datetime DEFAULT NULL, `date_clock` datetime DEFAULT NULL, `date_end` datetime DEFAULT NULL, `seconds` int NOT NULL DEFAULT 0, `returns` int NOT NULL DEFAULT 0,
            `users_id_done` $int, `tickets_id` $int, `late_notified` tinyint NOT NULL DEFAULT 0, `ready_notified` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), UNIQUE KEY `evento_code` (`$ev`, `code`), KEY `status` (`status`)",
        // linha do tempo / auditoria: início, conclusão, devolução, pulo, decisões, bloqueios, comentários
        Historico::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `$ev` $int, `code` varchar(30) DEFAULT NULL, `action` varchar(20) NOT NULL,
            `comment` text, `users_id` $int, `date` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `evento` (`$ev`)",
        // aprovações por alçada. status: waiting (aguarda a etapa anterior), pending, approved, rejected, postponed, canceled, superseded
        Aprovacao::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `$ev` $int, `batch` $int, `kind` varchar(15) NOT NULL, `registros_id` $int, `title` varchar(255) DEFAULT NULL,
            `amount` $money, `step` int NOT NULL DEFAULT 1, `label` varchar(100) DEFAULT NULL, `groups_id` $int, `users_id_approver` $int,
            `status` varchar(10) NOT NULL DEFAULT 'pending', `users_id_requested` $int, `users_id_decided` $int, `delegate_users_id` $int, `delegate_until` date DEFAULT NULL,
            `justification` text, `request_reason` text, `version` int NOT NULL DEFAULT 1, `date_requested` datetime DEFAULT NULL, `date_due` datetime DEFAULT NULL,
            `date_decision` datetime DEFAULT NULL, `overdue_notified` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `evento` (`$ev`), KEY `batch` (`batch`), KEY `status` (`status`), KEY `registro` (`registros_id`)",
        // alçadas (configuração): quem aprova o quê, por valor e tipo de evento
        Alcada::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(150) DEFAULT NULL, `kind` varchar(15) NOT NULL, `min_amount` $money, `max_amount` $money,
            `event_type` varchar(20) DEFAULT NULL, `step` int NOT NULL DEFAULT 1, `groups_id` $int, `users_id` $int, `is_active` tinyint NOT NULL DEFAULT 1, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `kind` (`kind`)",
        // registros das abas (marcos, riscos, compras, lançamentos, ocorrências, resultados…); o tipo define os campos usados
        Registro::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `$ev` $int, `kind` varchar(20) NOT NULL, `parent_id` $int, `code` varchar(100) DEFAULT NULL,
            `title` varchar(255) DEFAULT NULL, `detail` mediumtext, `category` varchar(80) DEFAULT NULL, `subcategory` varchar(80) DEFAULT NULL, `supplier` varchar(255) DEFAULT NULL,
            `status` varchar(20) DEFAULT NULL, `nature` varchar(10) DEFAULT NULL, `phase` varchar(30) DEFAULT NULL,
            `users_id_owner` $int, `users_id` $int, `users_id_approver` $int,
            `qty` decimal(12,2) NOT NULL DEFAULT 0, `unit` varchar(20) DEFAULT NULL, `target` decimal(14,2) NOT NULL DEFAULT 0, `result` decimal(14,2) NOT NULL DEFAULT 0,
            `amount_budget` $money, `amount_eac` $money, `amount_committed` $money, `amount_real` $money,
            `date_due` date DEFAULT NULL, `date_done` date DEFAULT NULL, `is_critical` tinyint NOT NULL DEFAULT 0, `confirmed` tinyint NOT NULL DEFAULT 0,
            `doc_ref` varchar(255) DEFAULT NULL, `cc` varchar(60) DEFAULT NULL, `approvals_batch` $int, `extra` mediumtext,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `evento_kind` (`$ev`, `kind`), KEY `parent` (`parent_id`), KEY `status` (`status`)",
        // viajantes e a simulação de custo (parâmetros usados ficam guardados na própria simulação)
        Viajante::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `$ev` $int, `name` varchar(255) DEFAULT NULL, `mode` varchar(12) NOT NULL DEFAULT 'uber',
            `origin_cep` varchar(9) DEFAULT NULL, `origin_label` varchar(255) DEFAULT NULL, `origin_lat` decimal(10,6) DEFAULT NULL, `origin_lon` decimal(10,6) DEFAULT NULL,
            `dest_cep` varchar(9) DEFAULT NULL, `dest_label` varchar(255) DEFAULT NULL, `dest_lat` decimal(10,6) DEFAULT NULL, `dest_lon` decimal(10,6) DEFAULT NULL,
            `geo_source` varchar(12) DEFAULT NULL, `km_oneway` decimal(10,2) NOT NULL DEFAULT 0, `km_manual` tinyint NOT NULL DEFAULT 0, `tolls_out` $money, `tolls_back` $money,
            `peak_out` tinyint NOT NULL DEFAULT 0, `peak_back` tinyint NOT NULL DEFAULT 0,
            `airport_origin` varchar(4) DEFAULT NULL, `airport_dest` varchar(4) DEFAULT NULL, `km_access_origin` decimal(10,2) NOT NULL DEFAULT 0, `km_access_dest` decimal(10,2) NOT NULL DEFAULT 0,
            `air_fare_out` $money, `air_fare_back` $money, `air_extra` text,
            `hotel_nights` int NOT NULL DEFAULT 0, `hotel_rate` $money, `meal_days` int NOT NULL DEFAULT 0, `meal_rate` $money, `parking_days` int NOT NULL DEFAULT 0, `parking_rate` $money,
            `uber_source` tinyint NOT NULL DEFAULT 0, `uber_policy` varchar(5) NOT NULL DEFAULT 'max',
            `params_version` int NOT NULL DEFAULT 0, `total` $money, `total_pending` tinyint NOT NULL DEFAULT 0, `calc` mediumtext, `calc_date` datetime DEFAULT NULL,
            `linked_registros_id` $int, `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `evento` (`$ev`)",
        // parâmetros de tarifa versionados (simulações antigas mantêm os que usaram)
        Parametro::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `version` int NOT NULL DEFAULT 1, `is_current` tinyint NOT NULL DEFAULT 1,
            `base_fare` $money, `per_km` $money, `peak_mult` decimal(6,2) NOT NULL DEFAULT 1, `route_factor` decimal(6,2) NOT NULL DEFAULT 1.3, `km_refund` $money,
            `hotel_night` $money, `meal_day` $money, `parking_day` $money, `comment` varchar(255) DEFAULT NULL, `users_id` $int, `date_creation` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `version` (`version`)",
        // cotações da API Uber: um registro por consulta, nunca sobrescrito
        Cotacao::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `$ev` $int, `viajantes_id` $int, `leg` varchar(20) NOT NULL, `product_id` varchar(64) DEFAULT NULL, `product_name` varchar(100) DEFAULT NULL,
            `low` decimal(12,2) DEFAULT NULL, `high` decimal(12,2) DEFAULT NULL, `currency` varchar(3) DEFAULT NULL, `distance_km` decimal(10,2) DEFAULT NULL, `duration_min` int DEFAULT NULL,
            `surge` decimal(5,2) DEFAULT NULL, `source` varchar(8) NOT NULL DEFAULT 'api', `status` varchar(15) NOT NULL DEFAULT 'ok', `error_code` varchar(30) DEFAULT NULL,
            `is_selected` tinyint NOT NULL DEFAULT 0, `users_id` $int, `quoted_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `viajante_leg` (`viajantes_id`, `leg`), KEY `evento` (`$ev`)",
        // avisos de contagem regressiva: regra padrão (evento 0) e por evento; o que cada pessoa dispensou ou adiou; o que já foi enviado ao sino/Teams
        Alerta::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `$ev` $int, `is_active` tinyint NOT NULL DEFAULT 1, `offsets` varchar(255) DEFAULT NULL, `roles` varchar(120) DEFAULT NULL, `extra_users` varchar(255) DEFAULT NULL,
            `sound` varchar(12) NOT NULL DEFAULT 'chime', `volume` int NOT NULL DEFAULT 70, `repeat_sec` int NOT NULL DEFAULT 0, `popup` tinyint NOT NULL DEFAULT 1, `dashboard` tinyint NOT NULL DEFAULT 1, `notify` tinyint NOT NULL DEFAULT 1,
            `date_mod` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `evento` (`$ev`)",
        Alerta::ACOES => "`id` int $sign NOT NULL AUTO_INCREMENT, `users_id` $int, `$ev` $int, `offset_sec` int NOT NULL DEFAULT 0, `action` varchar(8) NOT NULL, `until` datetime DEFAULT NULL, `date` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `quem_o_que` (`users_id`, `$ev`, `offset_sec`)",
        Alerta::ENVIOS => "`id` int $sign NOT NULL AUTO_INCREMENT, `$ev` $int, `offset_sec` int NOT NULL DEFAULT 0, `date` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `evento_momento` (`$ev`, `offset_sec`)",
        // etapa a que cada documento se refere (o documento é do GLPI, ligado ao evento)
        Documentos::TABLE => "`id` int $sign NOT NULL AUTO_INCREMENT, `documents_id` $int, `$ev` $int, `code` varchar(30) DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `documents_id` (`documents_id`), KEY `evento` (`$ev`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // etapas padrão: a ordem e a regra vêm do processo; nome, grupo, SLO e ativa continuam os configurados
    $pos = 0;
    foreach (Etapa::DEFAULTS as $code => [$name, $area, $applies, $slo, $description, $requirement, $clock]) {
        $pos += 10;
        if (!countElementsInTable(Etapa::getTable(), ['code' => $code])) {
            $DB->insert(Etapa::getTable(), [
                'code' => $code, 'position' => $pos, 'name' => $name, 'area' => $area, 'applies' => $applies, 'slo_days' => $slo, 'warn_pct' => 80, 'clock_from' => $clock,
                'description' => $description, 'requirement' => $requirement, 'is_active' => 1, 'date_mod' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $DB->update(Etapa::getTable(), ['position' => $pos, 'requirement' => $requirement], ['code' => $code]);
        }
    }

    // regra padrão dos avisos (evento 0)
    if (!countElementsInTable(Alerta::getTable(), ['plugin_gestaoeventos_eventos_id' => 0])) {
        $DB->insert(Alerta::getTable(), ['plugin_gestaoeventos_eventos_id' => 0] + array_diff_key(Alerta::DEFAULTS, ['plugin_gestaoeventos_eventos_id' => 1]) + ['date_mod' => date('Y-m-d H:i:s')]);
    }

    // alçadas sugeridas (sem grupo: quem tem o direito "Aprovar" decide, até o grupo ser escolhido em Configuração)
    if (!countElementsInTable(Alcada::getTable())) {
        foreach (Alcada::DEFAULTS as [$name, $kind, $min, $max, $step]) {
            $DB->insert(Alcada::getTable(), ['name' => $name, 'kind' => $kind, 'min_amount' => $min, 'max_amount' => $max, 'step' => $step, 'is_active' => 1, 'date_mod' => date('Y-m-d H:i:s')]);
        }
    }

    // parâmetros de tarifa da primeira versão (valores de referência do simulador legado: revise em Configuração)
    if (!countElementsInTable(Parametro::getTable())) {
        $DB->insert(Parametro::getTable(), [
            'version' => 1, 'is_current' => 1, 'base_fare' => 6.00, 'per_km' => 2.20, 'peak_mult' => 1.50, 'route_factor' => 1.30, 'km_refund' => 1.20,
            'hotel_night' => 450.00, 'meal_day' => 120.00, 'parking_day' => 60.00, 'comment' => 'Versão inicial: revise as tarifas com Financeiro/Marketing', 'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    $current = Config::getConfigurationValues('plugin:gestaoeventos');
    Config::setConfigurationValues('plugin:gestaoeventos', array_diff_key([
        'tickets' => '1', 'lead_days' => '60', 'lead_mode' => 'exception', 'tolerance_pct' => '5', 'approval_sla_days' => '3', 'allow_self_approval' => '0',
        'dicas_on' => '1', 'avisos_dash_on' => '1', 'avisos_dash_days' => '45', 'avisos_dash_max' => '8', 'avisos_start_time' => '08:00', 'avisos_poll' => '60', 'sharepoint_base' => '', 'categories' => Registro::DEFAULT_CATEGORIES,
        'uber_mode' => 'off', 'uber_authorized' => '0', 'uber_env' => 'production', 'uber_client_id' => '', 'uber_client_secret' => '', 'uber_timeout' => '10',
        'uber_policy' => 'max', 'uber_rate_per_min' => '20', 'uber_ttl' => '0', 'uber_fail_limit' => '5',
    ], $current));

    // direitos: ler e abrir eventos para todos da interface padrão; tudo (inclusive aprovar e financeiro) para quem administra a configuração
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Evento::$rightname])) {
        ProfileRight::addProfileRights([Evento::$rightname]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $rights = ($config & UPDATE) ? READ | CREATE | UPDATE | PURGE | Evento::APPROVE | Evento::FINANCE : READ | CREATE;
            $DB->update(ProfileRight::getTable(), ['rights' => $rights], ['profiles_id' => $row['id'], 'name' => Evento::$rightname]);
        }
    }
    \GlpiPlugin\Gestaoeventos\Acesso::seed();
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Evento::$rightname]);
        $_SESSION['glpiactiveprofile'][Evento::$rightname] = (int) ($rights[Evento::$rightname] ?? 0);
        \GlpiPlugin\Gestaoeventos\Acesso::sessionLoad();
    }
    unset($_SESSION['glpimenu']);

    CronTask::register(Regras::class, 'eventosatrasos', HOUR_TIMESTAMP, [
        'comment' => 'Gestão de Eventos: avisa etapas perto do SLO ou atrasadas, aprovações vencidas e marcos críticos',
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);
    return true;
}

function plugin_gestaoeventos_uninstall(): bool
{
    global $DB;
    foreach ([Evento::getTable(), Etapa::getTable(), Fase::getTable(), Historico::getTable(), Aprovacao::getTable(), Alcada::getTable(), Registro::getTable(),
        Viajante::getTable(), Parametro::getTable(), Cotacao::getTable(), Documentos::TABLE, Alerta::getTable(), Alerta::ACOES, Alerta::ENVIOS] as $table) {
        $DB->dropTable($table, true);
    }
    $DB->delete('glpi_documents_items', ['itemtype' => Evento::class]);
    $DB->delete('glpi_logs', ['itemtype' => Evento::class]);
    ProfileRight::deleteProfileRights(array_merge([Evento::$rightname], \GlpiPlugin\Gestaoeventos\Acesso::names()));
    Config::deleteConfigurationValues('plugin:gestaoeventos');
    CronTask::unregister('gestaoeventos');
    unset($_SESSION['glpimenu']);
    return true;
}
