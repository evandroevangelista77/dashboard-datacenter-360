<?php

use GlpiPlugin\Datacenter360\Menu;

/**
 * Cria o direito "plugin_datacenter360" e libera leitura para os perfis da
 * interface padrão (técnicos e administradores). Perfis self-service não veem.
 */
function plugin_datacenter360_install(): bool
{
    global $DB;

    ProfileRight::addProfileRights([Menu::$rightname]);

    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
        $DB->update(
            ProfileRight::getTable(),
            ['rights' => READ],
            ['profiles_id' => $row['id'], 'name' => Menu::$rightname]
        );
    }

    // perfil da sessão atual passa a enxergar o menu sem precisar relogar
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $_SESSION['glpiactiveprofile'][Menu::$rightname] = (int) ($DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => ['profiles_id' => $_SESSION['glpiactiveprofile']['id'], 'name' => Menu::$rightname],
        ])->current()['rights'] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    return true;
}

function plugin_datacenter360_uninstall(): bool
{
    ProfileRight::deleteProfileRights([Menu::$rightname]);
    unset($_SESSION['glpimenu']);
    return true;
}
