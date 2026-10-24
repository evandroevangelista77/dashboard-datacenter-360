<?php

namespace GlpiPlugin\Rh;

use CommonGLPI;
use Html;
use Session;

/**
 * Decisões de aprovação (vagas e férias) e a tela "Minhas aprovações":
 * o que está esperando o usuário (como gestor ou como diretoria).
 */
class Aprovacoes extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Minhas aprovações';
    }

    public static function record(string $itemtype, int $id, string $step, string $decision, string $comment = ''): void
    {
        global $DB;
        $DB->insert('glpi_plugin_rh_aprovacoes', [
            'itemtype' => $itemtype, 'items_id' => $id, 'step' => $step, 'decision' => $decision,
            'users_id' => (int) Session::getLoginUserID(), 'comment' => $comment, 'date' => $_SESSION['glpi_currenttime'],
        ]);
    }

    public static function forItem(string $itemtype, int $id): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => 'glpi_plugin_rh_aprovacoes', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $id], 'ORDER' => 'id']), false);
    }

    /** Critérios das vagas que esperam o usuário. */
    private static function vagaCriteria(): array
    {
        $me = (int) Session::getLoginUserID();
        $or = [['status' => Vaga::WAIT_MANAGER, 'users_id_manager' => $me]];
        if (Session::haveRight(Vaga::$rightname, Vaga::APPROVE_BOARD)) {
            $or[] = ['status' => Vaga::WAIT_BOARD];
            $or[] = ['status' => Vaga::WAIT_MANAGER, 'users_id_manager' => 0];
        }
        return ['is_deleted' => 0, 'OR' => $or];
    }

    private static function feriasCriteria(): array
    {
        $me = (int) Session::getLoginUserID();
        $where = ['is_deleted' => 0, 'status' => Feria::PENDING];
        if (!Session::haveRight(Feria::$rightname, Feria::APPROVE)) {
            $where['users_id_manager'] = $me;
        }
        $where[] = ['NOT' => ['users_id' => $me]]; // ninguém aprova as próprias férias
        return $where;
    }

    public static function count(): int
    {
        global $DB;
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!isset($_SESSION['glpiID'])) {
            return $cache = 0;
        }
        return $cache = countElementsInTable(Vaga::getTable(), self::vagaCriteria()) + countElementsInTable(Feria::getTable(), self::feriasCriteria());
    }

    public static function show(): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        echo "<div class='rh'>" . Menu::title('Minhas aprovações', 'aprovacoes');

        $vagas = iterator_to_array($DB->request(['FROM' => Vaga::getTable(), 'WHERE' => self::vagaCriteria(), 'ORDER' => 'date_submit']), false);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-briefcase-2 me-2'></i>Vagas <span class='badge bg-primary ms-2'>" . count($vagas) . "</span></h3></div>";
        if (!$vagas) {
            echo "<div class='card-body text-secondary'>Nenhuma vaga esperando sua aprovação.</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Vaga</th><th>Departamento</th><th>Posições</th><th>Solicitante</th><th>Etapa</th><th>Enviada em</th><th class='w-25'>Decisão</th></tr></thead><tbody>";
            foreach ($vagas as $v) {
                $step = (int) $v['status'] === Vaga::WAIT_MANAGER ? 'manager' : 'board';
                echo "<tr><td><a href='" . $e(Vaga::getFormURLWithID((int) $v['id'])) . "' class='fw-bold'>" . $e($v['name']) . "</a><div class='small text-secondary'>" . $e(\Dropdown::getDropdownName(Cargo::getTable(), (int) $v['plugin_rh_cargos_id'], false, true, false, '')) . "</div></td>"
                    . "<td>" . $e(\Dropdown::getDropdownName(Departamento::getTable(), (int) $v['plugin_rh_departamentos_id'], false, true, false, '')) . "</td>"
                    . "<td>" . (int) $v['positions'] . "</td><td>" . $e(getUserName((int) $v['users_id_requester'])) . "</td>"
                    . "<td>" . Vaga::statusBadge((int) $v['status']) . "</td><td class='text-nowrap'>" . Html::convDateTime($v['date_submit']) . "</td>"
                    . "<td>" . self::decisionForm(Vaga::getFormURL(), (int) $v['id'], $step === 'manager' ? 'approve_manager' : 'approve_board') . "</td></tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div>";

        $ferias = iterator_to_array($DB->request(['FROM' => Feria::getTable(), 'WHERE' => self::feriasCriteria(), 'ORDER' => 'date_begin']), false);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-beach me-2'></i>Férias <span class='badge bg-primary ms-2'>" . count($ferias) . "</span></h3></div>";
        if (!$ferias) {
            echo "<div class='card-body text-secondary'>Nenhum pedido de férias esperando sua aprovação.</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Colaborador</th><th>Período</th><th>Dias</th><th>Abono</th><th>Conflitos na equipe</th><th class='w-25'>Decisão</th></tr></thead><tbody>";
            foreach ($ferias as $f) {
                $conflicts = Feria::conflicts($f);
                echo "<tr><td><a href='" . $e(Feria::getFormURLWithID((int) $f['id'])) . "' class='fw-bold'>" . $e(getUserName((int) $f['users_id'])) . "</a></td>"
                    . "<td class='text-nowrap'>" . Html::convDate($f['date_begin']) . ' a ' . Html::convDate($f['date_end']) . "</td><td>" . (int) $f['days'] . "</td><td>" . ((int) $f['sell_days'] ?: '—') . "</td>"
                    . "<td class='small'>" . ($conflicts ? "<span class='text-warning'><i class='ti ti-alert-triangle me-1'></i>" . $e(implode(', ', $conflicts)) . "</span>" : "<span class='text-secondary'>nenhum</span>") . "</td>"
                    . "<td>" . self::decisionForm(Feria::getFormURL(), (int) $f['id'], 'approve') . "</td></tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div></div>";
    }

    /** Formulário compacto de aprovar/reprovar (o comentário é obrigatório para reprovar). */
    public static function decisionForm(string $action, int $id, string $approve): string
    {
        $a = Menu::e($action);
        return "<form method='post' action='$a' class='d-flex gap-1 align-items-start'><input type='hidden' name='id' value='$id'>"
            . "<input class='form-control form-control-sm' name='comment' placeholder='Comentário (obrigatório para reprovar)'>"
            . "<button class='btn btn-sm btn-success' name='workflow' value='$approve' title='Aprovar'><i class='ti ti-check'></i></button>"
            . "<button class='btn btn-sm btn-outline-danger' name='workflow' value='reject' title='Reprovar'><i class='ti ti-x'></i></button>"
            . Html::closeForm(false);
    }
}
