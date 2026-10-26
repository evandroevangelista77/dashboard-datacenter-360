<?php

use GlpiPlugin\Reembolso\Adiantamento;
use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Money;

Session::checkLoginUser();
if (!Adiantamento::canView()) {
    Html::displayRightError();
}

Html::header(Adiantamento::getTypeName(2), '', Menu::SECTOR, Menu::item(Adiantamento::class));
echo Menu::css();
$actions = Adiantamento::canCreate() ? "<a class='btn btn-primary' href='" . htmlspecialchars(Adiantamento::getFormURL(), ENT_QUOTES) . "'><i class='ti ti-plus me-1'></i>Novo adiantamento</a>" : '';
echo "<div class='rb'>" . Menu::title('Adiantamentos', 'adiantamento', $actions) . "</div>";

// o que espera a pessoa agora
$mine = Adiantamento::pendingFor((int) Session::getLoginUserID());
if ($mine) {
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    echo "<div class='rb'><div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-bell-ringing me-2'></i>Esperando você</h3><div class='card-actions small text-secondary'>" . count($mine) . " adiantamento(s)</div></div><div class='list-group list-group-flush'>";
    foreach ($mine as $a) {
        $f = $a->fields;
        echo "<a class='list-group-item list-group-item-action d-flex flex-wrap align-items-center gap-3' href='" . $e(Adiantamento::getFormURLWithID($a->getID())) . "'>"
            . "<b>" . Adiantamento::code($a->getID()) . "</b><span>" . $e(getUserName((int) $f['users_id'])) . "</span>"
            . "<span class='text-secondary small'>" . ((int) $f['tickets_id'] ? 'chamado nº ' . (int) $f['tickets_id'] . ' · ' : '') . $e(Adiantamento::periodLabel($f)) . "</span>"
            . ((int) $f['minutes_night'] ? "<span class='badge rb-night-badge'><i class='ti ti-moon-stars me-1'></i>noturno " . Money::minutes((int) $f['minutes_night']) . "</span>" : '')
            . ($a->isLate() ? "<span class='badge bg-red-lt'><i class='ti ti-alarm me-1'></i>prestação atrasada</span>" : '')
            . "<span class='ms-auto fw-bold'>" . Money::format((float) $f['total']) . "</span>" . Adiantamento::badge((int) $f['status']) . "</a>";
    }
    echo "</div></div></div>";
}
Search::show(Adiantamento::class);
Html::footer();
