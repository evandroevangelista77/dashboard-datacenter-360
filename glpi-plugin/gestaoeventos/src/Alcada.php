<?php

namespace GlpiPlugin\Gestaoeventos;

use Group;
use Html;
use User;

/**
 * Alçadas: quem aprova cada tipo de decisão, por faixa de valor e tipo de evento.
 * Etapas com o mesmo número aprovam em paralelo; números diferentes aprovam em sequência.
 */
class Alcada
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_alcadas';

    public static string $rightname = 'config';

    /** [nome, tipo, valor mínimo, valor máximo (0 = sem limite), etapa] */
    public const DEFAULTS = [
        ['Entrada do evento: Diretoria', 'entrada', 0, 0, 1],
        ['Orçamento base: Financeiro', 'orcamento', 0, 0, 1],
        ['Orçamento base: Diretoria (acima de R$ 50 mil)', 'orcamento', 50000, 0, 2],
        ['Compra: gestor do evento (até R$ 20 mil)', 'compra', 0, 20000, 1],
        ['Compra: gestor do evento (acima de R$ 20 mil)', 'compra', 20000.01, 0, 1],
        ['Compra: Financeiro (acima de R$ 20 mil)', 'compra', 20000.01, 0, 2],
        ['Compra: Diretoria (acima de R$ 100 mil)', 'compra', 100000, 0, 3],
        ['Mudança: Diretoria', 'mudanca', 0, 0, 1],
        ['Aditivo: Financeiro', 'aditivo', 0, 0, 1],
        ['Exceção: Diretoria', 'excecao', 0, 0, 1],
        ['Custo emergencial: Diretoria', 'emergencial', 0, 0, 1],
        ['Revisão de orçamento: Diretoria', 'revisao', 0, 0, 1],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Alçadas' : 'Alçada';
    }

    /** Etapas de aprovação que valem para o tipo, o valor e o tipo de evento (ordenadas). Sem nenhuma: um passo "Diretoria" sem grupo. */
    public static function steps(string $kind, float $amount, string $eventType): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['kind' => $kind, 'is_active' => 1], 'ORDER' => ['step', 'id']]) as $r) {
            if ($amount < (float) $r['min_amount'] || ((float) $r['max_amount'] > 0 && $amount > (float) $r['max_amount'])) {
                continue;
            }
            if ($r['event_type'] !== null && $r['event_type'] !== '' && $r['event_type'] !== $eventType) {
                continue;
            }
            $out[] = $r;
        }
        return $out ?: [['name' => 'Diretoria', 'step' => 1, 'groups_id' => 0, 'users_id' => 0]];
    }

    public static function showConfig(): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $rows = iterator_to_array($DB->request(['FROM' => self::getTable(), 'ORDER' => ['kind', 'step', 'min_amount']]), false);
        echo "<form method='post' action='" . $e(Menu::url('/front/admin.php')) . "?tab=alcadas' class='card'>";
        echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-signature me-2'></i>Alçadas de aprovação</h3></div>";
        echo "<div class='card-body small text-secondary border-bottom'>Cada linha é um aprovador (grupo do GLPI ou usuário). Linhas do <b>mesmo tipo e da mesma etapa</b> aprovam em paralelo; "
            . "etapas diferentes aprovam em sequência (a 2ª só abre quando a 1ª aprovou). A linha só vale quando o valor da decisão está entre o mínimo e o máximo (0 = sem limite). "
            . "Sem grupo e sem usuário, decide quem tem o direito <b>Aprovar</b> no perfil. Ninguém aprova a própria solicitação.</div>";
        echo "<div class='table-responsive'><table class='table table-sm table-vcenter card-table'><thead><tr><th style='min-width:240px'>Nome</th><th>Tipo</th><th>De (R$)</th><th>Até (R$)</th><th>Eventos</th><th>Etapa</th><th style='min-width:200px'>Grupo</th><th style='min-width:200px'>Usuário</th><th>Ativa</th><th></th></tr></thead><tbody>";
        $line = function (string $key, array $r) use ($e) {
            $n = "al[$key]";
            return "<tr><td><input class='form-control form-control-sm' name='{$n}[name]' value='" . $e($r['name'] ?? '') . "' maxlength='150'></td>"
                . "<td><select class='form-select form-select-sm' name='{$n}[kind]'>" . Menu::options(Aprovacao::KINDS, $r['kind'] ?? 'compra') . "</select></td>"
                . "<td style='width:110px'><input class='form-control form-control-sm' name='{$n}[min_amount]' value='" . $e(number_format((float) ($r['min_amount'] ?? 0), 2, ',', '.')) . "'></td>"
                . "<td style='width:110px'><input class='form-control form-control-sm' name='{$n}[max_amount]' value='" . $e(number_format((float) ($r['max_amount'] ?? 0), 2, ',', '.')) . "'></td>"
                . "<td><select class='form-select form-select-sm' name='{$n}[event_type]'><option value=''>Todos</option>" . Menu::options(Menu::labels(Evento::TYPES), $r['event_type'] ?? '') . "</select></td>"
                . "<td style='width:70px'><input type='number' min='1' max='9' class='form-control form-control-sm' name='{$n}[step]' value='" . (int) ($r['step'] ?? 1) . "'></td>"
                . "<td>" . Group::dropdown(['name' => "{$n}[groups_id]", 'value' => (int) ($r['groups_id'] ?? 0), 'display' => false, 'width' => '100%']) . "</td>"
                . "<td>" . User::dropdown(['name' => "{$n}[users_id]", 'value' => (int) ($r['users_id'] ?? 0), 'right' => 'all', 'display' => false, 'width' => '100%']) . "</td>"
                . "<td><label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='{$n}[is_active]' value='1'" . (($r['is_active'] ?? 1) ? ' checked' : '') . "></label></td>"
                . "<td>" . (isset($r['id']) ? "<label class='btn btn-sm btn-ghost-danger px-1 m-0' title='Marque para remover ao salvar'><input type='checkbox' class='d-none' name='{$n}[delete]' value='1' onchange=\"this.parentElement.classList.toggle('active', this.checked)\"><i class='ti ti-trash'></i></label>" : '') . "</td></tr>";
        };
        foreach ($rows as $r) {
            echo $line((string) $r['id'], $r);
        }
        echo "<tr><td colspan='10' class='small text-secondary pt-3'><b>Nova alçada</b></td></tr>" . $line('new', []);
        echo "</tbody></table></div>";
        echo "<div class='card-footer'><button class='btn btn-primary' name='save_alcadas' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar alçadas</button></div>";
        Html::closeForm();
    }

    public static function saveConfig(array $post): void
    {
        global $DB;
        foreach ((array) ($post['al'] ?? []) as $key => $r) {
            $id = $key === 'new' ? 0 : (int) $key;
            if ($id && !empty($r['delete'])) {
                $DB->delete(self::getTable(), ['id' => $id]);
                continue;
            }
            $kind = isset(Aprovacao::KINDS[$r['kind'] ?? '']) ? $r['kind'] : 'compra';
            $data = [
                'name' => mb_substr(trim((string) ($r['name'] ?? '')), 0, 150), 'kind' => $kind,
                'min_amount' => max(0, Menu::parseMoney((string) ($r['min_amount'] ?? '0'))), 'max_amount' => max(0, Menu::parseMoney((string) ($r['max_amount'] ?? '0'))),
                'event_type' => isset(Evento::TYPES[$r['event_type'] ?? '']) ? $r['event_type'] : null, 'step' => max(1, min(9, (int) ($r['step'] ?? 1))),
                'groups_id' => max(0, (int) ($r['groups_id'] ?? 0)), 'users_id' => max(0, (int) ($r['users_id'] ?? 0)),
                'is_active' => empty($r['is_active']) ? 0 : 1, 'date_mod' => date('Y-m-d H:i:s'),
            ];
            if ($id) {
                $DB->update(self::getTable(), $data, ['id' => $id]);
            } elseif ($data['name'] !== '') {
                $DB->insert(self::getTable(), $data);
            }
        }
    }
}
