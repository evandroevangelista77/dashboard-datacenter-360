<?php

namespace GlpiPlugin\Pmo;

use CommonGLPI;
use Project;
use Session;

/**
 * Aba "PMO" no Projeto: resumo e atalhos para quadro, backlog, Gantt e painéis
 * (que abrem em página inteira, com espaço para o quadro e o Gantt).
 */
class ProjectTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Project || $item->isNewItem() || !Session::haveRight(Menu::$rightname, READ)) {
            return '';
        }
        return [
            1 => self::createTabEntry('PMO', 0, null, Menu::getIcon()),
            2 => self::createTabEntry('Gantt', 0, null, 'ti ti-timeline'),
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $DB;
        if (!$item instanceof Project) {
            return false;
        }
        if ((int) $tabnum === 2) {
            echo "<div class='p-2 pmo'>" . View::ganttMount($item) . "</div>";
            return true;
        }
        $id = (int) $item->getID();
        $config = ProjectConfig::get($id);
        $e = [View::class, 'e'];

        echo "<div class='p-3 pmo'>";
        if (!$config) {
            echo "<div class='card'><div class='card-body'><h3 class='card-title'><i class='ti ti-briefcase me-2'></i>PMO</h3>"
                . "<p class='text-secondary'>Este projeto ainda não usa o PMO. Ative para ter quadro Kanban, backlog e sprints, Gantt, painéis, documentação e registro de riscos sobre as tarefas do projeto.</p>"
                . "<a class='btn btn-primary' href='" . $e(View::projectUrl($id, 'board')) . "'><i class='ti ti-rocket me-1'></i>Ativar o PMO neste projeto</a></div></div></div>";
            return true;
        }

        $columns = ProjectConfig::columns($id);
        $kinds = array_column($columns, 'kind', 'id');
        $tasks = Board::sync($id);
        $leaves = Metrics::leaves($tasks);
        $progress = Metrics::progress($item->fields, $tasks, $kinds, $config['estimation']);
        $done = count(array_filter($leaves, fn ($t) => Metrics::isDone($t, $kinds)));
        $late = count(array_filter($leaves, fn ($t) => !Metrics::isDone($t, $kinds) && $t['plan_end_date'] && strtotime($t['plan_end_date']) < time()));
        $health = Metrics::health($item->fields, $progress['planned'], $progress['actual'], $late);
        $sprint = Sprint::active($id);
        $risks = (int) $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_plugin_pmo_risks', 'WHERE' => ['projects_id' => $id, 'status' => ['open', 'mitigating']]])->current()['c'];

        $tile = fn ($label, $value, $sub = '') => "<div class='col-6 col-md-3 col-xl-2'><div class='pmo-kpi'><div class='pmo-kpi-label'>$label</div><div class='pmo-kpi-value'>$value</div><div class='pmo-kpi-sub'>$sub</div></div></div>";
        echo "<div class='row g-2 mb-3'>";
        echo $tile('Metodologia', $e(ProjectConfig::METHODOLOGIES[$config['methodology']] ?? ''), '');
        echo $tile('Saúde', "<span class='pmo-health pmo-health-{$health['code']}'></span>" . $e($health['label']), '');
        echo $tile('% real', $progress['actual'] . '%', $progress['planned'] !== null ? 'planejado ' . $progress['planned'] . '%' : '');
        echo $tile('Tarefas', "$done / " . count($leaves), 'concluídas');
        echo $tile('Atrasadas', (string) $late, '');
        echo $tile($sprint ? 'Sprint atual' : 'Riscos abertos', $sprint ? $e($sprint['name']) : (string) $risks, $sprint ? 'até ' . \Html::convDate($sprint['end_date']) : '');
        echo "</div><div class='d-flex flex-wrap gap-2'>";
        foreach (View::VIEWS as $key => [$label, $icon]) {
            echo "<a class='btn btn-outline-primary' href='" . $e(View::projectUrl($id, $key)) . "'><i class='$icon me-1'></i>$label</a>";
        }
        echo "</div></div>";
        return true;
    }

    /** Gantt logo abaixo do formulário, na tela principal do projeto (hook post_item_form). */
    public static function showGanttOnForm(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof Project || $item->isNewItem() || !Session::haveRight(Menu::$rightname, READ) || !$item->canViewItem()) {
            return;
        }
        $id = (int) $item->getID();
        echo "<div class='pmo pmo-form-gantt mt-3'><div class='d-flex align-items-center gap-2 mb-2'>"
            . "<h3 class='m-0'><i class='ti ti-timeline me-2'></i>Gantt das atividades</h3>"
            . "<a class='btn btn-sm btn-ghost-secondary ms-auto' href='" . View::e(View::projectUrl($id, 'gantt')) . "'><i class='ti ti-maximize me-1'></i>Tela cheia</a></div>"
            . View::ganttMount($item, true) . "</div>";
    }
}
