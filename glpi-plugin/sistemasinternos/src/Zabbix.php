<?php

namespace GlpiPlugin\Sistemasinternos;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * Cliente da API do Zabbix (JSON-RPC) com a conta de serviço do plugin.
 * O token nunca sai do servidor do GLPI.
 */
class Zabbix
{
    /** Chama um método da API; erro do Zabbix vira RuntimeException com a mensagem dele. */
    public static function call(string $method, array $params = [], ?string $url = null, ?string $token = null, int $timeout = 25): mixed
    {
        $url ??= Settings::get('zabbix_url');
        $token ??= Settings::get('zabbix_token');
        if ($url === '') {
            throw new RuntimeException('Endereço da API do Zabbix não configurado.');
        }
        $headers = ['Content-Type: application/json-rpc'];
        if (!in_array($method, ['apiinfo.version', 'user.checkAuthentication'], true)) { // esses dois recusam o cabeçalho de autenticação
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init($url);
        $verify = Settings::get('zabbix_verify') !== '0';
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params ?: new stdClass(), 'id' => 1]),
        ]);
        self::proxy($ch);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            throw new RuntimeException('Zabbix inacessível: ' . curl_error($ch));
        }
        $resp = json_decode((string) $raw, true);
        if (!is_array($resp)) {
            throw new RuntimeException("Resposta inválida do Zabbix (HTTP $code). Confira o endereço da API: é o endereço da tela de login do Zabbix seguido de /api_jsonrpc.php.");
        }
        if (isset($resp['error'])) {
            throw new RuntimeException(self::friendly($method, (string) ($resp['error']['data'] ?? $resp['error']['message'] ?? 'erro desconhecido')));
        }
        return $resp['result'] ?? null;
    }

    /** Respeita o proxy configurado no GLPI (Configurar → Geral → Proxy). */
    private static function proxy($ch): void
    {
        global $CFG_GLPI;
        if (!empty($CFG_GLPI['proxy_name'])) {
            curl_setopt($ch, CURLOPT_PROXY, $CFG_GLPI['proxy_name'] . ':' . (int) $CFG_GLPI['proxy_port']);
            if (!empty($CFG_GLPI['proxy_user'])) {
                $pass = (string) (new \GLPIKey())->decrypt((string) $CFG_GLPI['proxy_passwd']);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $CFG_GLPI['proxy_user'] . ':' . $pass);
            }
        }
    }

    /** Mensagens do Zabbix em português, para quem está cadastrando. */
    private static function friendly(string $method, string $msg): string
    {
        $map = [
            '/No permissions to referred object or it does not exist/i' => 'A conta de serviço do Zabbix não tem permissão neste objeto (ou ele não existe).',
            '/Host with the same name "(.+)" already exists/i'         => 'Já existe um host com o nome técnico "$1" no Zabbix.',
            '/Host with the same visible name "(.+)" already exists/i' => 'Já existe um host com o nome visível "$1" no Zabbix.',
            '/Not authori[sz]ed/i'                                      => 'Token da conta de serviço inválido ou expirado.',
            '/Session terminated/i'                                     => 'Token da conta de serviço inválido ou expirado.',
            '/You do not have permission to perform this operation/i'   => 'A conta de serviço do Zabbix não tem permissão para esta operação.',
            '/No permissions to call "(.+)"/i'                          => 'A conta de serviço do Zabbix não tem permissão para "$1": o papel dela precisa ser do tipo Admin, com escrita nos grupos do departamento.',
            '/Incorrect value for field "ip"/i'                         => 'Endereço IP inválido.',
        ];
        foreach ($map as $re => $pt) {
            if (preg_match($re, $msg)) {
                return preg_replace($re, $pt, $msg);
            }
        }
        return "Zabbix ($method): $msg";
    }

    /** Versão da API (cacheada), para os parâmetros que mudaram entre o 6.0 e o 7.x. */
    public static function version(): string
    {
        return (string) self::cached('version', 3600, fn () => self::call('apiinfo.version'));
    }

    public static function atLeast(string $v): bool
    {
        try {
            return version_compare(self::version(), $v, '>=');
        } catch (Throwable) {
            return true;
        }
    }

    /** selectHostGroups no 6.2+, selectGroups antes. */
    public static function groupsParam(): string
    {
        return self::atLeast('6.2') ? 'selectHostGroups' : 'selectGroups';
    }

    /** Grupos do host, qualquer que seja o nome do campo na versão. */
    public static function hostGroups(array $host): array
    {
        return $host['hostgroups'] ?? $host['groups'] ?? [];
    }

    /** Endereço da interface web (para os atalhos "Abrir no Zabbix"). */
    public static function webUrl(string $path = ''): string
    {
        $api = Settings::get('zabbix_url');
        return $api === '' ? '' : preg_replace('#/api_jsonrpc\.php$#', '/', $api) . ltrim($path, '/');
    }

    /** Cache curto no cache do GLPI (vale para todos os usuários). */
    public static function cached(string $key, int $ttl, callable $fn): mixed
    {
        global $GLPI_CACHE;
        $k = 'plugin_sistemasinternos_' . md5($key . '|' . Settings::get('zabbix_url'));
        try {
            if ($GLPI_CACHE && ($hit = $GLPI_CACHE->get($k)) !== null) {
                return $hit;
            }
        } catch (Throwable) {
            // sem cache: consulta direto
        }
        $value = $fn();
        try {
            $GLPI_CACHE?->set($k, $value, $ttl);
        } catch (Throwable) {
        }
        return $value;
    }

    public static function forget(string $key): void
    {
        global $GLPI_CACHE;
        try {
            $GLPI_CACHE?->delete('plugin_sistemasinternos_' . md5($key . '|' . Settings::get('zabbix_url')));
        } catch (Throwable) {
        }
    }

    /** Todos os hostgroups que a conta de serviço enxerga: id → nome. */
    public static function allGroups(): array
    {
        return self::cached('groups', 300, function () {
            $out = [];
            foreach (self::call('hostgroup.get', ['output' => ['groupid', 'name'], 'sortfield' => 'name']) as $g) {
                $out[(string) $g['groupid']] = (string) $g['name'];
            }
            return $out;
        });
    }

    /** Todos os templates que a conta de serviço enxerga: id → nome. */
    public static function allTemplates(): array
    {
        return self::cached('templates', 300, function () {
            $out = [];
            foreach (self::call('template.get', ['output' => ['templateid', 'name'], 'sortfield' => 'name']) as $t) {
                $out[(string) $t['templateid']] = (string) $t['name'];
            }
            return $out;
        });
    }

    /** Proxies (só aparece quando a conta de serviço pode listá-los). */
    public static function proxies(): array
    {
        try {
            return self::cached('proxies', 300, function () {
                $out = [];
                $name = self::atLeast('7.0') ? 'name' : 'host';
                foreach (self::call('proxy.get', ['output' => ['proxyid', $name]]) as $p) {
                    $out[(string) $p['proxyid']] = (string) $p[$name];
                }
                asort($out);
                return $out;
            });
        } catch (Throwable) {
            return [];
        }
    }

    public const SEVERITIES = [
        0 => ['Não classificada', '#97aab3'],
        1 => ['Informação', '#7499ff'],
        2 => ['Atenção', '#ffc859'],
        3 => ['Média', '#ffa059'],
        4 => ['Alta', '#e97659'],
        5 => ['Desastre', '#e45959'],
    ];

    public const INTERFACES = [
        1 => ['Agente Zabbix', 10050],
        2 => ['SNMP', 161],
        3 => ['IPMI', 623],
        4 => ['JMX', 12345],
    ];
}
