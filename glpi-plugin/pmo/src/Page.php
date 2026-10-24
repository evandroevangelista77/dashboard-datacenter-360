<?php

namespace GlpiPlugin\Pmo;

use RuntimeException;

/**
 * Documentação do projeto: páginas em texto rico, com modelos de PMO.
 */
class Page
{
    public const TEMPLATES = [
        'tap' => ['Termo de Abertura do Projeto (TAP)', <<<HTML
<h2>1. Justificativa</h2><p>Por que o projeto existe; problema ou oportunidade.</p>
<h2>2. Objetivos (SMART)</h2><ul><li>Objetivo 1</li><li>Objetivo 2</li></ul>
<h2>3. Escopo resumido</h2><p><strong>Inclui:</strong> …</p><p><strong>Não inclui:</strong> …</p>
<h2>4. Entregas principais</h2><ul><li>…</li></ul>
<h2>5. Marcos e prazo</h2><table><tbody><tr><th>Marco</th><th>Data prevista</th></tr><tr><td>Kick-off</td><td></td></tr><tr><td>Go-live</td><td></td></tr><tr><td>Encerramento</td><td></td></tr></tbody></table>
<h2>6. Orçamento estimado</h2><p>R$ …</p>
<h2>7. Partes interessadas</h2><table><tbody><tr><th>Nome</th><th>Papel</th><th>Organização</th></tr><tr><td></td><td>Patrocinador</td><td></td></tr><tr><td></td><td>Gerente do projeto</td><td></td></tr><tr><td></td><td>Líder técnico</td><td></td></tr></tbody></table>
<h2>8. Premissas e restrições</h2><ul><li>…</li></ul>
<h2>9. Riscos iniciais</h2><p>Registre na aba <em>Riscos</em> do PMO.</p>
<h2>10. Aprovação</h2><p>Patrocinador: ____________________ Data: ___/___/______</p>
HTML],
        'escopo' => ['Declaração de Escopo / EAP', <<<HTML
<h2>Descrição do escopo do produto</h2><p>…</p>
<h2>Critérios de aceitação</h2><ul><li>…</li></ul>
<h2>EAP (Estrutura Analítica do Projeto)</h2><ol><li>Gerenciamento do projeto<ol><li>Planejamento</li><li>Monitoramento e controle</li><li>Encerramento</li></ol></li><li>Entrega 1<ol><li>Pacote de trabalho 1.1</li></ol></li><li>Entrega 2</li></ol>
<h2>Exclusões</h2><ul><li>…</li></ul>
<h2>Premissas</h2><ul><li>…</li></ul>
HTML],
        'raci' => ['Matriz RACI', <<<HTML
<p><strong>R</strong> = Responsável (executa) · <strong>A</strong> = Aprovador (responde) · <strong>C</strong> = Consultado · <strong>I</strong> = Informado</p>
<table><tbody><tr><th>Atividade / Entrega</th><th>Gerente do projeto</th><th>Líder técnico</th><th>Cliente</th><th>PMO</th></tr>
<tr><td>Planejamento</td><td>A/R</td><td>C</td><td>I</td><td>C</td></tr>
<tr><td>Execução técnica</td><td>A</td><td>R</td><td>I</td><td>I</td></tr>
<tr><td>Aceite das entregas</td><td>R</td><td>C</td><td>A</td><td>I</td></tr></tbody></table>
HTML],
        'comunicacao' => ['Plano de Comunicação', <<<HTML
<table><tbody><tr><th>O quê</th><th>Para quem</th><th>Responsável</th><th>Frequência</th><th>Canal</th></tr>
<tr><td>Status report</td><td>Cliente e patrocinador</td><td>Gerente do projeto</td><td>Semanal</td><td>E-mail</td></tr>
<tr><td>Daily</td><td>Equipe</td><td>Líder técnico</td><td>Diária</td><td>Teams</td></tr>
<tr><td>Comitê de acompanhamento</td><td>Diretoria</td><td>PMO</td><td>Mensal</td><td>Reunião</td></tr></tbody></table>
HTML],
        'ata' => ['Ata de Reunião', <<<HTML
<p><strong>Data:</strong> ___/___/______ · <strong>Local/canal:</strong> … · <strong>Facilitador:</strong> …</p>
<h2>Participantes</h2><ul><li>…</li></ul>
<h2>Pauta</h2><ol><li>…</li></ol>
<h2>Decisões</h2><ul><li>…</li></ul>
<h2>Ações</h2><table><tbody><tr><th>Ação</th><th>Responsável</th><th>Prazo</th></tr><tr><td></td><td></td><td></td></tr></tbody></table>
HTML],
        'status' => ['Status Report', <<<HTML
<p><strong>Período:</strong> … · <strong>Saúde:</strong> 🟢 / 🟡 / 🔴</p>
<h2>Realizado no período</h2><ul><li>…</li></ul>
<h2>Planejado para o próximo período</h2><ul><li>…</li></ul>
<h2>Pontos de atenção e riscos</h2><ul><li>…</li></ul>
<h2>Decisões necessárias</h2><ul><li>…</li></ul>
HTML],
        'licoes' => ['Lições Aprendidas', <<<HTML
<table><tbody><tr><th>Situação</th><th>O que funcionou</th><th>O que melhorar</th><th>Recomendação</th></tr><tr><td></td><td></td><td></td><td></td></tr></tbody></table>
HTML],
        'retro' => ['Retrospectiva da Sprint', <<<HTML
<p><strong>Sprint:</strong> … · <strong>Data:</strong> …</p>
<h2>😀 O que foi bem</h2><ul><li>…</li></ul>
<h2>😕 O que pode melhorar</h2><ul><li>…</li></ul>
<h2>🎯 Ações para a próxima sprint</h2><ul><li>…</li></ul>
HTML],
        'blank' => ['Página em branco', ''],
    ];

    public static function all(int $projectId): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'SELECT' => ['id', 'name', 'template', 'users_id', 'users_id_lastupdater', 'date_creation', 'date_mod'],
            'FROM'   => 'glpi_plugin_pmo_pages',
            'WHERE'  => ['projects_id' => $projectId],
            'ORDER'  => ['rank', 'id'],
        ]), false);
    }

    public static function find(int $projectId, int $id): ?array
    {
        global $DB;
        return $DB->request(['FROM' => 'glpi_plugin_pmo_pages', 'WHERE' => ['id' => $id, 'projects_id' => $projectId]])->current() ?: null;
    }

    public static function create(int $projectId, string $template, string $name = ''): int
    {
        global $DB;
        [$title, $html] = self::TEMPLATES[$template] ?? self::TEMPLATES['blank'];
        $now = $_SESSION['glpi_currenttime'];
        $max = (int) ($DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => 'glpi_plugin_pmo_pages', 'WHERE' => ['projects_id' => $projectId]])->current()['m'] ?? 0);
        $DB->insert('glpi_plugin_pmo_pages', [
            'projects_id'          => $projectId,
            'name'                 => mb_substr(trim($name) ?: $title, 0, 255),
            'template'             => isset(self::TEMPLATES[$template]) ? $template : 'blank',
            'content'              => $html,
            'rank'                 => $max + 1,
            'users_id'             => \Session::getLoginUserID() ?: 0,
            'users_id_lastupdater' => \Session::getLoginUserID() ?: 0,
            'date_creation'        => $now,
            'date_mod'             => $now,
        ]);
        return (int) $DB->insertId();
    }

    public static function save(int $projectId, int $id, string $name, string $content): void
    {
        global $DB;
        if (!self::find($projectId, $id)) {
            throw new RuntimeException('Página não encontrada.');
        }
        $DB->update('glpi_plugin_pmo_pages', [
            'name'                 => mb_substr(trim($name) ?: 'Sem título', 0, 255),
            'content'              => $content,
            'users_id_lastupdater' => \Session::getLoginUserID() ?: 0,
            'date_mod'             => $_SESSION['glpi_currenttime'],
        ], ['id' => $id]);
    }

    public static function delete(int $projectId, int $id): void
    {
        global $DB;
        $DB->delete('glpi_plugin_pmo_pages', ['id' => $id, 'projects_id' => $projectId]);
    }
}
