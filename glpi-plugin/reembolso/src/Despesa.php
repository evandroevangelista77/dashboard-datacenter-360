<?php

namespace GlpiPlugin\Reembolso;

use CommonDBChild;
use RuntimeException;

/**
 * Despesa lançada numa solicitação. Nos tipos por quilometragem o valor é
 * KM rodado × valor por KM; o KM vem da rota (origem → destino, opcionalmente
 * ida e volta) ou da diferença do odômetro (KM final − KM inicial).
 */
class Despesa extends CommonDBChild
{
    public static string $itemtype = Reembolso::class;
    public static string $items_id = 'plugin_reembolso_reembolsos_id';
    public bool $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Despesas' : 'Despesa';
    }

    public static function forReembolso(int $id): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::$items_id => $id], 'ORDER' => ['date', 'id']]), false);
    }

    public static function toArray(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'type'        => (int) $r['plugin_reembolso_tipodespesas_id'],
            'date'        => (string) $r['date'],
            'description' => (string) $r['description'],
            'origin'      => (string) $r['origin'],
            'destination' => (string) $r['destination'],
            'round_trip'  => (bool) $r['round_trip'],
            'km_start'    => $r['km_start'] === null ? null : (float) $r['km_start'],
            'km_end'      => $r['km_end'] === null ? null : (float) $r['km_end'],
            'distance'    => (float) $r['distance'],
            'km_rate'     => (float) $r['km_rate'],
            'amount'      => (float) $r['amount'],
        ];
    }

    /**
     * Grava (cria ou atualiza) uma despesa a partir do formulário, validando e
     * calculando o valor no servidor (o navegador só mostra a prévia).
     */
    public static function save(Reembolso $reembolso, array $in): int
    {
        if (!$reembolso->isEditable()) {
            throw new RuntimeException('Esta solicitação não pode mais ser alterada nesta etapa.');
        }
        $types = Tipodespesa::all();
        $type = $types[(int) ($in['type'] ?? 0)] ?? null;
        if (!$type) {
            throw new RuntimeException('Escolha o tipo de despesa.');
        }
        $date = (string) ($in['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Informe a data da despesa.');
        }
        if ($date > date('Y-m-d')) {
            throw new RuntimeException('A data da despesa não pode ser futura.');
        }
        $data = [
            Reembolso::getForeignKeyField()   => $reembolso->getID(),
            'plugin_reembolso_tipodespesas_id' => $type['id'],
            'date'                             => $date,
            'description'                      => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255),
            'origin'                           => null,
            'destination'                      => null,
            'round_trip'                       => 0,
            'km_start'                         => null,
            'km_end'                           => null,
            'distance'                         => 0,
            'km_rate'                          => 0,
        ];

        if ($type['mileage']) {
            $data['origin'] = mb_substr(trim((string) ($in['origin'] ?? '')), 0, 255) ?: null;
            $data['destination'] = mb_substr(trim((string) ($in['destination'] ?? '')), 0, 255) ?: null;
            $data['round_trip'] = empty($in['round_trip']) ? 0 : 1;
            $start = ($in['km_start'] ?? '') === '' || $in['km_start'] === null ? null : Money::parse((string) $in['km_start']);
            $end = ($in['km_end'] ?? '') === '' || $in['km_end'] === null ? null : Money::parse((string) $in['km_end']);
            if ($start !== null && $end !== null && $end < $start) {
                throw new RuntimeException('O KM final do odômetro é menor que o inicial.');
            }
            $data['km_start'] = $start;
            $data['km_end'] = $end;
            // KM rodado: o informado (rota calculada ou digitado); se vazio, a diferença do odômetro
            $distance = Money::parse((string) ($in['distance'] ?? ''));
            if ($distance <= 0 && $start !== null && $end !== null) {
                $distance = $end - $start;
            }
            if ($distance <= 0) {
                throw new RuntimeException('Informe o KM rodado: calcule pela rota (origem → destino) ou preencha o KM inicial e final do odômetro.');
            }
            if ($distance > 5000) {
                throw new RuntimeException('KM rodado acima de 5.000 km num só lançamento: confira os valores.');
            }
            $data['distance'] = round($distance, 1);
            // o valor por KM vem da configuração; só quem aprova pode usar outro
            $rate = Money::config()['km_rate'];
            if (Reembolso::isApprover() && isset($in['km_rate']) && $in['km_rate'] !== '') {
                $rate = Money::parse((string) $in['km_rate']);
            }
            $data['km_rate'] = $rate;
            $data['amount'] = round($data['distance'] * $rate, 2);
        } else {
            $data['amount'] = round(Money::parse((string) ($in['amount'] ?? '')), 2);
        }
        if ($data['amount'] <= 0) {
            throw new RuntimeException('O valor da despesa precisa ser maior que zero.');
        }
        if ($data['amount'] > 100000) {
            throw new RuntimeException('Valor acima de R$ 100.000,00 num só lançamento: confira o valor.');
        }

        $item = new self();
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            if (!$item->getFromDB($id) || (int) $item->fields[self::$items_id] !== (int) $reembolso->getID()) {
                throw new RuntimeException('Despesa não encontrada.');
            }
            $item->update(['id' => $id] + $data);
        } else {
            $id = (int) $item->add($data);
            if (!$id) {
                throw new RuntimeException('O GLPI não gravou a despesa.');
            }
        }
        Reembolso::recalc((int) $reembolso->getID());
        return $id;
    }

    public static function remove(Reembolso $reembolso, int $id): void
    {
        if (!$reembolso->isEditable()) {
            throw new RuntimeException('Esta solicitação não pode mais ser alterada nesta etapa.');
        }
        $item = new self();
        if (!$item->getFromDB($id) || (int) $item->fields[self::$items_id] !== (int) $reembolso->getID()) {
            throw new RuntimeException('Despesa não encontrada.');
        }
        $item->delete(['id' => $id], true);
        Reembolso::recalc((int) $reembolso->getID());
    }
}
