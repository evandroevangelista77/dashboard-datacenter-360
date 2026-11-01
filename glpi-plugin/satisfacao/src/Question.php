<?php

namespace GlpiPlugin\Satisfacao;

use CommonDBTM;
use Session;

/**
 * Pergunta da pesquisa. As perguntas são editadas no plugin e valem para as pesquisas
 * enviadas daí em diante; as respostas antigas guardam o texto que foi perguntado.
 * Pergunta usada não se apaga: desativa-se.
 */
class Question extends CommonDBTM
{
    public static string $rightname = 'plugin_satisfacao';

    public const TYPES = [
        'rating' => 'Estrelas (nota de 1 a 5)',
        'nps'    => 'Recomendação (nota de 0 a 10)',
        'yesno'  => 'Sim ou não',
        'text'   => 'Comentário livre',
    ];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Perguntas' : 'Pergunta';
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    /** Perguntas ativas, na ordem da pesquisa. */
    public static function active(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => ['position', 'id']]));
    }

    /** Todas as perguntas, inclusive as desativadas, na ordem da tela. */
    public static function all(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'ORDER' => ['position', 'id']]));
    }

    /** Sobe (-1) ou desce (+1) a pergunta, trocando de lugar com a vizinha. */
    public function move(int $direction): void
    {
        global $DB;
        $ids = array_map('intval', array_column(self::all(), 'id'));
        $i = array_search((int) $this->getID(), $ids, true);
        $j = $i === false ? null : $i + $direction;
        if ($j === null || !isset($ids[$j])) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $n => $id) {
            $DB->update(self::getTable(), ['position' => ($n + 1) * 10], ['id' => $id]);
        }
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->clean($input);
        if ($input === false) {
            return false;
        }
        $input['position'] = $this->nextPosition();
        $input['date_creation'] = date('Y-m-d H:i:s');
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->clean($input);
        if ($input === false) {
            return false;
        }
        $input['date_mod'] = date('Y-m-d H:i:s');
        return parent::prepareInputForUpdate($input);
    }

    /** Valida o nome e o tipo; quando algo está errado, avisa e devolve false. */
    private function clean(array $input)
    {
        if (isset($input['name'])) {
            $input['name'] = mb_substr(trim((string) $input['name']), 0, 255);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(__('Informe o texto da pergunta.'), false, ERROR);
                return false;
            }
        }
        if (isset($input['type']) && !isset(self::TYPES[$input['type']])) {
            Session::addMessageAfterRedirect(__('Tipo de pergunta inválido.'), false, ERROR);
            return false;
        }
        foreach (['is_required', 'is_active'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = empty($input[$flag]) ? 0 : 1;
            }
        }
        return $input;
    }

    private function nextPosition(): int
    {
        $max = 0;
        foreach (self::all() as $row) {
            $max = max($max, (int) $row['position']);
        }
        return $max + 10;
    }
}
