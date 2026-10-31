<?php

namespace GlpiPlugin\Gestaoeventos;

use RuntimeException;

/**
 * Relatórios gerenciais com filtros e exportação CSV. A tela e o CSV usam a mesma consulta (mesmos números),
 * respeitam as entidades e o perfil (financeiro só com o direito) e os filtros aplicados.
 */
class Relatorios
{
    public const TYPES = [
        'eventos'    => ['Eventos', 'ti-calendar-event', false],
        'financeiro' => ['Financeiro: Budget × EAC × Realizado', 'ti-cash', true],
        'categorias' => ['Financeiro por categoria', 'ti-chart-pie', true],
        'slo'        => ['SLO por etapa', 'ti-target-arrow', false],
        'aprovacoes' => ['Aprovações e tempos de decisão', 'ti-signature', false],
        'viagens'    => ['Viagens simuladas', 'ti-plane', true],
    ];

    private static function eventos(array $get): array
    {
        global $DB;
        $where = ['is_deleted' => 0] + getEntitiesRestrictCriteria(Evento::getTable(), 'entities_id', '', true);
        if ($scope = Acesso::scope()) {
            $where[] = $scope;
        }
        if (!empty($get['status']) && isset(Evento::STATUSES[(int) $get['status']])) {
            $where['status'] = (int) $get['status'];
        }
        if (!empty($get['type']) && isset(Evento::TYPES[$get['type']])) {
            $where['event_type'] = $get['type'];
        }
        if (!empty($get['gp'])) {
            $where['users_id_gp'] = (int) $get['gp'];
        }
        if (!empty($get['partner'])) {
            $where['partner'] = ['LIKE', '%' . $get['partner'] . '%'];
        }
        if (!empty($get['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['from'])) {
            $where[] = ['date_start' => ['>=', $get['from']]];
        }
        if (!empty($get['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['to'])) {
            $where[] = ['date_start' => ['<=', $get['to']]];
        }
        return iterator_to_array($DB->request(['FROM' => Evento::getTable(), 'WHERE' => $where, 'ORDER' => 'date_start DESC']), false);
    }

    /** @return array{0:string,1:array<int,string>,2:array<int,array<int,string>>} título, cabeçalhos e linhas (texto puro) */
    public static function data(string $type, array $get): array
    {
        global $DB;
        if (!isset(self::TYPES[$type])) {
            throw new RuntimeException('Relatório desconhecido.');
        }
        if (!Acesso::canView('relatorios')) {
            throw new RuntimeException('Seu perfil não tem acesso aos relatórios.');
        }
        if (self::TYPES[$type][2] && !Evento::canFinance()) {
            throw new RuntimeException('Este relatório é restrito ao perfil Financeiro.');
        }
        $events = self::eventos($get);
        $ids = array_column($events, 'id');
        $title = self::TYPES[$type][0];
        $rows = [];
        switch ($type) {
            case 'eventos':
                $h = ['Código', 'Evento', 'Tipo', 'Início', 'Fim', 'Cidade', 'Fabricante/parceiro', 'GP', 'Dono', 'Etapa atual', 'Aprovação', 'Status do projeto', 'Situação', 'Centro de custo', 'Dias no ciclo', 'Devoluções'];
                foreach ($events as $c) {
                    $rows[] = [$c['code'], $c['name'], Evento::TYPES[$c['event_type']][0] ?? '', Menu::date($c['date_start']), Menu::date($c['date_finish']), $c['city'], $c['partner'], getUserName((int) $c['users_id_gp']),
                        getUserName((int) $c['users_id_owner']), $c['current_phase'] ? Fase::name((string) $c['current_phase']) : '—', Evento::APPROVAL[$c['approval_status']][0] ?? '', Evento::PROJECT_STATUS[$c['project_status']][0] ?? '',
                        Evento::STATUSES[(int) $c['status']][0] ?? '', $c['cc_number'], $c['date_request'] ? Menu::days(Evento::cycleSeconds($c)) : '—', (string) (int) $c['returns']];
                }
                return [$title, $h, $rows];
            case 'financeiro':
                $h = ['Código', 'Evento', 'Budget', 'EAC', 'Comprometido aberto', 'Realizado', 'Saldo disponível', 'Desvio EAC × Budget', 'Receita (EAC)', 'Receita realizada', 'Resultado (realizado)'];
                $t = array_fill(0, 9, 0.0);
                foreach ($events as $c) {
                    $s = Financeiro::summary((int) $c['id']);
                    $d = $s['despesa'];
                    $vals = [$s['limite'], $d['eac'], $d['open'], $d['real'], $s['saldo'], $s['desvio'], $s['receita']['eac'], $s['receita']['real'], $s['resultado']['real']];
                    foreach ($vals as $i => $v) {
                        $t[$i] += $v;
                    }
                    $rows[] = array_merge([$c['code'], $c['name']], array_map(fn ($v) => Menu::num((float) $v), $vals));
                }
                $rows[] = array_merge(['', 'TOTAL'], array_map(fn ($v) => Menu::num((float) $v), $t));
                return [$title, $h, $rows];
            case 'categorias':
                $by = [];
                foreach ($events as $c) {
                    foreach (Financeiro::summary((int) $c['id'])['categorias'] as $cat => $v) {
                        $by[$cat] ??= ['budget' => 0.0, 'eac' => 0.0, 'open' => 0.0, 'real' => 0.0];
                        foreach (['budget', 'eac', 'open', 'real'] as $k) {
                            $by[$cat][$k] += $v[$k];
                        }
                    }
                }
                ksort($by);
                foreach ($by as $cat => $v) {
                    $rows[] = [$cat, Menu::num($v['budget']), Menu::num($v['eac']), Menu::num($v['open']), Menu::num($v['real']), Menu::num($v['eac'] - $v['budget'])];
                }
                return [$title, ['Categoria', 'Budget', 'EAC', 'Comprometido aberto', 'Realizado', 'Desvio EAC × Budget'], $rows];
            case 'slo':
                $per = [];
                if ($ids) {
                    foreach ($DB->request(['FROM' => Fase::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $ids, 'status' => 'done']]) as $f) {
                        $per[$f['code']][] = [Fase::elapsed($f) / 86400, Fase::isLate($f), (int) $f['slo_days'], (int) $f['returns']];
                    }
                }
                foreach (Etapa::all() as $code => $et) {
                    $l = $per[$code] ?? [];
                    $n = count($l);
                    $days = array_column($l, 0);
                    sort($days);
                    $withSlo = array_filter($l, fn ($x) => $x[2] > 0);
                    $ok = count(array_filter($withSlo, fn ($x) => !$x[1]));
                    $rows[] = [$et['name'], Fase::area(['code' => $code, 'groups_id' => $et['groups_id']]), (string) $et['slo_days'], (string) $n, $n ? Menu::num(array_sum($days) / $n, 1) : '—',
                        $n ? Menu::num($days[intdiv($n, 2)], 1) : '—', $n ? Menu::num(end($days), 1) : '—', $withSlo ? round(100 * $ok / count($withSlo)) . '%' : '—', (string) array_sum(array_column($l, 3))];
                }
                return [$title, ['Etapa', 'Área', 'SLO (dias)', 'Concluídas', 'Média (dias)', 'Mediana (dias)', 'Maior (dias)', 'No SLO', 'Devoluções'], $rows];
            case 'aprovacoes':
                if ($ids) {
                    foreach ($DB->request(['FROM' => Aprovacao::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $ids], 'ORDER' => 'date_requested DESC']) as $a) {
                        $c = array_values(array_filter($events, fn ($x) => (int) $x['id'] === (int) $a['plugin_gestaoeventos_eventos_id']))[0];
                        $hours = $a['date_decision'] ? Menu::num((strtotime($a['date_decision']) - strtotime($a['date_requested'])) / 3600, 1) : '—';
                        $rows[] = [$c['code'], $c['name'], Aprovacao::KINDS[$a['kind']] ?? '', $a['title'], $a['label'], Menu::num((float) $a['amount']), Aprovacao::STATUSES[$a['status']][0] ?? '', getUserName((int) $a['users_id_requested']),
                            Menu::date($a['date_requested'], true), $a['date_decision'] ? getUserName((int) $a['users_id_decided']) : '', Menu::date($a['date_decision'], true), $hours, $a['justification']];
                    }
                }
                return [$title, ['Código', 'Evento', 'Tipo', 'Pedido', 'Alçada', 'Valor', 'Situação', 'Solicitante', 'Solicitada em', 'Decidiu', 'Decisão em', 'Horas até decidir', 'Justificativa'], $rows];
            case 'viagens':
                foreach ($events as $c) {
                    foreach (Viajante::forEvento((int) $c['id']) as $v) {
                        $rows[] = [$c['code'], $c['name'], $v['name'], Viajante::MODES[$v['mode']] ?? '', Menu::num((float) $v['km_oneway']), 'v' . (int) $v['params_version'],
                            ((json_decode((string) $v['calc'], true) ?: [])['source'] ?? '') === 'uber' ? 'Cotação Uber' : 'Estimativa interna', Menu::num((float) $v['total']), $v['total_pending'] ? 'há trecho sem estimativa' : '', Menu::date($v['calc_date'], true)];
                    }
                }
                return [$title, ['Código', 'Evento', 'Viajante', 'Modo', 'Km (um sentido)', 'Parâmetros', 'Origem do valor', 'Total estimado (R$)', 'Observação', 'Calculado em'], $rows];
        }
        throw new RuntimeException('Relatório desconhecido.');
    }

    /** Demonstrativo financeiro de um evento: visão gerencial e trilha de cada lançamento. */
    public static function statement(Evento $ev): array
    {
        if (!Evento::canFinance() || !Acesso::canView('exportar')) {
            throw new RuntimeException('O demonstrativo financeiro é restrito ao perfil Financeiro com direito de exportar.');
        }
        $rows = [];
        foreach (Registro::forEvento($ev->getID(), 'lancamento') as $l) {
            $rows[] = [$l['nature'] === 'receita' ? 'Receita' : 'Despesa', trim($l['category'] . ($l['subcategory'] !== '' ? ' > ' . $l['subcategory'] : '')), $l['title'], $l['supplier'], $l['cc'],
                Menu::num((float) $l['amount_budget']), Menu::num((float) $l['amount_eac']), Menu::num((float) $l['amount_committed']), Menu::num((float) $l['amount_real']), Registro::KINDS['lancamento'][4]['status'][2][$l['status']] ?? $l['status'],
                $l['doc_ref'], Menu::date($l['date_due']), Menu::date($l['date_done']), $l['users_id_owner'] ? getUserName((int) $l['users_id_owner']) : '', $l['users_id_approver'] ? getUserName((int) $l['users_id_approver']) : '', getUserName((int) $l['users_id']), Menu::date($l['date_creation'], true)];
        }
        return ['Demonstrativo ' . $ev->fields['code'], ['Natureza', 'Categoria', 'Descrição', 'Fornecedor/fonte', 'Centro de custo', 'Budget', 'EAC', 'Comprometido', 'Realizado', 'Situação', 'Documento', 'Vencimento', 'Pago/recebido em', 'Solicitante', 'Aprovador', 'Lançado por', 'Lançado em'], $rows];
    }

    public static function csv(string $title, array $headers, array $rows): void
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $title) ?: 'relatorio');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v; // evita fórmula injetada em planilha
        fputcsv($out, array_map($safe, $headers), ';');
        foreach ($rows as $r) {
            fputcsv($out, array_map($safe, $r), ';');
        }
        fclose($out);
    }

    public static function page(array $get): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $type = isset(self::TYPES[$get['r'] ?? '']) ? $get['r'] : 'eventos';
        $gps = [];
        foreach ($DB->request(['SELECT' => ['users_id_gp'], 'DISTINCT' => true, 'FROM' => Evento::getTable(), 'WHERE' => ['is_deleted' => 0, 'users_id_gp' => ['>', 0]] + getEntitiesRestrictCriteria(Evento::getTable(), 'entities_id', '', true)]) as $r) {
            $gps[(int) $r['users_id_gp']] = getUserName((int) $r['users_id_gp']);
        }
        $q = array_intersect_key($get, array_flip(['status', 'type', 'gp', 'partner', 'from', 'to']));
        echo "<div class='ge'>" . Menu::title('Relatórios', 'relatorios');
        echo "<div class='ge-lib'><aside class='ge-lib-side card'><div class='ge-lib-head'>Relatório</div>";
        foreach (self::TYPES as $k => [$label, $icon, $needFin]) {
            if ($needFin && !Evento::canFinance()) {
                continue;
            }
            echo "<a class='ge-lib-folder" . ($k === $type ? ' on' : '') . "' href='?" . $e(http_build_query(['r' => $k] + $q)) . "'><i class='ti $icon'></i><span>" . $e($label) . "</span></a>";
        }
        echo "</aside><section class='ge-lib-main'>";
        echo "<div class='card mb-3'><div class='card-body'><form method='get' class='row g-2 align-items-end m-0'><input type='hidden' name='r' value='" . $e($type) . "'>"
            . "<div class='col-md-2'><label class='form-label small mb-0'>Início do evento — de</label><input type='date' class='form-control form-control-sm' name='from' value='" . $e($q['from'] ?? '') . "'></div>"
            . "<div class='col-md-2'><label class='form-label small mb-0'>até</label><input type='date' class='form-control form-control-sm' name='to' value='" . $e($q['to'] ?? '') . "'></div>"
            . "<div class='col-md-2'><label class='form-label small mb-0'>Tipo</label><select class='form-select form-select-sm' name='type'><option value=''>Todos</option>" . Menu::options(Menu::labels(Evento::TYPES), $q['type'] ?? '') . "</select></div>"
            . "<div class='col-md-2'><label class='form-label small mb-0'>Situação</label><select class='form-select form-select-sm' name='status'><option value=''>Todas</option>" . Menu::options(Menu::labels(Evento::STATUSES), $q['status'] ?? '') . "</select></div>"
            . "<div class='col-md-2'><label class='form-label small mb-0'>GP</label><select class='form-select form-select-sm' name='gp'><option value=''>Todos</option>" . Menu::options($gps, (string) ($q['gp'] ?? '')) . "</select></div>"
            . "<div class='col-md-2'><label class='form-label small mb-0'>Fabricante / parceiro</label><input class='form-control form-control-sm' name='partner' value='" . $e($q['partner'] ?? '') . "'></div>"
            . "<div class='col-12 d-flex gap-2'><button class='btn btn-sm btn-primary'><i class='ti ti-filter me-1'></i>Filtrar</button>"
            . "<a class='btn btn-sm btn-outline-secondary' href='" . $e(Menu::url('/front/exportar.php') . '?' . http_build_query(['tipo' => $type] + $q)) . "'><i class='ti ti-download me-1'></i>Exportar CSV</a></div></form></div></div>";
        try {
            [$title, $headers, $rows] = self::data($type, $q);
        } catch (RuntimeException $ex) {
            echo "<div class='alert alert-warning'>" . $e($ex->getMessage()) . "</div></section></div></div>";
            return;
        }
        echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . $e($title) . "</h3><div class='card-actions small text-secondary'>" . count($rows) . " linha(s) · os números batem com o CSV</div></div>"
            . "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr>";
        foreach ($headers as $h) {
            echo "<th>" . $e($h) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $r) {
            echo "<tr>" . implode('', array_map(fn ($v) => "<td class='small'>" . $e($v) . "</td>", $r)) . "</tr>";
        }
        echo $rows ? '' : "<tr><td colspan='" . count($headers) . "' class='text-secondary small'>Nada para os filtros escolhidos.</td></tr>";
        echo "</tbody></table></div></div></section></div></div>";
    }
}
