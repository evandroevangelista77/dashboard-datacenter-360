<?php

use GlpiPlugin\Satisfacao\Campaign;
use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Notifications;
use GlpiPlugin\Satisfacao\Question;
use GlpiPlugin\Satisfacao\Survey;

/** Tabelas, perguntas padrão, configuração, direitos e as notificações de e-mail. */
function plugin_satisfacao_install(): bool
{
    global $DB;
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $int = "int $sign NOT NULL DEFAULT 0";
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    $tables = [
        // perguntas editáveis; tipo: rating (1 a 5), nps (0 a 10), yesno (sim ou não), text (comentário)
        Question::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, `type` varchar(10) NOT NULL DEFAULT 'rating',
            `position` int NOT NULL DEFAULT 0, `is_required` tinyint NOT NULL DEFAULT 0, `is_active` tinyint NOT NULL DEFAULT 1,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `is_active` (`is_active`), KEY `position` (`position`)",
        // uma pesquisa por chamado ou ciclo; status: 0 enviada, 1 respondida
        Survey::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `entities_id` $int, `itemtype` varchar(100) NOT NULL, `items_id` $int,
            `name` varchar(255) DEFAULT NULL, `recipients` varchar(500) DEFAULT NULL, `group_name` varchar(255) DEFAULT NULL, `token` varchar(64) NOT NULL,
            `status` tinyint NOT NULL DEFAULT 0, `date_sent` datetime DEFAULT NULL, `date_answered` datetime DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `token` (`token`), KEY `item` (`itemtype`, `items_id`), KEY `status` (`status`), KEY `date_answered` (`date_answered`)",
        // campanhas de eNPS (clima): quem recebe é a união dos grupos escolhidos e dos e-mails extras; status: 0 rascunho, 1 enviada, 2 encerrada
        Campaign::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `entities_id` $int, `name` varchar(255) NOT NULL, `question` varchar(500) DEFAULT NULL, `comment_question` varchar(500) DEFAULT NULL,
            `groups` varchar(255) DEFAULT NULL, `emails` text, `status` tinyint NOT NULL DEFAULT 0, `date_sent` datetime DEFAULT NULL, `date_close` datetime DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `status` (`status`), KEY `date_sent` (`date_sent`)",
        // respostas do eNPS: anônimas, sem ligação com a pesquisa (o token só impede responder duas vezes); só a data, sem hora
        Survey::ENPS_TABLE => "`id` int $sign NOT NULL AUTO_INCREMENT, `campaigns_id` $int, `score` tinyint NOT NULL, `comment` text, `group_name` varchar(255) DEFAULT NULL, `date_answered` date DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `campaign` (`campaigns_id`), KEY `date_answered` (`date_answered`)",
        // respostas; a pergunta é guardada junto, para o histórico não mudar quando a pergunta for editada
        Survey::ANSWERS_TABLE => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_satisfacao_surveys_id` $int, `questions_id` $int,
            `question` varchar(255) NOT NULL, `type` varchar(10) NOT NULL, `score` tinyint DEFAULT NULL, `comment` text,
            PRIMARY KEY (`id`), KEY `survey` (`plugin_satisfacao_surveys_id`), KEY `question` (`questions_id`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // 1.1.0: o eNPS cria várias pesquisas por campanha (a chave do item deixa de ser única) e guarda o grupo do destinatário
    if (!$DB->fieldExists(Survey::getTable(), 'group_name')) {
        $DB->doQuery("ALTER TABLE `" . Survey::getTable() . "` ADD `group_name` varchar(255) DEFAULT NULL AFTER `recipients`");
    }
    $index = $DB->doQuery("SHOW INDEX FROM `" . Survey::getTable() . "` WHERE Key_name = 'item'");
    $unique = $index ? $index->fetch_assoc() : null;
    if ($unique && (int) $unique['Non_unique'] === 0) {
        $DB->doQuery("ALTER TABLE `" . Survey::getTable() . "` DROP INDEX `item`, ADD KEY `item` (`itemtype`, `items_id`)");
    }

    // perguntas padrão, só na primeira instalação (depois a equipe edita as suas)
    if (!countElementsInTable(Question::getTable())) {
        $now = date('Y-m-d H:i:s');
        $defaults = [
            ['Como você avalia o atendimento recebido?', 'rating', 1],
            ['Qual a chance de você recomendar nosso atendimento a um colega? (0 a 10)', 'nps', 1],
            ['O seu problema foi resolvido?', 'yesno', 1],
            ['Quer deixar algum comentário?', 'text', 0],
        ];
        foreach ($defaults as $pos => [$name, $type, $required]) {
            $DB->insert(Question::getTable(), [
                'name' => $name, 'type' => $type, 'position' => ($pos + 1) * 10, 'is_required' => $required, 'is_active' => 1,
                'date_creation' => $now, 'date_mod' => $now,
            ]);
        }
    }

    // configuração: filtros (entidade, categoria, solução) e o que dispara em cada origem; vazio nos filtros = todos
    $current = Config::getConfigurationValues('plugin:satisfacao');
    Config::setConfigurationValues('plugin:satisfacao', array_diff_key([
        'email_enabled'   => '1',
        'email_questions' => '1',
        'entities'        => '',
        'categories'      => '',
        'solutions'       => '',
        'on_solved'       => '0',
        'project_enabled' => '1',
        'project_emails'  => '',
        'event_enabled'   => '1',
        'event_emails'    => '',
        'ciclo_enabled'   => '1',
        'cycle_emails'    => '',
    ], $current));

    // direitos: ver o painel e as respostas para a interface padrão; editar perguntas e configurar para quem administra
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Menu::$rightname])) {
        ProfileRight::addProfileRights([Menu::$rightname]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? READ | CREATE | UPDATE | PURGE : READ], ['profiles_id' => $row['id'], 'name' => Menu::$rightname]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::$rightname]);
        $_SESSION['glpiactiveprofile'][Menu::$rightname] = (int) ($rights[Menu::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    Notifications::install();
    return true;
}

function plugin_satisfacao_uninstall(): bool
{
    global $DB;
    foreach ([Question::getTable(), Survey::getTable(), Survey::ANSWERS_TABLE, Campaign::getTable(), Survey::ENPS_TABLE] as $table) {
        $DB->dropTable($table, true);
    }
    $DB->delete('glpi_logs', ['itemtype' => Survey::class]);
    ProfileRight::deleteProfileRights([Menu::$rightname]);
    Config::deleteConfigurationValues('plugin:satisfacao', ['email_enabled', 'email_questions', 'entities', 'categories', 'solutions', 'on_solved', 'project_enabled', 'project_emails', 'event_enabled', 'event_emails', 'ciclo_enabled', 'cycle_emails']);
    Notifications::uninstall();
    unset($_SESSION['glpimenu']);
    return true;
}
