<?php

namespace GlpiPlugin\Avisos;

use CommonDBTM;
use Session;
use User;

/**
 * Um aviso para um usuário: aparece no sino e como pop-up, e vai para o canal
 * do Teams do plugin que o gerou.
 *
 * Os plugins chamam Aviso::send([...]) com:
 *   source   'rh' | 'pmo' | 'reembolso'
 *   event    nome curto do evento (ex.: 'vaga_aprovada')
 *   users    ids dos destinatários (quem fez a ação fica de fora, a menos que include_self)
 *   title    título curto; message: uma ou duas frases
 *   url      endereço no GLPI (como getFormURLWithID devolve)
 *   level    info | success | warning | danger
 *   facts    [rótulo => valor] mostrados no cartão do Teams
 *   item     [itemtype, id] do objeto (opcional)
 *   teams    false para não mandar ao Teams
 */
class Aviso extends CommonDBTM
{
    public static string $rightname = 'config';

    public const LEVELS = ['info', 'success', 'warning', 'danger'];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Avisos' : 'Aviso';
    }

    public static function getIcon()
    {
        return 'ti ti-bell';
    }

    // cada um vê só os próprios avisos (pela página e pela API do sino)
    public static function canView(): bool
    {
        return Session::getLoginUserID() !== false;
    }

    public static function send(array $a): int
    {
        global $DB;
        $source = (string) ($a['source'] ?? '');
        $title = mb_substr(trim((string) ($a['title'] ?? '')), 0, 255);
        if ($source === '' || $title === '') {
            return 0;
        }
        $level = in_array($a['level'] ?? '', self::LEVELS, true) ? $a['level'] : 'info';
        $message = trim((string) ($a['message'] ?? ''));
        $url = self::safeUrl((string) ($a['url'] ?? ''));
        [$itemtype, $itemId] = $a['item'] ?? [null, 0];

        $me = (int) Session::getLoginUserID();
        $users = [];
        foreach ((array) ($a['users'] ?? []) as $uid) {
            $uid = (int) $uid;
            if ($uid > 0 && ($uid !== $me || !empty($a['include_self']))) {
                $users[$uid] = true;
            }
        }
        $names = [];
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach (array_keys($users) as $uid) {
            $u = new User();
            if (!$u->getFromDB($uid) || !$u->fields['is_active'] || $u->fields['is_deleted']) {
                continue;
            }
            $DB->insert(self::getTable(), [
                'users_id' => $uid, 'source' => $source, 'event' => mb_substr((string) ($a['event'] ?? ''), 0, 60), 'level' => $level,
                'title' => $title, 'message' => $message, 'url' => $url,
                'itemtype' => $itemtype, 'items_id' => (int) $itemId, 'date_creation' => $now,
            ]);
            $names[] = $u->getFriendlyName();
        }

        if (($a['teams'] ?? true) && ($names || !empty($a['teams_without_users']))) {
            Teams::queue($source, Teams::card($source, $title, $message, (array) ($a['facts'] ?? []), $url, $names, $level));
        }
        return count($names);
    }

    /** Só endereços internos do GLPI (começam com / e não com //). */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? mb_substr($url, 0, 500) : '';
    }

    /** Endereço completo, para links que saem do GLPI (Teams). */
    public static function absolute(string $url): string
    {
        global $CFG_GLPI;
        if ($url === '') {
            return rtrim((string) $CFG_GLPI['url_base'], '/');
        }
        $root = (string) $CFG_GLPI['root_doc'];
        return rtrim((string) $CFG_GLPI['url_base'], '/') . ($root !== '' && str_starts_with($url, $root) ? substr($url, strlen($root)) : $url);
    }

    // ------------------------------------------------------------------ leitura (sino, pop-up e página)

    public static function unreadCount(int $uid): int
    {
        return countElementsInTable(self::getTable(), ['users_id' => $uid, 'is_read' => 0]);
    }

    public static function latest(int $uid, int $limit = 8, bool $unreadOnly = false): array
    {
        global $DB;
        $where = ['users_id' => $uid];
        if ($unreadOnly) {
            $where['is_read'] = 0;
        }
        return array_map([self::class, 'toJson'], iterator_to_array($DB->request([
            'FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => $limit,
        ]), false));
    }

    /** Avisos novos que ainda não viraram pop-up; já ficam marcados como mostrados. */
    public static function takeNew(int $uid): array
    {
        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM' => self::getTable(), 'WHERE' => ['users_id' => $uid, 'is_shown' => 0, 'is_read' => 0], 'ORDER' => 'id ASC', 'LIMIT' => 5,
        ]), false);
        if ($rows) {
            $DB->update(self::getTable(), ['is_shown' => 1], ['users_id' => $uid, 'id' => array_column($rows, 'id')]);
        }
        return array_map([self::class, 'toJson'], $rows);
    }

    public static function markRead(int $uid, ?int $id = null): void
    {
        global $DB;
        $where = ['users_id' => $uid, 'is_read' => 0];
        if ($id !== null) {
            $where['id'] = $id;
        }
        $DB->update(self::getTable(), ['is_read' => 1, 'is_shown' => 1, 'date_read' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')], $where);
    }

    public static function openUrl(int $id): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/avisos/front/abrir.php?id=' . $id;
    }

    public static function toJson(array $r): array
    {
        [$sourceName, $icon, $color] = Canal::meta((string) $r['source']);
        return [
            'id' => (int) $r['id'], 'source' => $r['source'], 'sourceName' => $sourceName, 'icon' => $icon, 'color' => $color,
            'level' => $r['level'], 'title' => (string) $r['title'], 'message' => (string) $r['message'],
            'url' => $r['url'] ? self::openUrl((int) $r['id']) : '', 'read' => (bool) $r['is_read'],
            'date' => (string) $r['date_creation'], 'ago' => self::ago((string) $r['date_creation']),
        ];
    }

    public static function ago(string $date): string
    {
        $s = max(0, time() - strtotime($date));
        return match (true) {
            $s < 60 => 'agora',
            $s < 3600 => 'há ' . intdiv($s, 60) . ' min',
            $s < 86400 => 'há ' . intdiv($s, 3600) . ' h',
            $s < 7 * 86400 => 'há ' . intdiv($s, 86400) . ' dia(s)',
            default => date('d/m/Y', strtotime($date)),
        };
    }
}
