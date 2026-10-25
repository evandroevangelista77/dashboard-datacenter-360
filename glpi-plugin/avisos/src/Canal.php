<?php

namespace GlpiPlugin\Avisos;

use CommonDBTM;
use Session;

/**
 * Canal do Microsoft Teams de cada plugin: a URL do webhook ("Workflows" ou
 * webhook de entrada do canal), ligado ou desligado, e o resultado do último envio.
 */
class Canal extends CommonDBTM
{
    public static string $rightname = 'config';

    /** Plugin → [nome, ícone, cor]. */
    public const SOURCES = [
        'rh'        => ['RH', 'ti-users-group', '#ec4899'],
        'pmo'       => ['PMO', 'ti-briefcase', '#3b82f6'],
        'reembolso' => ['Financeiro (Reembolso)', 'ti-receipt-2', '#22c55e'],
        'ciclo'     => ['Ciclo Financeiro', 'ti-recycle', '#14b8a6'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Canais do Teams' : 'Canal do Teams';
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function forSource(string $source): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['source' => $source], 'LIMIT' => 1])->current();
        return $row ?: null;
    }

    /** Só https (ou http em localhost, para teste). */
    public static function validUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return false;
        }
        return ($p['scheme'] ?? '') === 'https' || (($p['scheme'] ?? '') === 'http' && in_array($p['host'], ['localhost', '127.0.0.1'], true));
    }

    public static function meta(string $source): array
    {
        return self::SOURCES[$source] ?? [ucfirst($source), 'ti-bell', '#64748b'];
    }
}
