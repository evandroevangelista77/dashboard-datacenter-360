<?php

include('../../../inc/includes.php');

use GlpiPlugin\Controleequipamentos\Devolucao;
use GlpiPlugin\Controleequipamentos\Entrega;
use GlpiPlugin\Controleequipamentos\Menu;

Session::checkRight('config', UPDATE);
global $DB;

const CICLOS = ['CE' => [Entrega::class, 'Entrega de equipamento'], 'CD' => [Devolucao::class, 'Devolução de equipamento']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
    $valores = [];
    foreach (CICLOS as $prefixo => [$classe]) {
        $fluxo = $classe::fluxo();
        foreach (range(2, $fluxo::ULTIMA) as $step) {
            $grupo = (int) ($_POST['cfg'][$prefixo][$step]['grupo'] ?? 0);
            $usuario = (int) ($_POST['cfg'][$prefixo][$step]['usuario'] ?? 0);
            $slo = trim((string) ($_POST['cfg'][$prefixo][$step]['slo'] ?? ''));
            $valores[$classe::chaveEtapa($step, 'grupo')] = $grupo > 0 ? (string) $grupo : '';
            $valores[$classe::chaveEtapa($step, 'usuario')] = $usuario > 0 ? (string) $usuario : '';
            $valores[$classe::chaveEtapa($step, 'slo')] = $slo !== '' && is_numeric($slo) ? (string) max(0, (int) $slo) : '';
        }
    }
    Config::setConfigurationValues(Menu::CONFIG_CONTEXT, $valores);
    Session::addMessageAfterRedirect('Fases e SLOs salvos.', false, INFO);
    Html::redirect(Menu::url('/front/config.php'));
}

// opções de grupos e usuários (ativos, da entidade atual)
$grupos = iterator_to_array($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_recursive' => 0] + (new \DbUtils())->getEntitiesRestrictCriteria('glpi_groups', 'entities_id', '', true), 'ORDER' => 'name']), false);
$usuarios = iterator_to_array($DB->request(['SELECT' => ['id', 'realname', 'firstname', 'name'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => 'realname']), false);
$nomeUsuario = fn ($u) => trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: $u['name'];
$atual = Config::getConfigurationValues(Menu::CONFIG_CONTEXT);

Menu::header('Fases e SLOs', 'config');
echo Menu::title('Fases e SLOs', 'Para cada etapa, escolha o grupo que a executa, o responsável (opcional) e o prazo em dias. O responsável recebe o chamado e o aviso da etapa.');

echo "<form method='post' action='" . Menu::e(Menu::url('/front/config.php')) . "' class='card'>" . Menu::csrf();
echo "<div class='table-responsive'><table class='table table-vcenter card-table'><caption class='visually-hidden'>Responsáveis e prazos por etapa</caption><thead><tr>"
    . "<th scope='col'>#</th><th scope='col' style='min-width:220px'>Etapa</th><th scope='col'>Área</th><th scope='col' style='min-width:220px'>Grupo responsável</th><th scope='col' style='min-width:220px'>Responsável</th><th scope='col'>SLO (dias)</th></tr></thead><tbody>";
foreach (CICLOS as $prefixo => [$classe, $titulo]) {
    $fluxo = $classe::fluxo();
    echo "<tr><td colspan='6' class='cf-section border-top'><i class='ti " . ($prefixo === 'CE' ? 'ti-user-plus' : 'ti-package-export') . " me-1' aria-hidden='true'></i>" . Menu::e($titulo) . "</td></tr>";
    foreach (range(2, $fluxo::ULTIMA) as $step) {
        $g = (int) ($atual[$classe::chaveEtapa($step, 'grupo')] ?? 0);
        $u = (int) ($atual[$classe::chaveEtapa($step, 'usuario')] ?? 0);
        $slo = $atual[$classe::chaveEtapa($step, 'slo')] ?? '';
        $slo = $slo === '' ? $fluxo::slo($step) : (int) $slo;
        $nome = "cfg[$prefixo][$step]";
        echo "<tr><td>$step</td><th scope='row' class='fw-medium'>" . Menu::e($fluxo::nome($step)) . "<div class='small text-secondary fw-normal'>" . Menu::e($fluxo::ETAPAS[$step][2] ?? '') . "</div></th><td class='small'>" . Menu::e($fluxo::AREAS[$fluxo::area($step)]) . "</td>"
            . "<td><label class='visually-hidden' for='g-$prefixo-$step'>Grupo da etapa $step</label><select class='form-select form-select-sm' id='g-$prefixo-$step' name='{$nome}[grupo]'><option value=''>Nenhum</option>";
        foreach ($grupos as $gr) {
            echo "<option value='" . (int) $gr['id'] . "'" . ($gr['id'] == $g ? ' selected' : '') . ">" . Menu::e($gr['name']) . "</option>";
        }
        echo "</select></td><td><label class='visually-hidden' for='u-$prefixo-$step'>Responsável da etapa $step</label><select class='form-select form-select-sm' id='u-$prefixo-$step' name='{$nome}[usuario]'><option value=''>Todo o grupo</option>";
        foreach ($usuarios as $us) {
            echo "<option value='" . (int) $us['id'] . "'" . ($us['id'] == $u ? ' selected' : '') . ">" . Menu::e($nomeUsuario($us)) . "</option>";
        }
        echo "</select></td><td style='width:110px'><label class='visually-hidden' for='s-$prefixo-$step'>Prazo da etapa $step, em dias</label>"
            . "<input class='form-control form-control-sm' type='number' min='0' max='365' step='1' id='s-$prefixo-$step' name='{$nome}[slo]' value='" . (int) $slo . "'></td></tr>";
    }
}
echo "</tbody></table></div>"
    . "<div class='card-footer d-flex align-items-center gap-3'><button type='submit' class='btn btn-primary'><i class='ti ti-device-floppy me-1' aria-hidden='true'></i>Salvar</button>"
    . "<span class='small text-secondary'>Os prazos valem para as etapas que começarem daqui em diante. SLO 0 = sem prazo.</span></div></form>";

Menu::footer();
