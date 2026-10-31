<?php

namespace GlpiPlugin\Gestaoeventos;

use ProjectState;
use ProjectTask;
use Throwable;

/**
 * Ligação entre os marcos do evento e as tarefas do projeto do GLPI (um projeto por evento). O marco guarda o id
 * da tarefa; concluir a tarefa (100% ou estado "finalizado") conclui o marco, e mudar a data de uma ou de outro
 * atualiza a outra. O projeto continua dono do cronograma; o evento, da aprovação, do orçamento e do fluxo.
 */
class Projeto
{
    /** evita laço: a gravação de um lado dispara o gancho do outro */
    private static bool $busy = false;

    public static function taskId(array $marco): int
    {
        return (int) ((json_decode((string) $marco['extra'], true) ?: [])['task'] ?? 0);
    }

    private static function stateId(bool $finished): int
    {
        global $DB;
        $r = $DB->request(['SELECT' => 'id', 'FROM' => ProjectState::getTable(), 'WHERE' => ['is_finished' => $finished ? 1 : 0], 'ORDER' => 'id', 'LIMIT' => 1])->current();
        return $r ? (int) $r['id'] : 0;
    }

    /** Cria a tarefa do projeto para um marco que ainda não tem e guarda o vínculo. Devolve o id (0 se não deu). */
    public static function ensureTask(Evento $ev, array $marco): int
    {
        global $DB;
        $project = (int) $ev->fields['projects_id'];
        if (!$project || self::taskId($marco) || !$marco['date_due']) {
            return self::taskId($marco);
        }
        try {
            self::$busy = true;
            $done = $marco['status'] === 'feito';
            $id = (int) (new ProjectTask())->add([
                'name' => $marco['title'], 'projects_id' => $project, 'entities_id' => (int) $ev->fields['entities_id'], 'plan_start_date' => $marco['date_due'] . ' 08:00:00', 'plan_end_date' => $marco['date_due'] . ' 18:00:00',
                'percent_done' => $done ? 100 : 0, 'projectstates_id' => $done ? self::stateId(true) : 0, 'users_id' => (int) ($marco['users_id_owner'] ?: $ev->fields['users_id_gp']),
            ]);
        } catch (Throwable $e) {
            trigger_error('Gestão de Eventos: tarefa do projeto não criada: ' . $e->getMessage(), E_USER_WARNING);
            $id = 0;
        } finally {
            self::$busy = false;
        }
        if ($id) {
            $extra = (json_decode((string) $marco['extra'], true) ?: []) + [];
            $extra['task'] = $id;
            $DB->update(Registro::getTable(), ['extra' => json_encode($extra)], ['id' => (int) $marco['id']]);
        }
        return $id;
    }

    /** Cria as tarefas dos marcos que ainda não têm (botão "Sincronizar com o projeto"). */
    public static function backfill(Evento $ev): int
    {
        $n = 0;
        foreach (Registro::forEvento($ev->getID(), 'marco') as $m) {
            if (!self::taskId($m) && self::ensureTask($ev, $m)) {
                $n++;
            }
        }
        if ($n) {
            Historico::record($ev->getID(), 'planejamento', 'change', "Marcos ligados ao projeto: $n tarefa(s) criada(s).");
        }
        return $n;
    }

    /** Marco alterado no evento: leva prazo e conclusão para a tarefa do projeto. */
    public static function marcoToTask(Evento $ev, array $marco): void
    {
        if (self::$busy || !(int) $ev->fields['projects_id']) {
            return;
        }
        $tid = self::taskId($marco);
        if (!$tid) {
            self::ensureTask($ev, $marco);
            return;
        }
        $t = new ProjectTask();
        if (!$t->getFromDB($tid)) {
            return;
        }
        $in = ['id' => $tid];
        if ($marco['date_due'] && substr((string) $t->fields['plan_end_date'], 0, 10) !== $marco['date_due']) {
            $in['plan_end_date'] = $marco['date_due'] . ' 18:00:00';
            if (!$t->fields['plan_start_date'] || substr((string) $t->fields['plan_start_date'], 0, 10) > $marco['date_due']) {
                $in['plan_start_date'] = $marco['date_due'] . ' 08:00:00';
            }
        }
        $done = $marco['status'] === 'feito';
        if ($done && (int) $t->fields['percent_done'] < 100) {
            $in['percent_done'] = 100;
            $in['projectstates_id'] = self::stateId(true) ?: $t->fields['projectstates_id'];
        } elseif (!$done && (int) $t->fields['percent_done'] >= 100) {
            $in['percent_done'] = 0;
            $in['projectstates_id'] = self::stateId(false) ?: 0;
        }
        if (count($in) > 1) {
            try {
                self::$busy = true;
                $t->update($in);
            } catch (Throwable $e) {
                trigger_error('Gestão de Eventos: tarefa do projeto não atualizada: ' . $e->getMessage(), E_USER_WARNING);
            } finally {
                self::$busy = false;
            }
        }
    }

    /** Gancho do GLPI: tarefa do projeto alterada → atualiza o marco do evento que aponta para ela. */
    public static function taskToMarco(ProjectTask $task): void
    {
        global $DB;
        if (self::$busy) {
            return;
        }
        $tid = (int) $task->getID();
        $rows = iterator_to_array($DB->request(['FROM' => Registro::getTable(), 'WHERE' => ['kind' => 'marco', 'OR' => [['extra' => ['LIKE', '%"task":' . $tid . ',%']], ['extra' => ['LIKE', '%"task":' . $tid . '}%']]]]]), false);
        foreach ($rows as $m) {
            if (self::taskId($m) !== $tid) {
                continue;
            }
            $ev = new Evento();
            if (!$ev->getFromDB((int) $m['plugin_gestaoeventos_eventos_id'])) {
                continue;
            }
            $state = $DB->request(['SELECT' => 'is_finished', 'FROM' => ProjectState::getTable(), 'WHERE' => ['id' => (int) $task->fields['projectstates_id']]])->current();
            $finished = (int) $task->fields['percent_done'] >= 100 || (bool) ($state['is_finished'] ?? 0);
            $upd = [];
            $why = [];
            if ($finished && $m['status'] !== 'feito') {
                $upd += ['status' => 'feito', 'date_done' => date('Y-m-d', strtotime($_SESSION['glpi_currenttime'] ?? 'now'))];
                $why[] = 'concluído pela tarefa do projeto';
            } elseif (!$finished && $m['status'] === 'feito') {
                $upd += ['status' => 'andamento', 'date_done' => null];
                $why[] = 'reaberto pela tarefa do projeto';
            }
            $end = substr((string) $task->fields['plan_end_date'], 0, 10);
            if ($end && $end !== $m['date_due']) {
                $upd['date_due'] = $end;
                $why[] = 'prazo ' . Menu::date($end);
            }
            if ($upd) {
                $DB->update(Registro::getTable(), $upd + ['date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')], ['id' => $m['id']]);
                Historico::record($ev->getID(), 'planejamento', 'change', 'Marco "' . $m['title'] . '": ' . implode('; ', $why) . '.');
                Fase::maybeAdvance($ev);
            }
        }
    }

    /** Eventos já ligados a um projeto (para não repetir o projeto em outro evento). */
    public static function usedBy(int $projectId, int $exceptEventoId = 0): ?array
    {
        global $DB;
        return $DB->request(['FROM' => Evento::getTable(), 'WHERE' => ['projects_id' => $projectId, 'is_deleted' => 0, 'NOT' => ['id' => $exceptEventoId]], 'LIMIT' => 1])->current() ?: null;
    }

    public static function usedIds(int $exceptEventoId = 0): array
    {
        global $DB;
        return array_map(fn ($r) => (int) $r['projects_id'], iterator_to_array($DB->request(['SELECT' => 'projects_id', 'FROM' => Evento::getTable(), 'WHERE' => ['is_deleted' => 0, ['projects_id' => ['>', 0]], 'NOT' => ['id' => $exceptEventoId]]]), false));
    }
}
