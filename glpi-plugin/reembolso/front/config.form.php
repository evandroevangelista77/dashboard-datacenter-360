<?php

use GlpiPlugin\Reembolso\Menu;
use GlpiPlugin\Reembolso\Money;

Session::checkRight('config', UPDATE);

if (isset($_POST['save'])) {
    $rate = Money::parse((string) ($_POST['km_rate'] ?? ''));
    if ($rate <= 0 || $rate > 50) {
        Session::addMessageAfterRedirect('Valor por KM inválido.', false, ERROR);
    } else {
        Config::setConfigurationValues('plugin:reembolso', [
            'km_rate'         => (string) $rate,
            'route_enabled'   => empty($_POST['route_enabled']) ? '0' : '1',
            'route_country'   => preg_match('/^[a-z]{2}(,[a-z]{2})*$/', strtolower((string) ($_POST['route_country'] ?? ''))) ? strtolower($_POST['route_country']) : '',
            'require_receipt' => empty($_POST['require_receipt']) ? '0' : '1',
        ]);
        Session::addMessageAfterRedirect('Configuração salva. O novo valor por KM vale para as despesas lançadas daqui em diante.', false, INFO);
    }
    Html::back();
}

$c = Money::config();
Html::header('Reembolso de despesas', '', Menu::SECTOR, 'config');
echo Menu::css();
$action = htmlspecialchars(Menu::url('/front/config.form.php'), ENT_QUOTES);
$check = fn ($v) => $v ? ' checked' : '';
echo "<div class='rb'>" . Menu::title('Configuração de reembolsos', 'config') . "<form method='post' action='$action' class='card' style='max-width:760px'>";
echo "<div class='card-body'>";
echo "<div class='mb-3'><label class='form-label'>Valor por KM rodado (R$)</label><input class='form-control' style='max-width:180px' name='km_rate' value='" . number_format($c['km_rate'], 2, ',', '') . "' required>";
echo "<div class='form-hint'>Usado nas despesas por quilometragem (KM × valor). Despesas já lançadas mantêm o valor da época.</div></div>";
echo "<label class='form-check form-switch mb-1'><input class='form-check-input' type='checkbox' name='route_enabled' value='1'" . $check($c['route_enabled']) . "><span class='form-check-label'>Calcular o KM pela rota entre origem e destino (OpenStreetMap)</span></label>";
echo "<div class='form-hint mb-3'>O servidor do GLPI consulta os serviços públicos Nominatim e OSRM. Sem internet, o KM é informado pelo odômetro.</div>";
echo "<div class='mb-3'><label class='form-label'>Países da busca de endereços</label><input class='form-control' style='max-width:180px' name='route_country' value='" . htmlspecialchars($c['route_country'], ENT_QUOTES) . "'>";
echo "<div class='form-hint'>Códigos ISO separados por vírgula (ex.: br). Vazio = qualquer país.</div></div>";
echo "<label class='form-check form-switch mb-1'><input class='form-check-input' type='checkbox' name='require_receipt' value='1'" . $check($c['require_receipt']) . "><span class='form-check-label'>Exigir comprovante anexado para enviar despesas de tipos que pedem comprovante</span></label>";
echo "</div><div class='card-footer d-flex gap-2'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>";
echo "<a class='btn btn-ghost-secondary' href='" . htmlspecialchars(GlpiPlugin\Reembolso\Tipodespesa::getSearchURL(), ENT_QUOTES) . "'><i class='ti ti-receipt me-1'></i>Tipos de despesa</a>";
echo "<a class='btn btn-ghost-secondary' href='" . htmlspecialchars(GlpiPlugin\Reembolso\Centrocusto::getSearchURL(), ENT_QUOTES) . "'><i class='ti ti-building-bank me-1'></i>Centros de custo</a></div>";
Html::closeForm();
echo "</div>";
Html::footer();
