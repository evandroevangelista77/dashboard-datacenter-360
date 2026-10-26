<?php

namespace GlpiPlugin\Pmo;

use Project;

/** Documentos dos projetos (nativos do GLPI) na biblioteca de documentos. */
class Docs
{
    public static function sources(): array
    {
        return [
            Project::class => ['label' => 'Projetos', 'icon' => 'ti-briefcase', 'color' => '#3b82f6',
                'code' => fn ($f) => (string) ($f['code'] ?: 'Projeto ' . (int) $f['id']),
                'title' => fn ($f) => (string) $f['name'],
                'sub' => fn ($f) => $f['users_id'] ? 'gerente ' . getUserName((int) $f['users_id']) : ''],
        ];
    }

    public static function card(Project $project): string
    {
        return Biblioteca::card(self::sources(), $project, Menu::url('/front/documentos.form.php'), 'Arquivos do projeto', 'Cronograma (.mpp), atas, propostas, contratos, evidências de entrega…')
            . View::script('drawer.js') . View::script('biblioteca.js');
    }
}
