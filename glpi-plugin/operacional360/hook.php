<?php

use GlpiPlugin\Operacional360\Menu;
use GlpiPlugin\Operacional360\Settings;

/**
 * Instalação: cria o direito de ver o painel (todo perfil da interface central) e a
 * configuração padrão. Não cria tabelas: o mapa fica na configuração do plugin.
 */
function plugin_operacional360_install(): bool
{
    global $DB;

    // criar o direito só se ainda não existir (repetir a instalação daria chave duplicada)
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Menu::$rightname])) {
        ProfileRight::addProfileRights([Menu::$rightname]);
    }
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
        $pid = (int) $row['id'];
        $current = (int) ($DB->request(['SELECT' => ['rights'], 'FROM' => ProfileRight::getTable(),
            'WHERE' => ['profiles_id' => $pid, 'name' => Menu::$rightname]])->current()['rights'] ?? 0);
        if (!$current) {
            // a entidade e o host group já limitam o que cada um vê: todo perfil pode abrir o painel
            $DB->update(ProfileRight::getTable(), ['rights' => READ], ['profiles_id' => $pid, 'name' => Menu::$rightname]);
        }
    }

    Settings::installDefaults();
    unset($_SESSION['glpimenu']);

    return true;
}

function plugin_operacional360_uninstall(): bool
{
    ProfileRight::deleteProfileRights([Menu::$rightname]);
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Settings::DEFAULTS));
    unset($_SESSION['glpimenu']);

    return true;
}
