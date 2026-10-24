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
        ];
    }
}
