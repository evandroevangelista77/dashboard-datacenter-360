<?php

/** Fases e SLOs: grupo responsável, SLO em dias e se a fase está ativa. */

use GlpiPlugin\Ciclo\Etapa;
use GlpiPlugin\Ciclo\Menu;

Session::checkRight('config', UPDATE);

global $DB;
if (isset($_POST['save'])) {
    foreach ((array) ($_POST['etapa'] ?? []) as $id => $row) {
        $DB->update(Etapa::getTable(), [
            'name'      => mb_substr(trim((string) ($row['name'] ?? '')), 0, 255) ?: null,
            'groups_id' => max(0, (int) ($row['groups_id'] ?? 0)),
            'slo_days'  => max(0, min(365, (int) ($row['slo_days'] ?? 0))),
            'is_active' => empty($row['is_active']) ? 0 : 1,
            'date_mod'  => date('Y-m-d H:i:s'),
        ], ['id' => (int) $id, 'NOT' => ['code' => 'aviso']]);
    }
    Config::setConfigurationValues('plugin:ciclo', ['tickets' => empty($_POST['tickets']) ? '0' : '1']);
    Session::addMessageAfterRedirect('Fases salvas. Valem para as fases que começarem daqui em diante.');
    Html::back();
}

Menu::header('Fases e SLOs', 'config');
Etapa::showConfig();
Html::footer();
