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

define('PLUGIN_CRONOGRAMA_VERSION', '1.0.0');
define('PLUGIN_CRONOGRAMA_MIN_GLPI', '12.0.0');
define('PLUGIN_CRONOGRAMA_MAX_GLPI', '12.0.99');

function plugin_init_cronograma(): void
{
    Plugin::registerClass(ProjectTab::class, ['addtabon' => [Project::class]]);
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
