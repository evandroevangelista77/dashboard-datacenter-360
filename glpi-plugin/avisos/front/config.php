<?php

/**
 * Configuração dos avisos: pop-up, intervalo de busca e os canais do Teams
 * (um por plugin), com envio de teste e o resultado do último envio.
 */

use GlpiPlugin\Avisos\Canal;
use GlpiPlugin\Avisos\Pagina;
use GlpiPlugin\Avisos\Teams;

Session::checkRight('config', UPDATE);

global $DB;
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

if (isset($_POST['save'])) {
    Config::setConfigurationValues('plugin:avisos', [
        'popup'          => empty($_POST['popup']) ? '0' : '1',
        'poll_seconds'   => (string) max(20, min(600, (int) ($_POST['poll_seconds'] ?? 60))),
        'retention_days' => (string) max(30, min(3650, (int) ($_POST['retention_days'] ?? 180))),
    ]);
    foreach ((array) ($_POST['canal'] ?? []) as $id => $c) {
        $url = trim((string) ($c['webhook_url'] ?? ''));
        if ($url !== '' && !Canal::validUrl($url)) {
            Session::addMessageAfterRedirect('URL do webhook inválida (use https://): ' . htmlspecialchars($url), false, ERROR);
            continue;
        }
        $DB->update(Canal::getTable(), [
            'webhook_url' => $url, 'is_active' => !empty($c['is_active']) && $url !== '' ? 1 : 0, 'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $id]);
    }
    Session::addMessageAfterRedirect('Configuração dos avisos salva.');
    Html::back();
}
if (isset($_POST['test'])) {
    $canal = $DB->request(['FROM' => Canal::getTable(), 'WHERE' => ['id' => (int) $_POST['test']], 'LIMIT' => 1])->current();
    if ($canal && $canal['webhook_url']) {
        [$ok, $error] = Teams::test($canal);
        Session::addMessageAfterRedirect($ok ? 'Mensagem de teste enviada ao canal ' . htmlspecialchars($canal['name']) . '.' : 'O Teams não aceitou o teste: ' . htmlspecialchars($error), false, $ok ? INFO : ERROR);
    } else {
        Session::addMessageAfterRedirect('Salve a URL do webhook antes de testar.', false, WARNING);
    }
    Html::back();
}

Html::header('Avisos', '', 'config', 'plugin');
Pagina::config();
Html::footer();
