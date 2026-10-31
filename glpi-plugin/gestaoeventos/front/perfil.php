<?php

/** Grava o acesso por módulo de um perfil (matriz da aba do perfil) ou aplica um modelo de acesso. */

use GlpiPlugin\Gestaoeventos\Acesso;
use GlpiPlugin\Gestaoeventos\Menu;

Session::checkRightsOr('profile', [CREATE, UPDATE, PURGE]);

$id = (int) ($_POST['profiles_id'] ?? 0);
$profile = new Profile();
if (!$id || !$profile->getFromDB($id) || $profile->fields['interface'] !== 'central') {
    Html::displayNotFoundError();
}
if (isset($_POST['ge_model'])) {
    $model = Acesso::models()[$_POST['model'] ?? ''] ?? null;
    if (!$model) {
        Session::addMessageAfterRedirect('Modelo desconhecido.', false, ERROR);
        Html::back();
    }
    [$name, , $base, $modules] = $model;
    Acesso::write($id, $base, $modules);
    Session::addMessageAfterRedirect('Modelo "' . htmlspecialchars($name) . '" aplicado ao perfil ' . htmlspecialchars((string) $profile->fields['name']) . '.');
} elseif (isset($_POST['ge_save'])) {
    $modules = [];
    foreach (array_keys(Acesso::MODULES) as $k) {
        $c = (array) ($_POST['acc'][$k] ?? []);
        $modules[$k] = !empty($c['w']) ? 'w' : (!empty($c['r']) ? 'r' : '');
    }
    $base = (int) (ProfileRight::getProfileRights($id, [GlpiPlugin\Gestaoeventos\Evento::$rightname])[GlpiPlugin\Gestaoeventos\Evento::$rightname] ?? 0);
    Acesso::write($id, $base, $modules);
    Session::addMessageAfterRedirect('Acesso por módulo salvo.');
}
if (($_POST['back'] ?? '') === 'admin') {
    Html::redirect(Menu::url('/front/admin.php') . '?tab=acesso');
}
Html::back();
