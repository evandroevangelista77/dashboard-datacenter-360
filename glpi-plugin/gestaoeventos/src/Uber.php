<?php

namespace GlpiPlugin\Gestaoeventos;

use Config;
use GLPIKey;
use Throwable;

/**
 * Adaptador da API de estimativa de preço da Uber (Riders API, GET /v1.2/estimates/price) com OAuth 2.0
 * client_credentials, escopo ride_request.estimate. A consulta é feita só pelo servidor: client_id,
 * client_secret e token nunca vão ao navegador, a PDF, a logs ou a mensagens de erro.
 *
 * Modos: off (desligado), mock (adaptador simulado para homologar a tela, nunca apresentado como cotação oficial)
 * e live (exige a autorização formal da Uber marcada na configuração e credenciais). A API só dá a estimativa
 * do instante da consulta: não garante o valor para a data do evento e não pede corrida.
 */
class Uber
{
    private const CTX = 'plugin:gestaoeventos';

    public static function cfg(?array $keys = null): array
    {
        return Config::getConfigurationValues(self::CTX, $keys ?? []);
    }

    private static function set(array $values): void
    {
        Config::setConfigurationValues(self::CTX, $values);
    }

    public static function mode(): string
    {
        $m = (string) (self::cfg(['uber_mode'])['uber_mode'] ?? 'off');
        return in_array($m, ['mock', 'live'], true) ? $m : 'off';
    }

    /** Há como consultar agora (mock ligado, ou live autorizado e com credenciais). */
    public static function enabled(): bool
    {
        $c = self::cfg();
        return match (self::mode()) {
            'mock' => true,
            'live' => ($c['uber_authorized'] ?? '0') === '1' && trim((string) ($c['uber_client_id'] ?? '')) !== '' && trim((string) ($c['uber_client_secret'] ?? '')) !== '',
            default => false,
        };
    }

    public static function label(string $source): string
    {
        return $source === 'mock' ? 'SIMULADO — não é cotação Uber' : 'Cotação Uber (consulta instantânea)';
    }

    // ------------------------------------------------------------------ segredos

    public static function saveSecret(string $name, string $value): void
    {
        self::set([$name => $value === '' ? '' : (new GLPIKey())->encrypt($value)]);
    }

    private static function secret(string $name): string
    {
        $v = (string) (self::cfg([$name])[$name] ?? '');
        if ($v === '') {
            return '';
        }
        try {
            return (string) (new GLPIKey())->decrypt($v);
        } catch (Throwable) {
            return '';
        }
    }

    // ------------------------------------------------------------------ resiliência

    private static function guard(): void
    {
        $c = self::cfg(['uber_open_until', 'uber_rate_per_min', 'uber_calls']);
        $now = time();
        if ((int) ($c['uber_open_until'] ?? 0) > $now) {
            throw new UberException('indisponivel', 'Integração Uber pausada após falhas seguidas (disjuntor aberto). Tente novamente em alguns minutos ou use a estimativa interna.');
        }
        $calls = array_values(array_filter((array) json_decode((string) ($c['uber_calls'] ?? '[]'), true), fn ($t) => $t > $now - 60));
        $limit = max(1, (int) ($c['uber_rate_per_min'] ?? 20));
        if (count($calls) >= $limit) {
            throw new UberException('limite', "Limite local de $limit consultas por minuto atingido. Aguarde um instante.");
        }
        $calls[] = $now;
        self::set(['uber_calls' => json_encode($calls)]);
    }

    private static function failed(string $code, string $text): void
    {
        $c = self::cfg(['uber_fail_count', 'uber_fail_limit']);
        $n = (int) ($c['uber_fail_count'] ?? 0) + 1;
        $values = ['uber_fail_count' => (string) $n, 'uber_last_error' => date('d/m/Y H:i') . ' · ' . $code . ' · ' . mb_substr($text, 0, 160)];
        if (in_array($code, ['indisponivel', 'limite'], true) && $n >= max(1, (int) ($c['uber_fail_limit'] ?? 5))) {
            $values['uber_open_until'] = (string) (time() + 300);
            $values['uber_fail_count'] = '0';
        }
        self::set($values);
    }

    private static function ok(): void
    {
        self::set(['uber_fail_count' => '0', 'uber_last_ok' => date('d/m/Y H:i')]);
    }

    // ------------------------------------------------------------------ HTTP

    /** @return array{0:int,1:string} código HTTP e corpo */
    private static function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        $timeout = max(2, min(30, (int) (self::cfg(['uber_timeout'])['uber_timeout'] ?? 10)));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'GLPI-GestaoEventos/' . PLUGIN_GESTAOEVENTOS_VERSION]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($out === false || $err) {
            throw new UberException('indisponivel', 'Sem resposta da Uber (tempo esgotado ou rede indisponível).');
        }
        return [$code, (string) $out];
    }

    private static function token(): string
    {
        $c = self::cfg(['uber_token', 'uber_token_exp', 'uber_client_id']);
        if (!empty($c['uber_token']) && (int) ($c['uber_token_exp'] ?? 0) > time() + 60) {
            return self::secret('uber_token');
        }
        $secret = self::secret('uber_client_secret');
        $id = trim((string) ($c['uber_client_id'] ?? ''));
        if ($id === '' || $secret === '') {
            throw new UberException('sem_autorizacao', 'Credenciais da Uber não configuradas.');
        }
        [$code, $body] = self::http('POST', 'https://auth.uber.com/oauth/v2/token', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query(['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials', 'scope' => 'ride_request.estimate']));
        $json = json_decode($body, true);
        if ($code === 401 || $code === 403 || $code === 400) {
            throw new UberException('sem_autorizacao', 'A Uber recusou as credenciais ou o escopo ride_request.estimate (sem autorização).');
        }
        if ($code >= 500 || $code === 0 || empty($json['access_token'])) {
            throw new UberException('indisponivel', 'Não foi possível obter o token da Uber.');
        }
        self::saveSecret('uber_token', (string) $json['access_token']);
        self::set(['uber_token_exp' => (string) (time() + max(60, (int) ($json['expires_in'] ?? 3600)))]);
        return (string) $json['access_token'];
    }

    // ------------------------------------------------------------------ consulta

    /**
     * Estima o preço entre dois pontos. Devolve ['source' => api|mock, 'quoted_at', 'products' => [[product_id, name, low, high, currency, distance_km, duration_min, surge]]].
     * Valores nulos continuam nulos (nunca viram zero). A distância vem em milhas e só é convertida para exibição.
     */
    public static function estimate(float $lat1, float $lon1, float $lat2, float $lon2): array
    {
        if (!self::enabled()) {
            throw new UberException('desativada', 'A cotação Uber não está ativada ou autorizada. Use a estimativa interna.');
        }
        if (abs($lat1) > 90 || abs($lat2) > 90 || abs($lon1) > 180 || abs($lon2) > 180 || ($lat1 == 0.0 && $lon1 == 0.0) || ($lat2 == 0.0 && $lon2 == 0.0)) {
            throw new UberException('entrada_invalida', 'Coordenadas inválidas: busque o CEP antes de cotar.');
        }
        self::guard();
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        if (self::mode() === 'mock') {
            $km = self::km($lat1, $lon1, $lat2, $lon2) * 1.3;
            $mk = fn (string $id, string $name, float $mult) => ['product_id' => $id, 'name' => $name, 'low' => round((6 + 2.2 * $km) * $mult * 0.9, 2), 'high' => round((6 + 2.2 * $km) * $mult * 1.15, 2),
                'currency' => 'BRL', 'distance_km' => round($km, 2), 'duration_min' => (int) round($km * 2.4), 'surge' => null];
            return ['source' => 'mock', 'quoted_at' => $now, 'products' => [$mk('mock-x', 'UberX (simulado)', 1.0), $mk('mock-comfort', 'Comfort (simulado)', 1.35)]];
        }
        $token = self::token();
        $env = (string) (self::cfg(['uber_env'])['uber_env'] ?? 'production');
        $host = $env === 'sandbox' ? 'https://sandbox-api.uber.com' : 'https://api.uber.com';
        $q = http_build_query(['start_latitude' => $lat1, 'start_longitude' => $lon1, 'end_latitude' => $lat2, 'end_longitude' => $lon2]);
        [$code, $body] = self::http('GET', "$host/v1.2/estimates/price?$q", ["Authorization: Bearer $token", 'Accept-Language: pt_BR', 'Content-Type: application/json']);
        if ($code === 401 || $code === 403) {
            self::set(['uber_token' => '', 'uber_token_exp' => '0']);
            self::failed('sem_autorizacao', "HTTP $code");
            throw new UberException('sem_autorizacao', 'Sem autorização da Uber para o endpoint de preços (HTTP ' . $code . ').');
        }
        if ($code === 422 || $code === 400) {
            self::failed('entrada_invalida', "HTTP $code");
            throw new UberException('entrada_invalida', 'A Uber rejeitou a entrada (distância ou coordenadas inválidas).');
        }
        if ($code === 429) {
            self::failed('limite', 'HTTP 429');
            throw new UberException('limite', 'Limite de consultas da Uber atingido. Tente mais tarde.');
        }
        if ($code < 200 || $code >= 300) {
            self::failed('indisponivel', "HTTP $code");
            throw new UberException('indisponivel', 'A Uber está indisponível no momento (HTTP ' . $code . ').');
        }
        $json = json_decode($body, true);
        $products = [];
        foreach ((array) ($json['prices'] ?? []) as $p) {
            $products[] = [
                'product_id' => (string) ($p['product_id'] ?? ''), 'name' => (string) ($p['localized_display_name'] ?? $p['display_name'] ?? ''),
                'low' => isset($p['low_estimate']) && is_numeric($p['low_estimate']) ? (float) $p['low_estimate'] : null, 'high' => isset($p['high_estimate']) && is_numeric($p['high_estimate']) ? (float) $p['high_estimate'] : null,
                'currency' => (string) ($p['currency_code'] ?? ''), 'distance_km' => isset($p['distance']) && is_numeric($p['distance']) ? round((float) $p['distance'] * 1.609344, 2) : null,
                'duration_min' => isset($p['duration']) && is_numeric($p['duration']) ? (int) round($p['duration'] / 60) : null, 'surge' => isset($p['surge_multiplier']) && is_numeric($p['surge_multiplier']) ? (float) $p['surge_multiplier'] : null,
            ];
        }
        self::ok();
        return ['source' => 'api', 'quoted_at' => $now, 'products' => $products];
    }

    /** Teste da tela de configuração: autentica e informa conectado / sem autorização / erro, sem expor segredos. */
    public static function test(): array
    {
        if (self::mode() === 'off') {
            return ['off', 'A integração está desligada.'];
        }
        if (self::mode() === 'mock') {
            return ['mock', 'Modo simulado: nenhuma chamada real é feita.'];
        }
        if (!self::enabled()) {
            return ['sem_autorizacao', 'Falta marcar a autorização formal da Uber e preencher as credenciais.'];
        }
        try {
            self::set(['uber_token' => '', 'uber_token_exp' => '0']);
            self::token();
            self::ok();
            return ['conectado', 'Autenticação concluída: token obtido (escopo ride_request.estimate).'];
        } catch (UberException $e) {
            self::failed($e->errCode, $e->getMessage());
            return [$e->errCode, $e->getMessage()];
        }
    }

    public static function km(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0088;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
