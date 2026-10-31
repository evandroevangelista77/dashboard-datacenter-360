<?php

namespace GlpiPlugin\Controleequipamentos;

use Plugin;
use Session;

/**
 * Ciclo de entrega de equipamento para novos colaboradores (8 etapas, ver Fluxo).
 * O motor comum fica em Ciclo; aqui ficam só os dados e o cadastro da etapa 1.
 */
class Entrega extends Ciclo
{
    public const TABLE = 'glpi_plugin_controleequipamentos_entregas';
    public const TABLE_ETAPAS = 'glpi_plugin_controleequipamentos_entrega_etapas';

    public static function fluxo(): string
    {
        return Fluxo::class;
    }

    public static function tabela(): string
    {
        return self::TABLE;
    }

    public static function tabelaEtapas(): string
    {
        return self::TABLE_ETAPAS;
    }

    public static function colunaCiclo(): string
    {
        return 'plugin_controleequipamentos_entregas_id';
    }

    public static function prefixo(): string
    {
        return 'CE';
    }

    public static function pagina(): string
    {
        return '/front/entrega.form.php';
    }

    public static function resumo(array $ciclo): string
    {
        return (string) ($ciclo['setor'] ?? '');
    }

    /** Admissões do plugin RH (onboarding) que ainda não têm ciclo de entrega. */
    public static function admissoesRh(): array
    {
        global $DB;
        if (!Plugin::isPluginActive('rh')) {
            return [];
        }
        $usadas = [];
        foreach ($DB->request(['SELECT' => ['rh_admissoes_id'], 'FROM' => self::TABLE, 'WHERE' => ['rh_admissoes_id' => ['>', 0], 'is_deleted' => 0, 'status' => ['!=', self::CANCELADA]]]) as $r) {
            $usadas[(int) $r['rh_admissoes_id']] = true;
        }
        $linhas = [];
        foreach (self::dadosRh() as $id => $a) {
            if (!isset($usadas[$id])) {
                $linhas[$id] = $a;
            }
        }
        return $linhas;
    }

    /** Dados de uma admissão do RH (nome, cargo, setor, início), ou null. */
    public static function admissaoRh(int $id): ?array
    {
        $dados = self::dadosRh();
        return $dados[$id] ?? null;
    }

    /** Admissões em andamento ou concluídas, com cargo e setor vindos das vagas do RH. */
    private static function dadosRh(): array
    {
        global $DB;
        if (!Plugin::isPluginActive('rh')) {
            return [];
        }
        $vagas = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_rh_vagas']) as $v) {
            $vagas[(int) $v['id']] = $v;
        }
        $cargos = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_rh_cargos']) as $c) {
            $cargos[(int) $c['id']] = $c['name'];
        }
        $deptos = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_rh_departamentos']) as $d) {
            $deptos[(int) $d['id']] = $d['name'];
        }
        $saida = [];
        $admissoes = $DB->request(['FROM' => 'glpi_plugin_rh_admissaos', 'WHERE' => ['is_deleted' => 0, 'status' => [1, 2]], 'ORDER' => 'start_date DESC']);
        foreach ($admissoes as $a) {
            $vaga = $vagas[(int) $a['plugin_rh_vagas_id']] ?? [];
            $saida[(int) $a['id']] = [
                'id'        => (int) $a['id'],
                'name'      => (string) $a['name'],
                'cargo'     => $cargos[(int) ($vaga['plugin_rh_cargos_id'] ?? 0)] ?? '',
                'setor'     => $deptos[(int) ($vaga['plugin_rh_departamentos_id'] ?? 0)] ?? '',
                'inicio'    => $a['start_date'],
                'status'    => (int) $a['status'] === 2 ? 'concluída' : 'em andamento',
                'entities_id' => (int) $a['entities_id'],
                'is_recursive' => (int) $a['is_recursive'],
            ];
        }
        return $saida;
    }

    /**
     * Inicia o ciclo: valida a etapa 1, grava o cadastro (preenchido pela admissão do RH, se houver),
     * conclui a etapa 1 e abre a etapa 2.
     * @return array{id?: int, erros?: string[]}
     */
    public static function criar(array $post): array
    {
        $rhId = (int) ($post['rh_admissao'] ?? 0);
        $rh = null;
        if ($rhId > 0) {
            $rh = self::admissaoRh($rhId);
            if (!$rh || !Session::haveAccessToEntity($rh['entities_id'], (bool) $rh['is_recursive'])) {
                return ['erros' => ['A admissão escolhida não está disponível no RH.']];
            }
            if (self::jaLigada($rhId)) {
                return ['erros' => ['Esta admissão já tem um ciclo de entrega.']];
            }
            // o RH já tem estes dados: só completa o que estiver em branco
            $post['colaborador'] = ($post['colaborador'] ?? '') !== '' ? $post['colaborador'] : $rh['name'];
            $post['setor'] = ($post['setor'] ?? '') !== '' ? $post['setor'] : $rh['setor'];
            $post['cargo'] = ($post['cargo'] ?? '') !== '' ? $post['cargo'] : $rh['cargo'];
            $post['data_inicio'] = ($post['data_inicio'] ?? '') !== '' ? $post['data_inicio'] : $rh['inicio'];
        }
        [$valores, $erros] = Fluxo::validar(1, $post);
        if ($erros) {
            return ['erros' => $erros];
        }
        $id = self::iniciar([
            'name'          => $valores['colaborador'],
            'setor'         => $valores['setor'],
            'cargo'         => $valores['cargo'] ?: null,
            'centro_custo'  => $valores['centro_custo'] ?: null,
            'data_inicio'   => $valores['data_inicio'] ?: null,
            'equipamentos'  => $valores['equipamentos'],
            'local_entrega' => $valores['local_entrega'] ?: null,
            'rh_admissoes_id' => $rhId,
        ], $valores);
        return ['id' => $id];
    }

    private static function jaLigada(int $rhId): bool
    {
        return countElementsInTable(self::TABLE, ['rh_admissoes_id' => $rhId, 'is_deleted' => 0, 'status' => ['!=', self::CANCELADA]]) > 0;
    }
}
