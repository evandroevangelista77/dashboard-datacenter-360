<?php

include('../../../inc/includes.php');

use GlpiPlugin\Controleequipamentos\Entrega;
use GlpiPlugin\Controleequipamentos\Fluxo;
use GlpiPlugin\Controleequipamentos\Menu;
use GlpiPlugin\Controleequipamentos\Tela;

Session::checkRight(Menu::RIGHT, READ);

$id = (int) ($_REQUEST['id'] ?? 0);
$erros = [];
$preenchido = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
    $acao = $_POST['acao'] ?? '';
    $preenchido = $_POST;

    if ($acao === 'criar') {
        Session::checkRight(Menu::RIGHT, CREATE);
        $resultado = Entrega::criar($_POST);
        if (isset($resultado['erros'])) {
            $erros = $resultado['erros'];
        } else {
            Session::addMessageAfterRedirect('Ciclo iniciado. A etapa 1 foi concluída e o responsável da etapa 2 foi avisado.', false, INFO);
            Html::redirect(Menu::url('/front/entrega.form.php?id=' . (int) $resultado['id']));
        }
    } elseif ($acao === 'avancar') {
        $resultado = Entrega::avancar($id, $_POST);
        if (isset($resultado['erros'])) {
            $erros = $resultado['erros'];
        } else {
            Session::addMessageAfterRedirect('Etapa concluída. O próximo responsável foi avisado.', false, INFO);
            Html::redirect(Menu::url('/front/entrega.form.php?id=' . $id));
        }
    } elseif ($acao === 'cancelar') {
        $resultado = Entrega::cancelar($id, trim((string) ($_POST['motivo'] ?? '')));
        if (isset($resultado['erros'])) {
            $erros = $resultado['erros'];
        } else {
            Session::addMessageAfterRedirect('Ciclo cancelado.', false, INFO);
            Html::redirect(Menu::url('/front/entrega.form.php?id=' . $id));
        }
    }
}

Menu::header('Ciclo de entrega', 'controle');
$lista = Menu::button('Ciclos', Menu::url('/front/controle.php?aba=entrega'), 'ti-list', 'btn-outline-secondary');

// ------------------------------------------------------------ iniciar ciclo
if (isset($_GET['novo']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && $erros && ($preenchido['acao'] ?? '') === 'criar')) {
    Session::checkRight(Menu::RIGHT, CREATE);
    $admissoes = Entrega::admissoesRh();
    $rhSel = (int) ($preenchido['rh_admissao'] ?? ($_GET['rh'] ?? 0));
    echo Menu::cabecalhoNovo('Novo ciclo', 'Entrega de equipamento', 'A etapa 1 é o cadastro do novo colaborador, feito pelo RH. Ao iniciar, o ciclo abre o chamado da etapa 2 e avisa o responsável.', 'ti-user-plus', $lista);
    if ($erros) {
        Tela::erros($erros);
    }
    echo "<form method='post' action='" . Menu::e(Menu::url('/front/entrega.form.php')) . "' class='card mb-3 ce-form' novalidate>" . Menu::csrf()
        . "<input type='hidden' name='acao' value='criar'>"
        . "<div class='card-header'><h3 class='card-title'><i class='ti ti-file-description me-2' aria-hidden='true'></i>Dados do colaborador</h3></div><div class='card-body row g-3'>";

    // admissão do RH (onboarding): preenche os dados do colaborador
    echo "<div class='col-12 cf-section'><i class='ti ti-users me-1' aria-hidden='true'></i>Admissão do RH <span class='text-secondary fw-normal'>· etapa 1</span></div>";
    if (Plugin::isPluginActive('rh')) {
        echo "<div class='col-12 col-md-8'><label class='form-label' for='ce-rh-admissao'>Admissão em onboarding</label><select class='form-select' id='ce-rh-admissao' name='rh_admissao'>"
            . "<option value=''>Sem admissão do RH (preencher manualmente)</option>";
        foreach ($admissoes as $a) {
            $rotulo = $a['name'] . ($a['cargo'] !== '' ? ' · ' . $a['cargo'] : '') . ($a['setor'] !== '' ? ' · ' . $a['setor'] : '') . ' · início ' . Menu::date($a['inicio']) . ' · ' . $a['status'];
            echo "<option value='" . (int) $a['id'] . "'" . ((int) $a['id'] === $rhSel ? ' selected' : '') . ">" . Menu::e($rotulo) . "</option>";
        }
        echo "</select><div class='form-hint'>Lista as admissões do plugin RH que ainda não têm ciclo de entrega. Os campos em branco abaixo são preenchidos com os dados da admissão.</div></div>";
        echo "<div class='col-12 col-md-4 d-flex align-items-center'>" . (count($admissoes) ? "<span class='badge bg-blue-lt text-blue'>" . count($admissoes) . " admissão(ões) disponível(is)</span>" : "<span class='text-secondary small'>Nenhuma admissão pendente no RH.</span>") . "</div>";
    } else {
        echo "<div class='col-12 text-secondary'>O plugin RH não está ativo. Preencha os dados do colaborador manualmente.</div>";
    }

    echo "<div class='col-12 cf-section'><i class='ti ti-id-badge me-1' aria-hidden='true'></i>Colaborador e equipamentos</div>";
    foreach (Fluxo::campos(1) as [$key, $label, $tipo, $obrig]) {
        echo Tela::campo($key, $label, $tipo, $obrig, (string) ($preenchido[$key] ?? ''));
    }
    echo "</div><div class='card-footer d-flex justify-content-end'><button type='submit' class='btn btn-primary'><i class='ti ti-player-play me-1' aria-hidden='true'></i>Iniciar ciclo</button></div></form>";
    Menu::footer();
    exit;
}

// ------------------------------------------------------------ ciclo existente (layout do Ciclo Financeiro)
$ciclo = $id ? Entrega::buscar($id) : null;
if (!$ciclo) {
    echo Menu::title('Ciclo não encontrado', '', $lista);
    echo "<div class='alert alert-warning' role='alert'>O ciclo não existe ou você não tem acesso à entidade dele.</div>";
    Menu::footer();
    exit;
}

$cadastro = [
    ['Colaborador', $ciclo['name']],
    ['Setor', $ciclo['setor']],
    ['Cargo', $ciclo['cargo']],
    ['Centro de custo', $ciclo['centro_custo']],
    ['Data de início', Menu::date($ciclo['data_inicio'])],
    ['Equipamentos', $ciclo['equipamentos']],
    ['Local de entrega', $ciclo['local_entrega']],
    ['Admissão no RH', $ciclo['rh_admissoes_id'] ? '#' . (int) $ciclo['rh_admissoes_id'] : 'Manual'],
];
$devolucoes = count(array_filter(Entrega::etapas($id), fn ($v) => $v['action'] === 'devolvida'));
Tela::cabecalho(Entrega::class, $ciclo, [
    'subtitulo' => 'Entrega de equipamento · ' . ($ciclo['setor'] ?: ''),
    'extra1' => ['Devoluções para Compras', (string) $devolucoes],
    'extra2' => ['Equipamentos', $ciclo['equipamentos'] ? mb_strimwidth((string) $ciclo['equipamentos'], 0, 28, '…') : '—'],
]);
Tela::esteira(Entrega::class, $ciclo);
Tela::etapaAtual(Entrega::class, $ciclo, $preenchido, $erros);
Tela::dadosCiclo(Entrega::class, $ciclo, $cadastro);
Tela::linhaDoTempo(Entrega::class, $id);

Menu::footer();
