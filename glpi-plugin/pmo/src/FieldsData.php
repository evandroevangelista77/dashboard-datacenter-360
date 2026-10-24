<?php

namespace GlpiPlugin\Pmo;

use Plugin;

/**
 * Leitura (somente) do bloco "Dados do projeto" do plugin Fields, achado pelo
 * rótulo, para mostrar cliente, classificação, líder técnico etc. no portfólio.
 */
class FieldsData
{
    public const CONTAINER_LABEL = 'Dados do projeto';

    /** Rótulos exibidos no portfólio, na ordem. */
    public const SHOWN = ['Cliente', 'Classificação', 'Líder Técnico', 'Ponto de Atenção', 'Status Atual do Projeto', 'Prazo EAC', 'Previsão Conclusão GP'];

    /** @return array<int, array<string, string>> projeto => [rótulo => valor exibível] */
    public static function forProjects(array $projectIds): array
    {
        global $DB;
        if (!$projectIds || !(new Plugin())->isActivated('fields') || !$DB->tableExists('glpi_plugin_fields_containers')) {
            return [];
        }
        $container = $DB->request(['FROM' => 'glpi_plugin_fields_containers', 'WHERE' => ['label' => self::CONTAINER_LABEL, 'is_active' => 1]])->current();
        if (!$container) {
            return [];
        }
        $table = 'glpi_plugin_fields_project' . $container['name'] . 's';
        if (!$DB->tableExists($table)) {
            return [];
        }
        $fields = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields', 'WHERE' => ['plugin_fields_containers_id' => $container['id'], 'label' => self::SHOWN]]) as $f) {
            $fields[$f['label']] = $f;
        }
        $out = [];
        $dropdownNames = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['items_id' => $projectIds, 'itemtype' => 'Project']]) as $row) {
            $vals = [];
            foreach ($fields as $label => $f) {
                $name = $f['name'];
                if ($f['type'] === 'dropdown') {
                    $id = (int) ($row["plugin_fields_{$name}dropdowns_id"] ?? 0);
                    $dtable = "glpi_plugin_fields_{$name}dropdowns";
                    if ($id && !isset($dropdownNames[$dtable][$id]) && $DB->tableExists($dtable)) {
                        $dropdownNames[$dtable][$id] = (string) ($DB->request(['SELECT' => ['name'], 'FROM' => $dtable, 'WHERE' => ['id' => $id]])->current()['name'] ?? '');
                    }
                    $vals[$label] = $id ? ($dropdownNames[$dtable][$id] ?? '') : '';
                } elseif (str_starts_with($f['type'], 'dropdown-User')) {
                    $id = (int) ($row[$name] ?? 0);
                    $vals[$label] = $id ? (string) getUserName($id) : '';
                } elseif ($f['type'] === 'date') {
                    $v = (string) ($row[$name] ?? '');
                    $vals[$label] = $v ? date('d/m/Y', strtotime($v)) : '';
                } else {
                    $vals[$label] = trim(strip_tags((string) ($row[$name] ?? '')));
                }
            }
            $out[(int) $row['items_id']] = $vals;
        }
        return $out;
    }
}
