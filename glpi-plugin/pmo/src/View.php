<?php

namespace GlpiPlugin\Pmo;

use Document;
use Dropdown;
use Glpi\RichText\RichText;
use Html;
use Project;
use ProjectState;
use ProjectTaskType;
use Session;
use User;

/**
 * Páginas do PMO. Quadro, backlog, Gantt e painéis são montados no navegador
 * (public/js); configuração, documentação e riscos são formulários do servidor.
 */
class View
{
    public const VIEWS = [
        'board'     => ['Quadro', 'ti ti-layout-kanban'],
        'backlog'   => ['Backlog', 'ti ti-list-numbers'],
        'gantt'     => ['Gantt', 'ti ti-timeline'],
        'dashboard' => ['Painéis', 'ti ti-chart-line'],
        'docs'      => ['Documentação', 'ti ti-book'],
        'risks'     => ['Riscos', 'ti ti-alert-triangle'],
        'config'    => ['Configuração', 'ti ti-settings'],
    ];

    /** Subtítulo de cada página e de cada aba do projeto: para que serve a tela. */
    public const SUBTITLES = [
        'portfolio' => 'Todos os projetos num só lugar: situação, saúde do prazo, progresso e responsáveis. Filtre, ordene e clique num projeto para abrir o quadro, o Gantt e os painéis.',
        'mywork'    => 'Tarefas abertas em que você está na equipe, em todos os projetos, separadas entre atrasadas, que vencem em 7 dias, próximas e sem prazo.',
        'setup'     => 'Este projeto ainda não usa o PMO. Escolha a metodologia e as colunas do quadro para ativar o Kanban, o Gantt, as sprints, os painéis, a documentação e os riscos.',
        'board'     => 'Quadro Kanban das tarefas do projeto: arraste os cartões entre as colunas para atualizar a situação e filtre pelos responsáveis.',
        'backlog'   => 'Lista priorizada das tarefas ainda não iniciadas. Com sprints, planeje cada ciclo arrastando tarefas do backlog para a sprint, com início, fim e meta.',
        'gantt'     => 'Cronograma das tarefas no tempo, com dependências e progresso. Arraste a divisória para ver mais nomes ou mais calendário.',
        'dashboard' => 'Painéis do projeto: progresso, tarefas por situação e responsável, atrasos e, com sprints, burndown e velocidade.',
        'docs'      => 'Documentação do projeto: páginas em texto rico (como o termo de abertura) e os arquivos anexados ao projeto.',
        'risks'     => 'Registro de riscos e pendências, com probabilidade, impacto, responsável e plano de ação. A matriz mostra onde estão os riscos abertos.',
        'config'    => 'Configuração do PMO neste projeto: metodologia, colunas do quadro e os avisos por e-mail de tarefa atrasada, sprint e risco crítico.',
    ];

    public static function subtitle(string $key): string
    {
        return isset(self::SUBTITLES[$key]) ? "<div class='text-secondary small pmo-subtitle'>" . self::e(self::SUBTITLES[$key]) . "</div>" : '';
    }

    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    public static function projectUrl(int $id, string $view, array $extra = []): string
    {
        return Menu::url('/front/project.php?' . http_build_query(['id' => $id, 'view' => $view] + $extra));
    }

    /** Scripts e estilos que cada visão precisa (chamar antes do Html::header). */
    public static function requireAssets(string $view): void
    {
        if (in_array($view, ['dashboard', 'portfolio'], true)) {
            Html::requireJs('charts');
        }
    }

    /**
     * Script do plugin versionado pela data do arquivo: a versão fixa do plugin no
     * ?v= fazia o navegador seguir com o arquivo antigo depois de uma atualização.
     */
    public static function script(string $file): string
    {
        return Html::script(Menu::url('/js/' . $file), ['version' => self::fileVersion('js/' . $file)], false);
    }

    /** Folha de estilo do plugin, versionada como os scripts (o add_css usa a versão fixa). */
    public static function css(): string
    {
        return "<link rel='stylesheet' href='" . self::e(Menu::url('/css/pmo.css') . '?m=' . self::fileVersion('css/pmo.css')) . "'>";
    }

    private static function fileVersion(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . $path;
        return PLUGIN_PMO_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
    }

    public static function showProject(Project $project, string $view): void
    {
        $id = (int) $project->getID();
        $config = ProjectConfig::get($id);
        $canEdit = ProjectConfig::canEdit($project);
        if (!isset(self::VIEWS[$view])) {
            $view = 'board';
        }

        $state = '';
        if ($project->fields['projectstates_id']) {
            $s = new ProjectState();
            if ($s->getFromDB($project->fields['projectstates_id'])) {
                $state = "<span class='badge pmo-badge' style='--c:" . self::e($s->fields['color'] ?: '#64748b') . "'>" . self::e($s->fields['name']) . "</span>";
            }
        }
        $method = $config ? "<span class='badge bg-secondary-lt'><i class='ti ti-route me-1'></i>" . self::e(ProjectConfig::METHODOLOGIES[$config['methodology']] ?? '') . "</span>" : '';
        $code = $project->fields['code'] ? "<span class='text-secondary me-2'>" . self::e($project->fields['code']) . "</span>" : '';

        echo self::css();
        echo "<div class='pmo'>";
        echo "<div class='pmo-head'>";
        echo "<div><div class='pmo-crumb'><a href='" . self::e(Menu::url('/front/portfolio.php')) . "'><i class='ti ti-briefcase me-1'></i>PMO</a> / projeto</div>";
        echo "<h2 class='pmo-title'>$code" . self::e($project->getName()) . "</h2><div class='pmo-badges'>$state $method</div>" . self::subtitle($config ? $view : 'setup') . "</div>";
        echo "<div class='pmo-head-actions'><a class='btn btn-outline-secondary btn-sm' href='" . self::e($project->getLinkURL()) . "'><i class='ti ti-external-link me-1'></i>Abrir projeto no GLPI</a></div>";
        echo "</div>";

        if (!$config) {
            self::showSetup($project, $canEdit);
            echo "</div>";
            return;
        }

        echo "<ul class='nav nav-tabs pmo-nav'>";
        foreach (self::VIEWS as $key => [$label, $icon]) {
            if ($key === 'backlog' && ProjectConfig::usesSprints($config)) {
                $label = 'Backlog e sprints';
            }
            $active = $key === $view ? ' active' : '';
            echo "<li class='nav-item'><a class='nav-link$active' href='" . self::e(self::projectUrl($id, $key)) . "'><i class='$icon me-1'></i>$label</a></li>";
        }
        echo "</ul><div class='pmo-body'>";

        switch ($view) {
            case 'docs':
                self::showDocs($project, $canEdit);
                break;
            case 'risks':
                self::showRisks($project, $canEdit);
                break;
            case 'config':
                self::showConfig($project, $config, $canEdit);
                break;
            case 'gantt':
                echo self::ganttMount($project);
                break;
            default:
                self::boot($view, self::bootData($project, $config, $canEdit));
                echo self::script('pmo.js');
                echo self::script($view . '.js');
        }
        echo "</div></div>";
    }

    /** Dados que as visões em JavaScript recebem do servidor. */
    public static function bootData(Project $project, ?array $config, bool $canEdit): array
    {
        $id = (int) $project->getID();
        return [
            'project'  => $id,
            'name'     => $project->getName(),
            'edit'     => $canEdit,
            'config'   => $config,
            'sprints'  => ProjectConfig::usesSprints($config),
            'me'       => Session::getLoginUserID(),
            'urls'     => [
                'ajax'    => Menu::url('/front/ajax.php'),
                'backlog' => self::projectUrl($id, 'backlog'),
                'board'   => self::projectUrl($id, 'board'),
                'gantt'   => self::projectUrl($id, 'gantt'),
                'task'    => \ProjectTask::getFormURL(),
                'newtask' => \ProjectTask::getFormURL() . '?projects_id=' . $id,
            ],
        ];
    }

    /**
     * Gantt do projeto. Funciona mesmo sem o PMO ativado (lê as tarefas nativas),
     * e pode aparecer mais de uma vez na página (tela do projeto e aba).
     * @param bool $compact versão embutida na tela principal do projeto
     */
    public static function ganttMount(Project $project, bool $compact = false): string
    {
        $data = self::bootData($project, ProjectConfig::get((int) $project->getID()), ProjectConfig::canEdit($project));
        $data['compact'] = $compact;
        $json = self::e(json_encode($data, JSON_UNESCAPED_UNICODE));
        return self::css() . "<div class='pmo-app pmo-gantt-app" . ($compact ? ' pmo-gantt-compact' : '') . "' data-boot='$json'>"
            . "<div class='pmo-loading'><span class='spinner-border spinner-border-sm me-2'></span>Carregando o Gantt…</div></div>"
            . self::script('pmo.js') . self::script('gantt.js');
    }

    /** Ponto de montagem de uma visão em JavaScript. */
    public static function boot(string $view, array $data): void
    {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        echo "<div id='pmo-app' class='pmo-app pmo-view-$view' data-view='$view'><div class='pmo-loading'><span class='spinner-border spinner-border-sm me-2'></span>Carregando…</div></div>";
        echo "<script type='application/json' id='pmo-boot'>$json</script>";
    }

    private static function showSetup(Project $project, bool $canEdit): void
    {
        $id = (int) $project->getID();
        $suggest = ProjectConfig::suggest($id);
        echo "<div class='card pmo-setup'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='ti ti-rocket me-2'></i>Ativar o PMO neste projeto</h3>";
        echo "<p class='text-secondary'>Escolha como a equipe trabalha. As tarefas do projeto viram cartões do quadro; nada é duplicado, e dá para mudar depois em <i>Configuração</i>.</p>";
        if (!$canEdit) {
            echo "<div class='alert alert-info mb-0'>Peça a quem gerencia o projeto para ativar o PMO (é preciso o direito <b>PMO: Atualizar</b> e poder editar o projeto).</div></div></div>";
            return;
        }
        $desc = [
            'scrum'     => ['ti ti-run', 'Backlog priorizado, sprints com meta, quadro da sprint, burndown e velocidade.'],
            'kanban'    => ['ti ti-layout-kanban', 'Fluxo contínuo com limites de WIP por coluna e fluxo cumulativo.'],
            'waterfall' => ['ti ti-timeline', 'Cronograma em fases com Gantt e dependências, curva S planejado × real. Ideal para cronogramas importados do MS Project.'],
            'hybrid'    => ['ti ti-git-merge', 'Cronograma macro no Gantt e execução em sprints.'],
        ];
        echo "<form method='post' action='" . self::e(Menu::url('/front/config.form.php')) . "'>";
        echo "<input type='hidden' name='projects_id' value='$id'><div class='row g-3 mb-3'>";
        foreach (ProjectConfig::METHODOLOGIES as $key => $label) {
            [$icon, $text] = $desc[$key];
            $checked = $key === $suggest ? ' checked' : '';
            echo "<div class='col-md-6 col-xl-3'><label class='pmo-method'><input type='radio' name='methodology' value='$key'$checked>"
                . "<span class='pmo-method-box'><i class='$icon'></i><b>" . self::e($label) . "</b><small>$text</small></span></label></div>";
        }
        echo "</div><div class='row g-3 align-items-end'>";
        echo "<div class='col-auto'><label class='form-label'>Estimativa dos cartões</label><select class='form-select' name='estimation'><option value='points'>Story points</option><option value='hours'" . ($suggest === 'waterfall' ? ' selected' : '') . ">Horas planejadas</option></select></div>";
        echo "<div class='col-auto'><label class='form-label'>Duração da sprint (dias)</label><input class='form-control' type='number' name='sprint_days' value='14' min='1' max='60'></div>";
        echo "<div class='col-auto'><button class='btn btn-primary' name='enable' value='1'><i class='ti ti-check me-1'></i>Ativar PMO</button></div>";
        echo "</div>";
        Html::closeForm();
        echo "</div></div>";
    }

    private static function showConfig(Project $project, array $config, bool $canEdit): void
    {
        $id = (int) $project->getID();
        $dis = $canEdit ? '' : ' disabled';
        $states = [0 => '— não muda —'];
        foreach ((new ProjectState())->find([], ['name']) as $s) {
            $states[(int) $s['id']] = $s['name'];
        }
        $opt = function (array $options, $value) {
            $h = '';
            foreach ($options as $k => $v) {
                $h .= "<option value='" . self::e($k) . "'" . ((string) $k === (string) $value ? ' selected' : '') . ">" . self::e($v) . "</option>";
            }
            return $h;
        };
        $row = function (array $c) use ($opt, $states, $dis) {
            $pct = $c['percent'] >= 0 ? (int) $c['percent'] : '';
            return "<tr class='pmo-col-row'><td class='pmo-grip'><i class='ti ti-grip-vertical'></i></td>"
                . "<td><input type='hidden' name='columns[id][]' value='" . (int) ($c['id'] ?? 0) . "'><input class='form-control form-control-sm' name='columns[name][]' value='" . self::e($c['name'] ?? '') . "' required$dis></td>"
                . "<td><select class='form-select form-select-sm' name='columns[kind][]'$dis>" . $opt(ProjectConfig::KINDS, $c['kind'] ?? 'todo') . "</select></td>"
                . "<td><input type='color' class='form-control form-control-sm form-control-color' name='columns[color][]' value='" . self::e($c['color'] ?? '#64748b') . "'$dis></td>"
                . "<td><input type='number' min='0' class='form-control form-control-sm' style='width:80px' name='columns[wip_limit][]' value='" . (int) ($c['wip_limit'] ?? 0) . "'$dis></td>"
                . "<td><input type='number' min='0' max='100' placeholder='mantém' class='form-control form-control-sm' style='width:95px' name='columns[percent][]' value='$pct'$dis></td>"
                . "<td><select class='form-select form-select-sm' name='columns[projectstates_id][]'$dis>" . $opt($states, $c['projectstates_id'] ?? 0) . "</select></td>"
                . "<td class='text-nowrap'><button type='button' class='btn btn-sm btn-ghost-secondary pmo-up' title='Subir'$dis><i class='ti ti-arrow-up'></i></button>"
                . "<button type='button' class='btn btn-sm btn-ghost-secondary pmo-down' title='Descer'$dis><i class='ti ti-arrow-down'></i></button>"
                . "<button type='button' class='btn btn-sm btn-ghost-danger pmo-del' title='Remover'$dis><i class='ti ti-trash'></i></button></td></tr>";
        };

        echo "<form method='post' action='" . self::e(Menu::url('/front/config.form.php')) . "' class='pmo-config'>";
        echo "<input type='hidden' name='projects_id' value='$id'>";
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Projeto</h3></div><div class='card-body row g-3'>";
        echo "<div class='col-md-4'><label class='form-label'>Metodologia</label><select class='form-select' name='methodology'$dis>" . $opt(ProjectConfig::METHODOLOGIES, $config['methodology']) . "</select></div>";
        echo "<div class='col-md-4'><label class='form-label'>Estimativa dos cartões</label><select class='form-select' name='estimation'$dis>" . $opt(['points' => 'Story points', 'hours' => 'Horas planejadas (da tarefa)'], $config['estimation']) . "</select>"
            . "<div class='form-hint'>Base do burndown, burnup e velocidade.</div></div>";
        echo "<div class='col-md-4'><label class='form-label'>Duração padrão da sprint (dias)</label><input class='form-control' type='number' min='1' max='60' name='sprint_days' value='" . (int) $config['sprint_days'] . "'$dis></div>";
        echo "</div></div>";

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Colunas do quadro</h3></div>";
        echo "<div class='table-responsive'><table class='table table-vcenter card-table pmo-cols'><thead><tr><th></th><th>Nome</th><th>Categoria</th><th>Cor</th><th>Limite WIP</th><th>% ao entrar</th><th>Estado da tarefa ao entrar</th><th></th></tr></thead><tbody>";
        foreach (ProjectConfig::columns($id) as $c) {
            echo $row($c);
        }
        echo "</tbody></table></div>";
        echo "<template id='pmo-col-template'>" . $row(['name' => '', 'kind' => 'doing', 'color' => '#3b82f6', 'wip_limit' => 0, 'percent' => -1]) . "</template>";
        echo "<div class='card-body border-top small text-secondary'>"
            . "<b>Categoria</b>: “Concluído” coloca a tarefa em 100% e marca a data de conclusão (base do burndown). "
            . "<b>% ao entrar</b>: vazio mantém o % da tarefa. <b>Limite WIP</b>: 0 = sem limite. "
            . "Remover uma coluna leva os cartões dela para a primeira coluna da mesma categoria.</div>";
        if ($canEdit) {
            echo "<div class='card-footer d-flex flex-wrap gap-2'><button type='button' class='btn btn-outline-primary btn-sm' id='pmo-add-col'><i class='ti ti-plus me-1'></i>Adicionar coluna</button>";
            echo "<span class='ms-auto text-secondary small align-self-center'>Recriar pelo modelo:</span>";
            foreach (['scrum' => 'Scrum', 'kanban' => 'Kanban', 'waterfall' => 'Waterfall'] as $k => $l) {
                echo "<button class='btn btn-ghost-secondary btn-sm' name='template' value='$k' onclick=\"return confirm('Substituir as colunas atuais pelo modelo $l? Os cartões vão para a coluna equivalente.')\">$l</button>";
            }
            echo "</div>";
        }
        echo "</div>";
        // avisos por e-mail: dependem das notificações do GLPI estarem ligadas
        global $CFG_GLPI;
        $on = $CFG_GLPI['use_notifications'] && $CFG_GLPI['notifications_mailing'];
        $notifUrl = self::e(\Notification::getSearchURL());
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-bell me-2'></i>Avisos por e-mail</h3></div><div class='card-body'>";
        echo $on
            ? "<div class='text-success mb-2'><i class='ti ti-circle-check me-1'></i>As notificações por e-mail do GLPI estão ligadas.</div>"
            : "<div class='alert alert-warning mb-2'>As notificações por e-mail do GLPI estão <b>desligadas</b>: nenhum aviso é enviado. Ligue em <i>Configurar → Notificações</i> (acompanhamento e e-mail) e configure o servidor SMTP.</div>";
        echo "<div class='small text-secondary'>O PMO avisa <b>sprint iniciada</b> e <b>sprint concluída</b> e <b>risco crítico</b> (P×I ≥ " . Risk::CRITICAL . ") ao gerente e à equipe do projeto, "
            . "e <b>tarefa atrasada</b> (uma vez por prazo vencido, conferido de hora em hora) à equipe da tarefa. As mudanças de tarefa (mover cartão, %, datas) usam a notificação nativa <i>Atualização de tarefa de projeto</i>. "
            . "Modelos e destinatários: <a href='$notifUrl'>Configurar → Notificações</a>.</div></div></div>";

        if ($canEdit) {
            echo "<button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar configuração</button>";
            echo " <button class='btn btn-ghost-danger ms-2' name='disable' value='1' onclick=\"return confirm('Desativar o PMO neste projeto? Quadro, sprints, pontos, documentação e riscos do PMO deste projeto serão apagados. As tarefas do GLPI ficam.')\"><i class='ti ti-power me-1'></i>Desativar PMO no projeto</button>";
        }
        Html::closeForm();
        echo <<<JS
<script>
(() => {
    const body = document.querySelector('.pmo-cols tbody');
    document.getElementById('pmo-add-col')?.addEventListener('click', () => {
        body.insertAdjacentHTML('beforeend', document.getElementById('pmo-col-template').innerHTML);
    });
    body.addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        const tr = btn?.closest('tr');
        if (!tr) return;
        if (btn.classList.contains('pmo-up') && tr.previousElementSibling) tr.parentNode.insertBefore(tr, tr.previousElementSibling);
        if (btn.classList.contains('pmo-down') && tr.nextElementSibling) tr.parentNode.insertBefore(tr.nextElementSibling, tr);
        if (btn.classList.contains('pmo-del')) {
            if (body.querySelectorAll('tr').length <= 1) { alert('O quadro precisa de pelo menos uma coluna.'); return; }
            tr.remove();
        }
    });
})();
</script>
JS;
    }

    private static function showDocs(Project $project, bool $canEdit): void
    {
        global $DB;
        $id = (int) $project->getID();
        $pages = Page::all($id);
        $current = (int) ($_GET['page'] ?? ($pages[0]['id'] ?? 0));
        $page = $current ? Page::find($id, $current) : null;
        $editing = $page && $canEdit && !empty($_GET['edit']);
        $action = self::e(Menu::url('/front/page.form.php'));

        echo "<div class='row g-3'><div class='col-lg-3'>";
        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-book me-2'></i>Páginas</h3></div><div class='list-group list-group-flush'>";
        if (!$pages) {
            echo "<div class='list-group-item text-secondary'>Nenhuma página ainda.</div>";
        }
        foreach ($pages as $p) {
            $active = (int) $p['id'] === $current ? ' active' : '';
            echo "<a class='list-group-item list-group-item-action$active' href='" . self::e(self::projectUrl($id, 'docs', ['page' => $p['id']])) . "'><i class='ti ti-file-text me-2'></i>" . self::e($p['name']) . "</a>";
        }
        echo "</div>";
        if ($canEdit) {
            echo "<form method='post' action='$action' class='card-body border-top'><input type='hidden' name='projects_id' value='$id'>";
            echo "<label class='form-label'>Nova página a partir do modelo</label><select class='form-select form-select-sm mb-2' name='template'>";
            foreach (Page::TEMPLATES as $k => [$label]) {
                echo "<option value='$k'>" . self::e($label) . "</option>";
            }
            echo "</select><button class='btn btn-primary btn-sm w-100' name='create' value='1'><i class='ti ti-plus me-1'></i>Criar página</button>";
            Html::closeForm();
            echo "</div>";
        } else {
            echo "</div>";
        }

        // documentos nativos anexados ao projeto (inclui os .mpp/.xml do cronograma)
        echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-paperclip me-2'></i>Arquivos do projeto</h3></div><div class='list-group list-group-flush'>";
        $docs = $DB->request([
            'SELECT'     => ['d.id', 'd.name', 'd.filename', 'd.date_mod'],
            'FROM'       => 'glpi_documents AS d',
            'INNER JOIN' => ['glpi_documents_items AS di' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE'      => ['di.itemtype' => Project::class, 'di.items_id' => $id, 'd.is_deleted' => 0],
            'ORDER'      => 'd.date_mod DESC',
        ]);
        if (!count($docs)) {
            echo "<div class='list-group-item text-secondary small'>Nenhum arquivo anexado.</div>";
        }
        foreach ($docs as $d) {
            $doc = new Document();
            $doc->getFromDB($d['id']);
            echo "<a class='list-group-item list-group-item-action small' href='" . self::e($doc->getDownloadLink(null)) . "'><i class='ti ti-download me-2'></i>" . self::e($d['name'] ?: $d['filename']) . "</a>";
        }
        $docsTab = $project->getLinkURL() . '&forcetab=' . rawurlencode('Document_Item$1');
        echo "</div><div class='card-footer'><a class='small' href='" . self::e($docsTab) . "'><i class='ti ti-upload me-1'></i>Anexar arquivos no projeto</a></div></div>";
        echo "</div><div class='col-lg-9'>";

        if (!$page) {
            echo "<div class='card'><div class='card-body text-secondary'>Crie a primeira página da documentação (Termo de Abertura, EAP, RACI, plano de comunicação, atas, status report, lições aprendidas…).</div></div></div></div>";
            return;
        }
        $meta = 'Criada por ' . self::e(getUserName((int) $page['users_id'])) . ' em ' . Html::convDateTime($page['date_creation'])
            . ' · atualizada por ' . self::e(getUserName((int) $page['users_id_lastupdater'])) . ' em ' . Html::convDateTime($page['date_mod']);
        if ($editing) {
            echo "<form method='post' action='$action' class='card'><input type='hidden' name='projects_id' value='$id'><input type='hidden' name='id' value='" . (int) $page['id'] . "'>";
            echo "<div class='card-header'><input class='form-control form-control-lg' name='name' value='" . self::e($page['name']) . "' required></div><div class='card-body'>";
            Html::textarea(['name' => 'content', 'value' => RichText::getSafeHtml($page['content']), 'enable_richtext' => true, 'enable_images' => false, 'rows' => 25]);
            echo "</div><div class='card-footer d-flex gap-2'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>";
            echo "<a class='btn btn-ghost-secondary' href='" . self::e(self::projectUrl($id, 'docs', ['page' => $page['id']])) . "'>Cancelar</a></div>";
            Html::closeForm();
        } else {
            echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . self::e($page['name']) . "</h3>";
            if ($canEdit) {
                echo "<div class='card-actions d-flex gap-2'><a class='btn btn-primary btn-sm' href='" . self::e(self::projectUrl($id, 'docs', ['page' => $page['id'], 'edit' => 1])) . "'><i class='ti ti-pencil me-1'></i>Editar</a>";
                echo "<form method='post' action='$action' onsubmit=\"return confirm('Excluir esta página?')\"><input type='hidden' name='projects_id' value='$id'><input type='hidden' name='id' value='" . (int) $page['id'] . "'>"
                    . "<button class='btn btn-ghost-danger btn-sm' name='delete' value='1'><i class='ti ti-trash'></i></button>";
                Html::closeForm();
                echo "</div>";
            }
            echo "</div><div class='card-body pmo-doc rich_text_container'>" . RichText::getEnhancedHtml($page['content']) . "</div>";
            echo "<div class='card-footer small text-secondary'>$meta</div></div>";
        }
        echo "</div></div>";
    }

    private static function showRisks(Project $project, bool $canEdit): void
    {
        $id = (int) $project->getID();
        $risks = Risk::all($id);
        $editId = (int) ($_GET['risk'] ?? 0);
        $edit = null;
        foreach ($risks as $r) {
            $edit = (int) $r['id'] === $editId ? $r : $edit;
        }
        $action = self::e(Menu::url('/front/risk.form.php'));

        // matriz probabilidade × impacto dos itens abertos
        $matrix = [];
        foreach ($risks as $r) {
            if ($r['status'] !== 'closed' && $r['kind'] === 'risk') {
                $matrix[(int) $r['probability']][(int) $r['impact']][] = $r;
            }
        }
        echo "<div class='row g-3'><div class='col-xl-4'><div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-grid-dots me-2'></i>Matriz de riscos (abertos)</h3></div><div class='card-body'>";
        echo "<div class='pmo-matrix'><div class='pmo-matrix-y'>Probabilidade</div><table><tbody>";
        for ($p = 5; $p >= 1; $p--) {
            echo "<tr><th>$p</th>";
            for ($i = 1; $i <= 5; $i++) {
                [, $cls] = Risk::level($p * $i);
                $list = $matrix[$p][$i] ?? [];
                $title = self::e(implode("\n", array_column($list, 'name')));
                echo "<td class='pmo-lvl-$cls' title='$title'>" . (count($list) ?: '') . "</td>";
            }
            echo "</tr>";
        }
        echo "<tr><th></th>" . implode('', array_map(fn ($i) => "<th>$i</th>", range(1, 5))) . "</tr></tbody></table><div class='pmo-matrix-x'>Impacto</div></div>";
        echo "</div></div>";

        if ($canEdit) {
            $r = $edit ?? ['id' => 0, 'kind' => 'risk', 'name' => '', 'description' => '', 'probability' => 3, 'impact' => 3, 'users_id' => 0, 'response' => '', 'status' => 'open', 'due_date' => ''];
            $sel = function (string $name, array $options, $value) {
                $h = "<select class='form-select form-select-sm' name='$name'>";
                foreach ($options as $k => $v) {
                    $h .= "<option value='$k'" . ((string) $k === (string) $value ? ' selected' : '') . ">" . self::e(is_int($k) ? "$k - $v" : $v) . "</option>";
                }
                return $h . "</select>";
            };
            echo "<form method='post' action='$action' class='card mt-3'><input type='hidden' name='projects_id' value='$id'><input type='hidden' name='id' value='" . (int) $r['id'] . "'>";
            echo "<div class='card-header'><h3 class='card-title'>" . ($r['id'] ? 'Editar item' : 'Novo risco ou pendência') . "</h3></div><div class='card-body row g-2'>";
            echo "<div class='col-6'><label class='form-label'>Tipo</label>" . $sel('kind', Risk::KINDS, $r['kind']) . "</div>";
            echo "<div class='col-6'><label class='form-label'>Status</label>" . $sel('status', Risk::STATUSES, $r['status']) . "</div>";
            echo "<div class='col-12'><label class='form-label'>Título</label><input class='form-control form-control-sm' name='name' value='" . self::e($r['name']) . "' required></div>";
            echo "<div class='col-12'><label class='form-label'>Descrição / causa</label><textarea class='form-control form-control-sm' rows='2' name='description'>" . self::e($r['description']) . "</textarea></div>";
            echo "<div class='col-6'><label class='form-label'>Probabilidade</label>" . $sel('probability', Risk::SCALE, $r['probability']) . "</div>";
            echo "<div class='col-6'><label class='form-label'>Impacto</label>" . $sel('impact', Risk::SCALE, $r['impact']) . "</div>";
            echo "<div class='col-12'><label class='form-label'>Plano de resposta</label><textarea class='form-control form-control-sm' rows='2' name='response'>" . self::e($r['response']) . "</textarea></div>";
            echo "<div class='col-7'><label class='form-label'>Responsável</label>";
            User::dropdown(['name' => 'users_id', 'value' => (int) $r['users_id'], 'right' => 'all', 'width' => '100%']);
            echo "</div><div class='col-5'><label class='form-label'>Prazo</label><input type='date' class='form-control form-control-sm' name='due_date' value='" . self::e($r['due_date']) . "'></div>";
            echo "</div><div class='card-footer d-flex gap-2'><button class='btn btn-primary btn-sm' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>";
            if ($r['id']) {
                echo "<a class='btn btn-ghost-secondary btn-sm' href='" . self::e(self::projectUrl($id, 'risks')) . "'>Novo item</a>";
                echo "<button class='btn btn-ghost-danger btn-sm ms-auto' name='delete' value='1' onclick=\"return confirm('Excluir este item?')\"><i class='ti ti-trash'></i></button>";
            }
            echo "</div>";
            Html::closeForm();
        }
        echo "</div><div class='col-xl-8'><div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-list me-2'></i>Registro de riscos e pendências</h3></div>";
        if (!$risks) {
            echo "<div class='card-body text-secondary'>Nenhum risco ou pendência registrado.</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-vcenter card-table table-hover'><thead><tr><th>Item</th><th>P×I</th><th>Nível</th><th>Status</th><th>Responsável</th><th>Prazo</th></tr></thead><tbody>";
            foreach ($risks as $r) {
                [$lvl, $cls] = Risk::level($r['score']);
                $closed = $r['status'] === 'closed' ? ' class="opacity-50"' : '';
                $late = $r['due_date'] && $r['status'] !== 'closed' && strtotime($r['due_date']) < strtotime('today') ? " text-danger" : '';
                $link = self::e(self::projectUrl($id, 'risks', ['risk' => $r['id']]));
                echo "<tr$closed><td><a href='$link'><i class='ti " . ($r['kind'] === 'risk' ? 'ti-alert-triangle' : 'ti-flag') . " me-1'></i>" . self::e($r['name']) . "</a>"
                    . ($r['response'] ? "<div class='small text-secondary text-truncate' style='max-width:420px'>" . self::e($r['response']) . "</div>" : '') . "</td>"
                    . "<td>{$r['probability']}×{$r['impact']} = <b>{$r['score']}</b></td><td><span class='badge pmo-lvl-$cls'>$lvl</span></td>"
                    . "<td>" . self::e(Risk::STATUSES[$r['status']] ?? $r['status']) . "</td><td>" . self::e($r['users_id'] ? getUserName((int) $r['users_id']) : '') . "</td>"
                    . "<td class='$late'>" . ($r['due_date'] ? Html::convDate($r['due_date']) : '') . "</td></tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div></div></div>";
    }

    /** Lista dos tipos de tarefa (para o editor de cartões). */
    public static function taskTypes(): array
    {
        $out = [];
        foreach ((new ProjectTaskType())->find([], ['name']) as $t) {
            $out[] = ['id' => (int) $t['id'], 'name' => $t['name']];
        }
        return $out;
    }

    public static function showPortfolio(): void
    {
        echo self::css();
        echo "<div class='pmo'><div class='pmo-head'><div><div class='pmo-crumb'><i class='ti ti-briefcase me-1'></i>PMO</div><h2 class='pmo-title'>Portfólio de projetos</h2>" . self::subtitle('portfolio') . "</div>";
        echo "<div class='pmo-head-actions'><a class='btn btn-primary btn-sm' href='" . self::e(Project::getFormURL()) . "'><i class='ti ti-plus me-1'></i>Novo projeto</a></div></div>";
        self::boot('portfolio', [
            'urls' => ['ajax' => Menu::url('/front/ajax.php')],
            'fields' => FieldsData::SHOWN,
        ]);
        echo self::script('pmo.js');
        echo self::script('drawer.js');
        echo self::script('portfolio.js');
        echo "</div>";
    }

    public static function showMyWork(): void
    {
        $rows = Portfolio::myWork((int) Session::getLoginUserID());
        $groups = ['late' => ['Atrasadas', 'danger'], 'soon' => ['Vencem em 7 dias', 'warning'], 'later' => ['Próximas', 'primary'], 'none' => ['Sem prazo', 'secondary']];
        echo "<div class='pmo'><div class='pmo-head'><div><div class='pmo-crumb'><i class='ti ti-briefcase me-1'></i>PMO</div><h2 class='pmo-title'>Meu trabalho</h2>" . self::subtitle('mywork') . "</div></div>";
        if (!$rows) {
            echo "<div class='card'><div class='card-body text-secondary'>Nenhuma tarefa aberta atribuída a você. 🎉</div></div></div>";
            return;
        }
        echo "<div class='row g-3'>";
        foreach ($groups as $key => [$label, $cls]) {
            $list = array_filter($rows, fn ($r) => $r['due'] === $key);
            echo "<div class='col-md-6 col-xl-3'><div class='card pmo-mywork'><div class='card-header'><h3 class='card-title'><span class='badge bg-$cls me-2'>" . count($list) . "</span>$label</h3></div><div class='list-group list-group-flush'>";
            foreach ($list as $r) {
                $col = $r['column'] ? "<span class='badge pmo-badge' style='--c:" . self::e($r['color']) . "'>" . self::e($r['column']) . "</span>" : '';
                echo "<a class='list-group-item list-group-item-action' href='" . self::e(\ProjectTask::getFormURLWithID($r['id'])) . "'>"
                    . "<div class='fw-bold'>" . self::e($r['name']) . "</div>"
                    . "<div class='small text-secondary'>" . self::e($r['project']) . "</div>"
                    . "<div class='d-flex gap-2 align-items-center mt-1 small'>$col<span>" . (int) $r['percent_done'] . "%</span>"
                    . ($r['plan_end_date'] ? "<span class='ms-auto'><i class='ti ti-calendar me-1'></i>" . Html::convDate(substr($r['plan_end_date'], 0, 10)) . "</span>" : '')
                    . "</div></a>";
            }
            echo "</div></div></div>";
        }
        echo "</div></div>";
    }
}
