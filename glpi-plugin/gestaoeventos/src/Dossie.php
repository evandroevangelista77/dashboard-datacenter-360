<?php

namespace GlpiPlugin\Gestaoeventos;

use Document;
use Session;
use Throwable;

/**
 * Dossiê executivo do evento em PDF (TCPDF do próprio GLPI): capa, sumário executivo, briefing, fluxo e SLO,
 * aprovações, planejamento, marketing com a foto dos brindes, compras, financeiro, viagens, logística,
 * execução, encerramento, documentos e a trilha de auditoria. Seções financeiras só saem para quem tem o
 * direito Financeiro. Ao concluir o evento o dossiê é gerado e anexado sozinho aos documentos.
 */
class Dossie
{
    private const TEAL = [20, 184, 166];
    private const INK = [15, 23, 42];

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** Texto puro de uma célula (sem as marcações da tela). */
    private static function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    /** Tabela HTML do TCPDF: cabeçalho colorido, linhas zebradas, colunas com peso e alinhamento. */
    private static function table(array $headers, array $rows, array $weights = [], array $right = []): string
    {
        if (!$rows) {
            return '';
        }
        $sum = array_sum($weights) ?: count($headers);
        $h = "<table border=\"0\" cellpadding=\"3\" cellspacing=\"0\" width=\"100%\"><thead><tr bgcolor=\"#0f766e\">";
        foreach ($headers as $i => $t) {
            $w = round(100 * ($weights[$i] ?? 1) / $sum, 1);
            $h .= "<th width=\"$w%\" align=\"" . (in_array($i, $right, true) ? 'right' : 'left') . "\"><font color=\"#ffffff\" size=\"7.5\"><b>" . self::e($t) . "</b></font></th>";
        }
        $h .= "</tr></thead><tbody>";
        foreach ($rows as $n => $r) {
            $h .= "<tr nobr=\"true\" bgcolor=\"" . ($n % 2 ? '#f1f5f9' : '#ffffff') . "\">";
            foreach ($r as $i => $v) {
                $h .= "<td align=\"" . (in_array($i, $right, true) ? 'right' : 'left') . "\"><font size=\"7.5\">" . nl2br(self::e($v)) . "</font></td>";
            }
            $h .= "</tr>";
        }
        return $h . "</tbody></table><br/>";
    }

    private static function title(string $t, string $sub = ''): string
    {
        return "<br/><table border=\"0\" cellpadding=\"2\" width=\"100%\"><tr><td style=\"border-bottom:1.5px solid #14b8a6\"><font size=\"12\" color=\"#0f766e\"><b>" . self::e($t) . "</b></font>"
            . ($sub !== '' ? " <font size=\"7.5\" color=\"#64748b\">" . self::e($sub) . "</font>" : '') . "</td></tr></table><br/>";
    }

    private static function sub(string $t): string
    {
        return "<font size=\"9\" color=\"#0f172a\"><b>" . self::e($t) . "</b></font><br/>";
    }

    private static function para(string $label, string $text): string
    {
        return trim($text) === '' ? '' : "<font size=\"8\" color=\"#64748b\"><b>" . self::e($label) . "</b></font><br/><font size=\"8.5\">" . nl2br(self::e($text)) . "</font><br/><br/>";
    }

    /** Tabela de um tipo de registro: as colunas da lista da aba, mais a observação quando existe. */
    private static function reg(Evento $ev, string $kind, array $skip = []): string
    {
        $rows = Registro::forEvento($ev->getID(), $kind);
        if (!$rows) {
            return '';
        }
        [$title, , , , $fields] = Registro::KINDS[$kind];
        $cols = array_filter($fields, fn ($d, $f) => $d[3] && $d[1] !== 'image' && !in_array($f, $skip, true), ARRAY_FILTER_USE_BOTH);
        $headers = array_map(fn ($d) => $d[0], $cols);
        $weights = [];
        $right = [];
        $i = 0;
        foreach ($cols as $f => $d) {
            $weights[] = in_array($f, ['title'], true) ? 2.2 : ($d[1] === 'money' ? 1.5 : (in_array($d[1], ['number', 'date', 'check'], true) ? 0.9 : 1.1));
            if (in_array($d[1], ['money', 'number'], true)) {
                $right[] = $i;
            }
            $i++;
        }
        $out = [];
        foreach ($rows as $r) {
            $line = [];
            foreach (array_keys($cols) as $f) {
                $v = self::plain(Registro::cell($ev, $kind, $f, $r));
                $line[] = $fields[$f][1] === 'check' ? ($r[$f] ? 'Sim' : 'Não') : $v;
            }
            $out[] = $line;
        }
        return self::sub($title . ' (' . count($rows) . ')') . self::table($headers, $out, $weights, $right);
    }

    // ------------------------------------------------------------------ montagem

    public static function build(Evento $ev): string
    {
        $f = $ev->fields;
        $id = $ev->getID();
        $fin = Evento::canFinance();
        $now = $_SESSION['glpi_currenttime'];
        $me = getUserName((int) Session::getLoginUserID());

        $pdf = new class ('P', 'mm', 'A4', true, 'UTF-8', false) extends \TCPDF {
            public string $left = '';
            public bool $cover = true;

            public function Header(): void
            {
            }

            public function Footer(): void
            {
                if ($this->cover || $this->getPage() === 1) {
                    return;
                }
                $this->SetY(-12);
                $this->SetFont('dejavusans', '', 7);
                $this->SetTextColor(100, 116, 139);
                $this->Cell(0, 6, $this->left, 0, 0, 'L');
                $this->SetX(15);
                $this->Cell(0, 6, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        };
        $pdf->left = $f['code'] . ' · ' . $f['name'] . ' · Dossiê executivo · confidencial';
        $pdf->SetCreator('GLPI · Gestão de Eventos');
        $pdf->SetAuthor($me);
        $pdf->SetTitle('Dossiê executivo ' . $f['code'] . ' - ' . $f['name']);
        $pdf->SetMargins(15, 14, 15);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->setImageScale(1.25);
        $pdf->setFontSubsetting(true);

        // ---------------------------------------------- capa
        $pdf->AddPage();
        $pdf->SetFillColor(...self::INK);
        $pdf->Rect(0, 0, 210, 120, 'F');
        $pdf->SetFillColor(...self::TEAL);
        $pdf->Rect(0, 120, 210, 3, 'F');
        $pdf->SetTextColor(148, 163, 184);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetXY(18, 30);
        $pdf->Cell(0, 6, 'DOSSIÊ EXECUTIVO DO EVENTO', 0, 1);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 26);
        $pdf->SetXY(18, 42);
        $pdf->MultiCell(174, 12, $f['name'], 0, 'L');
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->SetTextColor(94, 234, 212);
        $pdf->SetXY(18, $pdf->GetY() + 4);
        $pdf->Cell(0, 7, $f['code'] . ' · ' . (Evento::TYPES[$f['event_type']][0] ?? ''), 0, 1);
        $pdf->SetTextColor(203, 213, 225);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(18, $pdf->GetY() + 3);
        $pdf->MultiCell(174, 6, Menu::date($f['date_start']) . ($f['date_finish'] && $f['date_finish'] !== $f['date_start'] ? ' a ' . Menu::date($f['date_finish']) : '') . "\n" . trim($f['venue'] . ' · ' . $f['city'], ' ·'), 0, 'L');
        $pdf->SetTextColor(...self::INK);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetXY(18, 132);
        $info = [['Situação', Evento::STATUSES[(int) $f['status']][0] ?? ''], ['Aprovação', Evento::APPROVAL[$f['approval_status']][0] ?? ''], ['Etapa atual', $f['current_phase'] ? Fase::name((string) $f['current_phase']) : '—'],
            ['Dono do evento', getUserName((int) $f['users_id_owner'])], ['Gerente do projeto', getUserName((int) $f['users_id_gp'])], ['Solicitante', getUserName((int) $f['users_id_requester'])],
            ['Área demandante', (string) $f['requesting_area']], ['Fabricante / parceiro', (string) $f['partner']], ['Centro de custo', trim($f['cc_number'] . ' ' . $f['cc_description'])]];
        foreach ($info as [$l, $v]) {
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetX(18);
            $pdf->Cell(48, 7, mb_strtoupper($l), 0, 0);
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->SetTextColor(...self::INK);
            $pdf->Cell(0, 7, $v !== '' ? $v : '—', 0, 1);
        }
        $pdf->SetXY(18, 262);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->MultiCell(174, 4, 'Gerado em ' . Menu::date($now, true) . ' por ' . $me . ($fin ? '' : ' (sem dados financeiros: o perfil de quem gerou não tem o direito Financeiro)')
            . ".\nDocumento confidencial. Valores de simulação de viagem são estimativas não vinculantes.", 0, 'L');

        // ---------------------------------------------- sumário executivo
        $pdf->AddPage();
        $pdf->cover = false;
        $pdf->SetFont('dejavusans', '', 8.5);
        $s = $fin ? Financeiro::summary($id) : null;
        $risk = Evento::semaforo($ev);
        $guests = Registro::countKind($id, 'convidado');
        $kpi = fn (string $l, string $v, string $sub = '') => "<td width=\"33%\" bgcolor=\"#f1f5f9\"><font size=\"7\" color=\"#64748b\"><b>" . self::e(mb_strtoupper($l)) . "</b></font><br/><font size=\"12\" color=\"#0f172a\"><b>" . self::e($v) . "</b></font><br/><font size=\"7\" color=\"#64748b\">" . self::e($sub) . "</font></td>";
        $cards = [
            [$kpi('Dias no ciclo', $f['date_request'] ? Menu::days(Evento::cycleSeconds($f)) : '—', (int) $f['status'] === Evento::DONE ? 'ciclo concluído' : 'desde o envio do briefing'),
                $kpi('Devoluções', (string) (int) $f['returns'], 'retrabalho entre etapas'), $kpi('Semáforo', $risk ? 'Em risco' : 'Verde', $risk ? implode('; ', array_slice($risk, 0, 2)) : 'sem alertas')],
        ];
        if ($s) {
            $cards[] = [$kpi('Budget', Menu::money($s['limite']), (float) $f['budget_approved'] > 0 ? 'aprovado · v' . (int) $f['budget_version'] : 'ainda não aprovado'), $kpi('EAC', Menu::money($s['despesa']['eac']), 'desvio ' . ($s['desvio'] > 0 ? '+' : '') . Menu::money($s['desvio'])),
                $kpi('Realizado', Menu::money($s['despesa']['real']), 'saldo ' . Menu::money($s['saldo']))];
            $cards[] = [$kpi('Receita realizada', Menu::money($s['receita']['real']), 'previsto ' . Menu::money($s['receita']['budget'])), $kpi('Resultado (realizado)', Menu::money($s['resultado']['real']), 'receita − despesa'),
                $kpi('Custo por convidado', $guests ? Menu::money($s['despesa']['real'] / $guests) : '—', $guests . ' convidado(s)')];
        } else {
            $cards[] = [$kpi('Orçamento previsto', Menu::money((float) $f['budget_estimated']), 'briefing'), $kpi('Público estimado', (string) (int) $f['audience'], 'pessoas'), $kpi('Convidados', (string) $guests, Registro::countKind($id, 'convidado', ['status' => 'presente']) . ' presente(s)')];
        }
        $html = self::title('Sumário executivo');
        $html .= "<table border=\"0\" cellpadding=\"6\" cellspacing=\"4\" width=\"100%\">";
        foreach ($cards as $row) {
            $html .= '<tr>' . implode('', $row) . '</tr>';
        }
        $html .= "</table><br/>" . self::para('Objetivo', (string) $f['objective']) . self::para('Escopo', (string) $f['scope']);
        $res = Registro::forEvento($id, 'resultado');
        if ($res) {
            $html .= self::sub('Resultados dos objetivos') . self::table(['Objetivo', 'Resultado', 'Justificativa'], array_map(fn ($r) => [$r['title'], ['atingido' => 'Atingido', 'parcial' => 'Parcialmente atingido', 'nao_atingido' => 'Não atingido'][$r['status']] ?? '', $r['detail']], $res), [2, 1.4, 3]);
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        // ---------------------------------------------- briefing
        $labels = [['Tipo', Evento::TYPES[$f['event_type']][0] ?? ''], ['Formato', Evento::FORMATS[$f['format']] ?? ''], ['Público-alvo', $f['target_audience']], ['Público estimado', (string) (int) $f['audience']],
            ['Prioridade comercial', Evento::PRIORITIES[$f['commercial_priority']] ?? ''], ['Início', Menu::date($f['date_start'])], ['Término', Menu::date($f['date_finish'])], ['Prazo crítico', Menu::date($f['date_critical'])],
            ['Local', $f['venue'] . ($f['venue_defined'] ? ' (confirmado)' : ' (não confirmado)')], ['Endereço', $f['address']], ['Cidade', $f['city']],
            ['Estrutura necessária', $f['need_structure']], ['Coffee break / happy hour', $f['need_coffee']], ['Almoço / jantar com clientes', $f['need_meals']], ['Viagens', $f['need_travel']], ['Hospedagem', $f['need_lodging']],
            ['Transporte e logística', $f['need_transport']], ['Brindes', $f['need_gifts']], ['Audiovisual e tecnologia', $f['need_av']], ['Comunicação e materiais', $f['need_comm']], ['Fornecedores previstos', $f['need_suppliers']]];
        if ($fin || (int) $f['users_id_owner'] === (int) Session::getLoginUserID()) {
            $labels[] = ['Orçamento previsto', Menu::money((float) $f['budget_estimated'])];
            $labels[] = ['Patrocínio previsto', Menu::money((float) $f['sponsorship_estimated'])];
        }
        $labels = array_merge($labels, [['Contrato / confirmação de participação', $f['participation_url']], ['Pasta SharePoint', $f['sharepoint_url']], ['Observações', (string) $f['comment']]]);
        $lead = Evento::leadInfo($f);
        if ($lead) {
            $labels[] = ['Antecedência', $lead['days'] . ' dia(s) (mínimo ' . $lead['min'] . ')' . ($f['exception_status'] !== 'none' ? ' · exceção ' . $f['exception_status'] : '')];
        }
        $html = self::title('Briefing', 'dados de entrada do evento')
            . self::table(['Campo', 'Conteúdo'], array_map(fn ($r) => [$r[0], $r[1] !== '' ? $r[1] : '—'], $labels), [1.3, 4]);
        $pdf->writeHTML($html, true, false, true, false, '');

        // ---------------------------------------------- fluxo e SLO
        $phases = Fase::forEvento($id);
        $rows = [];
        foreach ($phases as $code => $p) {
            $late = Fase::isLate($p);
            $rows[] = [Fase::name($code), Fase::area($p), (int) $p['slo_days'] ? $p['slo_days'] . ' d' : '—', in_array($p['status'], ['active', 'done'], true) || (int) $p['seconds'] ? Menu::days(Fase::elapsed($p)) . ' d' : '—',
                Fase::STATUSES[$p['status']][0] . ($late ? ' · acima do SLO' : ''), $p['date_start'] ? Menu::date($p['date_start']) : '—', $p['date_end'] ? Menu::date($p['date_end']) : '—', (string) (int) $p['returns']];
        }
        $html = self::title('Fluxo do evento e SLO', 'tempo de cada etapa contra o prazo') . self::table(['Etapa', 'Área', 'SLO', 'Tempo', 'Situação', 'Início', 'Fim', 'Devol.'], $rows, [2.6, 1.8, 0.7, 0.8, 1.6, 1, 1, 0.6], [2, 3, 7]);
        $pdf->writeHTML($html, true, false, true, false, '');
        self::sloChart($pdf, $phases);
        $ret = array_filter(Historico::forEvento($id), fn ($h) => $h['action'] === 'return');
        if ($ret) {
            $pdf->writeHTML(self::sub('Devoluções (retrabalho)') . self::table(['Quando', 'Etapa', 'Quem', 'Motivo'], array_map(fn ($h) => [Menu::date($h['date'], true), Fase::name((string) $h['code']), getUserName((int) $h['users_id']), $h['comment']], $ret), [1.2, 1.6, 1.4, 4]), true, false, true, false, '');
        }

        // ---------------------------------------------- aprovações
        $ap = Aprovacao::forEvento($id);
        if ($ap) {
            $html = self::title('Aprovações e decisões', 'alçadas, quem decidiu, quando e por quê')
                . self::table(['Pedido', 'Alçada', 'Valor', 'Situação', 'Decisão', 'Justificativa'], array_map(fn ($a) => [(Aprovacao::KINDS[$a['kind']] ?? '') . ' — ' . $a['title'], $a['label'] . ' (etapa ' . $a['step'] . ')', (float) $a['amount'] > 0 ? Menu::money((float) $a['amount']) : '—',
                    Aprovacao::STATUSES[$a['status']][0] ?? '', $a['date_decision'] ? getUserName((int) $a['users_id_decided']) . ' · ' . Menu::date($a['date_decision'], true) : 'solicitada em ' . Menu::date($a['date_requested'], true), $a['justification']], $ap), [3, 1.6, 1.1, 1.1, 1.8, 2], [2]);
            $pdf->writeHTML($html, true, false, true, false, '');
        }

        // ---------------------------------------------- planejamento
        $html = self::title('Planejamento', 'projeto, cronograma, responsabilidades e riscos');
        if ((int) $f['projects_id']) {
            $html .= self::para('Projeto no GLPI', \Dropdown::getDropdownName('glpi_projects', (int) $f['projects_id']));
        }
        $html .= self::reg($ev, 'kickoff') . self::reg($ev, 'marco') . self::reg($ev, 'checkpoint');
        $raci = Registro::forEvento($id, 'raci');
        if ($raci) {
            $html .= self::sub('Matriz RACI') . self::table(array_merge(['Atividade'], array_values(Abas::RACI_AREAS)), array_map(function ($r) {
                $x = json_decode((string) $r['extra'], true) ?: [];
                return array_merge([$r['title']], array_map(fn ($a) => (string) ($x[$a] ?? ''), array_keys(Abas::RACI_AREAS)));
            }, $raci), [3, 1, 1, 1, 1, 1, 1]);
        }
        $html .= self::reg($ev, 'risco') . self::reg($ev, 'mudanca') . self::reg($ev, 'pendencia');
        $pdf->writeHTML($html, true, false, true, false, '');

        // ---------------------------------------------- marketing
        $html = self::title('Marketing, convidados e patrocínio') . self::reg($ev, 'peca') . self::reg($ev, 'programacao') . self::reg($ev, 'kpi');
        $pdf->writeHTML($html, true, false, true, false, '');
        self::brindes($pdf, $ev);
        $html = self::reg($ev, 'patrocinador', $fin ? [] : ['amount_budget', 'amount_committed', 'amount_real']);
        if ($guests) {
            $by = [];
            foreach (Registro::forEvento($id, 'convidado') as $g) {
                $by[$g['status']] = ($by[$g['status']] ?? 0) + 1;
            }
            $html .= self::sub('Convidados (' . $guests . ')') . self::table(['Situação', 'Quantidade'], array_map(fn ($k, $v) => [ucfirst($k), (string) $v], array_keys($by), $by), [3, 1], [1]);
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        // ---------------------------------------------- compras
        $html = self::title('Compras e contratações', 'requisições, propostas, contratos e aditivos');
        $html .= self::reg($ev, 'compra', $fin ? [] : []) . self::reg($ev, 'proposta') . self::reg($ev, 'contrato') . self::reg($ev, 'aditivo') . self::reg($ev, 'recebimento') . self::reg($ev, 'nf');
        $pdf->writeHTML($html, true, false, true, false, '');

        // ---------------------------------------------- financeiro e viagens
        if ($s) {
            $d = $s['despesa'];
            $html = self::title('Financeiro', 'Budget × EAC × Realizado')
                . self::table(['Indicador', 'Valor'], [['Budget' . ((float) $f['budget_approved'] > 0 ? ' aprovado (v' . (int) $f['budget_version'] . ')' : ' (soma das linhas)'), Menu::money($s['limite'])], ['EAC (custo final estimado)', Menu::money($d['eac'])],
                    ['Comprometido aberto', Menu::money($d['open'])], ['Realizado', Menu::money($d['real'])], ['Saldo disponível', Menu::money($s['saldo'])], ['Desvio EAC × Budget', ($s['desvio'] > 0 ? '+' : '') . Menu::money($s['desvio'])],
                    ['Receita (previsto / EAC / realizado)', Menu::money($s['receita']['budget']) . ' / ' . Menu::money($s['receita']['eac']) . ' / ' . Menu::money($s['receita']['real'])],
                    ['Resultado líquido (Budget / EAC / Realizado)', Menu::money($s['resultado']['budget']) . ' / ' . Menu::money($s['resultado']['eac']) . ' / ' . Menu::money($s['resultado']['real'])]], [3, 2], [1]);
            if ($s['categorias']) {
                $html .= self::sub('Despesas por categoria') . self::table(['Categoria', 'Budget', 'EAC', 'Comprometido', 'Realizado', 'Desvio'], array_map(fn ($k, $c) => [$k, Menu::money($c['budget']), Menu::money($c['eac']), Menu::money($c['open']), Menu::money($c['real']), Menu::money($c['eac'] - $c['budget'])], array_keys($s['categorias']), $s['categorias']), [2.4, 1.2, 1.2, 1.2, 1.2, 1.2], [1, 2, 3, 4, 5]);
            }
            $pdf->writeHTML($html, true, false, true, false, '');
            self::finChart($pdf, $s['categorias']);
            $pdf->writeHTML(self::reg($ev, 'lancamento', ['users_id_owner', 'users_id_approver']), true, false, true, false, '');
            $trav = Viajante::forEvento($id);
            if ($trav) {
                $pdf->writeHTML(self::title('Viagens simuladas', 'estimativa preliminar, não vinculante') . self::table(['Viajante', 'Modo', 'Km (um sentido)', 'Parâmetros', 'Origem do valor', 'Total estimado'],
                    array_map(fn ($v) => [$v['name'], Viajante::MODES[$v['mode']] ?? '', Menu::num((float) $v['km_oneway']), 'v' . (int) $v['params_version'], ((json_decode((string) $v['calc'], true) ?: [])['source'] ?? '') === 'uber' ? 'Cotação Uber' : 'Estimativa interna', Menu::money((float) $v['total']) . ($v['total_pending'] ? ' + pendente' : '')], $trav),
                    [2, 1.6, 1, 1, 1.6, 1.4], [2, 5]) . "<font size=\"8\">Total estimado: <b>" . Menu::money(array_sum(array_column($trav, 'total'))) . "</b></font>", true, false, true, false, '');
            }
        } else {
            $pdf->writeHTML(self::title('Financeiro') . "<font size=\"8.5\" color=\"#64748b\">Seção restrita ao perfil Financeiro. Gere o dossiê com um usuário que tenha o direito Financeiro para incluir Budget, EAC, Realizado e viagens.</font>", true, false, true, false, '');
        }

        // ---------------------------------------------- logística, execução e encerramento
        $html = self::title('Logística e prontidão') . "<font size=\"8.5\">Aceite do GP: <b>" . ($f['ready_gp_date'] ? self::e(getUserName((int) $f['ready_gp_users']) . ' em ' . Menu::date($f['ready_gp_date'])) : 'pendente')
            . "</b> · Aceite do Marketing: <b>" . ($f['ready_mkt_date'] ? self::e(getUserName((int) $f['ready_mkt_users']) . ' em ' . Menu::date($f['ready_mkt_date'])) : 'pendente') . "</b></font><br/><br/>"
            . self::reg($ev, 'material') . self::reg($ev, 'reserva') . self::reg($ev, 'contingencia') . self::reg($ev, 'checklist');
        $pdf->writeHTML($html, true, false, true, false, '');
        $html = self::title('Execução') . self::reg($ev, 'atividade') . self::reg($ev, 'ocorrencia', $fin ? [] : ['amount_eac']);
        $pdf->writeHTML($html, true, false, true, false, '');
        $html = self::title('Encerramento', 'Definition of Done, leads e aprendizados')
            . self::table(['Item', 'Situação', 'Detalhe'], array_map(fn ($x) => [$x[0], $x[1] ? 'Cumprido' : 'Pendente', $x[2]], Encerramento::dod($ev)), [3, 1, 3])
            . self::reg($ev, 'lead') . self::reg($ev, 'licao') . self::reg($ev, 'satisfacao');
        $pdf->writeHTML($html, true, false, true, false, '');

        // ---------------------------------------------- documentos e auditoria
        $docs = Documentos::rows($id);
        $html = self::title('Documentos anexados', count($docs) . ' arquivo(s)')
            . self::table(['Arquivo', 'Etapa', 'Enviado por', 'Data'], array_map(fn ($d) => [$d['filename'], $d['code'] ? Fase::name((string) $d['code']) : '—', getUserName((int) $d['users_id']), Menu::date($d['date_creation'], true)], $docs), [3, 1.6, 1.4, 1.2]);
        $log = array_slice(Historico::forEvento($id), 0, 80);
        $html .= self::title('Trilha de auditoria', 'últimos ' . count($log) . ' registros, do mais recente para o mais antigo')
            . self::table(['Quando', 'Quem', 'Ação', 'Etapa', 'Detalhe'], array_map(fn ($x) => [Menu::date($x['date'], true), $x['users_id'] ? getUserName((int) $x['users_id']) : 'sistema', Historico::ACTIONS[$x['action']][0] ?? $x['action'], $x['code'] ? Fase::name((string) $x['code']) : '—', $x['comment']], $log), [1.2, 1.2, 1.2, 1.3, 4]);
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ gráficos e fotos

    private static function need(\TCPDF $pdf, float $h): void
    {
        if ($pdf->GetY() + $h > 275) {
            $pdf->AddPage();
        }
    }

    /** Barras: tempo de cada etapa contra o SLO (verde no prazo, vermelho acima, faixa cinza é o SLO). */
    private static function sloChart(\TCPDF $pdf, array $phases): void
    {
        $max = 1.0;
        foreach ($phases as $p) {
            $max = max($max, Fase::elapsed($p) / 86400, (float) $p['slo_days']);
        }
        self::need($pdf, 8 + count($phases) * 7);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor(...self::INK);
        $pdf->Cell(0, 5, 'Tempo por etapa × SLO (dias)', 0, 1);
        $x0 = 62;
        $w = 118;
        foreach ($phases as $code => $p) {
            $y = $pdf->GetY();
            $days = Fase::elapsed($p) / 86400;
            $slo = (float) $p['slo_days'];
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetTextColor(...self::INK);
            $pdf->SetXY(15, $y);
            $pdf->Cell(46, 5.5, mb_strimwidth(Fase::name($code), 0, 30, '…'), 0, 0);
            if ($slo > 0) {
                $pdf->SetFillColor(226, 232, 240);
                $pdf->Rect($x0, $y + 0.8, $w * $slo / $max, 3.8, 'F');
            }
            if ($days > 0) {
                $over = $slo > 0 && $days > $slo;
                $pdf->SetFillColor(...($over ? [239, 68, 68] : [34, 197, 94]));
                $pdf->Rect($x0, $y + 1.4, $w * $days / $max, 2.6, 'F');
            }
            $pdf->SetXY($x0 + $w + 1, $y);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell(0, 5.5, ($days > 0 ? str_replace('.', ',', (string) round($days, 1)) : '—') . ($slo > 0 ? ' / ' . (int) $slo : ''), 0, 1);
        }
        $pdf->Ln(3);
    }

    /** Barras por categoria: Budget (azul), EAC (âmbar) e Realizado (verde). */
    private static function finChart(\TCPDF $pdf, array $cats): void
    {
        if (!$cats) {
            return;
        }
        $max = 1.0;
        foreach ($cats as $c) {
            $max = max($max, $c['budget'], $c['eac'], $c['real']);
        }
        self::need($pdf, 12 + count($cats) * 11);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor(...self::INK);
        $pdf->Cell(0, 5, 'Budget (azul) × EAC (âmbar) × Realizado (verde) por categoria', 0, 1);
        $x0 = 62;
        $w = 118;
        foreach ($cats as $name => $c) {
            $y = $pdf->GetY();
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetTextColor(...self::INK);
            $pdf->SetXY(15, $y);
            $pdf->Cell(46, 9, mb_strimwidth((string) $name, 0, 30, '…'), 0, 0);
            foreach ([['budget', [59, 130, 246]], ['eac', $c['eac'] > $c['budget'] + 0.005 ? [239, 68, 68] : [245, 158, 11]], ['real', [34, 197, 94]]] as $i => [$k, $rgb]) {
                $pdf->SetFillColor(...$rgb);
                if ($c[$k] > 0) {
                    $pdf->Rect($x0, $y + 0.6 + $i * 2.7, $w * $c[$k] / $max, 2.2, 'F');
                }
            }
            $pdf->SetXY($x0 + $w + 1, $y);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell(0, 9, Menu::num($c['eac'] / 1000, 1) . ' mil', 0, 1);
        }
        $pdf->Ln(3);
    }

    /** Brindes com a foto (miniatura em JPEG para o PDF não pesar). */
    private static function brindes(\TCPDF $pdf, Evento $ev): void
    {
        $rows = Registro::forEvento($ev->getID(), 'brinde');
        if (!$rows) {
            return;
        }
        $opts = Registro::KINDS['brinde'][4]['status'][2];
        $cells = [];
        foreach ($rows as $r) {
            $img = '';
            $doc = (int) $r['doc_ref'] ? Registro::documentFile((int) $r['doc_ref']) : null;
            if ($doc && function_exists('imagecreatefromstring')) {
                $im = @imagecreatefromstring((string) file_get_contents($doc));
                if ($im) {
                    $sm = imagescale($im, 260);
                    ob_start();
                    imagejpeg($sm ?: $im, null, 82);
                    $img = "<img src=\"@" . base64_encode((string) ob_get_clean()) . "\" width=\"92\" height=\"92\"/><br/>";
                    imagedestroy($im);
                }
            }
            $cells[] = "<td width=\"25%\" align=\"center\" bgcolor=\"#f8fafc\">$img<font size=\"7.5\"><b>" . self::e($r['title']) . "</b></font><br/><font size=\"7\" color=\"#64748b\">"
                . ((float) $r['qty'] ? self::e(Menu::num((float) $r['qty'], 0)) . ' ' . self::e($r['unit'] ?: 'un.') . ' · ' : '') . self::e($opts[$r['status']] ?? $r['status'])
                . ($r['supplier'] ? '<br/>' . self::e($r['supplier']) : '') . (Evento::canFinance() && (float) $r['amount_budget'] ? '<br/>' . self::e(Menu::money((float) $r['amount_budget'])) : '') . "</font></td>";
        }
        $html = self::sub('Brindes e materiais (' . count($rows) . ')') . "<table border=\"0\" cellpadding=\"5\" cellspacing=\"4\" width=\"100%\">";
        foreach (array_chunk($cells, 4) as $chunk) {
            $html .= "<tr nobr=\"true\">" . implode('', $chunk) . str_repeat('<td width="25%"></td>', 4 - count($chunk)) . "</tr>";
        }
        $pdf->writeHTML($html . "</table><br/>", true, false, true, false, '');
    }

    // ------------------------------------------------------------------ anexar ao evento

    /** Gera o dossiê e o anexa ao evento (etapa Encerramento). Devolve o id do documento (0 se falhar). */
    public static function attach(Evento $ev): int
    {
        global $DB;
        try {
            $pdf = self::build($ev);
            $name = 'Dossie-' . $ev->fields['code'] . '-' . date('Ymd-His') . '.pdf';
            $prefix = 'ge' . bin2hex(random_bytes(6)) . '_';
            file_put_contents(GLPI_TMP_DIR . '/' . $prefix . $name, $pdf);
            $doc = new Document();
            $id = (int) $doc->add([
                'name' => 'Dossiê executivo ' . $ev->fields['code'] . ' (' . date('d/m/Y H:i') . ')', 'comment' => 'Gerado automaticamente.', 'documentcategories_id' => 0,
                '_filename' => [$prefix . $name], '_prefix_filename' => [$prefix], 'itemtype' => Evento::class, 'items_id' => $ev->getID(), 'entities_id' => (int) $ev->fields['entities_id'], 'is_recursive' => 1,
            ]);
            @unlink(GLPI_TMP_DIR . '/' . $prefix . $name);
            if ($id) {
                $DB->insert(Documentos::TABLE, ['documents_id' => $id, 'plugin_gestaoeventos_eventos_id' => $ev->getID(), 'code' => 'encerramento']);
                Historico::record($ev->getID(), 'encerramento', 'comment', 'Dossiê executivo (PDF) gerado e anexado aos documentos.');
            }
            return $id;
        } catch (Throwable $e) {
            trigger_error('Gestão de Eventos: dossiê não gerado: ' . $e->getMessage(), E_USER_WARNING);
            return 0;
        }
    }
}
