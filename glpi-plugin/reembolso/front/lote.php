<?php

use GlpiPlugin\Reembolso\Lote;
use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Money;

Session::checkLoginUser();
if (!Lote::canView()) {
    Html::displayRightError();
}

Html::header(Lote::getTypeName(2), '', Menu::SECTOR, Menu::item(Lote::class));
echo Menu::css();
echo "<div class='rb'>" . Menu::title('Lotes de reembolso', 'lote') . "</div>";

// o que espera a pessoa agora
$mine = Lote::pendingFor((int) Session::getLoginUserID());
if ($mine) {
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    echo "<div class='rb'><div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-bell-ringing me-2'></i>Esperando você</h3><div class='card-actions small text-secondary'>" . count($mine) . " lote(s)</div></div><div class='list-group list-group-flush'>";
    foreach ($mine as $l) {
        $f = $l->fields;
        echo "<a class='list-group-item list-group-item-action d-flex flex-wrap align-items-center gap-3' href='" . $e(Lote::getFormURLWithID($l->getID())) . "'>"
            . "<b>" . Lote::code($l->getID()) . "</b><span>" . $e(getUserName((int) $f['users_id'])) . "</span><span class='text-secondary small'>" . $e(Lote::periodLabel($f)) . "</span>"
            . ((int) $f['night_count'] ? "<span class='badge rb-night-badge'><i class='ti ti-moon-stars me-1'></i>" . (int) $f['night_count'] . " noturna(s)</span>" : '')
            . "<span class='ms-auto fw-bold'>" . Money::format((float) $f['total']) . "</span>" . Lote::badge((int) $f['status']) . "</a>";
    }
    echo "</div></div></div>";
}
Search::show(Lote::class);
Html::footer();
