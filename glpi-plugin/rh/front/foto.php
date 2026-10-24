<?php

/**
 * Foto do candidato (só para quem pode ver o candidato).
 */

use GlpiPlugin\Rh\Candidato;

Session::checkLoginUser();

$cand = new Candidato();
if (!$cand->getFromDB((int) ($_GET['id'] ?? 0)) || empty($cand->fields['picture']) || !$cand->canSeePicture()) {
    http_response_code(404);
    exit;
}
$name = basename((string) $cand->fields['picture']);
$file = Candidato::pictureDir() . '/' . $name;
if ($name !== $cand->fields['picture'] || !is_file($file)) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'svg' => 'image/svg+xml'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
readfile($file);
exit;
