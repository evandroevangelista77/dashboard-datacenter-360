<?php

namespace GlpiPlugin\Pmo;

use RuntimeException;

/**
 * Sprints do Scrum: futura → ativa (uma por projeto) → encerrada.
 * `scope` guarda os ids das tarefas da sprint no encerramento, para o burndown
 * de sprints antigas continuar certo depois que as pendentes voltam ao backlog.
 */
class Sprint
{
    public const STATES = ['future' => 'Planejada', 'active' => 'Em andamento', 'closed' => 'Encerrada'];

    public static function all(int $projectId): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_pmo_sprints', 'WHERE' => ['projects_id' => $projectId], 'ORDER' => ['start_date', 'id']]) as $r) {
            $rows[] = self::normalize($r);
        }
        return $rows;
    }

    public static function find(int $projectId, int $sprintId): array
    {
        global $DB;
        $r = $DB->request(['FROM' => 'glpi_plugin_pmo_sprints', 'WHERE' => ['id' => $sprintId, 'projects_id' => $projectId]])->current();
        if (!$r) {
            throw new RuntimeException('Sprint não encontrada.');
        }
        return self::normalize($r);
    }

    public static function active(int $projectId): ?array
    {
        foreach (self::all($projectId) as $s) {
            if ($s['state'] === 'active') {
                return $s;
            }
        }
        return null;
    }

    private static function normalize(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['committed'] = (float) $r['committed'];
        $r['completed'] = (float) $r['completed'];
        $r['scope'] = $r['scope'] ? (json_decode($r['scope'], true) ?: []) : [];
        $r['stateName'] = self::STATES[$r['state']] ?? $r['state'];
        return $r;
    }

    public static function create(int $projectId, array $in = []): int
    {
        global $DB;
        $config = ProjectConfig::get($projectId);
        $days = (int) ($config['sprint_days'] ?? 14);
        $all = self::all($projectId);
        $last = end($all) ?: null;
        $start = self::date($in['start_date'] ?? '')
            ?? ($last && $last['end_date'] && strtotime($last['end_date']) >= strtotime('today')
                ? date('Y-m-d', strtotime($last['end_date'] . ' +1 day'))
                : date('Y-m-d'));
        $end = self::date($in['end_date'] ?? '') ?? date('Y-m-d', strtotime("$start +" . ($days - 1) . ' days'));
        $DB->insert('glpi_plugin_pmo_sprints', [
            'projects_id' => $projectId,
            'name'        => mb_substr(trim((string) ($in['name'] ?? '')) ?: 'Sprint ' . (count($all) + 1), 0, 100),
            'goal'        => (string) ($in['goal'] ?? ''),
            'start_date'  => $start,
            'end_date'    => $end,
            'state'       => 'future',
        ]);
        return (int) $DB->insertId();
    }

    public static function update(int $projectId, int $sprintId, array $in): void
    {
        global $DB;
        self::find($projectId, $sprintId);
        $data = [];
        if (isset($in['name']) && trim($in['name']) !== '') {
            $data['name'] = mb_substr(trim($in['name']), 0, 100);
        }
        if (isset($in['goal'])) {
            $data['goal'] = (string) $in['goal'];
        }
        foreach (['start_date', 'end_date'] as $f) {
            if (isset($in[$f]) && ($d = self::date($in[$f]))) {
                $data[$f] = $d;
            }
        }
        if ($data) {
            $DB->update('glpi_plugin_pmo_sprints', $data, ['id' => $sprintId]);
        }
    }

    public static function start(int $projectId, int $sprintId, array $in): void
    {
        global $DB;
        $sprint = self::find($projectId, $sprintId);
        if ($sprint['state'] !== 'future') {
            throw new RuntimeException('Só uma sprint planejada pode ser iniciada.');
        }
        if (self::active($projectId)) {
            throw new RuntimeException('Já existe uma sprint em andamento. Encerre-a antes de iniciar outra.');
        }
        self::update($projectId, $sprintId, $in);
        [$total, , $ids] = self::totals($projectId, $sprintId);
        $DB->update('glpi_plugin_pmo_sprints', ['state' => 'active', 'committed' => $total], ['id' => $sprintId]);
        Notifications::sprintStarted($projectId, self::find($projectId, $sprintId), $total, count($ids), self::unit($projectId));
    }

    /**
     * Encerra a sprint. As pendentes vão para o backlog (0), para outra sprint
     * planejada ou para uma sprint nova (-1).
     */
    public static function close(int $projectId, int $sprintId, int $target): array
    {
        global $DB;
        $sprint = self::find($projectId, $sprintId);
        if ($sprint['state'] !== 'active') {
            throw new RuntimeException('Só a sprint em andamento pode ser encerrada.');
        }
        if ($target === -1) {
            $target = self::create($projectId);
        } elseif ($target > 0) {
            $next = self::find($projectId, $target);
            if ($next['state'] === 'closed') {
                throw new RuntimeException('A sprint de destino já foi encerrada.');
            }
        }
        [$total, $done, $ids, $pending] = self::totals($projectId, $sprintId);
        $DB->update('glpi_plugin_pmo_sprints', [
            'state'       => 'closed',
            'completed'   => $done,
            'committed'   => max($sprint['committed'], $total),
            'scope'       => json_encode($ids),
            'date_closed' => $_SESSION['glpi_currenttime'],
        ], ['id' => $sprintId]);
        if ($pending) {
            $DB->update('glpi_plugin_pmo_items', ['sprints_id' => max(0, $target)], ['projecttasks_id' => $pending]);
        }
        $result = ['done' => $done, 'total' => $total, 'moved' => count($pending), 'target' => $target];
        Notifications::sprintClosed($projectId, $sprint, $result, self::unit($projectId), $target > 0 ? self::find($projectId, $target)['name'] : 'o backlog');
        return $result;
    }

    public static function delete(int $projectId, int $sprintId): void
    {
        global $DB;
        $sprint = self::find($projectId, $sprintId);
        if ($sprint['state'] !== 'future') {
            throw new RuntimeException('Só sprints planejadas (não iniciadas) podem ser excluídas.');
        }
        $DB->update('glpi_plugin_pmo_items', ['sprints_id' => 0], ['sprints_id' => $sprintId]);
        $DB->delete('glpi_plugin_pmo_sprints', ['id' => $sprintId]);
    }

    /** [tamanho total, tamanho concluído, ids das tarefas, ids pendentes] da sprint. */
    public static function totals(int $projectId, int $sprintId): array
    {
        $config = ProjectConfig::get($projectId);
        $estimation = $config['estimation'] ?? 'points';
        $kinds = array_column(ProjectConfig::columns($projectId), 'kind', 'id');
        $total = $done = 0.0;
        $ids = $pending = [];
        foreach (Board::tasks($projectId) as $t) {
            if ((int) $t['sprints_id'] !== $sprintId || $t['children'] > 0) {
                continue;
            }
            $size = Board::size($t, $estimation);
            $total += $size;
            $ids[] = $t['id'];
            if (($kinds[(int) $t['columns_id']] ?? '') === 'done') {
                $done += $size;
            } else {
                $pending[] = $t['id'];
            }
        }
        return [$total, $done, $ids, $pending];
    }

    /** Unidade da estimativa do projeto, para os avisos. */
    private static function unit(int $projectId): string
    {
        return (ProjectConfig::get($projectId)['estimation'] ?? 'points') === 'hours' ? 'horas' : 'pontos';
    }

    private static function date($v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
