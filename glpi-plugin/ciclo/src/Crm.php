<?php

namespace GlpiPlugin\Ciclo;

/**
 * Campos do Aviso de Fechamento do CRM, nos mesmos blocos do negócio no CRM:
 * Oportunidade, Dados da Oportunidade, Qualificação, Proposta, Informações
 * Adicionais, Validação de Conformidade e Contato. Os campos principais (venda,
 * cliente, valor, data em que ganhou, responsável) ficam nas colunas do Ciclo;
 * os demais ficam juntos em crm_data (JSON), prontos para virem da integração.
 */
class Crm
{
    public const YES_NO = ['sim' => 'Sim', 'nao' => 'Não'];
    public const YES_NO_NA = ['sim' => 'Sim', 'nao' => 'Não', 'na' => 'Não se aplica'];

    public const FUNNEL = ['prospeccao' => 'Prospecção', 'qualificacao' => 'Qualificação', 'desenho' => 'Desenho de Solução', 'negociacao' => 'Negociação',
        'commit' => 'Commit', 'conformidade' => 'Conformidade de Fechamento'];

    /**
     * bloco => [título, ícone, [chave => [rótulo, tipo, opções/dica, coluna]]]
     * tipos: text, textarea, date, number, percent, money, select (opções), yesno, yesnona
     */
    public const SECTIONS = [
        'oportunidade' => ['Oportunidade', 'ti-thumb-up', [
            'funnel_phase'   => ['Fase do funil', 'select', self::FUNNEL, 'col-md-4'],
            'crm_status'     => ['Status no CRM', 'select', ['aberto' => 'Aberto', 'ganhou' => 'Ganhou', 'perdeu' => 'Perdeu'], 'col-md-4'],
            'date_expected'  => ['Data prevista', 'date', '', 'col-md-4'],
        ]],
        'dados' => ['Dados da Oportunidade', 'ti-briefcase', [
            'business_front'   => ['Frente de Negócio', 'text', 'Ex.: Axon', 'col-md-4'],
            'origin'           => ['Origem da Oportunidade', 'select', ['base' => 'Base de clientes', 'indicacao' => 'Indicação', 'fabricante' => 'Fabricante / parceiro', 'marketing' => 'Marketing', 'prospeccao' => 'Prospecção', 'outro' => 'Outro'], 'col-md-4'],
            'origin_detail'    => ['Detalhe da Origem', 'text', 'Ex.: Base de clientes - Cross Sell', 'col-md-4'],
            'prospect_type'    => ['Tipo de Prospecção', 'select', ['ativa' => 'Ativa', 'receptiva' => 'Receptiva'], 'col-md-3'],
            'business_type'    => ['Tipo de negócio', 'select', ['privado' => 'Privado', 'publico' => 'Público'], 'col-md-3'],
            'contract_type'    => ['Tipo do Contrato', 'select', ['novo' => 'Novo', 'renovacao' => 'Renovação', 'aditivo' => 'Aditivo'], 'col-md-3'],
            'negotiation_type' => ['Tipo de Negociação', 'select', ['direta' => 'Negociação Direta', 'licitacao' => 'Licitação', 'pregao' => 'Pregão', 'ata' => 'Ata de registro de preço'], 'col-md-3'],
            'contract_months'  => ['Duração do contrato (meses)', 'number', '24', 'col-md-3'],
            'probability'      => ['Probabilidade de fechamento %', 'percent', '90', 'col-md-3'],
            'ro_number'        => ['Número do RO - Registro da Oportunidade', 'text', '', 'col-md-3'],
            'date_auction'     => ['Data do Pregão', 'date', '', 'col-md-3'],
        ]],
        'qualificacao' => ['Qualificação', 'ti-target-arrow', [
            'pain'             => ['Dor do Cliente', 'select', ['financeira' => 'Financeira', 'operacional' => 'Operacional', 'seguranca' => 'Segurança', 'compliance' => 'Compliance / regulatória', 'tecnologica' => 'Tecnológica', 'outra' => 'Outra'], 'col-md-4'],
            'project_name'     => ['Nome do Projeto', 'text', 'Ex.: REF.: 20260407 - Solução Axon - Câmeras corporais', 'col-md-8'],
            'pain_description' => ['Descrição das dores (Identified PAIN)', 'textarea', '', 'col-12'],
            'economic_buyer'   => ['Economic Buyer', 'text', '', 'col-md-4'],
            'decision_area'    => ['Área do Decisor', 'text', 'Ex.: Financeiro', 'col-md-4'],
            'am_estimate'      => ['Valor Estimado pelo AM (R$)', 'money', '', 'col-md-4'],
            'has_budget'       => ['O cliente tem orçamento (budget)?', 'yesno', '', 'col-md-4'],
            'talking_decider'  => ['Estamos negociando com quem decide?', 'yesno', '', 'col-md-4'],
            'needs_cert'       => ['Será necessário termos certificação para implementar?', 'yesno', '', 'col-md-4'],
        ]],
        'proposta' => ['Proposta', 'ti-file-text', [
            'manufacturer'     => ['Fabricante da Solução/principal', 'text', 'Ex.: Axon', 'col-md-4'],
            'proposal_id'      => ['ID da proposta', 'text', 'Ex.: 50302205', 'col-md-4'],
            'presales'         => ['Pré-Vendas Líder', 'text', '', 'col-md-4'],
            'proposal_version' => ['Versionamento da Proposta', 'text', 'Ex.: V1', 'col-md-3'],
            'has_poc'          => ['Projeto tem PoC?', 'yesno', '', 'col-md-3'],
            'payment_terms'    => ['Forma de Pagamento', 'text', 'Ex.: Recorrente - 24 meses', 'col-md-6'],
            'date_contract_end' => ['Data de Encerramento do Contrato', 'date', '', 'col-md-4'],
            'date_proposal_sent' => ['Data de envio da proposta ao cliente', 'date', '', 'col-md-4'],
            'proposal_sent'    => ['Proposta foi encaminhada para o cliente?', 'yesno', '', 'col-md-4'],
        ]],
        'adicionais' => ['Informações Adicionais', 'ti-info-circle', [
            'forecast_validated' => ['A previsão de fechamento foi validada com o cliente ou está sustentada pelo processo de decisão conhecido?', 'yesno', '', 'col-md-6'],
            'critical_pending'   => ['Existe alguma pendência crítica que possa impedir o fechamento na data prevista?', 'yesno', '', 'col-md-6'],
            'next_step'          => ['Existe próximo passo de fechamento registrado com responsável e data?', 'yesno', '', 'col-md-6'],
            'docs_attached'      => ['Documentos foram anexados ao negócio?', 'yesno', '', 'col-md-6'],
        ]],
        'conformidade' => ['Validação de Conformidade', 'ti-checklist', [
            'chk_commercial'  => ['Última proposta comercial anexada?', 'yesnona', '', 'col-md-4'],
            'chk_technical'   => ['Última proposta técnica anexada?', 'yesnona', '', 'col-md-4'],
            'chk_pf'          => ['Última versão da PF com validação do pré-vendas está anexada?', 'yesnona', '', 'col-md-4'],
            'chk_acceptance'  => ['Temos o aceite do Cliente e/ou PO?', 'yesnona', '', 'col-md-4'],
            'chk_quote'       => ['Cotação do fornecedor anexada?', 'yesnona', '', 'col-md-4'],
            'chk_bom'         => ['BOM (Bill of Materials) anexado?', 'yesnona', '', 'col-md-4'],
            'chk_contract'    => ['Contrato está anexado?', 'yesnona', '', 'col-md-4'],
            'chk_complete'    => ['A documentação está completa?', 'yesnona', '', 'col-md-4'],
            'chk_financial'   => ['As validações financeiras foram realizadas?', 'yesnona', '', 'col-md-4'],
        ]],
        'contato' => ['Contato e empresa', 'ti-address-book', [
            'contact_name'  => ['Nome do contato', 'text', '', 'col-md-4'],
            'contact_email' => ['E-mail do contato', 'text', '', 'col-md-4'],
            'contact_phone' => ['Telefone do contato', 'text', '', 'col-md-4'],
            'company_doc'   => ['CNPJ da empresa', 'text', '', 'col-md-4'],
        ]],
    ];

    /** Todos os campos: chave => [rótulo, tipo, opções/dica, coluna]. */
    public static function fields(): array
    {
        return array_merge(...array_values(array_map(fn ($s) => $s[2], self::SECTIONS)));
    }

    public static function decode(?string $json): array
    {
        $d = json_decode((string) $json, true);
        return is_array($d) ? $d : [];
    }

    /** Valores do formulário, limpos conforme o tipo de cada campo (o resto é descartado). */
    public static function clean(array $in): array
    {
        $out = [];
        foreach (self::fields() as $key => [$label, $type, $opts]) {
            $v = trim((string) ($in[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            $v = match ($type) {
                'select'   => isset($opts[$v]) ? $v : null,
                'yesno'    => isset(self::YES_NO[$v]) ? $v : null,
                'yesnona'  => isset(self::YES_NO_NA[$v]) ? $v : null,
                'date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null,
                'number'   => (string) max(0, (int) $v),
                'percent'  => (string) min(100, max(0, (int) $v)),
                'money'    => (string) max(0, Menu::parseMoney($v)),
                'textarea' => mb_substr($v, 0, 4000),
                default    => mb_substr($v, 0, 255),
            };
            if ($v !== null) {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    /** Texto do valor para leitura ("Sim", "Recorrente - 24 meses", "21/10/2026"…). */
    public static function label(string $key, $v): string
    {
        $f = self::fields()[$key] ?? null;
        if (!$f || $v === null || $v === '') {
            return '';
        }
        return match ($f[1]) {
            'select'  => $f[2][$v] ?? '',
            'yesno'   => self::YES_NO[$v] ?? '',
            'yesnona' => self::YES_NO_NA[$v] ?? '',
            'date'    => date('d/m/Y', strtotime((string) $v)),
            'percent' => $v . '%',
            'money'   => Menu::money((float) $v),
            default   => (string) $v,
        };
    }

    /** Validação de Conformidade: [sim, não, não se aplica, sem resposta]. */
    public static function checklist(array $d): array
    {
        $n = ['sim' => 0, 'nao' => 0, 'na' => 0, 'vazio' => 0];
        foreach (array_keys(self::SECTIONS['conformidade'][2]) as $k) {
            $n[$d[$k] ?? 'vazio']++;
        }
        return $n;
    }

    /** Blocos do CRM no formulário do Ciclo (name="crm[chave]"). */
    public static function form(array $d, bool $edit): string
    {
        $e = [Menu::class, 'e'];
        $dis = $edit ? '' : 'disabled';
        $h = '';
        foreach (self::SECTIONS as $code => [$title, $icon, $fields]) {
            $extra = '';
            if ($code === 'conformidade') {
                $c = self::checklist($d);
                $total = count($fields);
                $extra = " <span class='cf-crm-chk'><b class='ok'>{$c['sim']} sim</b>" . ($c['nao'] ? " · <b class='bad'>{$c['nao']} não</b>" : '') . ($c['na'] ? " · {$c['na']} não se aplica" : '')
                    . ($c['vazio'] ? " · {$c['vazio']} sem resposta" : '') . " <small>de $total</small></span>";
            }
            $h .= "<div class='col-12 cf-section'><i class='ti $icon me-1'></i>" . $e($title) . "$extra</div>";
            foreach ($fields as $key => [$label, $type, $opts, $col]) {
                $name = "crm[$key]";
                $v = (string) ($d[$key] ?? '');
                $h .= "<div class='$col'><label class='form-label cf-crm-label'>" . $e($label) . '</label>';
                if (in_array($type, ['select', 'yesno', 'yesnona'], true)) {
                    $list = $type === 'select' ? $opts : ($type === 'yesno' ? self::YES_NO : self::YES_NO_NA);
                    $cls = $type !== 'select' && $v !== '' ? ' cf-yn-' . $v : '';
                    $h .= "<select class='form-select$cls' name='$name' $dis><option value=''>—</option>";
                    foreach ($list as $k => $l) {
                        $h .= "<option value='" . $e($k) . "'" . ($v === (string) $k ? ' selected' : '') . '>' . $e($l) . '</option>';
                    }
                    $h .= '</select>';
                } elseif ($type === 'textarea') {
                    $h .= "<textarea class='form-control' rows='2' name='$name' $dis>" . $e($v) . '</textarea>';
                } else {
                    $input = match ($type) {
                        'date' => "type='date'",
                        'number', 'percent' => "type='number' min='0'" . ($type === 'percent' ? " max='100'" : ''),
                        default => "type='text' maxlength='255'",
                    };
                    $val = $type === 'money' && $v !== '' ? number_format((float) $v, 2, ',', '.') : $v;
                    $h .= "<input class='form-control' $input name='$name' value='" . $e($val) . "' placeholder='" . $e(is_string($opts) ? $opts : '') . "' $dis>";
                }
                $h .= '</div>';
            }
        }
        return $h;
    }
}
