<?php

namespace GlpiPlugin\Gestaoeventos;

use Config;

/**
 * Assistente do evento: "o que fazer agora". Lê o estado do evento (etapa atual, exigências que faltam,
 * aprovações, prazos, financeiro, convidados, marcos) e devolve dicas ordenadas por urgência, cada uma com
 * quem deve agir e a aba onde resolver. Aparece no alto do evento e como faixa em cada aba. Só mostra o que o
 * perfil do usuário enxerga (módulo "Dicas" e as abas a que ele tem acesso).
 */
class Dicas
{
    public const SEV = [
        'block' => ['Impede concluir a etapa', '#ef4444', 'ti-lock'],
        'late'  => ['Urgente', '#f97316', 'ti-alarm'],
        'warn'  => ['Atenção', '#f59e0b', 'ti-alert-triangle'],
        'todo'  => ['Sugestão', '#3b82f6', 'ti-bulb'],
        'ok'    => ['Pronto', '#22c55e', 'ti-circle-check'],
    ];

    /** palavra na mensagem de pendência → aba onde resolver */
    private const ONDE = [
        '/aprova(ç|c)ão|alçada/iu' => 'aprovacoes', '/or(ç|c)amento base|budget|lan(ç|c)amento|conciliação|fiscal|documento fiscal/iu' => 'financeiro', '/projeto|kickoff|marco|RACI/u' => 'planejamento',
        '/checklist/iu' => 'checklists', '/peça|brinde|patroc|convidad|lead|lições?|resultado|objetivo|satisfa/iu' => 'marketing', '/compra|contrato|cotação|requisição/iu' => 'compras',
        '/entrega|material|diverg|contingência|prontidão|reserva/iu' => 'logistica', '/ocorr(ê|e)ncia|emergencial/iu' => 'execucao', '/pasta sharepoint|aceite formal|Definition|logística/iu' => 'encerramento',
    ];

    public static function enabled(): bool
    {
        return (Config::getConfigurationValues('plugin:gestaoeventos', ['dicas_on'])['dicas_on'] ?? '1') === '1' && Acesso::canView('dicas');
    }

    private static function where(string $msg): ?string
    {
        foreach (self::ONDE as $re => $tab) {
            if (preg_match($re, $msg)) {
                return $tab;
            }
        }
        return null;
    }

    /** Dicas do evento, da mais urgente para a menos. Cada uma: [sev, texto, aba|null, quem]. */
    public static function tips(Evento $ev): array
    {
        $f = $ev->fields;
        $id = $ev->getID();
        $now = strtotime($_SESSION['glpi_currenttime'] ?? 'now');
        $out = [];
        $add = function (string $sev, string $text, ?string $tab = null, string $who = '') use (&$out) {
            $out[] = ['sev' => $sev, 'text' => $text, 'tab' => $tab, 'who' => $who];
        };
        $n = fn (string $kind, array $w = []) => Registro::countKind($id, $kind, $w);

        if ((int) $f['status'] === Evento::DONE) {
            $add('ok', 'Evento concluído. O dossiê executivo foi gerado nos documentos; baixe a versão atual pelo botão "Dossiê executivo (PDF)".', 'encerramento');
            return $out;
        }
        if ((int) $f['status'] === Evento::CANCELED) {
            $add('todo', 'Evento cancelado. Confira o impacto financeiro na aba Financeiro e registre as lições aprendidas.', 'encerramento');
            return $out;
        }
        if ($f['is_draft']) {
            if ($miss = Evento::briefingMissing($f)) {
                $add('block', $miss[0], null, 'Solicitante');
            } else {
                $add('ok', 'Briefing completo. Envie à triagem do Marketing para o fluxo começar.', null, 'Solicitante');
            }
            if ($lead = Evento::leadInfo($f, date('Y-m-d', $now))) {
                if ($lead['short']) {
                    $add('warn', "Antecedência de {$lead['days']} dia(s), abaixo do mínimo de {$lead['min']}: " . ($lead['mode'] === 'block' ? 'ajuste a data para enviar.' : 'informe a justificativa da exceção ao enviar.'), null, 'Solicitante');
                }
            }
            if (trim((string) $f['participation_url']) === '') {
                $add('todo', 'Já tem o contrato ou a confirmação de participação? Informe o link no briefing: sem ele as compras ficam bloqueadas.', null, 'Solicitante');
            }
            return self::sort($out);
        }

        $p = Fase::active($id);
        if ($p) {
            $code = $p['code'];
            $name = Fase::name($code);
            $area = Fase::area($p);
            $missing = Fase::missing($ev, $p);
            foreach ($missing as $m) {
                $add('block', $m, self::where($m), $area);
            }
            if (!$missing) {
                $add('ok', "Tudo o que a etapa \"$name\" exige está pronto: conclua a etapa para o evento seguir.", null, $area);
            }
            if (Fase::isLate($p)) {
                $add('late', "A etapa \"$name\" passou do SLO de {$p['slo_days']} dia(s) (está há " . Menu::days(Fase::elapsed($p)) . ' dias). Resolva as pendências ou devolva com o motivo.', null, $area);
            } elseif (Fase::isWarn($p)) {
                $add('warn', "A etapa \"$name\" já usou {$p['warn_pct']}% do SLO (" . Menu::days(Fase::elapsed($p)) . ' de ' . $p['slo_days'] . ' dias).', null, $area);
            }
            self::phaseTips($ev, $code, $add, $n);
        }

        // aprovações
        global $DB;
        foreach ($DB->request(['FROM' => Aprovacao::getTable(), 'WHERE' => ['plugin_gestaoeventos_eventos_id' => $id, 'status' => 'pending']]) as $a) {
            $over = Aprovacao::isOverdue($a);
            $add($over ? 'late' : 'warn', (Aprovacao::KINDS[$a['kind']] ?? 'Aprovação') . ' aguardando a alçada "' . $a['label'] . '"' . ($a['date_due'] ? ($over ? ' — venceu em ' : ' — prazo ') . Menu::date($a['date_due']) : '') . '.', 'aprovacoes', (string) $a['label']);
        }
        // financeiro
        if (Evento::canFinance() && (float) $f['budget_approved'] > 0) {
            $s = Financeiro::summary($id);
            $tol = (float) (Config::getConfigurationValues('plugin:gestaoeventos', ['tolerance_pct'])['tolerance_pct'] ?? 5);
            if ($s['saldo'] < 0) {
                $add('late', 'Saldo do orçamento negativo (' . Menu::money($s['saldo']) . '): novas compras serão bloqueadas até aprovar uma revisão.', 'financeiro', 'Financeiro');
            } elseif ($s['despesa']['eac'] > (float) $f['budget_approved'] * (1 + $tol / 100)) {
                $add('warn', 'O EAC (' . Menu::money($s['despesa']['eac']) . ') passou do Budget aprovado além da tolerância de ' . Menu::num($tol, 0) . '%.', 'financeiro', 'Financeiro');
            } elseif ($s['limite'] > 0 && ($s['despesa']['real'] + $s['despesa']['open']) / $s['limite'] >= 0.9) {
                $add('warn', 'Já foram usados 90% ou mais do Budget (realizado + comprometido).', 'financeiro', 'Financeiro');
            }
        }
        // data do evento
        if ($f['date_start']) {
            $days = (int) floor((strtotime($f['date_start']) - $now) / 86400);
            if ($days >= 0 && $days <= 14 && $p && in_array($p['code'], ['briefing', 'avaliacao', 'planejamento', 'compras'], true)) {
                $add('late', "O evento começa em $days dia(s) e ainda está em \"" . Fase::name($p['code']) . '": priorize o que impede avançar.', null, 'GP');
            }
            if ($days >= 0 && $days <= 30 && (int) $f['audience'] > 0 && $n('convidado', ['status' => ['confirmado', 'presente']]) < 0.6 * (int) $f['audience']) {
                $add('todo', 'Faltam menos de 30 dias e os confirmados estão abaixo de 60% do público estimado. Reforce os convites.', 'marketing', 'Marketing');
            }
        }
        if ($n('marco', ['is_critical' => 1, 'status' => ['a_fazer', 'andamento', 'bloqueado'], 'date_due' => ['<', date('Y-m-d', $now)]])) {
            $add('late', 'Há marco crítico vencido no cronograma.', 'planejamento', 'GP');
        }
        if ($n('risco', ['category' => 'alta', 'status' => ['aberto', 'mitigando']])) {
            $add('warn', 'Há risco de probabilidade alta ainda aberto: defina o tratamento e o dono.', 'planejamento', 'GP');
        }
        if ((float) $f['sponsorship_estimated'] > 0 && !$n('patrocinador')) {
            $add('todo', 'O briefing prevê patrocínio, mas nenhum patrocinador foi cadastrado: cadastre para a receita entrar no financeiro.', 'marketing', 'Marketing');
        }
        if (count(array_filter(Registro::forEvento($id, 'brinde'), fn ($r) => !(int) $r['doc_ref']))) {
            $add('todo', 'Há brindes sem foto. Anexe a foto para a aprovação do Marketing e o dossiê.', 'marketing', 'Marketing');
        }
        if (Evento::canFinance()) {
            $unlinked = count(array_filter(Viajante::forEvento($id), fn ($v) => (float) $v['total'] > 0 && !(int) $v['linked_registros_id']));
            if ($unlinked) {
                $add('todo', "$unlinked simulação(ões) de viagem ainda não foi vinculada ao EAC.", 'viagens', 'Financeiro');
            }
        }
        return self::sort($out);
    }

    /** Sugestões que dependem da etapa atual (o que costuma faltar, mesmo sem ser exigido). */
    private static function phaseTips(Evento $ev, string $code, callable $add, callable $n): void
    {
        $f = $ev->fields;
        switch ($code) {
            case 'briefing':
                if ($f['triage_status'] === 'pendente') {
                    $add('todo', 'O Marketing precisa fazer a triagem: aceitar o briefing ou devolver ao solicitante com o motivo.', null, 'Marketing');
                }
                break;
            case 'avaliacao':
                if (Aprovacao::stateFor($ev->getID(), 'entrada') === 'none') {
                    $add('todo', 'Peça a aprovação formal de entrada na aba Aprovações. As alçadas configuradas são avisadas.', 'aprovacoes', 'Dono do evento / GP');
                }
                if (trim((string) $f['participation_url']) === '' && !Documentos::countPhase($ev->getID(), 'avaliacao')) {
                    $add('warn', 'Sem contrato ou confirmação de participação: o gate de compras vai bloquear qualquer contratação. Informe o link ou anexe em Documentos.', 'documentos', 'Comercial');
                }
                break;
            case 'planejamento':
                if ((int) $f['projects_id'] && count(array_filter(Registro::forEvento($ev->getID(), 'marco'), fn ($m) => !Projeto::taskId($m)))) {
                    $add('todo', 'Há marcos sem tarefa no projeto. Use "Sincronizar marcos com o projeto" para os dois andarem juntos.', 'planejamento', 'GP');
                }
                if (!$n('checklist')) {
                    $add('todo', 'Carregue o modelo de checklist para acompanhar as etapas e os itens críticos.', 'checklists', 'GP');
                }
                if (!$n('marco')) {
                    $add('todo', 'Gere o cronograma do modelo (marcos de T-60 a T+15 dias) e ajuste as datas.', 'planejamento', 'GP');
                }
                if (!$n('kpi')) {
                    $add('todo', 'Defina os objetivos e KPIs agora: no encerramento o resultado é comparado com a meta.', 'marketing', 'Marketing');
                }
                if (Evento::canFinance() && !$n('lancamento')) {
                    $add('todo', 'Cadastre as linhas de Budget por categoria e peça a aprovação do orçamento base.', 'financeiro', 'Financeiro');
                }
                break;
            case 'compras':
                $big = array_filter(Registro::forEvento($ev->getID(), 'compra'), fn ($r) => (float) $r['amount_budget'] >= 20000 && $r['status'] !== 'cancelado');
                foreach ($big as $r) {
                    if (Registro::countKind($ev->getID(), 'proposta', ['parent_id' => $r['id']]) < 2) {
                        $add('todo', 'A compra "' . mb_strimwidth((string) $r['title'], 0, 40, '…') . '" é de valor relevante e tem menos de duas propostas: registre a comparação ou a justificativa.', 'compras', 'Compras');
                        break;
                    }
                }
                if ($n('contrato', ['status' => ['minuta', 'juridico']])) {
                    $add('todo', 'Há contrato aguardando validação do Jurídico ou assinatura.', 'compras', 'Compras / Jurídico');
                }
                break;
            case 'producao':
                if ($n('peca', ['status' => ['rascunho', 'em_aprovacao']])) {
                    $add('todo', 'Há peças de comunicação sem aprovação do Marketing.', 'marketing', 'Marketing');
                }
                if (!$n('reserva') && trim((string) $f['need_travel']) !== '' && stripos((string) $f['need_travel'], 'sem viagem') === false) {
                    $add('todo', 'O briefing prevê viagens, mas não há passagens ou hospedagens registradas.', 'logistica', 'Logística');
                }
                break;
            case 'prontidao':
                if (!$n('contingencia')) {
                    $add('todo', 'Registre o plano de contingência e os contatos dos fornecedores críticos.', 'logistica', 'GP');
                }
                break;
            case 'execucao':
                if (!$n('atividade')) {
                    $add('todo', 'Registre as atividades executadas (previsto × efetivo) para o relatório final.', 'execucao', 'Marketing');
                }
                if ($n('convidado') && !$n('convidado', ['status' => 'presente'])) {
                    $add('todo', 'Marque os convidados presentes na aba Marketing: a presença alimenta o custo por participante.', 'marketing', 'Marketing');
                }
                break;
            case 'encerramento':
                if (!$n('licao')) {
                    $add('todo', 'Registre as lições aprendidas enquanto o evento está fresco.', 'encerramento', 'PMO');
                }
                if (!$n('lead')) {
                    $add('todo', 'Registre os leads originados e influenciados e peça a confirmação do Comercial.', 'encerramento', 'Comercial');
                }
                break;
        }
    }

    private static function sort(array $tips): array
    {
        $rank = ['block' => 0, 'late' => 1, 'warn' => 2, 'todo' => 3, 'ok' => 4];
        $i = 0;
        foreach ($tips as &$t) {
            $t['i'] = $i++;
        }
        unset($t);
        usort($tips, fn ($a, $b) => [$rank[$a['sev']], $a['i']] <=> [$rank[$b['sev']], $b['i']]);
        return $tips;
    }

    private static function link(Evento $ev, ?string $tab): string
    {
        if (!$tab || !isset(Abas::META[$tab]) || !Acesso::canView($tab)) {
            return '';
        }
        return "<a class='btn btn-sm btn-ghost-primary' href='" . Menu::e(Evento::tabUrl($ev->getID(), $tab)) . "'>" . Menu::e(Abas::META[$tab][0]) . " <i class='ti ti-arrow-right'></i></a>";
    }

    /** Cartão "O que fazer agora" no alto do evento. */
    public static function card(Evento $ev): string
    {
        if (!self::enabled()) {
            return '';
        }
        $e = [Menu::class, 'e'];
        $tips = self::tips($ev);
        if (!$tips) {
            return '';
        }
        $count = array_count_values(array_column($tips, 'sev'));
        $phases = Fase::forEvento($ev->getID());
        $done = count(array_filter($phases, fn ($p) => in_array($p['status'], ['done', 'skipped'], true)));
        $h = "<div class='card mb-3 ge-tips'><div class='card-header'><h3 class='card-title'><i class='ti ti-bulb me-2'></i>O que fazer agora</h3><div class='card-actions d-flex gap-2 small'>";
        foreach (['block', 'late', 'warn', 'todo', 'ok'] as $s) {
            if (!empty($count[$s])) {
                $h .= "<span class='badge ge-badge' style='--c:" . self::SEV[$s][1] . "'>" . $count[$s] . ' ' . $e(mb_strtolower(self::SEV[$s][0])) . "</span>";
            }
        }
        $h .= "</div></div><div class='ge-tip-list'>";
        foreach (array_slice($tips, 0, 9) as $t) {
            [$label, $color, $icon] = self::SEV[$t['sev']];
            $h .= "<div class='ge-tip' style='--c:$color'><i class='ti $icon ge-tip-icon' title='" . $e($label) . "'></i><div class='ge-tip-body'><div>" . $e($t['text']) . "</div>"
                . ($t['who'] !== '' ? "<div class='small text-secondary'><i class='ti ti-user me-1'></i>" . $e($t['who']) . "</div>" : '') . "</div>" . self::link($ev, $t['tab']) . "</div>";
        }
        if (count($tips) > 9) {
            $h .= "<div class='small text-secondary px-3 pb-2'>+ " . (count($tips) - 9) . " dica(s) menos urgente(s) nas abas.</div>";
        }
        $h .= "</div>";
        // o que vem depois
        foreach ($phases as $p) {
            if ($p['status'] === 'pending') {
                $et = Etapa::all()[$p['code']] ?? [];
                $h .= "<div class='card-footer small text-secondary'><i class='ti ti-player-track-next me-1'></i><b>Depois:</b> " . $e(Fase::name($p['code'])) . ' · ' . $e(Fase::area($p)) . ((int) $p['slo_days'] ? ' · SLO ' . (int) $p['slo_days'] . ' dia(s)' : '')
                    . ' · vai exigir: ' . $e(Etapa::REQUIREMENTS[$et['requirement'] ?? ''] ?? '—') . " <span class='ms-2'>(etapa " . ($done + 2) . ' de ' . count($phases) . ")</span></div>";
                break;
            }
        }
        return $h . "</div>";
    }

    /** Faixa com as dicas da aba (as que apontam para ela). */
    public static function strip(Evento $ev, string $tab): string
    {
        if (!self::enabled() || $tab === 'historico' || (int) $ev->fields['status'] !== Evento::OPEN) {
            return '';
        }
        $mine = array_values(array_filter(self::tips($ev), fn ($t) => $t['tab'] === $tab && $t['sev'] !== 'ok'));
        if (!$mine) {
            return '';
        }
        $e = [Menu::class, 'e'];
        $h = "<div class='ge ge-tab'><div class='ge-strip'><i class='ti ti-bulb'></i><div class='flex-grow-1'><b>Dicas para esta aba</b>";
        foreach (array_slice($mine, 0, 4) as $t) {
            $h .= "<div class='small' style='color:" . self::SEV[$t['sev']][1] . "'><i class='ti " . self::SEV[$t['sev']][2] . " me-1'></i>" . $e($t['text']) . "</div>";
        }
        return $h . "</div></div></div>";
    }
}
