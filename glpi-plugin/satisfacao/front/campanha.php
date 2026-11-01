<?php

/** Campanhas de eNPS (clima): criar, enviar, lembrar quem não respondeu, encerrar. */

use GlpiPlugin\Satisfacao\Campaign;
use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Survey;

Session::checkRight(Menu::$rightname, UPDATE);

global $DB;
$back = Menu::url('/front/campanha.php');
$campaign = new Campaign();
$load = fn () => $campaign->getFromDB((int) ($_POST['id'] ?? 0));

if (isset($_POST['save'])) {
    $input = [
        'name'             => $_POST['name'] ?? '',
        'question'         => $_POST['question'] ?? '',
        'comment_question' => $_POST['comment_question'] ?? '',
        'groups'           => $_POST['groups'] ?? [],
        'emails'           => $_POST['emails'] ?? '',
    ];
    if (!empty($_POST['id'])) {
        if ($load() && (int) $campaign->fields['status'] === Campaign::DRAFT) {
            $campaign->update(['id' => $campaign->getID()] + $input);
            Session::addMessageAfterRedirect('Campanha salva.');
        }
    } elseif ($campaign->add($input + ['entities_id' => (int) ($_SESSION['glpiactive_entity'] ?? 0)])) {
        Session::addMessageAfterRedirect('Campanha criada como rascunho. Confira os destinatários e envie.');
    }
    Html::redirect($back);
}
if (isset($_POST['send']) && $load()) {
    $n = $campaign->send();
    Session::addMessageAfterRedirect($n ? "Campanha enviada: $n pesquisa(s) criada(s)." : 'Nenhum destinatário novo para enviar.', false, $n ? INFO : WARNING);
    Html::redirect($back);
}
if (isset($_POST['remind']) && $load()) {
    Session::addMessageAfterRedirect($campaign->remind() . ' lembrete(s) enviado(s).');
    Html::redirect($back);
}
if (isset($_POST['close']) && $load()) {
    $campaign->update(['id' => $campaign->getID(), 'status' => Campaign::CLOSED, 'date_close' => date('Y-m-d H:i:s')]);
    Session::addMessageAfterRedirect('Campanha encerrada: ela não aceita mais respostas.');
    Html::redirect($back);
}
if (isset($_POST['delete']) && $load() && (int) $campaign->fields['status'] === Campaign::DRAFT) {
    $campaign->delete(['id' => $campaign->getID()], true);
    Session::addMessageAfterRedirect('Rascunho excluído.');
    Html::redirect($back);
}

$editing = null;
if (isset($_GET['edit']) && $campaign->getFromDB((int) $_GET['edit']) && (int) $campaign->fields['status'] === Campaign::DRAFT) {
    $editing = $campaign->fields;
}
$e = fn ($v) => Menu::e($v);

// participação de cada campanha
$stats = [];
foreach ($DB->request(['SELECT' => ['items_id', 'status', 'COUNT' => 'id AS n'], 'FROM' => Survey::getTable(), 'WHERE' => ['itemtype' => Survey::ENPS], 'GROUPBY' => ['items_id', 'status']]) as $r) {
    $stats[(int) $r['items_id']][(int) $r['status']] = (int) $r['n'];
}

Menu::header('Campanhas de eNPS');
echo "<div class='sf'>";
echo Menu::title('Campanhas de e-NPS', 'O eNPS mede o clima: o quanto a equipe recomendaria a empresa como lugar para trabalhar. A resposta é anônima; ninguém vê quem respondeu o quê.',
    Menu::button('Painel do eNPS', Menu::url('/front/enps.php'), 'ti-chart-bar', 'btn-outline-secondary'));

echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-hover card-table table-vcenter'>";
echo "<thead><tr><th>Campanha</th><th>Situação</th><th class='text-end'>Enviadas</th><th class='text-end'>Respondidas</th><th style='width:18%'>Participação</th><th></th></tr></thead><tbody>";
$rows = iterator_to_array($DB->request(['FROM' => Campaign::getTable(), 'ORDER' => ['id DESC']]));
if (!$rows) {
    echo "<tr><td colspan='6' class='text-center text-secondary py-4'>Nenhuma campanha ainda. Crie a primeira abaixo.</td></tr>";
}
foreach ($rows as $c) {
    $id = (int) $c['id'];
    $sent = array_sum($stats[$id] ?? []);
    $answered = $stats[$id][Survey::ANSWERED] ?? 0;
    $rate = $sent ? (int) round(100 * $answered / $sent) : 0;
    [$label, $color] = Campaign::STATUSES[(int) $c['status']];
    $form = fn (string $name, string $text, string $cls = 'btn-outline-secondary', string $confirm = '') => "<form method='post' class='d-inline' action='" . $e($back) . "'"
        . ($confirm ? " onsubmit=\"return confirm('" . $e($confirm) . "')\"" : '') . "><input type='hidden' name='id' value='$id'><button class='btn btn-sm $cls' name='$name' value='1'>$text</button></form> ";
    $actions = match ((int) $c['status']) {
        Campaign::DRAFT  => "<a class='btn btn-sm btn-outline-primary' href='" . $e(Menu::url('/front/campanha.php?edit=' . $id)) . "'><i class='ti ti-pencil'></i> Editar</a> "
            . $form('send', '<i class="ti ti-send"></i> Enviar', 'btn-primary', 'Enviar agora para todos os destinatários?') . $form('delete', 'Excluir', 'btn-outline-danger', 'Excluir este rascunho?'),
        Campaign::SENT   => $form('remind', 'Lembrar quem não respondeu', 'btn-outline-primary', 'Reenviar o e-mail para quem ainda não respondeu?') . $form('close', 'Encerrar', 'btn-outline-secondary', 'Encerrar? A campanha deixa de aceitar respostas.'),
        default          => '',
    };
    echo "<tr><td><b>" . $e($c['name']) . "</b><div class='small text-secondary'>" . $e(mb_strimwidth((string) $c['question'], 0, 90, '…')) . "</div></td>"
        . "<td><span class='badge bg-$color-lt'>" . $e($label) . "</span>" . ($c['date_sent'] ? "<div class='small text-secondary'>" . $e(date('d/m/Y', strtotime($c['date_sent']))) . "</div>" : '') . "</td>"
        . "<td class='text-end'>$sent</td><td class='text-end'>$answered</td>"
        . "<td>" . ($sent ? "<div class='sf-pct'><i style='width:$rate%'></i><span>$rate%</span></div>" : "<span class='text-secondary small'>—</span>") . "</td>"
        . "<td class='text-end text-nowrap'>$actions</td></tr>";
}
echo "</tbody></table></div></div>";

// formulário
$groupOptions = '';
$chosen = $editing ? Survey::idList((string) $editing['groups']) : [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename']) as $g) {
    $groupOptions .= "<option value='" . (int) $g['id'] . "'" . (in_array((int) $g['id'], $chosen, true) ? ' selected' : '') . '>' . $e($g['completename']) . '</option>';
}
$preview = '';
if ($editing) {
    $preview = "<div class='alert alert-info mb-0'><div><b>" . count($campaign->recipients()) . " destinatário(s)</b> com e-mail pelos grupos e e-mails escolhidos.</div></div>";
}
echo "<form method='post' action='" . $e($back) . "' class='card'><div class='card-header'><h3 class='card-title'>" . ($editing ? 'Editar campanha' : 'Nova campanha') . "</h3></div><div class='card-body'><div class='row g-3'>";
if ($editing) {
    echo "<input type='hidden' name='id' value='" . (int) $editing['id'] . "'>";
}
echo "<div class='col-12'><label class='form-label'>Nome da campanha</label><input class='form-control' name='name' maxlength='255' required placeholder='eNPS Novembro/2026' value='" . $e($editing['name'] ?? '') . "'></div>";
echo "<div class='col-md-6'><label class='form-label'>Pergunta (nota de 0 a 10)</label><textarea class='form-control' rows='3' name='question' maxlength='500'>" . $e($editing['question'] ?? Campaign::DEFAULT_QUESTION) . "</textarea></div>";
echo "<div class='col-md-6'><label class='form-label'>Pergunta do comentário (opcional para quem responde)</label><textarea class='form-control' rows='3' name='comment_question' maxlength='500'>" . $e($editing['comment_question'] ?? Campaign::DEFAULT_COMMENT) . "</textarea></div>";
echo "<div class='col-md-6'><label class='form-label'>Grupos que recebem</label><select class='form-select' name='groups[]' multiple size='7'>$groupOptions</select>"
    . "<div class='form-hint'>Usuários ativos com e-mail desses grupos. O grupo aparece só no painel, e só quando tem " . Survey::ENPS_MIN_GROUP . " respostas ou mais.</div></div>";
echo "<div class='col-md-6'><label class='form-label'>E-mails extras</label><textarea class='form-control' rows='6' name='emails' placeholder='fulano@empresa.com, ciclano@empresa.com'>" . $e($editing['emails'] ?? '') . "</textarea>"
    . "<div class='form-hint'>Separe por vírgula, ponto e vírgula ou espaço.</div></div>";
if ($preview) {
    echo "<div class='col-12'>$preview</div>";
}
echo "</div></div><div class='card-footer text-end'>" . ($editing ? "<a class='btn btn-link' href='" . $e($back) . "'>Cancelar</a> " : '')
    . "<button class='btn btn-primary' type='submit' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>" . ($editing ? 'Salvar' : 'Criar rascunho') . "</button></div></form>";
echo "</div>";
Html::footer();
