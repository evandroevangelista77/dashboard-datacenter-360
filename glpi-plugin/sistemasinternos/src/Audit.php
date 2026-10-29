<?php

namespace GlpiPlugin\Sistemasinternos;

use Session;

/**
 * Auditoria do cadastro de hosts: quem fez, quando, o quê, o antes e o depois.
 */
class Audit
{
    public const TABLE = 'glpi_plugin_sistemasinternos_logs';

    public const ACTIONS = [
        'create'  => ['Incluiu', 'ti-circle-plus', 'success'],
        'update'  => ['Editou', 'ti-pencil', 'primary'],
        'disable' => ['Desativou', 'ti-player-pause', 'warning'],
        'enable'  => ['Ativou', 'ti-player-play', 'success'],
        'delete'  => ['Excluiu', 'ti-trash', 'danger'],
    ];

    public static function log(string $action, string $hostid, string $host, ?array $before, ?array $after, string $result = 'ok', string $message = ''): int
    {
        global $DB;
        $DB->insert(self::TABLE, [
            'users_id'      => (int) Session::getLoginUserID(),
            'action'        => $action,
            'hostid'        => $hostid,
            'host'          => mb_substr($host, 0, 255),
            'result'        => $result,
            'message'       => $message,
            'before'        => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after'         => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip'            => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        return (int) $DB->insertId();
    }

    /** Diferenças entre o antes e o depois, campo a campo, para a tela. */
    public static function diff(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
            $a = self::flat($before[$k] ?? null);
            $b = self::flat($after[$k] ?? null);
            if ($a !== $b) {
                $out[] = [$k, $a, $b];
            }
        }
        return $out;
    }

    private static function flat(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_array($v)) {
            return implode(', ', array_map(fn ($x) => is_array($x) ? implode('=', array_map('strval', $x)) : (string) $x, $v));
        }
        return (string) $v;
    }
}
