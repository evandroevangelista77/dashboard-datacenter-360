<?php

namespace GlpiPlugin\Pmo;

use Project;
use ProjectTask;
use Session;

/**
 * Metodologia do projeto no PMO e colunas do quadro.
 */
class ProjectConfig
{
    public const METHODOLOGIES = [
        'scrum'     => 'Scrum',
        'kanban'    => 'Kanban',
        'waterfall' => 'Waterfall (cascata)',
        'hybrid'    => 'Híbrido (cronograma + sprints)',
    ];

    public const KINDS = ['todo' => 'A fazer', 'doing' => 'Em andamento', 'done' => 'Concluído'];

    /** Colunas iniciais por metodologia: nome, categoria, cor, limite WIP, % ao entrar (-1 = mantém). */
    public const TEMPLATES = [
        'scrum' => [
            ['A fazer', 'todo', '#64748b', 0, 0],
            ['Em andamento', 'doing', '#3b82f6', 0, -1],
            ['Em revisão', 'doing', '#a855f7', 0, -1],
            ['Concluído', 'done', '#22c55e', 0, 100],
        ],
        'kanban' => [
            ['Backlog', 'todo', '#64748b', 0, 0],
            ['Selecionado', 'todo', '#0ea5e9', 5, 0],
            ['Em andamento', 'doing', '#3b82f6', 3, -1],
            ['Validação', 'doing', '#a855f7', 3, -1],
            ['Concluído', 'done', '#22c55e', 0, 100],
        ],
        'waterfall' => [
            ['Não iniciado', 'todo', '#64748b', 0, 0],
            ['Em execução', 'doing', '#3b82f6', 0, -1],
            ['Aguardando cliente', 'doing', '#f59e0b', 0, -1],
            ['Concluído', 'done', '#22c55e', 0, 100],
        ],
    ];

    public static function get(int $projectId): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => 'glpi_plugin_pmo_projects', 'WHERE' => ['projects_id' => $projectId]])->current();
        return $row ?: null;
    }

    /** Metodologia sugerida para um projeto ainda sem configuração. */
    public static function suggest(int $projectId): string
    {
        global $DB;
        if ($DB->tableExists('glpi_plugin_cronograma_imports')
            && count($DB->request(['FROM' => 'glpi_plugin_cronograma_imports', 'WHERE' => ['projects_id' => $projectId], 'LIMIT' => 1]))) {
            return 'waterfall';
        }
        return 'scrum';
    }

    /** Liga o PMO no projeto: grava a metodologia e cria as colunas do modelo. */
    public static function enable(int $projectId, string $methodology, string $estimation = 'points', int $sprintDays = 14): void
    {
        global $DB;
        $methodology = isset(self::METHODOLOGIES[$methodology]) ? $methodology : 'kanban';
        $estimation  = $estimation === 'hours' ? 'hours' : 'points';
        $data = [
            'methodology' => $methodology,
            'estimation'  => $estimation,
            'sprint_days' => max(1, min(60, $sprintDays)),
            'date_mod'    => $_SESSION['glpi_currenttime'],
        ];
        if (self::get($projectId)) {
            $DB->update('glpi_plugin_pmo_projects', $data, ['projects_id' => $projectId]);
        } else {
            $DB->insert('glpi_plugin_pmo_projects', $data + ['projects_id' => $projectId]);
        }
        if (!self::columns($projectId)) {
            self::applyTemplate($projectId, $methodology);
        }
    }

    /** Recria as colunas a partir do modelo; os cartões vão para a coluna equivalente pela categoria. */
    public static function applyTemplate(int $projectId, string $methodology): void
    {
        global $DB;
        $template = self::TEMPLATES[$methodology === 'hybrid' ? 'scrum' : $methodology] ?? self::TEMPLATES['kanban'];
        $old = self::columns($projectId);
        $DB->delete('glpi_plugin_pmo_columns', ['projects_id' => $projectId]);
        $firstByKind = [];
        foreach ($template as $rank => [$name, $kind, $color, $wip, $pct]) {
            $DB->insert('glpi_plugin_pmo_columns', [
                'projects_id' => $projectId, 'name' => $name, 'kind' => $kind, 'color' => $color,
                'wip_limit' => $wip, 'percent' => $pct, 'rank' => $rank,
            ]);
            $firstByKind[$kind] ??= (int) $DB->insertId();
        }
        foreach ($old as $col) {
            $DB->update('glpi_plugin_pmo_items', ['columns_id' => $firstByKind[$col['kind']] ?? 0], ['columns_id' => $col['id']]);
        }
    }

    public static function columns(int $projectId): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_pmo_columns', 'WHERE' => ['projects_id' => $projectId], 'ORDER' => ['rank', 'id']]) as $r) {
            $r['id'] = (int) $r['id'];
            $r['wip_limit'] = (int) $r['wip_limit'];
            $r['percent'] = (int) $r['percent'];
            $r['projectstates_id'] = (int) $r['projectstates_id'];
            $rows[] = $r;
        }
        return $rows;
    }

    /** Primeira coluna de cada categoria (destino padrão ao sincronizar pelo %). */
    public static function firstByKind(array $columns): array
    {
        $map = [];
        foreach ($columns as $c) {
            $map[$c['kind']] ??= $c['id'];
        }
        $any = $columns[0]['id'] ?? 0;
        return ['todo' => $map['todo'] ?? $any, 'doing' => $map['doing'] ?? ($map['todo'] ?? $any), 'done' => $map['done'] ?? $any];
    }

    /**
     * Grava as colunas vindas do formulário de configuração.
     * @param array $rows [ ['id'=>?, 'name','kind','color','wip_limit','percent','projectstates_id','delete'] ... ] na ordem
     */
    public static function saveColumns(int $projectId, array $rows): void
    {
        global $DB;
        $existing = array_column(self::columns($projectId), null, 'id');
        $kept = [];
        $rank = 0;
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            $name = trim((string) ($r['name'] ?? ''));
            if (!empty($r['delete']) || $name === '') {
                continue;
            }
            $data = [
                'name'             => mb_substr($name, 0, 100),
                'kind'             => isset(self::KINDS[$r['kind'] ?? '']) ? $r['kind'] : 'todo',
                'color'            => preg_match('/^#[0-9a-f]{6}$/i', (string) ($r['color'] ?? '')) ? $r['color'] : '#64748b',
                'wip_limit'        => max(0, (int) ($r['wip_limit'] ?? 0)),
                'percent'          => ($r['percent'] ?? '') === '' ? -1 : max(-1, min(100, (int) $r['percent'])),
                'projectstates_id' => max(0, (int) ($r['projectstates_id'] ?? 0)),
                'rank'             => $rank++,
            ];
            if ($id && isset($existing[$id])) {
                $DB->update('glpi_plugin_pmo_columns', $data, ['id' => $id]);
                $kept[] = $id;
            } else {
                $DB->insert('glpi_plugin_pmo_columns', $data + ['projects_id' => $projectId]);
                $kept[] = (int) $DB->insertId();
            }
        }
        // colunas removidas: os cartões vão para a primeira coluna da mesma categoria
        $first = self::firstByKind(self::columns($projectId) ?: []);
        foreach ($existing as $id => $col) {
            if (!in_array($id, $kept, true)) {
                $DB->delete('glpi_plugin_pmo_columns', ['id' => $id]);
                $DB->update('glpi_plugin_pmo_items', ['columns_id' => $first[$col['kind']] ?? 0], ['columns_id' => $id]);
            }
        }
    }

    /** O usuário pode ver o projeto no PMO? */
    public static function canView(Project $project): bool
    {
        return Session::haveRight(Menu::$rightname, READ) && $project->canViewItem();
    }

    /** O usuário pode mexer no quadro, sprints, documentação e riscos do projeto? */
    public static function canEdit(Project $project): bool
    {
        return Menu::canEdit() && ($project->canUpdateItem() || ProjectTask::canUpdate());
    }

    public static function usesSprints(?array $config): bool
    {
        return in_array($config['methodology'] ?? '', ['scrum', 'hybrid'], true);
    }
}
