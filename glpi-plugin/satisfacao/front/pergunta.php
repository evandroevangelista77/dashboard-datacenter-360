<?php

/** Perguntas da pesquisa: criar, editar, reordenar, ativar e desativar. */

use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Question;
use GlpiPlugin\Satisfacao\Survey;

Session::checkRight(Menu::$rightname, UPDATE);

global $DB;
$question = new Question();

if (isset($_POST['save'])) {
    $input = [
        'name'        => $_POST['name'] ?? '',
        'type'        => $_POST['type'] ?? 'rating',
        'is_required' => $_POST['is_required'] ?? 0,
        'is_active'   => $_POST['is_active'] ?? 0,
    ];
    if (!empty($_POST['id'])) {
        if ($question->getFromDB((int) $_POST['id'])) {
            $question->update(['id' => $question->getID()] + $input);
            Session::addMessageAfterRedirect('Pergunta salva. Vale para as pesquisas enviadas daqui em diante.');
        }
    } elseif ($question->add($input)) {
        Session::addMessageAfterRedirect('Pergunta criada.');
    }
    Html::redirect(Menu::url('/front/pergunta.php'));
}

if (isset($_POST['move']) && $question->getFromDB((int) $_POST['id'])) {
    $question->move((int) $_POST['move']);
    Html::redirect(Menu::url('/front/pergunta.php'));
}

if (isset($_POST['toggle']) && $question->getFromDB((int) $_POST['id'])) {
    $question->update(['id' => $question->getID(), 'is_active' => $question->fields['is_active'] ? 0 : 1]);
    Html::redirect(Menu::url('/front/pergunta.php'));
}

$editing = null;
if (isset($_GET['edit']) && $question->getFromDB((int) $_GET['edit'])) {
    $editing = $question->fields;
}

// quantas respostas cada pergunta já recebeu (perguntas com resposta não se apagam, só se desativam)
$answers = [];
foreach ($DB->request(['SELECT' => ['questions_id', 'COUNT' => 'id AS n'], 'FROM' => Survey::ANSWERS_TABLE, 'GROUPBY' => 'questions_id']) as $row) {
    $answers[(int) $row['questions_id']] = (int) $row['n'];
}

Menu::header('Perguntas');
echo "<div class='sf'>";
echo Menu::title('Perguntas do CSAT', 'Edite o texto, o tipo e a ordem. Mudanças valem para as pesquisas enviadas daqui em diante; as respostas antigas mantêm a pergunta como foi feita.',
    Menu::button('Painel', Menu::url('/front/painel.php'), 'ti-chart-bar', 'btn-outline-secondary'));

$rows = Question::all();
echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-hover mb-0'>";
echo "<thead><tr><th style='width:1%'>Ordem</th><th>Pergunta</th><th>Tipo</th><th>Obrigatória</th><th>Situação</th><th class='text-end'>Respostas</th><th></th></tr></thead><tbody>";
if (!$rows) {
    echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Nenhuma pergunta cadastrada.</td></tr>";
}
$last = count($rows) - 1;
foreach ($rows as $i => $q) {
    $id = (int) $q['id'];
    $posts = "<form method='post' class='d-inline'><input type='hidden' name='id' value='$id'>";
    echo "<tr" . (!$q['is_active'] ? " class='text-secondary'" : '') . ">";
    echo "<td class='text-nowrap'>"
        . ($i > 0 ? "<button class='btn btn-sm btn-ghost-secondary' name='move' value='-1' form='mv$id' title='Subir'><i class='ti ti-arrow-up'></i></button>" : '')
        . ($i < $last ? "<button class='btn btn-sm btn-ghost-secondary' name='move' value='1' form='mv$id' title='Descer'><i class='ti ti-arrow-down'></i></button>" : '')
        . "<form id='mv$id' method='post'><input type='hidden' name='id' value='$id'></form></td>";
    echo "<td>" . Menu::e($q['name']) . "</td>";
    echo "<td>" . Menu::e(Question::typeLabel($q['type'])) . "</td>";
    echo "<td>" . ($q['is_required'] ? 'Sim' : 'Não') . "</td>";
    echo "<td>" . ($q['is_active'] ? "<span class='badge bg-green-lt'>Ativa</span>" : "<span class='badge bg-secondary-lt'>Desativada</span>") . "</td>";
    echo "<td class='text-end'>" . ($answers[$id] ?? 0) . "</td>";
    echo "<td class='text-end text-nowrap'>"
        . "<a class='btn btn-sm btn-outline-primary' href='" . Menu::e(Menu::url('/front/pergunta.php?edit=' . $id)) . "'><i class='ti ti-pencil'></i> Editar</a> "
        . "$posts<button class='btn btn-sm btn-outline-secondary' name='toggle' value='1'>" . ($q['is_active'] ? 'Desativar' : 'Ativar') . "</button></form>"
        . "</td></tr>";
}
echo "</tbody></table></div></div>";

$v = fn (string $key, $default = '') => Menu::e($editing[$key] ?? $default);
$typeOptions = '';
foreach (Question::TYPES as $key => $label) {
    $typeOptions .= "<option value='$key'" . (($editing['type'] ?? 'rating') === $key ? ' selected' : '') . '>' . Menu::e($label) . '</option>';
}
echo "<form method='post' class='card'><div class='card-header'><h3 class='card-title'>" . ($editing ? 'Editar pergunta' : 'Nova pergunta') . "</h3></div>";
echo "<div class='card-body'>";
if ($editing) {
    echo "<input type='hidden' name='id' value='" . (int) $editing['id'] . "'>";
}
echo "<div class='mb-3'><label class='form-label'>Texto da pergunta</label><input class='form-control' name='name' maxlength='255' required value='" . $v('name') . "'></div>";
echo "<div class='row'><div class='col-md-6 mb-3'><label class='form-label'>Tipo de resposta</label><select class='form-select' name='type'>$typeOptions</select></div>";
echo "<div class='col-md-6 mb-3 d-flex flex-column justify-content-end gap-2'>"
    . "<label class='form-check mb-0'><input class='form-check-input' type='checkbox' name='is_required' value='1'" . (($editing['is_required'] ?? 0) ? ' checked' : '') . "><span class='form-check-label'>Obrigatória</span></label>"
    . "<label class='form-check mb-0'><input class='form-check-input' type='checkbox' name='is_active' value='1'" . (($editing['is_active'] ?? 1) ? ' checked' : '') . "><span class='form-check-label'>Ativa (aparece nas pesquisas)</span></label>"
    . "</div></div>";
echo "<p class='text-secondary small mb-0'>Nota: estrelas (1 a 5) e recomendação (0 a 10) geram a média e o NPS no painel. Comentário livre não entra nas médias.</p>";
echo "</div><div class='card-footer text-end'>"
    . ($editing ? "<a class='btn btn-link' href='" . Menu::e(Menu::url('/front/pergunta.php')) . "'>Cancelar</a> " : '')
    . "<button class='btn btn-primary' type='submit' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>" . ($editing ? 'Salvar' : 'Adicionar') . "</button></div></form>";
echo "</div>";
Html::footer();
