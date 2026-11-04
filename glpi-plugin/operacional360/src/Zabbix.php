<?php

namespace GlpiPlugin\Operacional360;

use RuntimeException;
use stdClass;

/**
 * Cliente de leitura da API do Zabbix (JSON-RPC) com o token da conta de serviço.
 * Só métodos ".get" (e a verificação de versão): o painel nunca altera nada no Zabbix.
 * O token fica no servidor do GLPI e nunca vai para o navegador.
 */
class Zabbix
{
    private const READ_ONLY = '/\.get$|^sla\.getsli$|^apiinfo\.version$/';

    public static function guard(string $method): void
    {
        if (!preg_match(self::READ_ONLY, $method)) {
            throw new RuntimeException("Método do Zabbix não permitido (somente leitura): $method");
        }
    }

    /** Sem endereço e token da API, a tela explica o que falta (503), em vez de um erro do Zabbix. */
    private static function requireConfigured(): void
    {
        if (!Settings::isConfigured()) {
            throw new HttpError(503, 'Conexão com o Zabbix não configurada (Configurar → Plugins → Operacional 360).');
        }
    }

    /** Uma chamada. Erro do Zabbix vira RuntimeException com a mensagem dele. */
    public static function call(string $method, array $params = []): mixed
    {
        self::guard($method);
        self::requireConfigured();
        $url = Settings::get('zabbix_url');
        $ch = curl_init($url);
        curl_setopt_array($ch, self::options($method, $params, 25));
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Zabbix inacessível: ' . curl_error($ch));
        }
        $resp = json_decode((string) $raw, true);
        if (isset($resp['error'])) {
            throw new RuntimeException("Zabbix $method: " . ($resp['error']['data'] ?? $resp['error']['message'] ?? 'erro'));
        }
        return $resp['result'] ?? null;
    }

    /**
     * Várias chamadas ao mesmo tempo (a latência até o servidor é paga uma vez).
     * @param array<string, array{0:string,1:array}> $calls chave => [método, parâmetros]
     * @return array<string, mixed> chave => resultado; null quando aquela chamada falhou
     */
    public static function batch(array $calls): array
    {
        $url = Settings::get('zabbix_url');
        self::requireConfigured();
        $url = Settings::get('zabbix_url');
        $mh = curl_multi_init();
        $handles = [];
        foreach ($calls as $key => [$method, $params]) {
            self::guard($method);
            $ch = curl_init($url);
            curl_setopt_array($ch, self::options($method, $params, 40));
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            $resp = json_decode((string) curl_multi_getcontent($ch), true);
            $out[$key] = (is_array($resp) && !isset($resp['error'])) ? ($resp['result'] ?? null) : null;
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Como batch(), mas falha se qualquer chamada falhar (os painéis não mostram números pela metade). */
    public static function batchStrict(array $calls): array
    {
        $out = self::batch($calls);
        foreach ($out as $key => $value) {
            if ($value === null) {
                throw new RuntimeException("Zabbix: a consulta '$key' falhou");
            }
        }
        return $out;
    }

    /** Cabeçalhos da chamada: a versão da API não recebe o token (alguns Zabbix recusam o cabeçalho nela). */
    private static function headers(string $method): array
    {
        $headers = ['Content-Type: application/json-rpc'];
        if (!in_array($method, ['apiinfo.version', 'user.checkAuthentication'], true)) {
            $headers[] = 'Authorization: Bearer ' . Settings::get('zabbix_token');
        }
        return $headers;
    }

    private static function options(string $method, array $params, int $timeout): array
    {
        $verify = Settings::get('zabbix_verify') !== '0';
        return [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER     => self::headers($method),
            CURLOPT_POSTFIELDS     => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params ?: new stdClass(), 'id' => 1]),
        ];
    }
}
