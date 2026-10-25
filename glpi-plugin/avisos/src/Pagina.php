<?php

namespace GlpiPlugin\Avisos;

use Config;
use Html;

/** Conteúdo das páginas "Meus avisos" e "Configuração dos avisos". */
class Pagina
{
    public static function meusAvisos(int $uid, string $source, bool $unread): void
    {
        global $DB;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $where = ['users_id' => $uid];
        if ($source) {
            $where['source'] = $source;
        }
        if ($unread) {
            $where['is_read'] = 0;
        }
        $rows = iterator_to_array($DB->request(['FROM' => Aviso::getTable(), 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => 200]), false);
        $count = Aviso::unreadCount($uid);
        $url = fn (array $q) => '?' . http_build_query(array_filter($q + ['source' => $source, 'unread' => $unread ? 1 : 0]));

        echo "<div class='avisos-page'>";
        echo "<div class='avisos-title'><div><div class='avisos-crumb'>Avisos</div><h2>Meus avisos</h2>"
            . "<div class='text-secondary small'>Tudo o que os módulos de RH, PMO e Financeiro avisaram a você: aprovações pendentes, andamento de vagas, férias, reembolsos, sprints, riscos e tarefas atrasadas. Clique num aviso para abrir o item.</div></div>";
        if ($count) {
            echo "<form method='post' class='m-0'><input type='hidden' name='action' value='read_all'>"
                . "<button class='btn btn-outline-secondary'><i class='ti ti-checks me-1'></i>Marcar todos como lidos ($count)</button>" . Html::closeForm(false);
        }
        echo "</div>";

        echo "<div class='d-flex flex-wrap gap-2 mb-3'>";
        echo "<a class='btn btn-sm " . (!$source ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . $e($url(['source' => ''])) . "'>Todos</a>";
        foreach (Canal::SOURCES as $key => [$name, $icon]) {
            echo "<a class='btn btn-sm " . ($source === $key ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . $e($url(['source' => $key])) . "'><i class='ti $icon me-1'></i>" . $e($name) . "</a>";
        }
        echo "<label class='form-check form-switch ms-auto mb-0 align-self-center'><input class='form-check-input' type='checkbox'" . ($unread ? ' checked' : '')
            . " onchange=\"location.href=this.checked ? '" . $e($url(['unread' => 1])) . "' : '" . $e($url(['unread' => 0])) . "'\"><span class='form-check-label'>Só não lidos</span></label>";
        echo "</div>";

        echo "<div class='card'><div class='list-group list-group-flush'>";
        if (!$rows) {
            echo "<div class='list-group-item text-secondary py-4 text-center'><i class='ti ti-bell-off me-1'></i>Nenhum aviso por aqui.</div>";
        }
        foreach ($rows as $r) {
            $j = Aviso::toJson($r);
            $href = $j['url'] ?: '#';
            echo "<a class='list-group-item list-group-item-action avisos-row lvl-" . $e($j['level']) . ($j['read'] ? '' : ' unread') . "' href='" . $e($href) . "'>"
                . "<span class='avisos-ico' style='--c:" . $e($j['color']) . "'><i class='ti " . $e($j['icon']) . "'></i></span>"
                . "<span class='flex-grow-1'><span class='d-flex gap-2 align-items-baseline'><b>" . $e($j['title']) . "</b><small class='text-secondary ms-auto text-nowrap'>" . $e($j['sourceName']) . ' · ' . $e(Html::convDateTime($r['date_creation'])) . "</small></span>"
                . ($j['message'] !== '' ? "<span class='d-block text-secondary small'>" . $e($j['message']) . "</span>" : '') . "</span></a>";
        }
        echo "</div></div></div>";
    }

    public static function config(): void
    {
        global $DB, $CFG_GLPI;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $cfg = Config::getConfigurationValues('plugin:avisos');
        $canais = iterator_to_array($DB->request(['FROM' => Canal::getTable(), 'ORDER' => 'id']), false);
        $queue = [];
        foreach ($DB->request(['SELECT' => ['source', 'status', 'COUNT' => 'id AS n'], 'FROM' => Teams::QUEUE, 'GROUPBY' => ['source', 'status']]) as $r) {
            $queue[$r['source']][$r['status']] = (int) $r['n'];
        }

        echo "<div class='avisos-page'>";
        echo "<div class='avisos-title'><div><div class='avisos-crumb'>Avisos</div><h2>Configuração dos avisos</h2>"
            . "<div class='text-secondary small'>Pop-up e sino no GLPI para quem é avisado, e envio para o Microsoft Teams: cada plugin (RH, PMO e Financeiro) manda para o canal configurado aqui. O e-mail continua pelas notificações do GLPI.</div></div></div>";

        echo "<form method='post' action='" . $e($CFG_GLPI['root_doc'] . '/plugins/avisos/front/config.php') . "'>";
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-bell me-2'></i>No GLPI</h3></div><div class='card-body row g-3'>";
        echo "<div class='col-12'><label class='form-check form-switch'><input class='form-check-input' type='checkbox' name='popup' value='1'" . (($cfg['popup'] ?? '1') ? ' checked' : '') . "><span class='form-check-label'>Mostrar pop-up quando chegar um aviso novo</span></label>"
            . "<div class='form-hint'>O sino no topo da tela mostra os avisos não lidos de qualquer jeito.</div></div>";
        echo "<div class='col-md-4'><label class='form-label'>Procurar avisos novos a cada (segundos)</label><input type='number' min='20' max='600' class='form-control' name='poll_seconds' value='" . (int) ($cfg['poll_seconds'] ?? 60) . "'></div>";
        echo "<div class='col-md-4'><label class='form-label'>Guardar avisos lidos por (dias)</label><input type='number' min='30' max='3650' class='form-control' name='retention_days' value='" . (int) ($cfg['retention_days'] ?? 180) . "'></div>";
        echo "</div></div>";

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-brand-teams me-2'></i>Microsoft Teams</h3></div><div class='card-body'>";
        echo "<p class='text-secondary small'>No canal do Teams: <b>⋯ → Workflows → “Postar em um canal quando uma solicitação de webhook for recebida”</b>, copie a URL gerada e cole abaixo. "
            . "A mensagem chega como um cartão com o título, os detalhes, quem precisa agir e o botão <b>Abrir no GLPI</b>. O servidor do GLPI precisa acessar a internet (ou o proxy configurado no GLPI).</p>";
        echo "<div class='table-responsive'><table class='table table-vcenter'><thead><tr><th>Plugin</th><th style='min-width:420px'>URL do webhook</th><th>Ligado</th><th>Último envio</th><th>Fila</th><th></th></tr></thead><tbody>";
        foreach ($canais as $c) {
            [$name, $icon, $color] = Canal::meta((string) $c['source']);
            $q = $queue[$c['source']] ?? [];
            $status = $c['date_last']
                ? ($c['last_status'] === 'ok' ? "<span class='text-success'><i class='ti ti-circle-check me-1'></i>ok</span>" : "<span class='text-danger' title='" . $e($c['last_error']) . "'><i class='ti ti-alert-triangle me-1'></i>erro</span>")
                    . "<div class='small text-secondary'>" . $e(Html::convDateTime($c['date_last'])) . "</div>"
                : "<span class='text-secondary'>—</span>";
            echo "<tr><td class='text-nowrap'><span class='avisos-ico' style='--c:" . $e($color) . "'><i class='ti " . $e($icon) . "'></i></span> <b>" . $e($name) . "</b></td>"
                . "<td><input class='form-control' name='canal[" . (int) $c['id'] . "][webhook_url]' placeholder='https://…' value='" . $e($c['webhook_url']) . "'>"
                . ($c['last_status'] === 'erro' && $c['last_error'] ? "<div class='small text-danger mt-1'>" . $e(mb_substr($c['last_error'], 0, 200)) . "</div>" : '') . "</td>"
                . "<td><label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='canal[" . (int) $c['id'] . "][is_active]' value='1'" . ($c['is_active'] ? ' checked' : '') . "></label></td>"
                . "<td>$status</td>"
                . "<td class='small text-secondary text-nowrap'>" . (int) ($q['sent'] ?? 0) . " enviadas" . (($q['pending'] ?? 0) + ($q['error'] ?? 0) ? '<br>' . (($q['pending'] ?? 0) + ($q['error'] ?? 0)) . ' na fila' : '') . (($q['failed'] ?? 0) ? '<br><span class=\'text-danger\'>' . (int) $q['failed'] . ' falharam</span>' : '') . "</td>"
                . "<td><button class='btn btn-sm btn-outline-primary' name='test' value='" . (int) $c['id'] . "'" . ($c['webhook_url'] ? '' : ' disabled') . "><i class='ti ti-send me-1'></i>Testar</button></td></tr>";
        }
        echo "</tbody></table></div></div>";
        echo "<div class='card-footer'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div></div>";
        Html::closeForm();
        echo "</div>";
    }
}
