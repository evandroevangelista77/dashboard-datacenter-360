<?php

namespace GlpiPlugin\Cronograma;

use CommonGLPI;
use Document;
use Html;
use Project;

/**
 * Aba "Cronograma (MS Project)" no Projeto: envio do arquivo e histórico de importações.
 */
class ProjectTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Project || $item->isNewItem()) {
            return '';
        }
        return self::createTabEntry('Cronograma (MS Project)', 0, null, 'ti ti-timeline');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI, $DB;
        if (!$item instanceof Project) {
            return false;
        }
        $projectId = (int) $item->getID();
        $imported = self::countImported($projectId);

        echo "<div class='p-3'>";
        if ($item->canUpdateItem()) {
            // o envio fica numa página própria: formulário com upload dentro da aba (AJAX) não era enviado
            $url = htmlspecialchars($CFG_GLPI['root_doc'] . '/plugins/cronograma/front/import.php?projects_id=' . $projectId, ENT_QUOTES);
            $label = $imported === 0 ? 'Importar cronograma do MS Project' : "Reimportar cronograma ($imported tarefa(s) já importada(s))";
            echo "<a class='btn btn-primary mb-3' href='$url'><i class='ti ti-file-upload me-1'></i>$label</a>";
        }

        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-history me-2'></i>Importações</h3></div>";
        $rows = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_cronograma_imports', 'WHERE' => ['projects_id' => $projectId], 'ORDER' => 'id DESC', 'LIMIT' => 20]));
        if (!$rows) {
            echo "<div class='card-body text-secondary'>Nenhum cronograma importado ainda.</div></div></div>";
            return true;
        }
        echo "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr>"
            . "<th>Data</th><th>Arquivo</th><th>Cronograma</th><th class='text-end'>Criadas</th><th class='text-end'>Atualizadas</th>"
            . "<th class='text-end'>Removidas</th><th class='text-end'>Dependências</th><th>Por</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            $doc = new Document();
            $file = $r['documents_id'] && $doc->getFromDB($r['documents_id'])
                ? "<a href='" . htmlspecialchars($doc->getDownloadLink(null), ENT_QUOTES) . "'>" . htmlspecialchars($r['filename']) . "</a>"
                : htmlspecialchars($r['filename']);
            $warn = $r['warnings'] ? "<div class='small text-warning' title='" . htmlspecialchars($r['warnings'], ENT_QUOTES) . "'><i class='ti ti-alert-triangle'></i> avisos</div>" : '';
            echo "<tr><td>" . Html::convDateTime($r['date_creation']) . "</td><td>$file$warn</td><td>" . htmlspecialchars($r['ms_project_name']) . "</td>"
                . "<td class='text-end'>{$r['created']}</td><td class='text-end'>{$r['updated']}</td><td class='text-end'>{$r['removed']}</td>"
                . "<td class='text-end'>{$r['links']}</td><td>" . htmlspecialchars(getUserName($r['users_id'])) . "</td></tr>";
        }
        echo "</tbody></table></div></div></div>";
        return true;
    }

    /** Formulário de envio, exibido em front/import.php. */
    public static function showImportForm(Project $project): void
    {
        global $CFG_GLPI;
        $projectId = (int) $project->getID();
        $action = htmlspecialchars($CFG_GLPI['root_doc'] . '/plugins/cronograma/front/import.form.php', ENT_QUOTES);
        $imported = self::countImported($projectId);
        $back = htmlspecialchars($project->getLinkURL() . '&forcetab=' . rawurlencode(self::class . '$1'), ENT_QUOTES);

        echo "<div class='container-fluid'><div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-file-upload me-2'></i>Importar cronograma do MS Project — "
            . htmlspecialchars($project->getName()) . "</h3></div>";
        echo "<form method='post' action='$action' enctype='multipart/form-data' class='card-body'>";
        echo "<input type='hidden' name='projects_id' value='$projectId'>";
        echo "<div class='mb-3'><label class='form-label'>Arquivo do cronograma</label>";
        echo "<input class='form-control' type='file' name='cronograma' accept='.mpp,.mpt,.xml' required>";
        echo "<div class='form-hint'>Arquivo do Project (.mpp) ou XML exportado em <i>Arquivo → Salvar como → XML</i>. Até 20 MB.</div></div>";
        if ($imported) {
            echo "<div class='alert alert-info py-2'>Este projeto já tem <b>$imported</b> tarefa(s) importada(s). Uma nova importação <b>atualiza</b> essas tarefas (pelo identificador do Project) e cria as novas, sem duplicar.</div>";
        }
        $opts = [
            ['update_project_dates', 'Atualizar as datas planejadas do projeto com as do cronograma', true],
            ['team', 'Adicionar à equipe da tarefa os recursos que forem usuários do GLPI', true],
            ['remove_missing', 'Enviar para a lixeira as tarefas importadas antes que não estão mais no cronograma', false],
        ];
        foreach ($opts as [$name, $label, $checked]) {
            echo "<label class='form-check'><input class='form-check-input' type='checkbox' name='$name' value='1'" . ($checked ? ' checked' : '') . "><span class='form-check-label'>$label</span></label>";
        }
        echo "<div class='mt-3'><button class='btn btn-primary' type='submit' name='import' value='1'><i class='ti ti-upload me-1'></i>Importar cronograma</button>";
        echo " <a class='btn btn-ghost-secondary' href='$back'>Voltar ao projeto</a></div>";
        echo "<div class='mt-3' id='cronograma-status'></div>";
        Html::closeForm();
        echo "</div></div>";
        // envio por XHR: mostra o andamento (%) e erros na tela em vez de depender do submit nativo
        echo <<<JS
<script>
(() => {
    const init = () => {
        const form = document.querySelector('form[action$="/plugins/cronograma/front/import.form.php"]');
        if (!form || form.dataset.cronograma) return;
        form.dataset.cronograma = '1';
        const status = document.getElementById('cronograma-status');
        const button = form.querySelector('button[name=import]');
        const bar = (pct, text, cls = 'primary', animated = false) => {
            status.innerHTML = "<div class='small mb-1'>" + text + "</div>"
                + "<div class='progress' style='height:18px'><div class='progress-bar bg-" + cls + (animated ? " progress-bar-striped progress-bar-animated" : "")
                + "' style='width:" + pct + "%'>" + pct + "%</div></div>";
        };
        const fail = (msg) => {
            button.disabled = false;
            status.innerHTML = "<div class='alert alert-danger py-2 mb-0'>Falha no envio: " + msg + "</div>";
        };
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            const file = form.querySelector('input[type=file]').files[0];
            if (!file) { fail('nenhum arquivo selecionado.'); return; }
            const data = new FormData(form);
            data.set('import', '1');
            button.disabled = true;
            bar(0, 'Enviando ' + file.name + '…');
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action);
            xhr.upload.onprogress = (ev) => {
                if (ev.lengthComputable) {
                    // envio = 0–70%; o restante é a conversão/importação no servidor
                    bar(Math.round(ev.loaded / ev.total * 70), 'Enviando ' + file.name + '…');
                }
            };
            xhr.upload.onload = () => bar(70, 'Arquivo enviado. Convertendo o .mpp e criando as tarefas…', 'primary', true);
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 400) {
                    bar(100, 'Concluído. Abrindo o projeto…', 'success');
                    window.location.href = xhr.responseURL;
                } else {
                    fail('HTTP ' + xhr.status);
                }
            };
            xhr.onerror = () => fail('erro de rede (o navegador não conseguiu falar com o servidor).');
            xhr.send(data);
        }, true);
        status.innerHTML = "<div class='small text-secondary'>Pronto para importar.</div>";
    };
    init(); // o script vem logo depois do formulário: não precisa esperar o resto da página
})();
</script>
JS;
    }

    /** Tarefas importadas que ainda existem no projeto (fora da lixeira). */
    private static function countImported(int $projectId): int
    {
        global $DB;
        return (int) $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => 'glpi_plugin_cronograma_tasks AS m',
            'INNER JOIN' => ['glpi_projecttasks AS t' => ['ON' => ['m' => 'projecttasks_id', 't' => 'id']]],
            'WHERE'      => ['m.projects_id' => $projectId, 't.is_deleted' => 0],
        ])->current()['cpt'];
    }
}
