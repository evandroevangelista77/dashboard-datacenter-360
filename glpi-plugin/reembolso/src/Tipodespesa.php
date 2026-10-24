<?php

namespace GlpiPlugin\Reembolso;

use CommonDropdown;

/**
 * Tipo de despesa (lista suspensa): se é calculada por quilometragem, o limite
 * por lançamento e se exige comprovante.
 */
class Tipodespesa extends CommonDropdown
{
    public static string $rightname = 'dropdown';

    /** Criados na instalação: nome, por KM, limite por lançamento (R$), exige comprovante. */
    public const DEFAULTS = [
        ['Quilometragem (veículo próprio)', 1, 0, 0],
        ['Combustível (veículo da empresa ou locado)', 0, 0, 1],
        ['Pedágio', 0, 0, 1],
        ['Estacionamento', 0, 0, 1],
        ['Café da manhã', 0, 35, 1],
        ['Almoço', 0, 70, 1],
        ['Jantar', 0, 70, 1],
        ['Lanche', 0, 25, 1],
        ['Hospedagem', 0, 0, 1],
        ['Lavanderia (viagem longa)', 0, 0, 1],
        ['Táxi / aplicativo de transporte', 0, 0, 1],
        ['Transporte público (ônibus, metrô, trem)', 0, 0, 1],
        ['Passagem rodoviária', 0, 0, 1],
        ['Passagem aérea', 0, 0, 1],
        ['Bagagem e taxa de embarque', 0, 0, 1],
        ['Locação de veículo', 0, 0, 1],
        ['Balsa / travessia', 0, 0, 1],
        ['Lavagem de veículo', 0, 0, 1],
        ['Manutenção emergencial de veículo', 0, 0, 1],
        ['Material de consumo', 0, 0, 1],
        ['Ferramentas e equipamentos', 0, 0, 1],
        ['Peças e componentes', 0, 0, 1],
        ['Cabos, conectores e acessórios', 0, 0, 1],
        ['Frete / Correios / envio de equipamento', 0, 0, 1],
        ['Cópias e impressões', 0, 0, 1],
        ['Telefonia / internet móvel', 0, 0, 1],
        ['Diária de viagem', 0, 0, 0],
        ['Seguro viagem', 0, 0, 1],
        ['Vistos e documentos', 0, 0, 1],
        ['Outros', 0, 0, 1],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Tipos de despesa' : 'Tipo de despesa';
    }

    public static function getIcon()
    {
        return 'ti ti-receipt';
    }

    public function getAdditionalFields()
    {
        return [
            ['name' => 'is_mileage', 'label' => 'Calculada por quilometragem (KM × valor por KM)', 'type' => 'bool'],
            ['name' => 'max_amount', 'label' => 'Limite por lançamento (R$, 0 = sem limite)', 'type' => 'text'],
            ['name' => 'requires_receipt', 'label' => 'Exige comprovante', 'type' => 'bool'],
        ];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = ['id' => 101, 'table' => $this->getTable(), 'field' => 'is_mileage', 'name' => 'Por quilometragem', 'datatype' => 'bool'];
        $tab[] = ['id' => 102, 'table' => $this->getTable(), 'field' => 'max_amount', 'name' => 'Limite por lançamento', 'datatype' => 'decimal'];
        $tab[] = ['id' => 103, 'table' => $this->getTable(), 'field' => 'requires_receipt', 'name' => 'Exige comprovante', 'datatype' => 'bool'];
        return $tab;
    }

    public function prepareInputForAdd($input)
    {
        return parent::prepareInputForAdd(self::normalize($input));
    }

    public function prepareInputForUpdate($input)
    {
        return parent::prepareInputForUpdate(self::normalize($input));
    }

    /** Aceita "70,00" ou "70.00" no limite. */
    private static function normalize(array $input): array
    {
        if (isset($input['max_amount'])) {
            $input['max_amount'] = max(0, Money::parse((string) $input['max_amount']));
        }
        return $input;
    }

    /** Tipos para o formulário de despesa (id => dados). */
    public static function all(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'name']) as $t) {
            $out[(int) $t['id']] = [
                'id'      => (int) $t['id'],
                'name'    => (string) $t['name'],
                'mileage' => (bool) $t['is_mileage'],
                'max'     => (float) $t['max_amount'],
                'receipt' => (bool) $t['requires_receipt'],
            ];
        }
        return $out;
    }
}
