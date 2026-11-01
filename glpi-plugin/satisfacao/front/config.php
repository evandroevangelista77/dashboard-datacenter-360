<?php

/**
 * Quando a pesquisa é enviada: o que dispara (chamado fechado ou resolvido, projeto finalizado,
 * evento e ciclo concluídos) e para quais entidades, categorias e tipos de solução.
 */

use GlpiPlugin\Satisfacao\Menu;
use GlpiPlugin\Satisfacao\Notifications;
use GlpiPlugin\Satisfacao\Survey;

Session::checkRight('config', UPDATE);

$ids = fn ($v) => implode(',', array_filter(array_map('intval', (array) $v)));
$emails = fn ($v) => implode(', ', Survey::splitEmails((string) $v));
$flag = fn (string $key) => empty($_POST[$key]) ? '0' : '1';

if (isset($_POST['save'])) {
    Config::setConfigurationValues('plugin:satisfacao', [
        'email_enabled'   => $flag('email_enabled'),
        'email_questions' => $flag('email_questions'),
        'entities'        => $ids($_POST['entities'] ?? []),
        'categories'      => $ids($_POST['categories'] ?? []),
        'solutions'       => $ids($_POST['solutions'] ?? []),
        'on_solved'       => $flag('on_solved'),
        'project_enabled' => $flag('project_enabled'),
        'project_emails'  => $emails($_POST['project_emails'] ?? ''),
        'event_enabled'   => $flag('event_enabled'),
        'event_emails'    => $emails($_POST['event_emails'] ?? ''),
        'ciclo_enabled'   => $flag('ciclo_enabled'),
        'cycle_emails'    => $emails($_POST['cycle_emails'] ?? ''),
    ]);
    // assunto e texto do e-mail ficam no modelo de notificação do GLPI
    foreach ([Notifications::EVENT => ['email', 'restore_email'], Notifications::EVENT_ENPS => ['enps', 'restore_enps']] as $event => [$field, $restore]) {
        $subject = trim((string) ($_POST[$field . '_subject'] ?? ''));
        $body = trim((string) ($_POST[$field . '_body'] ?? ''));
        if (!empty($_POST[$restore])) {
            [, $defaultSubject, $defaultBody] = Notifications::defaults($event);
            Notifications::saveTemplate($defaultSubject, $defaultBody, $event);
        } elseif ($subject !== '' && $body !== '') {
            Notifications::saveTemplate($subject, $body, $event);
        }
    }
    Session::addMessageAfterRedirect('Configuração salva.');
    Html::redirect(Menu::url('/front/config.php'));
}

global $DB;
$cfg = Config::getConfigurationValues('plugin:satisfacao') + ['email_enabled' => '1', 'email_questions' => '1', 'project_enabled' => '1', 'event_enabled' => '1', 'ciclo_enabled' => '1'];
$template = Notifications::template();
$templateEnps = Notifications::template(Notifications::EVENT_ENPS);
$on = fn (string $key) => ($cfg[$key] ?? '0') === '1' ? ' checked' : '';
$e = fn ($v) => Menu::e($v);

/** Lista de seleção múltipla (vazia vale para todos) com as opções de uma tabela do GLPI. */
$multi = function (string $name, string $table, string $label, string $selected) use ($DB, $e): string {
    $chosen = Survey::idList($selected);
    $html = "<select class='form-select' name='{$name}[]' multiple size='7'>";
    foreach ($DB->request(['SELECT' => ['id', $label], 'FROM' => $table, 'ORDER' => $label]) as $row) {
        $html .= "<option value='" . (int) $row['id'] . "'" . (in_array((int) $row['id'], $chosen, true) ? ' selected' : '') . '>' . $e($row[$label]) . '</option>';
    }
    return $html . '</select>';
};
$switch = fn (string $name, string $text) => "<label class='form-check form-switch mb-2'><input class='form-check-input' type='checkbox' name='$name' value='1'" . $on($name) . ">"
    . "<span class='form-check-label'>$text</span></label>";
$mails = fn (string $name, string $placeholder) => "<label class='form-label'>E-mails que recebem a pesquisa</label>"
    . "<textarea class='form-control' rows='2' name='$name' placeholder='$placeholder'>" . $e($cfg[$name] ?? '') . "</textarea>"
    . "<div class='form-hint'>Separe por vírgula, ponto e vírgula ou espaço.</div>";

Menu::header('Configuração');
echo "<div class='sf'>";
echo Menu::title('Configuração', 'O e-mail da pesquisa e quando ela sai: para quais entidades, categorias e soluções, e em chamados, projetos, eventos e ciclos.');

echo "<form method='post' action='" . $e(Menu::url('/front/config.php')) . "'>";

if (!Notifications::mailEnabled()) {
    echo "<div class='alert alert-warning'><div><b>As notificações por e-mail do GLPI estão desligadas.</b> As pesquisas são criadas e aparecem no painel, mas o e-mail só sai depois de ligar "
        . "<i>Configurar → Notificações → Configuração das notificações por e-mail</i> e de configurar o SMTP.</div></div>";
}
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>✉️ E-mail da pesquisa</h3></div><div class='card-body'>";
echo $switch('email_enabled', 'Enviar o convite por <b>e-mail</b> automaticamente')
    . "<div class='text-secondary small mb-3'>Desligado, a pesquisa é criada mas ninguém recebe e-mail; ela fica como “sem resposta” no painel.</div>";
echo $switch('email_questions', 'Mostrar a <b>lista de perguntas</b> no corpo do e-mail');
if ($template) {
    echo "<div class='row g-3 mt-1'><div class='col-12'><label class='form-label'>Assunto</label><input class='form-control' name='email_subject' maxlength='255' value='" . $e($template['subject']) . "'></div>"
        . "<div class='col-12'><label class='form-label'>Texto (HTML)</label><textarea class='form-control font-monospace' rows='9' name='email_body'>" . $e($template['html']) . "</textarea>"
        . "<div class='form-hint'>Campos disponíveis: <code>##satisfacao.titulo##</code> (chamado, projeto, evento ou ciclo), <code>##satisfacao.origem##</code>, "
        . "<code>##satisfacao.url##</code> (link para responder) e <code>##satisfacao.perguntas##</code> (lista de perguntas). Mantenha o link no texto.</div></div></div>"
        . "<label class='form-check mt-3 mb-0'><input class='form-check-input' type='checkbox' name='restore_email' value='1'><span class='form-check-label'>Restaurar o assunto e o texto padrão ao salvar</span></label>";
} else {
    echo "<div class='text-secondary small mt-2'>O modelo de e-mail não foi encontrado. Reinstale o plugin para recriá-lo.</div>";
}
echo "<div class='text-secondary small mt-3'>O remetente, o servidor SMTP e os destinatários extras ficam em <i>Configurar → Notificações</i>, na notificação “Pesquisa de satisfação”.</div>";
echo "</div></div>";

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>✉️ E-mail do eNPS (clima)</h3></div><div class='card-body'>";
if ($templateEnps) {
    echo "<div class='row g-3'><div class='col-12'><label class='form-label'>Assunto</label><input class='form-control' name='enps_subject' maxlength='255' value='" . $e($templateEnps['subject']) . "'></div>"
        . "<div class='col-12'><label class='form-label'>Texto (HTML)</label><textarea class='form-control font-monospace' rows='7' name='enps_body'>" . $e($templateEnps['html']) . "</textarea>"
        . "<div class='form-hint'>Campos disponíveis: <code>##satisfacao.titulo##</code> (nome da campanha), <code>##satisfacao.pergunta##</code> (pergunta do eNPS) e <code>##satisfacao.url##</code> (link para responder). Mantenha o link no texto.</div></div></div>"
        . "<label class='form-check mt-3 mb-0'><input class='form-check-input' type='checkbox' name='restore_enps' value='1'><span class='form-check-label'>Restaurar o assunto e o texto padrão ao salvar</span></label>";
} else {
    echo "<div class='text-secondary small'>O modelo de e-mail do eNPS não foi encontrado. Reinstale o plugin para recriá-lo.</div>";
}
echo "</div></div>";

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Filtros</h3></div><div class='card-body'><div class='row g-3'>";
echo "<div class='col-md-4'><label class='form-label'>Entidades</label>" . $multi('entities', 'glpi_entities', 'completename', (string) ($cfg['entities'] ?? ''))
    . "<div class='form-hint'>Vale para chamados, projetos, eventos e ciclos. Nada marcado = todas as entidades.</div></div>";
echo "<div class='col-md-4'><label class='form-label'>Categorias do chamado</label>" . $multi('categories', 'glpi_itilcategories', 'completename', (string) ($cfg['categories'] ?? ''))
    . "<div class='form-hint'>Só chamados dessas categorias recebem a pesquisa. Nada marcado = todas.</div></div>";
echo "<div class='col-md-4'><label class='form-label'>Tipos de solução do chamado</label>" . $multi('solutions', 'glpi_solutiontypes', 'name', (string) ($cfg['solutions'] ?? ''))
    . "<div class='form-hint'>Só chamados resolvidos com esses tipos de solução. Nada marcado = todos.</div></div>";
echo "</div></div></div>";

echo "<div class='row g-3 mb-3'>";
echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>💬 Chamados</h3></div><div class='card-body'>"
    . $switch('on_solved', 'Enviar também quando o chamado for <b>resolvido</b> (além de quando for fechado)')
    . "<div class='text-secondary small'>A pesquisa vai ao <b>fechar</b> o chamado, para os solicitantes com e-mail. Cada chamado recebe uma só pesquisa: se for resolvido e depois fechado, não sai outra.</div></div></div></div>";
echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>📁 Projetos</h3></div><div class='card-body'>"
    . $switch('project_enabled', 'Enviar quando o projeto for <b>finalizado</b> (estado marcado como finalizado no GLPI)')
    . $mails('project_emails', 'cliente@empresa.com') . "</div></div></div>";
echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>🎉 Eventos</h3></div><div class='card-body'>"
    . $switch('event_enabled', 'Enviar quando o evento for <b>concluído</b> (plugin Gestão de Eventos)')
    . $mails('event_emails', 'organizador@empresa.com') . "</div></div></div>";
echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>🔄 Ciclo Financeiro</h3></div><div class='card-body'>"
    . $switch('ciclo_enabled', 'Enviar quando o ciclo for <b>concluído</b>')
    . $mails('cycle_emails', 'cliente@empresa.com, comercial@empresa.com') . "</div></div></div>";
echo "</div>";

echo "<div class='text-end'><button class='btn btn-primary' type='submit' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div></form>";
echo "</div>";
Html::footer();
