<?php

namespace GlpiPlugin\Reembolso;

use GuzzleHttp\Client;
use RuntimeException;
use Throwable;

/**
 * Distância de carro entre dois endereços pelo OpenStreetMap:
 * Nominatim localiza os endereços (com cache local) e o OSRM calcula a rota.
 * Serviços públicos e gratuitos, com limite de uso: uso leve, um pedido por vez.
 */
class Route
{
    private const NOMINATIM = 'https://nominatim.openstreetmap.org/search';
    private const OSRM = 'https://router.project-osrm.org/route/v1/driving/';

    /** @return array{km: float, origin: string, destination: string} */
    public static function distance(string $origin, string $destination): array
    {
        if (!Money::config()['route_enabled']) {
            throw new RuntimeException('O cálculo automático de rota está desligado na configuração do plugin. Informe o KM pelo odômetro.');
        }
        $origin = trim($origin);
        $destination = trim($destination);
        if ($origin === '' || $destination === '') {
            throw new RuntimeException('Informe a origem e o destino.');
        }
        $a = self::geocode($origin);
        $b = self::geocode($destination);
        try {
            $res = self::client()->get(self::OSRM . "{$a['lon']},{$a['lat']};{$b['lon']},{$b['lat']}", ['query' => ['overview' => 'false']]);
            $json = json_decode((string) $res->getBody(), true);
        } catch (Throwable $e) {
            throw new RuntimeException('Não consegui calcular a rota agora (serviço de rotas indisponível). Informe o KM pelo odômetro.');
        }
        if (($json['code'] ?? '') !== 'Ok' || empty($json['routes'][0])) {
            throw new RuntimeException('Não há rota de carro entre esses endereços.');
        }
        return [
            'km'          => round($json['routes'][0]['distance'] / 1000, 1),
            'origin'      => $a['label'],
            'destination' => $b['label'],
        ];
    }

    /** @return array{lat: float, lon: float, label: string} */
    private static function geocode(string $query): array
    {
        global $DB;
        $key = mb_strtolower(mb_substr($query, 0, 255));
        $cached = $DB->request(['FROM' => 'glpi_plugin_reembolso_geocaches', 'WHERE' => ['query' => $key]])->current();
        if ($cached) {
            return ['lat' => (float) $cached['lat'], 'lon' => (float) $cached['lon'], 'label' => (string) $cached['label']];
        }
        $params = ['q' => $query, 'format' => 'json', 'limit' => 1, 'addressdetails' => 0];
        $country = Money::config()['route_country'];
        if ($country !== '') {
            $params['countrycodes'] = $country;
        }
        try {
            $res = self::client()->get(self::NOMINATIM, ['query' => $params]);
            $json = json_decode((string) $res->getBody(), true);
        } catch (Throwable $e) {
            throw new RuntimeException('Não consegui localizar os endereços agora (serviço de mapas indisponível). Informe o KM pelo odômetro.');
        }
        if (empty($json[0]['lat'])) {
            throw new RuntimeException("Endereço não encontrado no mapa: \"$query\". Tente com cidade e estado (ex.: \"Rua X, 100, Curitiba PR\").");
        }
        $row = [
            'query'         => $key,
            'lat'           => (float) $json[0]['lat'],
            'lon'           => (float) $json[0]['lon'],
            'label'         => mb_substr((string) ($json[0]['display_name'] ?? $query), 0, 255),
            'date_creation' => date('Y-m-d H:i:s'),
        ];
        $DB->insert('glpi_plugin_reembolso_geocaches', $row);
        return ['lat' => $row['lat'], 'lon' => $row['lon'], 'label' => $row['label']];
    }

    private static function client(): Client
    {
        global $CFG_GLPI;
        $opts = [
            'timeout'         => 12,
            'connect_timeout' => 6,
            // o Nominatim exige identificação de quem consulta
            'headers'         => ['User-Agent' => 'GLPI-plugin-reembolso/' . PLUGIN_REEMBOLSO_VERSION . ' (+' . ($CFG_GLPI['url_base'] ?? 'glpi') . ')', 'Accept-Language' => 'pt-BR'],
        ];
        if (!empty($CFG_GLPI['proxy_name'])) {
            $opts['proxy'] = 'http://' . $CFG_GLPI['proxy_name'] . ':' . (int) $CFG_GLPI['proxy_port'];
        }
        return new Client($opts);
    }
}
