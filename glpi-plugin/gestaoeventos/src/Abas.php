<?php

namespace GlpiPlugin\Gestaoeventos;

use CommonDBTM;
use CommonGLPI;
use Html;
use Project;
use ProjectTask;
use RuntimeException;
use Session;
use Throwable;

/**
 * As abas do evento: uma por parte do processo. Cada aba mostra, no alto, a etapa a que pertence
 * (situação, SLO, o que falta e o botão de concluir), de modo que dá para andar o fluxo de qualquer
 * uma delas; o que se cadastra numa aba alimenta as exigências das etapas seguintes.
 */
class Abas extends CommonGLPI
{
    /** número da aba => chave */
    public const TABS = [1 => 'aprovacoes', 2 => 'planejamento', 3 => 'checklists', 4 => 'marketing', 5 => 'compras', 6 => 'financeiro', 7 => 'viagens', 8 => 'logistica', 9 => 'execucao', 10 => 'encerramento', 11 => 'historico', 12 => 'avisos'];

    /** chave => [título, ícone, etapa a que pertence] */
    public const META = [
        'aprovacoes'   => ['Aprovações', 'ti ti-signature', 'avaliacao'],
        'planejamento' => ['Planejamento', 'ti ti-route', 'planejamento'],
        'checklists'   => ['Checklists', 'ti ti-list-check', ''],
        'marketing'    => ['Marketing', 'ti ti-speakerphone', 'producao'],
        'compras'      => ['Compras', 'ti ti-shopping-cart', 'compras'],
        'financeiro'   => ['Financeiro', 'ti ti-cash', 'planejamento'],
        'viagens'      => ['Viagens', 'ti ti-plane', 'producao'],
        'logistica'    => ['Logística', 'ti ti-truck-delivery', 'producao'],
        'execucao'     => ['Execução', 'ti ti-player-play', 'execucao'],
        'encerramento' => ['Encerramento', 'ti ti-flag-check', 'encerramento'],
        'historico'    => ['Linha do tempo', 'ti ti-timeline-event', ''],
        'avisos'       => ['Avisos', 'ti ti-bell-ringing', ''],
    ];

    /** etapa => abas onde se cumpre o que ela exige */
    private const GUIDE = [
        'briefing' => [], 'avaliacao' => ['aprovacoes', 'financeiro'], 'planejamento' => ['planejamento', 'financeiro', 'aprovacoes'], 'compras' => ['compras', 'aprovacoes', 'financeiro'],
        'producao' => ['marketing', 'logistica', 'checklists', 'viagens'], 'prontidao' => ['checklists', 'logistica'], 'execucao' => ['execucao'], 'encerramento' => ['encerramento', 'financeiro', 'marketing'],
    ];

    // ------------------------------------------------------------------ abas do GLPI

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Evento || $item->isNewItem()) {
            return '';
        }
        $count = (bool) ($_SESSION['glpishow_count_on_tabs'] ?? false);
        $id = $item->getID();
        $n = fn (string $kind, array $w = []) => $count ? Registro::countKind($id, $kind, $w) : 0;
        $counts = [
            'aprovacoes' => $count ? countElementsInTable(Aprovacao::getTable(), ['plugin_gestaoeventos_eventos_id' => $id, 'status' => 'pending']) : 0,
            'planejamento' => $n('marco', ['status' => ['a_fazer', 'andamento', 'bloqueado']]), 'checklists' => $n('checklist', ['status' => 'a_fazer']),
            'marketing' => $n('peca') + $n('convidado') + $n('brinde'), 'compras' => $n('compra'), 'financeiro' => 0, 'viagens' => $count ? countElementsInTable(Viajante::getTable(), ['plugin_gestaoeventos_eventos_id' => $id]) : 0,
            'logistica' => $n('material') + $n('reserva'), 'execucao' => $n('ocorrencia', ['status' => 'aberta']), 'encerramento' => 0, 'historico' => 0, 'avisos' => 0,
        ];
        $titles = [];
        foreach (self::TABS as $num => $key) {
            if (!Acesso::canView($key)) {
                continue;
            }
            [$label, $icon] = self::META[$key];
            $titles[$num] = self::createTabEntry($label, $counts[$key], Evento::class, $icon);
        }
        return $titles;
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Evento) {
            return false;
        }
        $key = self::TABS[(int) $tabnum] ?? null;
        if (!$key) {
            return false;
        }
        if (!Acesso::canView($key)) {
            echo "<div class='ge ge-tab'><div class='alert alert-warning'><i class='ti ti-lock me-1'></i>Seu perfil não tem acesso a este módulo.</div></div>";
            return true;
        }
        $edit = $item->canEditData() && Acesso::canEdit($key);
        $back = Evento::tabUrl($item->getID(), $key);
        echo Dicas::strip($item, $key);
        switch ($key) {
            case 'aprovacoes':
                Aprovacao::tab($item, $edit);
                break;
            case 'planejamento':
                self::planejamento($item, $edit, $back);
                break;
            case 'checklists':
                self::checklists($item, $edit, $back);
                break;
            case 'marketing':
                self::marketing($item, $edit, $back);
                break;
            case 'compras':
                self::compras($item, $edit, $back);
                break;
            case 'financeiro':
                Financeiro::tab($item, $edit);
                break;
            case 'viagens':
                Viagem::tab($item, $edit);
                break;
            case 'logistica':
                self::logistica($item, $edit, $back);
                break;
            case 'execucao':
                self::execucao($item, $edit, $back);
                break;
            case 'encerramento':
                Encerramento::tab($item, $edit);
                break;
            case 'historico':
                Timeline::show($item);
                break;
            case 'avisos':
                Alerta::tab($item, $edit);
                break;
        }
        echo Menu::script('drawer.js') . Menu::script('gestaoeventos.js');
        return true;
    }

    // ------------------------------------------------------------------ cabeçalho de cada aba

    /** Links das abas onde se cumpre o que a etapa exige. */
    public static function phaseGuide(Evento $ev, array $p): string
    {
        $tabs = self::GUIDE[$p['code']] ?? [];
        if (!$tabs) {
            return '';
        }
        $h = "<div class='small mb-2 ge-guide'><i class='ti ti-compass me-1'></i>Onde fazer: ";
        foreach ($tabs as $t) {
            $h .= "<a class='badge bg-blue-lt me-1' href='" . Menu::e(Evento::tabUrl($ev->getID(), $t)) . "'><i class='" . self::META[$t][1] . " me-1'></i>" . Menu::e(self::META[$t][0]) . "</a>";
        }
        return $h . "</div>";
    }

    /** Faixa no alto da aba: a etapa dela, SLO, pendências e o botão de concluir. */
    public static function banner(Evento $ev, string $code): string
    {
        $e = [Menu::class, 'e'];
        $phases = Fase::forEvento($ev->getID());
        $p = $phases[$code] ?? null;
        if (!$p) {
            return '';
        }
        $et = Etapa::all()[$code] ?? [];
        $late = Fase::isLate($p);
        $warn = Fase::isWarn($p);
        [$label, $color] = Fase::STATUSES[$p['status']] ?? ['', '#64748b'];
        if ($late && $p['status'] === 'active') {
            [$label, $color] = ['Atrasada', '#ef4444'];
        }
        $slo = (int) $p['slo_days'];
        $secs = Fase::elapsed($p);
        $pct = $slo ? min(100, round(100 * $secs / ($slo * 86400))) : 0;
        $num = array_search($code, array_keys($phases), true) + 1;
        $h = "<div class='ge-banner" . ($late ? ' late' : '') . "' style='--c:$color'><div class='ge-banner-top'><span class='ge-banner-num'>$num</span><div class='me-auto'><b>Etapa " . $e(Fase::name($code)) . "</b>"
            . "<div class='small text-secondary'>" . $e(Fase::area($p)) . ($slo ? " · SLO $slo dia(s)" : '') . ($p['status'] === 'active' ? ' · há ' . Menu::days($secs) . ' dia(s)' : ($secs ? ' · levou ' . Menu::days($secs) . ' dia(s)' : '')) . "</div></div>"
            . "<span class='badge ge-badge' style='--c:$color'>" . $e($label) . ($warn ? ' · perto do prazo' : '') . "</span></div>";
        if ($slo && $p['status'] === 'active') {
            $h .= "<div class='ge-dr-bar" . ($late ? ' bad' : '') . "'><i style='width:$pct%'></i></div>";
        }
        if ($p['status'] === 'active' && (int) $ev->fields['status'] === Evento::OPEN) {
            $missing = Fase::missing($ev, $p);
            if ($missing) {
                $h .= "<ul class='ge-banner-miss'>" . implode('', array_map(fn ($m) => '<li>' . $e($m) . '</li>', array_slice($missing, 0, 6))) . "</ul>";
            } else {
                $h .= "<div class='small text-success mt-2'><i class='ti ti-check me-1'></i>Tudo o que a etapa pede está pronto.</div>";
            }
            if (Fase::canAct($ev, $p)) {
                $h .= "<form method='post' action='" . $e(Evento::getFormURL()) . "' class='d-flex gap-2 mt-2 align-items-center'><input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e(Evento::tabUrl($ev->getID(), self::keyOf($code))) . "'>"
                    . "<input class='form-control form-control-sm' name='comment' maxlength='500' placeholder='Comentário (opcional)' style='max-width:340px'>"
                    . "<button class='btn btn-sm btn-success' name='workflow' value='conclude'" . ($missing ? ' disabled' : '') . "><i class='ti ti-check me-1'></i>Concluir etapa</button>" . Html::closeForm(false);
            }
        } elseif ($p['status'] === 'pending') {
            $h .= "<div class='small text-secondary mt-1'>Esta etapa ainda não começou. Você já pode cadastrar o que vai precisar nela.</div>";
        }
        return $h . "</div>";
    }

    private static function keyOf(string $code): string
    {
        foreach (self::META as $k => [, , $phase]) {
            if ($phase === $code) {
                return $k;
            }
        }
        return 'aprovacoes';
    }

    // ------------------------------------------------------------------ planejamento

    private static function planejamento(Evento $ev, bool $edit, string $back): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $action = $e(Evento::getFormURL());
        $f = $ev->fields;
        $hid = "<input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e($back) . "'>";
        echo "<div class='ge ge-tab'>" . self::banner($ev, 'planejamento');

        // projeto do GLPI e cronograma
        echo "<div class='row g-3 mb-3'><div class='col-xl-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-briefcase me-2'></i>Projeto no GLPI</h3></div><div class='card-body'>";
        if ((int) $f['projects_id']) {
            echo "<p class='mb-2'><a href='" . $e(Project::getFormURLWithID((int) $f['projects_id'])) . "'><i class='ti ti-external-link me-1'></i>" . $e(\Dropdown::getDropdownName('glpi_projects', (int) $f['projects_id'])) . "</a></p>"
                . "<div class='small text-secondary'>As tarefas do projeto aparecem ao lado e refletem o que for alterado no GLPI.</div>";
        } else {
            echo "<p class='text-secondary small'>O evento ainda não tem projeto. Vincule um existente nos dados do evento ou crie um agora com as datas do briefing.</p>";
            if ($edit) {
                echo "<form method='post' action='$action' class='m-0'>$hid<button class='btn btn-outline-primary' name='plan_action' value='project_create'><i class='ti ti-plus me-1'></i>Criar projeto no GLPI</button>" . Html::closeForm(false);
            }
        }
        if ($edit) {
            echo "<hr><form method='post' action='$action' class='m-0'>$hid<button class='btn btn-outline-secondary' name='plan_action' value='schedule_seed'" . ($f['date_start'] ? '' : ' disabled') . "><i class='ti ti-calendar-plus me-1'></i>Gerar cronograma do modelo</button>"
                . "<div class='form-hint'>Cria os marcos padrão (de T-60 a T+15 dias em relação ao início do evento) e, se houver projeto, as tarefas dele. O que já existe é mantido.</div>" . Html::closeForm(false);
            if ((int) $f['projects_id']) {
                echo "<hr><form method='post' action='$action' class='m-0'>$hid<button class='btn btn-outline-secondary' name='plan_action' value='tasks_sync'><i class='ti ti-refresh me-1'></i>Sincronizar marcos com o projeto</button>"
                    . "<div class='form-hint'>Cria a tarefa de projeto dos marcos que ainda não têm. Marco e tarefa andam juntos: concluir um conclui o outro e o prazo muda nos dois.</div>" . Html::closeForm(false);
            }
        }
        echo "</div></div></div><div class='col-xl-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='ti ti-chart-gantt me-2'></i>Tarefas do projeto</h3></div>";
        $tasks = (int) $f['projects_id'] ? iterator_to_array($DB->request(['FROM' => 'glpi_projecttasks', 'WHERE' => ['projects_id' => (int) $f['projects_id']], 'ORDER' => 'plan_start_date', 'LIMIT' => 40]), false) : [];
        $linked = [];
        foreach (Registro::forEvento($ev->getID(), 'marco') as $m) {
            if ($tid = Projeto::taskId($m)) {
                $linked[$tid] = $m;
            }
        }
        $avg = $tasks ? round(array_sum(array_column($tasks, 'percent_done')) / count($tasks)) : 0;
        $lateTasks = count(array_filter($tasks, fn ($t) => (int) $t['percent_done'] < 100 && $t['plan_end_date'] && $t['plan_end_date'] < $_SESSION['glpi_currenttime']));
        if ($tasks) {
            echo "<div class='card-body border-bottom small'>Andamento do projeto: <b>$avg%</b> · " . count($tasks) . ' tarefa(s) · ' . ($lateTasks ? "<span class='text-danger fw-bold'>$lateTasks atrasada(s)</span>" : 'nenhuma atrasada') . ' · ' . count($linked) . ' ligada(s) a marcos do evento</div>';
        }
        echo "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Tarefa</th><th>Início</th><th>Fim</th><th>Marco</th><th class='text-end'>Feito</th></tr></thead><tbody>";
        foreach ($tasks as $t) {
            echo "<tr><td><a href='" . $e(ProjectTask::getFormURLWithID((int) $t['id'])) . "'>" . $e($t['name']) . "</a></td><td class='small'>" . Menu::date($t['plan_start_date']) . "</td><td class='small'>" . Menu::date($t['plan_end_date']) . "</td>"
                . "<td>" . (isset($linked[(int) $t['id']]) ? "<span class='badge bg-teal-lt' title='Ligada ao marco do evento'><i class='ti ti-link me-1'></i>marco</span>" : "<span class='text-secondary small'>—</span>") . "</td>"
                . "<td class='text-end'><div class='ge-dr-pct'><i style='width:" . (int) $t['percent_done'] . "%'></i><span>" . (int) $t['percent_done'] . "%</span></div></td></tr>";
        }
        echo $tasks ? '' : "<tr><td colspan='5' class='text-secondary small'>" . ((int) $f['projects_id'] ? 'O projeto não tem tarefas.' : 'Sem projeto vinculado.') . "</td></tr>";
        echo "</tbody></table></div></div></div></div>";

        echo "<div class='row g-3 mb-3'>" . Registro::block($ev, 'kickoff', $edit, $back, 'col-xl-6') . Registro::block($ev, 'marco', $edit, $back, 'col-xl-6') . "</div>";
        self::raci($ev, $edit, $back);
        echo "<div class='row g-3 mb-3'>" . Registro::block($ev, 'risco', $edit, $back, 'col-xl-6') . Registro::block($ev, 'mudanca', $edit, $back, 'col-xl-6') . "</div>";
        echo "<div class='row g-3'>" . Registro::block($ev, 'pendencia', $edit, $back, 'col-xl-6') . Registro::block($ev, 'checkpoint', $edit, $back, 'col-xl-6') . "</div></div>";
    }

    public const RACI_AREAS = ['MKT' => 'Marketing', 'PMO' => 'PMO', 'FIN' => 'Financeiro', 'COMP' => 'Compras', 'COM' => 'Comercial', 'DIR' => 'Diretoria'];

    /** Matriz de responsabilidade macro do documento (R, A, C, I): ponto de partida que o kickoff confirma. */
    public const RACI_DEFAULT = [
        'Briefing e classificação' => ['A/R', 'C', 'C', 'I', 'C', 'I'], 'Aprovação de entrada' => ['R', 'C', 'C', 'I', 'C', 'A'], 'Kickoff, cronograma e RACI' => ['R', 'A/R', 'C', 'C', 'C', 'I'],
        'Orçamento e centro de custo' => ['C', 'R', 'A/R', 'C', 'I', 'C'], 'Compra e contratação' => ['C', 'C', 'C', 'A/R', 'I', 'I'], 'Materiais e produção' => ['A/R', 'C', 'I', 'R', 'C', 'I'],
        'Prontidão e contingência' => ['A/R', 'A/R', 'C', 'C', 'C', 'I'], 'Execução' => ['A/R', 'R', 'I', 'C', 'C', 'I'], 'Resultados e leads' => ['A/R', 'C', 'I', 'I', 'R', 'I'],
        'Conciliação e fechamento' => ['R', 'A/R', 'A/R', 'C', 'C', 'I'], 'Exceções relevantes' => ['C', 'R', 'C', 'C', 'C', 'A'],
    ];

    private static function raci(Evento $ev, bool $edit, string $back): void
    {
        $e = [Menu::class, 'e'];
        $rows = Registro::forEvento($ev->getID(), 'raci');
        $action = $e(Evento::getFormURL());
        $hid = "<input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e($back) . "'>";
        $opts = ['' => '—', 'R' => 'R', 'A' => 'A', 'A/R' => 'A/R', 'C' => 'C', 'I' => 'I'];
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-grid-dots me-2'></i>Matriz RACI do evento</h3>"
            . "<div class='card-actions small text-secondary'>R executa · A responde · C consultado · I informado" . ($rows ? ' · ' . count($rows) . ' atividade(s)' : '') . "</div></div>";
        if (!$rows) {
            echo "<div class='card-body'><p class='text-secondary small'>A matriz macro é só uma sugestão: confirme-a no kickoff e ajuste o que divergir para este evento.</p>"
                . ($edit ? "<form method='post' action='$action' class='m-0'>$hid<button class='btn btn-outline-primary' name='plan_action' value='raci_seed'><i class='ti ti-wand me-1'></i>Carregar a matriz padrão</button>" . Html::closeForm(false) : '') . "</div></div>";
            return;
        }
        echo "<form method='post' action='$action' class='m-0'>$hid<div class='table-responsive'><table class='table table-sm card-table table-vcenter ge-raci'><thead><tr><th>Atividade</th>";
        foreach (self::RACI_AREAS as $a) {
            echo "<th class='text-center'>" . $e($a) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $r) {
            $x = json_decode((string) $r['extra'], true) ?: [];
            echo "<tr><td>" . $e($r['title']) . "</td>";
            foreach (array_keys(self::RACI_AREAS) as $a) {
                $v = (string) ($x[$a] ?? '');
                echo "<td class='text-center'>" . ($edit ? "<select class='form-select form-select-sm ge-raci-sel' name='raci[" . (int) $r['id'] . "][$a]'>" . Menu::options($opts, $v) . "</select>"
                    : "<span class='ge-raci-cell " . strtolower(str_replace('/', '', $v)) . "'>" . $e($v ?: '—') . "</span>") . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>" . ($edit ? "<div class='card-footer'><button class='btn btn-primary' name='plan_action' value='raci_save'><i class='ti ti-device-floppy me-1'></i>Salvar RACI (registra a revisão)</button></div>" : '') . Html::closeForm(false) . "</div>";
    }

    /** Ações da aba Planejamento (formulário do evento). */
    public static function planAction(Evento $ev, string $action, array $post): string
    {
        global $DB;
        $now = $_SESSION['glpi_currenttime'];
        switch ($action) {
            case 'project_create':
                if ((int) $ev->fields['projects_id']) {
                    throw new RuntimeException('O evento já tem projeto.');
                }
                $p = new Project();
                $pid = (int) $p->add([
                    'name' => $ev->fields['code'] . ' · ' . $ev->fields['name'], 'code' => $ev->fields['code'], 'entities_id' => (int) $ev->fields['entities_id'], 'is_recursive' => 1,
                    'users_id' => (int) ($ev->fields['users_id_gp'] ?: Session::getLoginUserID()), 'plan_start_date' => $ev->fields['date_request'] ?: $now,
                    'plan_end_date' => ($ev->fields['date_finish'] ?: $ev->fields['date_start'] ?: date('Y-m-d')) . ' 23:59:59', 'content' => (string) $ev->fields['objective'],
                ]);
                if (!$pid) {
                    throw new RuntimeException('O GLPI não criou o projeto (veja as permissões de Projetos do seu perfil).');
                }
                $ev->update(['id' => $ev->getID(), '_workflow' => true, 'projects_id' => $pid]);
                $ev->getFromDB($ev->getID());
                Historico::record($ev->getID(), 'planejamento', 'change', 'Projeto do GLPI criado e vinculado: #' . $pid);
                Fase::maybeAdvance($ev);
                return 'Projeto criado e vinculado ao evento.';

            case 'schedule_seed':
                $start = (string) $ev->fields['date_start'];
                if (!$start) {
                    throw new RuntimeException('Informe a data de início do evento no briefing.');
                }
                $model = [
                    [-60, 'Aprovação de entrada e orçamento base', 1], [-45, 'Contratações principais concluídas', 1], [-30, 'Peças de comunicação aprovadas', 1], [-21, 'Convites enviados', 0],
                    [-14, 'Logística e viagens fechadas', 1], [-7, 'Prontidão: aceite do GP e do Marketing', 1], [0, 'Início do evento', 1], [15, 'Conciliação financeira e relatório final', 1],
                ];
                $have = array_column(Registro::forEvento($ev->getID(), 'marco'), 'title');
                $n = 0;
                $project = (int) $ev->fields['projects_id'];
                foreach ($model as [$off, $title, $crit]) {
                    if (in_array($title, $have, true)) {
                        continue;
                    }
                    $due = date('Y-m-d', strtotime("$start $off days"));
                    $DB->insert(Registro::getTable(), ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'marco', 'title' => $title, 'date_due' => $due, 'is_critical' => $crit, 'status' => 'a_fazer',
                        'phase' => 'planejamento', 'users_id_owner' => (int) $ev->fields['users_id_gp'], 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now, 'date_mod' => $now]);
                    $n++;
                    if ($project) {
                        Projeto::ensureTask($ev, Registro::row((int) $DB->insertId()));
                    }
                }
                Historico::record($ev->getID(), 'planejamento', 'change', "Cronograma do modelo gerado: $n marco(s).");
                return $n ? "$n marco(s) criado(s)." : 'Os marcos do modelo já existem.';

            case 'raci_seed':
                if (Registro::countKind($ev->getID(), 'raci')) {
                    throw new RuntimeException('O evento já tem RACI.');
                }
                foreach (self::RACI_DEFAULT as $title => $cells) {
                    $DB->insert(Registro::getTable(), ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'raci', 'title' => $title, 'extra' => json_encode(array_combine(array_keys(self::RACI_AREAS), $cells)),
                        'phase' => 'planejamento', 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now, 'date_mod' => $now]);
                }
                Historico::record($ev->getID(), 'planejamento', 'change', 'RACI padrão carregada (versão 1). Confirme no kickoff.');
                Fase::maybeAdvance($ev);
                return 'Matriz RACI padrão carregada.';

            case 'tasks_sync':
                if (!(int) $ev->fields['projects_id']) {
                    throw new RuntimeException('O evento ainda não tem projeto.');
                }
                $n = Projeto::backfill($ev);
                return $n ? "$n marco(s) ligado(s) a tarefas do projeto." : 'Todos os marcos já estão ligados ao projeto.';

            case 'checklist_seed':
                return self::checklistSeed($ev);

            case 'raci_save':
                foreach ((array) ($post['raci'] ?? []) as $id => $cells) {
                    $row = Registro::row((int) $id, $ev->getID());
                    if (!$row || $row['kind'] !== 'raci') {
                        continue;
                    }
                    $clean = [];
                    foreach (array_keys(self::RACI_AREAS) as $a) {
                        $v = (string) ($cells[$a] ?? '');
                        $clean[$a] = in_array($v, ['R', 'A', 'A/R', 'C', 'I'], true) ? $v : '';
                    }
                    $DB->update(Registro::getTable(), ['extra' => json_encode($clean), 'date_mod' => $now], ['id' => (int) $id]);
                }
                $version = 1 + countElementsInTable(Historico::getTable(), ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'comment' => ['LIKE', 'RACI revisada%']]);
                Historico::record($ev->getID(), 'planejamento', 'change', 'RACI revisada (versão ' . ($version + 1) . ') por ' . getUserName((int) Session::getLoginUserID()) . '.');
                return 'RACI salva.';
        }
        throw new RuntimeException('Ação desconhecida.');
    }

    // ------------------------------------------------------------------ checklists

    /** Modelo de checklist por etapa: [título, crítico] */
    public const CHECKLIST_MODEL = [
        'briefing' => [['Briefing completo e validado pelo solicitante', 0], ['Antecedência mínima conferida', 0]],
        'avaliacao' => [['Viabilidade analisada', 0], ['Centro de custo validado pelo Financeiro', 1], ['Contrato ou confirmação de participação anexado', 1], ['Proposta de orçamento revisada', 0]],
        'planejamento' => [['GP designado', 0], ['Kickoff realizado e ata registrada', 1], ['RACI confirmado no kickoff', 1], ['Riscos iniciais registrados', 0], ['Orçamento base aprovado', 1], ['Pasta SharePoint criada (01 a 08)', 0]],
        'compras' => [['Duas ou mais cotações por item relevante (ou justificativa)', 0], ['Contratos revisados pelo Jurídico quando aplicável', 0], ['Aprovação da alçada antes de contratar', 1]],
        'producao' => [['Brindes e materiais aprovados e em produção', 1], ['Peças de comunicação aprovadas pelo Marketing', 1], ['Viagens e hospedagens reservadas', 0], ['Materiais enviados ao local', 0]],
        'prontidao' => [['Checklist final do local conferido', 1], ['Contatos e contingências distribuídos', 1], ['Lista de convidados confirmada', 0], ['Aceite do GP e do Marketing', 1]],
        'execucao' => [['Credenciamento e presença registrados', 0], ['Ocorrências registradas com evidência', 0]],
        'encerramento' => [['Conciliação financeira concluída', 1], ['NFs e NDs anexadas', 1], ['Resultados e leads registrados', 0], ['Lições aprendidas registradas', 0], ['Arquivos movidos para 08_Encerramento', 0]],
    ];

    private static function checklists(Evento $ev, bool $edit, string $back): void
    {
        $e = [Menu::class, 'e'];
        $action = $e(Evento::getFormURL());
        $hid = "<input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e($back) . "'>";
        $rows = Registro::forEvento($ev->getID(), 'checklist');
        $active = Fase::active($ev->getID());
        echo "<div class='ge ge-tab'>" . ($active ? self::banner($ev, $active['code']) : '');
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-progress-check me-2'></i>Andamento por etapa</h3>"
            . ($edit ? "<div class='card-actions'><form method='post' action='$action' class='m-0'>$hid<button class='btn btn-sm btn-outline-primary' name='plan_action' value='checklist_seed'><i class='ti ti-wand me-1'></i>Carregar o modelo de checklist</button>" . Html::closeForm(false) . "</div>" : '') . "</div><div class='card-body'><div class='row g-3'>";
        foreach (Fase::forEvento($ev->getID()) as $code => $p) {
            $list = array_filter($rows, fn ($r) => $r['phase'] === $code);
            $total = count(array_filter($list, fn ($r) => $r['status'] !== 'na'));
            $done = count(array_filter($list, fn ($r) => $r['status'] === 'feito'));
            $crit = count(array_filter($list, fn ($r) => $r['is_critical'] && $r['status'] === 'a_fazer'));
            $pct = $total ? round(100 * $done / $total) : 0;
            echo "<div class='col-md-6 col-xl-3'><div class='ge-chk-card" . ($crit ? ' bad' : '') . "'><div class='small fw-bold'>" . $e(Fase::name($code)) . "</div>"
                . "<div class='ge-dr-pct" . ($crit ? ' bad' : '') . " my-1'><i style='width:$pct%'></i><span>$pct%</span></div><div class='small text-secondary'>$done de $total feito(s)" . ($crit ? " · <b class='text-danger'>$crit crítico(s) pendente(s)</b>" : '') . "</div></div></div>";
        }
        echo "</div><div class='small text-secondary mt-2'>Itens <b>críticos</b> pendentes bloqueiam a conclusão das etapas de Produção e de Preparação final.</div></div></div>";
        echo "<div class='row g-3'>" . Registro::block($ev, 'checklist', $edit, $back) . "</div></div>";
    }

    public static function checklistSeed(Evento $ev): string
    {
        global $DB;
        $now = $_SESSION['glpi_currenttime'];
        $have = array_column(Registro::forEvento($ev->getID(), 'checklist'), 'title');
        $phases = Fase::forEvento($ev->getID());
        $n = 0;
        foreach (self::CHECKLIST_MODEL as $code => $items) {
            if (!isset($phases[$code])) {
                continue;
            }
            foreach ($items as [$title, $crit]) {
                if (in_array($title, $have, true)) {
                    continue;
                }
                $DB->insert(Registro::getTable(), ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'checklist', 'title' => $title, 'phase' => $code, 'is_critical' => $crit, 'status' => 'a_fazer',
                    'users_id_owner' => (int) $ev->fields['users_id_gp'], 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now, 'date_mod' => $now]);
                $n++;
            }
        }
        Historico::record($ev->getID(), null, 'change', "Modelo de checklist carregado: $n item(ns).");
        return $n ? "$n item(ns) de checklist criado(s)." : 'O modelo já está carregado.';
    }

    // ------------------------------------------------------------------ marketing

    private static function marketing(Evento $ev, bool $edit, string $back): void
    {
        $id = $ev->getID();
        $inv = Registro::countKind($id, 'convidado');
        $conf = Registro::countKind($id, 'convidado', ['status' => ['confirmado', 'presente']]);
        $pres = Registro::countKind($id, 'convidado', ['status' => 'presente']);
        $estimated = (int) $ev->fields['audience'];
        echo "<div class='ge ge-tab'>" . self::banner($ev, 'producao');
        echo "<div class='row g-3 mb-3'>" . Aprovacao::tile('Público estimado', (string) $estimated, '#3b82f6') . Aprovacao::tile('Convidados', (string) $inv, '#8b5cf6')
            . Aprovacao::tile('Confirmados', "$conf" . ($inv ? ' (' . round(100 * $conf / $inv) . '%)' : ''), '#22c55e') . Aprovacao::tile('Presentes', (string) $pres, '#14b8a6') . "</div>";
        echo self::galeriaBrindes($ev);
        echo "<div class='row g-3 mb-3'>" . Registro::block($ev, 'peca', $edit, $back, 'col-xl-6') . Registro::block($ev, 'brinde', $edit, $back, 'col-xl-6') . "</div>";
        echo "<div class='row g-3 mb-3'>" . Registro::block($ev, 'patrocinador', $edit, $back, 'col-xl-6') . Registro::block($ev, 'programacao', $edit, $back, 'col-xl-6') . "</div>";
        echo $edit ? Importacao::card($ev) : '';
        echo "<div class='row g-3'>" . Registro::block($ev, 'convidado', $edit, $back, 'col-xl-6') . Registro::block($ev, 'kpi', $edit, $back, 'col-xl-6') . "</div>";
        echo "<div class='small text-secondary mt-2'>Aprovar peças e brindes é do Marketing; o Comercial acompanha e contribui. Os patrocínios entram como receita no Financeiro (o realizado só conta quando o recebimento é confirmado).</div></div>";
    }

    /** Galeria de brindes com foto: cartões com miniatura, quantidade e situação; clicar abre a pré-visualização. */
    private static function galeriaBrindes(Evento $ev): string
    {
        $e = [Menu::class, 'e'];
        $rows = Registro::forEvento($ev->getID(), 'brinde');
        if (!$rows) {
            return '';
        }
        $opts = Registro::KINDS['brinde'][4]['status'][2];
        $h = "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-gift me-2'></i>Galeria de brindes</h3><div class='card-actions small text-secondary'>" . count($rows) . " item(ns) · clique na foto para ampliar</div></div>"
            . "<div class='card-body'><div class='ge-gallery'>";
        foreach ($rows as $r) {
            $h .= "<div class='ge-gal-card'><div class='ge-gal-img'>" . Registro::thumb($ev, $r) . "</div><div class='ge-gal-info'><b>" . $e($r['title']) . "</b>"
                . "<div class='small text-secondary'>" . ((float) $r['qty'] ? Menu::num((float) $r['qty'], 0) . ' ' . $e($r['unit'] ?: 'un.') : 'sem quantidade') . ((string) $r['supplier'] !== '' ? ' · ' . $e($r['supplier']) : '') . "</div>"
                . "<div class='mt-1'>" . Registro::chip((string) $r['status'], (string) ($opts[$r['status']] ?? $r['status'])) . "</div></div></div>";
        }
        return $h . "</div></div></div>";
    }

    // ------------------------------------------------------------------ compras

    private static function compras(Evento $ev, bool $edit, string $back): void
    {
        $id = $ev->getID();
        $f = $ev->fields;
        $okApproved = $f['approval_status'] === 'aprovado';
        echo "<div class='ge ge-tab'>" . self::banner($ev, 'compras');
        $gate = Gate::commitBlocks($ev, 0.01);
        echo $gate
            ? "<div class='alert alert-warning'><b><i class='ti ti-lock me-1'></i>Contratação bloqueada:</b><ul class='mb-0'>" . implode('', array_map(fn ($m) => '<li>' . Menu::e($m) . '</li>', $gate)) . "</ul></div>"
            : "<div class='alert alert-success py-2'><i class='ti ti-lock-open me-1'></i>Contratação liberada: evento aprovado, confirmação de participação, centro de custo e Budget aprovados.</div>";
        unset($okApproved);
        echo "<div class='row g-3 mb-3'>" . Registro::block($ev, 'compra', $edit, $back) . "</div>";
        echo "<div class='row g-3 mb-3'>" . Registro::block($ev, 'proposta', $edit, $back, 'col-xl-6') . Registro::block($ev, 'contrato', $edit, $back, 'col-xl-6') . "</div>";
        echo "<div class='row g-3'>" . Registro::block($ev, 'aditivo', $edit, $back, 'col-xl-6') . Registro::block($ev, 'recebimento', $edit, $back, 'col-xl-6') . "</div>";
        echo "<div class='row g-3 mt-0'>" . Registro::block($ev, 'nf', $edit, $back) . "</div>";
        echo "<div class='small text-secondary mt-2'>Fluxo da compra: requisição → cotação → negociação → <b>aprovação</b> → contrato → pedido → produção → recebimento → pagamento. "
            . "O bloqueio vale no servidor: uma requisição direta também é recusada e o motivo fica na linha do tempo. O aditivo só altera o valor contratado depois de aprovado.</div></div>";
    }

    // ------------------------------------------------------------------ logística

    private static function logistica(Evento $ev, bool $edit, string $back): void
    {
        $f = $ev->fields;
        echo "<div class='ge ge-tab'>" . self::banner($ev, 'prontidao');
        $mk = fn ($label, $date, $user) => "<div class='col-md-6'><div class='ge-kpi' style='--c:" . ($date ? '#22c55e' : '#f59e0b') . ";min-height:0'><div class='ge-kpi-label'>$label</div>"
            . "<div class='fw-bold'>" . ($date ? 'Dado por ' . Menu::e(getUserName((int) $user)) . ' em ' . Menu::date($date) : 'Pendente') . "</div></div></div>";
        echo "<div class='row g-3 mb-3'>" . $mk('Aceite de prontidão — GP', $f['ready_gp_date'], $f['ready_gp_users']) . $mk('Aceite de prontidão — Marketing', $f['ready_mkt_date'], $f['ready_mkt_users']) . "</div>";
        echo "<div class='row g-3 mb-3'>" . Registro::block($ev, 'material', $edit, $back, 'col-xl-6') . Registro::block($ev, 'reserva', $edit, $back, 'col-xl-6') . "</div>";
        echo "<div class='row g-3'>" . Registro::block($ev, 'contingencia', $edit, $back) . "</div>";
        echo "<div class='small text-secondary mt-2'>O aceite de prontidão é dado na etapa Preparação final (GP e Marketing), com os itens críticos do checklist resolvidos e o plano de contingência registrado. "
            . "Cada participante só acessa o que é dele; viagens simuladas ficam na aba Viagens.</div></div>";
    }

    // ------------------------------------------------------------------ execução

    private static function execucao(Evento $ev, bool $edit, string $back): void
    {
        $id = $ev->getID();
        $acts = Registro::forEvento($id, 'atividade');
        $planned = count($acts);
        $done = count(array_filter($acts, fn ($a) => $a['status'] === 'feito'));
        $late = count(array_filter($acts, fn ($a) => $a['date_due'] && $a['date_done'] && $a['date_done'] > $a['date_due']));
        $oc = Registro::forEvento($id, 'ocorrencia');
        $open = count(array_filter($oc, fn ($o) => $o['status'] === 'aberta'));
        $crit = count(array_filter($oc, fn ($o) => $o['status'] === 'aberta' && $o['category'] === 'critica'));
        $extra = array_sum(array_map(fn ($o) => (float) $o['amount_eac'], $oc));
        echo "<div class='ge ge-tab'>" . self::banner($ev, 'execucao');
        echo "<div class='row g-3 mb-3'>" . Aprovacao::tile('Atividades', "$done / $planned", '#3b82f6') . Aprovacao::tile('Fora do previsto', (string) $late, $late ? '#f59e0b' : '#22c55e')
            . Aprovacao::tile('Ocorrências abertas', "$open" . ($crit ? " ($crit crítica)" : ''), $crit ? '#ef4444' : ($open ? '#f59e0b' : '#22c55e')) . Aprovacao::tile('Custo adicional', Evento::canFinance() ? Menu::money($extra) : '—', '#a855f7') . "</div>";
        echo "<div class='row g-3'>" . Registro::block($ev, 'atividade', $edit, $back, 'col-xl-6') . Registro::block($ev, 'ocorrencia', $edit, $back, 'col-xl-6') . "</div>";
        echo "<div class='small text-secondary mt-2'>Ocorrência crítica gera alerta e fica ligada ao evento. Custo emergencial não vira despesa sozinho: o botão <i class='ti ti-coin'></i> pede a aprovação da alçada de exceção. "
            . "A presença efetiva é marcada na aba Marketing (convidados presentes).</div></div>";
    }
}
