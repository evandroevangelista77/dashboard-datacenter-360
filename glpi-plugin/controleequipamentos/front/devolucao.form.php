<?php

include('../../../inc/includes.php');

use GlpiPlugin\Controleequipamentos\Ciclo;
use GlpiPlugin\Controleequipamentos\Devolucao;
use GlpiPlugin\Controleequipamentos\FluxoDevolucao;
use GlpiPlugin\Controleequipamentos\Menu;
use GlpiPlugin\Controleequipamentos\Tela;

Session::checkRight(Menu::RIGHT, READ);

const FORMULARIO_REF = 'Solicitação de Transporte de Serviços · Nº 60.13.11 · rev. 02';

$id = (int) ($_REQUEST['id'] ?? 0);
$erros = [];
$preenchido = [];
$itensForm = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar') {
        Session::checkRight(Menu::RIGHT, CREATE);
        $preenchido = $_POST;
        $itensForm = $_POST['itens'] ?? [];
        $resultado = Devolucao::criar($_POST);
        if (isset($resultado['erros'])) {
            $erros = $resultado['erros'];
        } else {
            Session::addMessageAfterRedirect('Ciclo iniciado. A etapa 1 foi concluída e o responsável da etapa 2 foi avisado.', false, INFO);
            Html::redirect(Menu::url('/front/devolucao.form.php?id=' . (int) $resultado['id']));
        }
    } elseif ($acao === 'avancar') {
        $preenchido = $_POST;
        $resultado = Devolucao::avancar($id, $_POST);
        if (isset($resultado['erros'])) {
            $erros = $resultado['erros'];
        } else {
            Session::addMessageAfterRedirect('Etapa concluída. O próximo responsável foi avisado.', false, INFO);
            Html::redirect(Menu::url('/front/devolucao.form.php?id=' . $id));
        }
    } elseif ($acao === 'cancelar') {
        $resultado = Devolucao::cancelar($id, trim((string) ($_POST['motivo'] ?? '')));
        if (isset($resultado['erros'])) {
            $erros = $resultado['erros'];
        } else {
            Session::addMessageAfterRedirect('Ciclo cancelado.', false, INFO);
            Html::redirect(Menu::url('/front/devolucao.form.php?id=' . $id));
        }
    }
}

Menu::header('Ciclo de devolução', 'controle');
$lista = Menu::button('Ciclos', Menu::url('/front/controle.php?aba=devolucao'), 'ti-list', 'btn-outline-secondary');

// ------------------------------------------------------------ iniciar ciclo (etapa 1 = formulário)
if ($id === 0) {
    Session::checkRight(Menu::RIGHT, CREATE);
    echo Menu::cabecalhoNovo('Novo ciclo', 'Devolução de equipamento', 'Preencha o formulário de solicitação de transporte. Ao iniciar, o ciclo abre o chamado da etapa 2 (Compras) e avisa o responsável.', 'ti-package-export', $lista);
    if ($erros) {
        Tela::erros($erros);
    }
    formulario(null, $preenchido, array_values($itensForm), true);
    Menu::footer();
    exit;
}

// ------------------------------------------------------------ ciclo existente (layout do Ciclo Financeiro)
$ciclo = Devolucao::buscar($id);
if (!$ciclo) {
    echo Menu::title('Ciclo não encontrado', '', $lista);
    echo "<div class='alert alert-warning' role='alert'>O ciclo não existe ou você não tem acesso à entidade dele.</div>";
    Menu::footer();
    exit;
}

$cadastro = [
    ['Colaborador', $ciclo['name']],
    ['Solicitante', $ciclo['solicitante']],
    ['Centro de custo', $ciclo['centro_custo']],
    ['Data de admissão', Menu::date($ciclo['data_admissao'])],
    ['Data de saída', Menu::date($ciclo['data_saida'])],
    ['RG', $ciclo['rg']],
    ['CPF', $ciclo['cpf']],
    ['Coleta', trim(($ciclo['coleta_endereco'] ?? '') . ' · ' . Menu::date($ciclo['coleta_data']) . ' · ' . ($ciclo['coleta_horario'] ?? ''))],
    ['Entrega', trim(($ciclo['entrega_endereco'] ?? '') . ' · ' . Menu::date($ciclo['entrega_data']) . ' · ' . ($ciclo['entrega_horario'] ?? ''))],
    ['Tipo de serviço', Devolucao::TIPOS[$ciclo['tipo_servico']] ?? ''],
    ['Transportadora', $ciclo['transportadora']],
    ['Total informado', Menu::money((float) $ciclo['total'])],
];
$itens = Devolucao::itens($id);
Tela::cabecalho(Devolucao::class, $ciclo, [
    'subtitulo' => 'Devolução de equipamento · ' . FORMULARIO_REF,
    'extra1' => ['Itens', (string) array_sum(array_column($itens, 'quantidade'))],
    'extra2' => ['Total informado', Menu::money((float) $ciclo['total'])],
]);
Tela::esteira(Devolucao::class, $ciclo);
Tela::etapaAtual(Devolucao::class, $ciclo, $preenchido, $erros);
Tela::dadosCiclo(Devolucao::class, $ciclo, $cadastro);
Tela::linhaDoTempo(Devolucao::class, $id);

echo "<details class='card mb-3'><summary class='card-header fw-bold'>Formulário de solicitação e materiais (somente leitura)</summary><div class='card-body'>";
formulario($ciclo, $ciclo, $itens, false);
echo "</div></details>";

Menu::footer();

/** Formulário de solicitação: cadastro (etapa 1) ou leitura dos dados gravados. */
function formulario(?array $ciclo, array $valores, array $itens, bool $novo): void
{
    $action = Menu::url('/front/devolucao.form.php' . ($novo ? '' : '?id=' . (int) $ciclo['id']));
    $desabilitado = $novo ? '' : ' disabled';
    echo "<form method='post' action='" . Menu::e($action) . "' class='ce-form' novalidate>" . Menu::csrf()
        . ($novo ? "<input type='hidden' name='acao' value='criar'>" : '');

    $secoes = [
        'Dados para emissão da nota fiscal' => ['name', 'solicitante', 'centro_custo', 'data_admissao', 'rg', 'cpf', 'data_saida'],
        'Dados da coleta'                   => ['coleta_endereco', 'coleta_data', 'coleta_horario', 'coleta_contato'],
        'Dados da entrega'                  => ['entrega_endereco', 'entrega_data', 'entrega_horario', 'entrega_contato'],
        'Tipo de serviço'                   => ['tipo_servico', 'transportadora', 'observacao'],
    ];
    foreach ($secoes as $titulo => $chaves) {
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>" . Menu::e($titulo) . "</h3></div><div class='card-body'><div class='row g-3'>";
        foreach ($chaves as $chave) {
            [$rotulo, $tipo, $obrigatorio] = Devolucao::CAMPOS[$chave];
            $valor = (string) ($valores[$chave] ?? '');
            $campo = $tipo === 'tipo'
                ? Menu::campo($chave, $rotulo, 'select', $obrigatorio, $valor, Devolucao::TIPOS)
                : Menu::campo($chave, $rotulo, $tipo, $obrigatorio, $valor);
            echo $novo ? $campo : str_replace(['<input ', '<select ', '<textarea '], ['<input disabled ', '<select disabled ', '<textarea disabled '], $campo);
        }
        echo "</div></div></div>";
    }

    // materiais
    $colunas = ['material' => 'Material', 'quantidade' => 'Qtd.', 'peso' => 'Peso', 'dimensao' => 'Dimensão (alt. × larg. × comp.)', 'serie' => 'Nº de série', 'valor' => 'Valor (R$)'];
    $linhas = $itens;
    if ($novo) {
        $linhas = array_merge($linhas, array_fill(0, max(0, 4 - count($linhas)), []));
    }
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Materiais</h3><div class='card-actions small text-secondary'>Linhas em branco são ignoradas</div></div><div class='table-responsive'><table class='table table-vcenter card-table ce-materiais'>"
        . "<caption class='visually-hidden'>Materiais a devolver</caption><thead><tr>";
    foreach ($colunas as $rotulo) {
        echo "<th scope='col'>" . Menu::e($rotulo) . "</th>";
    }
    echo "</tr></thead><tbody>";
    foreach ($linhas as $i => $linha) {
        echo "<tr>";
        foreach ($colunas as $chave => $rotulo) {
            $v = (string) ($linha[$chave] ?? '');
            if ($chave === 'valor' && $v !== '' && is_numeric($v)) {
                $v = number_format((float) $v, 2, ',', '.');
            }
            $tipoInput = $chave === 'quantidade' ? "type='number' min='0' step='1'" : "type='text'";
            echo "<td><input class='form-control form-control-sm' $tipoInput name='itens[$i][$chave]' value='" . Menu::e($v) . "' aria-label='" . Menu::e('Linha ' . ($i + 1) . ": $rotulo") . "'$desabilitado></td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table></div></div>";

    if ($novo) {
        echo "<div class='card mb-3'><div class='card-footer d-flex justify-content-end'><button type='submit' class='btn btn-primary'><i class='ti ti-player-play me-1' aria-hidden='true'></i>Iniciar ciclo</button></div></div>";
    }
    echo "</form>";
}
