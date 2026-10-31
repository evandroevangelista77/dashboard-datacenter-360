<?php

namespace GlpiPlugin\Gestaoeventos;

use Html;
use RuntimeException;
use Session;

/**
 * Pós-evento: Definition of Done, conciliação, resultados, leads, lições e o relatório final.
 * O evento só é concluído com tudo isso e o aceite formal do dono.
 */
class Encerramento
{
    /** Itens da Definition of Done: [rótulo, cumprido, detalhe, aba onde resolver] */
    public static function dod(Evento $ev): array
    {
        $id = $ev->getID();
        $f = $ev->fields;
        $n = fn (string $kind, array $w = []) => Registro::countKind($id, $kind, $w);
        $lines = array_filter(Registro::forEvento($id, 'lancamento'), fn ($l) => $l['status'] !== 'cancelado' && ((float) $l['amount_eac'] || (float) $l['amount_committed'] || (float) $l['amount_real'] || (float) $l['amount_budget']));
        $unreconciled = count(array_filter($lines, fn ($l) => $l['status'] !== 'conciliado'));
        $noDoc = count(array_filter($lines, fn ($l) => $l['nature'] === 'despesa' && (float) $l['amount_real'] > 0 && trim((string) $l['doc_ref']) === ''));
        $unpaid = $n('compra', ['status' => ['requisicao', 'cotacao', 'negociacao', 'aprovacao', 'contrato', 'pedido', 'producao', 'recebido']]);
        $sponsor = $n('patrocinador', ['status' => ['previsto', 'comprometido']]);
        $logistic = $n('material', ['status' => ['a_enviar', 'enviado', 'divergente']]) + $n('recebimento', ['status' => ['pendente', 'divergente']]);
        $kpis = $n('kpi');
        $results = $n('resultado');
        $leadsOpen = Registro::countKind($id, 'lead', ['status' => ['pendente', 'followup']]) + Registro::countKind($id, 'lead', ['confirmed' => 0, 'status' => 'concluido']);
        $oc = $n('ocorrencia', ['status' => 'aberta']);
        $appr = countElementsInTable(Aprovacao::getTable(), ['plugin_gestaoeventos_eventos_id' => $id, 'status' => 'pending']);
        return [
            ['Conciliação financeira concluída', $unreconciled === 0, $unreconciled ? "$unreconciled lançamento(s) sem conciliação" : 'todos conciliados', 'financeiro'],
            ['Despesas realizadas com documento fiscal', $noDoc === 0, $noDoc ? "$noDoc despesa(s) realizada(s) sem NF/ND/comprovante" : 'evidências completas', 'financeiro'],
            ['Compras pagas ou canceladas', $unpaid === 0, $unpaid ? "$unpaid compra(s) sem pagamento" : 'sem pendência', 'compras'],
            ['Patrocínios recebidos ou cancelados', $sponsor === 0, $sponsor ? "$sponsor patrocínio(s) sem recebimento confirmado" : 'sem pendência', 'marketing'],
            ['Logística e entregas sem pendência', $logistic === 0, $logistic ? "$logistic item(ns) de logística aberto(s)" : 'sem pendência', 'logistica'],
            ['Resultado de cada objetivo registrado', $results >= max(1, $kpis), $kpis ? "$results resultado(s) para $kpis objetivo(s)" : ($results ? "$results resultado(s)" : 'nenhum resultado registrado'), 'encerramento'],
            ['Leads com follow-up e confirmação do Comercial', $leadsOpen === 0, $leadsOpen ? "$leadsOpen lote(s) de leads sem follow-up ou sem confirmação" : 'em dia', 'encerramento'],
            ['Lições aprendidas registradas', $n('licao') >= 1, $n('licao') . ' lição(ões)', 'encerramento'],
            ['Ocorrências resolvidas', $oc === 0, $oc ? "$oc ocorrência(s) aberta(s)" : 'sem ocorrência aberta', 'execucao'],
            ['Nenhuma aprovação pendente', $appr === 0, $appr ? "$appr aprovação(ões) pendente(s)" : 'sem pendência', 'aprovacoes'],
            ['Pasta SharePoint do evento informada', trim((string) $f['sharepoint_url']) !== '', trim((string) $f['sharepoint_url']) !== '' ? 'informada' : 'sem link', 'encerramento'],
            ['Aceite formal do dono do evento', (bool) $f['accepted_date'], $f['accepted_date'] ? getUserName((int) $f['accepted_users']) . ' em ' . Menu::date($f['accepted_date']) : 'pendente', 'encerramento'],
        ];
    }

    /** Mensagens do que falta (vazio = Definition of Done cumprida). */
    public static function dodMissing(Evento $ev): array
    {
        $out = [];
        foreach (self::dod($ev) as [$label, $ok, $detail]) {
            if (!$ok) {
                $out[] = "$label: $detail.";
            }
        }
        return $out;
    }

    public static function tab(Evento $ev, bool $edit): void
    {
        $e = [Menu::class, 'e'];
        $back = Evento::tabUrl($ev->getID(), 'encerramento');
        $action = $e(Evento::getFormURL());
        $hid = "<input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e($back) . "'>";
        $dod = self::dod($ev);
        $ok = count(array_filter($dod, fn ($x) => $x[1]));
        echo "<div class='ge ge-tab'>" . Abas::banner($ev, 'encerramento');
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-checklist me-2'></i>Definition of Done</h3>"
            . "<div class='card-actions small'>" . ($ok === count($dod) ? "<span class='text-success fw-bold'>cumprida</span>" : "<span class='text-danger fw-bold'>" . (count($dod) - $ok) . " pendência(s)</span>") . " · $ok de " . count($dod) . "</div></div>";
        echo "<div class='list-group list-group-flush'>";
        foreach ($dod as [$label, $done, $detail, $tab]) {
            echo "<a class='list-group-item list-group-item-action d-flex align-items-center gap-3' href='" . $e(Evento::tabUrl($ev->getID(), $tab)) . "'><i class='ti " . ($done ? 'ti-circle-check text-success' : 'ti-circle-x text-danger') . " fs-3'></i>"
                . "<div class='me-auto'><div>" . $e($label) . "</div><div class='small text-secondary'>" . $e($detail) . "</div></div><span class='small text-secondary'><i class='" . Abas::META[$tab][1] . " me-1'></i>" . $e(Abas::META[$tab][0]) . "</span></a>";
        }
        echo "</div><div class='card-footer d-flex flex-wrap gap-2 align-items-center'>"
            . (Acesso::canView('dossie') ? "<a class='btn btn-primary' target='_blank' href='" . $e(Menu::url('/front/dossie.php') . '?id=' . $ev->getID()) . "'><i class='ti ti-file-type-pdf me-1'></i>Dossiê executivo (PDF)</a>"
            . "<a class='btn btn-outline-secondary' target='_blank' href='" . $e(Menu::url('/front/relatorio.php') . '?id=' . $ev->getID()) . "'><i class='ti ti-file-analytics me-1'></i>Relatório final (tela)</a>" : '');
        if ($edit && Evento::canFinance()) {
            echo "<form method='post' action='$action' class='m-0'>$hid<button class='btn btn-outline-primary' name='close_action' value='reconcile_all' onclick=\"return confirm('Conciliar todos os lançamentos já realizados?')\"><i class='ti ti-checks me-1'></i>Conciliar os lançamentos realizados</button>" . Html::closeForm(false);
        }
        echo "<span class='small text-secondary ms-auto'>O aceite formal do dono é dado no cartão da etapa, na aba do evento.</span></div></div>";
        echo "<div class='row g-3 mb-3'>" . Registro::block($ev, 'resultado', $edit, $back, 'col-xl-6') . Registro::block($ev, 'lead', $edit, $back, 'col-xl-6') . "</div>";
        echo "<div class='row g-3'>" . Registro::block($ev, 'licao', $edit, $back, 'col-xl-6') . Registro::block($ev, 'satisfacao', $edit, $back, 'col-xl-6') . "</div></div>";
    }

    public static function action(Evento $ev, string $action): string
    {
        global $DB;
        if ($action === 'reconcile_all') {
            if (!Evento::canFinance() || !Acesso::canEdit('financeiro')) {
                throw new RuntimeException('Só o perfil Financeiro concilia lançamentos.');
            }
            $n = 0;
            foreach (Registro::forEvento($ev->getID(), 'lancamento') as $l) {
                if ($l['status'] === 'realizado') {
                    $DB->update(Registro::getTable(), ['status' => 'conciliado', 'date_mod' => $_SESSION['glpi_currenttime']], ['id' => $l['id']]);
                    $n++;
                }
            }
            Historico::record($ev->getID(), 'encerramento', 'finance', "Conciliação em lote: $n lançamento(s) conciliado(s) por " . getUserName((int) Session::getLoginUserID()) . '.');
            Fase::maybeAdvance($ev);
            return "$n lançamento(s) conciliado(s).";
        }
        throw new RuntimeException('Ação desconhecida.');
    }

    // ------------------------------------------------------------------ relatório final

    /** Relatório final em página própria, pronta para imprimir ou salvar em PDF pelo navegador. */
    public static function report(Evento $ev): void
    {
        $e = [Menu::class, 'e'];
        $f = $ev->fields;
        $id = $ev->getID();
        $fin = Evento::canFinance();
        $s = $fin ? Financeiro::summary($id) : null;
        $phases = Fase::forEvento($id);
        $css = "body{font-family:Arial,Helvetica,sans-serif;color:#0f172a;margin:24px auto;max-width:980px;padding:0 16px;font-size:13px}h1{margin:0 0 4px}h2{margin:22px 0 8px;font-size:15px;border-bottom:2px solid #14b8a6;padding-bottom:4px}"
            . "table{border-collapse:collapse;width:100%;margin-bottom:6px}th,td{border:1px solid #cbd5e1;padding:4px 7px;text-align:left}th{background:#f1f5f9}td.n,th.n{text-align:right}.k{display:flex;gap:10px;flex-wrap:wrap}"
            . ".k div{flex:1;min-width:150px;border:1px solid #cbd5e1;border-radius:8px;padding:8px}.k small{display:block;color:#64748b;text-transform:uppercase;font-size:10px}.k b{font-size:16px}.bad{color:#dc2626}.good{color:#16a34a}"
            . ".bar{background:#e2e8f0;border-radius:4px;height:8px}.bar i{display:block;height:8px;border-radius:4px;background:#14b8a6}@media print{.noprint{display:none}}";
        echo "<!doctype html><html lang='pt-BR'><head><meta charset='utf-8'><title>Relatório final · " . $e($f['code']) . "</title><style>$css</style></head><body>";
        echo "<div class='noprint' style='text-align:right'><button onclick='window.print()'>Imprimir / salvar em PDF</button></div>";
        echo "<h1>" . $e($f['name']) . "</h1><div>" . $e($f['code']) . ' · ' . $e(Evento::TYPES[$f['event_type']][0] ?? '') . ' · ' . $e(Menu::date($f['date_start'])) . ' a ' . $e(Menu::date($f['date_finish'])) . ' · ' . $e($f['city']) . "</div>";
        echo "<div style='color:#64748b'>Dono: " . $e(getUserName((int) $f['users_id_owner'])) . ' · GP: ' . $e(getUserName((int) $f['users_id_gp'])) . ' · Situação: ' . $e(Evento::STATUSES[(int) $f['status']][0] ?? '')
            . ' · Gerado em ' . $e(Menu::date($_SESSION['glpi_currenttime'], true)) . ' por ' . $e(getUserName((int) Session::getLoginUserID())) . "</div>";

        echo "<h2>Objetivo e resultados</h2><p>" . nl2br($e($f['objective'])) . "</p><table><tr><th>Objetivo / KPI</th><th>Resultado</th><th>Responsável</th><th>Justificativa</th></tr>";
        $res = Registro::forEvento($id, 'resultado');
        foreach ($res as $r) {
            echo "<tr><td>" . $e($r['title']) . "</td><td>" . $e(['atingido' => 'Atingido', 'parcial' => 'Parcialmente atingido', 'nao_atingido' => 'Não atingido'][$r['status']] ?? '') . "</td><td>" . $e(getUserName((int) $r['users_id_owner'])) . "</td><td>" . $e($r['detail']) . "</td></tr>";
        }
        echo $res ? '' : "<tr><td colspan='4'>Nenhum resultado registrado.</td></tr>";
        echo "</table><table><tr><th>Indicador</th><th class='n'>Meta</th><th class='n'>Resultado</th><th>Unidade</th></tr>";
        foreach (Registro::forEvento($id, 'kpi') as $k) {
            echo "<tr><td>" . $e($k['title']) . "</td><td class='n'>" . Menu::num((float) $k['target']) . "</td><td class='n'>" . Menu::num((float) $k['result']) . "</td><td>" . $e($k['unit']) . "</td></tr>";
        }
        echo "</table>";
        $att = Registro::countKind($id, 'convidado', ['status' => 'presente']);
        echo "<div class='k'><div><small>Público estimado</small><b>" . (int) $f['audience'] . "</b></div><div><small>Convidados</small><b>" . Registro::countKind($id, 'convidado') . "</b></div><div><small>Presentes</small><b>$att</b></div>"
            . "<div><small>Leads</small><b>" . (int) array_sum(array_column(Registro::forEvento($id, 'lead'), 'qty')) . "</b></div></div>";

        if ($s) {
            $d = $s['despesa'];
            echo "<h2>Orçamento (Budget × EAC × Realizado)</h2><div class='k'><div><small>Budget" . ((float) $f['budget_approved'] > 0 ? ' aprovado' : '') . "</small><b>" . Menu::money($s['limite']) . "</b></div><div><small>EAC</small><b>" . Menu::money($d['eac']) . "</b></div>"
                . "<div><small>Realizado</small><b>" . Menu::money($d['real']) . "</b></div><div><small>Desvio EAC × Budget</small><b class='" . ($s['desvio'] > 0 ? 'bad' : 'good') . "'>" . Menu::money($s['desvio']) . "</b></div>"
                . "<div><small>Receita realizada</small><b>" . Menu::money($s['receita']['real']) . "</b></div><div><small>Resultado líquido (realizado)</small><b class='" . ($s['resultado']['real'] >= 0 ? 'good' : 'bad') . "'>" . Menu::money($s['resultado']['real']) . "</b></div></div>";
            echo "<table><tr><th>Categoria</th><th class='n'>Budget</th><th class='n'>EAC</th><th class='n'>Realizado</th></tr>";
            foreach ($s['categorias'] as $cat => $c) {
                echo "<tr><td>" . $e($cat) . "</td><td class='n'>" . Menu::money($c['budget']) . "</td><td class='n'>" . Menu::money($c['eac']) . "</td><td class='n'>" . Menu::money($c['real']) . "</td></tr>";
            }
            echo "</table>";
        }

        echo "<h2>Cronograma</h2><table><tr><th>Marco</th><th>Prazo</th><th>Concluído em</th><th>Situação</th></tr>";
        foreach (Registro::forEvento($id, 'marco') as $m) {
            echo "<tr><td>" . $e($m['title']) . ($m['is_critical'] ? ' (crítico)' : '') . "</td><td>" . Menu::date($m['date_due']) . "</td><td>" . Menu::date($m['date_done']) . "</td><td>" . $e(Registro::ST_TASK[$m['status']] ?? $m['status']) . "</td></tr>";
        }
        echo "</table><table><tr><th>Etapa</th><th>Área</th><th class='n'>Dias</th><th class='n'>SLO</th><th>Situação</th></tr>";
        foreach ($phases as $code => $p) {
            $late = Fase::isLate($p);
            echo "<tr><td>" . $e(Fase::name($code)) . "</td><td>" . $e(Fase::area($p)) . "</td><td class='n'>" . Menu::days(Fase::elapsed($p)) . "</td><td class='n'>" . ((int) $p['slo_days'] ?: '—') . "</td><td class='" . ($late ? 'bad' : '') . "'>" . $e(Fase::STATUSES[$p['status']][0]) . ($late ? ' · acima do SLO' : '') . "</td></tr>";
        }
        echo "</table>";

        echo "<h2>Ocorrências</h2><table><tr><th>Ocorrência</th><th>Severidade</th><th>Situação</th><th>Solução / evidência</th></tr>";
        $oc = Registro::forEvento($id, 'ocorrencia');
        foreach ($oc as $o) {
            echo "<tr><td>" . $e($o['title']) . "</td><td>" . $e($o['category']) . "</td><td>" . $e($o['status']) . "</td><td>" . $e($o['detail']) . "</td></tr>";
        }
        echo $oc ? '' : "<tr><td colspan='4'>Nenhuma ocorrência.</td></tr>";
        echo "</table><h2>Decisões e aprovações</h2><table><tr><th>Pedido</th><th>Alçada</th><th>Situação</th><th>Decidido por</th><th>Justificativa</th></tr>";
        foreach (Aprovacao::forEvento($id) as $a) {
            echo "<tr><td>" . $e((Aprovacao::KINDS[$a['kind']] ?? '') . ' — ' . $a['title']) . "</td><td>" . $e($a['label']) . "</td><td>" . $e(Aprovacao::STATUSES[$a['status']][0] ?? $a['status']) . "</td><td>" . $e($a['date_decision'] ? getUserName((int) $a['users_id_decided']) . ' em ' . Menu::date($a['date_decision'], true) : '—') . "</td><td>" . $e($a['justification']) . "</td></tr>";
        }
        echo "</table><h2>Lições aprendidas</h2><table><tr><th>Área</th><th>Lição</th><th>Recomendação</th></tr>";
        foreach (Registro::forEvento($id, 'licao') as $l) {
            echo "<tr><td>" . $e($l['category']) . "</td><td>" . $e($l['title']) . "</td><td>" . $e($l['detail']) . "</td></tr>";
        }
        echo "</table><h2>Evidências (documentos)</h2><ul>";
        foreach (Documentos::rows($id) as $d) {
            echo "<li>" . $e($d['filename']) . ($d['code'] ? ' — etapa ' . $e(Fase::name((string) $d['code'])) : '') . ' · ' . $e(Menu::date($d['date_creation'])) . "</li>";
        }
        echo "</ul>" . ($f['sharepoint_url'] ? "<p>Pasta SharePoint: " . $e($f['sharepoint_url']) . "</p>" : '');
        echo "<p style='color:#64748b;font-size:11px;margin-top:24px'>Relatório gerado a partir dos registros do evento no GLPI. Valores de simulação de viagem são estimativas não vinculantes.</p></body></html>";
    }
}
