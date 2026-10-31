<?php

namespace GlpiPlugin\Gestaoeventos;

use Html;
use Profile;
use ProfileRight;
use Session;

/**
 * Controle de acesso por perfil. Além do direito geral "Eventos" (ler, criar, atualizar, excluir, aprovar,
 * financeiro, ver só os envolvidos), cada módulo do plugin (abas, painel, relatórios, exportação, dossiê,
 * dicas…) tem o próprio direito: Ver e, nos módulos de trabalho, Editar. A matriz fica em
 * Administração → Perfis → aba Gestão de Eventos, com modelos prontos (Solicitante, Marketing, PMO,
 * Compras, Financeiro, Diretoria, Somente leitura). Tudo é conferido no servidor. Quem administra a
 * configuração do GLPI sempre tem acesso a tudo, para ninguém se trancar para fora.
 */
class Acesso
{
    /** Direito "ver só os eventos em que estou envolvido" (no direito geral Eventos). */
    public const RESTRICT = 4096;

    /** chave => [rótulo, ícone, grupo, tipo ('rw' ver e editar, 'r' só ver), o que controla] */
    public const MODULES = [
        'painel'       => ['Painel', 'ti-chart-dots-3', 'Consulta', 'r', 'Painel de Eventos com os indicadores e o pop-up de próximos eventos.'],
        'calendario'   => ['Calendário', 'ti-calendar', 'Consulta', 'r', 'Visão em calendário dos eventos.'],
        'relatorios'   => ['Relatórios', 'ti-report-analytics', 'Consulta', 'r', 'Tela de relatórios (os financeiros também exigem o módulo Financeiro).'],
        'exportar'     => ['Exportar (CSV)', 'ti-download', 'Consulta', 'r', 'Exportação dos relatórios e do demonstrativo financeiro.'],
        'dossie'       => ['Dossiê executivo (PDF)', 'ti-file-type-pdf', 'Consulta', 'r', 'Gerar e baixar o dossiê e o relatório final do evento.'],
        'historico'    => ['Linha do tempo e auditoria', 'ti-timeline-event', 'Consulta', 'r', 'Aba Linha do tempo com a trilha de auditoria.'],
        'dicas'        => ['Dicas e próximos passos', 'ti-bulb', 'Consulta', 'r', 'Assistente "o que fazer agora" em cada evento.'],
        'aprovacoes'   => ['Aprovações', 'ti-signature', 'Fluxo', 'rw', 'Ver as aprovações; Editar = solicitar e delegar.'],
        'decidir'      => ['Decidir aprovações', 'ti-circle-check', 'Fluxo', 'r', 'Aprovar, rejeitar ou postergar (além de estar na alçada).'],
        'planejamento' => ['Planejamento', 'ti-route', 'Fluxo', 'rw', 'Projeto, marcos, kickoff, RACI, riscos, mudanças.'],
        'checklists'   => ['Checklists', 'ti-list-check', 'Fluxo', 'rw', 'Checklists por etapa.'],
        'marketing'    => ['Marketing', 'ti-speakerphone', 'Operação', 'rw', 'Peças, brindes (com foto), patrocinadores, programação, convidados e importação de planilha.'],
        'compras'      => ['Compras', 'ti-shopping-cart', 'Operação', 'rw', 'Requisições, propostas, contratos, aditivos, recebimentos e NF.'],
        'logistica'    => ['Logística', 'ti-truck-delivery', 'Operação', 'rw', 'Materiais, reservas, contingência e aceites.'],
        'execucao'     => ['Execução', 'ti-player-play', 'Operação', 'rw', 'Atividades e ocorrências.'],
        'encerramento' => ['Encerramento', 'ti-flag-check', 'Operação', 'rw', 'Resultados, leads, lições, satisfação e conciliação.'],
        'documentos'   => ['Documentos', 'ti-files', 'Operação', 'rw', 'Ver a biblioteca; Editar = anexar e remover arquivos e pasta SharePoint.'],
        'financeiro'   => ['Financeiro', 'ti-cash', 'Financeiro', 'rw', 'Budget, EAC, realizado, orçamento base e valores no painel e nos relatórios.'],
        'viagens'      => ['Viagens', 'ti-plane', 'Financeiro', 'rw', 'Simulador de custos e cotação Uber.'],
        'avisos'       => ['Avisos do evento', 'ti-bell-ringing', 'Administração', 'rw', 'Regra de avisos e contagem regressiva de cada evento.'],
    ];

    /** Carrega na sessão os direitos dos módulos quando ela é anterior à instalação/atualização do plugin. */
    public static function sessionLoad(): void
    {
        $pid = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if (!$pid) {
            return;
        }
        $missing = array_values(array_filter(self::names(), fn ($n) => !isset($_SESSION['glpiactiveprofile'][$n])));
        if (!$missing) {
            return;
        }
        foreach (\ProfileRight::getProfileRights($pid, $missing) as $name => $rights) {
            $_SESSION['glpiactiveprofile'][$name] = (int) $rights;
        }
    }

    public static function rightName(string $key): string
    {
        return 'plugin_gestaoeventos_' . $key;
    }

    public static function names(): array
    {
        return array_map([self::class, 'rightName'], array_keys(self::MODULES));
    }

    // ------------------------------------------------------------------ consulta de direitos

    /** Direitos do módulo no perfil ativo; sem a linha no perfil (sessão antiga) deriva do direito geral. */
    public static function bits(string $key, array $profileRights = null): int
    {
        $p = $profileRights ?? ($_SESSION['glpiactiveprofile'] ?? []);
        $name = self::rightName($key);
        if (isset($p[$name])) {
            return (int) $p[$name];
        }
        return self::derive((int) ($p[Evento::$rightname] ?? 0), false)[$key] ?? 0;
    }

    public static function can(string $key, int $bit = READ): bool
    {
        if (!isset(self::MODULES[$key]) || Session::haveRight('config', UPDATE)) {
            return true;
        }
        $bits = self::bits($key);
        return $bit === UPDATE ? ($bits & UPDATE) && ($bits & READ) : (bool) ($bits & $bit);
    }

    public static function canView(string $key): bool
    {
        return self::can($key, READ);
    }

    public static function canEdit(string $key): bool
    {
        return self::can($key, UPDATE);
    }

    /** Mensagem padrão quando o perfil não edita o módulo. */
    public static function denyEdit(string $key): \RuntimeException
    {
        return new \RuntimeException('Seu perfil não tem permissão para editar "' . (self::MODULES[$key][0] ?? $key) . '". Peça ao administrador (Administração → Perfis → Gestão de Eventos).');
    }

    public static function requireEdit(string $key): void
    {
        if (!self::canEdit($key)) {
            throw self::denyEdit($key);
        }
    }

    // ------------------------------------------------------------------ ver só os envolvidos

    public static function restricted(): bool
    {
        return Session::haveRight(Evento::$rightname, self::RESTRICT) && !Session::haveRight('config', UPDATE);
    }

    private static array $involved = [];

    /** Eventos em que o usuário atua: solicitante, dono, GP, membro de grupo de alguma etapa ou aprovador pendente. */
    public static function involvedIds(): array
    {
        global $DB;
        $me = (int) Session::getLoginUserID();
        if (isset(self::$involved[$me])) {
            return self::$involved[$me];
        }
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Evento::getTable(), 'WHERE' => ['OR' => ['users_id_requester' => $me, 'users_id_owner' => $me, 'users_id_gp' => $me]]]) as $r) {
            $ids[] = (int) $r['id'];
        }
        $groups = array_map(fn ($r) => (int) $r['groups_id'], iterator_to_array($DB->request(['SELECT' => 'groups_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $me]]), false));
        if ($groups) {
            foreach ($DB->request(['SELECT' => ['plugin_gestaoeventos_eventos_id'], 'FROM' => Fase::getTable(), 'WHERE' => ['groups_id' => $groups]]) as $r) {
                $ids[] = (int) $r['plugin_gestaoeventos_eventos_id'];
            }
        }
        foreach ($DB->request(['FROM' => Aprovacao::getTable(), 'WHERE' => ['status' => ['pending', 'waiting']]]) as $a) {
            if (in_array($me, Aprovacao::approverIds($a), true) || $me === (int) $a['users_id_requested']) {
                $ids[] = (int) $a['plugin_gestaoeventos_eventos_id'];
            }
        }
        return self::$involved[$me] = array_values(array_unique($ids));
    }

    public static function involved(array $evento): bool
    {
        return in_array((int) $evento['id'], self::involvedIds(), true);
    }

    /** Critério extra para as consultas de eventos quando o perfil só vê os envolvidos ($prefix = alias da tabela). */
    public static function scope(string $prefix = ''): array
    {
        if (!self::restricted()) {
            return [];
        }
        $p = $prefix !== '' ? $prefix . '.' : '';
        $me = (int) Session::getLoginUserID();
        return ['OR' => [$p . 'users_id_requester' => $me, $p . 'users_id_owner' => $me, $p . 'users_id_gp' => $me, $p . 'id' => self::involvedIds() ?: [0]]];
    }

    // ------------------------------------------------------------------ padrões e modelos

    /**
     * Direitos de cada módulo derivados do direito geral Eventos (usado na instalação e para sessões
     * anteriores à instalação). $admin = o perfil administra a configuração do GLPI.
     */
    public static function derive(int $base, bool $admin): array
    {
        $read = (bool) ($base & READ) || $admin;
        $write = (bool) ($base & (UPDATE | CREATE)) || $admin;
        $fin = (bool) ($base & Evento::FINANCE) || $admin;
        $out = [];
        foreach (self::MODULES as $k => [, , , $kind]) {
            $r = $read;
            $w = $kind === 'rw' && $read && $write;
            if (in_array($k, ['financeiro', 'viagens'], true)) {
                $r = $fin;
                $w = $fin;
            }
            if ($k === 'exportar') {
                $r = $read && ($fin || $admin);
            }
            if ($k === 'avisos') {
                $w = $read && ($admin || (bool) ($base & UPDATE));
            }
            if ($k === 'decidir') {
                $r = $read;
            }
            $out[$k] = ($r ? READ : 0) | ($w ? UPDATE : 0);
        }
        return $out;
    }

    /** Modelos de perfil: [nome, descrição, direito geral, [módulo => 'r'|'w']] ('w' inclui ver). */
    public static function models(): array
    {
        $all = array_keys(self::MODULES);
        $view = ['painel', 'calendario', 'relatorios', 'dossie', 'historico', 'dicas'];
        $mk = function (array $r = [], array $w = []) use ($all) {
            $o = array_fill_keys($all, '');
            foreach ($r as $k) {
                $o[$k] = 'r';
            }
            foreach ($w as $k) {
                $o[$k] = 'w';
            }
            return $o;
        };
        $fluxo = ['aprovacoes', 'planejamento', 'checklists', 'marketing', 'compras', 'logistica', 'execucao', 'encerramento', 'documentos'];
        return [
            'solicitante' => ['Solicitante', 'Abre o briefing e acompanha o evento. Anexa documentos. Não vê valores.', READ | CREATE, $mk(array_merge($view, ['aprovacoes', 'planejamento', 'checklists', 'marketing', 'compras', 'logistica', 'execucao', 'encerramento', 'decidir']), ['documentos'])],
            'marketing'   => ['Marketing', 'Triagem, peças, brindes, convidados, produção e execução. Sem valores financeiros.', READ | CREATE, $mk(array_merge($view, ['aprovacoes', 'planejamento', 'compras', 'encerramento']), ['marketing', 'checklists', 'logistica', 'execucao', 'documentos', 'avisos'])],
            'pmo'         => ['PMO / Gerente de projeto', 'Coordena as etapas, edita qualquer evento e fecha o ciclo. Vê o financeiro só se receber o módulo.', READ | CREATE | UPDATE, $mk(array_merge($view, ['marketing', 'compras', 'decidir']), ['aprovacoes', 'planejamento', 'checklists', 'logistica', 'execucao', 'encerramento', 'documentos', 'avisos'])],
            'compras'     => ['Compras / Contratos', 'Cotações, contratos e recebimentos. Não fura o gate financeiro e não vê o orçamento.', READ | CREATE, $mk(array_merge($view, ['aprovacoes', 'planejamento', 'checklists', 'marketing', 'logistica', 'execucao', 'encerramento', 'decidir']), ['compras', 'documentos'])],
            'financeiro'  => ['Financeiro', 'Budget, EAC, realizado, viagens, conciliação, relatórios e exportação.', READ | CREATE | Evento::FINANCE, $mk(array_merge($view, ['planejamento', 'checklists', 'marketing', 'logistica', 'execucao', 'exportar', 'decidir']), ['aprovacoes', 'compras', 'financeiro', 'viagens', 'encerramento', 'documentos'])],
            'diretoria'   => ['Diretoria / Liderança', 'Decide as aprovações e acompanha tudo, inclusive o financeiro. Não edita.', READ | Evento::APPROVE, $mk(array_merge($view, ['aprovacoes', 'decidir', 'planejamento', 'checklists', 'marketing', 'compras', 'logistica', 'execucao', 'encerramento', 'documentos', 'financeiro', 'viagens', 'exportar']), [])],
            'leitura'     => ['Somente leitura', 'Consulta o andamento dos eventos que enxerga, sem valores e sem editar.', READ, $mk(array_merge($view, $fluxo), [])],
            'admin'       => ['Administrador do plugin', 'Tudo: edita, aprova, financeiro, exporta e configura.', READ | CREATE | UPDATE | PURGE | Evento::APPROVE | Evento::FINANCE, $mk([], $all)],
        ];
    }

    /** Escreve os direitos de um perfil: o geral e os módulos (r = Ver, w = Ver + Editar). */
    public static function write(int $profileId, int $base, array $modules): void
    {
        global $DB;
        $all = [Evento::$rightname => $base];
        foreach (self::MODULES as $k => [, , , $kind]) {
            $m = $modules[$k] ?? '';
            $all[self::rightName($k)] = $m === 'w' && $kind === 'rw' ? READ | UPDATE : ($m === 'r' || $m === 'w' ? READ : 0);
        }
        foreach ($all as $name => $rights) {
            if (!countElementsInTable(ProfileRight::getTable(), ['profiles_id' => $profileId, 'name' => $name])) {
                $DB->insert(ProfileRight::getTable(), ['profiles_id' => $profileId, 'name' => $name, 'rights' => $rights]);
            } else {
                $DB->update(ProfileRight::getTable(), ['rights' => $rights], ['profiles_id' => $profileId, 'name' => $name]);
            }
        }
        // se é o perfil ativo do usuário, vale já
        if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === $profileId) {
            foreach ($all as $name => $rights) {
                $_SESSION['glpiactiveprofile'][$name] = $rights;
            }
        }
        unset($_SESSION['glpimenu']);
    }

    /** Instalação: cria os direitos dos módulos e, nos perfis que ainda não os têm, deriva do direito geral. */
    public static function seed(): void
    {
        global $DB;
        foreach (self::names() as $name) {
            if (!countElementsInTable(ProfileRight::getTable(), ['name' => $name])) {
                ProfileRight::addProfileRights([$name]);
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $p) {
                    $rights = ProfileRight::getProfileRights((int) $p['id'], ['config', Evento::$rightname]);
                    $admin = (bool) (($rights['config'] ?? 0) & UPDATE);
                    $key = substr($name, strlen('plugin_gestaoeventos_'));
                    $DB->update(ProfileRight::getTable(), ['rights' => self::derive((int) ($rights[Evento::$rightname] ?? 0), $admin)[$key] ?? 0], ['profiles_id' => $p['id'], 'name' => $name]);
                }
            }
        }
    }

    // ------------------------------------------------------------------ telas

    /** Matriz de módulos de um perfil e os modelos prontos (aba do perfil). */
    public static function profileForm(Profile $profile, bool $canedit): string
    {
        $e = [Menu::class, 'e'];
        $id = (int) $profile->getID();
        $rights = ProfileRight::getProfileRights($id, array_merge(self::names(), [Evento::$rightname, 'config']));
        $admin = (bool) (($rights['config'] ?? 0) & UPDATE);
        $base = (int) ($rights[Evento::$rightname] ?? 0);
        $action = Menu::url('/front/perfil.php');
        $h = "<div class='card mt-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-lock-access me-2'></i>Acesso por módulo — Gestão de Eventos</h3>"
            . "<div class='card-actions small text-secondary'>" . ($admin ? 'este perfil administra a configuração: acessa tudo' : '') . "</div></div>";
        $h .= "<div class='card-body border-bottom small text-secondary'>Cada módulo tem <b>Ver</b> e, nos de trabalho, <b>Editar</b>. A aba some para quem não vê e o servidor recusa a edição sem o direito. "
            . "Os direitos <b>Aprovar</b> (alçadas sem grupo), <b>Financeiro</b> e <b>Ver só os eventos em que está envolvido</b> ficam na tabela acima.</div>";
        if ($canedit) {
            $h .= "<form method='post' action='" . $e($action) . "' class='card-body border-bottom row g-2 align-items-end'><input type='hidden' name='profiles_id' value='$id'>"
                . "<div class='col-md-5'><label class='form-label'>Aplicar um modelo de acesso</label><select class='form-select' name='model'>";
            foreach (self::models() as $k => [$name, $desc]) {
                $h .= "<option value='$k'>" . $e($name . ' — ' . $desc) . "</option>";
            }
            $h .= "</select></div><div class='col-auto'><button class='btn btn-outline-primary' name='ge_model' value='1' onclick=\"return confirm('Substitui os direitos de Gestão de Eventos deste perfil pelos do modelo. Continuar?')\"><i class='ti ti-wand me-1'></i>Aplicar modelo</button></div>"
                . "<div class='col-auto small text-secondary'>Preenche o direito geral e todos os módulos; depois você ajusta o que quiser abaixo.</div>" . Html::closeForm(false);
        }
        $h .= "<form method='post' action='" . $e($action) . "'><input type='hidden' name='profiles_id' value='$id'><div class='table-responsive'><table class='table table-sm card-table table-vcenter'>"
            . "<thead><tr><th>Módulo</th><th class='text-center' style='width:90px'>Ver</th><th class='text-center' style='width:90px'>Editar</th><th>O que controla</th></tr></thead><tbody>";
        $group = '';
        foreach (self::MODULES as $k => [$label, $icon, $grp, $kind, $hint]) {
            if ($grp !== $group) {
                $h .= "<tr class='ge-acc-group'><td colspan='4'>" . $e($grp) . "</td></tr>";
                $group = $grp;
            }
            $bits = isset($rights[self::rightName($k)]) ? (int) $rights[self::rightName($k)] : (self::derive($base, $admin)[$k] ?? 0);
            $dis = $canedit ? '' : ' disabled';
            $h .= "<tr><td><i class='ti $icon me-2'></i>" . $e($label) . "</td>"
                . "<td class='text-center'><input class='form-check-input' type='checkbox' name='acc[$k][r]' value='1'" . (($bits & READ) ? ' checked' : '') . "$dis></td>"
                . "<td class='text-center'>" . ($kind === 'rw' ? "<input class='form-check-input' type='checkbox' name='acc[$k][w]' value='1'" . (($bits & UPDATE) ? ' checked' : '') . "$dis>" : "<span class='text-secondary'>—</span>") . "</td>"
                . "<td class='small text-secondary'>" . $e($hint) . "</td></tr>";
        }
        $h .= "</tbody></table></div>" . ($canedit ? "<div class='card-footer'><button class='btn btn-primary' name='ge_save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar o acesso por módulo</button></div>" : '') . Html::closeForm(false) . "</div>";
        return $h;
    }

    /** Visão geral: perfis × módulos (aba "Acesso" em Alçadas e regras). */
    public static function overview(): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $profiles = iterator_to_array($DB->request(['FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central'], 'ORDER' => 'name']), false);
        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-lock-access me-2'></i>Quem acessa o quê</h3></div>"
            . "<div class='card-body border-bottom small text-secondary'>Resumo dos direitos de cada perfil. <b>V</b> = ver, <b>E</b> = ver e editar. Para mudar, abra o perfil (aba Gestão de Eventos) ou aplique um modelo aqui. "
            . "Perfis que administram a configuração do GLPI acessam tudo.</div><div class='table-responsive'><table class='table table-sm card-table table-vcenter ge-acc-table'><thead><tr><th>Perfil</th><th>Geral</th>";
        foreach (self::MODULES as [$label, $icon]) {
            echo "<th class='text-center' title='" . $e($label) . "'><i class='ti $icon'></i></th>";
        }
        echo "<th>Modelo</th></tr></thead><tbody>";
        foreach ($profiles as $p) {
            $r = ProfileRight::getProfileRights((int) $p['id'], array_merge(self::names(), [Evento::$rightname, 'config']));
            $admin = (bool) (($r['config'] ?? 0) & UPDATE);
            $base = (int) ($r[Evento::$rightname] ?? 0);
            $flags = array_filter([$base & READ ? 'Ler' : '', $base & CREATE ? 'Criar' : '', $base & UPDATE ? 'Atualizar' : '', $base & Evento::APPROVE ? 'Aprovar' : '', $base & Evento::FINANCE ? 'Financeiro' : '', $base & self::RESTRICT ? 'Só envolvidos' : '']);
            echo "<tr><td><a href='" . $e(Profile::getFormURLWithID((int) $p['id'])) . "'><b>" . $e($p['name']) . "</b></a>" . ($admin ? " <span class='badge bg-blue-lt'>admin</span>" : '') . "</td><td class='small'>" . $e(implode(' · ', $flags) ?: '—') . "</td>";
            foreach (array_keys(self::MODULES) as $k) {
                $bits = $admin ? READ | UPDATE : (isset($r[self::rightName($k)]) ? (int) $r[self::rightName($k)] : 0);
                echo "<td class='text-center'>" . (($bits & UPDATE) && ($bits & READ) ? "<span class='ge-acc e'>E</span>" : (($bits & READ) ? "<span class='ge-acc v'>V</span>" : "<span class='ge-acc n'>·</span>")) . "</td>";
            }
            echo "<td><form method='post' action='" . $e(Menu::url('/front/perfil.php')) . "' class='d-flex gap-1 m-0'><input type='hidden' name='profiles_id' value='" . (int) $p['id'] . "'><input type='hidden' name='back' value='admin'>"
                . "<select class='form-select form-select-sm' name='model'>" . implode('', array_map(fn ($k, $m) => "<option value='$k'>" . $e($m[0]) . "</option>", array_keys(self::models()), self::models())) . "</select>"
                . "<button class='btn btn-sm btn-outline-primary' name='ge_model' value='1' onclick=\"return confirm('Aplicar o modelo a este perfil?')\">Aplicar</button>" . Html::closeForm(false) . "</td></tr>";
        }
        echo "</tbody></table></div><div class='card-footer ge-g-legend'>";
        foreach (self::MODULES as [$label, $icon]) {
            echo "<span><i class='ti $icon'></i> " . $e($label) . "</span>";
        }
        echo "</div></div>";
    }

    /** Lista simples de eventos para perfis que só veem os envolvidos (a busca nativa do GLPI não filtra por envolvimento). */
    public static function restrictedList(): void
    {
        global $DB;
        $e = [Menu::class, 'e'];
        $rows = iterator_to_array($DB->request(['FROM' => Evento::getTable(), 'WHERE' => ['is_deleted' => 0] + self::scope() + getEntitiesRestrictCriteria(Evento::getTable(), 'entities_id', '', true), 'ORDER' => 'date_start DESC']), false);
        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-lock-access me-2'></i>Meus eventos</h3><div class='card-actions small text-secondary'>seu perfil vê só os eventos em que você está envolvido · " . count($rows) . "</div></div>"
            . "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Evento</th><th>Tipo</th><th>Início</th><th>Cidade</th><th>Etapa</th><th>Aprovação</th><th>Situação</th></tr></thead><tbody>";
        foreach ($rows as $c) {
            echo "<tr><td><a href='" . $e(Evento::getFormURLWithID((int) $c['id'])) . "'><b>" . $e($c['code']) . "</b> " . $e($c['name']) . "</a></td><td class='small'>" . $e(Evento::TYPES[$c['event_type']][0] ?? '') . "</td><td class='small'>" . Menu::date($c['date_start'])
                . "</td><td class='small'>" . $e($c['city']) . "</td><td class='small'>" . $e($c['current_phase'] ? Fase::name((string) $c['current_phase']) : '—') . "</td><td>" . Evento::badge(Evento::APPROVAL, (string) $c['approval_status']) . "</td><td>" . Evento::statusBadge((int) $c['status']) . "</td></tr>";
        }
        echo $rows ? '' : "<tr><td colspan='7' class='text-secondary small'>Você ainda não está envolvido em nenhum evento.</td></tr>";
        echo "</tbody></table></div></div>";
    }
}
