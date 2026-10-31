<?php

use GlpiPlugin\Gestaoeventos\Abas;
use GlpiPlugin\Gestaoeventos\Acesso;
use GlpiPlugin\Gestaoeventos\Alerta;
use GlpiPlugin\Gestaoeventos\Aprovacao;
use GlpiPlugin\Gestaoeventos\Documentos;
use GlpiPlugin\Gestaoeventos\Encerramento;
use GlpiPlugin\Gestaoeventos\Evento;
use GlpiPlugin\Gestaoeventos\Fase;
use GlpiPlugin\Gestaoeventos\Financeiro;
use GlpiPlugin\Gestaoeventos\Historico;
use GlpiPlugin\Gestaoeventos\Importacao;
use GlpiPlugin\Gestaoeventos\Menu;
use GlpiPlugin\Gestaoeventos\Registro;
use GlpiPlugin\Gestaoeventos\Viagem;

Session::checkRight(Evento::$rightname, READ);
$evento = new Evento();

/** Carrega o evento do POST; $edit confere se o usuário altera os dados dele. */
$load = function (bool $edit = true) use ($evento): void {
    if (!$evento->getFromDB((int) ($_POST['id'] ?? 0)) || !$evento->canViewItem()) {
        Html::displayNotFoundError();
    }
    if ($edit && !$evento->canEditData()) {
        Html::displayRightError();
    }
};

/** Volta para a aba de onde veio (só endereços deste plugin); senão, para a página anterior. */
$back = function (): void {
    $to = (string) ($_POST['_back'] ?? '');
    if ($to !== '' && str_starts_with($to, '/') && !str_starts_with($to, '//') && str_contains($to, '/plugins/gestaoeventos/front/')) {
        Html::redirect($to);
    }
    Html::back();
};

/** Exige o direito de Editar do módulo (além de poder editar o evento). */
$need = function (string $module): void {
    Acesso::requireEdit($module);
};

try {
    if (isset($_POST['add'])) {
        $evento->check(-1, CREATE, $_POST);
        if ($id = $evento->add($_POST)) {
            Session::addMessageAfterRedirect('Rascunho salvo. Complete o briefing e envie à triagem do Marketing.');
            Html::redirect(Evento::getFormURLWithID($id));
        }
        Html::back();
    } elseif (isset($_POST['update'])) {
        $load();
        $evento->update(array_intersect_key($_POST, array_flip([
            'id', 'name', 'event_type', 'users_id_owner', 'users_id_gp', 'requesting_area', 'partner', 'commercial_priority', 'target_audience', 'objective', 'scope', 'audience',
            'date_start', 'date_finish', 'date_critical', 'format', 'city', 'venue', 'address', 'venue_defined', 'need_structure', 'need_coffee', 'need_meals', 'need_travel', 'need_lodging',
            'need_transport', 'need_gifts', 'need_av', 'need_comm', 'need_suppliers', 'budget_estimated', 'sponsorship_estimated', 'cc_number', 'cc_description', 'participation_url',
            'projects_id', 'sharepoint_url', 'comment',
        ])));
        Html::back();
    } elseif (isset($_POST['purge'])) {
        $evento->check((int) $_POST['id'], PURGE);
        $evento->delete(['id' => (int) $_POST['id']], true);
        Html::redirect(Evento::getSearchURL());
    } elseif (isset($_POST['workflow'])) {
        $load(false);
        Session::addMessageAfterRedirect(htmlspecialchars(Fase::workflow($evento, (string) $_POST['workflow'], $_POST)));
        $back();
    } elseif (isset($_POST['reg_save'])) {
        $load();
        $regId = Registro::save($evento, (array) ($_POST['reg'] ?? []));
        if (!empty($_FILES['reg_image']['name'])) {
            Registro::attachImage($evento, $regId, (array) $_FILES['reg_image']);
        }
        $back();
    } elseif (isset($_POST['reg_status'])) {
        $load();
        Registro::setStatus($evento, (int) ($_POST['reg_id'] ?? 0), (string) $_POST['reg_status']);
        $back();
    } elseif (isset($_POST['reg_action'])) {
        $load();
        Session::addMessageAfterRedirect(htmlspecialchars(Registro::action($evento, (int) ($_POST['reg_id'] ?? 0), (string) $_POST['reg_action'])));
        $back();
    } elseif (isset($_POST['reg_delete'])) {
        $load();
        Registro::remove($evento, (int) ($_POST['reg_id'] ?? 0));
        $back();
    } elseif (isset($_POST['approval_request'])) {
        $load();
        $need('aprovacoes');
        $kind = (string) ($_POST['kind'] ?? '');
        $f = $evento->fields;
        if (!in_array($kind, ['entrada', 'orcamento', 'excecao', 'mudanca', 'revisao'], true)) {
            throw new RuntimeException('Tipo de aprovação inválido.');
        }
        $amount = Menu::parseMoney((string) ($_POST['amount'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($kind === 'entrada') {
            if ($f['is_draft'] || $f['triage_status'] !== 'aceito') {
                throw new RuntimeException('A aprovação de entrada só pode ser pedida depois que o Marketing aceitar a triagem.');
            }
            $amount = $amount ?: (float) $f['budget_estimated'];
            $title = 'Participar e investir em ' . $f['name'];
        } elseif ($kind === 'orcamento') {
            if (!Evento::canFinance()) {
                throw new RuntimeException('O orçamento base é pedido pelo Financeiro.');
            }
            $amount = Financeiro::budgetTotal($evento->getID());
            if ($amount <= 0) {
                throw new RuntimeException('Cadastre o Budget de despesas na aba Financeiro antes de pedir a aprovação.');
            }
            $title = 'Orçamento base de ' . $f['name'];
        } else {
            $title = $reason !== '' ? mb_substr($reason, 0, 120) : Aprovacao::KINDS[$kind];
        }
        Aprovacao::request($evento, $kind, $title, $amount, 0, $reason);
        Session::addMessageAfterRedirect('Aprovação solicitada. Os aprovadores da alçada foram avisados.');
        $back();
    } elseif (isset($_POST['approval_decide'])) {
        $load(false);
        Session::addMessageAfterRedirect(htmlspecialchars(Aprovacao::decide($evento, (int) ($_POST['approval_id'] ?? 0), (string) $_POST['approval_decide'], (string) ($_POST['justification'] ?? ''))));
        $back();
    } elseif (isset($_POST['approval_delegate'])) {
        $load(false);
        $need('aprovacoes');
        Aprovacao::delegate($evento, (int) ($_POST['approval_id'] ?? 0), (int) ($_POST['delegate_to'] ?? 0), (string) ($_POST['delegate_until'] ?? ''));
        Session::addMessageAfterRedirect('Aprovação delegada.');
        $back();
    } elseif (isset($_POST['plan_action'])) {
        $load();
        $need(in_array($_POST['plan_action'], ['checklist_seed'], true) ? 'checklists' : 'planejamento');
        Session::addMessageAfterRedirect(htmlspecialchars(Abas::planAction($evento, (string) $_POST['plan_action'], $_POST)));
        $back();
    } elseif (isset($_POST['viagem_action'])) {
        $load();
        Session::addMessageAfterRedirect(htmlspecialchars(Viagem::action($evento, (string) $_POST['viagem_action'], $_POST)));
        $back();
    } elseif (isset($_POST['close_action'])) {
        $load();
        Session::addMessageAfterRedirect(htmlspecialchars(Encerramento::action($evento, (string) $_POST['close_action'])));
        $back();
    } elseif (isset($_POST['import_convidados'])) {
        $load();
        $need('marketing');
        Session::addMessageAfterRedirect(htmlspecialchars(Importacao::convidados($evento, (array) ($_FILES['convidados_xlsx'] ?? []))));
        $back();
    } elseif (isset($_POST['alerta_save'])) {
        $load();
        $need('avisos');
        Alerta::save($evento->getID(), (array) ($_POST['al'] ?? []));
        Historico::record($evento->getID(), null, 'change', 'Regra de avisos do evento personalizada.');
        Session::addMessageAfterRedirect('Regra de avisos do evento salva.');
        $back();
    } elseif (isset($_POST['alerta_reset'])) {
        $load();
        $need('avisos');
        Alerta::reset($evento->getID());
        Session::addMessageAfterRedirect('O evento voltou a usar a regra padrão de avisos.');
        $back();
    } elseif (isset($_POST['sharepoint_save'])) {
        $load();
        $need('documentos');
        $evento->update(['id' => $evento->getID(), 'sharepoint_url' => (string) ($_POST['sharepoint_url'] ?? '')]);
        Historico::record($evento->getID(), null, 'integration', 'Pasta SharePoint do evento atualizada.');
        $back();
    } elseif (isset($_POST['doc_upload'])) {
        $load();
        $need('documentos');
        $n = Documentos::upload($evento, (array) ($_FILES['docs'] ?? []), (int) ($_POST['doc_category'] ?? 0), (string) ($_POST['doc_code'] ?? ''), trim((string) ($_POST['doc_note'] ?? '')));
        Session::addMessageAfterRedirect($n ? "$n documento(s) anexado(s)." : 'Escolha pelo menos um arquivo.', false, $n ? INFO : WARNING);
        Fase::maybeAdvance($evento);
        Html::back();
    } elseif (isset($_POST['doc_remove'])) {
        $load();
        $need('documentos');
        Documentos::remove($evento, (int) ($_POST['doc_id'] ?? 0));
        Html::back();
    }
} catch (RuntimeException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    $back();
}

$id = (int) ($_GET['id'] ?? 0);
$evento->check($id ?: -1, $id ? READ : CREATE);
Menu::header(Evento::getTypeName(2), 'evento');
$evento->display(['id' => $id]);
Html::footer();
