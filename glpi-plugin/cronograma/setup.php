<?php

/**
 * Plugin "Cronograma (MS Project)" para GLPI 12.
 *
 * Aba no Projeto para importar um cronograma do Microsoft Project (.mpp nativo ou
 * .xml exportado): cria/atualiza as tarefas com hierarquia, datas, duração,
 * % concluído, marcos, dependências do Gantt e equipe.
 * O .mpp é convertido pelo MPXJ (Java) no servidor.
 */

use GlpiPlugin\Cronograma\ProjectTab;

define('PLUGIN_CRONOGRAMA_VERSION', '1.1.0');
define('PLUGIN_CRONOGRAMA_MIN_GLPI', '12.0.0');
define('PLUGIN_CRONOGRAMA_MAX_GLPI', '12.0.99');

function plugin_init_cronograma(): void
{
    global $PLUGIN_HOOKS;

    // aba do plugin em Administração → Perfis
    Plugin::registerClass(GlpiPlugin\Cronograma\ProfileTab::class, ['addtabon' => [Profile::class]]);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile']['plugin_cronograma'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], ['plugin_cronograma']);
        $_SESSION['glpiactiveprofile']['plugin_cronograma'] = (int) ($rights['plugin_cronograma'] ?? 0);
    }

    Plugin::registerClass(ProjectTab::class, ['addtabon' => [Project::class]]);
    // tarefa ou projeto apagado de vez: o vínculo com o UID do Project sai junto
    $PLUGIN_HOOKS['item_purge']['cronograma'] = [
        ProjectTask::class => 'plugin_cronograma_item_purge',
        Project::class     => 'plugin_cronograma_item_purge',
    ];
    // barra de evolução e zebra na lista de tarefas do projeto
    $PLUGIN_HOOKS['add_css']['cronograma']        = ['css/tarefas.css'];
    $PLUGIN_HOOKS['add_javascript']['cronograma'] = ['js/tarefas.js'];
}

function plugin_version_cronograma(): array
{
    return [
        'name'         => 'Cronograma (MS Project)',
        'version'      => PLUGIN_CRONOGRAMA_VERSION,
        'author'       => 'Evandro Evangelista',
        'homepage'     => 'https://github.com/evandroevangelista77/dashboard-datacenter-360',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_CRONOGRAMA_MIN_GLPI, 'max' => PLUGIN_CRONOGRAMA_MAX_GLPI],
        ],
    ];
}

function plugin_cronograma_check_prerequisites(): bool
{
    foreach (['simplexml', 'libxml'] as $ext) {
        if (!extension_loaded($ext)) {
            echo "A extensão PHP $ext é necessária.";
            return false;
        }
    }
    return true;
}

function plugin_cronograma_check_config($verbose = false): bool
{
    return true;
}
