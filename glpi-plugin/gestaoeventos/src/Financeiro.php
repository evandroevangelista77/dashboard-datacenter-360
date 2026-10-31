<?php

namespace GlpiPlugin\Gestaoeventos;

use Config;
use Html;

/**
 * Budget, EAC e Realizado do evento. As definições seguem o documento de requisitos:
 * Budget = orçamento aprovado; EAC = estimativa vigente do custo final (nunca abaixo do
 * comprometido nem do realizado); Realizado = o que foi liquidado; Comprometido aberto =
 * obrigações ainda não realizadas, sem dupla contagem; Saldo = Budget − Realizado − Comprometido aberto.
 */
class Financeiro
{
    private const DONE = ['realizado', 'conciliado'];

    /** Soma do Budget de despesas (linhas não canceladas). */
    public static function budgetTotal(int $eventoId): float
    {
        $s = self::summary($eventoId);
        return round($s['despesa']['budget'], 2);
    }

    private static function emptySide(): array
    {
        return ['budget' => 0.0, 'eac' => 0.0, 'committed' => 0.0, 'open' => 0.0, 'real' => 0.0];
    }

    /** Totais de despesa e receita, por categoria e o resultado. */
    public static function summary(int $eventoId): array
    {
        global $DB;
        $ev = new Evento();
        $ev->getFromDB($eventoId);
        $d = self::emptySide();
        $r = self::emptySide();
        $cats = [];
        foreach (Registro::forEvento($eventoId, 'lancamento') as $l) {
            if ($l['status'] === 'cancelado') {
                continue;
            }
            $isRev = $l['nature'] === 'receita';
            $side = $isRev ? $r : $d;
            $done = in_array($l['status'], self::DONE, true);
            $real = $isRev ? ($done ? (float) $l['amount_real'] : 0.0) : (float) $l['amount_real'];
            $eac = max((float) $l['amount_eac'], (float) $l['amount_committed'], (float) $l['amount_real'], (float) $l['amount_budget'] * 0);
            $open = $isRev || $done ? 0.0 : max(0.0, (float) $l['amount_committed'] - (float) $l['amount_real']);
            $side['budget'] += (float) $l['amount_budget'];
            $side['eac'] += $eac;
            $side['committed'] += (float) $l['amount_committed'];
            $side['open'] += $open;
            $side['real'] += $real;
            if ($isRev) {
                $r = $side;
            } else {
                $d = $side;
                $key = (string) $l['category'] !== '' ? (string) $l['category'] : 'Sem categoria';
                $cats[$key] ??= self::emptySide();
                $cats[$key]['budget'] += (float) $l['amount_budget'];
                $cats[$key]['eac'] += $eac;
                $cats[$key]['open'] += $open;
                $cats[$key]['real'] += $real;
            }
        }
        // mudanças e aditivos aprovados entram no EAC (o aditivo já altera o comprometido pela compra)
        $changes = 0.0;
        foreach (Registro::forEvento($eventoId, 'mudanca') as $m) {
            if ($m['status'] === 'aprovada') {
                $changes += (float) $m['amount_eac'];
            }
        }
        $d['eac'] += $changes;
        // patrocínios do Marketing são receita: previsto = Budget, comprometido/previsto = EAC, recebido = Realizado
        foreach (Registro::forEvento($eventoId, 'patrocinador') as $p) {
            if ($p['status'] === 'cancelado') {
                continue;
            }
            $r['budget'] += (float) $p['amount_budget'];
            $r['eac'] += max((float) $p['amount_committed'], (float) $p['amount_real'], (float) $p['amount_committed'] > 0 ? 0 : (float) $p['amount_budget']);
            $r['real'] += $p['status'] === 'recebido' ? (float) $p['amount_real'] : 0.0;
        }
        $base = (float) ($ev->fields['budget_approved'] ?? 0);
        $limit = $base > 0 ? $base : $d['budget'];
        return [
            'despesa' => $d, 'receita' => $r, 'categorias' => $cats, 'mudancas' => $changes, 'base' => $base, 'limite' => $limit,
            'saldo' => $limit - $d['real'] - $d['open'],
            'desvio' => $d['eac'] - ($base > 0 ? $base : $d['budget']),
            'resultado' => ['budget' => $r['budget'] - $d['budget'], 'eac' => $r['eac'] - $d['eac'], 'real' => $r['real'] - $d['real']],
        ];
    }

    // ------------------------------------------------------------------ compras ↔ linhas do orçamento

    /** Espelha a compra numa linha do orçamento (a escolhida ou uma nova fora do Budget) e recalcula o comprometido e o pago. */
    public static function mirrorPurchase(Evento $ev, array $compra): void
    {
        global $DB;
        $line = (int) $compra['parent_id'] ? Registro::row((int) $compra['parent_id'], $ev->getID()) : null;
        if (!$line || $line['kind'] !== 'lancamento') {
            if ($compra['status'] === 'cancelado' || ((float) $compra['amount_committed'] <= 0 && (float) $compra['amount_real'] <= 0)) {
                return;
            }
            $now = $_SESSION['glpi_currenttime'];
            $DB->insert(Registro::getTable(), [
                'plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'lancamento', 'parent_id' => 0, 'title' => 'Compra: ' . $compra['title'], 'category' => $compra['category'], 'subcategory' => $compra['subcategory'],
                'supplier' => $compra['supplier'], 'nature' => 'despesa', 'status' => 'previsto', 'cc' => (string) $ev->fields['cc_number'], 'phase' => 'compras',
                'extra' => json_encode(['source' => 'compra', 'id' => (int) $compra['id']]), 'users_id' => (int) $compra['users_id'], 'date_creation' => $now, 'date_mod' => $now,
            ]);
            $lineId = (int) $DB->insertId();
            $DB->update(Registro::getTable(), ['parent_id' => $lineId], ['id' => (int) $compra['id']]);
        } else {
            $lineId = (int) $line['id'];
        }
        self::rollup($lineId);
    }

    /** O comprometido e o pago da linha vêm das compras ligadas a ela. */
    public static function rollup(int $lineId): void
    {
        global $DB;
        $line = Registro::row($lineId);
        if (!$line) {
            return;
        }
        $committed = 0.0;
        $real = 0.0;
        $n = 0;
        foreach ($DB->request(['FROM' => Registro::getTable(), 'WHERE' => ['kind' => 'compra', 'parent_id' => $lineId, 'NOT' => ['status' => 'cancelado']]]) as $c) {
            $committed += (float) $c['amount_committed'];
            $real += (float) $c['amount_real'];
            $n++;
        }
        if (!$n) {
            return;
        }
        $status = $line['status'];
        if (!in_array($status, ['conciliado', 'cancelado'], true)) {
            $status = $real > 0 && $real >= $committed && $committed > 0 ? 'realizado' : ($committed > 0 ? 'comprometido' : 'previsto');
        }
        $DB->update(Registro::getTable(), ['amount_committed' => $committed, 'amount_real' => $real, 'status' => $status, 'date_mod' => $_SESSION['glpi_currenttime']], ['id' => $lineId]);
    }

    // ------------------------------------------------------------------ tolerância (RF-017)

    /** EAC acima do Budget aprovado além da tolerância reabre a aprovação (nova versão, a anterior fica preservada). */
    public static function checkTolerance(Evento $ev): void
    {
        $ev->getFromDB($ev->getID());
        if ($ev->fields['approval_status'] !== 'aprovado' || (float) $ev->fields['budget_approved'] <= 0) {
            return;
        }
        $tol = (float) (Config::getConfigurationValues('plugin:gestaoeventos', ['tolerance_pct'])['tolerance_pct'] ?? 5);
        $s = self::summary($ev->getID());
        $base = (float) $ev->fields['budget_approved'];
        if ($s['despesa']['eac'] <= $base * (1 + $tol / 100) + 0.005) {
            return;
        }
        if (in_array(Aprovacao::stateFor($ev->getID(), 'revisao'), ['pending', 'rejected', 'postponed'], true)) {
            return;
        }
        $over = round(100 * ($s['despesa']['eac'] - $base) / $base, 1);
        Aprovacao::request($ev, 'revisao', 'EAC acima do Budget aprovado', $s['despesa']['eac'],
            0, 'EAC de ' . Menu::money($s['despesa']['eac']) . ' excede o Budget aprovado de ' . Menu::money($base) . " em $over% (tolerância de " . Menu::num($tol, 0) . "%).");
        Historico::record($ev->getID(), null, 'finance', "Tolerância ultrapassada ($over%): revisão do orçamento enviada para aprovação.");
    }

    // ------------------------------------------------------------------ aba

    public static function tab(Evento $ev, bool $edit): void
    {
        $e = [Menu::class, 'e'];
        $back = Evento::tabUrl($ev->getID(), 'financeiro');
        echo "<div class='ge ge-tab'>" . Abas::banner($ev, 'planejamento');
        if (!Evento::canFinance()) {
            echo "<div class='alert alert-warning'><i class='ti ti-lock me-1'></i>Os dados financeiros são restritos ao perfil Financeiro. Peça o direito <b>Financeiro</b> ao administrador (Administração → Perfis → Gestão de Eventos).</div></div>";
            return;
        }
        $s = self::summary($ev->getID());
        $d = $s['despesa'];
        $r = $s['receita'];
        $f = $ev->fields;
        $kpi = fn (string $label, string $value, string $sub, string $color) => "<div class='col-6 col-md-3 col-xl'><div class='ge-kpi' style='--c:$color;min-height:0'><div class='ge-kpi-label'>$label</div><div class='ge-kpi-value' style='font-size:1.25rem'>$value</div><div class='ge-kpi-sub'>$sub</div></div></div>";
        $dev = $s['desvio'];
        echo "<div class='row g-3 mb-3'>"
            . $kpi('Budget', Menu::money($s['limite']), (float) $f['budget_approved'] > 0 ? 'aprovado · v' . (int) $f['budget_version'] : 'soma das linhas (ainda não aprovado)', (float) $f['budget_approved'] > 0 ? '#22c55e' : '#f59e0b')
            . $kpi('EAC (custo final)', Menu::money($d['eac']), ($dev > 0 ? '+' : '') . Menu::money($dev) . ' vs Budget', $dev > 0 ? '#ef4444' : '#22c55e')
            . $kpi('Comprometido aberto', Menu::money($d['open']), 'obrigações ainda não realizadas', '#f59e0b')
            . $kpi('Realizado', Menu::money($d['real']), 'despesas liquidadas', '#3b82f6')
            . $kpi('Saldo disponível', Menu::money($s['saldo']), 'Budget − realizado − comprometido', $s['saldo'] < 0 ? '#ef4444' : '#14b8a6')
            . "</div>";
        echo "<div class='row g-3 mb-3'>"
            . $kpi('Receita (Budget / EAC / Realizado)', Menu::money($r['budget']) . ' · ' . Menu::money($r['eac']) . ' · ' . Menu::money($r['real']), 'inclui os patrocinadores da aba Marketing; realizado só com recebimento confirmado', '#a855f7')
            . $kpi('Resultado líquido — Budget', Menu::money($s['resultado']['budget']), 'receita − despesa', $s['resultado']['budget'] >= 0 ? '#22c55e' : '#ef4444')
            . $kpi('Resultado líquido — EAC', Menu::money($s['resultado']['eac']), 'receita − despesa', $s['resultado']['eac'] >= 0 ? '#22c55e' : '#ef4444')
            . $kpi('Resultado líquido — Realizado', Menu::money($s['resultado']['real']), 'receita − despesa', $s['resultado']['real'] >= 0 ? '#22c55e' : '#ef4444')
            . "</div>";

        $state = Aprovacao::stateFor($ev->getID(), 'orcamento');
        if ($edit && (int) $f['status'] === Evento::OPEN) {
            echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'><i class='ti ti-signature fs-2 text-teal'></i><div class='me-auto'><b>Orçamento base</b><div class='small text-secondary'>"
                . ((float) $f['budget_approved'] > 0 ? 'Aprovado em ' . Menu::money((float) $f['budget_approved']) . ' (v' . (int) $f['budget_version'] . '). Mudanças acima da tolerância reabrem a aprovação com uma versão nova.'
                    : 'Cadastre as linhas de Budget das despesas e peça a aprovação. Sem Budget aprovado nenhuma compra é contratada.') . "</div></div>";
            if ($state === 'pending') {
                echo "<span class='badge bg-yellow-lt'>aprovação do orçamento pendente</span>";
            } elseif ($d['budget'] > 0 && abs($d['budget'] - (float) $f['budget_approved']) > 0.005 && (float) $f['budget_approved'] <= 0) {
                echo "<form method='post' action='" . $e(Evento::getFormURL()) . "' class='m-0'><input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e($back) . "'><input type='hidden' name='kind' value='orcamento'>"
                    . "<input type='hidden' name='amount' value='" . $e(number_format($d['budget'], 2, ',', '.')) . "'><input type='hidden' name='reason' value='Orçamento base: Budget de despesas de " . $e(Menu::money($d['budget'])) . "'>"
                    . "<button class='btn btn-primary' name='approval_request' value='1'><i class='ti ti-send me-1'></i>Solicitar aprovação de " . $e(Menu::money($d['budget'])) . "</button>" . Html::closeForm(false);
            }
            echo "<a class='btn btn-outline-secondary' href='" . $e(Menu::url('/front/exportar.php') . '?tipo=financeiro&id=' . $ev->getID()) . "'><i class='ti ti-download me-1'></i>Demonstrativo (CSV)</a></div></div>";
        }

        if ($s['categorias']) {
            echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-chart-bar me-2'></i>Despesas por categoria</h3></div><div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Categoria</th><th class='text-end'>Budget</th><th class='text-end'>EAC</th><th class='text-end'>Comprometido aberto</th><th class='text-end'>Realizado</th><th class='text-end'>Desvio EAC × Budget</th><th style='width:22%'>Consumo do Budget</th></tr></thead><tbody>";
            foreach ($s['categorias'] as $cat => $c) {
                $pct = $c['budget'] > 0 ? min(100, round(100 * ($c['real'] + $c['open']) / $c['budget'])) : ($c['real'] + $c['open'] > 0 ? 100 : 0);
                $dv = $c['eac'] - $c['budget'];
                echo "<tr><td>" . $e($cat) . "</td><td class='text-end'>" . Menu::money($c['budget']) . "</td><td class='text-end'>" . Menu::money($c['eac']) . "</td><td class='text-end'>" . Menu::money($c['open']) . "</td><td class='text-end'>" . Menu::money($c['real']) . "</td>"
                    . "<td class='text-end" . ($dv > 0.005 ? ' text-danger fw-bold' : '') . "'>" . ($dv > 0 ? '+' : '') . Menu::money($dv) . "</td><td><div class='ge-dr-bar" . ($pct >= 100 && $dv > 0.005 ? ' bad' : '') . "'><i style='width:$pct%'></i></div></td></tr>";
            }
            echo "</tbody></table></div></div>";
        }
        echo "<div class='row g-3'>" . Registro::block($ev, 'lancamento', $edit, $back) . "</div>";
        echo "<div class='small text-secondary mt-2'>Convenções: custo é sempre positivo (natureza <b>despesa</b>); o EAC nunca fica abaixo do comprometido nem do realizado; realizado de receita só conta com recebimento confirmado; "
            . "compras ligadas a uma linha alimentam o comprometido e o pago dela sem dupla contagem.</div></div>";
    }
}
