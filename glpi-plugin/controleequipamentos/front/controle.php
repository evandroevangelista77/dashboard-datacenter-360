<?php

include('../../../inc/includes.php');

use GlpiPlugin\Controleequipamentos\Ciclo;
use GlpiPlugin\Controleequipamentos\Devolucao;
use GlpiPlugin\Controleequipamentos\Entrega;
use GlpiPlugin\Controleequipamentos\Menu;
use GlpiPlugin\Controleequipamentos\Tela;

Session::checkRight(Menu::RIGHT, READ);

$aba = ($_GET['aba'] ?? 'entrega') === 'devolucao' ? 'devolucao' : 'entrega';
$podeCriar = Session::haveRight(Menu::RIGHT, CREATE);
$classe = $aba === 'devolucao' ? Devolucao::class : Entrega::class;
$filtros = ['aberta' => 'Em andamento', 'concluida' => 'Concluídos', 'cancelada' => 'Cancelados', 'todas' => 'Todos'];
$filtro = array_key_exists($_GET['status'] ?? '', $filtros) ? $_GET['status'] : 'aberta';

Menu::header('Ciclos de equipamentos', 'controle');

$painel = Menu::button('Painel', Menu::url('/front/painel.php?aba=' . $aba), 'ti-chart-dots-3', 'btn-outline-secondary');
if ($aba === 'devolucao') {
    $acao = $podeCriar ? Menu::button('Iniciar ciclo de devolução', Menu::url('/front/devolucao.form.php?novo=1')) : '';
    echo Menu::title('Devolução de equipamento', 'Colaboradores desligados: do pedido de transporte à baixa no patrimônio.', $painel . $acao);
} else {
    $acao = $podeCriar ? Menu::button('Iniciar ciclo de entrega', Menu::url('/front/entrega.form.php?novo=1')) : '';
    echo Menu::title('Entrega de equipamento', 'Novos colaboradores: do cadastro do RH à configuração final.', $painel . $acao);
}
echo Menu::abas($aba);

$lista = $classe::listar($filtro);
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-list me-2' aria-hidden='true'></i>Ciclos</h3>"
    . "<div class='card-actions d-flex gap-3 align-items-center'><span class='small text-secondary'>" . count($lista) . " ciclo(s)</span>" . Menu::filtroStatus($aba, $filtro, $filtros) . "</div></div>";
echo listaCiclos($classe, $lista);
echo "</div>";

Menu::footer();

/** Tabela completa dos ciclos: detalhes, etapa, responsável, prazo e status. */
function listaCiclos(string $classe, array $linhas): string
{
    $e = [Menu::class, 'e'];
    $ehEntrega = $classe === Entrega::class;
    $html = "<div class='table-responsive'><table class='table table-sm table-vcenter card-table ce-tabela'><caption class='visually-hidden'>Ciclos de " . ($ehEntrega ? 'entrega' : 'devolução') . "</caption><thead><tr>"
        . "<th scope='col'>Código</th><th scope='col'>" . ($ehEntrega ? 'Colaborador' : 'Colaborador') . "</th>"
        . ($ehEntrega ? "<th scope='col'>Setor · cargo</th>" : "<th scope='col'>Tipo de serviço</th><th scope='col'>Saída</th><th scope='col' class='ce-num'>Total</th>")
        . "<th scope='col'>Etapa atual</th><th scope='col'>Responsável</th><th scope='col'>Prazo</th><th scope='col'>Início</th><th scope='col'>Status</th><th scope='col'>Chamado</th><th scope='col'><span class='visually-hidden'>Abrir</span></th></tr></thead><tbody>";
    if (!$linhas) {
        $html .= "<tr><td colspan='12' class='text-secondary'>Nenhum ciclo para este filtro.</td></tr>";
    }
    foreach ($linhas as $c) {
        $flow = $classe::fluxo();
        $id = (int) $c['id'];
        $cod = $classe::prefixo() . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
        $aberto = $c['status'] === Ciclo::ABERTA;
        $atual = (int) $c['current_step'];
        $visita = $aberto ? $classe::etapaAberta($id, $atual) : null;
        $prazo = $visita ? $classe::prazo($visita, date('Y-m-d H:i:s')) : ['dias' => 0, 'slo' => 0, 'atrasada' => false];
        [$rotulo, $cor] = [Ciclo::ABERTA => ['Em andamento', 'blue'], Ciclo::CONCLUIDA => ['Concluído', 'green'], Ciclo::CANCELADA => ['Cancelado', 'secondary']][$c['status']] ?? [$c['status'], 'secondary'];
        $situacao = $aberto ? ($prazo['atrasada'] ? "<span class='badge bg-red-lt text-red'>Atrasada</span>" : "<span class='badge bg-green-lt text-green'>No prazo</span>") : '';
        $chamado = $visita && $visita['tickets_id'] ? "<a href='" . $e(Ticket::getFormURLWithID((int) $visita['tickets_id'])) . "'>#" . (int) $visita['tickets_id'] . "</a>" : '—';
        $html .= "<tr><th scope='row'><a href='" . $e(Menu::url($classe::pagina() . '?id=' . $id)) . "'>" . $e($cod) . "</a></th>"
            . "<td>" . $e($c['name']) . "</td>"
            . ($ehEntrega
                ? "<td>" . $e(trim(($c['setor'] ?? '') . ($c['cargo'] ? ' · ' . $c['cargo'] : ''))) . "</td>"
                : "<td>" . $e(Devolucao::TIPOS[$c['tipo_servico']] ?? '—') . "</td><td>" . Menu::date($c['data_saida']) . "</td><td class='ce-num'>" . Menu::money((float) $c['total']) . "</td>")
            . "<td>" . ($aberto ? $atual . '. ' . $e($flow::nome($atual)) : '—') . "</td>"
            . "<td>" . ($aberto ? $e(Tela::responsavel($classe, $atual)) : '—') . "</td>"
            . "<td>" . ($aberto ? number_format($prazo['dias'], 1, ',', '.') . 'd' . ($prazo['slo'] ? ' / ' . $prazo['slo'] . 'd' : '') . ' ' . $situacao : '—') . "</td>"
            . "<td>" . Menu::date($c['date_creation']) . "</td>"
            . "<td><span class='badge bg-$cor-lt text-$cor'>" . $e($rotulo) . "</span></td><td>$chamado</td>"
            . "<td><a class='btn btn-sm btn-outline-primary' href='" . $e(Menu::url($classe::pagina() . '?id=' . $id)) . "'>Abrir</a></td></tr>";
    }
    return $html . "</tbody></table></div>";
}
