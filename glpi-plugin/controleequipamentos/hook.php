<?php

use GlpiPlugin\Controleequipamentos\Devolucao;
use GlpiPlugin\Controleequipamentos\Entrega;
use GlpiPlugin\Controleequipamentos\Fluxo;
use GlpiPlugin\Controleequipamentos\Menu;

/** Tabelas e direito. Nenhum dado padrão: os grupos são escolhidos em Configurar → Plugins. */
function plugin_controleequipamentos_install(): bool
{
    global $DB;
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $int = "int $sign NOT NULL DEFAULT 0";
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    $tables = [
        // um fluxo de entrega por novo colaborador; status: aberta, concluida, cancelada
        Entrega::TABLE => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL DEFAULT '',
            `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0,
            `setor` varchar(255) DEFAULT NULL, `cargo` varchar(255) DEFAULT NULL, `centro_custo` varchar(100) DEFAULT NULL,
            `data_inicio` date DEFAULT NULL, `equipamentos` text, `local_entrega` text,
            `tickets_id` $int, `current_step` tinyint NOT NULL DEFAULT 1, `rh_admissoes_id` $int, `status` varchar(12) NOT NULL DEFAULT 'aberta',
            `dados` longtext, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            `is_deleted` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `entities_id` (`entities_id`), KEY `status` (`status`), KEY `tickets_id` (`tickets_id`)",
        // histórico de cada etapa concluída ou devolvida
        Entrega::TABLE_ETAPAS => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_controleequipamentos_entregas_id` $int,
            `step` tinyint NOT NULL DEFAULT 1, `action` varchar(12) NOT NULL DEFAULT 'concluida', `users_id` $int,
            `date_start` datetime DEFAULT NULL, `tickets_id` $int, `groups_id` $int, `users_id_responsavel` $int, `date` datetime DEFAULT NULL, `observacao` text, `dados` longtext,
            PRIMARY KEY (`id`), KEY `entrega` (`plugin_controleequipamentos_entregas_id`)",
        // formulário de solicitação de transporte (devolução de desligados)
        Devolucao::TABLE => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL DEFAULT '',
            `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0,
            `solicitante` varchar(255) DEFAULT NULL, `centro_custo` varchar(100) DEFAULT NULL,
            `data_admissao` date DEFAULT NULL, `rg` varchar(30) DEFAULT NULL, `cpf` varchar(20) DEFAULT NULL, `data_saida` date DEFAULT NULL,
            `coleta_endereco` text, `coleta_data` date DEFAULT NULL, `coleta_horario` varchar(50) DEFAULT NULL, `coleta_contato` varchar(255) DEFAULT NULL,
            `entrega_endereco` text, `entrega_data` date DEFAULT NULL, `entrega_horario` varchar(50) DEFAULT NULL, `entrega_contato` varchar(255) DEFAULT NULL,
            `tipo_servico` varchar(20) DEFAULT NULL, `transportadora` varchar(255) DEFAULT NULL,
            `current_step` tinyint NOT NULL DEFAULT 1, `dados` longtext,
            `total` decimal(14,2) NOT NULL DEFAULT 0, `observacao` text, `status` varchar(12) NOT NULL DEFAULT 'aberta',
            `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            `is_deleted` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `entities_id` (`entities_id`), KEY `status` (`status`), KEY `data_saida` (`data_saida`)",
        // visitas de cada etapa da devolução (mesmo modelo da entrega)
        Devolucao::TABLE_ETAPAS => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_controleequipamentos_devolucoes_id` $int,
            `step` tinyint NOT NULL DEFAULT 1, `action` varchar(12) NOT NULL DEFAULT 'concluida', `users_id` $int,
            `date_start` datetime DEFAULT NULL, `tickets_id` $int, `groups_id` $int, `users_id_responsavel` $int, `date` datetime DEFAULT NULL, `observacao` text, `dados` longtext,
            PRIMARY KEY (`id`), KEY `devolucao` (`plugin_controleequipamentos_devolucoes_id`)",
        // materiais de cada devolução
        Devolucao::TABLE_ITENS => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_controleequipamentos_devolucoes_id` $int,
            `material` varchar(255) DEFAULT NULL, `quantidade` int NOT NULL DEFAULT 0, `peso` varchar(50) DEFAULT NULL,
            `dimensao` varchar(100) DEFAULT NULL, `serie` varchar(100) DEFAULT NULL, `valor` decimal(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `devolucao` (`plugin_controleequipamentos_devolucoes_id`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // 1.1.0: cada visita de etapa guarda a data de início e o chamado aberto para o grupo
    foreach (['date_start' => "datetime DEFAULT NULL AFTER `step`", 'tickets_id' => "int $sign NOT NULL DEFAULT 0 AFTER `date_start`"] as $campo => $tipo) {
        if (!$DB->fieldExists(Entrega::TABLE_ETAPAS, $campo)) {
            $DB->doQuery("ALTER TABLE `" . Entrega::TABLE_ETAPAS . "` ADD `$campo` $tipo");
        }
    }

    // 1.3.0: responsável e grupo por visita, admissão do RH no ciclo de entrega
    foreach ([Entrega::TABLE_ETAPAS, Devolucao::TABLE_ETAPAS] as $tabela) {
        foreach (['groups_id' => "int $sign NOT NULL DEFAULT 0", 'users_id_responsavel' => "int $sign NOT NULL DEFAULT 0"] as $campo => $tipo) {
            if (!$DB->fieldExists($tabela, $campo)) {
                $DB->doQuery("ALTER TABLE `$tabela` ADD `$campo` $tipo");
            }
        }
    }
    if (!$DB->fieldExists(Entrega::TABLE, 'rh_admissoes_id')) {
        $DB->doQuery("ALTER TABLE `" . Entrega::TABLE . "` ADD `rh_admissoes_id` int $sign NOT NULL DEFAULT 0 AFTER `current_step`");
    }

    // 1.2.0: a devolução vira ciclo, com etapa atual
    if (!$DB->fieldExists(Devolucao::TABLE, 'current_step')) {
        $DB->doQuery("ALTER TABLE `" . Devolucao::TABLE . "` ADD `current_step` tinyint NOT NULL DEFAULT 1 AFTER `transportadora`");
    }
    if (!$DB->fieldExists(Devolucao::TABLE, 'dados')) {
        $DB->doQuery("ALTER TABLE `" . Devolucao::TABLE . "` ADD `dados` longtext AFTER `current_step`");
    }

    // direito: ler para todos da interface padrão; criar e atualizar para quem administra a configuração
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Menu::RIGHT])) {
        ProfileRight::addProfileRights([Menu::RIGHT]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? READ | CREATE | UPDATE : READ], ['profiles_id' => $row['id'], 'name' => Menu::RIGHT]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::RIGHT]);
        $_SESSION['glpiactiveprofile'][Menu::RIGHT] = (int) ($rights[Menu::RIGHT] ?? 0);
    }
    unset($_SESSION['glpimenu']);
    return true;
}

function plugin_controleequipamentos_uninstall(): bool
{
    global $DB;
    foreach ([Entrega::TABLE, Entrega::TABLE_ETAPAS, Devolucao::TABLE, Devolucao::TABLE_ETAPAS, Devolucao::TABLE_ITENS] as $table) {
        $DB->dropTable($table, true);
    }
    ProfileRight::deleteProfileRights([Menu::RIGHT]);
    Config::deleteConfigurationValues(Menu::CONFIG_CONTEXT, array_merge(Entrega::configKeys(), Devolucao::configKeys(), Fluxo::chavesGrupo()));
    unset($_SESSION['glpimenu']);
    return true;
}
