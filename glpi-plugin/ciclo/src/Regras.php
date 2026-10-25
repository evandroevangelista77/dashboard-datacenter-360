<?php

namespace GlpiPlugin\Ciclo;

use CronTask;

/**
 * Regras fixas do Ciclo (sem IA): fase que passou do SLO avisa a área, o GP e
 * o OTC uma vez por vez que a fase é iniciada.
 */
class Regras
{
    public static function cronInfo($name)
    {
        return ['description' => 'Ciclo Financeiro: avisa as fases que passaram do SLO'];
    }

    public static function cronCicloatrasos(CronTask $task): int
    {
        global $DB;
        $sent = 0;
        foreach ($DB->request([
            'SELECT' => ['f.*'], 'FROM' => Fase::getTable() . ' AS f',
            'INNER JOIN' => [Ciclo::getTable() . ' AS c' => ['ON' => ['c' => 'id', 'f' => 'plugin_ciclo_ciclos_id']]],
            'WHERE' => ['f.status' => 'active', 'f.late_notified' => 0, 'f.slo_days' => ['>', 0], 'c.status' => Ciclo::OPEN, 'c.is_deleted' => 0],
        ]) as $f) {
            if (!Fase::isLate($f)) {
                continue;
            }
            $c = new Ciclo();
            if (!$c->getFromDB((int) $f['plugin_ciclo_ciclos_id'])) {
                continue;
            }
            $name = Fase::name($f['code']);
            $over = Menu::days(Fase::elapsed($f) - (int) $f['slo_days'] * 86400);
            Avisos::send($c, 'ciclo_atraso', array_merge(Avisos::members((int) $f['groups_id']), Avisos::followers($c)), "Fase atrasada: $name",
                "{$c->fields['name']} está em \"$name\" há " . Menu::days(Fase::elapsed($f)) . " dias (SLO de {$f['slo_days']}), $over dia(s) além do prazo.", 'danger',
                ['Fase' => $name, 'Área' => Fase::area($f), 'Na fase há' => Menu::days(Fase::elapsed($f)) . ' dias', 'SLO' => $f['slo_days'] . ' dia(s)']);
            $DB->update(Fase::getTable(), ['late_notified' => 1], ['id' => $f['id']]);
            $sent++;
        }
        $task->addVolume($sent);
        return $sent ? 1 : 0;
    }
}
