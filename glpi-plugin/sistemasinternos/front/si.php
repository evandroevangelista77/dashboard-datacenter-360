<?php

/**
 * Sistemas Internos 360 completo (mapa, topologia, access points, vulnerabilidades,
 * resumo operacional, voz…) servido pelo próprio GLPI, sem servidor à parte:
 *
 *   si.php?p=/                → página
 *   si.php?p=/api/xxx?a=1&b=2 → dados, calculados aqui (Zabbix pela conta de serviço, GLPI pelo banco)
 *   si.php?p=/si/… /static/…  → arquivos da página (pasta si360 do plugin)
 *
 * O navegador só fala com o GLPI, com o login e os direitos do GLPI. Os caminhos
 * absolutos da página (/api, /si, /static, /resumo) são reescritos para passar por aqui.
 */

use GlpiPlugin\Sistemasinternos\Denied;
use GlpiPlugin\Sistemasinternos\Menu;
use GlpiPlugin\Sistemasinternos\NotFound;
use GlpiPlugin\Sistemasinternos\Settings;
use GlpiPlugin\Sistemasinternos\Si360;
use GlpiPlugin\Sistemasinternos\Si360Glpi;

Session::checkRight(Menu::RIGHT_PAINEL, READ);
$csrf = Session::getNewCSRFToken();
// a página faz dezenas de chamadas ao mesmo tempo: libera a sessão para não enfileirar
session_write_close();

// caminho pedido: tudo depois de "p=" na query string, como veio (inclui a query original)
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
// sem "p=": é a própria página com parâmetros dela (a página troca a URL para "?topologia=1")
$path = str_starts_with($qs, 'p=') ? substr($qs, 2) : ($qs !== '' ? '/?' . $qs : '/');
if (str_starts_with($path, '%2F')) {
    $path = rawurldecode($path);
}
if ($path === '') {
    $path = '/';
}
[$route, $query] = array_pad(explode('?', $path, 2), 2, '');
parse_str($query, $q);
$route = rawurldecode($route);
if (str_contains($route, '..') || !preg_match('#^/(|api/.*|si/.*|static/.*|resumo|favicon\.ico)$#s', $route)) {
    http_response_code(404);
    return;
}

$json = function (int $status, mixed $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
};

// ------------------------------------------------------------------ dados
if (str_starts_with($route, '/api/')) {
    $api = substr($route, 5);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !str_starts_with($api, 'si/book/')) {
        $json(405, ['erro' => 'método não permitido']);
        return;
    }
    if ($api === 'tts') { // sem voz neural no servidor: a página usa a voz do navegador
        $json(503, ['erro' => 'voz neural indisponível; usando a voz do navegador']);
        return;
    }
    if ($api === 'export.xlsx') {
        $json(501, ['erro' => 'exportação indisponível no GLPI']);
        return;
    }
    if (!Settings::isConfigured() && !str_starts_with($api, 'si/')) {
        $json(503, ['erro' => 'Conexão com o Zabbix não configurada (Sistemas Internos → Configurações).']);
        return;
    }
    @set_time_limit(180);
    try {
        $out = str_starts_with($api, 'si/') ? Si360Glpi::route(substr($api, 3), $q) : Si360::route($api, $q);
        $json(200, $out);
    } catch (NotFound $e) {
        $json(404, ['erro' => $e->getMessage()]);
    } catch (Denied $e) {
        $json(403, ['erro' => $e->getMessage()]);
    } catch (Throwable $e) {
        $json(502, ['erro' => $e->getMessage()]);
    }
    return;
}

if ($route === '/favicon.ico') {
    http_response_code(204);
    return;
}

// ------------------------------------------------------------------ arquivos da página
$root = realpath(dirname(__DIR__) . '/si360');
$file = match (true) {
    $route === '/'                     => $root . '/static/index.html',
    $route === '/resumo'               => $root . '/si/resumo.html',
    default                            => $root . $route,
};
$real = realpath($file);
if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
    http_response_code(404);
    return;
}
$types = ['html' => 'text/html; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8',
    'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml',
    'webp' => 'image/webp', 'gif' => 'image/gif'];
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$type = $types[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');

if (!in_array($ext, ['html', 'css', 'js'], true)) {
    header('Cache-Control: private, max-age=86400');
    readfile($real);
    return;
}

$body = (string) file_get_contents($real);
if ($route === '/') {
    // a página do departamento: motor do painel + o código próprio do SI (si.css, si.js)
    $v = fn ($f) => filemtime($root . '/si/' . $f);
    $title = Settings::get('title') ?: 'Sistemas Internos';
    $cfg = json_encode(['groups' => Settings::get('groups'), 'title' => $title], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $body = preg_replace('#</head>#i', "<link rel=\"stylesheet\" href=\"/si/si.css?v={$v('si.css')}\"><script>window.SI = $cfg;</script></head>", $body, 1);
    $body = str_replace('<title>NOC</title>', '<title>' . htmlspecialchars($title) . ' · visão 360</title>', $body);
    $pos = strripos($body, '</body>');
    if ($pos !== false) {
        $body = substr_replace($body, "<script src=\"/si/si.js?v={$v('si.js')}\"></script></body>", $pos, 7);
    }
}

// caminhos absolutos da página passam a apontar para cá
global $CFG_GLPI;
$prefix = $CFG_GLPI['root_doc'] . '/plugins/sistemasinternos/front/si.php?p=';
$body = preg_replace('#(["\'`(=])/(api|si|static|resumo)(?=[/?"\'`)\s]|$)#', '$1' . $prefix . '/$2', $body);
// verdes fixos da página (fundos, desenhos do mapa) → os mesmos tons em azul do departamento
$azul = Settings::get('si_theme') !== 'original';
if ($azul) {
    $body = str_ireplace(
        ['#07130d', '#0e2118', '#143024', '#1d382b', '#17332a', '#12302a', '#0b1a12', '#0a1d14', '#1f7a52', '#6fd3a2', '#16231f', '#123326', '#0d1613', '#0b1310', '#24332e', '#33463f', 'rgba(7,19,13,'],
        ['#081428', '#0f2242', '#152d55', '#1c3560', '#173055', '#13305c', '#0b1630', '#0a1a33', '#3769B2', '#8fb5ef', '#10223f', '#12284a', '#0d1424', '#0b111f', '#24304a', '#33445f', 'rgba(8,20,40,'],
        $body
    );
}
if ($ext === 'html') {
    $css = Menu::url('/css/si360-tema.css') . '?v=' . Menu::fileVersion('css/si360-tema.css');
    $token = json_encode($csrf);
    $inject = ($azul ? "<link rel='stylesheet' href='" . htmlspecialchars($css, ENT_QUOTES) . "'>" : '')
        // POST da página (book) com o token CSRF do GLPI
        . "<script>(()=>{const f=window.fetch;window.fetch=(u,o={})=>{if(o.method&&o.method.toUpperCase()!=='GET'){o.headers=Object.assign({},o.headers||{},{'X-Glpi-Csrf-Token':$token});}return f(u,o);};})();</script>";
    $body = preg_replace('#</head>#i', $inject . '</head>', $body, 1);
    header('Cache-Control: no-cache');
} else {
    header('Cache-Control: private, max-age=300');
}
echo $body;
