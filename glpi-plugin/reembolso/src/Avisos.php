<?php

namespace GlpiPlugin\Reembolso;

use Dropdown;
use NotificationEvent;
use Plugin;
use Throwable;

/**
 * Avisos do reembolso: e-mail pelas notificações do GLPI e, com o plugin "Avisos",
 * sino, pop-up e o canal do Teams do Reembolso.
 */
class Avisos
{
    /** Evento do fluxo → [e-mail, título, nível]. */
    private const EVENTS = [
        'submit'  => ['rb_enviado', 'Reembolso aguardando aprovação', 'warning'],
        'approve' => ['rb_aprovado', 'Reembolso aprovado', 'success'],
        'refuse'  => ['rb_recusado', 'Reembolso recusado', 'danger'],
        'pay'     => ['rb_pago', 'Reembolso pago', 'success'],
    ];

    public static function transition(Reembolso $r, string $action): void
    {
        if (!isset(self::EVENTS[$action])) {
            return;
        }
        [$event, $title, $level] = self::EVENTS[$action];
        $f = $r->fields;
        NotificationEvent::raiseEvent($event, $r);

        $tech = (string) getUserName((int) $f['users_id']);
        $code = Reembolso::code($r->getID());
        $total = Money::format((float) $f['total']);
        [$users, $message] = match ($action) {
            'submit'  => [NotificationTargetReembolso::approvers(), "$tech enviou a solicitação $code ($total) para aprovação."],
            'approve' => [[(int) $f['users_id'], (int) $f['users_id_recipient']], "A solicitação $code ($total) foi aprovada por " . getUserName((int) $f['users_id_approver']) . ($f['approval_comment'] ? ". Comentário: {$f['approval_comment']}" : '.')],
            'refuse'  => [[(int) $f['users_id'], (int) $f['users_id_recipient']], "A solicitação $code foi recusada. Motivo: {$f['approval_comment']}. Corrija e envie de novo."],
            'pay'     => [[(int) $f['users_id'], (int) $f['users_id_recipient']], "O pagamento de $total da solicitação $code foi registrado" . ($f['date_payment'] ? ' em ' . date('d/m/Y', strtotime($f['date_payment'])) : '') . '.'],
        };
        self::send([
            'event' => $event, 'users' => $users, 'title' => $title, 'message' => $message, 'level' => $level,
            'url' => Reembolso::getFormURLWithID($r->getID()), 'item' => [Reembolso::class, $r->getID()],
            'facts' => [
                'Solicitação'     => "$code · {$f['name']}",
                'Técnico'         => $tech,
                'Centro de custo' => Dropdown::getDropdownName(Centrocusto::getTable(), (int) $f['plugin_reembolso_centrocustos_id'], false, true, false, ''),
                'Total'           => $total,
                'Situação'        => Reembolso::STATUSES[(int) $f['status']][0] ?? '',
                'Pagamento'       => $f['payment_reference'] ? (string) $f['payment_reference'] : '',
            ],
        ]);
    }

    public static function send(array $a): void
    {
        if (!Plugin::isPluginActive('avisos') || !class_exists(\GlpiPlugin\Avisos\Aviso::class)) {
            return;
        }
        try {
            \GlpiPlugin\Avisos\Aviso::send($a + ['source' => 'reembolso']);
        } catch (Throwable $e) {
            trigger_error('Reembolso: aviso não enviado: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /** Endereço completo, para os e-mails. */
    public static function absolute(string $path): string
    {
        global $CFG_GLPI;
        $root = (string) $CFG_GLPI['root_doc'];
        return rtrim((string) $CFG_GLPI['url_base'], '/') . ($root !== '' && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path);
    }
}
