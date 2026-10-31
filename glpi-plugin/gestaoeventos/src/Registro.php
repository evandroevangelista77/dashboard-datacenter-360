<?php

namespace GlpiPlugin\Gestaoeventos;

use Config;
use Html;
use RuntimeException;
use Session;

/**
 * Registros das abas do evento. Um motor só serve marcos, riscos, mudanças, checklists, peças,
 * convidados, compras, contratos, lançamentos financeiros, ocorrências, resultados, lições…:
 * o tipo (kind) diz quais colunas existem, como aparecem e que regras valem ao gravar.
 */
class Registro
{
    use Tabela;

    public const TABLE = 'glpi_plugin_gestaoeventos_registros';

    public static string $rightname = 'plugin_gestaoeventos';

    public const DEFAULT_CATEGORIES = "Receita de patrocínio\nMateriais > Brindes\nMateriais > Folders\nMateriais > Camisetas\nMateriais > Coffee\nMateriais > Comunicação visual\n"
        . "Locação e serviços > Stand\nLocação e serviços > Cota\nLocação e serviços > Taxas\nLocação e serviços > Salão\nLocação e serviços > Jantares\nLocação e serviços > Mão de obra\n"
        . "RDV\nViagens > Passagens\nViagens > Hotel\nViagens > Uber/táxi\nViagens > Seguro\nViagens > Locação de carro\nViagens > Frete\nViagens > Estacionamento";

    public const ST_TASK = ['a_fazer' => 'A fazer', 'andamento' => 'Em andamento', 'feito' => 'Concluído', 'bloqueado' => 'Bloqueado'];
    public const PROB = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'];

    /** status → cor do selo */
    private const GOOD = ['feito', 'concluido', 'aprovada', 'aprovado', 'publicada', 'assinado', 'recebido', 'pago', 'resolvida', 'atingido', 'conciliado', 'realizado', 'entregue', 'presente', 'confirmado', 'emitido', 'escolhida', 'encerrado', 'reservado', 'medido', 'baixa', 'na'];
    private const BAD = ['bloqueado', 'rejeitada', 'rejeitado', 'divergente', 'nao_atingido', 'cancelado', 'ocorreu', 'critica', 'vencido', 'recusado', 'devolvido', 'ausente', 'descartada', 'alta'];
    private const WARN = ['em_aprovacao', 'aprovacao', 'pendente', 'aberta', 'solicitado', 'juridico', 'andamento', 'rascunho', 'parcial', 'mitigando', 'media', 'followup', 'negociacao', 'cotacao', 'comprometido', 'a_reservar', 'a_enviar', 'enviado'];

    /**
     * tipo => [título, ícone, aba, etapa, [campo => [rótulo, tipo, opções, na lista?]], opções]
     * tipos: text, textarea, money, number, date, select, user, check, cat · opções: totals (campos somados), manual_status
     */
    public const KINDS = [
        // ---- planejamento
        'kickoff' => ['Kickoff e ritos', 'ti-users-group', 'planejamento', 'planejamento', [
            'title' => ['Assunto', 'text', '', 1], 'date_done' => ['Realizado em', 'date', '', 1], 'doc_ref' => ['Evidência (link)', 'text', '', 1],
            'detail' => ['Participantes, decisões e ata', 'textarea', '', 0]], []],
        'marco' => ['Marcos e cronograma', 'ti-flag', 'planejamento', 'planejamento', [
            'title' => ['Marco', 'text', '', 1], 'users_id_owner' => ['Responsável', 'user', '', 1], 'date_due' => ['Prazo', 'date', '', 1], 'date_done' => ['Concluído em', 'date', '', 1],
            'status' => ['Situação', 'select', self::ST_TASK, 1], 'is_critical' => ['Crítico', 'check', '', 1], 'code' => ['Depende de', 'text', '', 0]], []],
        'checkpoint' => ['Checkpoints e decisões', 'ti-route', 'planejamento', 'planejamento', [
            'title' => ['Checkpoint', 'text', '', 1], 'date_due' => ['Data', 'date', '', 1], 'users_id_owner' => ['Responsável', 'user', '', 1], 'detail' => ['Decisão / escalonamento', 'textarea', '', 0]], []],
        'risco' => ['Riscos', 'ti-alert-triangle', 'planejamento', 'planejamento', [
            'title' => ['Risco', 'text', '', 1], 'category' => ['Probabilidade', 'select', self::PROB, 1], 'subcategory' => ['Impacto', 'select', self::PROB, 1],
            'users_id_owner' => ['Dono', 'user', '', 1], 'date_due' => ['Prazo', 'date', '', 1],
            'status' => ['Situação', 'select', ['aberto' => 'Aberto', 'mitigando' => 'Mitigando', 'ocorreu' => 'Ocorreu', 'encerrado' => 'Encerrado'], 1], 'detail' => ['Tratamento', 'textarea', '', 0]], []],
        'mudanca' => ['Solicitações de mudança', 'ti-arrows-exchange', 'planejamento', 'planejamento', [
            'title' => ['Mudança', 'text', '', 1], 'category' => ['Afeta', 'select', ['custo' => 'Custo', 'prazo' => 'Prazo', 'escopo' => 'Escopo', 'qualidade' => 'Qualidade', 'dependencia' => 'Dependência'], 1],
            'amount_eac' => ['Impacto no EAC (R$)', 'money', '', 1], 'status' => ['Situação', 'select', ['rascunho' => 'Rascunho', 'em_aprovacao' => 'Em aprovação', 'aprovada' => 'Aprovada', 'rejeitada' => 'Rejeitada'], 1],
            'detail' => ['Justificativa e impacto', 'textarea', '', 0]], ['manual_status' => false, 'totals' => ['amount_eac']]],
        'pendencia' => ['Dependências e pendências', 'ti-link', 'planejamento', 'planejamento', [
            'title' => ['Pendência', 'text', '', 1], 'supplier' => ['Dependente de', 'text', '', 1], 'users_id_owner' => ['Responsável', 'user', '', 1], 'date_due' => ['Prazo', 'date', '', 1],
            'is_critical' => ['Crítica', 'check', '', 1], 'status' => ['Situação', 'select', ['aberta' => 'Aberta', 'resolvida' => 'Resolvida'], 1]], []],
        // ---- checklists
        'checklist' => ['Checklist', 'ti-list-check', 'checklists', '', [
            'title' => ['Item', 'text', '', 1], 'phase' => ['Etapa', 'select', 'phases', 1], 'users_id_owner' => ['Responsável', 'user', '', 1], 'date_due' => ['Prazo', 'date', '', 1],
            'is_critical' => ['Crítico (bloqueia)', 'check', '', 1], 'status' => ['Situação', 'select', ['a_fazer' => 'A fazer', 'feito' => 'Feito', 'na' => 'Não se aplica'], 1], 'doc_ref' => ['Evidência', 'text', '', 0]], []],
        // ---- marketing
        'peca' => ['Plano de comunicação e peças', 'ti-speakerphone', 'marketing', 'producao', [
            'title' => ['Peça / campanha', 'text', '', 1], 'category' => ['Tipo', 'select', ['campanha' => 'Campanha', 'identidade' => 'Identidade visual', 'peca' => 'Peça', 'convite' => 'Convite', 'site' => 'Site / landing', 'outro' => 'Outro'], 1],
            'code' => ['Versão', 'text', '', 1], 'users_id_owner' => ['Responsável', 'user', '', 1], 'date_due' => ['Prazo', 'date', '', 1],
            'status' => ['Situação', 'select', ['rascunho' => 'Rascunho', 'em_aprovacao' => 'Em aprovação', 'aprovada' => 'Aprovada', 'publicada' => 'Publicada'], 1], 'doc_ref' => ['Arquivo (link)', 'text', '', 0]], ['marketing_status' => 'aprovada']],
        'convidado' => ['Convidados e participantes', 'ti-users', 'marketing', 'execucao', [
            'title' => ['Nome', 'text', '', 1], 'supplier' => ['Empresa', 'text', '', 1], 'category' => ['Perfil', 'select', ['cliente' => 'Cliente', 'prospect' => 'Prospect', 'parceiro' => 'Parceiro', 'interno' => 'Interno', 'palestrante' => 'Palestrante'], 1],
            'status' => ['Situação', 'select', ['convidado' => 'Convidado', 'confirmado' => 'Confirmado', 'recusado' => 'Recusado', 'presente' => 'Presente', 'ausente' => 'Ausente'], 1]], []],
        'brinde' => ['Brindes e materiais', 'ti-gift', 'marketing', 'producao', [
            'doc_ref' => ['Foto', 'image', '', 1], 'title' => ['Item', 'text', '', 1], 'qty' => ['Qtd.', 'number', '', 1], 'unit' => ['Unid.', 'text', '', 0], 'amount_budget' => ['Valor estimado (R$)', 'money', '', 1], 'supplier' => ['Fornecedor', 'text', '', 1],
            'date_due' => ['Prazo', 'date', '', 1], 'status' => ['Situação', 'select', ['a_definir' => 'A definir', 'arte' => 'Arte', 'producao' => 'Em produção', 'recebido' => 'Recebido', 'entregue' => 'Entregue'], 1]], ['totals' => ['amount_budget'], 'marketing_status' => 'arte']],
        'patrocinador' => ['Patrocinadores e fabricantes', 'ti-building-store', 'marketing', 'planejamento', [
            'title' => ['Patrocinador / fabricante', 'text', '', 1], 'category' => ['Tipo', 'select', ['patrocinio' => 'Patrocínio', 'contrapartida' => 'Contrapartida', 'cota' => 'Cota'], 1],
            'amount_budget' => ['Previsto (R$)', 'money', '', 1], 'amount_committed' => ['Comprometido (R$)', 'money', '', 1], 'amount_real' => ['Recebido (R$)', 'money', '', 1],
            'doc_ref' => ['ND / comprovante', 'text', '', 0], 'status' => ['Situação', 'select', ['previsto' => 'Previsto', 'comprometido' => 'Comprometido', 'recebido' => 'Recebido', 'cancelado' => 'Cancelado'], 1]], ['totals' => ['amount_budget', 'amount_committed', 'amount_real']]],
        'programacao' => ['Programação e palestrantes', 'ti-calendar-time', 'marketing', 'producao', [
            'title' => ['Atividade', 'text', '', 1], 'supplier' => ['Palestrante / responsável', 'text', '', 1], 'date_due' => ['Data', 'date', '', 1], 'code' => ['Horário', 'text', '', 1], 'doc_ref' => ['Evidência', 'text', '', 0]], []],
        'kpi' => ['Objetivos e KPIs', 'ti-target-arrow', 'marketing', 'planejamento', [
            'title' => ['Indicador', 'text', '', 1], 'unit' => ['Unidade', 'text', '', 1], 'target' => ['Meta', 'number', '', 1], 'result' => ['Resultado', 'number', '', 1],
            'status' => ['Situação', 'select', ['definido' => 'Definido', 'medido' => 'Medido'], 1], 'detail' => ['Método de medição', 'textarea', '', 0]], []],
        // ---- compras
        'compra' => ['Requisições e contratações', 'ti-shopping-cart', 'compras', 'compras', [
            'title' => ['Descrição', 'text', '', 1], 'qty' => ['Qtd.', 'number', '', 0], 'unit' => ['Unid.', 'text', '', 0], 'category' => ['Categoria', 'cat', '', 0], 'supplier' => ['Fornecedor', 'text', '', 1],
            'amount_budget' => ['Orçado (R$)', 'money', '', 1], 'amount_committed' => ['Contratado (R$)', 'money', '', 1], 'amount_real' => ['Pago (R$)', 'money', '', 1], 'date_due' => ['Data limite', 'date', '', 1],
            'parent_id' => ['Linha do orçamento', 'parent', 'lancamento', 0],
            'status' => ['Etapa', 'select', ['requisicao' => 'Requisição', 'cotacao' => 'Cotação', 'negociacao' => 'Negociação', 'aprovacao' => 'Em aprovação', 'contrato' => 'Contrato', 'pedido' => 'Pedido emitido', 'producao' => 'Em produção', 'recebido' => 'Recebido', 'pago' => 'Pago', 'cancelado' => 'Cancelado'], 1]],
            ['totals' => ['amount_budget', 'amount_committed', 'amount_real']]],
        'proposta' => ['Propostas de fornecedores', 'ti-scale', 'compras', 'compras', [
            'parent_id' => ['Requisição', 'parent', 'compra', 1], 'supplier' => ['Fornecedor', 'text', '', 1], 'amount_budget' => ['Valor proposto (R$)', 'money', '', 1],
            'status' => ['Situação', 'select', ['recebida' => 'Recebida', 'escolhida' => 'Escolhida', 'descartada' => 'Descartada'], 1], 'detail' => ['Justificativa da escolha', 'textarea', '', 0]], []],
        'contrato' => ['Contratos e termos', 'ti-file-signature', 'compras', 'compras', [
            'title' => ['Contrato / termo', 'text', '', 1], 'parent_id' => ['Requisição', 'parent', 'compra', 1], 'supplier' => ['Fornecedor', 'text', '', 1], 'doc_ref' => ['Assinatura / URL', 'text', '', 1],
            'date_due' => ['Vencimento', 'date', '', 1], 'status' => ['Situação', 'select', ['minuta' => 'Minuta', 'juridico' => 'Validação Jurídico', 'assinado' => 'Assinado', 'vencido' => 'Vencido'], 1]], []],
        'aditivo' => ['Aditivos de custo ou escopo', 'ti-file-plus', 'compras', 'compras', [
            'title' => ['Aditivo', 'text', '', 1], 'parent_id' => ['Requisição', 'parent', 'compra', 1], 'amount_eac' => ['Valor do aditivo (R$)', 'money', '', 1],
            'status' => ['Situação', 'select', ['solicitado' => 'Solicitado', 'aprovado' => 'Aprovado', 'rejeitado' => 'Rejeitado'], 1], 'detail' => ['Justificativa', 'textarea', '', 0]], ['manual_status' => false, 'totals' => ['amount_eac']]],
        'recebimento' => ['Recebimentos e pendências', 'ti-package-import', 'compras', 'producao', [
            'title' => ['Item', 'text', '', 1], 'parent_id' => ['Requisição', 'parent', 'compra', 1], 'qty' => ['Qtd.', 'number', '', 1], 'date_done' => ['Recebido em', 'date', '', 1],
            'status' => ['Situação', 'select', ['pendente' => 'Pendente', 'recebido' => 'Recebido', 'devolvido' => 'Devolvido', 'divergente' => 'Divergente'], 1], 'detail' => ['Divergência / observação', 'textarea', '', 0]], []],
        'nf' => ['Documentos fiscais', 'ti-receipt', 'compras', 'encerramento', [
            'title' => ['Nº do documento', 'text', '', 1], 'category' => ['Tipo', 'select', ['nf' => 'NF', 'nd' => 'ND', 'comprovante' => 'Comprovante', 'recibo' => 'Recibo'], 1], 'supplier' => ['Fornecedor', 'text', '', 1],
            'parent_id' => ['Requisição', 'parent', 'compra', 0], 'amount_real' => ['Valor (R$)', 'money', '', 1], 'date_done' => ['Emissão / pagamento', 'date', '', 1], 'doc_ref' => ['Chave / link', 'text', '', 0]], ['totals' => ['amount_real']]],
        // ---- financeiro
        'lancamento' => ['Lançamentos (Budget, EAC e Realizado)', 'ti-cash', 'financeiro', 'planejamento', [
            'nature' => ['Natureza', 'select', ['despesa' => 'Despesa', 'receita' => 'Receita'], 1], 'category' => ['Categoria', 'cat', '', 1], 'title' => ['Descrição', 'text', '', 1], 'supplier' => ['Fornecedor / fonte', 'text', '', 1],
            'amount_budget' => ['Budget (R$)', 'money', '', 1], 'amount_eac' => ['EAC (R$)', 'money', '', 1], 'amount_committed' => ['Comprometido (R$)', 'money', '', 1], 'amount_real' => ['Realizado (R$)', 'money', '', 1],
            'date_due' => ['Vencimento', 'date', '', 0], 'date_done' => ['Pago / recebido em', 'date', '', 0], 'doc_ref' => ['NF / documento', 'text', '', 1], 'cc' => ['Centro de custo', 'text', '', 0],
            'users_id_owner' => ['Solicitante', 'user', '', 0], 'users_id_approver' => ['Aprovador', 'user', '', 0],
            'status' => ['Situação', 'select', ['previsto' => 'Previsto', 'comprometido' => 'Comprometido', 'realizado' => 'Realizado', 'conciliado' => 'Conciliado', 'cancelado' => 'Cancelado'], 1]], ['finance' => true]],
        // ---- logística
        'material' => ['Materiais: envio, entrega e devolução', 'ti-truck-delivery', 'logistica', 'producao', [
            'title' => ['Material', 'text', '', 1], 'qty' => ['Qtd.', 'number', '', 1], 'category' => ['Local / destino', 'text', '', 1], 'users_id_owner' => ['Responsável', 'user', '', 1], 'date_due' => ['Prazo', 'date', '', 1], 'date_done' => ['Movimentado em', 'date', '', 1],
            'status' => ['Situação', 'select', ['a_enviar' => 'A enviar', 'enviado' => 'Enviado', 'entregue' => 'Entregue', 'recebido' => 'Recebido', 'devolvido' => 'Devolvido', 'divergente' => 'Divergente'], 1], 'detail' => ['Divergência / observação', 'textarea', '', 0]], []],
        'reserva' => ['Passagens, hospedagens e transporte', 'ti-plane', 'logistica', 'producao', [
            'title' => ['Participante / item', 'text', '', 1], 'category' => ['Tipo', 'select', ['passagem' => 'Passagem', 'hospedagem' => 'Hospedagem', 'transporte' => 'Transporte', 'voucher' => 'Voucher'], 1], 'supplier' => ['Fornecedor', 'text', '', 1],
            'doc_ref' => ['Localizador / voucher', 'text', '', 1], 'date_due' => ['Data', 'date', '', 1], 'status' => ['Situação', 'select', ['a_reservar' => 'A reservar', 'reservado' => 'Reservado', 'emitido' => 'Emitido', 'cancelado' => 'Cancelado'], 1]], []],
        'contingencia' => ['Contingência e contatos', 'ti-lifebuoy', 'logistica', 'prontidao', [
            'title' => ['Atividade / fornecedor', 'text', '', 1], 'supplier' => ['Contato', 'text', '', 1], 'doc_ref' => ['Telefone / e-mail', 'text', '', 1], 'detail' => ['Ação de contingência', 'textarea', '', 0]], []],
        // ---- execução
        'atividade' => ['Atividades executadas', 'ti-player-play', 'execucao', 'execucao', [
            'title' => ['Atividade', 'text', '', 1], 'users_id_owner' => ['Responsável', 'user', '', 1], 'date_due' => ['Previsto', 'date', '', 1], 'date_done' => ['Efetivo', 'date', '', 1],
            'status' => ['Situação', 'select', self::ST_TASK, 1], 'doc_ref' => ['Comprovante', 'text', '', 0]], []],
        'ocorrencia' => ['Ocorrências', 'ti-alert-octagon', 'execucao', 'execucao', [
            'title' => ['Ocorrência', 'text', '', 1], 'category' => ['Severidade', 'select', ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'critica' => 'Crítica'], 1], 'users_id_owner' => ['Responsável', 'user', '', 1],
            'amount_eac' => ['Custo adicional (R$)', 'money', '', 1], 'status' => ['Situação', 'select', ['aberta' => 'Aberta', 'resolvida' => 'Resolvida'], 1], 'detail' => ['Evidência e solução', 'textarea', '', 0]], ['totals' => ['amount_eac']]],
        // ---- encerramento
        'resultado' => ['Resultado dos objetivos', 'ti-trophy', 'encerramento', 'encerramento', [
            'title' => ['Objetivo / KPI', 'text', '', 1], 'status' => ['Resultado', 'select', ['atingido' => 'Atingido', 'parcial' => 'Parcialmente atingido', 'nao_atingido' => 'Não atingido'], 1],
            'users_id_owner' => ['Responsável', 'user', '', 1], 'doc_ref' => ['Evidência', 'text', '', 1], 'detail' => ['Justificativa', 'textarea', '', 0]], []],
        'lead' => ['Leads originados × influenciados', 'ti-user-plus', 'encerramento', 'encerramento', [
            'title' => ['Campanha / fonte', 'text', '', 1], 'category' => ['Classe', 'select', ['originado' => 'Originado', 'influenciado' => 'Influenciado'], 1], 'qty' => ['Leads', 'number', '', 1],
            'status' => ['Follow-up', 'select', ['pendente' => 'Pendente', 'followup' => 'Em andamento', 'concluido' => 'Concluído'], 1], 'confirmed' => ['Confirmado pelo Comercial', 'check', '', 1]], ['totals' => ['qty']]],
        'licao' => ['Lições aprendidas', 'ti-bulb', 'encerramento', 'encerramento', [
            'title' => ['Lição', 'text', '', 1], 'category' => ['Área', 'select', ['planejamento' => 'Planejamento', 'compras' => 'Compras', 'marketing' => 'Marketing', 'logistica' => 'Logística', 'execucao' => 'Execução', 'financeiro' => 'Financeiro'], 1],
            'detail' => ['O que aprendemos / recomendação', 'textarea', '', 0]], []],
        'satisfacao' => ['Satisfação e feedback', 'ti-mood-smile', 'encerramento', 'encerramento', [
            'title' => ['Público / pesquisa', 'text', '', 1], 'qty' => ['Respostas', 'number', '', 1], 'result' => ['Nota média (0-10)', 'number', '', 1], 'detail' => ['Comentários', 'textarea', '', 0]], []],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Registros do evento' : 'Registro do evento';
    }

    // ------------------------------------------------------------------ consultas

    public static function forEvento(int $eventoId, ?string $kind = null): array
    {
        global $DB;
        $where = ['plugin_gestaoeventos_eventos_id' => $eventoId];
        if ($kind) {
            $where['kind'] = $kind;
        }
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'id']), false);
    }

    public static function row(int $id, ?int $eventoId = null): ?array
    {
        global $DB;
        $where = ['id' => $id] + ($eventoId ? ['plugin_gestaoeventos_eventos_id' => $eventoId] : []);
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1])->current() ?: null;
    }

    public static function countKind(int $eventoId, string $kind, array $where = []): int
    {
        return countElementsInTable(self::getTable(), ['plugin_gestaoeventos_eventos_id' => $eventoId, 'kind' => $kind] + $where);
    }

    /** Categorias financeiras configuradas: "Categoria > Subcategoria" => rótulo. */
    public static function categories(): array
    {
        $raw = (string) (Config::getConfigurationValues('plugin:gestaoeventos', ['categories'])['categories'] ?? self::DEFAULT_CATEGORIES);
        $out = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim(preg_replace('/\s*>\s*/', ' > ', $line));
            if ($line !== '') {
                $out[$line] = $line;
            }
        }
        return $out ?: self::categories();
    }

    private static array $userCache = [];

    /** Usuários ativos para os seletores (cache da requisição). */
    public static function users(): array
    {
        global $DB;
        if (!self::$userCache) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0, 'NOT' => ['name' => '']], 'ORDER' => ['realname', 'firstname', 'name'], 'LIMIT' => 1500]) as $u) {
                self::$userCache[(int) $u['id']] = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: $u['name'];
            }
        }
        return self::$userCache;
    }

    /** Selo colorido de situação. */
    public static function chip(string $status, string $label): string
    {
        $c = in_array($status, self::GOOD, true) ? '#22c55e' : (in_array($status, self::BAD, true) ? '#ef4444' : (in_array($status, self::WARN, true) ? '#f59e0b' : '#3b82f6'));
        return "<span class='badge ge-badge' style='--c:$c'>" . Menu::e($label) . "</span>";
    }

    // ------------------------------------------------------------------ exibição

    /** Opções de um campo select/parent para este evento. */
    private static function selectOptions(Evento $ev, array $def, string $field = ''): array
    {
        $opts = $def[2];
        if ($opts === 'phases') {
            return ['' => '— geral —'] + array_map(fn ($x) => $x['name'], Etapa::all());
        }
        if ($def[1] === 'parent') {
            $out = ['0' => '—'];
            foreach (self::forEvento($ev->getID(), (string) $opts) as $p) {
                $out[(string) $p['id']] = '#' . $p['id'] . ' ' . mb_strimwidth((string) $p['title'], 0, 50, '…');
            }
            return $out;
        }
        return is_array($opts) ? $opts : [];
    }

    public static function cell(Evento $ev, string $kind, string $field, array $row): string
    {
        $def = self::KINDS[$kind][4][$field] ?? null;
        $e = [Menu::class, 'e'];
        $v = $row[$field] ?? null;
        if (!$def) {
            return $e($v);
        }
        switch ($def[1]) {
            case 'money':
                return (float) $v ? Menu::money((float) $v) : '—';
            case 'number':
                return (float) $v ? Menu::num((float) $v, (float) $v == (int) $v ? 0 : 2) : '—';
            case 'date':
                return Menu::date($v);
            case 'check':
                return $v ? "<i class='ti ti-circle-check text-success'></i>" : "<i class='ti ti-circle text-secondary'></i>";
            case 'image':
                return self::thumb($ev, $row);
            case 'user':
                return (int) $v ? $e(self::users()[(int) $v] ?? getUserName((int) $v)) : '—';
            case 'cat':
                return $e(trim($row['category'] . ((string) $row['subcategory'] !== '' ? ' · ' . $row['subcategory'] : ''), ' ·')) ?: '—';
            case 'parent':
                $p = (int) $v ? self::row((int) $v) : null;
                return $p ? '#' . $p['id'] . ' ' . $e(mb_strimwidth((string) $p['title'], 0, 34, '…')) : '—';
            case 'select':
                $opts = self::selectOptions($ev, $def, $field);
                $label = $opts[(string) $v] ?? '';
                if ($field === 'status') {
                    return $v !== null && $v !== '' ? self::chip((string) $v, (string) ($opts[(string) $v] ?? $v)) : '—';
                }
                if ($field === 'category' || $field === 'subcategory') {
                    return $v !== null && $v !== '' ? self::chip((string) $v, (string) $label ?: (string) $v) : '—';
                }
                return $label !== '' ? $e($label) : '—';
            default:
                $s = (string) $v;
                return $s !== '' ? (preg_match('#^https?://#i', $s) ? "<a href='" . $e($s) . "' target='_blank' rel='noopener'>" . $e(mb_strimwidth($s, 0, 36, '…')) . "</a>" : $e($s)) : '—';
        }
    }

    /** Campo de formulário (pequeno) com o nome reg[campo]. */
    private static function input(Evento $ev, array $def, string $field, $value, string $prefix = 'reg'): string
    {
        $e = [Menu::class, 'e'];
        $name = $prefix . '[' . $field . ']';
        [, $type] = $def;
        switch ($type) {
            case 'textarea':
                return "<textarea class='form-control form-control-sm' rows='2' name='$name' maxlength='4000'>" . $e($value) . "</textarea>";
            case 'money':
                return "<input class='form-control form-control-sm' name='$name' inputmode='decimal' value='" . ((float) $value ? $e(number_format((float) $value, 2, ',', '.')) : '') . "'>";
            case 'number':
                return "<input class='form-control form-control-sm' name='$name' inputmode='decimal' value='" . ((float) $value ? $e(rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',')) : '') . "'>";
            case 'date':
                return "<input type='date' class='form-control form-control-sm' name='$name' value='" . $e($value ? substr((string) $value, 0, 10) : '') . "'>";
            case 'image':
                return "<input type='file' class='form-control form-control-sm' name='reg_image' accept='image/png,image/jpeg,image/gif,image/webp'>" . ((int) $value ? "<div class='form-hint'>Já tem foto; enviar outra substitui.</div>" : '');
            case 'check':
                return "<label class='form-check form-switch m-0'><input type='hidden' name='$name' value='0'><input class='form-check-input' type='checkbox' name='$name' value='1'" . ($value ? ' checked' : '') . "></label>";
            case 'user':
                return "<select class='form-select form-select-sm' name='$name'><option value='0'>—</option>" . Menu::options(self::users(), (string) $value) . "</select>";
            case 'cat':
                return "<select class='form-select form-select-sm' name='{$prefix}[category]'><option value=''>—</option>" . Menu::options(self::categories(), (string) $value) . "</select>";
            case 'parent':
            case 'select':
                return "<select class='form-select form-select-sm' name='$name'>" . ($type === 'select' && $field !== 'phase' ? "<option value=''>—</option>" : '') . Menu::options(self::selectOptions($ev, $def, $field), (string) $value) . "</select>";
            default:
                return "<input class='form-control form-control-sm' name='$name' maxlength='255' value='" . $e($value) . "'>";
        }
    }

    /**
     * Cartão de uma lista: tabela com os registros, edição inline, mudança de situação,
     * remoção e o formulário de inclusão. $back volta para a aba depois de gravar.
     */
    public static function block(Evento $ev, string $kind, bool $edit, string $back, string $col = 'col-12'): string
    {
        [$title, $icon, , , $fields, $opts] = self::KINDS[$kind];
        $e = [Menu::class, 'e'];
        $action = $e(Evento::getFormURL());
        $rows = self::forEvento($ev->getID(), $kind);
        $list = array_filter($fields, fn ($d) => $d[3]);
        $hidden = "<input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e($back) . "'>";
        $totals = [];
        foreach ($opts['totals'] ?? [] as $tf) {
            $totals[$tf] = array_sum(array_map(fn ($r) => (float) $r[$tf], array_filter($rows, fn ($r) => !in_array($r['status'], ['cancelado', 'rejeitada', 'rejeitado', 'descartada'], true))));
        }
        $sub = [];
        foreach ($totals as $tf => $sum) {
            if ($sum) {
                $sub[] = $e($fields[$tf][0]) . ' ' . ($fields[$tf][1] === 'money' ? Menu::money($sum) : Menu::num($sum, 0));
            }
        }
        $h = "<div class='$col'><div class='card h-100 ge-block' id='ge-k-$kind'><div class='card-header'><h3 class='card-title'><i class='ti $icon me-2'></i>" . $e($title) . "</h3>"
            . "<div class='card-actions small text-secondary'>" . count($rows) . ($sub ? ' · ' . implode(' · ', $sub) : '') . "</div></div>";
        $h .= "<div class='table-responsive'><table class='table table-sm card-table table-vcenter ge-items'><thead><tr>";
        foreach ($list as $d) {
            $h .= "<th>" . $e($d[0]) . "</th>";
        }
        $h .= "<th></th></tr></thead><tbody>";
        foreach ($rows as $r) {
            $h .= "<tr" . ($r['is_critical'] && !in_array($r['status'], ['feito', 'na', 'resolvida', 'concluido'], true) && in_array($kind, ['checklist', 'pendencia', 'marco'], true) ? " class='ge-crit'" : '') . ">";
            foreach (array_keys($list) as $f) {
                $h .= "<td" . (in_array($fields[$f][1], ['money', 'number'], true) ? " class='text-end'" : '') . ">" . self::cell($ev, $kind, $f, $r) . "</td>";
            }
            $h .= "<td class='text-end text-nowrap'>";
            if ($edit) {
                $h .= self::rowActions($ev, $kind, $r, $fields, $opts, $action, $hidden);
            }
            $h .= "</td></tr>";
            if ($edit) {
                $h .= "<tr class='ge-edit-row d-none'><td colspan='" . (count($list) + 1) . "'><form method='post' action='$action' enctype='multipart/form-data' class='row g-2 align-items-end'>$hidden<input type='hidden' name='reg[kind]' value='$kind'><input type='hidden' name='reg[id]' value='" . (int) $r['id'] . "'>"
                    . self::fieldsHtml($ev, $fields, $r) . "<div class='col-auto'><button class='btn btn-sm btn-primary' name='reg_save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div>" . Html::closeForm(false) . "</td></tr>";
            }
        }
        if (!$rows) {
            $h .= "<tr><td colspan='" . (count($list) + 1) . "' class='text-secondary small'>Nenhum registro ainda.</td></tr>";
        }
        $h .= "</tbody></table></div>";
        if ($edit) {
            $h .= "<form method='post' action='$action' enctype='multipart/form-data' class='card-footer ge-add row g-2 align-items-end'>$hidden<input type='hidden' name='reg[kind]' value='$kind'>" . self::fieldsHtml($ev, $fields, [])
                . "<div class='col-auto'><button class='btn btn-sm btn-outline-primary' name='reg_save' value='1'><i class='ti ti-plus me-1'></i>Adicionar</button></div>" . Html::closeForm(false);
        }
        return $h . "</div></div>";
    }

    private static function fieldsHtml(Evento $ev, array $fields, array $row): string
    {
        $e = [Menu::class, 'e'];
        $h = '';
        foreach ($fields as $f => $d) {
            $wide = in_array($d[1], ['textarea'], true) ? 'col-12' : (in_array($f, ['title', 'supplier', 'parent_id'], true) ? 'col-md-4' : ($d[1] === 'check' ? 'col-auto' : 'col-md-2'));
            $value = $d[1] === 'cat'
                ? trim(($row['category'] ?? '') . ((string) ($row['subcategory'] ?? '') !== '' ? ' > ' . $row['subcategory'] : ''))
                : ($row[$f] ?? ($f === 'status' ? '' : ($f === 'phase' ? '' : '')));
            $h .= "<div class='$wide'><label class='form-label small mb-0'>" . $e($d[0]) . "</label>" . self::input($ev, $d, $f, $value) . "</div>";
        }
        return $h;
    }

    private static function rowActions(Evento $ev, string $kind, array $r, array $fields, array $opts, string $action, string $hidden): string
    {
        $h = '';
        $id = (int) $r['id'];
        $btn = fn (string $name, string $value, string $icon, string $tip, string $cls = 'btn-ghost-secondary', string $confirm = '') => "<form method='post' action='$action' class='d-inline'>$hidden<input type='hidden' name='reg_id' value='$id'>"
            . "<button class='btn btn-sm $cls px-1' name='$name' value='$value' title='" . Menu::e($tip) . "'" . ($confirm ? " onclick=\"return confirm('" . Menu::e($confirm) . "')\"" : '') . "><i class='ti $icon'></i></button>" . Html::closeForm(false);
        // situação direta na linha
        if (isset($fields['status']) && ($opts['manual_status'] ?? true)) {
            $h .= "<form method='post' action='$action' class='d-inline'>$hidden<input type='hidden' name='reg_id' value='$id'><select class='form-select form-select-sm d-inline-block w-auto ge-status-pick' name='reg_status' onchange='this.form.submit()' title='Mudar a situação'>"
                . Menu::options(self::selectOptions($ev, $fields['status']), (string) $r['status']) . "</select>" . Html::closeForm(false);
        }
        switch ($kind) {
            case 'compra':
                if (in_array($r['status'], ['negociacao', 'aprovacao'], true)) {
                    $state = Aprovacao::stateFor($ev->getID(), 'compra', $id);
                    $h .= $state === 'pending' ? "<span class='badge bg-yellow-lt' title='Aguardando a alçada'>aprovação pendente</span> "
                        : ($state === 'approved' ? "<span class='badge bg-green-lt'>aprovada</span> " : $btn('reg_action', 'request_approval', 'ti-signature', 'Solicitar aprovação da compra', 'btn-ghost-primary'));
                }
                break;
            case 'mudanca':
            case 'aditivo':
                $state = Aprovacao::stateFor($ev->getID(), $kind, $id);
                if (in_array($r['status'], ['rascunho', 'solicitado'], true) && $state !== 'pending') {
                    $h .= $btn('reg_action', 'request_approval', 'ti-signature', 'Solicitar aprovação', 'btn-ghost-primary');
                } elseif ($state === 'pending') {
                    $h .= "<span class='badge bg-yellow-lt'>aprovação pendente</span> ";
                }
                break;
            case 'ocorrencia':
                if ((float) $r['amount_eac'] > 0 && !in_array(Aprovacao::stateFor($ev->getID(), 'emergencial', $id), ['pending', 'approved'], true)) {
                    $h .= $btn('reg_action', 'request_approval', 'ti-coin', 'Pedir aprovação do custo emergencial (exceção)', 'btn-ghost-primary');
                } elseif (Aprovacao::stateFor($ev->getID(), 'emergencial', $id) === 'pending') {
                    $h .= "<span class='badge bg-yellow-lt'>custo em aprovação</span> ";
                }
                break;
            case 'checklist':
            case 'marco':
            case 'atividade':
                if (!in_array($r['status'], ['feito', 'na'], true)) {
                    $h .= $btn('reg_action', 'done', 'ti-check', 'Marcar como concluído');
                }
                break;
            case 'lancamento':
                if ($r['status'] === 'realizado') {
                    $h .= $btn('reg_action', 'reconcile', 'ti-checks', 'Conciliar');
                }
                break;
            case 'proposta':
                if ($r['status'] !== 'escolhida') {
                    $h .= $btn('reg_action', 'choose', 'ti-star', 'Escolher esta proposta (define o fornecedor da requisição)');
                }
                break;
        }
        $h .= "<button type='button' class='btn btn-sm btn-ghost-secondary px-1' title='Editar' onclick=\"var n=this.closest('tr').nextElementSibling;n.classList.toggle('d-none')\"><i class='ti ti-pencil'></i></button>";
        $h .= $btn('reg_delete', '1', 'ti-trash', 'Remover', 'btn-ghost-danger', 'Remover este registro?');
        return $h;
    }

    /** Caminho do arquivo de um documento do GLPI (null se não existir). */
    public static function documentFile(int $docId): ?string
    {
        global $DB;
        $d = $DB->request(['SELECT' => ['filepath'], 'FROM' => 'glpi_documents', 'WHERE' => ['id' => $docId], 'LIMIT' => 1])->current();
        $path = $d ? GLPI_DOC_DIR . '/' . $d['filepath'] : '';
        return $d && is_file($path) ? $path : null;
    }

    /** Miniatura da foto (clica e abre a pré-visualização no painel lateral). */
    public static function thumb(Evento $ev, array $row): string
    {
        $docId = (int) ($row['doc_ref'] ?? 0);
        if (!$docId) {
            return "<span class='ge-thumb ge-thumb-empty' title='Sem foto'><i class='ti ti-photo-off'></i></span>";
        }
        $url = Documentos::url(['id' => $docId, 'evento_id' => $ev->getID()]);
        $meta = trim(($row['title'] ?? '') . ((float) $row['qty'] ? ' · ' . Menu::num((float) $row['qty'], 0) . ' ' . ($row['unit'] ?: 'un.') : '') . ((float) $row['amount_budget'] ? ' · ' . Menu::money((float) $row['amount_budget']) : '')
            . ((string) $row['supplier'] !== '' ? ' · ' . $row['supplier'] : ''), ' ·');
        $tip = json_encode(['title' => (string) $row['title'], 'url' => $url, 'kind' => 'img', 'icon' => 'ti-gift', 'color' => '#a855f7', 'meta' => $meta], JSON_UNESCAPED_UNICODE);
        return "<a href='#' data-doc='" . Menu::e($tip) . "' class='ge-thumb-link' title='Ver foto'><img class='ge-thumb' src='" . Menu::e($url) . "' alt='" . Menu::e($row['title']) . "' loading='lazy'></a>";
    }

    /** Guarda a foto enviada no formulário como documento do evento e liga ao registro (substitui a anterior). */
    public static function attachImage(Evento $ev, int $id, array $file): void
    {
        global $DB;
        if (empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        $row = self::row($id, $ev->getID());
        if (!$row) {
            return;
        }
        if (!is_uploaded_file((string) $file['tmp_name']) || @getimagesize((string) $file['tmp_name']) === false) {
            throw new RuntimeException('A foto precisa ser uma imagem (PNG, JPG, GIF ou WebP).');
        }
        $ids = [];
        Documentos::upload($ev, ['name' => [$file['name']], 'tmp_name' => [$file['tmp_name']], 'error' => [$file['error']], 'size' => [$file['size']]], 0, 'producao', 'Foto: ' . $row['title'], $ids);
        if (!$ids) {
            return;
        }
        if ((int) $row['doc_ref']) {
            try {
                Documentos::remove($ev, (int) $row['doc_ref']);
            } catch (RuntimeException) {
            }
        }
        $DB->update(self::getTable(), ['doc_ref' => (string) $ids[0], 'date_mod' => $_SESSION['glpi_currenttime']], ['id' => $id]);
    }

    // ------------------------------------------------------------------ gravação

    /** Valores do formulário já convertidos para as colunas do tipo. */
    private static function coerce(Evento $ev, string $kind, array $in): array
    {
        $fields = self::KINDS[$kind][4];
        $data = [];
        foreach ($fields as $f => $d) {
            if ($d[1] === 'image') {
                continue; // a foto vem do envio de arquivo, não do texto
            }
            $field = $d[1] === 'cat' ? 'category' : $f;
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $v = is_array($in[$field]) ? '' : trim((string) $in[$field]);
            switch ($d[1]) {
                case 'money':
                    $data[$f] = Menu::parseMoney($v);
                    break;
                case 'number':
                    $data[$f] = round((float) str_replace(',', '.', str_replace('.', '', preg_match('/,/', $v) ? $v : str_replace('.', ',', $v))), 2);
                    break;
                case 'date':
                    $data[$f] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
                    break;
                case 'check':
                    $data[$f] = $v === '1' ? 1 : 0;
                    break;
                case 'user':
                case 'parent':
                    $data[$f] = max(0, (int) $v);
                    break;
                case 'cat':
                    $parts = explode(' > ', $v, 2);
                    $data['category'] = mb_substr($parts[0], 0, 80);
                    $data['subcategory'] = mb_substr($parts[1] ?? '', 0, 80);
                    break;
                case 'select':
                    $opts = self::selectOptions($ev, $d, $f);
                    $data[$f] = $v === '' ? null : (isset($opts[$v]) ? $v : null);
                    break;
                case 'textarea':
                    $data[$f] = mb_substr($v, 0, 4000);
                    break;
                default:
                    $data[$f] = mb_substr($v, 0, 255);
            }
        }
        return $data;
    }

    public static function save(Evento $ev, array $in): int
    {
        global $DB;
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            throw new RuntimeException('Tipo de registro inválido.');
        }
        Acesso::requireEdit(self::KINDS[$kind][2]);
        self::checkFinance($ev, $kind);
        $data = self::coerce($ev, $kind, $in);
        $now = $_SESSION['glpi_currenttime'];
        $id = (int) ($in['id'] ?? 0);
        $old = $id ? self::row($id, $ev->getID()) : null;
        if ($id && !$old) {
            throw new RuntimeException('Registro não encontrado neste evento.');
        }
        if (!$id && implode('', array_map('strval', array_filter($data, fn ($v) => $v !== null && $v !== 0 && $v !== 0.0 && $v !== '0'))) === '') {
            throw new RuntimeException('Preencha o registro antes de adicionar.');
        }
        if (isset($data['title']) && $data['title'] === '' && isset(self::KINDS[$kind][4]['title'])) {
            throw new RuntimeException('Informe ' . mb_strtolower(self::KINDS[$kind][4]['title'][0]) . '.');
        }
        if (isset($data['status']) && $data['status'] !== ($old['status'] ?? null)) {
            self::gateStatus($ev, $kind, $old, (string) $data['status'], $data);
        }
        if ($kind === 'lancamento' && isset($data['nature'])) {
            $data['nature'] = $data['nature'] ?: 'despesa';
        }
        if ($kind === 'lancamento' && empty($data['cc'])) {
            $data['cc'] = (string) $ev->fields['cc_number'];
        }
        if ($id) {
            $DB->update(self::getTable(), $data + ['date_mod' => $now], ['id' => $id, 'plugin_gestaoeventos_eventos_id' => $ev->getID()]);
        } else {
            $defaults = ['kind' => $kind, 'plugin_gestaoeventos_eventos_id' => $ev->getID(), 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now, 'date_mod' => $now,
                'phase' => self::KINDS[$kind][3] ?: null, 'nature' => $kind === 'lancamento' ? 'despesa' : null, 'cc' => $kind === 'lancamento' ? (string) $ev->fields['cc_number'] : null];
            if (isset(self::KINDS[$kind][4]['status']) && empty($data['status'])) {
                $data['status'] = array_key_first(self::selectOptions($ev, self::KINDS[$kind][4]['status'], 'status'));
            }
            $DB->insert(self::getTable(), $data + $defaults);
            $id = (int) $DB->insertId();
        }
        self::after($ev, $kind, $id, $old);
        return $id;
    }

    /** Lançamento financeiro: só quem tem o direito Financeiro (ou administra). */
    private static function checkFinance(Evento $ev, string $kind): void
    {
        if (!empty(self::KINDS[$kind][5]['finance']) && !Evento::canFinance()) {
            throw new RuntimeException('Os lançamentos financeiros são restritos ao perfil Financeiro.');
        }
    }

    /** Regras ao mudar a situação: aprovação de Marketing, gate de contratação e consistência de datas/valores. */
    private static function gateStatus(Evento $ev, string $kind, ?array $old, string $new, array &$data): void
    {
        $opts = self::KINDS[$kind][5];
        if (isset($opts['marketing_status']) && in_array($new, ['aprovada', 'publicada'], true) && !Evento::isMarketing()) {
            Historico::record($ev->getID(), null, 'blocked', 'Aprovação de peça/brinde recusada: só o Marketing decide (' . getUserName((int) Session::getLoginUserID()) . ').');
            throw new RuntimeException('Identidade visual e brindes são aprovados pelo Marketing.');
        }
        if ($kind === 'compra') {
            $order = array_keys(self::KINDS['compra'][4]['status'][2]);
            $commit = ['contrato', 'pedido', 'producao', 'recebido', 'pago'];
            $amount = max((float) ($data['amount_committed'] ?? $old['amount_committed'] ?? 0), (float) ($data['amount_budget'] ?? $old['amount_budget'] ?? 0));
            if ($new === 'aprovacao' && !$old) {
                return;
            }
            if (!$old && in_array($new, $commit, true)) {
                Historico::record($ev->getID(), 'compras', 'blocked', 'Contratação recusada: requisição nova já marcada como "' . $new . '" sem passar pela aprovação.');
                throw new RuntimeException('Cadastre a requisição primeiro e peça a aprovação da alçada antes de contratar.');
            }
            if (in_array($new, $commit, true)) {
                $block = Gate::commitBlocks($ev, $amount, $old ? (int) $old['id'] : 0, (float) ($old['amount_committed'] ?? 0));
                if ($old && $new !== 'pago' && Aprovacao::stateFor($ev->getID(), 'compra', (int) $old['id']) !== 'approved' && $amount > 0) {
                    $block[] = 'A compra não tem aprovação da alçada (' . Menu::money($amount) . '). Solicite a aprovação antes de contratar.';
                }
                if ($block) {
                    Historico::record($ev->getID(), 'compras', 'blocked', 'Contratação recusada (' . ($old['title'] ?? 'nova requisição') . '): ' . implode(' ', $block));
                    throw new RuntimeException('Não é possível contratar: ' . implode(' ', $block));
                }
                if (empty($data['amount_committed']) && !(float) ($old['amount_committed'] ?? 0)) {
                    $data['amount_committed'] = $amount;
                }
                if ($new === 'pago') {
                    $data['amount_real'] = (float) ($data['amount_real'] ?? 0) ?: (float) ($old['amount_real'] ?? 0) ?: (float) ($data['amount_committed'] ?? $old['amount_committed'] ?? 0);
                    $data['date_done'] ??= date('Y-m-d');
                }
            }
            if ($new === 'cancelado' && $old && (float) $old['amount_real'] > 0) {
                throw new RuntimeException('Há pagamento registrado: cancele pelo Financeiro (estorno).');
            }
            unset($order);
        }
        if (in_array($new, ['feito', 'concluido', 'recebido', 'resolvida', 'entregue'], true) && array_key_exists('date_done', self::KINDS[$kind][4]) && empty($data['date_done']) && empty($old['date_done'])) {
            $data['date_done'] = date('Y-m-d');
        }
        if ($kind === 'mudanca' && in_array($new, ['aprovada', 'rejeitada'], true)) {
            throw new RuntimeException('A situação da mudança muda pela decisão da alçada.');
        }
        if ($kind === 'aditivo' && in_array($new, ['aprovado', 'rejeitado'], true)) {
            throw new RuntimeException('A situação do aditivo muda pela decisão da alçada.');
        }
    }

    /** Depois de gravar: espelho financeiro das compras, tolerância do EAC e histórico. */
    private static function after(Evento $ev, string $kind, int $id, ?array $old): void
    {
        $row = self::row($id);
        if (!$row) {
            return;
        }
        if ($kind === 'compra') {
            Financeiro::mirrorPurchase($ev, $row);
        }
        if (in_array($kind, ['lancamento', 'compra', 'aditivo', 'mudanca'], true)) {
            Financeiro::checkTolerance($ev);
        }
        if ($kind === 'marco') {
            Projeto::marcoToTask($ev, $row);
        }
        $statusChanged = $old && $old['status'] !== $row['status'];
        if (!$old || $statusChanged || in_array($kind, ['lancamento', 'compra'], true)) {
            $label = self::KINDS[$kind][0];
            $what = !$old ? 'incluído' : ($statusChanged ? 'situação ' . $old['status'] . ' → ' . $row['status'] : 'alterado');
            Historico::record($ev->getID(), self::KINDS[$kind][3] ?: null, in_array($kind, ['lancamento', 'compra'], true) ? 'finance' : 'change', "$label: " . ($row['title'] ?: '#' . $id) . " ($what)");
        }
        // as demais etapas leem o estado das abas: tenta avançar sozinha a etapa ativa quando tudo o que ela pede ficou pronto
        Fase::maybeAdvance($ev);
    }

    public static function setStatus(Evento $ev, int $id, string $status): void
    {
        $r = self::row($id, $ev->getID());
        if (!$r) {
            throw new RuntimeException('Registro não encontrado.');
        }
        self::save($ev, ['id' => $id, 'kind' => $r['kind'], 'status' => $status]);
    }

    public static function remove(Evento $ev, int $id): void
    {
        global $DB;
        $r = self::row($id, $ev->getID());
        if (!$r) {
            return;
        }
        if (isset(self::KINDS[$r['kind']])) {
            Acesso::requireEdit(self::KINDS[$r['kind']][2]);
        }
        self::checkFinance($ev, $r['kind']);
        if ($r['kind'] === 'compra' && ((float) $r['amount_committed'] > 0 || (float) $r['amount_real'] > 0)) {
            throw new RuntimeException('A compra já tem valor contratado ou pago: cancele em vez de remover.');
        }
        if ($r['kind'] === 'lancamento' && (float) $r['amount_real'] > 0 && $r['status'] === 'conciliado') {
            throw new RuntimeException('Lançamento conciliado não pode ser removido.');
        }
        $DB->delete(self::getTable(), ['id' => $id]);
        if ($r['kind'] === 'brinde' && (int) $r['doc_ref']) {
            try {
                Documentos::remove($ev, (int) $r['doc_ref']);
            } catch (RuntimeException) {
            }
        }
        if ($r['kind'] === 'compra') {
            $DB->delete(self::getTable(), ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'lancamento', 'parent_id' => $id]);
        }
        Historico::record($ev->getID(), self::KINDS[$r['kind']][3] ?: null, 'change', self::KINDS[$r['kind']][0] . ': removido — ' . ($r['title'] ?: '#' . $id));
        Financeiro::checkTolerance($ev);
    }

    /** Ações especiais de uma linha (solicitar aprovação, concluir, conciliar, escolher). */
    public static function action(Evento $ev, int $id, string $action): string
    {
        global $DB;
        $r = self::row($id, $ev->getID());
        if (!$r) {
            throw new RuntimeException('Registro não encontrado.');
        }
        $kind = $r['kind'];
        if (isset(self::KINDS[$kind])) {
            Acesso::requireEdit(self::KINDS[$kind][2]);
        }
        switch ($action) {
            case 'request_approval':
                [$akind, $amount] = match ($kind) {
                    'compra' => ['compra', max((float) $r['amount_committed'], (float) $r['amount_budget'])],
                    'mudanca' => ['mudanca', abs((float) $r['amount_eac'])],
                    'aditivo' => ['aditivo', abs((float) $r['amount_eac'])],
                    'ocorrencia' => ['emergencial', (float) $r['amount_eac']],
                    default => throw new RuntimeException('Este registro não tem aprovação.'),
                };
                if ($kind === 'compra') {
                    $block = Gate::commitBlocks($ev, $amount, $id, (float) $r['amount_committed']);
                    if ($block) {
                        Historico::record($ev->getID(), 'compras', 'blocked', 'Pedido de aprovação da compra "' . $r['title'] . '" recusado: ' . implode(' ', $block));
                        throw new RuntimeException(implode(' ', $block));
                    }
                    $DB->update(self::getTable(), ['status' => 'aprovacao', 'date_mod' => $_SESSION['glpi_currenttime']], ['id' => $id]);
                } elseif (in_array($kind, ['mudanca', 'aditivo'], true)) {
                    $DB->update(self::getTable(), ['status' => $kind === 'mudanca' ? 'em_aprovacao' : 'solicitado', 'date_mod' => $_SESSION['glpi_currenttime']], ['id' => $id]);
                }
                Aprovacao::request($ev, $akind, (string) $r['title'], $amount, $id, '');
                Fase::maybeAdvance($ev);
                return 'Aprovação solicitada às alçadas.';
            case 'done':
                self::setStatus($ev, $id, $kind === 'checklist' ? 'feito' : ($kind === 'atividade' || $kind === 'marco' ? 'feito' : 'concluido'));
                return 'Concluído.';
            case 'reconcile':
                self::setStatus($ev, $id, 'conciliado');
                return 'Lançamento conciliado.';
            case 'choose':
                $DB->update(self::getTable(), ['status' => 'descartada'], ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'proposta', 'parent_id' => $r['parent_id'], 'NOT' => ['id' => $id]]);
                $DB->update(self::getTable(), ['status' => 'escolhida', 'date_mod' => $_SESSION['glpi_currenttime']], ['id' => $id]);
                if ((int) $r['parent_id']) {
                    $DB->update(self::getTable(), ['supplier' => $r['supplier'], 'status' => 'negociacao', 'date_mod' => $_SESSION['glpi_currenttime']], ['id' => (int) $r['parent_id'], 'status' => ['requisicao', 'cotacao']]);
                    $DB->update(self::getTable(), ['supplier' => $r['supplier']], ['id' => (int) $r['parent_id']]);
                }
                Historico::record($ev->getID(), 'compras', 'change', 'Proposta escolhida: ' . $r['supplier'] . ' (' . Menu::money((float) $r['amount_budget']) . ')');
                return 'Proposta escolhida. As demais foram descartadas.';
        }
        throw new RuntimeException('Ação desconhecida.');
    }

    /** Efeito de uma aprovação resolvida no registro de origem. */
    public static function onApproval(int $id, string $kind, string $outcome, Evento $ev): void
    {
        global $DB;
        $r = self::row($id);
        if (!$r) {
            return;
        }
        $now = $_SESSION['glpi_currenttime'];
        switch ($kind) {
            case 'compra':
                if ($outcome === 'approved') {
                    $DB->update(self::getTable(), ['status' => 'contrato', 'amount_committed' => (float) $r['amount_committed'] ?: max((float) $r['amount_budget'], 0), 'date_mod' => $now], ['id' => $id]);
                    Financeiro::mirrorPurchase($ev, self::row($id));
                } else {
                    $DB->update(self::getTable(), ['status' => 'negociacao', 'date_mod' => $now], ['id' => $id]);
                }
                break;
            case 'mudanca':
                $DB->update(self::getTable(), ['status' => $outcome === 'approved' ? 'aprovada' : ($outcome === 'rejected' ? 'rejeitada' : 'rascunho'), 'date_mod' => $now], ['id' => $id]);
                break;
            case 'aditivo':
                $DB->update(self::getTable(), ['status' => $outcome === 'approved' ? 'aprovado' : ($outcome === 'rejected' ? 'rejeitado' : 'solicitado'), 'date_mod' => $now], ['id' => $id]);
                if ($outcome === 'approved' && (int) $r['parent_id']) {
                    $parent = self::row((int) $r['parent_id']);
                    if ($parent) {
                        $DB->update(self::getTable(), ['amount_committed' => round((float) $parent['amount_committed'] + (float) $r['amount_eac'], 2), 'date_mod' => $now], ['id' => (int) $parent['id']]);
                        Financeiro::mirrorPurchase($ev, self::row((int) $parent['id']));
                    }
                }
                break;
            case 'emergencial':
                if ($outcome === 'approved') {
                    $DB->insert(self::getTable(), [
                        'plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'lancamento', 'parent_id' => 0, 'title' => 'Custo emergencial: ' . $r['title'], 'category' => 'Locação e serviços', 'subcategory' => 'Mão de obra',
                        'nature' => 'despesa', 'status' => 'comprometido', 'amount_eac' => $r['amount_eac'], 'amount_committed' => $r['amount_eac'], 'cc' => (string) $ev->fields['cc_number'],
                        'extra' => json_encode(['source' => 'ocorrencia', 'id' => $id]), 'phase' => 'execucao', 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now, 'date_mod' => $now,
                    ]);
                    Financeiro::checkTolerance($ev);
                }
                break;
        }
    }
}
