<?php

use GlpiPlugin\Assinatura\LibreSignClient;
use GlpiPlugin\Assinatura\Settings;

Session::checkRight('config', UPDATE);

if (isset($_POST['save']) || isset($_POST['test'])) {
    Settings::save($_POST);
    if (isset($_POST['test'])) {
        try {
            Session::addMessageAfterRedirect((new LibreSignClient())->ping(), false, INFO);
        } catch (Throwable $e) {
            Session::addMessageAfterRedirect('Falha: ' . htmlspecialchars($e->getMessage()), false, ERROR);
        }
    } else {
        Session::addMessageAfterRedirect('Configuração salva.', false, INFO);
    }
    Html::back();
}

Html::header(Settings::getTypeName(), '', 'config', 'plugin');
Settings::showForm();
Html::footer();
