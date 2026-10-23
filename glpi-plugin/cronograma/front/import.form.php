<?php

use GlpiPlugin\Cronograma\Importer;
use GlpiPlugin\Cronograma\MsProjectReader;

Session::checkLoginUser();

$project = new Project();
$projectId = (int) ($_POST['projects_id'] ?? 0);
if (!$project->getFromDB($projectId) || !$project->canUpdateItem()) {
    Html::displayRightError();
}

$back = $project->getLinkURL() . '&forcetab=' . rawurlencode('GlpiPlugin\\Cronograma\\ProjectTab$1');

try {
    $upload = $_FILES['cronograma'] ?? null;
    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Nenhum arquivo recebido (ou o envio falhou).');
    }
    if ($upload['size'] > 20 * 1024 * 1024) {
        throw new RuntimeException('Arquivo maior que 20 MB.');
    }
    $name = basename((string) $upload['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, MsProjectReader::EXTENSIONS, true)) {
        throw new RuntimeException('Formato não suportado. Envie um .mpp, .mpt ou .xml do MS Project.');
    }

    // cópia de trabalho no tmp do GLPI (o conversor roda como www-data)
    $work = GLPI_TMP_DIR . '/cronograma-' . bin2hex(random_bytes(8)) . '.' . $ext;
    move_uploaded_file($upload['tmp_name'], $work);

    try {
        $data = MsProjectReader::read($work, $name);
        $stats = (new Importer())->apply($project, $data, [
            'update_project_dates' => !empty($_POST['update_project_dates']),
            'team'                 => !empty($_POST['team']),
            'remove_missing'       => !empty($_POST['remove_missing']),
        ]);

        // guarda o arquivo original como documento do projeto
        $prefix = uniqid('', true);
        copy($work, GLPI_TMP_DIR . '/' . $prefix . $name);
        $docId = (int) (new Document())->add([
            'name'             => 'Cronograma - ' . pathinfo($name, PATHINFO_FILENAME),
            'entities_id'      => $project->fields['entities_id'],
            'itemtype'         => Project::class,
            'items_id'         => $projectId,
            '_filename'        => [$prefix . $name],
            '_prefix_filename' => [$prefix],
            '_no_message'      => true,
        ]);

        global $DB;
        $DB->insert('glpi_plugin_cronograma_imports', [
            'projects_id'     => $projectId,
            'users_id'        => Session::getLoginUserID() ?: 0,
            'documents_id'    => $docId,
            'filename'        => $name,
            'ms_project_name' => mb_substr($data['name'], 0, 255),
            'created'         => $stats['created'],
            'updated'         => $stats['updated'],
            'removed'         => $stats['removed'],
            'links'           => $stats['links'],
            'team'            => $stats['team'],
            'warnings'        => implode("\n", $stats['warnings']),
            'date_creation'   => $_SESSION['glpi_currenttime'],
        ]);

        Session::addMessageAfterRedirect(sprintf(
            'Cronograma importado: %d tarefa(s) criada(s), %d atualizada(s), %d removida(s), %d dependência(s), %d membro(s) de equipe.',
            $stats['created'], $stats['updated'], $stats['removed'], $stats['links'], $stats['team']
        ), false, INFO);
        foreach ($stats['warnings'] as $w) {
            Session::addMessageAfterRedirect(htmlspecialchars($w), false, WARNING);
        }
    } finally {
        @unlink($work);
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect('Cronograma: ' . htmlspecialchars($e->getMessage()), false, ERROR);
}

Html::redirect($back);
