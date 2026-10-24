<?php

use GlpiPlugin\Rh\Admissao;
use GlpiPlugin\Rh\Candidato;
use GlpiPlugin\Rh\Candidatura;
use GlpiPlugin\Rh\Cargo;
use GlpiPlugin\Rh\Departamento;
use GlpiPlugin\Rh\Feria;
use GlpiPlugin\Rh\Notifications;
use GlpiPlugin\Rh\Vaga;

function plugin_rh_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
    $int       = "int $sign NOT NULL DEFAULT 0";
    $common    = "`entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL";

    $tables = [
        Departamento::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `users_id` $int, `comment` text, $common,
            PRIMARY KEY (`id`), KEY `name` (`name`), KEY `entities_id` (`entities_id`)",
        Cargo::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `cbo` varchar(20) DEFAULT NULL, `job_description` longtext, `comment` text, $common,
            PRIMARY KEY (`id`), KEY `name` (`name`), KEY `entities_id` (`entities_id`)",
        // requisição de vaga; status: 1 rascunho, 2 aguardando gestor, 3 aguardando diretoria, 4 aberta, 5 preenchida, 6 cancelada, 7 reprovada
        Vaga::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL,
            `plugin_rh_cargos_id` $int, `plugin_rh_departamentos_id` $int, `positions` int NOT NULL DEFAULT 1,
            `contract` varchar(20) NOT NULL DEFAULT 'clt', `workmode` varchar(20) NOT NULL DEFAULT 'presencial', `locations_id` $int,
            `salary_min` decimal(12,2) NOT NULL DEFAULT 0, `salary_max` decimal(12,2) NOT NULL DEFAULT 0,
            `reason` varchar(20) NOT NULL DEFAULT 'aumento', `replaced_name` varchar(255) DEFAULT NULL,
            `users_id_requester` $int, `users_id_manager` $int, `users_id_recruiter` $int,
            `description` text, `requirements` text, `benefits` text, `deadline` date DEFAULT NULL, `priority` tinyint NOT NULL DEFAULT 2,
            `status` tinyint NOT NULL DEFAULT 1, `date_submit` timestamp NULL DEFAULT NULL, `date_open` timestamp NULL DEFAULT NULL, `date_close` timestamp NULL DEFAULT NULL,
            `close_comment` text, `is_deleted` tinyint NOT NULL DEFAULT 0, $common,
            PRIMARY KEY (`id`), KEY `status` (`status`), KEY `entities_id` (`entities_id`), KEY `users_id_manager` (`users_id_manager`),
            KEY `plugin_rh_departamentos_id` (`plugin_rh_departamentos_id`), KEY `is_deleted` (`is_deleted`)",
        Candidato::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `email` varchar(255) DEFAULT NULL,
            `phone` varchar(50) DEFAULT NULL, `linkedin` varchar(255) DEFAULT NULL, `city` varchar(255) DEFAULT NULL,
            `salary_expectation` decimal(12,2) NOT NULL DEFAULT 0, `source` varchar(30) NOT NULL DEFAULT 'outros', `plugin_rh_cargos_id` $int, `picture` varchar(255) DEFAULT NULL, `comment` text,
            `is_deleted` tinyint NOT NULL DEFAULT 0, $common,
            PRIMARY KEY (`id`), KEY `name` (`name`), KEY `email` (`email`), KEY `entities_id` (`entities_id`), KEY `plugin_rh_cargos_id` (`plugin_rh_cargos_id`)",
        // candidato numa vaga; status: active, hired, rejected, withdrew
        Candidatura::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_rh_vagas_id` $int, `plugin_rh_candidatos_id` $int,
            `stage` varchar(20) NOT NULL DEFAULT 'triagem', `status` varchar(10) NOT NULL DEFAULT 'active', `rank` int NOT NULL DEFAULT 0,
            `rating` tinyint NOT NULL DEFAULT 0, `notes` text, `interview_date` timestamp NULL DEFAULT NULL,
            `offer_salary` decimal(12,2) NOT NULL DEFAULT 0, `start_date` date DEFAULT NULL,
            `date_stage` timestamp NULL DEFAULT NULL, `date_hired` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `vaga_candidato` (`plugin_rh_vagas_id`, `plugin_rh_candidatos_id`), KEY `plugin_rh_candidatos_id` (`plugin_rh_candidatos_id`), KEY `stage` (`stage`)",
        // admissão (onboarding); status: 1 em andamento, 2 concluída, 3 cancelada
        Admissao::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `plugin_rh_candidaturas_id` $int, `plugin_rh_vagas_id` $int,
            `start_date` date DEFAULT NULL, `users_id_manager` $int, `status` tinyint NOT NULL DEFAULT 1, `tickets_id` $int, `comment` text,
            `is_deleted` tinyint NOT NULL DEFAULT 0, $common,
            PRIMARY KEY (`id`), KEY `status` (`status`), KEY `plugin_rh_vagas_id` (`plugin_rh_vagas_id`), KEY `entities_id` (`entities_id`)",
        // férias; status: 2 aguardando gestor, 3 aprovada, 4 reprovada, 5 cancelada
        Feria::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `users_id` $int, `users_id_manager` $int,
            `date_begin` date DEFAULT NULL, `date_end` date DEFAULT NULL, `days` int NOT NULL DEFAULT 0, `sell_days` int NOT NULL DEFAULT 0,
            `comment` text, `status` tinyint NOT NULL DEFAULT 2, `users_id_approver` $int, `date_approval` timestamp NULL DEFAULT NULL, `approval_comment` text,
            `is_deleted` tinyint NOT NULL DEFAULT 0, $common,
            PRIMARY KEY (`id`), KEY `users_id` (`users_id`), KEY `users_id_manager` (`users_id_manager`), KEY `status` (`status`), KEY `date_begin` (`date_begin`)",
        // decisões de aprovação (vaga e férias), para a linha do tempo
        'glpi_plugin_rh_aprovacoes' => "`id` int $sign NOT NULL AUTO_INCREMENT, `itemtype` varchar(100) NOT NULL, `items_id` $int,
            `step` varchar(20) NOT NULL, `decision` varchar(10) NOT NULL, `users_id` $int, `comment` text, `date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `item` (`itemtype`, `items_id`)",
        // mudanças de etapa no funil (tempo por etapa e linha do tempo do candidato)
        'glpi_plugin_rh_historicos' => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_rh_candidaturas_id` $int, `from_stage` varchar(20) DEFAULT NULL,
            `to_stage` varchar(20) NOT NULL, `status` varchar(10) NOT NULL DEFAULT 'active', `users_id` $int, `comment` text, `date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `plugin_rh_candidaturas_id` (`plugin_rh_candidaturas_id`)",
        // checklist da admissão
        'glpi_plugin_rh_checklists' => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_rh_admissaos_id` $int, `area` varchar(20) NOT NULL,
            `label` varchar(255) NOT NULL, `rank` int NOT NULL DEFAULT 0, `is_done` tinyint NOT NULL DEFAULT 0, `users_id` $int, `date_done` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `plugin_rh_admissaos_id` (`plugin_rh_admissaos_id`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // 1.0.1: CBO e job description no cargo
    foreach (['cbo' => 'varchar(20) DEFAULT NULL AFTER `name`', 'job_description' => 'longtext AFTER `cbo`'] as $field => $def) {
        if (!$DB->fieldExists(Cargo::getTable(), $field)) {
            $DB->doQuery('ALTER TABLE `' . Cargo::getTable() . "` ADD `$field` $def");
        }
    }

    // 1.0.2: foto do candidato
    if (!$DB->fieldExists(Candidato::getTable(), 'picture')) {
        $DB->doQuery('ALTER TABLE `' . Candidato::getTable() . "` ADD `picture` varchar(255) DEFAULT NULL AFTER `source`");
    }

    // 1.0.3: cargo pretendido do candidato, também como coluna na lista do banco de currículo
    if (!$DB->fieldExists(Candidato::getTable(), 'plugin_rh_cargos_id')) {
        $DB->doQuery('ALTER TABLE `' . Candidato::getTable() . "` ADD `plugin_rh_cargos_id` int unsigned NOT NULL DEFAULT 0 AFTER `source`, ADD KEY `plugin_rh_cargos_id` (`plugin_rh_cargos_id`)");
        $prefs = ['itemtype' => Candidato::class, 'users_id' => 0];
        if (countElementsInTable('glpi_displaypreferences', $prefs) && !countElementsInTable('glpi_displaypreferences', $prefs + ['num' => 9])) {
            $DB->update('glpi_displaypreferences', ['rank' => new \Glpi\DBAL\QueryExpression('`rank` + 1')], $prefs);
            $DB->insert('glpi_displaypreferences', $prefs + ['num' => 9, 'rank' => 0]);
        }
    }

    $now = date('Y-m-d H:i:s');
    foreach ([Departamento::class => Departamento::DEFAULTS, Cargo::class => Cargo::DEFAULTS] as $class => $names) {
        if (!countElementsInTable($class::getTable())) {
            foreach ($names as $name) {
                $DB->insert($class::getTable(), ['name' => $name, 'entities_id' => 0, 'is_recursive' => 1, 'date_creation' => $now, 'date_mod' => $now]);
            }
        }
    }

    Cargo::fillTemplates();

    // direitos: todos pedem vaga e férias; quem administra a configuração faz tudo
    $defaults = [
        Vaga::$rightname   => [READ | CREATE, READ | CREATE | UPDATE | PURGE | Vaga::READALL | Vaga::APPROVE_BOARD],
        Feria::$rightname => [READ | CREATE | UPDATE, READ | CREATE | UPDATE | PURGE | Feria::READALL | Feria::APPROVE],
    ];
    foreach ($defaults as $right => [$user, $admin]) {
        if (countElementsInTable(ProfileRight::getTable(), ['name' => $right])) {
            continue; // atualização: mantém o que foi configurado
        }
        ProfileRight::addProfileRights([$right]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? $admin : $user], ['profiles_id' => $row['id'], 'name' => $right]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Vaga::$rightname, Feria::$rightname]);
        foreach ([Vaga::$rightname, Feria::$rightname] as $r) {
            $_SESSION['glpiactiveprofile'][$r] = (int) ($rights[$r] ?? 0);
        }
    }
    unset($_SESSION['glpimenu']);

    Notifications::install();
    return true;
}

function plugin_rh_uninstall(): bool
{
    global $DB;
    Notifications::uninstall();
    foreach ([Departamento::class, Cargo::class, Vaga::class, Candidato::class, Candidatura::class, Admissao::class, Feria::class] as $class) {
        $DB->dropTable($class::getTable(), true);
        $DB->delete('glpi_documents_items', ['itemtype' => $class]);
        $DB->delete('glpi_logs', ['itemtype' => $class]);
    }
    foreach (['aprovacoes', 'historicos', 'checklists'] as $t) {
        $DB->dropTable("glpi_plugin_rh_$t", true);
    }
    ProfileRight::deleteProfileRights([Vaga::$rightname, Feria::$rightname]);
    unset($_SESSION['glpimenu']);
    return true;
}

function plugin_rh_getDropdown(): array
{
    return [Departamento::class => Departamento::getTypeName(2), Cargo::class => Cargo::getTypeName(2)];
}

/** Na busca: sem "ver todos", cada um vê só o que é seu. */
function plugin_rh_addDefaultWhere($itemtype)
{
    $me = (int) Session::getLoginUserID();
    if ($itemtype === Vaga::class && !Session::haveRight(Vaga::$rightname, Vaga::READALL)) {
        $t = Vaga::getTable();
        return ['OR' => ["$t.users_id_requester" => $me, "$t.users_id_manager" => $me, "$t.users_id_recruiter" => $me]];
    }
    if ($itemtype === Feria::class && !Session::haveRight(Feria::$rightname, Feria::READALL)) {
        $t = Feria::getTable();
        return ['OR' => ["$t.users_id" => $me, "$t.users_id_manager" => $me]];
    }
    if ($itemtype === Admissao::class && !Session::haveRight(Vaga::$rightname, Vaga::READALL)) {
        return [Admissao::getTable() . '.users_id_manager' => $me]; // o gestor acompanha a admissão do seu contratado
    }
    if ($itemtype === Candidato::class && !Session::haveRight(Vaga::$rightname, Vaga::READALL)) {
        return [new \Glpi\DBAL\QueryExpression('1 = 0')]; // banco de candidatos: só o RH
    }
    return [];
}
