<?php

/**
 * Resposta da pesquisa pelo link do e-mail. O token (aleatório, de uso único) diz
 * qual pesquisa é; cada pesquisa aceita uma única resposta.
 */

use GlpiPlugin\Satisfacao\Campaign;
use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Question;
use GlpiPlugin\Satisfacao\Survey;

$token = (string) ($_REQUEST['token'] ?? '');
$survey = Survey::byToken($token);

$isEnps = $survey && $survey->fields['itemtype'] === Survey::ENPS;
$error = '';
$thanks = false;
if ($survey && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = $isEnps ? $survey->saveEnps($_POST) : $survey->saveAnswers($_POST);
    $thanks = $error === '';
}

Html::header('Pesquisa de satisfação', '', 'plugins');
echo Menu::css();

echo "<div class='sf-respond'>";
if (!$survey) {
    echo "<div class='card sf-card'><div class='card-body'><h2>Pesquisa não encontrada</h2>"
        . "<p class='text-secondary mb-0'>O link pode estar incompleto ou a pesquisa já não existe.</p></div></div>";
} elseif ($thanks || (int) $survey->fields['status'] === Survey::ANSWERED) {
    echo "<div class='card sf-card'><div class='card-body text-center'><i class='ti ti-circle-check sf-ok'></i>"
        . "<h2>Obrigado pela sua resposta!</h2><p class='text-secondary mb-0'>" . ($isEnps ? 'Sua resposta é anônima e ajuda a melhorar o clima da equipe.' : 'Sua opinião ajuda a melhorar o atendimento.') . "</p></div></div>";
} elseif ($isEnps) {
    $campaign = new Campaign();
    $campaign->getFromDB((int) $survey->fields['items_id']);
    echo "<form method='post' action='" . Menu::e(Menu::url('/front/responder.php')) . "' class='card sf-card'>";
    echo "<input type='hidden' name='token' value='" . Menu::e($token) . "'><div class='card-body'>";
    echo "<div class='sf-crumb'>eNPS · clima da equipe</div><h2>" . Menu::e($survey->fields['name']) . "</h2>";
    echo "<p class='text-secondary'>🔒 Sua resposta é <b>anônima</b>: ninguém vê quem respondeu o quê.</p>";
    if ($error !== '') {
        echo "<div class='alert alert-danger'>" . Menu::e($error) . "</div>";
    }
    echo "<div class='sf-q'><label class='fw-bold mb-2'>" . Menu::e($campaign->fields['question'] ?? Campaign::DEFAULT_QUESTION) . " <span class='text-danger'>*</span></label>"
        . sfOptions('score', array_combine(range(0, 10), range(0, 10)), 'sf-nps', true)
        . "<div class='sf-nps-ends'><span>0 · nada provável</span><span>10 · muito provável</span></div></div>";
    echo "<div class='sf-q'><label class='fw-bold mb-2'>" . Menu::e($campaign->fields['comment_question'] ?? Campaign::DEFAULT_COMMENT) . "</label>"
        . "<textarea class='form-control' rows='4' name='comment' maxlength='5000'></textarea></div>";
    echo "</div><div class='card-footer text-end'><button type='submit' class='btn btn-primary'>Enviar resposta</button></div></form>";
} else {
    $questions = Question::active();
    echo "<form method='post' action='" . Menu::e(Menu::url('/front/responder.php')) . "' class='card sf-card'>";
    echo "<input type='hidden' name='token' value='" . Menu::e($token) . "'>";
    echo "<div class='card-body'>";
    echo "<div class='sf-crumb'>" . Menu::e(Survey::ORIGINS[$survey->fields['itemtype']] ?? 'Pesquisa') . "</div>";
    echo "<h2>" . Menu::e($survey->fields['name']) . "</h2>";
    echo "<p class='text-secondary'>Leva menos de um minuto. Os campos com * são obrigatórios.</p>";
    if ($error !== '') {
        echo "<div class='alert alert-danger'>" . Menu::e($error) . "</div>";
    }
    foreach ($questions as $q) {
        $name = 'q' . $q['id'];
        $required = (bool) $q['is_required'];
        echo "<div class='sf-q'><label class='fw-bold mb-2'>" . Menu::e($q['name']) . ($required ? " <span class='text-danger'>*</span>" : '') . '</label>';
        echo match ($q['type']) {
            'rating' => sfOptions($name, array_map(fn ($n) => "<b>" . Survey::FACES[$n] . "</b><small>" . Survey::FACE_NAMES[$n] . "</small>", array_combine(range(1, 5), range(1, 5))), 'sf-faces', $required, true),
            'nps'    => sfOptions($name, array_combine(range(0, 10), range(0, 10)), 'sf-nps', $required),
            'yesno'  => sfOptions($name, [1 => '👍 Sim', 0 => '👎 Não'], 'sf-yesno', $required),
            default  => "<textarea class='form-control' rows='3' name='$name' maxlength='5000'></textarea>",
        };
        echo "</div>";
    }
    echo "</div><div class='card-footer text-end'><button type='submit' class='btn btn-primary'>Enviar resposta</button></div></form>";
}
echo "</div>";
Html::footer();

/**
 * Botões de escolha única: $options é (valor => texto), $required diz se a pergunta obriga a escolha
 * e $html aceita rótulos com marcação (as carinhas, já montadas aqui).
 */
function sfOptions(string $name, array $options, string $class, bool $required, bool $html = false): string
{
    $out = "<div class='sf-options $class'>";
    foreach ($options as $value => $label) {
        $out .= "<label class='sf-opt'><input type='radio' name='" . Menu::e($name) . "' value='" . Menu::e($value) . "'" . ($required ? ' required' : '')
            . "><span>" . ($html ? $label : Menu::e($label)) . '</span></label>';
    }
    return $out . '</div>';
}
