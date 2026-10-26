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

    /** E-mails do lote (itemtype Lote). */
    private static function loteDefinitions(): array
    {
        $foot = '<p style="color:#64748b;font-size:12px">Enviado pelo módulo de reembolso do GLPI.</p>';
        $info = '<p><strong>##lote.code##</strong> · Técnico: ##lote.tecnico##<br>Período: ##lote.periodo## · ##lote.despesas## despesa(s) · Total: <strong>##lote.total##</strong><br>'
            . 'Adicional noturno: ##lote.noturno##</p>##lote.atendimentos##';
        $link = '<p><a href="##lote.url##">Abrir o lote</a></p>';
        return [
            ['lote_assinar', 'Lote de reembolso - Conferir e assinar', [NotificationTargetLote::TECH],
                '[Reembolso] Confira e assine o lote ##lote.code## (##lote.total##)',
                "<p>Seus reembolsos do período estão no lote abaixo, num PDF para conferência. Confira as despesas, inclusive as de adicional noturno, e assine (o link do LibreSign chega num e-mail à parte) ou aprove no GLPI.</p>$info$link$foot"],
            ['lote_aprovar', 'Lote de reembolso - Aguardando aprovação', [NotificationTargetLote::STEP],
                '[Reembolso] Lote ##lote.code## espera você: ##lote.etapa##',
                "<p>Um lote de reembolso espera a sua ação na etapa <strong>##lote.etapa##</strong>.</p>$info$link$foot"],
            ['lote_devolvido', 'Lote de reembolso - Devolvido ao técnico', [NotificationTargetLote::TECH],
                '[Reembolso] Lote ##lote.code## devolvido para correção',
                "<p>O lote foi <strong style=\"color:#b91c1c\">devolvido</strong>.</p>$info<p><strong>Motivo:</strong> ##lote.motivo##</p><p>Corrija as solicitações e reenvie: <a href=\"##lote.url##\">abrir o lote</a>.</p>$foot"],
            ['lote_pago', 'Lote de reembolso - Pago', [NotificationTargetLote::INVOLVED],
                '[Reembolso] Reembolso ##lote.code## finalizado com sucesso (##lote.total##)',
                "<p>O reembolso <strong>##lote.code##</strong> foi <strong style=\"color:#15803d\">pago</strong> e o comprovante está anexado ao lote.</p>$info<p>Pagamento: ##lote.pagamento##</p>$link$foot"],
        ];
    }

    /** E-mails do adiantamento (itemtype Adiantamento). */
    private static function adiantamentoDefinitions(): array
    {
        $foot = '<p style="color:#64748b;font-size:12px">Enviado pelo módulo de reembolso do GLPI.</p>';
        $info = '<p><strong>##ad.code##</strong> · Técnico: ##ad.tecnico##<br>##ad.chamado## · Centro de custo: ##ad.centro##<br>Atendimento: ##ad.atendimento## · Valor: <strong>##ad.total##</strong></p>##ad.despesas##';
        $link = '<p><a href="##ad.url##">Abrir o adiantamento</a></p>';
        return [
            ['ad_assinar', 'Adiantamento - Conferir e assinar', [NotificationTargetAdiantamento::TECH],
                '[Reembolso] Confira e assine o adiantamento ##ad.code## (##ad.total##)',
                "<p>Seu pedido de adiantamento está num PDF para conferência. Confira e assine (o link do LibreSign chega num e-mail à parte) ou aprove no GLPI.</p>$info$link$foot"],
            ['ad_aprovar', 'Adiantamento - Aguardando aprovação', [NotificationTargetAdiantamento::STEP],
                '[Reembolso] Adiantamento ##ad.code## espera você: ##ad.etapa##',
                "<p>Um adiantamento espera a sua ação na etapa <strong>##ad.etapa##</strong>.</p>$info$link$foot"],
            ['ad_devolvido', 'Adiantamento - Devolvido ao técnico', [NotificationTargetAdiantamento::TECH],
                '[Reembolso] Adiantamento ##ad.code## devolvido para correção',
                "<p>O adiantamento foi <strong style=\"color:#b91c1c\">devolvido</strong>.</p>$info<p><strong>Motivo:</strong> ##ad.motivo##</p>$link$foot"],
            ['ad_pago', 'Adiantamento - Pago', [NotificationTargetAdiantamento::INVOLVED],
                '[Reembolso] Adiantamento ##ad.code## pago (##ad.total##)',
                "<p>O adiantamento <strong>##ad.code##</strong> foi <strong style=\"color:#15803d\">pago</strong> (##ad.pagamento##). O técnico presta contas até <strong>##ad.prazo##</strong>.</p>$info$link$foot"],
            ['ad_prestacao', 'Adiantamento - Prestação de contas', [NotificationTargetAdiantamento::STEP],
                '[Reembolso] Prestação de contas do adiantamento ##ad.code##',
                "<p>O técnico prestou contas: gasto <strong>##ad.gasto##</strong> · ##ad.saldo##.</p>$info$link$foot"],
            ['ad_encerrado', 'Adiantamento - Encerrado', [NotificationTargetAdiantamento::INVOLVED],
                '[Reembolso] Adiantamento ##ad.code## finalizado com sucesso',
                "<p>A prestação de contas do adiantamento <strong>##ad.code##</strong> foi conferida e o adiantamento foi <strong style=\"color:#15803d\">encerrado</strong>. ##ad.saldo##.</p>$info$link$foot"],
        ];
    }

    public static function install(): void
    {
        $all = array_merge(
            array_map(fn ($d) => array_merge([Reembolso::class], $d), self::definitions()),
            array_map(fn ($d) => array_merge([Lote::class], $d), self::loteDefinitions()),
            array_map(fn ($d) => array_merge([Adiantamento::class], $d), self::adiantamentoDefinitions())
        );
        foreach ($all as [$itemtype, $event, $name, $targets, $subject, $html]) {
            $n = new Notification();
            if ($n->getFromDBByCrit(['itemtype' => $itemtype, 'event' => $event])) {
                continue;
            }
            $tpl = new NotificationTemplate();
            $tplId = (int) $tpl->add(['name' => $name, 'itemtype' => $itemtype, 'comment' => 'Criado pelo plugin Reembolso']);
            (new NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $tplId, 'language' => '', 'subject' => $subject, 'content_html' => $html,
                'content_text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $html)))),
            ]);
            $nid = (int) $n->add(['name' => $name, 'itemtype' => $itemtype, 'event' => $event, 'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1, 'comment' => 'Criada pelo plugin Reembolso']);
            (new Notification_NotificationTemplate())->add(['notifications_id' => $nid, 'mode' => Notification_NotificationTemplate::MODE_MAIL, 'notificationtemplates_id' => $tplId]);
            foreach ($targets as $t) {
                (new NotificationTarget())->add(['notifications_id' => $nid, 'type' => Notification::USER_TYPE, 'items_id' => $t]);
            }
        }
    }

    /** Modelos do lote criados antes da lista de atendimentos: acrescenta a lista (chamado, data e valor). */
    public static function upgradeLoteTemplates(): void
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['tr.id', 'tr.content_html'], 'FROM' => NotificationTemplateTranslation::getTable() . ' AS tr',
            'INNER JOIN' => [NotificationTemplate::getTable() . ' AS t' => ['ON' => ['t' => 'id', 'tr' => 'notificationtemplates_id']]],
            'WHERE' => ['t.itemtype' => Lote::class]]) as $r) {
            $html = (string) $r['content_html'];
            if (!str_contains($html, 'lote.atendimentos') && str_contains($html, 'Adicional noturno: ##lote.noturno##</p>')) {
                $DB->update(NotificationTemplateTranslation::getTable(), ['content_html' => str_replace('Adicional noturno: ##lote.noturno##</p>', 'Adicional noturno: ##lote.noturno##</p>##lote.atendimentos##', $html)], ['id' => $r['id']]);
            }
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => [Reembolso::class, Lote::class, Adiantamento::class]]]) as $n) {
            (new Notification())->delete(['id' => $n['id']], true);
        }
        foreach ($DB->request(['FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => [Reembolso::class, Lote::class, Adiantamento::class]]]) as $t) {
            (new NotificationTemplate())->delete(['id' => $t['id']], true);
        }
    }
}
