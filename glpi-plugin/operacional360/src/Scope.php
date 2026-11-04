<?php

namespace GlpiPlugin\Operacional360;

use Session;

/**
 * Quais entidades e host groups o usuário vê: só as entidades do mapa que estão
 * liberadas no perfil ativo (Session::haveAccessToEntity), e só os host groups
 * ligados a cada uma delas.
 */
class Scope
{
    /** Entidades do mapa que o usuário pode ver: id => nome completo. */
    public static function accessible(): array
    {
        $out = [];
        foreach (Settings::mapping() as $m) {
            $eid = $m['entity_id'];
            if (isset($out[$eid]) || !Session::haveAccessToEntity($eid)) {
                continue;
            }
            $name = Glpi::entityName($eid);
            if ($name !== '' && Settings::inEntityRoot($name)) {
                $out[$eid] = $name;
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Entidade do painel: a pedida (se o usuário pode ver) ou a primeira disponível.
     * @return array{id:int,name:string,short:string,groups:array,entities:array,severities:array}
     */
    public static function resolve(?int $requested): array
    {
        $list = self::accessible();
        if (!$list) {
            throw new HttpError(403, 'Nenhuma entidade com host group configurado está liberada para o seu perfil.');
        }
        if ($requested !== null && $requested > 0 && !isset($list[$requested])) {
            throw new HttpError(403, 'Entidade fora do seu acesso.');
        }
        $eid = ($requested !== null && $requested > 0) ? $requested : (int) array_key_first($list);
        $groups = array_values(array_filter(Settings::mapping(), fn ($m) => $m['entity_id'] === $eid));

        $sev = [];
        foreach ($groups as $g) {
            array_push($sev, ...$g['severities']);
        }
        $sev = array_values(array_unique($sev));
        rsort($sev);

        $entities = [];
        foreach ($list as $id => $name) {
            $entities[] = ['id' => $id, 'name' => $name, 'short' => Glpi::lastSegment($name)];
        }
        return [
            'id'         => $eid,
            'name'       => $list[$eid],
            'short'      => Glpi::lastSegment($list[$eid]),
            'groups'     => $groups,
            'severities' => $sev,
            'entities'   => $entities,
        ];
    }
}
