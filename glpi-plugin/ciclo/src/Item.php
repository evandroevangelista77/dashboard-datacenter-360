<?php

namespace GlpiPlugin\Ciclo;

use CommonDBTM;
use RuntimeException;
use Session;

/**
 * Itens da venda. Um Ciclo tem quantos quiser de cada tipo: vários pedidos do
 * Power Automate, RMs, pedidos a fornecedores, entregas parciais, NFs e parcelas.
 */
class Item extends CommonDBTM
{
    public static string $rightname = 'plugin_ciclo';

    /** tipo => [título, ícone, fase em que é preenchido, colunas: campo => rótulo] */
    public const KINDS = [
        'pedido_pa'  => ['Pedidos do Power Automate', 'ti-brand-office', 'transfer', ['number' => 'Nº do pedido', 'description' => 'Descrição', 'status' => 'Aprovação']],
        'rm'         => ['RMs de compra (TOTVS RM)', 'ti-file-invoice', 'requisicao', ['number' => 'Nº da RM', 'description' => 'Descrição', 'amount' => 'Valor']],
        'pedido'     => ['Pedidos a fornecedores', 'ti-truck-delivery', 'compra', ['supplier' => 'Fornecedor', 'number' => 'Nº do pedido', 'category' => 'Tipo', 'amount' => 'Valor', 'date_expected' => 'Previsão de entrega', 'date_done' => 'Entregue em']],
        'nf_entrada' => ['NFs de entrada (recebimento)', 'ti-package-import', 'recebimento', ['number' => 'Nº da NF', 'supplier' => 'Fornecedor', 'amount' => 'Valor', 'date_done' => 'Recebida em', 'status' => 'Conferência']],
        'nf_saida'   => ['NFs de saída (faturamento)', 'ti-receipt', 'faturamento', ['number' => 'Nº da NF', 'category' => 'Tipo', 'description' => 'Referente a', 'amount' => 'Valor', 'date_done' => 'Emitida em']],
        'parcela'    => ['Parcelas a receber', 'ti-cash', 'recebido', ['description' => 'Parcela', 'amount' => 'Valor', 'date_expected' => 'Vencimento', 'date_done' => 'Recebida em']],
    ];

    /** Valores possíveis do campo "status" de cada tipo. */
    public const STATUSES = [
        'pedido_pa'  => ['pending' => 'Em aprovação', 'ok' => 'Aprovado', 'nok' => 'Reprovado'],
        'nf_entrada' => ['pending' => 'A conferir', 'ok' => 'OK', 'nok' => 'NOK (divergente)'],
    ];

    public const CATEGORIES = ['hw' => 'Hardware', 'sw' => 'Software', 'srv' => 'Serviço'];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Itens do Ciclo' : 'Item do Ciclo';
    }

    public static function forCiclo(int $cicloId, ?string $kind = null): array
    {
        global $DB;
        $where = ['plugin_ciclo_ciclos_id' => $cicloId];
        if ($kind) {
            $where['kind'] = $kind;
        }
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'id']), false);
    }

    /** Grava um item novo ou alterado (só os campos do tipo). */
    public static function save(Ciclo $c, array $in): int
    {
        global $DB;
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            throw new RuntimeException('Tipo de item inválido.');
        }
        $data = [];
        foreach (array_keys(self::KINDS[$kind][3]) as $field) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $v = trim((string) $in[$field]);
            $data[$field] = match ($field) {
                'amount' => Menu::parseMoney($v),
                'date_expected', 'date_done' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null,
                'status' => isset(self::STATUSES[$kind][$v]) ? $v : null,
                'category' => isset(self::CATEGORIES[$v]) ? $v : null,
                default => mb_substr($v, 0, 255),
            };
        }
        $now = $_SESSION['glpi_currenttime'];
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            $DB->update(self::getTable(), $data + ['date_mod' => $now], ['id' => $id, 'plugin_ciclo_ciclos_id' => $c->getID()]);
            return $id;
        }
        if (implode('', array_map('strval', array_filter($data, fn ($v) => $v !== null && $v !== 0.0))) === '') {
            throw new RuntimeException('Preencha o item antes de adicionar.');
        }
        if (isset(self::STATUSES[$kind]) && empty($data['status'])) {
            $data['status'] = 'pending';
        }
        $DB->insert(self::getTable(), $data + [
            'plugin_ciclo_ciclos_id' => $c->getID(), 'kind' => $kind, 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now, 'date_mod' => $now,
        ]);
        return (int) $DB->insertId();
    }

    public static function remove(Ciclo $c, int $id): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['id' => $id, 'plugin_ciclo_ciclos_id' => $c->getID()]);
    }

    /** Valor de uma coluna, pronto para mostrar. */
    public static function show(string $kind, string $field, array $row): string
    {
        $v = $row[$field] ?? null;
        return match ($field) {
            'amount' => (float) $v ? Menu::money((float) $v) : '—',
            'date_expected', 'date_done' => Menu::date($v),
            'status' => self::STATUSES[$kind][$v] ?? '—',
            'category' => self::CATEGORIES[$v] ?? '—',
            default => (string) $v !== '' ? (string) $v : '—',
        };
    }
}
