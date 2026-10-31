<?php

namespace GlpiPlugin\Controleequipamentos;

/**
 * Painel dos ciclos (entrega e devolução), no padrão do Ciclo Financeiro: cartões que abrem o
 * resumo num painel lateral, gráficos do ECharts e uma tabela de apoio em cada gráfico.
 */
class Painel
{
    public const MESES = [3, 6, 12, 24];

    public static function show(string $classe, int $meses = 6): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $flow = $classe::fluxo();
        $ultimo = $flow::ULTIMA;
        $meses = in_array($meses, self::MESES, true) ? $meses : 6;
        $desde = date('Y-m-d H:i:s', strtotime("-$meses months"));
        $agora = date('Y-m-d H:i:s');
        $tituloTipo = $classe === Entrega::class ? 'entrega' : 'devolução';

        // ---------------------------------------------------------- dados
        $ciclos = [];
        foreach ($DB->request(['FROM' => $classe::tabela(), 'WHERE' => ['is_deleted' => 0] + (new \DbUtils())->getEntitiesRestrictCriteria($classe::tabela(), 'entities_id', '', true)]) as $row) {
            $ciclos[(int) $row['id']] = $row;
        }
        $abertos = array_filter($ciclos, fn ($c) => $c['status'] === Ciclo::ABERTA);
        $concluidos = array_filter($ciclos, fn ($c) => $c['status'] === Ciclo::CONCLUIDA && $c['date_mod'] >= $desde);
        $diasCiclo = array_map(fn ($c) => (strtotime($c['date_mod']) - strtotime($c['date_creation'])) / 86400, $concluidos);
        $cicloMedio = $diasCiclo ? array_sum($diasCiclo) / count($diasCiclo) : null;
        $meta = array_sum(array_map(fn ($s) => $flow::slo($s), range(2, $ultimo)));

        // visitas de todos os ciclos visíveis (sem a etapa 1, que nasce concluída)
        $visitas = [];
        if ($ciclos) {
            foreach ($DB->request(['FROM' => $classe::tabelaEtapas(), 'WHERE' => [
                $classe::colunaCiclo() => array_keys($ciclos),
                'step' => ['>', 1],
            ]]) as $v) {
                $visitas[] = $v;
            }
        }

        // por etapa: tempo médio das visitas concluídas no período, cumprimento do prazo e visitas abertas
        $etapas = [];
        for ($s = 2; $s <= $ultimo; $s++) {
            $etapas[$s] = ['nome' => $flow::nome($s), 'area' => $flow::AREAS[$flow::area($s)], 'slo' => $flow::slo($s), 'dias' => [], 'no_prazo' => 0,
                'abertas' => 0, 'atrasadas' => 0, 'lista' => [], 'lista_abertas' => []];
        }
        $atrasadasAgora = [];
        // cumprimento do prazo por área, grupo e responsável (só visitas concluídas com prazo)
        $sloArea = [];
        $sloGrupo = [];
        $sloUsuario = [];
        foreach ($visitas as $v) {
            $s = (int) $v['step'];
            if (!isset($etapas[$s]) || !isset($ciclos[(int) $v[$classe::colunaCiclo()]])) {
                continue;
            }
            $ciclo = $ciclos[(int) $v[$classe::colunaCiclo()]];
            $prazo = $classe::prazo($v, $agora);
            $item = [
                'code' => $classe::prefixo() . '-' . $ciclo['id'], 'name' => $ciclo['name'], 'url' => Menu::url($classe::pagina() . '?id=' . $ciclo['id']),
                'dias' => $prazo['dias'], 'slo' => $prazo['slo'], 'atrasada' => $prazo['atrasada'], 'fim' => $v['date'] ? substr((string) $v['date'], 0, 10) : '',
                'etapa' => $s, 'resultado' => $v['action'],
            ];
            if ($v['action'] === Ciclo::ABERTA && $ciclo['status'] === Ciclo::ABERTA) {
                $etapas[$s]['abertas']++;
                $etapas[$s]['lista_abertas'][] = $item;
                if ($prazo['atrasada']) {
                    $etapas[$s]['atrasadas']++;
                    $atrasadasAgora[] = $item;
                }
            } elseif (in_array($v['action'], [Ciclo::CONCLUIDA, 'devolvida'], true) && $v['date'] >= $desde) {
                $etapas[$s]['dias'][] = $prazo['dias'];
                $etapas[$s]['lista'][] = $item;
                if ($prazo['slo'] && $prazo['dias'] <= $prazo['slo']) {
                    $etapas[$s]['no_prazo']++;
                }
                if ($prazo['slo']) {
                    $ok = $prazo['dias'] <= $prazo['slo'] ? 1 : 0;
                    $area = $flow::AREAS[$flow::area($s)];
                    $grupo = (int) ($v['groups_id'] ?? 0);
                    $resp = (int) ($v['users_id_responsavel'] ?? 0);
                    self::acumular($sloArea, $area, $ok, $prazo['dias']);
                    self::acumular($sloGrupo, $grupo, $ok, $prazo['dias']);
                    self::acumular($sloUsuario, $resp, $ok, $prazo['dias']);
                }
            }
        }
        $comSlo = 0;
        $noSlo = 0;
        foreach ($etapas as $s => $et) {
            $n = count($et['dias']);
            $comSlo += $et['slo'] ? $n : 0;
            $noSlo += $et['slo'] ? $et['no_prazo'] : 0;
        }

        // por mês: concluídos e ciclo médio
        $meses_labels = [];
        $mesCount = [];
        $mesMedio = [];
        $mesLista = [];
        for ($i = $meses - 1; $i >= 0; $i--) {
            $chave = date('Y-m', strtotime("first day of -$i month"));
            $meses_labels[$chave] = date('m/Y', strtotime("$chave-01"));
            $mesCount[$chave] = 0;
            $mesMedio[$chave] = [];
            $mesLista[$chave] = [];
        }
        foreach ($concluidos as $c) {
            $chave = substr((string) $c['date_mod'], 0, 7);
            if (!isset($mesCount[$chave])) {
                continue;
            }
            $dias = (strtotime($c['date_mod']) - strtotime($c['date_creation'])) / 86400;
            $mesCount[$chave]++;
            $mesMedio[$chave][] = $dias;
            $mesLista[$chave][] = ['code' => $classe::prefixo() . '-' . $c['id'], 'name' => $c['name'], 'url' => Menu::url($classe::pagina() . '?id=' . $c['id']), 'dias' => $dias];
        }
        $mesAvg = [];
        foreach ($mesMedio as $chave => $dias) {
            $mesAvg[$chave] = $dias ? round(array_sum($dias) / count($dias), 1) : null;
        }

        // extras da devolução
        $extra = [];
        if ($classe === Devolucao::class) {
            $tipos = array_fill_keys(array_keys(Devolucao::TIPOS), 0);
            $valor = 0.0;
            $ids = [];
            foreach ($ciclos as $id => $c) {
                if ($c['status'] !== Ciclo::CANCELADA) {
                    $valor += (float) $c['total'];
                    $ids[] = $id;
                }
                if (isset($tipos[$c['tipo_servico']])) {
                    $tipos[$c['tipo_servico']]++;
                }
            }
            $itens = 0;
            if ($ids) {
                foreach ($DB->request(['SELECT' => ['quantidade'], 'FROM' => Devolucao::TABLE_ITENS, 'WHERE' => ['plugin_controleequipamentos_devolucoes_id' => $ids]]) as $r) {
                    $itens += (int) $r['quantidade'];
                }
            }
            $extra = ['tipos' => $tipos, 'valor' => $valor, 'itens' => $itens];
        }

        // ---------------------------------------------------------- cartões
        $kpi = fn ($key, $icon, $label, $valor, $sub, $cor, $drawer = true) => "<div class='col-6 col-md-4 col-xl-2'><button type='button' class='cf-kpi cf-kpi-btn' style='--c:$cor'"
            . ($drawer === 'ofensores' ? " data-ofensores='$key'" : ($drawer ? " data-drawer='$key'" : " tabindex='-1' aria-disabled='true'")) . "><div class='cf-kpi-top'><span class='cf-kpi-label'>" . $e($label) . "</span><i class='ti $icon'></i></div>"
            . "<div class='cf-kpi-value'>" . $e($valor) . "</div><div class='cf-kpi-sub'>" . $e($sub) . "</div>"
            . ($drawer ? "<div class='cf-kpi-more'>" . ($drawer === 'ofensores' ? 'ver ofensores' : 'ver resumo') . " <i class='ti ti-chevron-right'></i></div>" : '') . "</button></div>";

        echo "<div class='row g-3 mb-3'>";
        echo $kpi('andamento', 'ti-progress', 'Em andamento', count($abertos), count($atrasadasAgora) . ' com fase atrasada', '#3b82f6');
        echo $kpi('atrasos', 'ti-alarm', 'Fases atrasadas', count($atrasadasAgora), 'agora, além do prazo', '#ef4444');
        echo $kpi('concluidos', 'ti-circle-check', 'Concluídos', count($concluidos), "nos últimos $meses meses", '#22c55e');
        echo $kpi('ciclo', 'ti-clock-hour-4', 'Ciclo médio', $cicloMedio !== null ? number_format($cicloMedio, 1, ',', '.') . ' dias' : '—', "meta de $meta dias (soma dos prazos)", '#14b8a6');
        echo $kpi('prazo', 'ti-target-arrow', 'Etapas no prazo', $comSlo ? round(100 * $noSlo / $comSlo) . '%' : '—', $comSlo . ' etapa(s) com prazo concluída(s)', '#a855f7', 'ofensores');
        if ($classe === Devolucao::class) {
            echo $kpi('itens', 'ti-package', 'Itens em devoluções', $extra['itens'], 'em devoluções não canceladas', '#f59e0b', 'ofensores');
        } else {
            echo $kpi('desligados', 'ti-user-check', 'Entregas no período', count($concluidos) + count($abertos), 'iniciadas ou concluídas', '#f59e0b', 'ofensores');
        }
        echo "</div>";

        // ---------------------------------------------------------- esteira e gráficos
        $card = fn ($id, $icon, $titulo, $sub, $cls, $h) => "<div class='$cls'><div class='card h-100 cf-chart-card'><div class='card-header'><div><h3 class='card-title'><i class='ti $icon me-2'></i>" . $e($titulo) . "</h3><div class='small text-secondary'>" . $e($sub) . "</div></div></div>"
            . "<div class='card-body'><div class='cf-chart' id='$id' style='height:{$h}px' role='img' aria-label='" . $e($titulo) . "'></div></div></div></div>";

        echo "<div class='row g-3 mb-3'>";
        echo "<div class='col-xl-7'>" . self::esteira($flow, $etapas, $ultimo, count($abertos), count($atrasadasAgora)) . "</div>";
        echo "<div class='col-xl-5'>" . $card('cf-c-etapa', 'ti-hourglass', 'Tempo médio por etapa × prazo', "Dias das etapas concluídas nos últimos $meses meses. A faixa clara é o prazo; vermelho passou do prazo.", 'col-12', max(340, count($etapas) * 40 + 60)) . "</div>";
        echo "</div>";
        echo "<div class='row g-3 mb-3'>";
        echo $card('cf-c-slo', 'ti-target-arrow', 'Prazo cumprido por área', 'Percentual de etapas concluídas dentro do prazo, nos últimos ' . $meses . ' meses', 'col-12 col-xl-6', max(260, count($sloArea) * 48 + 80));
        echo "<div class='col-12 col-xl-6'>" . self::quadroSlo('Por grupo', $sloGrupo, 'grupo', $e) . self::quadroSlo('Por responsável', $sloUsuario, 'usuario', $e) . "</div>";
        echo "</div>";
        echo "<div class='row g-3 mb-3'>";
        echo $card('cf-c-mes', 'ti-chart-line', 'Ciclos concluídos por mês', "Quantidade e tempo médio do ciclo, contra a meta de $meta dias", 'col-12', 300);
        echo "</div>";

        if ($classe === Devolucao::class) {
            echo "<div class='row g-3 mb-3'>" . $card('cf-c-tipo', 'ti-truck-delivery', 'Devoluções por tipo de serviço', 'Quantidade de devoluções por tipo, todas as datas', 'col-12', 260) . "</div>";
        }

        // tabelas de apoio: o mesmo conteúdo dos gráficos, em texto
        echo "<details class='cf-tl-details'><summary>Ver os dados dos gráficos em tabela</summary><div class='table-responsive mt-2'><table class='ce-tabela'><caption>Etapas, nos últimos $meses meses</caption><thead><tr>"
            . "<th scope='col'>Etapa</th><th scope='col'>Área</th><th scope='col'>Prazo (dias)</th><th scope='col'>Média (dias)</th><th scope='col'>Concluídas</th><th scope='col'>Em andamento</th><th scope='col'>Atrasadas</th></tr></thead><tbody>";
        foreach ($etapas as $s => $et) {
            $media = $et['dias'] ? number_format(array_sum($et['dias']) / count($et['dias']), 1, ',', '.') : '—';
            echo "<tr><th scope='row'>$s. " . $e($et['nome']) . "</th><td>" . $e($et['area']) . "</td><td>" . ($et['slo'] ?: '—') . "</td><td>$media</td><td>" . count($et['dias']) . "</td><td>" . $et['abertas'] . "</td><td>" . $et['atrasadas'] . "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "<div class='table-responsive mt-3'><table class='ce-tabela'><caption>Concluídos por mês</caption><thead><tr><th scope='col'>Mês</th><th scope='col'>Concluídos</th><th scope='col'>Ciclo médio (dias)</th></tr></thead><tbody>";
        foreach ($meses_labels as $chave => $rotulo) {
            echo "<tr><th scope='row'>" . $e($rotulo) . "</th><td>" . $mesCount[$chave] . "</td><td>" . ($mesAvg[$chave] !== null ? number_format($mesAvg[$chave], 1, ',', '.') : '—') . "</td></tr>";
        }
        echo "</tbody></table></div></details>";

        // ---------------------------------------------------------- painel lateral (templates)
        $drawers = [
            'andamento' => ['Ciclos em andamento', 'ti-progress', '#3b82f6', self::lista(array_map(fn ($c) => self::resumoAberto($classe, $c, $etapas, $agora), $abertos), 'Nenhum ciclo em andamento.')],
            'atrasos' => ['Fases atrasadas agora', 'ti-alarm', '#ef4444', self::lista(array_map(fn ($a) => ['code' => $a['code'], 'name' => $a['name'], 'url' => $a['url'], 'val' => number_format($a['dias'], 1, ',', '.') . 'd', 'sub' => 'etapa ' . $a['etapa'] . ' · prazo ' . $a['slo'] . ' dia(s)', 'cls' => 'bad'], $atrasadasAgora), 'Nenhuma fase atrasada.')],
            'concluidos' => ['Concluídos no período', 'ti-circle-check', '#22c55e', self::lista(array_map(fn ($c) => ['code' => $classe::prefixo() . '-' . $c['id'], 'name' => $c['name'], 'url' => Menu::url($classe::pagina() . '?id=' . $c['id']), 'val' => number_format((strtotime($c['date_mod']) - strtotime($c['date_creation'])) / 86400, 1, ',', '.') . 'd', 'sub' => 'concluído em ' . substr((string) $c['date_mod'], 0, 10), 'cls' => 'good'], $concluidos), 'Nenhum ciclo concluído no período.')],
            'ciclo' => ['Tempo do ciclo', 'ti-clock-hour-4', '#14b8a6', "<p class='cf-dr-lead'>Meta de $meta dias, a soma dos prazos das etapas.</p>" . self::lista(array_map(fn ($c) => ['code' => $classe::prefixo() . '-' . $c['id'], 'name' => $c['name'], 'url' => Menu::url($classe::pagina() . '?id=' . $c['id']), 'val' => number_format((strtotime($c['date_mod']) - strtotime($c['date_creation'])) / 86400, 1, ',', '.') . 'd', 'sub' => ((strtotime($c['date_mod']) - strtotime($c['date_creation'])) / 86400 > $meta ? 'acima' : 'dentro') . ' da meta', 'cls' => ((strtotime($c['date_mod']) - strtotime($c['date_creation'])) / 86400 > $meta ? 'warn' : 'good')], $concluidos), 'Nenhum ciclo concluído no período.')],
        ];
        foreach ($etapas as $s => $et) {
            $todas = array_merge($et['lista'], $et['lista_abertas']);
            $drawers["etapa-$s"] = [$s . '. ' . $et['nome'], 'ti-hourglass', '#3b82f6', self::lista(array_map(fn ($i) => ['code' => $i['code'], 'name' => $i['name'], 'url' => $i['url'], 'val' => number_format($i['dias'], 1, ',', '.') . 'd', 'sub' => ($i['resultado'] === Ciclo::ABERTA ? 'em andamento' : ($i['resultado'] === 'devolvida' ? 'devolvida' : 'concluída')) . ($i['slo'] ? ' · prazo ' . $i['slo'] . 'd' : ''), 'cls' => $i['atrasada'] ? 'bad' : ($i['resultado'] === Ciclo::ABERTA ? '' : 'good')], $todas), 'Nenhuma passagem por esta etapa.')];
        }
        foreach ($meses_labels as $chave => $rotulo) {
            $drawers["mes-$chave"] = ["Concluídos em $rotulo", 'ti-calendar-check', '#14b8a6', self::lista(array_map(fn ($i) => ['code' => $i['code'], 'name' => $i['name'], 'url' => $i['url'], 'val' => number_format($i['dias'], 1, ',', '.') . 'd', 'sub' => $i['dias'] > $meta ? number_format($i['dias'] - $meta, 1, ',', '.') . ' dia(s) acima da meta' : 'dentro da meta', 'cls' => $i['dias'] > $meta ? 'warn' : 'good'], $mesLista[$chave]), 'Nenhum ciclo concluído neste mês.')];
        }
        foreach ($drawers as $key => [$titulo, $icone, $cor, $html]) {
            echo "<template id='cf-dr-$key' data-title='" . $e($titulo) . "' data-icon='$icone' data-color='$cor'>$html</template>";
        }
        echo "<div class='cf-drawer-backdrop' hidden></div><aside class='cf-drawer' aria-hidden='true' role='dialog' aria-labelledby='cf-drawer-title'>"
            . "<div class='cf-drawer-head'><span class='cf-drawer-icon'><i class='ti'></i></span><h3 id='cf-drawer-title'></h3><button type='button' class='btn-close' aria-label='Fechar'></button></div>"
            . "<div class='cf-drawer-body'></div></aside>";

        // ---------------------------------------------------------- dados para os gráficos
        $nomesGrupo = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_groups']) as $g) {
            $nomesGrupo[(int) $g['id']] = $g['name'];
        }
        $nomesUsuario = [];
        foreach ($DB->request(['SELECT' => ['id', 'firstname', 'realname', 'name'], 'FROM' => 'glpi_users']) as $u) {
            $nomesUsuario[(int) $u['id']] = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: $u['name'];
        }
        // ofensores do prazo: onde as etapas passam do prazo (concluídas fora do prazo + atrasadas agora)
        $ofensor = function (array $dadosSlo, ?array $nomes, string $vazio) {
            $saida = [];
            foreach ($dadosSlo as $chave => $v) {
                $fora = $v['n'] - $v['ok'];
                if ($fora > 0) {
                    $saida[] = ['nome' => $nomes === null ? (string) $chave : ($nomes[(int) $chave] ?? $vazio), 'fora' => $fora, 'n' => $v['n']];
                }
            }
            usort($saida, fn ($a, $b) => $b['fora'] <=> $a['fora']);
            return array_slice($saida, 0, 8);
        };
        $ofEtapas = [];
        foreach ($etapas as $s => $et) {
            $conc = $et['slo'] ? count($et['dias']) : 0;
            $fora = ($conc - ($et['slo'] ? $et['no_prazo'] : 0)) + $et['atrasadas'];
            if ($fora > 0) {
                $ofEtapas[] = ['nome' => $s . '. ' . $et['nome'], 'fora' => $fora, 'n' => $conc + $et['abertas']];
            }
        }
        usort($ofEtapas, fn ($a, $b) => $b['fora'] <=> $a['fora']);
        // ofensores do período: ciclos que levaram mais tempo (concluídos no período e em andamento)
        $ofCiclos = [];
        foreach (array_merge($concluidos, $abertos) as $c) {
            $fim = $c['status'] === Ciclo::ABERTA ? $agora : $c['date_mod'];
            $ofCiclos[] = ['nome' => $classe::prefixo() . '-' . str_pad((string) $c['id'], 5, '0', STR_PAD_LEFT) . ' · ' . $c['name'], 'dias' => round((strtotime($fim) - strtotime($c['date_creation'])) / 86400, 1),
                'aberto' => $c['status'] === Ciclo::ABERTA, 'url' => Menu::url($classe::pagina() . '?id=' . $c['id'])];
        }
        usort($ofCiclos, fn ($a, $b) => $b['dias'] <=> $a['dias']);
        $dados = [
            'ofensores' => [
                'prazo' => [
                    'etapas' => array_slice($ofEtapas, 0, 8),
                    'grupos' => $ofensor($sloGrupo, $nomesGrupo, 'Sem grupo'),
                    'usuarios' => $ofensor($sloUsuario, $nomesUsuario, 'Sem responsável'),
                ],
                'periodo' => ['ciclos' => array_slice($ofCiclos, 0, 10), 'meta' => $meta, 'total' => count($ofCiclos)],
            ],
            'slo' => array_values(array_map(fn ($a, $v) => ['nome' => $a, 'pct' => $v['n'] ? round(100 * $v['ok'] / $v['n']) : 0, 'n' => $v['n']], array_keys($sloArea), $sloArea)),
            'etapas' => array_values(array_map(fn ($s, $et) => [
                'step' => $s, 'nome' => $et['nome'], 'slo' => $et['slo'], 'media' => $et['dias'] ? round(array_sum($et['dias']) / count($et['dias']), 1) : null,
                'n' => count($et['dias']), 'atrasadas' => $et['atrasadas'],
            ], array_keys($etapas), $etapas)),
            'meses' => ['labels' => array_values($meses_labels), 'keys' => array_keys($meses_labels), 'count' => array_values($mesCount), 'avg' => array_values($mesAvg), 'meta' => $meta],
            'meta' => $meta,
            'tipos' => $classe === Devolucao::class ? array_values(array_map(fn ($k) => ['nome' => Devolucao::TIPOS[$k], 'n' => $extra['tipos'][$k]], array_keys(Devolucao::TIPOS))) : [],
        ];
        echo "<script type='application/json' id='cf-data'>" . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>";
        echo Menu::script('painel.js');
    }

    private static function acumular(array &$alvo, $chave, int $ok, float $dias): void
    {
        $alvo[$chave] ??= ['n' => 0, 'ok' => 0, 'dias' => 0.0];
        $alvo[$chave]['n']++;
        $alvo[$chave]['ok'] += $ok;
        $alvo[$chave]['dias'] += $dias;
    }

    /** Tabela de SLO por grupo ou por responsável: etapas, % no prazo e tempo médio. */
    private static function quadroSlo(string $titulo, array $dados, string $tipo, array $e): string
    {
        global $DB;
        $nomes = [];
        if ($tipo === 'grupo') {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_groups']) as $g) {
                $nomes[(int) $g['id']] = $g['name'];
            }
        } else {
            foreach ($DB->request(['SELECT' => ['id', 'firstname', 'realname', 'name'], 'FROM' => 'glpi_users']) as $u) {
                $nomes[(int) $u['id']] = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: $u['name'];
            }
        }
        uasort($dados, fn ($a, $b) => $b['n'] <=> $a['n']);
        $html = "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>" . Menu::e($titulo) . "</h3></div><div class='table-responsive'><table class='table table-vcenter card-table ce-tabela'>"
            . "<caption class='visually-hidden'>Prazo cumprido " . Menu::e(strtolower($titulo)) . "</caption><thead><tr><th scope='col'>" . ($tipo === 'grupo' ? 'Grupo' : 'Responsável') . "</th><th scope='col'>Etapas</th><th scope='col'>No prazo</th><th scope='col'>Média</th></tr></thead><tbody>";
        if (!$dados) {
            $html .= "<tr><td colspan='4' class='text-secondary'>Sem etapas com prazo concluídas no período.</td></tr>";
        }
        foreach ($dados as $chave => $v) {
            $pct = $v['n'] ? round(100 * $v['ok'] / $v['n']) : 0;
            $cor = $pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'danger');
            $nome = $chave ? ($nomes[(int) $chave] ?? '#' . $chave) : 'Não registrado';
            $html .= "<tr><th scope='row'>" . Menu::e($nome) . "</th><td>" . (int) $v['n'] . "</td>"
                . "<td><span class='badge bg-$cor-lt text-$cor'>$pct%</span></td><td>" . number_format($v['dias'] / max(1, $v['n']), 1, ',', '.') . " d</td></tr>";
        }
        return $html . "</tbody></table></div></div>";
    }

    /** Onde os ciclos estão: um bloco por etapa, com quantos ciclos estão nela e quantos estão atrasados. */
    private static function esteira(string $flow, array $etapas, int $ultimo, int $abertos, int $atrasados): string
    {
        $e = [Menu::class, 'e'];
        $html = "<div class='card h-100 cf-chart-card'><div class='card-header'><div><h3 class='card-title'><i class='ti ti-route me-2' aria-hidden='true'></i>Onde os ciclos estão agora</h3>"
            . "<div class='small text-secondary'>" . (int) $abertos . " em andamento" . ($atrasados ? ', ' . (int) $atrasados . ' com fase atrasada' : '') . "</div></div></div>";
        $html .= "<div class='card-body'><div class='ce-tiles'>";
        foreach ($etapas as $s => $et) {
            $qtd = (int) $et['abertas'];
            $tom = $et['atrasadas'] ? 'red' : ($qtd ? 'blue' : 'secondary');
            $html .= "<div class='ce-tile ce-tile-$tom'><div class='ce-tile-top'><span class='ce-tile-num'>$s</span><span class='ce-tile-qtd'>$qtd</span></div>"
                . "<div class='ce-tile-nome'>" . $e($et['nome']) . "</div><div class='ce-tile-area'>" . $e($et['area']) . "</div>"
                . ($et['atrasadas'] ? "<div class='ce-tile-late'>" . (int) $et['atrasadas'] . " atrasado(s)</div>" : '') . "</div>";
        }
        return $html . "</div></div></div>";
    }

    private static function resumoAberto(string $classe, array $c, array $etapas, string $agora): array
    {
        $flow = $classe::fluxo();
        $s = (int) $c['current_step'];
        $visita = $classe::etapaAberta((int) $c['id'], $s);
        $prazo = $visita ? $classe::prazo($visita, $agora) : ['dias' => 0, 'slo' => 0, 'atrasada' => false];
        return [
            'code' => $classe::prefixo() . '-' . $c['id'], 'name' => $c['name'], 'url' => Menu::url($classe::pagina() . '?id=' . $c['id']),
            'val' => number_format($prazo['dias'], 1, ',', '.') . 'd',
            'sub' => 'etapa ' . $s . ': ' . $flow::nome($s) . ($prazo['slo'] ? ' · prazo ' . $prazo['slo'] . ' dia(s)' : ''),
            'cls' => $prazo['atrasada'] ? 'bad' : 'good',
        ];
    }

    /** Lista de itens do painel lateral; cada item: code, name, url, val, sub, cls. */
    private static function lista(array $itens, string $vazio): string
    {
        $e = [Menu::class, 'e'];
        if (!$itens) {
            return "<div class='cf-dr-empty'>" . $e($vazio) . "</div>";
        }
        $html = "<div class='cf-dr-list'>";
        foreach ($itens as $i) {
            $html .= "<div class='cf-dr-item " . $e($i['cls'] ?? '') . "'><div class='cf-dr-row'><a href='" . $e($i['url']) . "'><b>" . $e($i['code']) . "</b></a>"
                . "<span class='cf-dr-name'>" . $e($i['name']) . "</span><span class='cf-dr-val'>" . $e($i['val'] ?? '') . "</span></div>"
                . (isset($i['sub']) ? "<div class='cf-dr-sub'>" . $e($i['sub']) . "</div>" : '') . "</div>";
        }
        return $html . "</div>";
    }
}
