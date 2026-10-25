<?php

namespace GlpiPlugin\Ciclo;

use CommonDBTM;
use Session;

/** Linha do tempo do Ciclo: aberto, fase iniciada, concluída, devolvida, pulada, comentário. */
class Evento extends CommonDBTM
{
    public static string $rightname = 'plugin_ciclo';

    public const ACTIONS = [
        'open'     => ['Ciclo aberto', 'ti-flag', '#3b82f6'],
        'start'    => ['Fase iniciada', 'ti-player-play', '#0ea5e9'],
        'done'     => ['Fase concluída', 'ti-circle-check', '#22c55e'],
        'return'   => ['Devolvida', 'ti-arrow-back-up', '#ef4444'],
        'skip'     => ['Fase pulada', 'ti-player-skip-forward', '#94a3b8'],
        'comment'  => ['Comentário', 'ti-message', '#a855f7'],
        'closed'   => ['Ciclo concluído', 'ti-trophy', '#22c55e'],
        'canceled' => ['Ciclo cancelado', 'ti-ban', '#94a3b8'],
        'reopened' => ['Ciclo reaberto', 'ti-refresh', '#f59e0b'],
    ];

    public static function getTypeName($nb = 0)
    {
        return 'Eventos do Ciclo';
    }

    public static function record(int $cicloId, ?string $code, string $action, string $comment = ''): void
    {
        global $DB;
        $DB->insert(self::getTable(), [
            'plugin_ciclo_ciclos_id' => $cicloId, 'code' => $code, 'action' => $action, 'comment' => $comment,
            'users_id' => (int) Session::getLoginUserID(), 'date' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public static function forCiclo(int $cicloId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_ciclo_ciclos_id' => $cicloId], 'ORDER' => 'id DESC']), false);
    }
}
