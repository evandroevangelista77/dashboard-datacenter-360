<?php

namespace GlpiPlugin\Pmo;

use DbUtils;
use Dropdown;
use Glpi\DBAL\QuerySubQuery;
use Project;
use ProjectState;
use ProjectType;
use Session;
use User;

/**
 * Portfólio do PMO: todos os projetos que o usuário vê, com saúde, progresso,
 * prazo e os campos do bloco "Dados do projeto".
 */
class Portfolio
{
    public static function rows(bool $includeClosed = false): array
    {
        global $DB;
        $where = ['p.is_deleted' => 0, 'p.is_template' => 0]
            + (new DbUtils())->getEntitiesRestrictCriteria('p', '', '', true);
        if (!Session::haveRight('project', Project::READALL)) {
            // só "meus projetos": gerente, equipe (usuário ou grupo)
            $uid = Session::getLoginUserID();
            $groups = $_SESSION['glpigroups'] ?? [];
            $or = [
                'p.users_id' => $uid,
                ['p.id' => new QuerySubQuery(['SELECT' => 'projects_id', 'FROM' => 'glpi_projectteams', 'WHERE' => ['itemtype' => User::class, 'items_id' => $uid]])],
            ];
            if ($groups) {
                $or[] = ['p.id' => new QuerySubQuery(['SELECT' => 'projects_id', 'FROM' => 'glpi_projectteams', 'WHERE' => ['itemtype' => 'Group', 'items_id' => $groups]])];
            }
            $where[] = ['OR' => $or];
        }
        $projects = iterator_to_array($DB->request([
            'SELECT'    => ['p.*', 'c.methodology'],
            'FROM'      => 'glpi_projects AS p',
            'LEFT JOIN' => ['glpi_plugin_pmo_projects AS c' => ['ON' => ['c' => 'projects_id', 'p' => 'id']]],
            'WHERE'     => $where,
            'ORDER'     => ['p.plan_end_date', 'p.name'],
        ]), false);

        $ids = array_map(fn ($p) => (int) $p['id'], $projects);
        $fields = FieldsData::forProjects($ids);
        $risks = [];
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['projects_id', 'COUNT' => 'id AS c'], 'FROM' => 'glpi_plugin_pmo_risks',
                'WHERE' => ['projects_id' => $ids, 'status' => ['open', 'mitigating']], 'GROUPBY' => 'projects_id']) as $r) {
                $risks[(int) $r['projects_id']] = (int) $r['c'];
            }
        }
        $states = [];
        foreach ($DB->request(['FROM' => ProjectState::getTable()]) as $s) {
            $states[(int) $s['id']] = $s;
        }

        $rows = [];
        foreach ($projects as $p) {
            $id = (int) $p['id'];
            $state = $states[(int) $p['projectstates_id']] ?? null;
            $finished = $state && (int) $state['is_finished'] === 1;
            if ($finished && !$includeClosed) {
                continue;
            }
            $config = $p['methodology'] ? ProjectConfig::get($id) : null;
            $columns = $config ? ProjectConfig::columns($id) : [];
            $kinds = array_column($columns, 'kind', 'id');
            $tasks = $config ? Board::sync($id) : Board::tasks($id);
            $progress = Metrics::progress($p, $tasks, $kinds, $config['estimation'] ?? 'points');
            $leaves = Metrics::leaves($tasks);
            $overdue = 0;
            foreach ($leaves as $t) {
                if (!Metrics::isDone($t, $kinds) && $t['plan_end_date'] && strtotime($t['plan_end_date']) < time()) {
                    $overdue++;
                }
            }
            $actual = $leaves ? $progress['actual'] : (float) $p['percent_done'];
            $rows[] = [
                'id'          => $id,
                'name'        => (string) $p['name'],
                'code'        => (string) $p['code'],
                'url'         => Project::getFormURLWithID($id),
                'pmo'         => Menu::url('/front/project.php?id=' . $id),
                'methodology' => $config ? ProjectConfig::METHODOLOGIES[$config['methodology']] ?? '' : '',
                'type'        => $p['projecttypes_id'] ? Dropdown::getDropdownName(ProjectType::getTable(), $p['projecttypes_id']) : '',
                'state'       => $state ? ['id' => (int) $state['id'], 'name' => $state['name'], 'color' => $state['color'] ?: '#64748b', 'finished' => (int) ($state['is_finished'] ?? 0)] : null,
                'manager'     => $p['users_id'] ? (string) getUserName((int) $p['users_id']) : '',
                'priority'    => (int) $p['priority'],
                'actual'      => $actual,
                'planned'     => $progress['planned'],
                'start'       => $p['plan_start_date'] ? substr($p['plan_start_date'], 0, 10) : '',
                'end'         => $p['plan_end_date'] ? substr($p['plan_end_date'], 0, 10) : '',
                'tasks'       => count($leaves),
                'overdue'     => $overdue,
                'risks'       => $risks[$id] ?? 0,
                'health'      => Metrics::health($p, $progress['planned'], $actual, $overdue),
                'fields'      => $fields[$id] ?? [],
            ];
        }
        return $rows;
    }

    /** Tarefas abertas do usuário em todos os projetos (Meu trabalho). */
    public static function myWork(int $uid): array
    {
        global $DB;
        $rows = [];
        $it = $DB->request([
            'SELECT'     => ['t.id', 't.name', 't.percent_done', 't.plan_start_date', 't.plan_end_date', 't.projects_id', 'p.name AS project', 'i.columns_id', 'i.story_points', 'i.priority', 'c.name AS column', 'c.color', 'c.kind'],
            'FROM'       => 'glpi_projecttasks AS t',
            'INNER JOIN' => [
                'glpi_projecttaskteams AS m' => ['ON' => ['m' => 'projecttasks_id', 't' => 'id', ['AND' => ['m.itemtype' => User::class, 'm.items_id' => $uid]]]],
                'glpi_projects AS p'         => ['ON' => ['p' => 'id', 't' => 'projects_id']],
            ],
            'LEFT JOIN'  => [
                'glpi_plugin_pmo_items AS i'   => ['ON' => ['i' => 'projecttasks_id', 't' => 'id']],
                'glpi_plugin_pmo_columns AS c' => ['ON' => ['c' => 'id', 'i' => 'columns_id']],
            ],
            'WHERE'      => ['t.is_deleted' => 0, 't.is_template' => 0, 'p.is_deleted' => 0, 't.percent_done' => ['<', 100]],
            'ORDER'      => ['t.plan_end_date', 't.id'],
        ]);
        foreach ($it as $r) {
            $end = $r['plan_end_date'] ? strtotime($r['plan_end_date']) : null;
            $r['due'] = $end ? ($end < time() ? 'late' : ($end < time() + 7 * DAY_TIMESTAMP ? 'soon' : 'later')) : 'none';
            $rows[] = $r;
        }
        return $rows;
    }
}
