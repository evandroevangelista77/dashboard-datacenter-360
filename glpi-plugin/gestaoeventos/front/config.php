<?php

/** Etapas e SLOs: grupo responsável, SLO em dias, alerta, relógio e se a etapa está ativa. */

use GlpiPlugin\Gestaoeventos\Etapa;
use GlpiPlugin\Gestaoeventos\Menu;

Session::checkRight('config', UPDATE);

global $DB;
if (isset($_POST['save'])) {
    foreach ((array) ($_POST['etapa'] ?? []) as $id => $row) {
        $DB->update(Etapa::getTable(), [
            'name'       => mb_substr(trim((string) ($row['name'] ?? '')), 0, 255) ?: null,
            'applies'    => isset(Etapa::APPLIES[$row['applies'] ?? '']) ? $row['applies'] : 'all',
            'groups_id'  => max(0, (int) ($row['groups_id'] ?? 0)),
            'slo_days'   => max(0, min(365, (int) ($row['slo_days'] ?? 0))),
            'warn_pct'   => max(0, min(100, (int) ($row['warn_pct'] ?? 80))),
            'clock_from' => isset(Etapa::CLOCKS[$row['clock_from'] ?? '']) ? $row['clock_from'] : 'activation',
            'is_active'  => empty($row['is_active']) ? 0 : 1,
            'date_mod'   => date('Y-m-d H:i:s'),
        ], ['id' => (int) $id]);
    }
    Config::setConfigurationValues('plugin:gestaoeventos', ['approval_sla_days' => (string) max(0, min(60, (int) ($_POST['approval_sla_days'] ?? 3)))]);
    Session::addMessageAfterRedirect('Etapas salvas. Valem para as etapas que começarem daqui em diante.');
    Html::back();
}

Menu::header('Etapas e SLOs', 'config');
Etapa::showConfig();
Html::footer();
