<?php

namespace GlpiPlugin\Pmo;

use CommonGLPI;
use CronTask;
use Html;
use Notification;
use Notification_NotificationTemplate;
use NotificationEvent;
use NotificationTarget;
use NotificationTargetProject;
use NotificationTargetProjectTask;
use NotificationTemplate;
use NotificationTemplateTranslation;
use Project;
use ProjectTask;

/**
 * Notificações do PMO pelo sistema de notificações do GLPI.
 *
 * Os eventos entram nos alvos nativos (hook item_get_events), então destinatários,
 * modelos e ativação ficam em Configurar → Notificações, como os do GLPI:
 * - Projeto: sprint iniciada, sprint concluída e risco crítico (gerente e equipe)
 * - Tarefa de projeto: tarefa atrasada (equipe da tarefa), pela ação automática pmolatetasks
 * Os dados do PMO chegam ao modelo como ##pmo.*## (hook item_get_datas).
 */
class Notifications extends CommonGLPI
{
    public const EVENTS = [
        Project::class     => [
            'pmo_sprint_start' => 'PMO: sprint iniciada',
            'pmo_sprint_end'   => 'PMO: sprint concluída',
            'pmo_risk'         => 'PMO: risco crítico',
        ],
        ProjectTask::class => [
            'pmo_task_late' => 'PMO: tarefa atrasada',
        ],
    ];

    /** Destinatários criados na instalação (podem ser mudados na notificação). */
    private const TARGETS = [
        Project::class     => [Notification::MANAGER_USER, Notification::MANAGER_GROUP, Notification::TEAM_USER, Notification::TEAM_GROUP],
        ProjectTask::class => [Notification::TEAM_USER, Notification::TEAM_GROUP],
    ];

    private const TARGET_ITEMTYPES = [
        NotificationTargetProject::class     => Project::class,
        NotificationTargetProjectTask::class => ProjectTask::class,
    ];

    public static function getTypeName($nb = 0)
    {
        return 'PMO';
    }

    /** Hook item_get_events: acrescenta os eventos do PMO aos alvos nativos. */
    public static function addEvents(NotificationTarget $target): void
    {
        $itemtype = self::TARGET_ITEMTYPES[get_class($target)] ?? null;
        foreach (self::EVENTS[$itemtype] ?? [] as $event => $label) {
            $target->events[$event] = $label;
        }
    }

    /** Hook item_get_datas: os dados passados no disparo viram tags ##pmo.*##. */
    public static function addData(NotificationTarget $target): void
    {
        foreach ($target->options['pmo'] ?? [] as $tag => $value) {
            $target->data["##pmo.$tag##"] = (string) $value;
        }
    }

    // ------------------------------------------------------------------ disparos

    public static function sprintStarted(int $projectId, array $sprint, float $committed, int $items, string $unit): void
    {
        self::raise('pmo_sprint_start', $projectId, [
            'sprint'    => $sprint['name'],
            'goal'      => $sprint['goal'] ?: '—',
            'start'     => Html::convDate($sprint['start_date']),
            'end'       => Html::convDate($sprint['end_date']),
            'committed' => self::num($committed),
            'items'     => $items,
            'unit'      => $unit,
            'url'       => self::absolute(View::projectUrl($projectId, 'board')),
        ]);
    }

    public static function sprintClosed(int $projectId, array $sprint, array $result, string $unit, string $targetName): void
    {
        $total = (float) $result['total'];
        self::raise('pmo_sprint_end', $projectId, [
            'sprint'    => $sprint['name'],
            'goal'      => $sprint['goal'] ?: '—',
            'start'     => Html::convDate($sprint['start_date']),
            'end'       => Html::convDate($sprint['end_date']),
            'committed' => self::num($total),
            'completed' => self::num((float) $result['done']),
            'rate'      => $total > 0 ? (int) round(100 * $result['done'] / $total) : 0,
            'moved'     => (int) $result['moved'],
            'target'    => $targetName,
            'unit'      => $unit,
            'url'       => self::absolute(View::projectUrl($projectId, 'dashboard')),
        ]);
    }

    public static function riskCritical(int $projectId, array $risk): void
    {
        [$level] = Risk::level((int) $risk['probability'] * (int) $risk['impact']);
        self::raise('pmo_risk', $projectId, [
            'risk'        => $risk['name'],
            'kind'        => Risk::KINDS[$risk['kind']] ?? $risk['kind'],
            'description' => $risk['description'] ?: '—',
            'probability' => (int) $risk['probability'],
            'impact'      => (int) $risk['impact'],
            'score'       => (int) $risk['probability'] * (int) $risk['impact'],
            'level'       => $level,
            'owner'       => $risk['users_id'] ? (string) getUserName((int) $risk['users_id']) : '—',
            'due'         => $risk['due_date'] ? Html::convDate($risk['due_date']) : '—',
            'response'    => $risk['response'] ?: '—',
            'status'      => Risk::STATUSES[$risk['status']] ?? $risk['status'],
            'url'         => self::absolute(View::projectUrl($projectId, 'risks', ['risk' => $risk['id']])),
        ]);
    }

    private static function raise(string $event, int $projectId, array $data): void
    {
        $project = new Project();
        if ($project->getFromDB($projectId)) {
            NotificationEvent::raiseEvent($event, $project, ['pmo' => $data]);
        }
    }

    // ------------------------------------------------------------------ tarefas atrasadas

    public static function cronInfo($name)
    {
        return ['description' => 'PMO: avisa as tarefas que passaram do fim planejado'];
    }

    /**
     * Ação automática pmolatetasks: tarefas abertas dos projetos com PMO que
     * passaram do fim planejado. Cada tarefa é avisada uma vez por fim planejado
     * (se o prazo for mudado e vencer de novo, avisa de novo).
     */
    public static function cronPmolatetasks(CronTask $task): int
    {
        global $DB, $CFG_GLPI;
        if (!$CFG_GLPI['use_notifications']) {
            return 0;
        }
        $sent = 0;
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $it = $DB->request([
            'SELECT'     => ['t.id', 't.plan_end_date', 'i.late_notified'],
            'FROM'       => 'glpi_projecttasks AS t',
            'INNER JOIN' => [
                'glpi_plugin_pmo_items AS i'    => ['ON' => ['i' => 'projecttasks_id', 't' => 'id']],
                'glpi_plugin_pmo_columns AS c'  => ['ON' => ['c' => 'id', 'i' => 'columns_id']],
                'glpi_plugin_pmo_projects AS p' => ['ON' => ['p' => 'projects_id', 't' => 'projects_id']],
                'glpi_projects AS g'            => ['ON' => ['g' => 'id', 't' => 'projects_id']],
            ],
            'WHERE'      => [
                't.is_deleted'    => 0,
                't.is_template'   => 0,
                'g.is_deleted'    => 0,
                't.percent_done'  => ['<', 100],
                'c.kind'          => ['<>', 'done'],
                't.plan_end_date' => ['<', $now], // também deixa de fora as tarefas sem fim planejado
            ],
            'LIMIT'      => 200,
        ]);
        foreach ($it as $row) {
            if ($row['late_notified'] === $row['plan_end_date']) {
                continue; // já avisada para este prazo
            }
            $pt = new ProjectTask();
            if (!$pt->getFromDB($row['id'])) {
                continue;
            }
            $team = array_map(fn ($u) => Board::person($u)['name'], Board::tasks((int) $pt->fields['projects_id'])[$pt->getID()]['users'] ?? []);
            NotificationEvent::raiseEvent('pmo_task_late', $pt, ['pmo' => [
                'days' => max(1, (int) floor((strtotime($now) - strtotime($row['plan_end_date'])) / DAY_TIMESTAMP)),
                'team' => $team ? implode(', ', $team) : '—',
                'url'  => self::absolute(View::projectUrl((int) $pt->fields['projects_id'], 'board')),
            ]]);
            $DB->update('glpi_plugin_pmo_items', ['late_notified' => $row['plan_end_date']], ['projecttasks_id' => $row['id']]);
            $sent++;
        }
        $task->addVolume($sent);
        return $sent ? 1 : 0;
    }

    // ------------------------------------------------------------------ instalação

    /** Cria os modelos e as notificações que ainda não existem (idempotente). */
    public static function install(): void
    {
        foreach (self::templates() as $event => [$itemtype, $name, $subject, $html, $text]) {
            $notification = new Notification();
            if ($notification->getFromDBByCrit(['itemtype' => $itemtype, 'event' => $event])) {
                continue;
            }
            $template = new NotificationTemplate();
            $templateId = (int) $template->add(['name' => $name, 'itemtype' => $itemtype, 'comment' => 'Criado pelo plugin PMO']);
            (new NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $templateId,
                'language'                 => '',
                'subject'                  => $subject,
                'content_text'             => $text,
                'content_html'             => $html,
            ]);
            $notificationId = (int) $notification->add([
                'name'         => $name,
                'itemtype'     => $itemtype,
                'event'        => $event,
                'entities_id'  => 0,
                'is_recursive' => 1,
                'is_active'    => 1,
                'comment'      => 'Criada pelo plugin PMO',
            ]);
            (new Notification_NotificationTemplate())->add([
                'notifications_id'         => $notificationId,
                'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
                'notificationtemplates_id' => $templateId,
            ]);
            foreach (self::TARGETS[$itemtype] as $recipient) {
                (new NotificationTarget())->add(['notifications_id' => $notificationId, 'type' => Notification::USER_TYPE, 'items_id' => $recipient]);
            }
        }
    }

    /** Remove as notificações e os modelos do PMO. */
    public static function uninstall(): void
    {
        global $DB;
        foreach (self::EVENTS as $itemtype => $events) {
            foreach ($DB->request(['FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'event' => array_keys($events)]]) as $n) {
                foreach ($DB->request(['FROM' => Notification_NotificationTemplate::getTable(), 'WHERE' => ['notifications_id' => $n['id']]]) as $link) {
                    (new NotificationTemplate())->delete(['id' => $link['notificationtemplates_id']], true);
                }
                (new Notification())->delete(['id' => $n['id']], true);
            }
        }
    }

    /** @return array<string, array{0: class-string, 1: string, 2: string, 3: string, 4: string}> */
    private static function templates(): array
    {
        $foot = '<p style="color:#64748b;font-size:12px">Enviado pelo PMO do GLPI.</p>';
        return [
            'pmo_sprint_start' => [Project::class, 'PMO - Sprint iniciada',
                '[PMO] Sprint iniciada: ##pmo.sprint## - ##project.name##',
                '<p>A sprint <strong>##pmo.sprint##</strong> do projeto <a href="##project.url##">##project.name##</a> foi iniciada.</p>'
                . '<p><strong>Período:</strong> ##pmo.start## a ##pmo.end##<br><strong>Meta:</strong> ##pmo.goal##<br>'
                . '<strong>Comprometido:</strong> ##pmo.committed## ##pmo.unit## em ##pmo.items## item(ns)</p>'
                . '<p><a href="##pmo.url##">Abrir o quadro da sprint</a></p>' . $foot,
                "A sprint ##pmo.sprint## do projeto ##project.name## foi iniciada.\nPeríodo: ##pmo.start## a ##pmo.end##\nMeta: ##pmo.goal##\nComprometido: ##pmo.committed## ##pmo.unit## em ##pmo.items## item(ns)\nQuadro: ##pmo.url##"],
            'pmo_sprint_end' => [Project::class, 'PMO - Sprint concluída',
                '[PMO] Sprint concluída: ##pmo.sprint## - ##project.name## (##pmo.rate##%)',
                '<p>A sprint <strong>##pmo.sprint##</strong> do projeto <a href="##project.url##">##project.name##</a> foi concluída.</p>'
                . '<p><strong>Período:</strong> ##pmo.start## a ##pmo.end##<br><strong>Meta:</strong> ##pmo.goal##<br>'
                . '<strong>Entregue:</strong> ##pmo.completed## de ##pmo.committed## ##pmo.unit## (##pmo.rate##%)<br>'
                . '<strong>Itens pendentes:</strong> ##pmo.moved##, movidos para ##pmo.target##</p>'
                . '<p><a href="##pmo.url##">Ver os painéis do projeto</a></p>' . $foot,
                "A sprint ##pmo.sprint## do projeto ##project.name## foi concluída.\nEntregue: ##pmo.completed## de ##pmo.committed## ##pmo.unit## (##pmo.rate##%)\nPendentes: ##pmo.moved##, movidos para ##pmo.target##\nPainéis: ##pmo.url##"],
            'pmo_risk' => [Project::class, 'PMO - Risco crítico',
                '[PMO] Risco crítico: ##pmo.risk## - ##project.name##',
                '<p>Foi registrado um item de nível <strong style="color:#b91c1c">##pmo.level##</strong> no projeto <a href="##project.url##">##project.name##</a>.</p>'
                . '<p><strong>##pmo.kind##:</strong> ##pmo.risk##<br><strong>Probabilidade × impacto:</strong> ##pmo.probability## × ##pmo.impact## = ##pmo.score##<br>'
                . '<strong>Responsável:</strong> ##pmo.owner##<br><strong>Prazo:</strong> ##pmo.due##<br><strong>Status:</strong> ##pmo.status##</p>'
                . '<p><strong>Descrição:</strong> ##pmo.description##</p><p><strong>Plano de resposta:</strong> ##pmo.response##</p>'
                . '<p><a href="##pmo.url##">Abrir o registro de riscos</a></p>' . $foot,
                "Item de nível ##pmo.level## no projeto ##project.name##.\n##pmo.kind##: ##pmo.risk##\nProbabilidade x impacto: ##pmo.probability## x ##pmo.impact## = ##pmo.score##\nResponsável: ##pmo.owner## - Prazo: ##pmo.due##\nPlano de resposta: ##pmo.response##\nRiscos: ##pmo.url##"],
            'pmo_task_late' => [ProjectTask::class, 'PMO - Tarefa atrasada',
                '[PMO] Tarefa atrasada: ##projecttask.name## (##pmo.days## dia(s))',
                '<p>A tarefa <a href="##projecttask.url##"><strong>##projecttask.name##</strong></a> do projeto ##projecttask.project## passou do fim planejado.</p>'
                . '<p><strong>Fim planejado:</strong> ##projecttask.planenddate##<br><strong>Atraso:</strong> ##pmo.days## dia(s)<br>'
                . '<strong>Concluído:</strong> ##projecttask.percent##<br><strong>Responsáveis:</strong> ##pmo.team##</p>'
                . '<p><a href="##pmo.url##">Abrir o quadro do projeto</a></p>' . $foot,
                "A tarefa ##projecttask.name## do projeto ##projecttask.project## passou do fim planejado (##projecttask.planenddate##).\nAtraso: ##pmo.days## dia(s) - Concluído: ##projecttask.percent##\nResponsáveis: ##pmo.team##\nTarefa: ##projecttask.url##"],
        ];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }

    /** Link completo (o e-mail é lido fora do GLPI). */
    private static function absolute(string $path): string
    {
        global $CFG_GLPI;
        $base = rtrim((string) $CFG_GLPI['url_base'], '/');
        $root = rtrim((string) $CFG_GLPI['root_doc'], '/');
        return $base . ($root !== '' && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path);
    }
}
