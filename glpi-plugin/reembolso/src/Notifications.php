<?php

namespace GlpiPlugin\Reembolso;

use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Cria (e remove) as notificações e os modelos de e-mail do reembolso. Ficam em
 * Configurar → Notificações, editáveis como as do GLPI.
 */
class Notifications
{
    private static function definitions(): array
    {
        $foot = '<p style="color:#64748b;font-size:12px">Enviado pelo módulo de reembolso do GLPI.</p>';
        $info = '<p><strong>##reembolso.code## · ##reembolso.name##</strong><br>Técnico: ##reembolso.tecnico## · Centro de custo: ##reembolso.centro##<br>'
            . 'Período: ##reembolso.periodo## · Total: <strong>##reembolso.total##</strong></p>';
        $link = '<p><a href="##reembolso.url##">Abrir a solicitação</a></p>';
        return [
            ['rb_enviado', 'Reembolso - Enviado para aprovação', [NotificationTargetReembolso::APPROVERS],
                '[Reembolso] Aprovar ##reembolso.code##: ##reembolso.tecnico## (##reembolso.total##)',
                "<p>Uma solicitação de reembolso espera a sua aprovação.</p>$info$link$foot"],
            ['rb_aprovado', 'Reembolso - Aprovado', [NotificationTargetReembolso::TECH, NotificationTargetReembolso::RECIPIENT],
                '[Reembolso] Aprovado: ##reembolso.code## (##reembolso.total##)',
                "<p>Sua solicitação de reembolso foi <strong style=\"color:#15803d\">aprovada</strong> por ##reembolso.approver##.</p>$info<p>Comentário: ##reembolso.comment##</p>$link$foot"],
            ['rb_recusado', 'Reembolso - Recusado', [NotificationTargetReembolso::TECH, NotificationTargetReembolso::RECIPIENT],
                '[Reembolso] Recusado: ##reembolso.code##',
                "<p>Sua solicitação de reembolso foi <strong style=\"color:#b91c1c\">recusada</strong> por ##reembolso.approver##.</p>$info<p><strong>Motivo:</strong> ##reembolso.comment##</p><p>Corrija e envie de novo: <a href=\"##reembolso.url##\">abrir a solicitação</a>.</p>$foot"],
            ['rb_pago', 'Reembolso - Pagamento registrado', [NotificationTargetReembolso::TECH, NotificationTargetReembolso::RECIPIENT],
                '[Reembolso] Pago: ##reembolso.code## (##reembolso.total##)',
                "<p>O pagamento do seu reembolso foi registrado.</p>$info<p>Pagamento: ##reembolso.payment##</p>$link$foot"],
        ];
    }

    public static function install(): void
    {
        foreach (self::definitions() as [$event, $name, $targets, $subject, $html]) {
            $n = new Notification();
            if ($n->getFromDBByCrit(['itemtype' => Reembolso::class, 'event' => $event])) {
                continue;
            }
            $tpl = new NotificationTemplate();
            $tplId = (int) $tpl->add(['name' => $name, 'itemtype' => Reembolso::class, 'comment' => 'Criado pelo plugin Reembolso']);
            (new NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $tplId, 'language' => '', 'subject' => $subject, 'content_html' => $html,
                'content_text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $html)))),
            ]);
            $nid = (int) $n->add(['name' => $name, 'itemtype' => Reembolso::class, 'event' => $event, 'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1, 'comment' => 'Criada pelo plugin Reembolso']);
            (new Notification_NotificationTemplate())->add(['notifications_id' => $nid, 'mode' => Notification_NotificationTemplate::MODE_MAIL, 'notificationtemplates_id' => $tplId]);
            foreach ($targets as $t) {
                (new NotificationTarget())->add(['notifications_id' => $nid, 'type' => Notification::USER_TYPE, 'items_id' => $t]);
            }
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => Reembolso::class]]) as $n) {
            (new Notification())->delete(['id' => $n['id']], true);
        }
        foreach ($DB->request(['FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => Reembolso::class]]) as $t) {
            (new NotificationTemplate())->delete(['id' => $t['id']], true);
        }
    }
}
