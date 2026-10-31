<?php

namespace GlpiPlugin\Gestaoeventos;

/** Nome da tabela de cada classe auxiliar (elas não são itens do GLPI, só guardam e leem dados). */
trait Tabela
{
    public static function getTable(): string
    {
        return static::TABLE;
    }
}
