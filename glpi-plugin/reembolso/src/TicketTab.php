<?php

namespace GlpiPlugin\Reembolso;

use CommonGLPI;
use Html;
use Session;
use Ticket;

/**
 * Aba "Reembolsos" no chamado: reembolsos do atendimento e atalho para lançar um novo.
 */
class TicketTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || $item->isNewItem() || !Reembolso::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $count = count(self::visible((int) $item->getID()));
        }
        return self::createTabEntry('Reembolsos', $count, null, Reembolso::getIcon());
    }

    private static function visible(int $ticketId): array
    {
        global $DB;
        $where = ['tickets_id' => $ticketId, 'is_deleted' => 0];
        if (!Session::haveRight(Reembolso::$rightname, Reembolso::READALL)) {
            $me = (int) Session::getLoginUserID();
            $where[] = ['OR' => ['users_id' => $me, 'users_id_recipient' => $me]];
        }
        return iterator_to_array($DB->request(['FROM' => Reembolso::getTable(), 'WHERE' => $where, 'ORDER' => 'id DESC']), false);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ticket) {
            return false;
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        echo Menu::css() . "<div class='rb p-2'>";
        if (Reembolso::canCreate()) {
            echo "<a class='btn btn-primary mb-3' href='" . $e(Reembolso::getFormURL() . '?tickets_id=' . (int) $item->getID()) . "'><i class='ti ti-plus me-1'></i>Lançar reembolso deste atendimento</a>";
        }
        $rows = self::visible((int) $item->getID());
        if (!$rows) {
            echo "<div class='text-secondary'>Nenhum reembolso ligado a este chamado.</div></div>";
            return true;
        }
        echo "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Solicitação</th><th>Técnico</th><th>Período</th><th>Status</th><th class='text-end'>Total</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            echo "<tr><td><a href='" . $e(Reembolso::getFormURLWithID((int) $r['id'])) . "'>" . $e($r['name']) . "</a></td>"
                . "<td>" . $e(getUserName((int) $r['users_id'])) . "</td>"
                . "<td class='text-nowrap'>" . Html::convDate($r['date_begin']) . ' a ' . Html::convDate($r['date_end']) . "</td>"
                . "<td>" . Reembolso::statusBadge((int) $r['status']) . "</td><td class='text-end fw-bold'>" . Money::format((float) $r['total']) . "</td></tr>";
        }
        echo "</tbody></table></div></div>";
        return true;
    }
}
