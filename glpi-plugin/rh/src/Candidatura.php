<?php

namespace GlpiPlugin\Rh;

use CommonDBTM;
use RuntimeException;
use Session;

/**
 * Candidato numa vaga: etapa do funil, nota, parecer, entrevista e proposta.
 * Cada mudança de etapa fica no histórico (tempo por etapa e linha do tempo).
 */
class Candidatura extends CommonDBTM
{
    public static string $rightname = 'plugin_rh_recrutamento';

    public const STAGES = [
        'triagem'    => ['Triagem', '#64748b', 'ti-filter'],
        'rh'         => ['Entrevista RH', '#0ea5e9', 'ti-messages'],
        'teste'      => ['Teste técnico', '#a855f7', 'ti-code'],
        'gestor'     => ['Entrevista com gestor', '#3b82f6', 'ti-user-check'],
        'proposta'   => ['Proposta', '#f59e0b', 'ti-file-dollar'],
        'contratado' => ['Contratado', '#22c55e', 'ti-confetti'],
    ];

    public const STATUSES = [
        'active'   => ['Em processo', '#3b82f6'],
        'hired'    => ['Contratado', '#22c55e'],
        'rejected' => ['Reprovado', '#ef4444'],
        'withdrew' => ['Desistiu', '#94a3b8'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Candidaturas' : 'Candidatura';
    }

    public static function stageBadge(string $stage): string
    {
        [$label, $color, $icon] = self::STAGES[$stage] ?? ['?', '#64748b', 'ti-help'];
        return Menu::badge($label, $color, $icon);
    }

    public static function statusBadge(string $status): string
    {
        [$label, $color] = self::STATUSES[$status] ?? ['?', '#64748b'];
        return Menu::badge($label, $color);
    }

    public static function stars(int $n): string
    {
        return "<span class='rh-stars' title='$n de 5'>" . str_repeat('★', $n) . "<span>" . str_repeat('★', 5 - $n) . "</span></span>";
    }

    private static function vaga(int $vagaId): Vaga
    {
        $v = new Vaga();
        if (!$v->getFromDB($vagaId) || !$v->canViewItem()) {
            throw new RuntimeException('Vaga não encontrada ou sem permissão.');
        }
        return $v;
    }

    private static function load(int $id, bool $manage = true): array
    {
        global $DB;
        $c = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        if (!$c) {
            throw new RuntimeException('Candidatura não encontrada.');
        }
        $v = self::vaga((int) $c['plugin_rh_vagas_id']);
        if ($manage && !$v->canManagePipeline()) {
            throw new RuntimeException('Você só pode ver este processo seletivo (ou a vaga não está aberta).');
        }
        return [$c, $v];
    }

    private static function history(int $id, ?string $from, string $to, string $status, string $comment = ''): void
    {
        global $DB;
        $DB->insert('glpi_plugin_rh_historicos', [
            'plugin_rh_candidaturas_id' => $id, 'from_stage' => $from, 'to_stage' => $to, 'status' => $status,
            'users_id' => (int) Session::getLoginUserID(), 'comment' => $comment, 'date' => $_SESSION['glpi_currenttime'],
        ]);
    }

    /** Dados do funil de uma vaga para o JavaScript. */
    public static function pipeline(int $vagaId): array
    {
        global $DB;
        $v = self::vaga($vagaId);
        $cards = [];
        $it = $DB->request([
            'SELECT'     => ['c.*', 'p.name', 'p.email', 'p.phone', 'p.linkedin', 'p.city', 'p.source', 'p.salary_expectation', 'p.picture'],
            'FROM'       => self::getTable() . ' AS c',
            'INNER JOIN' => [Candidato::getTable() . ' AS p' => ['ON' => ['p' => 'id', 'c' => 'plugin_rh_candidatos_id']]],
            'WHERE'      => ['c.plugin_rh_vagas_id' => $vagaId],
            'ORDER'      => ['c.rank', 'c.id'],
        ]);
        $ids = [];
        foreach ($it as $r) {
            $ids[] = (int) $r['id'];
            $cards[(int) $r['id']] = [
                'id'        => (int) $r['id'],
                'candidato' => (int) $r['plugin_rh_candidatos_id'],
                'name'      => (string) $r['name'],
                'initials'  => Menu::initials((string) $r['name']),
                'photo'     => Candidato::pictureUrl(['id' => $r['plugin_rh_candidatos_id'], 'picture' => $r['picture']]),
                'email'     => (string) $r['email'],
                'phone'     => (string) $r['phone'],
                'linkedin'  => (string) $r['linkedin'],
                'city'      => (string) $r['city'],
                'source'    => Candidato::SOURCES[$r['source']] ?? '',
                'expect'    => (float) $r['salary_expectation'],
                'stage'     => (string) $r['stage'],
                'status'    => (string) $r['status'],
                'rating'    => (int) $r['rating'],
                'notes'     => (string) $r['notes'],
                'interview' => $r['interview_date'] ? str_replace(' ', 'T', substr($r['interview_date'], 0, 16)) : '',
                'offer'     => (float) $r['offer_salary'],
                'start'     => (string) $r['start_date'],
                'days'      => $r['date_stage'] ? (int) floor((time() - strtotime($r['date_stage'])) / DAY_TIMESTAMP) : 0,
                'url'       => Candidato::getFormURLWithID((int) $r['plugin_rh_candidatos_id']),
                'history'   => [],
            ];
        }
        if ($ids) {
            foreach ($DB->request(['FROM' => 'glpi_plugin_rh_historicos', 'WHERE' => ['plugin_rh_candidaturas_id' => $ids], 'ORDER' => 'id']) as $h) {
                $cards[(int) $h['plugin_rh_candidaturas_id']]['history'][] = [
                    'from' => self::STAGES[$h['from_stage']][0] ?? '', 'to' => self::STAGES[$h['to_stage']][0] ?? '',
                    'status' => self::STATUSES[$h['status']][0] ?? '', 'user' => (string) getUserName((int) $h['users_id']),
                    'date' => \Html::convDateTime($h['date']), 'comment' => (string) $h['comment'],
                ];
            }
        }
        return [
            'vaga'    => ['id' => $vagaId, 'name' => $v->fields['name'], 'positions' => (int) $v->fields['positions'], 'hired' => $v->hiredCount(), 'status' => (int) $v->fields['status'],
                'statusName' => Vaga::STATUSES[(int) $v->fields['status']][0], 'salary' => [(float) $v->fields['salary_min'], (float) $v->fields['salary_max']]],
            'stages'  => array_map(fn ($k, $s) => ['key' => $k, 'name' => $s[0], 'color' => $s[1], 'icon' => $s[2]], array_keys(self::STAGES), self::STAGES),
            'cards'   => array_values($cards),
            'edit'    => $v->canManagePipeline(),
            'sources' => Candidato::SOURCES,
        ];
    }

    /** Candidatos do banco que ainda não estão na vaga (para adicionar). */
    public static function available(int $vagaId, string $q = ''): array
    {
        global $DB;
        self::vaga($vagaId);
        $where = ['is_deleted' => 0, ['NOT' => ['id' => new \Glpi\DBAL\QuerySubQuery(['SELECT' => 'plugin_rh_candidatos_id', 'FROM' => self::getTable(), 'WHERE' => ['plugin_rh_vagas_id' => $vagaId]])]]];
        if ($q !== '') {
            $where[] = ['OR' => ['name' => ['LIKE', "%$q%"], 'email' => ['LIKE', "%$q%"], 'city' => ['LIKE', "%$q%"]]];
        }
        $out = [];
        foreach ($DB->request(['FROM' => Candidato::getTable(), 'WHERE' => $where, 'ORDER' => 'name', 'LIMIT' => 30]) as $r) {
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'city' => (string) $r['city']];
        }
        return $out;
    }

    /** Põe um candidato (do banco ou novo) na triagem da vaga. */
    public static function addToVaga(int $vagaId, array $in): int
    {
        global $DB;
        $v = self::vaga($vagaId);
        if (!$v->canManagePipeline()) {
            throw new RuntimeException('A vaga precisa estar aberta e você precisa conduzir a seleção.');
        }
        $candId = (int) ($in['candidato'] ?? 0);
        if (!$candId) {
            if (!Vaga::isRH()) {
                throw new RuntimeException('Só o RH cadastra candidatos novos.');
            }
            $cand = new Candidato();
            $candId = (int) $cand->add([
                'name' => trim((string) ($in['name'] ?? '')), 'email' => trim((string) ($in['email'] ?? '')), 'phone' => (string) ($in['phone'] ?? ''),
                'linkedin' => (string) ($in['linkedin'] ?? ''), 'city' => (string) ($in['city'] ?? ''), 'source' => (string) ($in['source'] ?? 'outros'),
                'salary_expectation' => (string) ($in['expect'] ?? ''), 'entities_id' => (int) $v->fields['entities_id'], 'is_recursive' => 1,
            ]);
            if (!$candId) {
                throw new RuntimeException('Não foi possível cadastrar o candidato (confira o nome e o e-mail).');
            }
        }
        if (countElementsInTable(self::getTable(), ['plugin_rh_vagas_id' => $vagaId, 'plugin_rh_candidatos_id' => $candId])) {
            throw new RuntimeException('Esse candidato já está nesta vaga.');
        }
        $now = $_SESSION['glpi_currenttime'];
        $rank = (int) ($DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => self::getTable(), 'WHERE' => ['plugin_rh_vagas_id' => $vagaId]])->current()['m'] ?? 0) + 10;
        $DB->insert(self::getTable(), [
            'plugin_rh_vagas_id' => $vagaId, 'plugin_rh_candidatos_id' => $candId, 'stage' => 'triagem', 'status' => 'active', 'rank' => $rank,
            'date_stage' => $now, 'date_creation' => $now, 'date_mod' => $now,
        ]);
        $id = (int) $DB->insertId();
        self::history($id, null, 'triagem', 'active', 'Entrou no processo seletivo');
        return $id;
    }

    /**
     * Move para outra etapa. "Contratado" marca a contratação, cria a admissão
     * e encerra a vaga quando todas as posições forem preenchidas.
     * @return array{admissao: int, filled: bool}
     */
    public static function move(int $id, string $stage, array $order = []): array
    {
        global $DB;
        [$c, $v] = self::load($id);
        if (!isset(self::STAGES[$stage])) {
            throw new RuntimeException('Etapa inválida.');
        }
        if ($c['status'] !== 'active' && $c['status'] !== 'hired') {
            throw new RuntimeException('Reative o candidato antes de movê-lo.');
        }
        $now = $_SESSION['glpi_currenttime'];
        $result = ['admissao' => 0, 'filled' => false];
        if ($stage !== $c['stage']) {
            $data = ['stage' => $stage, 'date_stage' => $now, 'date_mod' => $now];
            if ($stage === 'contratado') {
                if ($v->hiredCount() >= (int) $v->fields['positions']) {
                    throw new RuntimeException('Todas as posições desta vaga já foram preenchidas.');
                }
                $data += ['status' => 'hired', 'date_hired' => $now];
            } elseif ($c['status'] === 'hired') {
                $data += ['status' => 'active', 'date_hired' => null];
            }
            $DB->update(self::getTable(), $data, ['id' => $id]);
            self::history($id, $c['stage'], $stage, $data['status'] ?? $c['status']);
            $name = self::candidateName((int) $c['plugin_rh_candidatos_id']);
            if ($stage === 'contratado') {
                Avisos::andamento($v, 'vaga_contratado', "Contratado: $name", "$name foi contratado(a) na vaga {$v->fields['name']}. A admissão foi criada.", 'success', [(int) $v->fields['users_id_manager']]);
                $result['admissao'] = Admissao::createFor($id);
                $v->getFromDB($v->getID());
                $result['filled'] = $v->checkFilled();
            } else {
                $from = self::STAGES[$c['stage']][0] ?? $c['stage'];
                $to = self::STAGES[$stage][0];
                Avisos::andamento($v, 'vaga_etapa', "$name: $to", "$name passou de $from para $to na vaga {$v->fields['name']}.", 'info', [(int) $v->fields['users_id_manager']], ['Candidato' => $name, 'Etapa' => $to]);
            }
        }
        foreach (array_values(array_map('intval', $order)) as $i => $cid) {
            $DB->update(self::getTable(), ['rank' => ($i + 1) * 10], ['id' => $cid, 'plugin_rh_vagas_id' => $v->getID()]);
        }
        return $result;
    }

    /** Nota, parecer, entrevista e proposta. */
    public static function saveDetails(int $id, array $in): void
    {
        global $DB;
        self::load($id);
        $data = ['date_mod' => $_SESSION['glpi_currenttime']];
        if (isset($in['rating'])) {
            $data['rating'] = max(0, min(5, (int) $in['rating']));
        }
        if (isset($in['notes'])) {
            $data['notes'] = (string) $in['notes'];
        }
        if (array_key_exists('interview', $in)) {
            $data['interview_date'] = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) $in['interview']) ? str_replace('T', ' ', $in['interview']) . ':00' : null;
        }
        if (isset($in['offer'])) {
            $data['offer_salary'] = max(0, Menu::parseMoney((string) $in['offer']));
        }
        if (array_key_exists('start', $in)) {
            $data['start_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in['start']) ? $in['start'] : null;
        }
        $DB->update(self::getTable(), $data, ['id' => $id]);
    }

    /** Reprovar, registrar desistência ou reativar. */
    public static function setStatus(int $id, string $status, string $comment): void
    {
        global $DB;
        [$c] = self::load($id);
        if (!in_array($status, ['rejected', 'withdrew', 'active'], true)) {
            throw new RuntimeException('Situação inválida.');
        }
        if ($status !== 'active' && trim($comment) === '') {
            throw new RuntimeException('Informe o motivo.');
        }
        if ($c['status'] === 'hired') {
            throw new RuntimeException('Candidato já contratado: mova-o para outra etapa antes.');
        }
        $DB->update(self::getTable(), ['status' => $status, 'date_mod' => $_SESSION['glpi_currenttime']], ['id' => $id]);
        self::history($id, $c['stage'], $c['stage'], $status, $comment);
        if ($status !== $c['status']) {
            $v = self::vaga((int) $c['plugin_rh_vagas_id']);
            $name = self::candidateName((int) $c['plugin_rh_candidatos_id']);
            [$title, $text] = match ($status) {
                'rejected' => ["$name reprovado(a)", "$name foi reprovado(a) na etapa " . (self::STAGES[$c['stage']][0] ?? '') . ". Motivo: $comment"],
                'withdrew' => ["$name desistiu", "$name desistiu do processo na etapa " . (self::STAGES[$c['stage']][0] ?? '') . ". Motivo: $comment"],
                default    => ["$name voltou ao processo", "$name foi reativado(a) na etapa " . (self::STAGES[$c['stage']][0] ?? '') . '.'],
            };
            Avisos::andamento($v, 'vaga_candidato', $title, $text, 'info', [(int) $v->fields['users_id_manager']], ['Candidato' => $name]);
        }
    }

    private static function candidateName(int $candidatoId): string
    {
        $c = new Candidato();
        return $c->getFromDB($candidatoId) ? (string) $c->fields['name'] : 'Candidato';
    }
}
