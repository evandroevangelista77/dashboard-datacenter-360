<?php

namespace GlpiPlugin\Gestaoeventos;

use Html;
use Session;

/**
 * Parâmetros de tarifa do simulador de viagens, versionados: alterar cria uma versão nova e
 * as simulações já feitas mantêm os parâmetros com que foram calculadas.
 */
class Parametro
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_parametros';

    public static string $rightname = 'config';

    public const FIELDS = [
        'base_fare'    => ['Uber: tarifa base (R$)', 'money'],
        'per_km'       => ['Uber: tarifa por km (R$)', 'money'],
        'peak_mult'    => ['Uber: multiplicador de horário de pico', 'number'],
        'route_factor' => ['Fator de correção de rota (× distância em linha reta)', 'number'],
        'km_refund'    => ['Veículo próprio: reembolso por km (R$)', 'money'],
        'hotel_night'  => ['Hotel: diária (R$)', 'money'],
        'meal_day'     => ['Alimentação: diária (R$)', 'money'],
        'parking_day'  => ['Estacionamento: diária (R$, só veículo próprio)', 'money'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Parâmetros de viagem' : 'Parâmetros de viagem';
    }

    public static function current(): array
    {
        global $DB;
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_current' => 1], 'ORDER' => 'version DESC', 'LIMIT' => 1])->current()
            ?: ['id' => 0, 'version' => 0, 'base_fare' => 0, 'per_km' => 0, 'peak_mult' => 1, 'route_factor' => 1.3, 'km_refund' => 0, 'hotel_night' => 0, 'meal_day' => 0, 'parking_day' => 0];
    }

    public static function version(int $version): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => ['version' => $version], 'LIMIT' => 1])->current() ?: null;
    }

    /** Grava uma versão nova quando algum valor mudou. Devolve se criou. */
    public static function saveNew(array $in): bool
    {
        global $DB;
        $cur = self::current();
        $data = [];
        $changed = false;
        foreach (self::FIELDS as $f => [, $type]) {
            $v = $type === 'money' ? Menu::parseMoney((string) ($in[$f] ?? '0')) : round((float) str_replace(',', '.', (string) ($in[$f] ?? '0')), 2);
            $data[$f] = max(0, $v);
            $changed = $changed || abs($data[$f] - (float) $cur[$f]) > 0.004;
        }
        if (!$changed) {
            return false;
        }
        $DB->update(self::getTable(), ['is_current' => 0], ['is_current' => 1]);
        $DB->insert(self::getTable(), $data + [
            'version' => (int) $cur['version'] + 1, 'is_current' => 1, 'comment' => mb_substr(trim((string) ($in['comment'] ?? '')), 0, 255),
            'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $_SESSION['glpi_currenttime'],
        ]);
        return true;
    }

    public static function showConfig(): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $cur = self::current();
        echo "<form method='post' action='" . $e(Menu::url('/front/admin.php')) . "?tab=tarifas' class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-car me-2'></i>Parâmetros do simulador de viagens</h3><div class='card-actions small text-secondary'>versão vigente: <b>v" . (int) $cur['version'] . "</b></div></div>";
        echo "<div class='card-body row g-3'><div class='col-12 small text-secondary'>Alterar qualquer valor cria uma <b>nova versão</b>; as simulações já feitas continuam com os parâmetros que usaram. Os valores iniciais são de referência: confirme com Financeiro e Marketing.</div>";
        foreach (self::FIELDS as $f => [$label, $type]) {
            $val = $type === 'money' ? number_format((float) $cur[$f], 2, ',', '.') : rtrim(rtrim(number_format((float) $cur[$f], 2, ',', ''), '0'), ',');
            echo "<div class='col-md-3'><label class='form-label'>" . $e($label) . "</label><input class='form-control' name='$f' value='" . $e($val) . "'></div>";
        }
        echo "<div class='col-md-6'><label class='form-label'>Motivo da alteração</label><input class='form-control' name='comment' maxlength='255' placeholder='Ex.: reajuste de tarifas de nov/2026'></div></div>";
        echo "<div class='card-footer'><button class='btn btn-primary' name='save_tarifas' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar (nova versão se mudou)</button></div>";
        Html::closeForm();

        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-history me-2'></i>Versões</h3></div><div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Versão</th><th>Data</th><th>Por</th><th class='text-end'>Base</th><th class='text-end'>/km</th><th class='text-end'>Pico</th><th class='text-end'>Rota</th><th class='text-end'>Reemb./km</th><th class='text-end'>Hotel</th><th class='text-end'>Refeição</th><th>Motivo</th></tr></thead><tbody>";
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'version DESC', 'LIMIT' => 30]) as $r) {
            echo "<tr" . ($r['is_current'] ? " class='fw-bold'" : '') . "><td>v" . (int) $r['version'] . ($r['is_current'] ? ' · vigente' : '') . "</td><td>" . Menu::date($r['date_creation'], true) . "</td><td>" . $e(getUserName((int) $r['users_id'])) . "</td>"
                . "<td class='text-end'>" . Menu::num((float) $r['base_fare']) . "</td><td class='text-end'>" . Menu::num((float) $r['per_km']) . "</td><td class='text-end'>" . Menu::num((float) $r['peak_mult']) . "</td><td class='text-end'>" . Menu::num((float) $r['route_factor']) . "</td>"
                . "<td class='text-end'>" . Menu::num((float) $r['km_refund']) . "</td><td class='text-end'>" . Menu::num((float) $r['hotel_night']) . "</td><td class='text-end'>" . Menu::num((float) $r['meal_day']) . "</td><td class='small'>" . $e($r['comment']) . "</td></tr>";
        }
        echo "</tbody></table></div></div>";
    }
}
