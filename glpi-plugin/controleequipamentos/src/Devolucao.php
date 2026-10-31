<?php

namespace GlpiPlugin\Controleequipamentos;

use Session;

/**
 * Devolução de equipamento de colaborador desligado.
 * Campos do formulário "Solicitação de Transporte de Serviços" (Nº 60.13.11, Rev. 02).
 */
class Devolucao extends Ciclo
{
    public const TABLE = 'glpi_plugin_controleequipamentos_devolucoes';
    public const TABLE_ITENS = 'glpi_plugin_controleequipamentos_devolucao_itens';
    public const TABLE_ETAPAS = 'glpi_plugin_controleequipamentos_devolucao_etapas';

    public static function fluxo(): string
    {
        return FluxoDevolucao::class;
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
        return 'plugin_controleequipamentos_devolucoes_id';
    }

    public static function prefixo(): string
    {
        return 'CD';
    }

    public static function pagina(): string
    {
        return '/front/devolucao.form.php';
    }

    public static function resumo(array $ciclo): string
    {
        return 'Solicitante: ' . ($ciclo['solicitante'] ?? '—') . ' · Centro de custo: ' . ($ciclo['centro_custo'] ?? '—');
    }

    public const TIPOS = [
        'correio'        => 'Correio',
        'motoboy'        => 'Motoboy',
        'transportadora' => 'Transportadora',
        'motorista'      => 'Motorista',
        'cartorio'       => 'Cartório',
        'copiadora'      => 'Copiadora',
    ];

    public const STATUS = [
        'aberta'    => 'Aberta',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];

    /** Colunas simples do formulário: chave => [rótulo, tipo, obrigatório]. */
    public const CAMPOS = [
        'name'             => ['Nome do colaborador', 'text', true],
        'solicitante'      => ['Solicitante', 'text', false],
        'centro_custo'     => ['Centro de custo', 'text', false],
        'data_admissao'    => ['Data de admissão', 'date', false],
        'rg'               => ['RG', 'text', false],
        'cpf'              => ['CPF', 'text', false],
        'data_saida'       => ['Data de saída (desligamento)', 'date', true],
        'coleta_endereco'  => ['Coleta: endereço completo', 'textarea', true],
        'coleta_data'      => ['Coleta: data', 'date', false],
        'coleta_horario'   => ['Coleta: horário', 'text', false],
        'coleta_contato'   => ['Coleta: contato', 'text', false],
        'entrega_endereco' => ['Entrega: endereço completo', 'textarea', true],
        'entrega_data'     => ['Entrega: data', 'date', false],
        'entrega_horario'  => ['Entrega: horário', 'text', false],
        'entrega_contato'  => ['Entrega: contato', 'text', false],
        'tipo_servico'     => ['Tipo de serviço', 'tipo', true],
        'transportadora'   => ['Transportadora (se houver)', 'text', false],
        'observacao'       => ['Observação', 'textarea', false],
    ];

    public const COLUNAS_ITEM = ['material', 'quantidade', 'peso', 'dimensao', 'serie', 'valor'];

    /**
     * Valida o formulário e devolve os valores prontos para o banco e os itens.
     * @return array{0: array, 1: array, 2: string[]}
     */
    public static function validar(array $post): array
    {
        $valores = [];
        $erros = [];
        foreach (self::CAMPOS as $key => [$label, $tipo, $obrigatorio]) {
            $valor = trim((string) ($post[$key] ?? ''));
            if ($obrigatorio && $valor === '') {
                $erros[] = "Preencha: $label.";
            }
            if ($tipo === 'date' && $valor !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
                $erros[] = "Data inválida em $label.";
            }
            if ($tipo === 'tipo' && $valor !== '' && !isset(self::TIPOS[$valor])) {
                $erros[] = 'Escolha um tipo de serviço válido.';
            }
            $valores[$key] = $valor === '' ? null : $valor;
        }

        $itens = [];
        foreach ((array) ($post['itens'] ?? []) as $linha) {
            $linha = array_map(static fn ($v) => trim((string) $v), (array) $linha);
            if (($linha['material'] ?? '') === '') {
                continue;
            }
            $itens[] = [
                'material'   => $linha['material'],
                'quantidade' => max(0, (int) ($linha['quantidade'] ?? 0)),
                'peso'       => $linha['peso'] ?? '',
                'dimensao'   => $linha['dimensao'] ?? '',
                'serie'      => $linha['serie'] ?? '',
                'valor'      => Menu::parseMoney((string) ($linha['valor'] ?? '')),
            ];
        }
        if (!$itens) {
            $erros[] = 'Informe pelo menos um material.';
        }
        return [$valores, $itens, $erros];
    }

    /**
     * Inicia o ciclo de devolução: grava o formulário (etapa 1), os materiais e abre a etapa 2.
     * @return array{id?: int, erros?: string[]}
     */
    public static function criar(array $post): array
    {
        [$valores, $itens, $erros] = self::validar($post);
        if ($erros) {
            return ['erros' => $erros];
        }
        global $DB;
        $total = array_sum(array_column($itens, 'valor'));
        $id = self::iniciar($valores + ['total' => $total], $valores);
        foreach ($itens as $item) {
            $DB->insert(self::TABLE_ITENS, $item + ['plugin_controleequipamentos_devolucoes_id' => $id]);
        }
        return ['id' => $id];
    }

    public static function itens(int $id): array
    {
        global $DB;
        $linhas = [];
        foreach ($DB->request(['FROM' => self::TABLE_ITENS, 'WHERE' => ['plugin_controleequipamentos_devolucoes_id' => $id], 'ORDER' => 'id ASC']) as $row) {
            $linhas[] = $row;
        }
        return $linhas;
    }
}
