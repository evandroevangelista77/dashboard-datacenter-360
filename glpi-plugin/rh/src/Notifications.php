<?php

namespace GlpiPlugin\Rh;

use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Cria (e remove) as notificações e os modelos de e-mail do RH. Ficam em
 * Configurar → Notificações, editáveis como as do GLPI.
 */
class Notifications
{
    private static function definitions(): array
    {
        $foot = '<p style="color:#64748b;font-size:12px">Enviado pelo módulo de RH do GLPI.</p>';
        $vaga = '<p><strong>##vaga.code## · ##vaga.name##</strong><br>Cargo: ##vaga.cargo## · Departamento: ##vaga.departamento##<br>'
            . 'Posições: ##vaga.positions## · ##vaga.contract## · ##vaga.workmode##<br>Motivo: ##vaga.reason## · Faixa salarial: ##vaga.salary##<br>Solicitante: ##vaga.requester##</p>';
        return [
            ['rh_vaga_gestor', Vaga::class, 'RH - Vaga aguardando o gestor', [NotificationTargetVaga::MANAGER],
                '[RH] Aprovar vaga: ##vaga.name##',
                "<p>Uma requisição de vaga do seu departamento espera a sua aprovação.</p>$vaga<p>Observação: ##vaga.comment##</p><p><a href=\"##vaga.url##\">Abrir a vaga</a> · <a href=\"##vaga.approvals_url##\">Minhas aprovações</a></p>$foot"],
            ['rh_vaga_diretoria', Vaga::class, 'RH - Vaga aguardando a diretoria', [NotificationTargetVaga::BOARD],
                '[RH] Aprovação da diretoria: ##vaga.name##',
                "<p>A requisição foi aprovada pelo gestor (##vaga.manager##) e espera a aprovação da diretoria.</p>$vaga<p>Comentário: ##vaga.comment##</p><p><a href=\"##vaga.url##\">Abrir a vaga</a> · <a href=\"##vaga.approvals_url##\">Minhas aprovações</a></p>$foot"],
            ['rh_vaga_aprovada', Vaga::class, 'RH - Vaga aprovada', [NotificationTargetVaga::REQUESTER, NotificationTargetVaga::RECRUITER, NotificationTargetVaga::MANAGER],
                '[RH] Vaga aprovada: ##vaga.name##',
                "<p>A vaga foi <strong style=\"color:#15803d\">aprovada</strong> e está aberta para seleção.</p>$vaga<p>Comentário da diretoria: ##vaga.comment##</p><p><a href=\"##vaga.url##\">Abrir a vaga e o funil de seleção</a></p>$foot"],
            ['rh_vaga_reprovada', Vaga::class, 'RH - Vaga reprovada', [NotificationTargetVaga::REQUESTER, NotificationTargetVaga::MANAGER],
                '[RH] Vaga reprovada: ##vaga.name##',
                "<p>A requisição de vaga foi <strong style=\"color:#b91c1c\">reprovada</strong>.</p>$vaga<p><strong>Motivo:</strong> ##vaga.comment##</p><p>Você pode ajustar e enviar de novo: <a href=\"##vaga.url##\">abrir a vaga</a>.</p>$foot"],
            ['rh_ferias_pendente', Feria::class, 'RH - Férias aguardando o gestor', [NotificationTargetFeria::MANAGER],
                '[RH] Pedido de férias: ##ferias.colaborador## (##ferias.periodo##)',
                "<p><strong>##ferias.colaborador##</strong> pediu férias e espera a sua aprovação.</p><p>Período: ##ferias.periodo## (##ferias.days## dias) · Abono: ##ferias.sell##<br>Observação: ##ferias.note##</p><p><a href=\"##ferias.url##\">Abrir o pedido</a> · <a href=\"##ferias.approvals_url##\">Minhas aprovações</a></p>$foot"],
            ['rh_ferias_decisao', Feria::class, 'RH - Decisão sobre férias', [NotificationTargetFeria::EMPLOYEE],
                '[RH] Férias: ##ferias.status## (##ferias.periodo##)',
                "<p>Seu pedido de férias de <strong>##ferias.periodo##</strong> (##ferias.days## dias) foi: <strong>##ferias.status##</strong>.</p><p>Comentário do gestor (##ferias.manager##): ##ferias.comment##</p><p><a href=\"##ferias.url##\">Abrir o pedido</a></p>$foot"],
        ];
    }

    public static function install(): void
    {
        foreach (self::definitions() as [$event, $itemtype, $name, $targets, $subject, $html]) {
            $n = new Notification();
            if ($n->getFromDBByCrit(['itemtype' => $itemtype, 'event' => $event])) {
                continue;
            }
            $tpl = new NotificationTemplate();
            $tplId = (int) $tpl->add(['name' => $name, 'itemtype' => $itemtype, 'comment' => 'Criado pelo plugin RH']);
            (new NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $tplId, 'language' => '', 'subject' => $subject, 'content_html' => $html,
                'content_text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $html)))),
            ]);
            $nid = (int) $n->add(['name' => $name, 'itemtype' => $itemtype, 'event' => $event, 'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1, 'comment' => 'Criada pelo plugin RH']);
            (new Notification_NotificationTemplate())->add(['notifications_id' => $nid, 'mode' => Notification_NotificationTemplate::MODE_MAIL, 'notificationtemplates_id' => $tplId]);
            foreach ($targets as $t) {
                (new NotificationTarget())->add(['notifications_id' => $nid, 'type' => Notification::USER_TYPE, 'items_id' => $t]);
            }
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        foreach ([Vaga::class, Feria::class] as $itemtype) {
            foreach ($DB->request(['FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => $itemtype]]) as $n) {
                (new Notification())->delete(['id' => $n['id']], true);
            }
            foreach ($DB->request(['FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => $itemtype]]) as $t) {
                (new NotificationTemplate())->delete(['id' => $t['id']], true);
            }
        }
    }
}
