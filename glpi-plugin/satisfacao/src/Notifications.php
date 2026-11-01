<?php

namespace GlpiPlugin\Satisfacao;

use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Cria (e remove) as notificações e os modelos de e-mail do plugin: a pesquisa de satisfação e
 * o convite do eNPS. O assunto e o texto também são editados na configuração do plugin; ficam em
 * Configurar → Notificações como os demais avisos do GLPI.
 */
class Notifications
{
    public const EVENT = 'satisfacao_survey';
    public const EVENT_ENPS = 'satisfacao_enps';

    private const FOOT = '<p style="color:#64748b;font-size:12px">Enviado pela pesquisa de satisfação do GLPI.</p>';
    private const BUTTON = 'display:inline-block;padding:8px 14px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none';

    /** @return array{0: string, 1: string, 2: string} nome da notificação, assunto e texto (HTML) padrão de cada evento */
    public static function defaults(string $event): array
    {
        if ($event === self::EVENT_ENPS) {
            return [
                'Pesquisa de clima (eNPS)',
                '[eNPS] Sua opinião sobre trabalhar aqui: ##satisfacao.titulo##',
                '<p>Queremos ouvir você. Esta pesquisa é <strong>anônima</strong>: ninguém vê quem respondeu o quê.</p>'
                . '<p><strong>##satisfacao.titulo##</strong><br>##satisfacao.pergunta##</p>'
                . '<p><a href="##satisfacao.url##" style="' . self::BUTTON . '">Responder o eNPS</a></p>'
                . '<p>Leva menos de um minuto.</p>' . self::FOOT,
            ];
        }
        return [
            'Pesquisa de satisfação',
            '[Satisfação] Como foi o atendimento: ##satisfacao.titulo##',
            '<p>Obrigado por usar nosso atendimento. Pode nos contar como foi?</p>'
            . '<p><strong>##satisfacao.origem##:</strong> ##satisfacao.titulo##</p>'
            . '<p><a href="##satisfacao.url##" style="' . self::BUTTON . '">Responder a pesquisa</a></p>'
            . '<p>São poucas perguntas, e leva menos de um minuto:</p>##satisfacao.perguntas##' . self::FOOT,
        ];
    }

    public static function install(): void
    {
        foreach ([self::EVENT, self::EVENT_ENPS] as $event) {
            $n = new Notification();
            if ($n->getFromDBByCrit(['itemtype' => Survey::class, 'event' => $event])) {
                continue;
            }
            [$name, $subject, $html] = self::defaults($event);
            $tplId = (int) (new NotificationTemplate())->add(['name' => $name, 'itemtype' => Survey::class, 'comment' => 'Criado pelo plugin Satisfação']);
            (new NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $tplId, 'language' => '', 'subject' => $subject,
                'content_html' => $html, 'content_text' => self::plainText($html),
            ]);
            $nid = (int) $n->add([
                'name' => $name, 'itemtype' => Survey::class, 'event' => $event,
                'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1, 'comment' => 'Criada pelo plugin Satisfação',
            ]);
            (new Notification_NotificationTemplate())->add(['notifications_id' => $nid, 'mode' => Notification_NotificationTemplate::MODE_MAIL, 'notificationtemplates_id' => $tplId]);
            (new NotificationTarget())->add(['notifications_id' => $nid, 'type' => Notification::USER_TYPE, 'items_id' => NotificationTargetSurvey::RECIPIENT]);
        }
    }

    /** Assunto e texto (HTML) atuais do e-mail do evento, ou null se a notificação não existe. */
    public static function template(string $event = self::EVENT): ?array
    {
        $translation = self::translation($event);
        return $translation ? ['subject' => (string) $translation->fields['subject'], 'html' => (string) $translation->fields['content_html']] : null;
    }

    /** Grava o assunto e o texto do e-mail no modelo de notificação do GLPI. */
    public static function saveTemplate(string $subject, string $html, string $event = self::EVENT): bool
    {
        $translation = self::translation($event);
        if (!$translation) {
            return false;
        }
        return (bool) $translation->update([
            'id' => $translation->getID(), 'subject' => $subject, 'content_html' => $html, 'content_text' => self::plainText($html),
        ]);
    }

    /** O e-mail do GLPI está ligado (Configurar → Notificações)? */
    public static function mailEnabled(): bool
    {
        global $CFG_GLPI;
        return !empty($CFG_GLPI['use_notifications']) && !empty($CFG_GLPI['notifications_mailing']);
    }

    private static function translation(string $event): ?NotificationTemplateTranslation
    {
        global $DB;
        $row = $DB->request([
            'SELECT'     => ['tr.id'],
            'FROM'       => NotificationTemplateTranslation::getTable() . ' AS tr',
            'INNER JOIN' => [
                NotificationTemplate::getTable() . ' AS t'              => ['ON' => ['t' => 'id', 'tr' => 'notificationtemplates_id']],
                Notification_NotificationTemplate::getTable() . ' AS l' => ['ON' => ['l' => 'notificationtemplates_id', 't' => 'id']],
                Notification::getTable() . ' AS n'                      => ['ON' => ['n' => 'id', 'l' => 'notifications_id']],
            ],
            'WHERE'      => ['n.itemtype' => Survey::class, 'n.event' => $event, 'tr.language' => ''],
            'LIMIT'      => 1,
        ])->current();
        $translation = new NotificationTemplateTranslation();
        return $row && $translation->getFromDB((int) $row['id']) ? $translation : null;
    }

    private static function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>'], ["\n", "\n\n", "\n"], $html))));
    }

    public static function uninstall(): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => Survey::class]]) as $n) {
            foreach ($DB->request(['FROM' => Notification_NotificationTemplate::getTable(), 'WHERE' => ['notifications_id' => $n['id']]]) as $link) {
                (new NotificationTemplate())->delete(['id' => $link['notificationtemplates_id']], true);
            }
            (new Notification())->delete(['id' => $n['id']], true);
        }
    }
}
