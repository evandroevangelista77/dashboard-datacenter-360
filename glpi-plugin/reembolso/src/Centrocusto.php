<?php

namespace GlpiPlugin\Reembolso;

use CommonDropdown;

/**
 * Centro de custo (lista suspensa com código).
 */
class Centrocusto extends CommonDropdown
{
    public static string $rightname = 'dropdown';

    /** Exemplos criados na instalação: código, nome. */
    public const DEFAULTS = [
        ['CC-100', 'Suporte técnico'],
        ['CC-200', 'Infraestrutura e redes'],
        ['CC-300', 'Projetos'],
        ['CC-400', 'Comercial e pré-vendas'],
        ['CC-500', 'Administrativo'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Centros de custo' : 'Centro de custo';
    }

    public static function getIcon()
    {
        return 'ti ti-building-bank';
    }

    public function getAdditionalFields()
    {
        return [
            ['name' => 'code', 'label' => 'Código', 'type' => 'text'],
            ['name' => 'users_id_manager', 'label' => 'Gestor do centro de custo (aprova os lotes)', 'type' => 'UserDropdown', 'right' => 'all'],
            ['name' => 'groups_id_manager', 'label' => 'Grupo gestor do centro de custo', 'type' => 'dropdownValue'],
        ];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = ['id' => 101, 'table' => $this->getTable(), 'field' => 'code', 'name' => 'Código', 'datatype' => 'string'];
        $tab[] = ['id' => 102, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_manager', 'name' => 'Gestor do centro de custo', 'datatype' => 'dropdown', 'right' => 'all'];
        return $tab;
    }

    /** "CC-100 - Suporte técnico" */
    public static function label(int $id): string
    {
        $c = new self();
        if (!$id || !$c->getFromDB($id)) {
            return '';
        }
        return trim(($c->fields['code'] ? $c->fields['code'] . ' - ' : '') . $c->fields['name']);
    }
}
