<?php

namespace GlpiPlugin\Pmo;

/**
 * Registro de riscos e pendências (issues) do projeto, com matriz 5×5.
 */
class Risk
{
    public const KINDS = ['risk' => 'Risco', 'issue' => 'Pendência / problema'];
    public const STATUSES = ['open' => 'Aberto', 'mitigating' => 'Em tratamento', 'closed' => 'Encerrado'];
    public const SCALE = [1 => 'Muito baixo', 2 => 'Baixo', 3 => 'Médio', 4 => 'Alto', 5 => 'Muito alto'];

    public static function all(int $projectId): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_pmo_risks', 'WHERE' => ['projects_id' => $projectId], 'ORDER' => ['status', 'id DESC']]) as $r) {
            $r['score'] = (int) $r['probability'] * (int) $r['impact'];
            $rows[] = $r;
        }
        usort($rows, fn ($a, $b) => [($a['status'] === 'closed'), -$a['score']] <=> [($b['status'] === 'closed'), -$b['score']]);
        return $rows;
    }

    /** Nível pela pontuação P×I (1–25). */
    public static function level(int $score): array
    {
        return match (true) {
            $score >= 15 => ['Crítico', 'danger'],
            $score >= 8  => ['Alto', 'orange'],
            $score >= 4  => ['Moderado', 'warning'],
            default      => ['Baixo', 'success'],
        };
    }

    public static function save(int $projectId, array $in): int
    {
        global $DB;
        $data = [
            'kind'        => isset(self::KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'risk',
            'name'        => mb_substr(trim((string) ($in['name'] ?? '')) ?: 'Sem título', 0, 255),
            'description' => (string) ($in['description'] ?? ''),
            'probability' => max(1, min(5, (int) ($in['probability'] ?? 3))),
            'impact'      => max(1, min(5, (int) ($in['impact'] ?? 3))),
            'users_id'    => max(0, (int) ($in['users_id'] ?? 0)),
            'response'    => (string) ($in['response'] ?? ''),
            'status'      => isset(self::STATUSES[$in['status'] ?? '']) ? $in['status'] : 'open',
            'due_date'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['due_date'] ?? '')) ? $in['due_date'] : null,
            'date_mod'    => $_SESSION['glpi_currenttime'],
        ];
        $id = (int) ($in['id'] ?? 0);
        if ($id && count($DB->request(['FROM' => 'glpi_plugin_pmo_risks', 'WHERE' => ['id' => $id, 'projects_id' => $projectId]]))) {
            $DB->update('glpi_plugin_pmo_risks', $data, ['id' => $id]);
            return $id;
        }
        $DB->insert('glpi_plugin_pmo_risks', $data + ['projects_id' => $projectId, 'date_creation' => $_SESSION['glpi_currenttime']]);
        return (int) $DB->insertId();
    }

    public static function delete(int $projectId, int $id): void
    {
        global $DB;
        $DB->delete('glpi_plugin_pmo_risks', ['id' => $id, 'projects_id' => $projectId]);
    }
}
