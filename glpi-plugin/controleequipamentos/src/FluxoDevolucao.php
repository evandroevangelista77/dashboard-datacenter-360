<?php

namespace GlpiPlugin\Controleequipamentos;

/**
 * Ciclo de devolução de equipamento de colaborador desligado.
 *
 * A etapa 1 é o próprio formulário de solicitação (RH). As etapas seguintes levam o equipamento
 * do pedido de transporte até a baixa no patrimônio. As áreas e as regras de retorno são as mesmas
 * da entrega (ver Fluxo): "não" na aprovação de custo devolve para Compras.
 */
class FluxoDevolucao extends Fluxo
{
    public const ULTIMA = 6;

    /** etapa => [nome, área, descrição, campos, SLO em dias] */
    public const ETAPAS = [
        1 => ['Cadastro e solicitação', 'rh', 'Preencher o formulário de solicitação de transporte com os dados do desligado e os materiais.', [], 0],
        2 => ['Solicitação do transporte', 'compras', 'Definir a transportadora e o valor do frete.', [
            ['transportadora_cotada', 'Transportadora', 'text', true],
            ['valor_cotado', 'Valor do frete', 'money', true],
        ], 3],
        3 => ['Aprovação do custo', 'gestao_custos', 'Aprovar o valor do frete. Se não aprovar, o ciclo volta para Compras.', [
            ['aprovado', 'Custo aprovado?', 'decisao', true],
            ['valor_aprovado', 'Valor aprovado', 'money', false],
        ], 2],
        4 => ['Nota fiscal do transporte', 'faturamento', 'Emitir a nota fiscal do transporte e registrar o número.', [
            ['numero_nf', 'Número da nota fiscal', 'text', true],
            ['data_emissao', 'Data de emissão', 'date', true],
        ], 2],
        5 => ['Coleta dos equipamentos', 'logistica', 'Confirmar a coleta dos equipamentos com a transportadora.', [
            ['data_coleta', 'Data da coleta', 'date', true],
            ['coleta_realizada', 'Coleta realizada', 'sim_nao', true],
        ], 3],
        6 => ['Recebimento e baixa', 'si', 'Receber os equipamentos, dar baixa no patrimônio e encerrar o ciclo.', [
            ['data_recebimento', 'Data de recebimento', 'date', true],
            ['patrimonio', 'Patrimônio', 'text', false],
            ['baixa_realizada', 'Baixa realizada', 'sim_nao', true],
        ], 2],
    ];
}
