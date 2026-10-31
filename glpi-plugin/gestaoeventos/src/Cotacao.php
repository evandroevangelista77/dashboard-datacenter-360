<?php

namespace GlpiPlugin\Gestaoeventos;

/**
 * Cotações da API Uber: um registro por produto e consulta, nunca sobrescrito. Guarda só o mínimo
 * (produto, faixa, moeda, distância, duração, instante, origem) — sem token, sem coordenadas, sem dados do viajante.
 */
class Cotacao
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_cotacoes';

    public const LEGS = [
        'ida' => 'Ida', 'volta' => 'Volta', 'acesso_ida' => 'Casa → aeroporto (ida)', 'egresso_ida' => 'Aeroporto → local (ida)',
        'acesso_volta' => 'Local → aeroporto (volta)', 'egresso_volta' => 'Aeroporto → casa (volta)',
    ];

    public static function forViajante(int $viajanteId, ?string $leg = null): array
    {
        global $DB;
        $where = ['viajantes_id' => $viajanteId] + ($leg ? ['leg' => $leg] : []);
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'id DESC']), false);
    }

    /** Valor de referência da faixa pela política: min | mid | max. null quando a API não trouxe valor (nunca zero). */
    public static function reference(array $q, string $policy): ?float
    {
        $low = $q['low'] !== null ? (float) $q['low'] : null;
        $high = $q['high'] !== null ? (float) $q['high'] : null;
        if ($low === null && $high === null) {
            return null;
        }
        $low ??= $high;
        $high ??= $low;
        return round(match ($policy) { 'min' => $low, 'mid' => ($low + $high) / 2, default => $high }, 2);
    }

    /** A cotação escolhida para o trecho (a mais recente marcada). */
    public static function selected(int $viajanteId, string $leg): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => ['viajantes_id' => $viajanteId, 'leg' => $leg, 'is_selected' => 1], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current() ?: null;
    }
}
