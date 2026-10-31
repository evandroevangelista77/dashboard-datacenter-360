<?php

namespace GlpiPlugin\Gestaoeventos;

use Config;
use Group;
use Html;
use Session;

/**
 * As etapas do fluxo (configuráveis em Etapas e SLOs): nome, grupo responsável, SLO em
 * dias corridos, alerta antes do prazo, de onde conta o relógio e se está ativa. A ordem
 * e a regra para concluir cada etapa vêm do processo e são fixas.
 */
class Etapa
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_etapas';

    public static string $rightname = 'config';

    /**
     * chave => [nome, área sugerida, vale para, SLO sugerido (dias), o que é feito, o que precisa para concluir, relógio]
     * "vale para": all | completo (todos, menos agenda comercial). Relógio: activation | event_start
     */
    public const DEFAULTS = [
        'briefing'     => ['Solicitação e briefing', 'Marketing', 'all', 3, 'O solicitante preenche o briefing e envia; Marketing faz a triagem (aceita ou devolve) e confere a antecedência mínima.', 'briefing_ok', 'activation'],
        'avaliacao'    => ['Avaliação e aprovação', 'Diretoria', 'all', 5, 'Viabilidade, centro de custo e proposta de orçamento seguem para a aprovação formal da liderança e das alçadas.', 'aprovado', 'activation'],
        'planejamento' => ['Abertura e planejamento', 'PMO', 'all', 7, 'GP designado, projeto no GLPI, kickoff, cronograma com marcos, RACI, riscos e o orçamento base aprovado.', 'planejamento_ok', 'activation'],
        'compras'      => ['Cotações e contratações', 'Compras', 'all', 15, 'Compras cota, negocia e contrata. Nada é comprometido sem aprovação, contrato/confirmação e verba disponível.', 'compras_ok', 'activation'],
        'producao'     => ['Produção e logística', 'Marketing / Logística', 'completo', 20, 'Peças, brindes, materiais, viagens e reservas em produção; entregas e dependências sob controle.', 'producao_ok', 'activation'],
        'prontidao'    => ['Preparação final', 'PMO + Marketing', 'completo', 3, 'Checklist final, contatos e contingência. Aceite de prontidão do GP e do Marketing.', 'prontidao_ok', 'activation'],
        'execucao'     => ['Execução', 'Marketing', 'all', 3, 'Programação executada, ocorrências registradas; custos emergenciais seguem para exceção.', 'execucao_ok', 'event_start'],
        'encerramento' => ['Pós-evento e encerramento', 'PMO / Financeiro', 'all', 30, 'Conciliação, fiscal, resultados, leads, lições e arquivos; Definition of Done e aceite formal do dono do evento.', 'dod', 'activation'],
    ];

    public const APPLIES = [
        'all'      => 'Todos os eventos',
        'completo' => 'Todos, menos agenda comercial',
    ];

    public const CLOCKS = [
        'activation'  => 'Ao iniciar a etapa',
        'event_start' => 'A partir do início do evento',
    ];

    public const REQUIREMENTS = [
        'briefing_ok'     => 'briefing completo, triagem aceita e antecedência',
        'aprovado'        => 'centro de custo e aprovação formal',
        'planejamento_ok' => 'GP, projeto, kickoff, marcos e orçamento base aprovado',
        'compras_ok'      => 'compras sem pendência de aprovação ou contrato',
        'producao_ok'     => 'checklists críticos e entregas sem pendência',
        'prontidao_ok'    => 'aceite do GP e do Marketing e contingência',
        'execucao_ok'     => 'evento realizado e sem ocorrência crítica aberta',
        'dod'             => 'Definition of Done e aceite do dono do evento',
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Etapas do fluxo' : 'Etapa do fluxo';
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** Etapas configuradas, na ordem do processo (chave => linha). */
    public static function all(): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'position']) as $r) {
            $rows[$r['code']] = $r;
        }
        return $rows;
    }

    public static function appliesTo(string $applies, string $eventType): bool
    {
        return $applies !== 'completo' || $eventType !== 'comercial';
    }

    /** Meta do ciclo: a soma dos SLOs das etapas ativas. */
    public static function meta(): int
    {
        return (int) array_sum(array_map(fn ($et) => $et['is_active'] ? (int) $et['slo_days'] : 0, self::all()));
    }

    /** Tabela de configuração das etapas e dos prazos gerais (Etapas e SLOs). */
    public static function showConfig(): void
    {
        $e = [Menu::class, 'e'];
        $cfg = Config::getConfigurationValues('plugin:gestaoeventos', ['approval_sla_days']);
        echo "<div class='ge'>" . Menu::title('Etapas e SLOs', 'config');
        echo "<form method='post' action='" . $e(Menu::url('/front/config.php')) . "' class='card'>";
        echo "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>#</th><th style='min-width:230px'>Etapa</th><th>Vale para</th><th style='min-width:230px'>Grupo responsável</th>"
            . "<th>SLO (dias)</th><th title='Avisa a área quando a etapa consome este percentual do SLO'>Alerta (%)</th><th>Relógio</th><th>Ativa</th><th>Para concluir</th></tr></thead><tbody>";
        $i = 0;
        foreach (self::all() as $r) {
            $i++;
            $id = (int) $r['id'];
            echo "<tr" . ($r['is_active'] ? '' : " class='text-secondary'") . "><td>$i</td>"
                . "<td><input class='form-control form-control-sm' name='etapa[$id][name]' value='" . $e($r['name']) . "'><div class='small text-secondary mt-1'>" . $e($r['description']) . "</div></td>"
                . "<td><select class='form-select form-select-sm' name='etapa[$id][applies]'>" . Menu::options(self::APPLIES, $r['applies']) . "</select></td>"
                . "<td>" . Group::dropdown(['name' => "etapa[$id][groups_id]", 'value' => (int) $r['groups_id'], 'display' => false, 'width' => '100%', 'condition' => ['is_assign' => 1]])
                    . "<div class='form-hint'>Sugerido: " . $e($r['area']) . "</div></td>"
                . "<td style='width:100px'><input type='number' min='0' max='365' class='form-control form-control-sm' name='etapa[$id][slo_days]' value='" . (int) $r['slo_days'] . "'></td>"
                . "<td style='width:100px'><input type='number' min='0' max='100' class='form-control form-control-sm' name='etapa[$id][warn_pct]' value='" . (int) $r['warn_pct'] . "'></td>"
                . "<td><select class='form-select form-select-sm' name='etapa[$id][clock_from]'>" . Menu::options(self::CLOCKS, $r['clock_from']) . "</select></td>"
                . "<td><label class='form-check form-switch m-0'><input class='form-check-input' type='checkbox' name='etapa[$id][is_active]' value='1'" . ($r['is_active'] ? ' checked' : '') . "></label></td>"
                . "<td class='small text-secondary'>" . $e(self::REQUIREMENTS[$r['requirement']] ?? '—') . "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "<div class='card-body border-top'><div class='row g-3 align-items-end'>"
            . "<div class='col-md-3'><label class='form-label'>SLO de cada aprovação (dias)</label><input type='number' min='0' max='60' class='form-control' name='approval_sla_days' value='" . (int) ($cfg['approval_sla_days'] ?? 3) . "'>"
            . "<div class='form-hint'>Aprovação pendente além deste prazo aparece como vencida e avisa o aprovador. 0 = sem prazo.</div></div>"
            . "<div class='col-md-9 small text-secondary'><b>Meta do ciclo:</b> " . self::meta() . " dias (a soma dos SLOs das etapas ativas) — é a linha de referência do Painel. "
            . "<b>Alerta</b>: quando a etapa consome esse percentual do SLO, a área e o GP recebem um aviso preventivo; ao passar do SLO, a etapa fica atrasada. "
            . "<b>Relógio</b>: a Execução conta a partir do início do evento, para não acusar atraso enquanto o evento ainda não aconteceu. SLO 0 = sem prazo.</div></div></div>";
        echo "<div class='card-footer d-flex align-items-center gap-3'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>"
            . "<span class='small text-secondary'>Os SLOs vêm com uma sugestão inicial: ajuste depois de medir os primeiros eventos (o Painel mostra o tempo real de cada etapa). Valem para as etapas que começarem daqui em diante.</span></div>";
        Html::closeForm();
        echo "</div>";
    }
}
