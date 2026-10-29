<?php

/**
 * Proxy do Sistemas Internos 360 completo (o mesmo dashboard da porta 8092: mapa,
 * topologia, vulnerabilidades, voz, book…) para dentro do GLPI.
 *
 *   si.php?p=/                → página
 *   si.php?p=/api/xxx?a=1&b=2 → API do painel (o resto da URL é repassado como está)
 *
 * O navegador só fala com o GLPI (mesma origem, sessão e direitos do GLPI); o GLPI
 * fala com o servidor do SI 360 (Configurações → Departamento). Os caminhos
 * absolutos do painel (/api, /si, /static) são reescritos para passar por aqui.
 */

use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\Settings;

Session::checkRight(Menu::RIGHT_PAINEL, READ);
$csrf = Session::getNewCSRFToken();
// o painel faz dezenas de chamadas ao mesmo tempo: libera a sessão para não enfileirar
session_write_close();

$base = rtrim(Settings::get('painel_url'), '/');
if ($base === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'O endereço do servidor do SI 360 não foi configurado (Sistemas Internos → Configurações → Departamento).';
    return;
}

// caminho pedido: tudo depois de "p=" na query string, como veio (inclui a query original)
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
// sem "p=": é a própria página com parâmetros dela (o painel troca a URL para "?topologia=1")
$path = str_starts_with($qs, 'p=') ? substr($qs, 2) : ($qs !== '' ? '/?' . $qs : '/');
if (str_starts_with($path, '%2F')) {
    $path = rawurldecode($path);
}
if ($path === '') {
    $path = '/';
}
if (!preg_match('#^/(|\?.*|api/.*|si/.*|static/.*|resumo.*|favicon\.ico)$#s', $path) || str_contains($path, '..')) {
    http_response_code(404);
    return;
}

$method = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
$ch = curl_init($base . $path);
$headers = ['Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? '*/*'), 'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? ''), 'X-GLPI-User: ' . (int) Session::getLoginUserID()];
$opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => false,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => false,
];
if ($method === 'POST') {
    $opts[CURLOPT_POST] = true;
    $opts[CURLOPT_POSTFIELDS] = file_get_contents('php://input');
    $headers[] = 'Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'application/json');
}
$opts[CURLOPT_HTTPHEADER] = $headers;
curl_setopt_array($ch, $opts);
$body = curl_exec($ch);
if ($body === false) {
    http_response_code(502);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['erro' => 'Servidor do SI 360 inacessível pelo GLPI: ' . curl_error($ch)], JSON_UNESCAPED_UNICODE);
    return;
}
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';

http_response_code($status);
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');

// texto: caminhos absolutos do painel passam a apontar para este proxy
if (preg_match('#^(text/html|text/css|text/javascript|application/javascript|application/json)#i', $type)) {
    global $CFG_GLPI;
    $prefix = $CFG_GLPI['root_doc'] . '/plugins/sistemasinternos/front/si.php?p=';
    $body = preg_replace('#(["\'`(=])/(api|si|static|resumo)(?=[/?"\'`)\s]|$)#', '$1' . $prefix . '/$2', $body);
    // verdes fixos do painel (fundos, desenhos do mapa) → os mesmos tons em azul do departamento
    $azul = Settings::get('si_theme') !== 'original';
    $body = !$azul ? $body : str_ireplace(
        ['#07130d', '#0e2118', '#143024', '#1d382b', '#17332a', '#12302a', '#0b1a12', '#0a1d14', '#1f7a52', '#6fd3a2', '#16231f', '#123326', '#0d1613', '#0b1310', '#24332e', '#33463f', 'rgba(7,19,13,'],
        ['#081428', '#0f2242', '#152d55', '#1c3560', '#173055', '#13305c', '#0b1630', '#0a1a33', '#3769B2', '#8fb5ef', '#10223f', '#12284a', '#0d1424', '#0b111f', '#24304a', '#33445f', 'rgba(8,20,40,'],
        $body
    );

    if (stripos($type, 'text/html') === 0) {
        $css = Menu::url('/css/si360-tema.css') . '?v=' . Menu::fileVersion('css/si360-tema.css');
        $token = json_encode($csrf);
        $inject = ($azul ? "<link rel='stylesheet' href='" . htmlspecialchars($css, ENT_QUOTES) . "'>" : '')
            // POST do painel (book) com o token CSRF do GLPI
            . "<script>(()=>{const f=window.fetch;window.fetch=(u,o={})=>{if(o.method&&o.method.toUpperCase()!=='GET'){o.headers=Object.assign({},o.headers||{},{'X-Glpi-Csrf-Token':$token});}return f(u,o);};})();</script>";
        $body = preg_replace('#</head>#i', $inject . '</head>', $body, 1);
        header('Cache-Control: no-cache');
    }
} else {
    header('Cache-Control: private, max-age=300');
}
echo $body;
