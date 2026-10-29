<?php

namespace GlpiPlugin\Sistemasinternos;

use Throwable;

/**
 * Parte do GLPI do dashboard Sistemas Internos 360 (o que antes vinha do painel do
 * GLPI na porta 8091), lida direto do banco do GLPI: chamados da entidade do
 * departamento (e filhas), interações de um chamado e inventário de softwares.
 */
class Si360Glpi
{
    private const OPEN = [1, 2, 3, 4];
    private const STATUS = [1 => 'Novo', 2 => 'Em atendimento (atribuído)', 3 => 'Em atendimento (planejado)', 4 => 'Pendente', 5 => 'Solucionado', 6 => 'Fechado'];
    private const PRIO = [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta', 6 => 'Crítica'];
    private const TIPO = [1 => 'Incidente', 2 => 'Requisição'];
    public const STALE_DAYS = 3;

    public static function route(string $path, array $q): array
    {
        $parts = explode('/', trim($path, '/'));
        return match ($parts[0] ?? '') {
            'config'    => ['groups' => Settings::get('groups'), 'title' => Settings::get('title'), 'entity' => self::entityName(), 'stale_days' => self::STALE_DAYS],
            'glpi'      => match ($parts[1] ?? '') {
                ''       => self::summary((float) ($q['from'] ?? 0) ?: time() - 30 * 86400, (float) ($q['to'] ?? 0) ?: time(), (string) ($q['bucket'] ?? 'day')),
                'list'   => self::listing($q),
                'latest' => self::latest(),
                default  => throw new NotFound('rota desconhecida'),
            },
            'timeline'  => self::timeline((int) ($parts[1] ?? 0)),
            'inventario' => self::inventory(),
            'img'       => ['files' => self::images()],
            'ia'        => ['zabbix' => [], 'glpi' => [], 'gerado' => Si360::obj([])], // a OAK IA roda fora do GLPI
            'book'      => Si360Book::route(array_slice($parts, 1), $q),
            default     => throw new NotFound('rota desconhecida'),
        };
    }

    private static function glpiUrl(): string
    {
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
    }

    private static function entityName(): string
    {
        $id = (int) Settings::get('entities_id');
        $e = new \Entity();
        return $e->getFromDB($id) ? mb_strtoupper((string) $e->fields['completename']) : '';
    }

    private static function ts(?string $s): ?float
    {
        if (!$s) {
            return null;
        }
        $t = strtotime(substr($s, 0, 19));
        return $t === false ? null : (float) $t;
    }

    /** Prestadores de serviço, como foram digitados (o SLA do departamento não conta os chamados deles). */
    private static function prestadores(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', Settings::get('prestadores')))));
    }

    private static function isPrest(array $t): bool
    {
        return (bool) array_intersect(array_map('mb_strtolower', $t['au']), array_map('mb_strtolower', self::prestadores()));
    }

    /** Nome do técnico como "Nome Sobrenome" (o mesmo usado em Prestadores de serviço). */
    private static function userNames(array $ids): array
    {
        global $DB;
        $out = [];
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => array_values(array_unique($ids))]]) as $u) {
                $nm = trim(implode(' ', array_filter([(string) $u['firstname'], (string) $u['realname']])));
                $out[(int) $u['id']] = $nm !== '' ? $nm : (string) $u['name'];
            }
        }
        return $out;
    }

    /** Chamados da entidade do departamento (cache de 60 s, para todos). */
    private static function data(): array
    {
        return Si360::cached('glpi-data|' . Settings::get('entities_id'), 60, function () {
            global $DB;
            $ents = Painel::entities();
            $rows = [];
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'status', 'type', 'priority', 'itilcategories_id', 'entities_id', 'date', 'solvedate', 'date_mod',
                    'takeintoaccountdate', 'time_to_resolve', 'time_to_own'],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['is_deleted' => 0, 'entities_id' => $ents],
            ]) as $t) {
                $rows[(int) $t['id']] = $t + ['au' => [], 'ag' => []];
            }
            $users = $groups = [];
            if ($rows) {
                foreach (array_chunk(array_keys($rows), 2000) as $part) {
                    foreach ($DB->request(['SELECT' => ['tickets_id', 'users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['type' => 2, 'tickets_id' => $part]]) as $r) {
                        $rows[(int) $r['tickets_id']]['au'][] = (int) $r['users_id'];
                        $users[] = (int) $r['users_id'];
                    }
                    foreach ($DB->request(['SELECT' => ['tickets_id', 'groups_id'], 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['type' => 2, 'tickets_id' => $part]]) as $r) {
                        $rows[(int) $r['tickets_id']]['ag'][] = (int) $r['groups_id'];
                        $groups[] = (int) $r['groups_id'];
                    }
                }
            }
            $names = self::userNames($users);
            $complete = function (string $table, array $ids) use ($DB) {
                $out = [];
                if ($ids) {
                    foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => $table, 'WHERE' => ['id' => array_values(array_unique($ids))]]) as $r) {
                        $out[(int) $r['id']] = (string) $r['completename'];
                    }
                }
                return $out;
            };
            $gnames = $complete('glpi_groups', $groups);
            $cats = $complete('glpi_itilcategories', array_column($rows, 'itilcategories_id'));
            $entn = $complete('glpi_entities', array_column($rows, 'entities_id'));
            $out = [];
            foreach ($rows as $t) {
                $c = (int) $t['itilcategories_id'];
                $catFull = $c ? ($cats[$c] ?? (string) $c) : 'Sem categoria';
                $out[] = ['id' => (int) $t['id'], 'n' => (string) $t['name'], 's' => (int) $t['status'], 'ty' => (int) $t['type'], 'p' => (int) $t['priority'],
                    'cat_full' => $catFull, 'cat' => $c ? (string) array_slice(explode(' > ', $catFull), -1)[0] : 'Sem categoria',
                    'ent' => $entn[(int) $t['entities_id']] ?? '',
                    'D' => self::ts($t['date']), 'SD' => self::ts($t['solvedate']), 'M' => self::ts($t['date_mod']), 'TK' => self::ts($t['takeintoaccountdate']),
                    'TTR' => self::ts($t['time_to_resolve']), 'TTO' => self::ts($t['time_to_own']),
                    'au' => array_map(fn ($u) => $names[$u] ?? "#$u", $t['au']),
                    'ag' => array_map(fn ($g) => (string) array_slice(explode(' > ', $gnames[$g] ?? (string) $g), -1)[0], $t['ag'])];
            }
            return ['tickets' => $out, 'glpi_url' => self::glpiUrl(), 'generated' => date('Y-m-d H:i:s')];
        }, false);
    }

    private static function idle(array $t, float $now): float
    {
        return ($now - ($t['M'] ?: $t['D'] ?: $now)) / 86400;
    }

    private static function brief(array $t, float $now): array
    {
        $open = in_array($t['s'], self::OPEN, true);
        return ['id' => $t['id'], 'n' => $t['n'], 's' => $t['s'], 'status' => self::STATUS[$t['s']] ?? '?', 'D' => $t['D'], 'SD' => $t['SD'], 'M' => $t['M'],
            'TTR' => $t['TTR'], 'cat' => $t['cat'], 'au' => $t['au'], 'ag' => $t['ag'], 'parado' => $open ? round(self::idle($t, $now), 1) : null,
            'atrasado' => (bool) ($t['TTR'] && $open && $now > $t['TTR']), 'prest' => self::isPrest($t),
            'p' => $t['p'], 'prioridade' => self::PRIO[$t['p']] ?? '—', 'tipo_ch' => self::TIPO[$t['ty']] ?? '—', 'TK' => $t['TK']];
    }

    private static function summary(float $frm, float $to, string $bucket): array
    {
        $g = self::data();
        $T = $g['tickets'];
        $now = (float) time();
        $to = min($to, $now);
        $opened = array_values(array_filter($T, fn ($t) => $t['D'] && $frm <= $t['D'] && $t['D'] <= $to));
        $solved = array_values(array_filter($T, fn ($t) => $t['SD'] && $frm <= $t['SD'] && $t['SD'] <= $to));
        $op = array_values(array_filter($T, fn ($t) => in_array($t['s'], self::OPEN, true)));
        $stale = array_filter($op, fn ($t) => self::idle($t, $now) >= self::STALE_DAYS);
        $sla = function (array $arr, string $due, callable $ref) {
            $n = $b = 0;
            foreach ($arr as $t) {
                if (!$t[$due] || ($r = $ref($t)) === null) {
                    continue;
                }
                $n++;
                $b += $r > $t[$due] ? 1 : 0;
            }
            return ['n' => $n, 'violados' => $b, 'pct' => $n ? round(100 * ($n - $b) / $n, 1) : null];
        };
        $interno = array_filter($opened, fn ($t) => !self::isPrest($t));
        $ttr = $sla($interno, 'TTR', fn ($t) => $t['SD'] ?: $now);
        $tto = $sla($interno, 'TTO', fn ($t) => $t['TK'] ?: ($t['s'] === 1 ? $now : $t['SD']));
        $lead = array_map(fn ($t) => ($t['SD'] - $t['D']) / 3600, array_filter($solved, fn ($t) => $t['D']));
        sort($lead);
        $med = $lead ? $lead[intdiv(count($lead), 2)] : null;
        $count = function (array $arr, callable $key, string $empty, int $top = 8) {
            $c = [];
            foreach ($arr as $t) {
                foreach ($key($t) ?: [$empty] as $k) {
                    $c[$k] = ($c[$k] ?? 0) + 1;
                }
            }
            arsort($c);
            return array_map(fn ($k, $v) => [(string) $k, $v], array_slice(array_keys($c), 0, $top), array_slice(array_values($c), 0, $top));
        };
        $step = $bucket === 'hour' ? 3600 : 86400;
        $series = [];
        for ($a = $step === 3600 ? $frm - fmod($frm, $step) : $frm; $a < $to; $a += $step) {
            $b = $a + $step;
            $series[] = ['from' => $a, 'to' => $b, 'abertos' => count(array_filter($opened, fn ($t) => $a <= $t['D'] && $t['D'] < $b)),
                'solucionados' => count(array_filter($solved, fn ($t) => $a <= $t['SD'] && $t['SD'] < $b))];
        }
        $abertos = $op;
        usort($abertos, fn ($x, $y) => self::idle($y, $now) <=> self::idle($x, $now));
        $ev = array_merge(array_map(fn ($t) => ['t' => $t['D'], 'tipo' => 'aberto'] + self::brief($t, $now), $opened),
            array_map(fn ($t) => ['t' => $t['SD'], 'tipo' => 'solucionado'] + self::brief($t, $now), $solved));
        usort($ev, fn ($x, $y) => $y['t'] <=> $x['t']);
        return [
            'glpi_url' => $g['glpi_url'], 'generated' => $g['generated'], 'entidade' => self::entityName(),
            'kpis' => ['abertos_agora' => count($op), 'novos' => count(array_filter($op, fn ($t) => $t['s'] === 1)),
                'pendentes' => count(array_filter($op, fn ($t) => $t['s'] === 4)), 'abertos_periodo' => count($opened),
                'solucionados_periodo' => count($solved), 'parados' => count($stale), 'sem_responsavel' => count(array_filter($op, fn ($t) => !$t['au'])),
                'sla_atrasados_agora' => count(array_filter($op, fn ($t) => $t['TTR'] && $now > $t['TTR'] && !self::isPrest($t))),
                'sla_solucao' => $ttr, 'sla_atendimento' => $tto, 'lead_mediano_h' => $med !== null ? round($med, 1) : null],
            'prestadores' => self::prestSummary($op, $opened, $solved, $now),
            'por_tecnico' => $count($op, fn ($t) => $t['au'], '(sem responsável)'),
            'por_grupo' => $count($op, fn ($t) => $t['ag'], '(sem grupo)'),
            'categorias' => $count($opened, fn ($t) => [$t['cat']], '(sem grupo)'),
            'por_status' => array_map(fn ($k) => [self::STATUS[$k], count(array_filter($op, fn ($t) => $t['s'] === $k))], [1, 2, 3, 4]),
            'series' => $series, 'bucket' => $step === 3600 ? 'hour' : 'day',
            'abertos' => array_map(fn ($t) => self::brief($t, $now), $abertos),
            'eventos' => array_slice($ev, 0, 60),
        ];
    }

    private static function prestSummary(array $op, array $opened, array $solved, float $now): array
    {
        $bloco = function (callable $f) use ($op, $opened, $solved, $now) {
            $o = array_filter($op, $f);
            $ab = array_filter($opened, $f);
            $comSla = array_filter($ab, fn ($t) => $t['TTR']);
            $viol = array_filter($comSla, fn ($t) => ($t['SD'] ?: $now) > $t['TTR']);
            return ['em_aberto' => count($o), 'abertos_periodo' => count($ab), 'solucionados_periodo' => count(array_filter($solved, $f)),
                'sla_atrasado_agora' => count(array_filter($o, fn ($t) => $t['TTR'] && $now > $t['TTR'])),
                'parados' => count(array_filter($o, fn ($t) => self::idle($t, $now) >= self::STALE_DAYS)),
                'mais_antigo_dias' => round(max(array_merge([0], array_map(fn ($t) => self::idle($t, $now), $o))), 1),
                'sla_solucao' => ['n' => count($comSla), 'violados' => count($viol), 'pct' => $comSla ? round(100 * (count($comSla) - count($viol)) / count($comSla), 1) : null]];
        };
        return ['nomes' => self::prestadores(), 'total' => $bloco(fn ($t) => self::isPrest($t)),
            'por_prestador' => array_map(fn ($n) => ['nome' => $n] + $bloco(fn ($t) => in_array(mb_strtolower($n), array_map('mb_strtolower', $t['au']), true)), self::prestadores())];
    }

    private static function full(array $t, float $now): array
    {
        $open = in_array($t['s'], self::OPEN, true);
        $prazo = function (?float $due, ?float $done) use ($now) {
            if (!$due) {
                return ['txt' => 'sem SLA', 'cls' => ''];
            }
            if ($done) {
                return ['txt' => $done <= $due ? 'cumprido' : 'violado', 'cls' => $done <= $due ? 'ok' : 'late'];
            }
            return ['txt' => $now > $due ? 'atrasado' : 'no prazo', 'cls' => $now > $due ? 'late' : 'ok'];
        };
        $ttoDone = $t['TK'] ?: ($t['s'] === 1 ? null : $t['SD']);
        return ['id' => $t['id'], 'n' => $t['n'], 's' => $t['s'], 'status' => self::STATUS[$t['s']] ?? '?', 'tipo' => self::TIPO[$t['ty']] ?? '—',
            'prioridade' => self::PRIO[$t['p']] ?? '—', 'p' => $t['p'], 'cat' => $t['cat'], 'cat_full' => $t['cat_full'] ?: $t['cat'], 'ent' => $t['ent'],
            'au' => $t['au'], 'ag' => $t['ag'], 'D' => $t['D'], 'M' => $t['M'], 'SD' => $t['SD'], 'TK' => $t['TK'], 'TTR' => $t['TTR'], 'TTO' => $t['TTO'],
            'parado' => $open ? round(self::idle($t, $now), 1) : null, 'aberto' => $open,
            'lead_h' => $t['SD'] && $t['D'] ? round(($t['SD'] - $t['D']) / 3600, 1) : null,
            'sla_ttr' => $prazo($t['TTR'], $t['SD']), 'sla_tto' => $prazo($t['TTO'], $ttoDone), 'prest' => self::isPrest($t)];
    }

    /** Chamados por trás de cada número/gráfico do GLPI na página. */
    private static function listing(array $a): array
    {
        $g = self::data();
        $T = $g['tickets'];
        $now = (float) time();
        $frm = (float) ($a['from'] ?? 0) ?: $now - 30 * 86400;
        $to = min((float) ($a['to'] ?? 0) ?: $now, $now);
        $kind = (string) ($a['list'] ?? 'em_aberto');
        $v = (string) ($a['v'] ?? '');
        $op = array_filter($T, fn ($t) => in_array($t['s'], self::OPEN, true));
        $opened = array_filter($T, fn ($t) => $t['D'] && $frm <= $t['D'] && $t['D'] <= $to);
        $solved = array_filter($T, fn ($t) => $t['SD'] && $frm <= $t['SD'] && $t['SD'] <= $to);
        $hasAu = fn ($t) => $v === '' || in_array($v, $t['au'], true);
        $sel = match ($kind) {
            'em_aberto'            => $op,
            'abertos_periodo'      => $opened,
            'solucionados_periodo' => $solved,
            'janela'               => array_column(array_merge($opened, $solved), null, 'id'),
            'parados'              => array_filter($op, fn ($t) => self::idle($t, $now) >= self::STALE_DAYS),
            'sem_resp'             => array_filter($op, fn ($t) => !$t['au']),
            'sla_violado'          => array_filter($opened, fn ($t) => $t['TTR'] && ($t['SD'] ?: $now) > $t['TTR'] && !self::isPrest($t)),
            'sla_tto_violado'      => array_filter($opened, fn ($t) => $t['TTO'] && (($t['TK'] ?: ($t['s'] === 1 ? $now : $t['SD'])) ?: 0) > $t['TTO'] && !self::isPrest($t)),
            'sla_atrasado'         => array_filter($op, fn ($t) => $t['TTR'] && $now > $t['TTR'] && !self::isPrest($t)),
            'prest_em_aberto'      => array_filter($op, fn ($t) => self::isPrest($t) && $hasAu($t)),
            'prest_periodo'        => array_filter($opened, fn ($t) => self::isPrest($t) && $hasAu($t)),
            'prest_solucionados'   => array_filter($solved, fn ($t) => self::isPrest($t) && $hasAu($t)),
            'prest_sla_atrasado'   => array_filter($op, fn ($t) => self::isPrest($t) && $t['TTR'] && $now > $t['TTR'] && $hasAu($t)),
            'tecnico'              => array_filter($op, fn ($t) => in_array($v, $t['au'], true) || ($v === '(sem responsável)' && !$t['au'])),
            'grupo'                => array_filter($op, fn ($t) => in_array($v, $t['ag'], true) || ($v === '(sem grupo)' && !$t['ag'])),
            'status'               => array_filter($op, fn ($t) => (string) $t['s'] === $v || (self::STATUS[$t['s']] ?? '') === $v),
            'categoria'            => array_filter($opened, fn ($t) => $t['cat'] === $v),
            'ticket'               => array_filter($T, fn ($t) => (string) $t['id'] === $v),
            default                => null,
        };
        if ($sel === null) {
            throw new NotFound('lista desconhecida');
        }
        $items = array_values(array_map(fn ($t) => self::full($t, $now), $sel));
        return ['items' => $items, 'total' => count($items), 'glpi_url' => $g['glpi_url']];
    }

    /** Chamados novos da entidade (para o aviso e a voz). */
    private static function latest(): array
    {
        global $DB;
        $out = [];
        $ents = [];
        foreach ($DB->request(['SELECT' => ['t.id', 't.name', 't.date', 't.type', 'e.completename'], 'FROM' => 'glpi_tickets AS t',
            'LEFT JOIN' => ['glpi_entities AS e' => ['ON' => ['t' => 'entities_id', 'e' => 'id']]],
            'WHERE' => ['t.is_deleted' => 0, 't.entities_id' => Painel::entities()], 'ORDER' => ['t.id DESC'], 'LIMIT' => 20]) as $t) {
            $out[] = ['id' => (int) $t['id'], 'n' => (string) $t['name'], 'e' => (string) $t['completename'], 'd' => $t['date'], 'ty' => (int) $t['type']];
        }
        return ['tickets' => $out];
    }

    // ------------------------------------------------------------------ interações do chamado

    private static function text(?string $s): string
    {
        $s = html_entity_decode(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $s);
        $s = preg_replace('#<img[^>]*>#i', '[imagem]', $s);
        $s = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</tr>|</h\d>#i', "\n", $s);
        $s = preg_replace('#<li[^>]*>#i', '• ', $s);
        $s = strip_tags($s);
        $s = preg_replace("/[ \t\x{a0}]+/u", ' ', $s);
        $s = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $s);
        return trim($s);
    }

    /** Descrição, acompanhamentos, tarefas e solução (só de chamados do departamento). */
    private static function timeline(int $tid): array
    {
        global $DB;
        $ok = false;
        foreach (self::data()['tickets'] as $t) {
            if ($t['id'] === $tid) {
                $ok = true;
                break;
            }
        }
        $ticket = new \Ticket();
        if (!$ok || !$ticket->getFromDB($tid)) {
            throw new NotFound('chamado fora deste painel');
        }
        $uids = [(int) $ticket->fields['users_id_recipient']];
        $raw = [];
        foreach ($DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid]]) as $x) {
            $raw[] = ['acompanhamento', $x];
            $uids[] = (int) $x['users_id'];
        }
        foreach ($DB->request(['FROM' => 'glpi_tickettasks', 'WHERE' => ['tickets_id' => $tid]]) as $x) {
            $raw[] = ['tarefa', $x];
            $uids[] = (int) $x['users_id'];
            $uids[] = (int) $x['users_id_tech'];
        }
        foreach ($DB->request(['FROM' => 'glpi_itilsolutions', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid]]) as $x) {
            $raw[] = ['solucao', $x];
            $uids[] = (int) $x['users_id'];
        }
        $names = self::userNames(array_filter($uids));
        $name = fn ($uid) => $uid ? ($names[(int) $uid] ?? "#$uid") : '—';
        $items = [['tipo' => 'abertura', 'quando' => $ticket->fields['date'], 'autor' => $name($ticket->fields['users_id_recipient']),
            'texto' => self::text($ticket->fields['content']), 'privado' => false]];
        foreach ($raw as [$tipo, $x]) {
            $it = ['tipo' => $tipo, 'quando' => $x['date'] ?? $x['date_creation'] ?? null, 'autor' => $name(($x['users_id'] ?? 0) ?: ($x['users_id_tech'] ?? 0)),
                'texto' => self::text($x['content'] ?? ''), 'privado' => (bool) ($x['is_private'] ?? false)];
            if ($tipo === 'tarefa') {
                $it += ['duracao' => (int) $x['actiontime'], 'estado' => [0 => '', 1 => 'a fazer', 2 => 'feita'][(int) $x['state']] ?? '', 'tecnico' => $name($x['users_id_tech'])];
            }
            if ($tipo === 'solucao') {
                $it['aprovacao'] = [1 => '', 2 => 'aguardando aprovação', 3 => 'aprovada', 4 => 'recusada'][(int) $x['status']] ?? '';
            }
            $items[] = $it;
        }
        usort($items, fn ($a, $b) => strcmp((string) $a['quando'], (string) $b['quando']));
        return ['id' => $tid, 'itens' => $items, 'lido' => time()];
    }

    // ------------------------------------------------------------------ inventário de softwares (botão Vulnerabilidades)

    private static function inventory(): array
    {
        return Si360::cached('inventario|' . Settings::get('entities_id'), 900, function () {
            global $DB;
            $ents = Painel::entities();
            $base = self::glpiUrl();
            $comps = [];
            foreach ($DB->request([
                'SELECT' => ['c.id', 'c.name', 'c.serial', 'c.is_dynamic', 'c.date_mod', 'e.completename AS entidade', 'm.name AS modelo', 'f.name AS fabricante', 'ty.name AS tipo'],
                'FROM' => 'glpi_computers AS c',
                'LEFT JOIN' => [
                    'glpi_entities AS e' => ['ON' => ['c' => 'entities_id', 'e' => 'id']],
                    'glpi_computermodels AS m' => ['ON' => ['c' => 'computermodels_id', 'm' => 'id']],
                    'glpi_manufacturers AS f' => ['ON' => ['c' => 'manufacturers_id', 'f' => 'id']],
                    'glpi_computertypes AS ty' => ['ON' => ['c' => 'computertypes_id', 'ty' => 'id']],
                ],
                'WHERE' => ['c.is_deleted' => 0, 'c.is_template' => 0, 'c.entities_id' => $ents],
            ]) as $c) {
                $comps[(int) $c['id']] = ['id' => (int) $c['id'], 'nome' => (string) $c['name'], 'entidade' => (string) $c['entidade'], 'modelo' => (string) $c['modelo'],
                    'fabricante' => (string) $c['fabricante'], 'tipo' => (string) $c['tipo'], 'serial' => (string) $c['serial'], 'agente' => (bool) $c['is_dynamic'],
                    'atualizado' => $c['date_mod'], 'so' => Si360::obj([]), 'softwares' => [], 'url' => "$base/front/computer.form.php?id={$c['id']}"];
            }
            if ($comps) {
                $ids = array_keys($comps);
                foreach ($DB->request([
                    'SELECT' => ['i.items_id', 'o.name AS nome', 'v.name AS versao', 'a.name AS arquitetura', 'ed.name AS edicao'],
                    'FROM' => 'glpi_items_operatingsystems AS i',
                    'LEFT JOIN' => [
                        'glpi_operatingsystems AS o' => ['ON' => ['i' => 'operatingsystems_id', 'o' => 'id']],
                        'glpi_operatingsystemversions AS v' => ['ON' => ['i' => 'operatingsystemversions_id', 'v' => 'id']],
                        'glpi_operatingsystemarchitectures AS a' => ['ON' => ['i' => 'operatingsystemarchitectures_id', 'a' => 'id']],
                        'glpi_operatingsystemeditions AS ed' => ['ON' => ['i' => 'operatingsystemeditions_id', 'ed' => 'id']],
                    ],
                    'WHERE' => ['i.itemtype' => 'Computer', 'i.items_id' => $ids, 'i.is_deleted' => 0],
                ]) as $o) {
                    $comps[(int) $o['items_id']]['so'] = ['nome' => (string) $o['nome'], 'versao' => (string) $o['versao'], 'arquitetura' => (string) $o['arquitetura'], 'edicao' => (string) $o['edicao']];
                }
                foreach (array_chunk($ids, 500) as $part) {
                    foreach ($DB->request([
                        'SELECT' => ['isv.items_id', 's.name AS n', 'sv.name AS v', 'mf.name AS f', 'sc.completename AS cat'],
                        'FROM' => 'glpi_items_softwareversions AS isv',
                        'INNER JOIN' => [
                            'glpi_softwareversions AS sv' => ['ON' => ['isv' => 'softwareversions_id', 'sv' => 'id']],
                            'glpi_softwares AS s' => ['ON' => ['sv' => 'softwares_id', 's' => 'id']],
                        ],
                        'LEFT JOIN' => [
                            'glpi_manufacturers AS mf' => ['ON' => ['s' => 'manufacturers_id', 'mf' => 'id']],
                            'glpi_softwarecategories AS sc' => ['ON' => ['s' => 'softwarecategories_id', 'sc' => 'id']],
                        ],
                        'WHERE' => ['isv.itemtype' => 'Computer', 'isv.items_id' => $part, 'isv.is_deleted' => 0, 's.is_deleted' => 0],
                    ]) as $s) {
                        $comps[(int) $s['items_id']]['softwares'][] = ['n' => (string) $s['n'], 'v' => (string) $s['v'], 'f' => (string) $s['f'], 'cat' => (string) $s['cat']];
                    }
                }
            }
            $out = array_values($comps);
            foreach ($out as &$c) {
                usort($c['softwares'], fn ($a, $b) => strcasecmp($a['n'], $b['n']));
            }
            unset($c);
            usort($out, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));
            return ['entidade' => self::entityName(), 'computadores' => $out, 'gerado' => time(),
                'total_softwares' => array_sum(array_map(fn ($c) => count($c['softwares']), $out))];
        }, false);
    }

    /** Fotos e logotipos próprios do mapa (si360/si/img). */
    private static function images(): array
    {
        $dir = dirname(__DIR__) . '/si360/si/img';
        $out = [];
        foreach (is_dir($dir) ? scandir($dir) : [] as $f) {
            if (preg_match('/\.(png|jpe?g|webp|svg)$/i', $f)) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }
}
