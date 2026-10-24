<?php

namespace GlpiPlugin\Rh;

use CommonDropdown;

/**
 * Departamento, com o gestor (aprovador padrão das vagas da área).
 */
class Departamento extends CommonDropdown
{
    public static string $rightname = 'dropdown';

    public const DEFAULTS = ['Tecnologia da Informação', 'Suporte e Serviços', 'Infraestrutura', 'Comercial', 'Financeiro', 'Recursos Humanos', 'Administrativo', 'Operações'];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Departamentos' : 'Departamento';
    }

    public static function getIcon()
    {
        return 'ti ti-sitemap';
    }

    public function getAdditionalFields()
    {
        return [['name' => 'users_id', 'label' => 'Gestor (aprova as vagas do departamento)', 'type' => 'UserDropdown']];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = ['id' => 101, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'name' => 'Gestor', 'datatype' => 'dropdown', 'right' => 'all'];
        return $tab;
    }

    public static function manager(int $id): int
    {
        $d = new self();
        return $id && $d->getFromDB($id) ? (int) $d->fields['users_id'] : 0;
    }
}
