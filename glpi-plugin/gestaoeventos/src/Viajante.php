<?php

namespace GlpiPlugin\Gestaoeventos;

/** Viajante do evento e a simulação de custo dele (a tela e os cálculos estão em Viagem). */
class Viajante
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_viajantes';

    public const MODES = ['uber' => 'Uber (ida e volta)', 'proprio' => 'Veículo próprio', 'uber_aereo' => 'Uber + aéreo'];

    public static function row(int $id, ?int $eventoId = null): ?array
    {
        global $DB;
        $where = ['id' => $id] + ($eventoId ? ['plugin_gestaoeventos_eventos_id' => $eventoId] : []);
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1])->current() ?: null;
    }

    public static function forEvento(int $eventoId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $eventoId], 'ORDER' => 'id']), false);
    }
}
