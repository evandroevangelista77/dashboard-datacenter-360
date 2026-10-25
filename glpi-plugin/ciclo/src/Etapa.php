<?php

namespace GlpiPlugin\Ciclo;

use CommonDBTM;
use Config;
use Group;
use Html;
use Session;

/**
 * As fases do Ciclo (configuráveis em Configurar → Plugins → Ciclo Financeiro):
 * nome, grupo responsável, SLO em dias corridos e se está ativa. A ordem e para
 * quais tipos de venda cada fase vale são fixas (vêm do processo).
 */
class Etapa extends CommonDBTM
{
    public static string $rightname = 'config';

    /**
     * chave => [nome, área sugerida, vale para, SLO sugerido (dias), o que é feito, o que precisa para concluir]
     * "vale para": all | fisico (tem hardware) | servico (tem serviço) | nao_so_hw (tem software ou serviço)
     */
    public const DEFAULTS = [
        'aviso'        => ['Aviso de fechamento', 'Comercial', 'all', 1, 'Venda fechada no CRM: oportunidade, cliente, tipo de venda e valor.', ''],
        'validacao'    => ['Validação da documentação', 'OTC', 'all', 2, 'OTC confere se a documentação está completa e consistente. Incompleta: devolve ao Comercial.', ''],
        'centro_custo' => ['Centro de custo', 'Gestão de custos', 'all', 2, 'Gestão de custos cria o centro de custo (número e descrição).', 'cc_number'],
        'divulgacao'   => ['Divulgação do aviso', 'OTC', 'all', 2, 'OTC preenche as informações do aviso de fechamento e divulga.', ''],
        'transfer'     => ['Transfermeeting e espelho do pedido', 'GP', 'all', 5, 'GP agenda e realiza a transfermeeting e emite o espelho do pedido no Power Automate.', 'pedido_pa'],
        'requisicao'   => ['Requisição de compra (RM)', 'OTC', 'all', 3, 'OTC cria a RM de compra no TOTVS RM e registra o número aqui. A aprovação segue no Power Automate.', 'rm'],
        'compra'       => ['Pedido ao fornecedor', 'Compras', 'all', 15, 'Compras formaliza o pedido e informa a previsão de entrega. Hardware vai para a Logística; software fica com Compras.', 'pedido'],
        'recebimento'  => ['Recebimento do material', 'Logística', 'fisico', 2, 'Logística confere o material, anexa a NF e informa se a entrega está OK. NOK: devolve a Compras.', 'nf_entrada_ok'],
        'fiscal'       => ['Entrada fiscal e estoque', 'Compras', 'fisico', 3, 'Compras envia a NF para a entrada fiscal e acompanha o lançamento no RM e o saldo do estoque.', ''],
        'expedicao'    => ['Separação e transporte', 'Logística', 'fisico', 3, 'GP pede a saída do material (romaneio), Logística separa (cubagem) e Compras cota o transporte ou define o motorista.', 'transport'],
        'faturamento_hw' => ['Faturamento do hardware', 'Faturamento', 'fisico', 2, 'Faturamento emite a NF do hardware conforme o contrato (antes da implantação).', 'nf_saida_hw'],
        'entrega'      => ['Entrega ao cliente', 'Compras', 'all', 5, 'Coleta e entrega ao cliente (ou entrega da licença), acompanhadas por Compras.', 'date_delivery'],
        'implantacao'  => ['Implantação', 'GP', 'servico', 30, 'GP conduz a implantação pelo cronograma do projeto (PMO).', ''],
        'aceite'       => ['Termo de aceite', 'GP', 'all', 5, 'GP emite o termo de aceite e registra o aceite do cliente.', 'date_accept'],
        'faturamento'  => ['Faturamento de serviço e licença', 'Faturamento', 'nao_so_hw', 3, 'Faturamento emite as NFs de serviço e licença conforme as entregas e aceites.', 'nf_saida_srv'],
        'recebido'     => ['Recebimento dos valores', 'Faturamento', 'all', 30, 'Faturamento acompanha os pagamentos. O Ciclo termina quando todas as parcelas foram recebidas.', 'parcelas_pagas'],
    ];

    public const APPLIES = [
        'all'       => 'Todas as vendas',
        'fisico'    => 'Vendas com hardware',
        'servico'   => 'Vendas com serviço',
        'nao_so_hw' => 'Vendas com software ou serviço',
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Fases do Ciclo' : 'Fase do Ciclo';
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** Fases configuradas, na ordem do processo (chave => linha). */
    public static function all(): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'position']) as $r) {
            $rows[$r['code']] = $r;
        }
        return $rows;
    }

    public static function appliesTo(string $applies, string $saleType): bool
    {
        $f = Ciclo::SALE_TYPES[$saleType][1] ?? [];
        return match ($applies) {
            'fisico'    => in_array('fisico', $f, true),
            'servico'   => in_array('servico', $f, true),
            'nao_so_hw' => $saleType !== 'hw',
            default     => true,
        };
    }

    /** Tabela de configuração das fases (Configurar → Plugins → Ciclo Financeiro). */
    public static function showConfig(): void
    {
        $e = [Menu::class, 'e'];
        $cfg = Config::getConfigurationValues('plugin:ciclo', ['tickets']);
        echo "<div class='cf'>" . Menu::title('Fases e SLOs', 'config');
        echo "<form method='post' action='" . $e(Menu::url('/front/config.php')) . "' class='card'>";
        echo "<div class='card-body'><label class='form-check form-switch mb-1'><input class='form-check-input' type='checkbox' name='tickets' value='1'" . (($cfg['tickets'] ?? '1') ? ' checked' : '') . ">"
            . "<span class='form-check-label'>Abrir um chamado para o grupo da fase quando ela começar (e resolver quando a fase terminar)</span></label>"
            . "<div class='form-hint'>O chamado leva o link do Ciclo; a área trabalha na fila dela como em qualquer requisição, e recebe os e-mails de chamado do GLPI.</div></div>";
        echo "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>#</th><th style='min-width:240px'>Fase</th><th>Vale para</th><th style='min-width:240px'>Grupo responsável</th><th>SLO (dias)</th><th>Ativa</th><th>Para concluir</th></tr></thead><tbody>";
        $req = ['cc_number' => 'nº do centro de custo', 'pedido_pa' => 'pedido do Power Automate', 'rm' => 'RM de compra', 'pedido' => 'pedido ao fornecedor', 'nf_entrada_ok' => 'NF de entrada OK',
            'transport' => 'transporte definido', 'nf_saida_hw' => 'NF de saída do hardware', 'nf_saida_srv' => 'NF de saída de serviço/licença', 'date_delivery' => 'data da entrega', 'date_accept' => 'data do aceite', 'parcelas_pagas' => 'todas as parcelas recebidas'];
        $i = 0;
        foreach (Etapa::all() as $code => $r) {
            $i++;
            $first = $code === 'aviso';
            $id = (int) $r['id'];
            echo "<tr" . ($r['is_active'] ? '' : " class='text-secondary'") . "><td>$i</td>"
                . "<td><input class='form-control form-control-sm' name='etapa[$id][name]' value='" . $e($r['name']) . "'" . ($first ? ' disabled' : '') . "><div class='small text-secondary mt-1'>" . $e($r['description']) . "</div></td>"
                . "<td class='small'>" . $e(Etapa::APPLIES[$r['applies']] ?? '') . "</td>"
                . "<td>" . ($first ? "<span class='small text-secondary'>Comercial (abre o Ciclo)</span>" : Group::dropdown(['name' => "etapa[$id][groups_id]", 'value' => (int) $r['groups_id'], 'display' => false, 'width' => '100%', 'condition' => ['is_assign' => 1]])
                    . "<div class='form-hint'>Sugerido: " . $e($r['area']) . "</div>") . "</td>"
                . "<td style='width:110px'><input type='number' min='0' max='365' class='form-control form-control-sm' name='etapa[$id][slo_days]' value='" . (int) $r['slo_days'] . "'" . ($first ? ' disabled' : '') . "></td>"
                . "<td><label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='etapa[$id][is_active]' value='1'" . ($r['is_active'] ? ' checked' : '') . ($first ? ' disabled' : '') . "></label></td>"
                . "<td class='small text-secondary'>" . $e($req[$r['requirement']] ?? '—') . "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "<div class='card-footer d-flex align-items-center gap-3'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>"
            . "<span class='small text-secondary'>Os SLOs vêm com uma sugestão inicial: ajuste depois de medir os primeiros Ciclos (o Painel mostra o tempo real de cada fase). SLO 0 = sem prazo.</span></div>";
        Html::closeForm();
        echo "</div>";
    }
}
