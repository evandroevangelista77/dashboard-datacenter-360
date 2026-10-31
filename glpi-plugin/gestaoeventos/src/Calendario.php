<?php

namespace GlpiPlugin\Gestaoeventos;

use DateTimeImmutable;

/**
 * Segunda visão da lista de eventos: calendário mensal. Cada evento é uma barra que atravessa os dias
 * em que acontece (cor pela situação: concluído, em andamento, atrasado, aguardando aprovação, rascunho,
 * cancelado); marcos, prazos críticos e aprovações com vencimento aparecem como marcas no dia.
 * Passar o mouse mostra os detalhes; clicar na barra abre o evento e clicar no dia abre o resumo do dia.
 */
class Calendario
{
    private const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const DOW = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    /** Classe de cor da barra: ok, now, late, warn (aprovação pendente), draft, canceled. */
    private static function kind(array $c, ?array $phase): array
    {
        if ((int) $c['status'] === Evento::CANCELED) {
            return ['canceled', 'Cancelado'];
        }
        if ((int) $c['status'] === Evento::DONE) {
            return ['ok', 'Concluído'];
        }
        if ($c['is_draft']) {
            return ['draft', 'Rascunho'];
        }
        if ($phase && Fase::isLate($phase)) {
            return ['late', 'Etapa atrasada'];
        }
        if (in_array($c['approval_status'], ['em_aprovacao', 'triagem'], true)) {
            return ['warn', $c['approval_status'] === 'triagem' ? 'Em triagem' : 'Aguardando aprovação'];
        }
        return ['now', 'Em andamento'];
    }

    public static function show(array $get): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $fin = Evento::canFinance();
        $m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($get['m'] ?? '')) ? $get['m'] : date('Y-m', strtotime($_SESSION['glpi_currenttime']));
        $today = date('Y-m-d', strtotime($_SESSION['glpi_currenttime']));
        $first = new DateTimeImmutable("$m-01");
        $last = $first->modify('last day of this month');
        $gs = $first->modify('-' . (int) $first->format('w') . ' days');
        $ge = $last->modify('+' . (6 - (int) $last->format('w')) . ' days');
        $prev = $first->modify('-1 month')->format('Y-m');
        $next = $first->modify('+1 month')->format('Y-m');

        // filtros (os mesmos do painel)
        $fType = isset(Evento::TYPES[$get['type'] ?? '']) ? $get['type'] : '';
        $fGp = (int) ($get['gp'] ?? 0);
        $fPartner = trim((string) ($get['partner'] ?? ''));
        $where = ['is_deleted' => 0, ['NOT' => ['date_start' => null]]] + getEntitiesRestrictCriteria(Evento::getTable(), 'entities_id', '', true);
        if ($scope = Acesso::scope()) {
            $where[] = $scope;
        }
        if ($fType) {
            $where['event_type'] = $fType;
        }
        if ($fGp) {
            $where['users_id_gp'] = $fGp;
        }
        if ($fPartner !== '') {
            $where['partner'] = ['LIKE', "%$fPartner%"];
        }
        $events = iterator_to_array($DB->request(['FROM' => Evento::getTable(), 'WHERE' => $where, 'ORDER' => 'date_start']), false);
        $byId = array_column($events, null, 'id');
        $active = [];
        if ($byId) {
            foreach ($DB->request(['FROM' => Fase::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => array_keys($byId), 'status' => 'active']]) as $p) {
                $active[(int) $p['plugin_gestaoeventos_eventos_id']] = $p;
            }
        }

        // eventos que tocam a grade
        $inGrid = [];
        foreach ($events as $c) {
            $s = $c['date_start'];
            $en = $c['date_finish'] && $c['date_finish'] >= $s ? $c['date_finish'] : $s;
            if ($s <= $ge->format('Y-m-d') && $en >= $gs->format('Y-m-d')) {
                $inGrid[] = $c + ['_end' => $en];
            }
        }

        // marcas do dia: marcos, prazo crítico, aprovações com vencimento
        $marks = [];
        $gsD = $gs->format('Y-m-d');
        $geD = $ge->format('Y-m-d');
        if ($byId) {
            foreach ($DB->request(['FROM' => Registro::getTable(), 'WHERE' => ['kind' => 'marco', 'plugin_gestaoeventos_eventos_id' => array_keys($byId), ['date_due' => ['>=', $gsD]], ['date_due' => ['<=', $geD]]]]) as $r) {
                $c = $byId[$r['plugin_gestaoeventos_eventos_id']];
                $done = $r['status'] === 'feito';
                $marks[$r['date_due']][] = ['icon' => 'ti-flag', 'cls' => $done ? 'ok' : ($r['date_due'] < $today ? 'crit' : ($r['is_critical'] ? 'warn' : 'info')), 'title' => $r['title'], 'sub' => 'Marco' . ($r['is_critical'] ? ' crítico' : '') . ' · ' . $c['code'] . ($done ? ' · concluído' : ''), 'url' => Evento::tabUrl((int) $c['id'], 'planejamento')];
            }
            foreach ($events as $c) {
                if ($c['date_critical'] && $c['date_critical'] >= $gsD && $c['date_critical'] <= $geD && (int) $c['status'] === Evento::OPEN) {
                    $marks[$c['date_critical']][] = ['icon' => 'ti-alarm', 'cls' => $c['date_critical'] < $today ? 'crit' : 'warn', 'title' => 'Prazo crítico', 'sub' => $c['code'] . ' · ' . $c['name'], 'url' => Evento::getFormURLWithID((int) $c['id'])];
                }
            }
            foreach ($DB->request(['FROM' => Aprovacao::getTable(), 'WHERE' => ['status' => 'pending', 'plugin_gestaoeventos_eventos_id' => array_keys($byId), ['date_due' => ['>=', "$gsD 00:00:00"]], ['date_due' => ['<=', "$geD 23:59:59"]]]]) as $a) {
                $c = $byId[$a['plugin_gestaoeventos_eventos_id']];
                $d = substr((string) $a['date_due'], 0, 10);
                $marks[$d][] = ['icon' => 'ti-signature', 'cls' => Aprovacao::isOverdue($a) ? 'crit' : 'warn', 'title' => 'Aprovação: ' . (Aprovacao::KINDS[$a['kind']] ?? ''), 'sub' => $c['code'] . ' · alçada ' . $a['label'] . (Aprovacao::isOverdue($a) ? ' · vencida' : ''), 'url' => Evento::tabUrl((int) $c['id'], 'aprovacoes')];
            }
        }

        // ------------------------------------------------------------------ cabeçalho
        $gps = [];
        foreach ($events as $c) {
            if ((int) $c['users_id_gp']) {
                $gps[(int) $c['users_id_gp']] = getUserName((int) $c['users_id_gp']);
            }
        }
        $qs = fn (array $o) => '?' . http_build_query(array_filter(['m' => $m, 'type' => $fType, 'gp' => $fGp, 'partner' => $fPartner] + [], fn ($v) => $v !== '' && $v !== 0) + $o);
        $q = array_filter(['type' => $fType, 'gp' => $fGp, 'partner' => $fPartner], fn ($v) => $v !== '' && $v !== 0);
        $nav = "<div class='btn-group'><a class='btn btn-outline-secondary' href='?" . $e(http_build_query(['m' => $prev] + $q)) . "' title='Mês anterior'><i class='ti ti-chevron-left'></i></a>"
            . "<a class='btn btn-outline-secondary' href='?" . $e(http_build_query(['m' => date('Y-m', strtotime($_SESSION['glpi_currenttime']))] + $q)) . "'>Hoje</a>"
            . "<a class='btn btn-outline-secondary' href='?" . $e(http_build_query(['m' => $next] + $q)) . "' title='Próximo mês'><i class='ti ti-chevron-right'></i></a></div>"
            . "<form method='get' class='d-flex flex-wrap gap-2 align-items-center m-0'><input type='hidden' name='m' value='" . $e($m) . "'>"
            . "<select class='form-select form-select-sm w-auto' name='type' onchange='this.form.submit()'><option value=''>Todos os tipos</option>" . Menu::options(Menu::labels(Evento::TYPES), $fType) . "</select>"
            . "<select class='form-select form-select-sm w-auto' name='gp' onchange='this.form.submit()'><option value='0'>Todos os GPs</option>" . Menu::options($gps, (string) $fGp) . "</select>"
            . "<input class='form-control form-control-sm w-auto' name='partner' value='" . $e($fPartner) . "' placeholder='Fabricante / parceiro' onchange='this.form.submit()'></form>"
            . "<div class='btn-group'><a class='btn btn-outline-secondary' href='" . $e(Evento::getSearchURL()) . "'><i class='ti ti-list me-1'></i>Lista</a><a class='btn btn-primary disabled'><i class='ti ti-calendar me-1'></i>Calendário</a></div>"
            . (Evento::canCreate() ? Menu::button('Novo evento', Evento::getFormURL()) : '');
        echo "<div class='ge'>" . Menu::title(ucfirst(self::MONTHS[(int) $first->format('n') - 1]) . ' de ' . $first->format('Y'), 'calendario', $nav);

        // ------------------------------------------------------------------ semanas
        $data = [];
        $day = $gs;
        $weeks = '';
        $nWeeks = (int) ceil(($ge->diff($gs)->days + 1) / 7);
        for ($w = 0; $w < $nWeeks; $w++) {
            $ws = $gs->modify("+" . ($w * 7) . " days");
            $we = $ws->modify('+6 days');
            // barras da semana e raias
            $bars = [];
            foreach ($inGrid as $c) {
                if ($c['date_start'] > $we->format('Y-m-d') || $c['_end'] < $ws->format('Y-m-d')) {
                    continue;
                }
                $s = max($c['date_start'], $ws->format('Y-m-d'));
                $en = min($c['_end'], $we->format('Y-m-d'));
                $bars[] = ['c' => $c, 'col' => (int) $ws->diff(new DateTimeImmutable($s))->days + 1, 'span' => (int) (new DateTimeImmutable($s))->diff(new DateTimeImmutable($en))->days + 1, 'l' => $c['date_start'] < $ws->format('Y-m-d'), 'r' => $c['_end'] > $we->format('Y-m-d')];
            }
            usort($bars, fn ($a, $b) => [$a['col'], -$a['span']] <=> [$b['col'], -$b['span']]);
            $lanes = [];
            foreach ($bars as &$b) {
                for ($lane = 0;; $lane++) {
                    if (($lanes[$lane] ?? 0) < $b['col']) {
                        $lanes[$lane] = $b['col'] + $b['span'] - 1;
                        $b['lane'] = $lane;
                        break;
                    }
                }
            }
            unset($b);
            $laneCount = count($lanes);
            $pad = 32 + $laneCount * 27;
            $cells = '';
            for ($i = 0; $i < 7; $i++) {
                $d = $ws->modify("+$i days");
                $ds = $d->format('Y-m-d');
                $other = $d->format('Y-m') !== $m;
                $items = [];
                foreach ($bars as $b) {
                    if ($b['c']['date_start'] <= $ds && $b['c']['_end'] >= $ds) {
                        [$cls, $label] = self::kind($b['c'], $active[(int) $b['c']['id']] ?? null);
                        $items[] = ['icon' => 'ti-calendar-event', 'cls' => $cls, 'title' => $b['c']['name'], 'sub' => $b['c']['code'] . ' · ' . $label . ($b['c']['city'] ? ' · ' . $b['c']['city'] : ''), 'url' => Evento::getFormURLWithID((int) $b['c']['id'])];
                    }
                }
                $items = array_merge($items, $marks[$ds] ?? []);
                if ($items) {
                    $data[$ds] = $items;
                }
                $dayMarks = $marks[$ds] ?? [];
                $rank = ['crit' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
                usort($dayMarks, fn ($a, $b) => ($rank[$a['cls']] ?? 9) <=> ($rank[$b['cls']] ?? 9));
                $chips = '';
                foreach (array_slice($dayMarks, 0, 3) as $mk) {
                    $icon = $mk['cls'] === 'ok' ? 'ti-circle-check' : $mk['icon'];
                    $chips .= "<a class='ge-cal-chip {$mk['cls']}' href='" . $e($mk['url']) . "' data-ctip='" . $e(json_encode(['title' => $mk['title'], 'sub' => $mk['sub'], 'cls' => $mk['cls']], JSON_UNESCAPED_UNICODE)) . "'><i class='ti $icon'></i><span>" . $e(mb_strimwidth($mk['title'], 0, 70, '…')) . "</span></a>";
                }
                if (count($dayMarks) > 3) {
                    $chips .= "<span class='ge-cal-more'>+" . (count($dayMarks) - 3) . " mais</span>";
                }
                $chips = $chips ? "<div class='ge-cal-chips'>$chips</div>" : '';
                $cells .= "<div class='ge-cal-day" . ($other ? ' other' : '') . ($ds === $today ? ' today' : '') . ($i === 0 || $i === 6 ? ' weekend' : '') . "' data-day='$ds' style='padding-top:{$pad}px'>"
                    . "<span class='ge-cal-num'>" . $d->format('j') . ($d->format('j') === '1' ? ' ' . mb_substr(self::MONTHS[(int) $d->format('n') - 1], 0, 3) : '') . "</span>" . ($items ? "<span class='ge-cal-count'>" . count($items) . "</span>" : '') . $chips . "</div>";
            }
            $barHtml = '';
            foreach ($bars as $b) {
                $c = $b['c'];
                $p = $active[(int) $c['id']] ?? null;
                [$cls, $label] = self::kind($c, $p);
                $tip = ['title' => $c['name'], 'sub' => $c['code'] . ' · ' . (Evento::TYPES[$c['event_type']][0] ?? ''), 'cls' => $cls, 'status' => $label,
                    'rows' => array_values(array_filter([
                        ['Data', Menu::date($c['date_start']) . ($c['_end'] !== $c['date_start'] ? ' a ' . Menu::date($c['_end']) : '')], ['Local', trim($c['venue'] . ' · ' . $c['city'], ' ·')],
                        ['Etapa', $p ? Fase::name($p['code']) . ' · ' . Menu::days(Fase::elapsed($p)) . 'd' . ((int) $p['slo_days'] ? ' / ' . (int) $p['slo_days'] : '') : ((int) $c['status'] === Evento::OPEN ? '—' : '')],
                        ['Aprovação', Evento::APPROVAL[$c['approval_status']][0] ?? ''], ['GP', getUserName((int) $c['users_id_gp'])], ['Fabricante', (string) $c['partner']],
                        $fin || (int) $c['users_id_owner'] === (int) \Session::getLoginUserID() ? ['Orçamento', Menu::money((float) $c['budget_estimated'])] : null,
                    ]))];
                $barHtml .= "<a class='ge-cal-bar $cls" . ($b['l'] ? ' cl' : '') . ($b['r'] ? ' cr' : '') . "' style='grid-column:{$b['col']} / span {$b['span']};grid-row:" . ($b['lane'] + 1) . "' href='" . $e(Evento::getFormURLWithID((int) $c['id'])) . "' data-ctip='" . $e(json_encode($tip, JSON_UNESCAPED_UNICODE)) . "'>"
                    . ($b['l'] ? "<i class='ti ti-chevron-left'></i>" : '') . "<span><b>" . $e($c['code']) . "</b> " . $e($c['name']) . "</span>" . ($b['r'] ? "<i class='ti ti-chevron-right'></i>" : '') . "</a>";
            }
            $weeks .= "<div class='ge-cal-week'><div class='ge-cal-days'>$cells</div><div class='ge-cal-bars' style='grid-template-rows:repeat(" . max(1, $laneCount) . ",24px)'>$barHtml</div></div>";
        }
        echo "<div class='card'><div class='ge-cal'><div class='ge-cal-head'>" . implode('', array_map(fn ($d) => "<div>$d</div>", self::DOW)) . "</div>$weeks</div>"
            . "<div class='card-footer ge-g-legend'><span><i class='ok'></i>Concluído</span><span><i class='now'></i>Em andamento</span><span><i class='late'></i>Etapa atrasada</span><span><i class='warn'></i>Aguardando aprovação</span><span><i class='draft'></i>Rascunho</span><span><i class='canc'></i>Cancelado</span>"
            . "<span class='text-secondary'><i class='ti ti-flag'></i> marco · <i class='ti ti-alarm'></i> prazo crítico · <i class='ti ti-signature'></i> aprovação com vencimento. Passe o mouse para os detalhes, clique na barra para abrir o evento e no dia para o resumo.</span></div></div>";

        // agenda do mês
        $month = array_values(array_filter($inGrid, fn ($c) => $c['date_start'] <= $last->format('Y-m-d') && $c['_end'] >= $first->format('Y-m-d')));
        echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-list-details me-2'></i>Eventos do mês</h3><div class='card-actions small text-secondary'>" . count($month) . "</div></div>"
            . "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Evento</th><th>Tipo</th><th>Data</th><th>Cidade</th><th>Etapa</th><th>Aprovação</th><th>GP</th></tr></thead><tbody>";
        foreach ($month as $c) {
            echo "<tr><td><a href='" . $e(Evento::getFormURLWithID((int) $c['id'])) . "'><b>" . $e($c['code']) . "</b> " . $e($c['name']) . "</a></td><td class='small'>" . $e(Evento::TYPES[$c['event_type']][0] ?? '') . "</td>"
                . "<td class='small text-nowrap'>" . Menu::date($c['date_start']) . ($c['_end'] !== $c['date_start'] ? ' a ' . Menu::date($c['_end']) : '') . "</td><td class='small'>" . $e($c['city']) . "</td><td class='small'>" . $e($c['current_phase'] ? Fase::name((string) $c['current_phase']) : '—')
                . "</td><td>" . Evento::badge(Evento::APPROVAL, (string) $c['approval_status']) . "</td><td class='small'>" . $e(getUserName((int) $c['users_id_gp'])) . "</td></tr>";
        }
        echo $month ? '' : "<tr><td colspan='7' class='text-secondary small'>Nenhum evento neste mês.</td></tr>";
        echo "</tbody></table></div></div>";
        echo "<script type='application/json' id='ge-cal-data'>" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "</script>" . Menu::script('drawer.js') . Menu::script('calendario.js') . "</div>";
    }
}
