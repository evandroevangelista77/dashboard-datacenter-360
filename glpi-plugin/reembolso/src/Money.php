<?php

namespace GlpiPlugin\Reembolso;

use Config;

/**
 * Valores em reais e configuração do plugin.
 */
class Money
{
    public static function format(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    public static function km(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',') . ' km';
    }

    /** "1.234,56", "1234.56" ou "1234,5" → 1234.56 */
    public static function parse(string $v): float
    {
        $v = trim(str_replace(['R$', ' '], '', $v));
        if ($v === '') {
            return 0.0;
        }
        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v); // "45.210" (odômetro, milhar) = 45210
        }
        return round((float) $v, 4);
    }

    public static function config(): array
    {
        $c = Config::getConfigurationValues('plugin:reembolso');
        return [
            'km_rate'         => (float) ($c['km_rate'] ?? 1.20),
            'route_enabled'   => (bool) ($c['route_enabled'] ?? true),
            'route_country'   => (string) ($c['route_country'] ?? 'br'),
            'require_receipt' => (bool) ($c['require_receipt'] ?? true),
            'night_start'     => self::hhmm((string) ($c['night_start'] ?? ''), '22:00'),
            'night_end'       => self::hhmm((string) ($c['night_end'] ?? ''), '05:00'),
            'business_start'  => self::hhmm((string) ($c['business_start'] ?? ''), '08:00'),
            'business_end'    => self::hhmm((string) ($c['business_end'] ?? ''), '18:00'),
            'lote_period'     => isset(Lote::PERIODS[$c['lote_period'] ?? '']) ? $c['lote_period'] : 'mensal',
            'tech_groups_id'    => (int) ($c['tech_groups_id'] ?? 0),
            'manager_users_id'  => (int) ($c['manager_users_id'] ?? 0),
            'manager_groups_id' => (int) ($c['manager_groups_id'] ?? 0),
            'otc_users_id'      => (int) ($c['otc_users_id'] ?? 0),
            'otc_groups_id'     => (int) ($c['otc_groups_id'] ?? 0),
            'finance_users_id'  => (int) ($c['finance_users_id'] ?? 0),
            'finance_groups_id' => (int) ($c['finance_groups_id'] ?? 0),
            'advance_account_days' => max(1, (int) ($c['advance_account_days'] ?? 5)),
        ];
    }

    public static function hhmm(string $v, string $default): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $default;
    }

    /**
     * Horas do atendimento, minuto a minuto: comerciais (segunda a sexta, no horário
     * comercial), fora do horário comercial (o resto, inclusive fim de semana) e, dentro
     * delas, as do adicional noturno. Null quando falta data ou hora ou o fim vem antes.
     */
    public static function hours(?string $d1, ?string $t1, ?string $d2, ?string $t2): ?array
    {
        if (!$d1 || !$t1 || !$d2 || !$t2) {
            return null;
        }
        $start = strtotime("$d1 " . substr($t1, 0, 5));
        $end = strtotime("$d2 " . substr($t2, 0, 5));
        if (!$start || !$end || $end <= $start) {
            return null;
        }
        $end = min($end, $start + 31 * 86400); // um atendimento não passa de um mês
        $c = self::config();
        $out = ['business' => 0, 'outside' => 0, 'night' => 0];
        for ($m = $start; $m < $end; $m += 60) {
            $t = date('H:i', $m);
            $weekday = (int) date('N', $m) <= 5;
            $out[$weekday && $t >= $c['business_start'] && $t < $c['business_end'] ? 'business' : 'outside']++;
            if (self::isNight($t)) {
                $out['night']++;
            }
        }
        return $out;
    }

    /** 90 → "1h30", 45 → "45min", 0 → "—" */
    public static function minutes(int $m): string
    {
        if ($m <= 0) {
            return '—';
        }
        $h = intdiv($m, 60);
        $r = $m % 60;
        return $h ? $h . 'h' . ($r ? str_pad((string) $r, 2, '0', STR_PAD_LEFT) : '') : $r . 'min';
    }

    /**
     * O horário cai no adicional noturno? A faixa pode virar a noite (22:00 às 05:00)
     * ou não (00:00 às 06:00); o fim não entra.
     */
    public static function isNight(?string $time): bool
    {
        if (!$time || !preg_match('/^(\d{2}):(\d{2})/', $time)) {
            return false;
        }
        $c = self::config();
        $t = substr($time, 0, 5);
        return $c['night_start'] <= $c['night_end']
            ? $t >= $c['night_start'] && $t < $c['night_end']
            : $t >= $c['night_start'] || $t < $c['night_end'];
    }
}
