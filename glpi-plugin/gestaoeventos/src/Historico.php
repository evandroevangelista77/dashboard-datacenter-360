<?php

namespace GlpiPlugin\Gestaoeventos;

use Session;

/** Linha do tempo e trilha de auditoria do evento: etapas, decisões, bloqueios, alterações e comentários. */
class Historico
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_historicos';

    public static string $rightname = 'plugin_gestaoeventos';

    public const ACTIONS = [
        'open'      => ['Evento aberto', 'ti-flag', '#3b82f6'],
        'submit'    => ['Enviado à triagem', 'ti-send', '#0ea5e9'],
        'start'     => ['Etapa iniciada', 'ti-player-play', '#0ea5e9'],
        'done'      => ['Etapa concluída', 'ti-circle-check', '#22c55e'],
        'return'    => ['Devolvida', 'ti-arrow-back-up', '#ef4444'],
        'skip'      => ['Etapa pulada', 'ti-player-skip-forward', '#94a3b8'],
        'comment'   => ['Comentário', 'ti-message', '#a855f7'],
        'closed'    => ['Evento concluído', 'ti-trophy', '#22c55e'],
        'canceled'  => ['Evento cancelado', 'ti-ban', '#94a3b8'],
        'reopened'  => ['Evento reaberto', 'ti-refresh', '#f59e0b'],
        'approval'  => ['Aprovação', 'ti-signature', '#14b8a6'],
        'blocked'   => ['Operação bloqueada', 'ti-lock', '#ef4444'],
        'change'    => ['Alteração', 'ti-edit', '#64748b'],
        'finance'   => ['Financeiro', 'ti-cash', '#f59e0b'],
        'triage'    => ['Triagem', 'ti-filter', '#8b5cf6'],
        'integration' => ['Integração', 'ti-plug-connected', '#64748b'],
    ];

    public static function getTypeName($nb = 0)
    {
        return 'Histórico do evento';
    }

    public static function record(int $eventoId, ?string $code, string $action, string $comment = ''): void
    {
        global $DB;
        $DB->insert(self::getTable(), [
            'plugin_gestaoeventos_eventos_id' => $eventoId, 'code' => $code, 'action' => $action, 'comment' => $comment,
            'users_id' => (int) Session::getLoginUserID(), 'date' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public static function forEvento(int $eventoId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $eventoId], 'ORDER' => 'id DESC']), false);
    }
}
