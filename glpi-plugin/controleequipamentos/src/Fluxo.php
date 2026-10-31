<?php

namespace GlpiPlugin\Controleequipamentos;

/**
 * Fluxo de entrega de equipamento: as 8 etapas, a área de cada uma, os campos que
 * ela exige para avançar e as regras de retorno e de encerramento.
 *
 * Tipos de campo: text, textarea, date, money, sim_nao (precisa ser "sim" se obrigatório),
 * decisao (sim ou não são respostas válidas; "não" devolve a etapa 2).
 */
class Fluxo
{
    public const AREAS = [
        'rh'            => 'RH',
        'compras'       => 'Compras',
        'gestao_custos' => 'Gestão de Custos',
        'faturamento'   => 'Faturamento',
        'logistica'     => 'Logística',
        'si'            => 'Sistemas Internos',
    ];

    /** etapa => [nome, área, descrição, campos, SLO em dias] */
    public const ETAPAS = [
        1 => ['Abertura do chamado', 'rh', 'Abrir o chamado no GLPI com as informações do novo colaborador.', [
            ['colaborador', 'Nome do colaborador', 'text', true],
            ['cargo', 'Cargo', 'text', false],
            ['setor', 'Setor', 'text', true],
            ['centro_custo', 'Centro de custo', 'text', false],
            ['data_inicio', 'Data de início', 'date', true],
            ['equipamentos', 'Equipamentos necessários', 'textarea', true],
            ['local_entrega', 'Local de entrega', 'textarea', false],
        ], 0],
        2 => ['Cotação da transportadora', 'compras', 'Cotar o frete com a transportadora.', [
            ['transportadora_cotada', 'Transportadora cotada', 'text', true],
            ['valor_cotado', 'Valor do frete cotado', 'money', true],
            ['prazo_entrega', 'Prazo de entrega (dias)', 'text', false],
        ], 3],
        3 => ['Aprovação e definição da transportadora', 'gestao_custos', 'Aprovar a cotação e definir a transportadora. Se não aprovar, o fluxo volta para Compras.', [
            ['aprovado', 'Cotação aprovada?', 'decisao', true],
            ['transportadora', 'Transportadora definida', 'text', true],
            ['valor_aprovado', 'Valor aprovado', 'money', false],
        ], 2],
        4 => ['Emissão da nota fiscal', 'faturamento', 'Emitir a nota fiscal e registrar o número.', [
            ['numero_nf', 'Número da nota fiscal', 'text', true],
            ['data_emissao', 'Data de emissão', 'date', true],
        ], 2],
        5 => ['Solicitação do transporte', 'compras', 'Acionar o transporte e enviar a documentação necessária.', [
            ['codigo_coleta', 'Número da coleta ou do transporte', 'text', false],
            ['data_coleta', 'Data prevista da coleta', 'date', false],
            ['documentacao_enviada', 'Documentação enviada', 'sim_nao', true],
        ], 3],
        6 => ['Recebimento e envio ao escritório', 'logistica', 'Receber os equipamentos e solicitar o envio ao escritório.', [
            ['data_recebimento', 'Data de recebimento', 'date', true],
            ['data_envio_escritorio', 'Data de envio ao escritório', 'date', false],
        ], 2],
        7 => ['Recebimento e formulário', 'rh', 'Receber os equipamentos e preencher o formulário, registrando a chegada.', [
            ['data_chegada', 'Data de chegada dos itens', 'date', true],
            ['itens_conferidos', 'Itens conferidos', 'sim_nao', true],
            ['formulario_preenchido', 'Formulário preenchido', 'sim_nao', true],
        ], 2],
        8 => ['Conferência final e configuração', 'si', 'Conferir, dar baixa e configurar os equipamentos. Ao concluir, o chamado é resolvido.', [
            ['patrimonio', 'Patrimônio', 'text', false],
            ['baixa_realizada', 'Baixa realizada', 'sim_nao', true],
            ['configuracao_concluida', 'Configuração concluída', 'sim_nao', true],
        ], 2],
    ];

    public const ULTIMA = 8;

    /** Quantos dias cada etapa pode levar (sugestão inicial, em dias corridos; 0 = sem prazo). */
    public static function slo(int $step): int
    {
        return (int) (static::ETAPAS[$step][4] ?? 0);
    }

    public static function nome(int $step): string
    {
        return static::ETAPAS[$step][0] ?? '';
    }

    public static function area(int $step): string
    {
        return static::ETAPAS[$step][1] ?? '';
    }

    public static function campos(int $step): array
    {
        return static::ETAPAS[$step][3] ?? [];
    }

    /** Chave da configuração que guarda o grupo de cada área. */
    public static function chaveGrupo(string $area): string
    {
        return 'grupo_' . $area;
    }

    public static function chavesGrupo(): array
    {
        return array_map([self::class, 'chaveGrupo'], array_keys(self::AREAS));
    }

    /**
     * Valida os campos de uma etapa a partir do POST.
     * @return array{0: array<string,string>, 1: string[]} valores válidos e mensagens de erro
     */
    public static function validar(int $step, array $post): array
    {
        $valores = [];
        $erros = [];
        // na etapa 3, a transportadora só é exigida quando a cotação é aprovada
        $devolve = $step === 3 && (($post['aprovado'] ?? '') === 'nao');

        foreach (self::campos($step) as [$key, $label, $tipo, $obrigatorio]) {
            $valor = trim((string) ($post[$key] ?? ''));
            if ($tipo === 'money') {
                $valor = (string) Menu::parseMoney($valor);
            }
            $valores[$key] = $valor;

            if ($devolve && $key !== 'aprovado') {
                continue;
            }
            if ($obrigatorio && $valor === '') {
                $erros[] = "Preencha: $label.";
                continue;
            }
            if ($tipo === 'sim_nao' && $obrigatorio && $valor !== 'sim') {
                $erros[] = "$label precisa estar marcado como sim para avançar.";
            }
            if ($tipo === 'decisao' && $valor !== '' && !in_array($valor, ['sim', 'nao'], true)) {
                $erros[] = "Escolha sim ou não em $label.";
            }
            if ($tipo === 'date' && $valor !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
                $erros[] = "Data inválida em $label.";
            }
        }
        if ($step === 3 && !in_array($valores['aprovado'] ?? '', ['sim', 'nao'], true)) {
            $erros[] = 'Informe se a cotação foi aprovada.';
        }
        return [$valores, $erros];
    }
}
