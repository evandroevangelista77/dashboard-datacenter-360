<?php

namespace GlpiPlugin\Datacenter360;

use RuntimeException;
use Throwable;

/**
 * Configuração do NOC 360 pela tela do GLPI (Configurar → Plugins → NOC 360).
 *
 * O painel (api.php, fora do GLPI) lê dois arquivos: /etc/datacenter360/config.php (base, criado pelo instalador)
 * e /var/lib/datacenter360/config.json (o que esta tela grava, só o servidor web escreve). O que está no JSON
 * vale mais que o config.php. O token do Zabbix fica nesse arquivo, fora da pasta web, e nunca vai para o navegador.
 */
class Settings
{
    public const BASE = '/etc/datacenter360/config.php';
    public const FILE = '/var/lib/datacenter360/config.json';

    public const SEVERITY_LABELS = [5 => 'Desastre', 4 => 'Alta', 3 => 'Média', 2 => 'Atenção', 1 => 'Informação', 0 => 'Não classificada'];

    public const CHANNELS = ['flag' => 'Sinalizador', 'sound' => 'Som', 'popup' => 'Pop-up', 'voice' => 'Voz', 'sticky' => 'Fica até fechar'];

    /** Tipo => [rótulo, grupo, ícone, cor, canais padrão, som]. Igual ao do painel (api.php, ALERTAS_TIPOS). */
    public const ALERT_TYPES = [
        'zbx_5'        => ['Desastre', 'Zabbix', 'ti-flame', '#e45959', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'sirene'],
        'zbx_4'        => ['Alta', 'Zabbix', 'ti-alert-octagon', '#e97659', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'duplo'],
        'zbx_3'        => ['Média', 'Zabbix', 'ti-alert-triangle', '#ffa059', ['flag', 'popup', 'voice'], 'suave'],
        'zbx_2'        => ['Atenção', 'Zabbix', 'ti-alert-circle', '#ffc859', ['flag', 'popup', 'voice'], 'suave'],
        'zbx_1'        => ['Informação', 'Zabbix', 'ti-info-circle', '#7499ff', [], 'suave'],
        'zbx_0'        => ['Não classificada', 'Zabbix', 'ti-help-circle', '#97aab3', [], 'suave'],
        'zbx_ok'       => ['Problema resolvido', 'Zabbix', 'ti-circle-check', '#22c55e', ['popup', 'voice'], 'sino'],
        'glpi_new'     => ['Chamado novo', 'GLPI', 'ti-ticket', '#3769B2', ['flag', 'sound', 'popup', 'voice'], 'sino'],
        'glpi_sla1h'   => ['SLA vence em até 1 h', 'GLPI', 'ti-hourglass-high', '#f59e0b', ['flag', 'sound', 'popup', 'voice'], 'duplo'],
        'glpi_slalate' => ['SLA vencido', 'GLPI', 'ti-hourglass-empty', '#ef4444', ['flag', 'sound', 'popup', 'voice', 'sticky'], 'sirene'],
    ];

    private static ?array $base = null;
    private static ?array $saved = null;

    // ------------------------------------------------------------------ leitura

    /** config.php do instalador (credenciais e ajustes de base). */
    public static function base(): array
    {
        if (self::$base === null) {
            self::$base = [];
            try {
                if (is_readable(self::BASE)) {
                    $v = (static fn () => require self::BASE)();
                    self::$base = is_array($v) ? $v : [];
                }
            } catch (Throwable) {
            }
        }
        return self::$base;
    }

    /** O que esta tela gravou. */
    public static function saved(): array
    {
        if (self::$saved === null) {
            $j = is_readable(self::FILE) ? json_decode((string) file_get_contents(self::FILE), true) : null;
            self::$saved = is_array($j) ? $j : [];
        }
        return self::$saved;
    }

    /** Valor em vigor: o salvo pela tela (se houver) ou o do config.php. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $s = self::saved();
        if (array_key_exists($key, $s) && $s[$key] !== null && $s[$key] !== '') {
            return $s[$key];
        }
        return self::base()[$key] ?? $default;
    }

    public static function isConfigured(): bool
    {
        return (string) self::get('zabbix_url', '') !== '' && (string) self::get('zabbix_token', '') !== '';
    }

    public static function hasToken(): bool
    {
        return (string) self::get('zabbix_token', '') !== '';
    }

    /** @return int[] severidades mostradas nos cartões (números do Zabbix). */
    public static function severities(): array
    {
        $v = self::get('severities', [5, 4, 2]);
        $v = is_array($v) ? array_values(array_unique(array_map('intval', $v))) : [5, 4, 2];
        return $v ?: [5, 4, 2];
    }

    /** Metas de solução por prioridade como texto: 6:1,5:2,... */
    public static function slaHoursText(): string
    {
        $m = self::get('sla_hours', [6 => 1, 5 => 2, 4 => 4, 3 => 8, 2 => 24, 1 => 48]);
        if (!is_array($m)) {
            return '';
        }
        $out = [];
        foreach ($m as $p => $h) {
            $out[] = ((int) $p) . ':' . ((int) $h);
        }
        return implode(',', $out);
    }

    /** "6:1,5:2" => [6 => 1, 5 => 2] (vazio se o texto não tiver nenhum par válido). */
    public static function parseSlaHours(string $text): array
    {
        $out = [];
        foreach (explode(',', $text) as $pair) {
            [$p, $h] = array_pad(explode(':', trim($pair), 2), 2, '');
            if (ctype_digit($p) && ctype_digit($h) && (int) $p >= 1 && (int) $p <= 6) {
                $out[(int) $p] = (int) $h;
            }
        }
        return $out;
    }

    /** Entidade raiz: só ela e as abaixo aparecem no mapa e nos chamados (ex.: OAKMONT > TI > NOC). */
    public static function entityRoot(): string
    {
        return (string) self::get('glpi_entity_prefix', '');
    }

    public static function inEntityRoot(string $name): bool
    {
        $root = self::entityRoot();
        return $root === '' || $name === $root || str_starts_with($name, $root . ' > ');
    }

    /** Linhas do mapa entidade → host group → severidades. */
    public static function mapping(): array
    {
        $out = [];
        foreach ((array) self::get('mapa_entidades', []) as $r) {
            if (!is_array($r) || (int) ($r['entity_id'] ?? 0) <= 0) {
                continue;
            }
            $out[] = [
                'entity_id'   => (int) $r['entity_id'],
                'entity_name' => (string) ($r['entity_name'] ?? ''),
                'group_id'    => (string) ($r['group_id'] ?? ''),
                'group_name'  => (string) ($r['group_name'] ?? ''),
                'severities'  => array_values(array_map('intval', (array) ($r['severities'] ?? []))),
            ];
        }
        return $out;
    }

    /** IDs dos usuários que veem o painel; vazio = todo perfil com o direito de ver. */
    public static function viewerIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) (self::saved()['viewers'] ?? [])))));
    }

    /** Alertas do navegador com os padrões preenchidos. */
    public static function alerts(): array
    {
        $a = (array) self::get('alertas', []);
        $tipos = [];
        foreach (self::ALERT_TYPES as $key => [, , , , $canais, $tom]) {
            $o = $a['tipos'][$key] ?? [];
            $tipos[$key] = [
                'channels' => is_array($o['channels'] ?? null) ? array_values(array_intersect($o['channels'], array_keys(self::CHANNELS))) : $canais,
                'tone'     => is_string($o['tone'] ?? null) ? $o['tone'] : $tom,
            ];
        }
        return [
            'tipos'            => $tipos,
            'poll'             => max(10, (int) ($a['poll'] ?? 20)),
            'volume'           => max(0, min(100, (int) ($a['volume'] ?? 80))),
            'voice_rate'       => (float) ($a['voice_rate'] ?? 1.05),
            'popup_seconds'    => (int) ($a['popup_seconds'] ?? 20),
            'popup_max'        => (int) ($a['popup_max'] ?? 5),
            'mute_minutes'     => (int) ($a['mute_minutes'] ?? 60),
            'sla_warn_minutes' => (int) ($a['sla_warn_minutes'] ?? 60),
            'quiet'            => $a['quiet'] ?? null,
        ];
    }

    // ------------------------------------------------------------------ gravação

    /** Mescla $values no arquivo da tela (token vazio mantém o salvo). Lança RuntimeException se não der para gravar. */
    public static function save(array $values): void
    {
        $dir = dirname(self::FILE);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException("A pasta $dir não existe ou não é gravável pelo servidor web. Rode o instalador do painel (instalar.sh) ou crie a pasta com dono do servidor web.");
        }
        $cur = self::saved();
        if (($values['zabbix_token'] ?? '') === '') {
            unset($values['zabbix_token']);
        }
        $new = array_replace($cur, $values);
        $tmp = self::FILE . '.tmp';
        if (@file_put_contents($tmp, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException('Não foi possível gravar ' . self::FILE);
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, self::FILE)) {
            throw new RuntimeException('Não foi possível substituir ' . self::FILE);
        }
        self::$saved = $new;
    }

    // ------------------------------------------------------------------ Zabbix (só leitura, para esta tela)

    /** Chamada de leitura ao Zabbix com a configuração em vigor. */
    public static function zabbix(string $method, array $params = []): mixed
    {
        if (!preg_match('/\.get$|^apiinfo\.version$/', $method)) {
            throw new RuntimeException("Método não permitido (somente leitura): $method");
        }
        $url = (string) self::get('zabbix_url', '');
        $token = (string) self::get('zabbix_token', '');
        if ($url === '' || $token === '') {
            throw new RuntimeException('Informe o endereço da API e o token do Zabbix.');
        }
        $verify = self::get('zabbix_verify', true) !== false && self::get('zabbix_verify', true) !== '0';
        $headers = ['Content-Type: application/json-rpc'];
        if ($method !== 'apiinfo.version') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params ?: new \stdClass(), 'id' => 1])]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Zabbix inacessível: ' . curl_error($ch));
        }
        $r = json_decode((string) $raw, true);
        if (isset($r['error'])) {
            throw new RuntimeException('Zabbix: ' . ($r['error']['data'] ?? $r['error']['message'] ?? 'erro'));
        }
        return $r['result'] ?? null;
    }

    // ------------------------------------------------------------------ quem vê o painel

    /** O usuário atual pode abrir o NOC 360? (direito do perfil e, se houver lista, estar nela; quem configura sempre pode). */
    public static function canView(): bool
    {
        if (!\Session::haveRight(Menu::$rightname, READ)) {
            return false;
        }
        $v = self::viewerIds();
        return !$v || in_array((int) \Session::getLoginUserID(), $v, true) || \Session::haveRight('config', UPDATE);
    }
}
