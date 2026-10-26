<?php

/** Envio e remoção de documentos pelo card dos formulários (arquivos dos projetos). */

use GlpiPlugin\Pmo\Biblioteca;
use GlpiPlugin\Pmo\Docs;

Session::checkLoginUser();
try {
    Session::addMessageAfterRedirect(htmlspecialchars(Biblioteca::handle(Docs::sources(), $_POST, $_FILES)));
} catch (RuntimeException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
}
$back = (string) ($_POST['back'] ?? '');
Html::redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back . '#oak-docs' : $CFG_GLPI['root_doc'] . '/');
