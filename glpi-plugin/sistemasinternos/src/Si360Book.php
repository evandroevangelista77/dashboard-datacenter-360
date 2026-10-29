<?php

namespace GlpiPlugin\Sistemasinternos;

use Session;

/**
 * Resumo operacional (book mensal) do dashboard: números digitados à mão, mês a mês.
 * Cada gravação vira uma linha nova em glpi_plugin_sistemasinternos_books (a mais
 * recente é a atual), para nada dos meses anteriores se perder.
 */
class Si360Book
{
    private const TABLE = 'glpi_plugin_sistemasinternos_books';

    public static function route(array $parts, array $q): array
    {
        $what = $parts[0] ?? '';
        if ($what === '') {
            return self::load();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new NotFound('rota desconhecida');
        }
        if (!Session::haveRight(Menu::RIGHT_CONFIG, UPDATE)) {
            throw new Denied('Só quem pode alterar as configurações do Sistemas Internos edita o resumo operacional.');
        }
        $b = json_decode((string) file_get_contents('php://input'), true) ?: [];
        if ($what === 'mes') {
            return self::month((string) ($parts[1] ?? ''), $b);
        }
        if ($what === 'metas') {
            return self::goals($b);
        }
        throw new NotFound('rota desconhecida');
    }

    public static function load(): array
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['data'], 'FROM' => self::TABLE, 'ORDER' => ['id DESC'], 'LIMIT' => 1])->current();
        $d = $row ? json_decode((string) $row['data'], true) : null;
        if (!is_array($d)) {
            $d = json_decode((string) file_get_contents(dirname(__DIR__) . '/resources/book-inicial.json'), true) ?: ['sla_meta' => 98, 'metas' => [], 'meses' => []];
            self::save($d, 'valores iniciais');
        }
        $d['meses'] = (object) ($d['meses'] ?? []);
        return $d;
    }

    private static function save(array $d, string $what): void
    {
        global $DB;
        $DB->insert(self::TABLE, ['users_id' => (int) Session::getLoginUserID(), 'what' => mb_substr($what, 0, 100),
            'data' => json_encode($d, JSON_UNESCAPED_UNICODE), 'date_creation' => date('Y-m-d H:i:s')]);
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim(str_replace(['%', ','], ['', '.'], (string) $v));
        return is_numeric($s) ? (float) $s : null;
    }

    private static function month(string $ym, array $b): array
    {
        if (!preg_match('/^20\d\d-(0[1-9]|1[0-2])$/', $ym)) {
            throw new NotFound('mês inválido');
        }
        $d = self::load();
        $meses = (array) $d['meses'];
        $pick = fn (array $src, array $keys) => array_combine($keys, array_map(fn ($k) => self::num($src[$k] ?? null), $keys));
        $meses[$ym] = ['tickets' => $pick((array) ($b['tickets'] ?? []), ['total', 'fechados', 'abertos', 'sla']),
            'cat' => $pick((array) ($b['cat'] ?? []), ['hw', 'infra', 'sis', 'prest', 'srv']),
            'metas' => (object) $pick((array) ($b['metas'] ?? []), array_column($d['metas'], 'id'))]
            + $pick($b, ['inc', 'inc_sla', 'req', 'req_sla', 'naocat', 'vuln', 'sla_acum'])
            + ['obs' => mb_substr((string) ($b['obs'] ?? ''), 0, 2000), 'atualizado' => date('Y-m-d\TH:i:s')];
        ksort($meses);
        $d['meses'] = $meses;
        self::save($d, "mês $ym");
        return self::load();
    }

    private static function goals(array $b): array
    {
        $d = self::load();
        $metas = [];
        foreach ((array) ($b['metas'] ?? []) as $x) {
            $nome = mb_substr(trim((string) ($x['nome'] ?? '')), 0, 120);
            if ($nome === '') {
                continue;
            }
            $id = preg_replace('/[^a-z0-9_]/', '', (string) ($x['id'] ?? '')) ?: 'm' . substr((string) round(microtime(true) * 1000), -8) . count($metas);
            $metas[] = ['id' => $id, 'nome' => $nome, 'meta' => mb_substr((string) ($x['meta'] ?? ''), 0, 20), 'lado' => ($x['lado'] ?? '') === 'dir' ? 'dir' : 'esq',
                'alvo' => self::num($x['alvo'] ?? null), 'regra' => ($x['regra'] ?? '') === 'max' ? 'max' : 'min'];
        }
        if ($metas) {
            $d['metas'] = $metas;
        }
        if (self::num($b['sla_meta'] ?? null) !== null) {
            $d['sla_meta'] = self::num($b['sla_meta']);
        }
        self::save($d, 'metas');
        return self::load();
    }
}
