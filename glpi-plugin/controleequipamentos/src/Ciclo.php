<?php

namespace GlpiPlugin\Controleequipamentos;

use Config;
use Group;
use Plugin;
use Group_User;
use ITILSolution;
use Session;
use Ticket;
use Throwable;
use User;

/**
 * Motor comum dos ciclos (entrega e devolução).
 *
 * Um ciclo nasce dentro do plugin. Cada etapa é uma visita em uma tabela de etapas, com a data de
 * início, o prazo (SLO), o chamado aberto para o grupo da área e, ao concluir, a data e quem concluiu.
 * Concluir uma etapa resolve o chamado dela e abre o da próxima. "Não" na aprovação devolve para a
 * etapa 2 (retrabalho): a visita anterior fica no histórico e uma nova visita é aberta.
 *
 * As subclasses dizem onde ficam os dados (tabelas, coluna de ligação, fluxo e páginas).
 */
abstract class Ciclo
{
    public const ABERTA = 'aberta';
    public const CONCLUIDA = 'concluida';
    public const CANCELADA = 'cancelada';

    /** Classe do fluxo: Fluxo (entrega) ou FluxoDevolucao. */
    abstract public static function fluxo(): string;

    abstract public static function tabela(): string;

    abstract public static function tabelaEtapas(): string;

    /** Coluna da tabela de etapas que liga a visita ao ciclo. */
    abstract public static function colunaCiclo(): string;

    /** Prefixo do ciclo nos títulos dos chamados (CE, CD). */
    abstract public static function prefixo(): string;

    /** Página que mostra o ciclo, a partir da raiz do plugin (ex.: /front/entrega.form.php). */
    abstract public static function pagina(): string;

    /** Trecho do cadastro que aparece no chamado da etapa. */
    abstract public static function resumo(array $ciclo): string;

    /** Chave de configuração de uma etapa: CE_2_grupo, CD_3_slo… */
    public static function chaveEtapa(int $step, string $campo): string
    {
        return static::prefixo() . '_' . $step . '_' . $campo;
    }

    /** Todas as chaves de configuração deste ciclo (para desinstalar). */
    public static function configKeys(): array
    {
        $keys = [];
        foreach (range(2, static::fluxo()::ULTIMA) as $step) {
            foreach (['grupo', 'usuario', 'slo'] as $campo) {
                $keys[] = static::chaveEtapa($step, $campo);
            }
        }
        return $keys;
    }

    /** Valor configurado para a etapa, ou null quando não há configuração. */
    public static function configEtapa(int $step, string $campo): ?int
    {
        $config = Config::getConfigurationValues(Menu::CONFIG_CONTEXT);
        $chave = static::chaveEtapa($step, $campo);
        return isset($config[$chave]) && $config[$chave] !== '' ? (int) $config[$chave] : null;
    }

    /** Grupo que executa a etapa (0 quando não configurado). */
    public static function grupoDaEtapa(int $step): int
    {
        return self::configEtapa($step, 'grupo') ?? 0;
    }

    /** Usuário responsável pela etapa (0 quando é o grupo inteiro). */
    public static function responsavelDaEtapa(int $step): int
    {
        return self::configEtapa($step, 'usuario') ?? 0;
    }

    /** Prazo da etapa em dias: o configurado, senão o padrão do fluxo. */
    public static function sloEtapa(int $step): int
    {
        $flow = static::fluxo();
        return self::configEtapa($step, 'slo') ?? $flow::slo($step);
    }

    /** Quem pode concluir a etapa: o responsável definido, ou quem é do grupo quando não há responsável. */
    public static function podeAgir(int $step): bool
    {
        if (Session::haveRight(Menu::RIGHT, UPDATE)) {
            return true;
        }
        $me = (int) Session::getLoginUserID();
        $resp = self::responsavelDaEtapa($step);
        if ($resp > 0) {
            return $resp === $me;
        }
        $grupo = self::grupoDaEtapa($step);
        return $grupo > 0 && countElementsInTable(Group_User::getTable(), ['users_id' => $me, 'groups_id' => $grupo]) > 0;
    }

    /** Ciclo com acesso verificado, ou null. */
    public static function buscar(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => static::tabela(), 'WHERE' => ['id' => $id, 'is_deleted' => 0]]) as $row) {
            return Session::haveAccessToEntity((int) $row['entities_id'], (bool) $row['is_recursive']) ? $row : null;
        }
        return null;
    }

    /** Linhas do ciclo para a lista, respeitando as entidades do usuário. */
    public static function listar(string $filtro = 'todas'): array
    {
        global $DB;
        $where = ['is_deleted' => 0] + (new \DbUtils())->getEntitiesRestrictCriteria(static::tabela(), 'entities_id', '', true);
        if (in_array($filtro, [self::ABERTA, self::CONCLUIDA, self::CANCELADA], true)) {
            $where['status'] = $filtro;
        }
        $linhas = [];
        foreach ($DB->request(['FROM' => static::tabela(), 'WHERE' => $where, 'ORDER' => 'date_mod DESC', 'LIMIT' => 200]) as $row) {
            $linhas[] = $row;
        }
        return $linhas;
    }

    /** Visitas de cada etapa, na ordem em que aconteceram (inclui as devolvidas). */
    public static function etapas(int $id): array
    {
        global $DB;
        $linhas = [];
        foreach ($DB->request(['FROM' => static::tabelaEtapas(), 'WHERE' => [static::colunaCiclo() => $id], 'ORDER' => 'id ASC']) as $row) {
            $linhas[] = $row;
        }
        return $linhas;
    }

    /** Visita em andamento de uma etapa, ou null. */
    public static function etapaAberta(int $id, int $step): ?array
    {
        global $DB;
        foreach ($DB->request([
            'FROM'  => static::tabelaEtapas(),
            'WHERE' => [static::colunaCiclo() => $id, 'step' => $step, 'action' => self::ABERTA],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return null;
    }

    /** Tempo da visita em dias e se passou do SLO da etapa (só conta enquanto a visita está aberta). */
    public static function prazo(array $visita, string $agora = ''): array
    {
        $fim = $visita['date'] ?: ($agora ?: date('Y-m-d H:i:s'));
        $dias = max(0, (strtotime($fim) - strtotime($visita['date_start'] ?: $fim)) / 86400);
        $flow = static::fluxo();
        $slo = static::sloEtapa((int) $visita['step']);
        return ['dias' => $dias, 'slo' => $slo, 'atrasada' => $slo > 0 && $visita['action'] === self::ABERTA && $dias > $slo];
    }

    /**
     * Conclui a etapa atual. "Não" na aprovação devolve para a etapa 2; concluir a última etapa encerra o ciclo.
     * @return array{ok?: bool, erros?: string[]}
     */
    public static function avancar(int $id, array $post): array
    {
        global $DB;
        $flow = static::fluxo();
        $ciclo = self::buscar($id);
        if (!$ciclo) {
            return ['erros' => ['Ciclo não encontrado.']];
        }
        if ($ciclo['status'] !== self::ABERTA) {
            return ['erros' => ['Este ciclo já foi encerrado.']];
        }
        $step = (int) $ciclo['current_step'];
        if (!self::podeAgir($step)) {
            return ['erros' => ['Só quem é do grupo da etapa atual, ou tem direito de atualização, pode concluir esta etapa.']];
        }

        [$valores, $erros] = $flow::validar($step, $post);
        if ($erros) {
            return ['erros' => $erros];
        }

        $observacao = trim((string) ($post['observacao'] ?? ''));
        $agora = date('Y-m-d H:i:s');
        $uid = Session::getLoginUserID();
        $devolvida = $step === 3 && $valores['aprovado'] === 'nao';
        $encerrar = $step === $flow::ULTIMA;
        $proxima = $devolvida ? 2 : ($encerrar ? $step : $step + 1);

        $dados = array_merge(
            json_decode((string) $ciclo['dados'], true) ?: [],
            array_filter($valores, static fn ($v) => $v !== '')
        );
        $visita = self::etapaAberta($id, $step);
        self::concluirVisita($visita, $devolvida ? 'devolvida' : self::CONCLUIDA, $agora, $observacao, $valores, $uid);
        self::resolverChamado((int) ($visita['tickets_id'] ?? 0), self::textoConclusao($step, $devolvida, $encerrar, $uid, $observacao));

        $DB->update(static::tabela(), [
            'current_step' => $proxima,
            'status'       => $encerrar ? self::CONCLUIDA : self::ABERTA,
            'dados'        => json_encode($dados, JSON_UNESCAPED_UNICODE),
            'date_mod'     => $agora,
        ], ['id' => $id]);

        if (!$encerrar) {
            self::abrirEtapa(self::buscar($id), $proxima, $devolvida ? $observacao : '');
        }
        return ['ok' => true];
    }

    /** Cancela o ciclo e resolve o chamado da etapa em andamento. Exige o direito de atualização. */
    public static function cancelar(int $id, string $motivo): array
    {
        global $DB;
        $ciclo = self::buscar($id);
        if (!$ciclo || !Session::haveRight(Menu::RIGHT, UPDATE)) {
            return ['erros' => ['Você não pode cancelar este ciclo.']];
        }
        if ($ciclo['status'] !== self::ABERTA) {
            return ['erros' => ['Este ciclo já foi encerrado.']];
        }
        $agora = date('Y-m-d H:i:s');
        $visita = self::etapaAberta($id, (int) $ciclo['current_step']);
        if ($visita) {
            $DB->update(static::tabelaEtapas(), ['action' => self::CANCELADA, 'date' => $agora, 'users_id' => Session::getLoginUserID(), 'observacao' => $motivo ?: null], ['id' => $visita['id']]);
            self::resolverChamado((int) $visita['tickets_id'], 'Ciclo cancelado. ' . $motivo);
        }
        $DB->update(static::tabela(), ['status' => self::CANCELADA, 'date_mod' => $agora], ['id' => $id]);
        return ['ok' => true];
    }

    /**
     * Grava o ciclo já com a etapa 1 concluída e abre a etapa 2. Usado no cadastro.
     * @return int id do ciclo
     */
    protected static function iniciar(array $campos, array $dados): int
    {
        global $DB;
        $agora = date('Y-m-d H:i:s');
        $uid = Session::getLoginUserID();
        $DB->insert(static::tabela(), $campos + [
            'entities_id'   => self::entidadeAtiva(),
            'current_step'  => 2,
            'status'        => self::ABERTA,
            'dados'         => json_encode($dados, JSON_UNESCAPED_UNICODE),
            'users_id'      => $uid,
            'date_creation' => $agora,
            'date_mod'      => $agora,
            'is_deleted'    => 0,
        ]);
        $id = (int) $DB->insertId();

        // a etapa 1 nasce concluída: é o próprio cadastro do ciclo
        $DB->insert(static::tabelaEtapas(), [
            static::colunaCiclo() => $id,
            'step'        => 1,
            'action'      => self::CONCLUIDA,
            'date_start'  => $agora,
            'date'        => $agora,
            'users_id'    => $uid,
            'tickets_id'  => 0,
            'observacao'  => null,
            'dados'       => json_encode($dados, JSON_UNESCAPED_UNICODE),
        ]);

        self::abrirEtapa(self::buscar($id), 2, '');
        return $id;
    }

    /** Grava a etapa como concluída, devolvida ou cancelada (a visita fica no histórico). */
    protected static function concluirVisita(?array $visita, string $action, string $agora, string $observacao, array $valores, int $uid): void
    {
        global $DB;
        if (!$visita) {
            return;
        }
        $DB->update(static::tabelaEtapas(), [
            'action'     => $action,
            'date'       => $agora,
            'users_id'   => $uid,
            'observacao' => $observacao ?: null,
            'dados'      => json_encode($valores, JSON_UNESCAPED_UNICODE),
        ], ['id' => $visita['id']]);
    }

    /** Abre a visita da etapa, o chamado do grupo (ou do responsável) e avisa quem vai agir. */
    protected static function abrirEtapa(?array $ciclo, int $step, string $motivo): int
    {
        global $DB;
        if (!$ciclo) {
            return 0;
        }
        $ticketId = self::abrirChamado($ciclo, $step, $motivo);
        $grupo = self::grupoDaEtapa($step);
        $resp = self::responsavelDaEtapa($step);
        $DB->insert(static::tabelaEtapas(), [
            static::colunaCiclo() => (int) $ciclo['id'],
            'step'                => $step,
            'action'              => self::ABERTA,
            'date_start'          => date('Y-m-d H:i:s'),
            'users_id'            => Session::getLoginUserID(),
            'tickets_id'          => $ticketId,
            'groups_id'           => $grupo,
            'users_id_responsavel' => $resp,
        ]);
        self::avisar($ciclo, $step, $motivo);
        return $ticketId;
    }

    /**
     * Aviso (sino, pop-up e Teams, pelo plugin Avisos) para o responsável da etapa,
     * ou para os membros do grupo quando não há responsável definido.
     */
    protected static function avisar(array $ciclo, int $step, string $motivo): void
    {
        global $DB;
        if (!Plugin::isPluginActive('avisos') || !class_exists(\GlpiPlugin\Avisos\Aviso::class)) {
            return;
        }
        $flow = static::fluxo();
        $resp = self::responsavelDaEtapa($step);
        $grupo = self::grupoDaEtapa($step);
        $membros = $grupo > 0 ? array_map(fn ($r) => (int) $r['users_id'], iterator_to_array(
            $DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $grupo]]), false)) : [];
        $usuarios = $resp > 0 ? [$resp] : $membros;
        $slo = self::sloEtapa($step);
        try {
            \GlpiPlugin\Avisos\Aviso::send([
                'source'  => 'controleequipamentos',
                'event'   => static::prefixo() . '-etapa-' . $step,
                'users'   => $usuarios,
                'title'   => static::prefixo() . '-' . $ciclo['id'] . ' · nova etapa: ' . $flow::nome($step),
                'message' => $flow::ETAPAS[$step][2] . ($motivo !== '' ? ' Devolvida: ' . $motivo : ''),
                'level'   => $motivo !== '' ? 'warning' : 'info',
                'url'     => Menu::url(static::pagina() . '?id=' . (int) $ciclo['id']),
                'item'    => [static::class, (int) $ciclo['id']],
                'teams'   => true,
                'facts'   => ['Etapa' => $step . '. ' . $flow::nome($step), 'Área' => $flow::AREAS[$flow::area($step)],
                    'Prazo' => $slo ? $slo . ' dia(s)' : 'sem prazo', 'Ciclo' => $ciclo['name']],
            ]);
        } catch (Throwable $ex) {
            trigger_error('Controle de Equipamentos: aviso não enviado: ' . $ex->getMessage(), E_USER_WARNING);
        }
    }

    /** Um chamado por etapa, atribuído ao grupo da área, com o link para o ciclo. */
    private static function abrirChamado(array $ciclo, int $step, string $motivo): int
    {
        $flow = static::fluxo();
        $e = [Menu::class, 'e'];
        $grupo = self::grupoDaEtapa($step);
        $resp = self::responsavelDaEtapa($step);
        $url = Menu::absolute(Menu::url(static::pagina() . '?id=' . (int) $ciclo['id']));
        $slo = self::sloEtapa($step);
        $content = '<p><strong>' . $e(static::prefixo() . '-' . $ciclo['id'] . ' · ' . $ciclo['name']) . '</strong> — ' . $e(static::resumo($ciclo)) . '</p>'
            . ($motivo !== '' ? '<p><strong>Devolvida para Compras:</strong> ' . $e($motivo) . '</p>' : '')
            . '<p>' . $e($flow::ETAPAS[$step][2]) . '</p>'
            . ($slo ? '<p>Prazo sugerido: ' . $slo . ' dia(s).</p>' : '')
            . '<p>Conclua a etapa no ciclo: <a href="' . $e($url) . '">' . $e($url) . '</a></p>';
        try {
            $id = (new Ticket())->add([
                'name'        => '[' . static::prefixo() . '-' . $ciclo['id'] . '] Etapa ' . $step . ': ' . $flow::nome($step) . ' — ' . $ciclo['name'],
                'content'     => $content,
                'entities_id' => (int) $ciclo['entities_id'],
                'type'        => Ticket::DEMAND_TYPE,
                '_actors'     => [
                    'requester' => [['itemtype' => 'User', 'items_id' => (int) Session::getLoginUserID(), 'use_notification' => 1]],
                    'assign'    => array_values(array_filter([
                        $grupo > 0 ? ['itemtype' => Group::class, 'items_id' => $grupo, 'use_notification' => 1] : null,
                        $resp > 0 ? ['itemtype' => 'User', 'items_id' => $resp, 'use_notification' => 1] : null,
                    ])),
                ],
            ]);
            return $id ? (int) $id : 0;
        } catch (Throwable $ex) {
            trigger_error('Controle de Equipamentos: chamado da etapa não aberto: ' . $ex->getMessage(), E_USER_WARNING);
            return 0;
        }
    }

    /** Resolve o chamado da etapa com a solução (o GLPI marca como resolvido). */
    protected static function resolverChamado(int $ticketId, string $texto): void
    {
        $t = new Ticket();
        if (!$ticketId || !$t->getFromDB($ticketId) || in_array((int) $t->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)) {
            return;
        }
        try {
            (new ITILSolution())->add(['itemtype' => Ticket::class, 'items_id' => $ticketId, 'content' => '<p>' . nl2br(Menu::e($texto)) . '</p>']);
        } catch (Throwable $ex) {
            trigger_error('Controle de Equipamentos: chamado não resolvido: ' . $ex->getMessage(), E_USER_WARNING);
        }
    }

    private static function textoConclusao(int $step, bool $devolvida, bool $encerrar, int $uid, string $observacao): string
    {
        $flow = static::fluxo();
        $quem = User::getFriendlyNameById($uid);
        $texto = 'Etapa ' . $step . ' (' . $flow::nome($step) . ') ' . ($devolvida ? 'não aprovada' : 'concluída') . " por $quem.";
        if ($devolvida) {
            $texto .= ' Ciclo devolvido para Compras (etapa 2).';
        } elseif ($encerrar) {
            $texto .= ' Ciclo encerrado.';
        }
        if ($observacao !== '') {
            $texto .= "\nObservação: $observacao";
        }
        return $texto;
    }

    protected static function entidadeAtiva(): int
    {
        return (int) ($_SESSION['glpiactive_entity'] ?? 0);
    }
}
