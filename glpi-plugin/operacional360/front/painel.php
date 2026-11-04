<?php

/**
 * Operacional 360: página do GLPI com o painel por entidade (o layout do NOC 360 é
 * servido por front/op360.php dentro de um quadro, com o mesmo login e os mesmos direitos).
 */

use GlpiPlugin\Operacional360\Menu;
use GlpiPlugin\Operacional360\Scope;

Session::checkRight(Menu::$rightname, READ);

Html::header('Operacional 360', $_SERVER['PHP_SELF'], Menu::SECTOR, 'operacional360');

if (!Scope::accessible()) {
    echo '<div class="alert alert-warning m-2" role="alert"><i class="ti ti-alert-triangle me-1"></i>'
        . 'Nenhuma entidade com host group configurado está liberada para o seu perfil. '
        . 'Peça ao administrador para configurar em <b>Operacional 360 → Configurar</b>.</div>';
    Html::footer();
    return;
}

// filtros da URL (entidade, período, cliente, host, chamado) seguem para o quadro
$keep = ['entity', 'from', 'to', 'preset', 'client', 'hostid', 'ticket'];
$query = array_intersect_key($_GET, array_flip($keep));
$src = htmlspecialchars(
    $CFG_GLPI['root_doc'] . '/plugins/operacional360/front/op360.php' . ($query ? '?' . http_build_query($query) : '?p=/'),
    ENT_QUOTES
);
$config = Session::haveRight('config', UPDATE)
    ? '<a class="btn btn-sm btn-outline-secondary" href="' . htmlspecialchars(Menu::CONFIG_PAGE, ENT_QUOTES) . '"><i class="ti ti-settings me-1"></i>Configurar</a>'
    : '';

echo <<<HTML
    <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="text-secondary small">
            <i class="ti ti-chart-dots-3 me-1"></i>
            Painel operacional da entidade: chamados do GLPI e alertas do Zabbix dos host groups
        </div>
        <div class="d-flex gap-2">
            $config
            <a class="btn btn-sm btn-outline-secondary" href="$src" target="_blank" rel="noopener">
                <i class="ti ti-external-link me-1"></i>Abrir em tela cheia
            </a>
        </div>
    </div>
    <iframe src="$src" title="Operacional 360"
            style="width:100%; height:calc(100vh - 190px); min-height:640px; border:0; border-radius:10px; background:#081428;"
            allow="fullscreen"></iframe>
HTML;

Html::footer();
