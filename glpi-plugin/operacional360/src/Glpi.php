<?php

namespace GlpiPlugin\Operacional360;

/**
 * Leituras do banco do GLPI (só SELECT, pela API de banco do próprio GLPI, com as
 * permissões do GLPI já aplicadas pelo chamador: entidades liberadas ao usuário).
 */
class Glpi
{
    /** Status do chamado (GLPI 11/12). */
    public const STATUS = [1 => 'Novo', 2 => 'Em atendimento', 3 => 'Planejado', 4 => 'Pendente', 5 => 'Solucionado', 6 => 'Fechado'];

    /** Prioridade do chamado (GLPI 11/12). */
    public const PRIORITY = [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta', 6 => 'Crítica'];

    private static ?array $entities = null;
    private static ?array $categories = null;
    private static ?array $groups = null;

    /** id => nome completo da entidade ("Empresa > Filial > Setor"). */
    public static function entities(): array
    {
        if (self::$entities === null) {
            global $DB;
            self::$entities = [];
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities']) as $r) {
                self::$entities[(int) $r['id']] = (string) $r['completename'];
            }
        }
        return self::$entities;
    }

    public static function entityName(int $id): string
    {
        return self::entities()[$id] ?? '';
    }

    /** Entidade e todas as filhas (pelo nome completo). */
    public static function subtree(int $root): array
    {
        $name = self::entityName($root);
        if ($name === '') {
            return [];
        }
        $ids = [];
        foreach (self::entities() as $id => $full) {
            if ($full === $name || str_starts_with($full, $name . ' > ')) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Nome do "cliente" de um chamado dentro da entidade do painel: a filha direta
     * da entidade do painel que contém o chamado (ou o próprio nome da entidade do painel).
     */
    public static function clientOf(string $fullName, string $root): string
    {
        $rootShort = self::lastSegment($root);
        if ($fullName === $root || !str_starts_with($fullName, $root . ' > ')) {
            return $rootShort;
        }
        $rest = substr($fullName, strlen($root) + 3);
        return explode(' > ', $rest)[0];
    }

    /** Entidades filhas diretas da entidade do painel (nomes), para o painel de clientes. */
    public static function directChildren(int $root): array
    {
        $name = self::entityName($root);
        $out = [];
        foreach (self::subtree($root) as $id) {
            $full = self::entityName($id);
            if ($full !== $name && str_starts_with($full, $name . ' > ')) {
                $first = explode(' > ', substr($full, strlen($name) + 3))[0];
                $out[$first] = true;
            }
        }
        $names = array_keys($out);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    public static function lastSegment(string $full): string
    {
        $parts = explode(' > ', $full);
        return (string) end($parts);
    }

    /** id => nome completo da categoria de chamado. */
    public static function categories(): array
    {
        if (self::$categories === null) {
            global $DB;
            self::$categories = [];
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories']) as $r) {
                self::$categories[(int) $r['id']] = (string) $r['completename'];
            }
        }
        return self::$categories;
    }

    /** id => nome completo do grupo de técnicos. */
    public static function groups(): array
    {
        if (self::$groups === null) {
            global $DB;
            self::$groups = [];
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups']) as $r) {
                self::$groups[(int) $r['id']] = (string) $r['completename'];
            }
        }
        return self::$groups;
    }

    /** id => nome do usuário ("Nome Sobrenome" ou o login). */
    public static function userNames(array $ids): array
    {
        global $DB;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            if (!$chunk) {
                continue;
            }
            foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $chunk]]) as $u) {
                $full = trim((string) $u['firstname'] . ' ' . (string) $u['realname']);
                $out[(int) $u['id']] = $full !== '' ? $full : (string) $u['name'];
            }
        }
        return $out;
    }

    /**
     * Chamados das entidades dadas. Com $open, os não solucionados/fechados (sem
     * período); senão, os abertos no período. Já vêm com cliente, grupo e técnico.
     */
    public static function tickets(array $entityIds, int $from, int $till, bool $open): array
    {
        global $DB;
        if (!$entityIds) {
            return [];
        }
        $where = ['is_deleted' => 0, 'entities_id' => $entityIds];
        if ($open) {
            $where['status'] = ['<', 5];
        } else {
            $where['AND'] = [
                ['date' => ['>=', date('Y-m-d H:i:s', $from)]],
                ['date' => ['<=', date('Y-m-d H:i:s', $till)]],
            ];
        }
        $rows = [];
        $max = max(1, (int) Settings::get('glpi_max_tickets'));
        foreach ($DB->request(['FROM' => 'glpi_tickets', 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => $max]) as $r) {
            $rows[] = $r;
        }
        return self::enrich($rows);
    }

    /** Um chamado pelo número, só se estiver nas entidades dadas. */
    public static function ticketIn(int $id, array $entityIds): ?array
    {
        global $DB;
        if (!$entityIds) {
            return null;
        }
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_tickets', 'WHERE' => ['id' => $id, 'entities_id' => $entityIds, 'is_deleted' => 0]]) as $r) {
            $rows[] = $r;
        }
        $out = self::enrich($rows);
        return $out[0] ?? null;
    }

    /** Acrescenta entidade, categoria, grupo e técnico (uma consulta para cada tipo, em lote). */
    private static function enrich(array $rows): array
    {
        global $DB;
        if (!$rows) {
            return [];
        }
        $entities = self::entities();
        $categories = self::categories();
        $groupNames = self::groups();
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);

        $groupOf = [];
        $techOf = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $chunk, 'type' => 2]]) as $g) {
                $groupOf[(int) $g['tickets_id']][] = $groupNames[(int) $g['groups_id']] ?? '';
            }
            foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $chunk, 'type' => 2]]) as $u) {
                $techOf[(int) $u['tickets_id']][] = (int) $u['users_id'];
            }
        }
        $allTechs = [];
        foreach ($techOf as $list) {
            array_push($allTechs, ...$list);
        }
        $names = self::userNames($allTechs);

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $techs = [];
            foreach ($techOf[$id] ?? [] as $uid) {
                $techs[] = $names[$uid] ?? ('#' . $uid);
            }
            $out[] = [
                'id'                        => $id,
                'name'                      => (string) $r['name'],
                'status'                    => (int) $r['status'],
                'priority'                  => (int) $r['priority'],
                'urgency'                   => (int) $r['urgency'],
                'type'                      => (int) $r['type'],
                'date'                      => (string) $r['date'],
                'solvedate'                 => $r['solvedate'] ?: null,
                'time_to_resolve'           => $r['time_to_resolve'] ?: null,
                'solve_delay_stat'          => (int) $r['solve_delay_stat'],
                'takeintoaccount_delay_stat' => (int) $r['takeintoaccount_delay_stat'],
                'entity'                    => $entities[(int) $r['entities_id']] ?? '',
                'category'                  => $categories[(int) ($r['itilcategories_id'] ?? 0)] ?? '',
                'group'                     => implode(', ', array_filter($groupOf[$id] ?? [])),
                'tech'                      => implode(', ', $techs),
            ];
        }
        return $out;
    }

    /** Texto (HTML) do conteúdo do chamado, para achar o evento do Zabbix citado nele. */
    public static function ticketContent(int $id): string
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['content'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $id]]) as $r) {
            return (string) $r['content'];
        }
        return '';
    }

    /**
     * Histórico do chamado: abertura, acompanhamentos, tarefas e solução, em ordem de data.
     * @return array<int, array{kind:string,date:string,author:string,private:bool,text:string}>
     */
    public static function history(int $id): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['date', 'content', 'users_id_recipient'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $id]]) as $t) {
            $names = self::userNames([(int) $t['users_id_recipient']]);
            $out[] = ['kind' => 'abertura', 'date' => (string) $t['date'], 'author' => $names[(int) $t['users_id_recipient']] ?? '', 'private' => false, 'text' => (string) $t['content']];
        }
        $sources = [
            ['acompanhamento', 'glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $id], 'date_creation', 'is_private'],
            ['tarefa', 'glpi_tickettasks', ['tickets_id' => $id], 'date', 'is_private'],
            ['solução', 'glpi_itilsolutions', ['itemtype' => 'Ticket', 'items_id' => $id], 'date_creation', null],
        ];
        foreach ($sources as [$kind, $table, $where, $dateCol, $privateCol]) {
            $rows = [];
            foreach ($DB->request(['FROM' => $table, 'WHERE' => $where]) as $r) {
                $rows[] = $r;
            }
            $names = self::userNames(array_map(fn ($r) => (int) $r['users_id'], $rows));
            foreach ($rows as $r) {
                $out[] = [
                    'kind'    => $kind,
                    'date'    => (string) $r[$dateCol],
                    'author'  => $names[(int) $r['users_id']] ?? '',
                    'private' => $privateCol ? (bool) $r[$privateCol] : false,
                    'text'    => (string) $r['content'],
                ];
            }
        }
        usort($out, fn ($x, $y) => strcmp((string) $x['date'], (string) $y['date']));
        return $out;
    }
}
