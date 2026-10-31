<?php

namespace GlpiPlugin\Controleequipamentos;

use Group;
use Session;
use Ticket;
use User;

/**
 * Partes compartilhadas das telas de ciclo (entrega e devolução): linha do tempo,
 * formulário da etapa atual e mensagens. Cada tela informa a classe do ciclo.
 */
class Tela
{
    public const SIM_NAO = ['sim' => 'Sim', 'nao' => 'Não'];

    /** Campos de decisão viram lista Sim/Não; o resto usa o tipo do fluxo. */
    public static function campo(string $key, string $label, string $tipo, bool $obrig, string $valor): string
    {
        if (in_array($tipo, ['sim_nao', 'decisao'], true)) {
            return Menu::campo($key, $label, 'select', $obrig, $valor, self::SIM_NAO);
        }
        return Menu::campo($key, $label, $tipo, $obrig, $valor);
    }

    public static function erros(array $erros): void
    {
        echo "<div class='alert alert-danger' role='alert'><div class='fw-bold mb-1'>Não foi possível concluir:</div><ul class='mb-0'>";
        foreach ($erros as $erro) {
            echo "<li>" . Menu::e($erro) . "</li>";
        }
        echo "</ul></div>";
    }

    /** Quem deve agir na etapa: o responsável, ou o grupo, ou vazio. */
    public static function responsavel(string $classe, int $step): string
    {
        $resp = $classe::responsavelDaEtapa($step);
        if ($resp > 0) {
            return User::getFriendlyNameById($resp);
        }
        $grupo = $classe::grupoDaEtapa($step);
        if ($grupo > 0) {
            $g = new Group();
            return $g->getFromDB($grupo) ? (string) $g->fields['name'] : '';
        }
        return '';
    }

    /** Cabeçalho do ciclo no padrão do Ciclo Financeiro: código, nome, status e indicadores à direita. */
    public static function cabecalho(string $classe, array $ciclo, array $indicadores): void
    {
        $flow = $classe::fluxo();
        $id = (int) $ciclo['id'];
        $cod = $classe::prefixo() . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
        [$rotulo, $cor] = [
            Ciclo::ABERTA => ['Em andamento', 'blue'],
            Ciclo::CONCLUIDA => ['Concluído', 'green'],
            Ciclo::CANCELADA => ['Cancelado', 'secondary'],
        ][$ciclo['status']] ?? [$ciclo['status'], 'secondary'];
        $atual = (int) $ciclo['current_step'];
        $fase = $ciclo['status'] === Ciclo::ABERTA ? $atual . '. ' . $flow::nome($atual) : '—';
        $dias = ((strtotime($ciclo['status'] === Ciclo::ABERTA ? date('Y-m-d H:i:s') : $ciclo['date_mod']) - strtotime($ciclo['date_creation'])) / 86400);
        $aba = $classe === Entrega::class ? 'entrega' : 'devolucao';
        echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'><div class='cf-head-icon'><i class='ti ti-" . ($classe === Entrega::class ? 'user-plus' : 'package-export') . "' aria-hidden='true'></i></div><div class='me-auto'>"
            . "<div class='cf-crumb'>" . Menu::e($cod) . "</div><h2 class='m-0'>" . Menu::e($ciclo['name']) . "</h2>"
            . "<div class='small text-secondary mt-1'><span class='badge bg-$cor-lt text-$cor'>" . Menu::e($rotulo) . "</span> · " . Menu::e($indicadores['subtitulo'] ?? '') . "</div></div>";
        foreach ([
            ['Dias no ciclo', number_format($dias, 1, ',', '.'), $ciclo['status'] === Ciclo::ABERTA ? 'desde o início' : 'do início ao fim'],
            ['Etapa atual', $fase, ''],
            [$indicadores['extra1'][0] ?? '', $indicadores['extra1'][1] ?? '—', ''],
            [$indicadores['extra2'][0] ?? '', $indicadores['extra2'][1] ?? '—', ''],
        ] as [$rot, $valor, $sub]) {
            echo "<div class='cf-stat'><div class='cf-stat-label'>" . Menu::e($rot) . "</div><div class='cf-stat-value'>" . Menu::e($valor) . "</div><div class='cf-stat-sub'>" . Menu::e($sub) . "</div></div>";
        }
        echo "<div>" . Menu::button('Ciclos', Menu::url('/front/controle.php?aba=' . $aba), 'ti-list', 'btn-outline-secondary') . "</div></div></div>";
    }

    /**
     * Esteira das etapas, como as fases do Ciclo Financeiro: cada etapa com o tempo gasto e o prazo
     * ("1,2d / 3d"); concluídas em verde, a atual em azul e atrasadas em vermelho.
     */
    public static function esteira(string $classe, array $ciclo): void
    {
        $flow = $classe::fluxo();
        $atual = (int) $ciclo['current_step'];
        $aberto = $ciclo['status'] === Ciclo::ABERTA;
        echo "<div class='card mb-3'><div class='cf-steps'>";
        for ($s = 1; $s <= $flow::ULTIMA; $s++) {
            $visita = null;
            $ultima = null;
            foreach ($classe::etapas((int) $ciclo['id']) as $v) {
                if ((int) $v['step'] === $s) {
                    $ultima = $v;
                    if ($v['action'] === Ciclo::ABERTA) {
                        $visita = $v;
                    }
                }
            }
            $feita = $ultima && $ultima['action'] !== Ciclo::ABERTA;
            $cls = $visita ? 'active' : ($feita ? 'done' : 'pending');
            $meta = '—';
            if ($ultima) {
                $p = $classe::prazo($ultima, date('Y-m-d H:i:s'));
                $meta = number_format($p['dias'], 1, ',', '.') . 'd' . ($p['slo'] ? ' / ' . $p['slo'] . 'd' : '');
                if ($p['atrasada']) {
                    $cls .= ' late';
                }
            }
            if (!$aberto && $s === $atual && !$feita) {
                $cls = 'skipped';
            }
            echo "<div class='cf-step $cls'><div class='cf-step-dot'>" . ($feita ? "<i class='ti ti-check' aria-hidden='true'></i>" : $s) . "</div>"
                . "<div class='cf-step-name'>" . Menu::e($flow::nome($s)) . "</div>"
                . "<div class='cf-step-meta'>" . Menu::e($flow::AREAS[$flow::area($s)]) . "</div>"
                . "<div class='cf-step-meta'>$meta</div></div>";
        }
        echo "</div></div>";
    }

    /**
     * Dados do ciclo, por seção (como "VENDA · FASE AVISO DE FECHAMENTO" no Ciclo Financeiro):
     * o cadastro e, para cada etapa já passada, os campos preenchidos e a observação.
     */
    public static function dadosCiclo(string $classe, array $ciclo, array $cadastro): void
    {
        $flow = $classe::fluxo();
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Dados do ciclo</h3></div><div class='card-body'>";
        echo "<div class='cf-section'>Cadastro</div><div class='row g-3 mb-2'>";
        foreach ($cadastro as [$rotulo, $valor]) {
            echo "<div class='col-6 col-md-4'><div class='text-secondary small'>" . Menu::e($rotulo) . "</div><div class='fw-medium'>" . Menu::e($valor !== '' && $valor !== null ? $valor : '—') . "</div></div>";
        }
        echo "</div>";
        $visitas = $classe::etapas((int) $ciclo['id']);
        for ($s = 2; $s <= $flow::ULTIMA; $s++) {
            $ultima = null;
            foreach ($visitas as $v) {
                if ((int) $v['step'] === $s && $v['action'] !== Ciclo::ABERTA) {
                    $ultima = $v;
                }
            }
            $dados = $ultima ? (json_decode((string) $ultima['dados'], true) ?: []) : [];
            echo "<div class='cf-section'>Etapa $s · " . Menu::e(mb_strtoupper($flow::nome($s))) . "</div>";
            if (!$ultima) {
                echo "<div class='text-secondary small mb-2'>Ainda não concluída.</div>";
                continue;
            }
            echo "<div class='row g-3 mb-2'>";
            foreach ($flow::campos($s) as [$key, $label, $tipo]) {
                $v = $dados[$key] ?? '';
                $texto = $v === 'sim' ? 'Sim' : ($v === 'nao' ? 'Não' : ($tipo === 'money' && $v !== '' ? Menu::money((float) str_replace(',', '.', $v)) : ($v !== '' ? $v : '—')));
                echo "<div class='col-6 col-md-4'><div class='text-secondary small'>" . Menu::e($label) . "</div><div class='fw-medium'>" . Menu::e($texto) . "</div></div>";
            }
            echo "</div>";
            echo "<div class='small text-secondary'>Concluída por " . Menu::e($ultima['users_id'] ? User::getFriendlyNameById((int) $ultima['users_id']) : '—')
                . " em " . Menu::date($ultima['date'], true) . ($ultima['observacao'] ? ' · Observação: ' . Menu::e($ultima['observacao']) : '') . "</div>";
        }
        echo "</div></div>";
    }

    /** Linha do tempo no padrão do Ciclo: uma passagem por linha, com duração contra o prazo. */
    public static function linhaDoTempo(string $classe, int $id): void
    {
        $flow = $classe::fluxo();
        $visitas = $classe::etapas($id);
        echo "<div class='card mb-3'><div class='card-body'><h3 class='card-title mb-1'>Linha do tempo</h3>"
            . "<div class='small text-secondary mb-3'>Cada passagem pela etapa, na ordem em que aconteceu. Uma devolução abre nova passagem pela etapa 2 e mantém a anterior.</div>";
        if (!$visitas) {
            echo "<p class='text-secondary'>Nenhuma passagem registrada.</p></div></div>";
            return;
        }
        echo "<ol class='cf-tl'>";
        foreach ($visitas as $visita) {
            $n = (int) $visita['step'];
            $prazo = $classe::prazo($visita, date('Y-m-d H:i:s'));
            $resultado = [Ciclo::CONCLUIDA => 'Concluída', 'devolvida' => 'Devolvida para Compras', Ciclo::ABERTA => 'Em andamento', Ciclo::CANCELADA => 'Cancelada'][$visita['action']] ?? $visita['action'];
            $cor = $visita['action'] === Ciclo::ABERTA ? ($prazo['atrasada'] ? '#ef4444' : '#3b82f6') : ($visita['action'] === 'devolvida' ? '#ef4444' : ($visita['action'] === Ciclo::CANCELADA ? '#94a3b8' : '#22c55e'));
            $run = $visita['action'] === Ciclo::ABERTA ? 'active' : ($visita['action'] === 'devolvida' ? 'return' : '');
            $pct = $prazo['slo'] ? min(100, round(100 * $prazo['dias'] / $prazo['slo'])) : 100;
            $quem = $visita['users_id'] ? User::getFriendlyNameById((int) $visita['users_id']) : '';
            $chamado = $visita['tickets_id'] ? "<a href='" . Menu::e(Ticket::getFormURLWithID((int) $visita['tickets_id'])) . "'>chamado #" . (int) $visita['tickets_id'] . "</a>" : 'sem chamado';
            echo "<li class='cf-tl-run $run' style='--c:$cor'><span class='cf-tl-dot'><i class='ti ti-" . ($visita['action'] === Ciclo::ABERTA ? 'loader-2' : ($visita['action'] === 'devolvida' ? 'arrow-back-up' : 'check')) . "'></i></span>"
                . "<div class='cf-tl-body'><div class='cf-tl-head'><b>$n. " . Menu::e($flow::nome($n)) . "</b><span class='cf-tl-area'>" . Menu::e($flow::AREAS[$flow::area($n)]) . "</span>"
                . "<span class='cf-tl-status'>" . Menu::e($resultado) . "</span>"
                . "<span class='cf-tl-dur'>" . number_format($prazo['dias'], 1, ',', '.') . "d" . ($prazo['slo'] ? ' / ' . $prazo['slo'] : '') . "</span>"
                . "<span class='cf-tl-when'>" . Menu::date($visita['date_start'], true) . ($visita['date'] ? ' → ' . Menu::date($visita['date'], true) : '') . "</span></div>";
            if ($prazo['slo']) {
                echo "<div class='cf-tl-bar'><i style='width:" . min(100, $pct) . "%'></i></div>";
            }
            echo "<div class='cf-tl-meta'>$chamado" . ($quem !== '' ? ' · ' . Menu::e($quem) : '') . ($prazo['atrasada'] ? " · <b class='text-danger'>atrasada</b>" : '') . "</div>";
            if (!empty($visita['observacao'])) {
                echo "<div class='cf-tl-note" . ($visita['action'] === 'devolvida' ? ' danger' : '') . "'>" . nl2br(Menu::e($visita['observacao'])) . "</div>";
            }
            echo "</div></li>";
        }
        echo "</ol></div></div>";
    }

    /**
     * Cartão da etapa atual: o formulário para quem pode concluir; para os outros, quem deve agir.
     * Com o ciclo encerrado, mostra só o estado final.
     */
    public static function etapaAtual(string $classe, array $ciclo, array $preenchido, array $erros): void
    {
        $flow = $classe::fluxo();
        $id = (int) $ciclo['id'];
        $step = (int) $ciclo['current_step'];

        if ($ciclo['status'] !== Ciclo::ABERTA) {
            $texto = $ciclo['status'] === Ciclo::CANCELADA ? 'Ciclo cancelado. Os chamados em aberto foram resolvidos.' : 'Ciclo concluído. Os chamados das etapas foram resolvidos.';
            echo "<div class='alert alert-" . ($ciclo['status'] === Ciclo::CANCELADA ? 'secondary' : 'success') . " mb-3' role='status'><i class='ti ti-" . ($ciclo['status'] === Ciclo::CANCELADA ? 'ban' : 'circle-check') . " me-2' aria-hidden='true'></i>" . Menu::e($texto) . "</div>";
            return;
        }
        $area = $flow::AREAS[$flow::area($step)];
        $responsavel = $classe::responsavelDaEtapa($step);
        $grupo = $classe::grupoDaEtapa($step);
        $quem = '';
        if ($responsavel > 0) {
            $quem = User::getFriendlyNameById($responsavel);
        } elseif ($grupo > 0) {
            $g = new Group();
            $quem = $g->getFromDB($grupo) ? (string) $g->fields['name'] : '';
        }
        if (!$classe::podeAgir($step)) {
            echo "<div class='alert alert-info mb-3' role='status'><i class='ti ti-hourglass me-2' aria-hidden='true'></i>Aguardando <strong>" . Menu::e($quem !== '' ? $quem : $area) . "</strong> (" . Menu::e($area) . "). Só quem é desse responsável ou grupo, ou tem direito de atualização, conclui esta etapa.</div>";
            return;
        }

        $action = Menu::url($classe::pagina() . '?id=' . $id);
        echo "<div class='card mb-3 cf-current'><div class='card-header'><div><div class='small text-secondary'>Etapa $step de " . (int) $flow::ULTIMA . " · " . Menu::e($area) . "</div>"
            . "<h3 class='card-title'>" . Menu::e($flow::nome($step)) . "</h3></div></div><div class='card-body'>";
        echo "<p class='text-secondary mb-3'>" . Menu::e($flow::ETAPAS[$step][2]) . "</p>";
        if ($erros) {
            self::erros($erros);
        }
        echo "<form method='post' action='" . Menu::e($action) . "' class='ce-form' novalidate>" . Menu::csrf()
            . "<input type='hidden' name='acao' value='avancar'><input type='hidden' name='id' value='$id'>";
        $campos = $flow::campos($step);
        if ($campos) {
            echo "<div class='row g-3 mb-3'>";
            foreach ($campos as [$key, $label, $tipo, $obrig]) {
                echo self::campo($key, $label, $tipo, $obrig, (string) ($preenchido[$key] ?? ''));
            }
            echo "</div>";
        }
        echo "<div class='row g-3 mb-3'>" . Menu::campo('observacao', 'Observação (opcional)', 'textarea', false, (string) ($preenchido['observacao'] ?? '')) . "</div>";
        echo "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2'><span class='text-secondary small'>Ao concluir, o chamado desta etapa é resolvido e o próximo responsável é avisado.</span>"
            . "<button type='submit' class='btn btn-primary'><i class='ti ti-check me-1' aria-hidden='true'></i>Concluir etapa $step" . ($step < $flow::ULTIMA ? ' e avisar a próxima' : ' e encerrar o ciclo') . "</button></div></form>";
        echo "</div></div>";

        if (Session::haveRight(Menu::RIGHT, UPDATE)) {
            echo "<details class='mb-3'><summary class='text-secondary'>Cancelar este ciclo</summary><form method='post' action='" . Menu::e($action) . "' class='mt-2' novalidate "
                . "onsubmit=\"return confirm('Cancelar este ciclo? O chamado da etapa em andamento será resolvido.');\">" . Menu::csrf()
                . "<input type='hidden' name='acao' value='cancelar'><div class='row g-2 align-items-end'><div class='col-md-8'><label class='form-label' for='ce-motivo'>Motivo do cancelamento</label>"
                . "<input class='form-control' id='ce-motivo' name='motivo' type='text'></div><div class='col-md-4'><button type='submit' class='btn btn-outline-danger w-100'>Cancelar ciclo</button></div></div></form></details>";
        }
    }
}
