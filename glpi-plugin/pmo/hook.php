<?php

use GlpiPlugin\Pmo\Menu;
use GlpiPlugin\Pmo\Snapshot;

function plugin_pmo_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    $tables = [
        // metodologia e parâmetros do projeto no PMO
        'glpi_plugin_pmo_projects' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `projects_id` int $sign NOT NULL DEFAULT 0,
            `methodology` varchar(20) NOT NULL DEFAULT 'kanban',
            `estimation` varchar(10) NOT NULL DEFAULT 'points',
            `sprint_days` int NOT NULL DEFAULT 14,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `projects_id` (`projects_id`)",
        // colunas do quadro; kind = todo | doing | done (categoria, como no Jira)
        'glpi_plugin_pmo_columns' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `projects_id` int $sign NOT NULL DEFAULT 0,
            `name` varchar(100) NOT NULL DEFAULT '',
            `color` varchar(20) NOT NULL DEFAULT '#64748b',
            `kind` varchar(10) NOT NULL DEFAULT 'todo',
            `wip_limit` int NOT NULL DEFAULT 0,
            `percent` int NOT NULL DEFAULT -1,
            `projectstates_id` int $sign NOT NULL DEFAULT 0,
            `rank` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `projects_id` (`projects_id`)",
        // cartão = tarefa nativa + o que o GLPI não tem
        'glpi_plugin_pmo_items' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `projecttasks_id` int $sign NOT NULL DEFAULT 0,
            `projects_id` int $sign NOT NULL DEFAULT 0,
            `columns_id` int $sign NOT NULL DEFAULT 0,
            `sprints_id` int $sign NOT NULL DEFAULT 0,
            `rank` int NOT NULL DEFAULT 0,
            `story_points` decimal(6,1) NOT NULL DEFAULT 0,
            `priority` tinyint NOT NULL DEFAULT 3,
            `date_done` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `projecttasks_id` (`projecttasks_id`),
            KEY `projects_id` (`projects_id`),
            KEY `sprints_id` (`sprints_id`)",
        // state = future | active | closed
        'glpi_plugin_pmo_sprints' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `projects_id` int $sign NOT NULL DEFAULT 0,
            `name` varchar(100) NOT NULL DEFAULT '',
            `goal` text,
            `start_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `state` varchar(10) NOT NULL DEFAULT 'future',
            `committed` decimal(8,1) NOT NULL DEFAULT 0,
            `completed` decimal(8,1) NOT NULL DEFAULT 0,
            `scope` text,
            `date_closed` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `projects_id` (`projects_id`)",
        // fotografia diária (fluxo cumulativo e % real ao longo do tempo)
        'glpi_plugin_pmo_snapshots' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `projects_id` int $sign NOT NULL DEFAULT 0,
            `day` date NOT NULL,
            `total_items` int NOT NULL DEFAULT 0,
            `done_items` int NOT NULL DEFAULT 0,
            `total_points` decimal(8,1) NOT NULL DEFAULT 0,
            `done_points` decimal(8,1) NOT NULL DEFAULT 0,
            `planned_percent` decimal(5,1) NOT NULL DEFAULT 0,
            `actual_percent` decimal(5,1) NOT NULL DEFAULT 0,
            `columns` text,
            PRIMARY KEY (`id`),
            UNIQUE KEY `project_day` (`projects_id`, `day`)",
        'glpi_plugin_pmo_pages' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `projects_id` int $sign NOT NULL DEFAULT 0,
            `name` varchar(255) NOT NULL DEFAULT '',
            `template` varchar(30) NOT NULL DEFAULT '',
            `content` longtext,
            `rank` int NOT NULL DEFAULT 0,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `users_id_lastupdater` int $sign NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `projects_id` (`projects_id`)",
        // kind = risk | issue; status = open | mitigating | closed
        'glpi_plugin_pmo_risks' => "
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `projects_id` int $sign NOT NULL DEFAULT 0,
            `kind` varchar(10) NOT NULL DEFAULT 'risk',
            `name` varchar(255) NOT NULL DEFAULT '',
            `description` text,
            `probability` tinyint NOT NULL DEFAULT 3,
            `impact` tinyint NOT NULL DEFAULT 3,
            `users_id` int $sign NOT NULL DEFAULT 0,
            `response` text,
            `status` varchar(12) NOT NULL DEFAULT 'open',
            `due_date` date DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `projects_id` (`projects_id`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // direito do plugin: leitura para perfis da interface padrão, edição para quem edita projetos
    ProfileRight::addProfileRights([Menu::$rightname]);
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
        $project = ProfileRight::getProfileRights($row['id'], ['project'])['project'] ?? 0;
        $DB->update(
            ProfileRight::getTable(),
            ['rights' => ($project & UPDATE) ? (READ | UPDATE) : READ],
            ['profiles_id' => $row['id'], 'name' => Menu::$rightname, 'rights' => 0]
        );
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::$rightname]);
        $_SESSION['glpiactiveprofile'][Menu::$rightname] = (int) ($rights[Menu::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    // tipos de tarefa usados nos cartões (lista nativa do GLPI; só cria os que faltam)
    $type = new ProjectTaskType();
    foreach (['História', 'Tarefa', 'Bug', 'Melhoria'] as $name) {
        if (!$type->getFromDBByCrit(['name' => $name])) {
            $type->add(['name' => $name]);
        }
    }

    CronTask::register(Snapshot::class, 'pmosnapshot', DAY_TIMESTAMP, [
        'comment' => 'PMO: fotografia diária dos projetos (fluxo cumulativo e curva S)',
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);

    return true;
}

function plugin_pmo_uninstall(): bool
{
    global $DB;

    // as tarefas e projetos nativos ficam; saem só os dados do PMO
    foreach (['projects', 'columns', 'items', 'sprints', 'snapshots', 'pages', 'risks'] as $t) {
        $DB->dropTable("glpi_plugin_pmo_$t", true);
    }
    ProfileRight::deleteProfileRights([Menu::$rightname]);
    CronTask::unregister('pmo');
    unset($_SESSION['glpimenu']);

    return true;
}

function plugin_pmo_item_purge(CommonDBTM $item): void
{
    global $DB;

    if ($item instanceof ProjectTask) {
        $DB->delete('glpi_plugin_pmo_items', ['projecttasks_id' => $item->getID()]);
        return;
    }
    foreach (['projects', 'columns', 'items', 'sprints', 'snapshots', 'pages', 'risks'] as $t) {
        $DB->delete("glpi_plugin_pmo_$t", ['projects_id' => $item->getID()]);
    }
}
