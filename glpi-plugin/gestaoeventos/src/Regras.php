<?php

namespace GlpiPlugin\Gestaoeventos;

use CronTask;

/**
 * Regras fixas do fluxo (sem IA), rodando de hora em hora: etapa perto do SLO ou atrasada, aprovação vencida,
 * marco crítico e checklist crítico vencidos e prazo crítico chegando. Cada aviso sai uma vez por ocorrência.
 */
class Regras
{
    public static function cronInfo($name)
    {
        return ['description' => 'Gestão de Eventos: avisa etapas perto do SLO ou atrasadas, aprovações vencidas e marcos críticos'];
    }

    public static function cronEventosatrasos(CronTask $task): int
    {
        global $DB;
        $sent = 0;
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $today = date('Y-m-d', strtotime($now));

        // etapas ativas: alerta preventivo (late_notified 1) e atraso (2)
        foreach ($DB->request([
            'SELECT' => ['f.*'], 'FROM' => Fase::getTable() . ' AS f',
            'INNER JOIN' => [Evento::getTable() . ' AS c' => ['ON' => ['c' => 'id', 'f' => 'plugin_gestaoeventos_eventos_id']]],
            'WHERE' => ['f.status' => 'active', 'f.late_notified' => ['<', 2], 'f.slo_days' => ['>', 0], 'c.status' => Evento::OPEN, 'c.is_deleted' => 0],
        ]) as $f) {
            $isLate = Fase::isLate($f);
            if (!$isLate && !(Fase::isWarn($f) && (int) $f['late_notified'] === 0)) {
                continue;
            }
            $ev = new Evento();
            if (!$ev->getFromDB((int) $f['plugin_gestaoeventos_eventos_id'])) {
                continue;
            }
            $name = Fase::name($f['code']);
            $elapsed = Fase::elapsed($f);
            $facts = ['Etapa' => $name, 'Área' => Fase::area($f), 'Na etapa há' => Menu::days($elapsed) . ' dias', 'SLO' => $f['slo_days'] . ' dia(s)'];
            if ($isLate) {
                $over = Menu::days($elapsed - (int) $f['slo_days'] * 86400);
                Avisos::send($ev, 'evento_atraso', array_merge(Avisos::members((int) $f['groups_id']), Avisos::followers($ev)), "Etapa atrasada: $name",
                    "{$ev->fields['name']} está em \"$name\" há " . Menu::days($elapsed) . " dias (SLO de {$f['slo_days']}), $over dia(s) além do prazo.", 'danger', $facts);
                Evento::syncProjectStatus($ev);
            } else {
                Avisos::send($ev, 'evento_prazo', array_merge(Avisos::members((int) $f['groups_id']), [(int) $ev->fields['users_id_gp']]), "Etapa perto do prazo: $name",
                    "{$ev->fields['name']} já consumiu {$f['warn_pct']}% do SLO de \"$name\" (" . Menu::days($elapsed) . ' de ' . $f['slo_days'] . ' dias).', 'warning', $facts);
            }
            $DB->update(Fase::getTable(), ['late_notified' => $isLate ? 2 : 1], ['id' => $f['id']]);
            $sent++;
        }

        // aprovações vencidas
        foreach ($DB->request(['FROM' => Aprovacao::getTable(), 'WHERE' => ['status' => 'pending', 'overdue_notified' => 0, 'date_due' => ['<', $now]]]) as $a) {
            $ev = new Evento();
            if (!$ev->getFromDB((int) $a['plugin_gestaoeventos_eventos_id']) || (int) $ev->fields['status'] !== Evento::OPEN) {
                continue;
            }
            Avisos::send($ev, 'aprovacao_vencida', array_merge(Aprovacao::approverIds($a), [(int) $a['users_id_requested']]), 'Aprovação vencida: ' . (Aprovacao::KINDS[$a['kind']] ?? ''),
                $a['title'] . ' — prazo de ' . Menu::date($a['date_due'], true) . ' ultrapassado. Alçada: ' . $a['label'] . '.', 'danger', ['Alçada' => (string) $a['label']]);
            $DB->update(Aprovacao::getTable(), ['overdue_notified' => 1], ['id' => $a['id']]);
            $sent++;
        }

        // marcos e checklists críticos vencidos (um aviso por item), e prazo crítico em até 3 dias
        foreach ($DB->request(['FROM' => Registro::getTable(), 'WHERE' => ['kind' => ['marco', 'checklist'], 'is_critical' => 1, 'status' => ['a_fazer', 'andamento', 'bloqueado'], 'date_due' => ['<', $today]]]) as $r) {
            $extra = json_decode((string) $r['extra'], true) ?: [];
            if (!empty($extra['late_notified'])) {
                continue;
            }
            $ev = new Evento();
            if (!$ev->getFromDB((int) $r['plugin_gestaoeventos_eventos_id']) || (int) $ev->fields['status'] !== Evento::OPEN) {
                continue;
            }
            Avisos::send($ev, 'item_critico_vencido', array_merge(Avisos::followers($ev), [(int) $r['users_id_owner']]), ($r['kind'] === 'marco' ? 'Marco crítico vencido: ' : 'Item crítico vencido: ') . $r['title'],
                "Prazo de " . Menu::date($r['date_due']) . " em {$ev->fields['name']}.", 'danger', ['Item' => (string) $r['title']]);
            $DB->update(Registro::getTable(), ['extra' => json_encode($extra + ['late_notified' => $today])], ['id' => $r['id']]);
            Evento::syncProjectStatus($ev);
            $sent++;
        }
        foreach ($DB->request(['FROM' => Evento::getTable(), 'WHERE' => ['status' => Evento::OPEN, 'is_deleted' => 0, 'is_draft' => 0, ['date_critical' => ['>=', $today]], ['date_critical' => ['<=', date('Y-m-d', strtotime('+3 days', strtotime($today)))]]]]) as $c) {
            if (countElementsInTable(Historico::getTable(), ['plugin_gestaoeventos_eventos_id' => $c['id'], 'action' => 'integration', 'comment' => 'Aviso de prazo crítico próximo.'])) {
                continue;
            }
            $ev = new Evento();
            $ev->getFromDB((int) $c['id']);
            Avisos::send($ev, 'prazo_critico', Avisos::followers($ev), 'Prazo crítico se aproximando', "{$c['name']}: prazo crítico em " . Menu::date($c['date_critical']) . '.', 'warning');
            Historico::record((int) $c['id'], null, 'integration', 'Aviso de prazo crítico próximo.');
            $sent++;
        }
        $sent += Alerta::cronNotify();
        $task->addVolume($sent);
        return $sent ? 1 : 0;
    }
}
