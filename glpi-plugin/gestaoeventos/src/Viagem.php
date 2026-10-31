<?php

namespace GlpiPlugin\Gestaoeventos;

use Html;
use RuntimeException;
use Session;

/**
 * Simulador de custos de viagem por participante: Uber, veículo próprio ou Uber + aéreo, com CEP,
 * distância estimada (Haversine × fator de rota), pedágio, estadia e parâmetros versionados. É uma
 * estimativa preliminar, nunca preço contratual. Com a integração ativada, cada trecho pode usar a
 * cotação da API Uber (identificada como tal); a estimativa interna continua disponível e rotulada.
 */
class Viagem
{
    /** código => [nome, latitude, longitude] */
    public const AIRPORTS = [
        'GRU' => ['São Paulo/Guarulhos', -23.4356, -46.4731], 'CGH' => ['São Paulo/Congonhas', -23.6261, -46.6564], 'VCP' => ['Campinas/Viracopos', -23.0074, -47.1345],
        'GIG' => ['Rio de Janeiro/Galeão', -22.8099, -43.2505], 'SDU' => ['Rio de Janeiro/Santos Dumont', -22.9105, -43.1631], 'BSB' => ['Brasília', -15.8711, -47.9186],
        'CNF' => ['Belo Horizonte/Confins', -19.6244, -43.9719], 'PLU' => ['Belo Horizonte/Pampulha', -19.8512, -43.9506], 'SSA' => ['Salvador', -12.9086, -38.3225],
        'REC' => ['Recife', -8.1265, -34.9236], 'FOR' => ['Fortaleza', -3.7763, -38.5326], 'POA' => ['Porto Alegre', -29.9944, -51.1714], 'CWB' => ['Curitiba', -25.5285, -49.1758],
        'FLN' => ['Florianópolis', -27.6703, -48.5525], 'BEL' => ['Belém', -1.3793, -48.4763], 'MAO' => ['Manaus', -3.0386, -60.0497], 'GYN' => ['Goiânia', -16.6320, -49.2207],
        'VIX' => ['Vitória', -20.2581, -40.2864], 'NAT' => ['Natal', -5.9111, -35.2477], 'MCZ' => ['Maceió', -9.5108, -35.7917], 'AJU' => ['Aracaju', -10.9840, -37.0703],
        'SLZ' => ['São Luís', -2.5854, -44.2340], 'CGB' => ['Cuiabá', -15.6529, -56.1167], 'CGR' => ['Campo Grande', -20.4687, -54.6725], 'JPA' => ['João Pessoa', -7.1458, -34.9486],
        'IGU' => ['Foz do Iguaçu', -25.5963, -54.4873], 'BPS' => ['Porto Seguro', -16.4386, -39.0809],
    ];

    // ------------------------------------------------------------------ geografia

    private static function get(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'GLPI-GestaoEventos/' . PLUGIN_GESTAOEVENTOS_VERSION . ' (simulador de viagens)', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = $out !== false && $code >= 200 && $code < 300 ? json_decode((string) $out, true) : null;
        return is_array($json) ? $json : null;
    }

    /** CEP → [rótulo, latitude, longitude]. Falha com mensagem clara (entrada manual continua possível). */
    public static function geocode(string $cep): array
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            throw new RuntimeException('CEP inválido: informe 8 dígitos.');
        }
        $addr = self::get("https://viacep.com.br/ws/$cep/json/");
        if ($addr === null) {
            throw new RuntimeException("Não consegui consultar o CEP $cep agora (serviço indisponível). Informe o km manualmente.");
        }
        if (!empty($addr['erro'])) {
            throw new RuntimeException("CEP $cep não encontrado. Confira o número ou informe o km manualmente.");
        }
        $label = trim(($addr['logradouro'] ?? '') . ', ' . ($addr['bairro'] ?? '') . ', ' . ($addr['localidade'] ?? '') . ' - ' . ($addr['uf'] ?? ''), ' ,');
        foreach ([trim(($addr['logradouro'] ?? '') . ', ' . ($addr['localidade'] ?? '') . ', ' . ($addr['uf'] ?? '') . ', Brasil', ' ,'), ($addr['localidade'] ?? '') . ', ' . ($addr['uf'] ?? '') . ', Brasil'] as $q) {
            if (trim($q, ', ') === 'Brasil') {
                continue;
            }
            $geo = self::get('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q=' . rawurlencode($q));
            if (!empty($geo[0]['lat'])) {
                return [$label, round((float) $geo[0]['lat'], 6), round((float) $geo[0]['lon'], 6)];
            }
            usleep(1100000); // política de uso do geocodificador: no máximo 1 consulta por segundo
        }
        throw new RuntimeException("Achei o endereço ($label), mas não consegui as coordenadas. Informe o km manualmente.");
    }

    private static function nearestAirport(float $lat, float $lon): string
    {
        $best = ['', PHP_FLOAT_MAX];
        foreach (self::AIRPORTS as $code => [, $alat, $alon]) {
            $d = Uber::km($lat, $lon, $alat, $alon);
            if ($d < $best[1]) {
                $best = [$code, $d];
            }
        }
        return $best[0];
    }

    // ------------------------------------------------------------------ cálculo

    private static function uberLeg(float $km, bool $peak, float $toll, array $p): float
    {
        return round(((float) $p['base_fare'] + $km * (float) $p['per_km']) * ($peak ? max(1.0, (float) $p['peak_mult']) : 1.0) + $toll, 2);
    }

    /** @return array<int,array{0:string,1:string,2:float,3:?float,4:string}> trechos de uma direção */
    public static function calc(array $v): array
    {
        $p = ($v['params_version'] ?? 0) ? (Parametro::version((int) $v['params_version']) ?: Parametro::current()) : Parametro::current();
        $useApi = (int) $v['uber_source'] === 1 && Uber::enabled();
        $policy = (string) $v['uber_policy'];
        $legs = [];
        $api = function (string $leg) use ($v, $policy) {
            $q = Cotacao::selected((int) $v['id'], $leg);
            return $q ? [Cotacao::reference($q, $policy), $q] : [null, null];
        };
        $add = function (string $key, string $label, float $km, float $internal) use (&$legs, $useApi, $api) {
            if ($useApi) {
                [$val, $q] = $api($key);
                $legs[] = [$key, $label, $km, $val, $q ? ($q['source'] === 'mock' ? 'Simulado (mock)' : 'Cotação Uber · ' . $q['product_name']) : 'Sem cotação adotada'];
            } else {
                $legs[] = [$key, $label, $km, $internal, 'Estimativa interna'];
            }
        };
        $km = (float) $v['km_oneway'];
        switch ($v['mode']) {
            case 'proprio':
                $legs[] = ['ida', 'Ida (veículo próprio)', $km, round($km * (float) $p['km_refund'] + (float) $v['tolls_out'], 2), 'Reembolso/km + pedágio'];
                $legs[] = ['volta', 'Volta (veículo próprio)', $km, round($km * (float) $p['km_refund'] + (float) $v['tolls_back'], 2), 'Reembolso/km + pedágio'];
                break;
            case 'uber_aereo':
                $a = (float) $v['km_access_origin'];
                $b = (float) $v['km_access_dest'];
                $add('acesso_ida', 'Casa → aeroporto (ida)', $a, self::uberLeg($a, (bool) $v['peak_out'], 0, $p));
                $legs[] = ['aereo_ida', 'Passagem aérea (ida)', 0.0, round((float) $v['air_fare_out'], 2), 'Tarifa informada'];
                $add('egresso_ida', 'Aeroporto → local (ida)', $b, self::uberLeg($b, (bool) $v['peak_out'], 0, $p));
                $add('acesso_volta', 'Local → aeroporto (volta)', $b, self::uberLeg($b, (bool) $v['peak_back'], 0, $p));
                $legs[] = ['aereo_volta', 'Passagem aérea (volta)', 0.0, round((float) $v['air_fare_back'], 2), 'Tarifa informada'];
                $add('egresso_volta', 'Aeroporto → casa (volta)', $a, self::uberLeg($a, (bool) $v['peak_back'], 0, $p));
                foreach (self::extraLegs((string) $v['air_extra']) as $i => [$desc, $fare]) {
                    $legs[] = ['extra' . $i, 'Trecho adicional: ' . $desc, 0.0, $fare, 'Tarifa informada'];
                }
                break;
            default:
                $add('ida', 'Ida (Uber)', $km, self::uberLeg($km, (bool) $v['peak_out'], (float) $v['tolls_out'], $p));
                $add('volta', 'Volta (Uber)', $km, self::uberLeg($km, (bool) $v['peak_back'], (float) $v['tolls_back'], $p));
        }
        // estadia: sem estadia fica zerada; estacionamento só no veículo próprio
        $hotel = (float) ((float) $v['hotel_rate'] > 0 ? $v['hotel_rate'] : $p['hotel_night']);
        $meal = (float) ((float) $v['meal_rate'] > 0 ? $v['meal_rate'] : $p['meal_day']);
        $park = (float) ((float) $v['parking_rate'] > 0 ? $v['parking_rate'] : $p['parking_day']);
        if ((int) $v['hotel_nights'] > 0) {
            $legs[] = ['hotel', (int) $v['hotel_nights'] . ' diária(s) de hotel', 0.0, round((int) $v['hotel_nights'] * $hotel, 2), 'Parâmetro v' . (int) $p['version']];
        }
        if ((int) $v['meal_days'] > 0) {
            $legs[] = ['meal', (int) $v['meal_days'] . ' diária(s) de alimentação', 0.0, round((int) $v['meal_days'] * $meal, 2), 'Parâmetro v' . (int) $p['version']];
        }
        if ($v['mode'] === 'proprio' && (int) $v['parking_days'] > 0) {
            $legs[] = ['parking', (int) $v['parking_days'] . ' diária(s) de estacionamento', 0.0, round((int) $v['parking_days'] * $park, 2), 'Parâmetro v' . (int) $p['version']];
        }
        return $legs;
    }

    /** "descrição;valor" por linha. */
    public static function extraLegs(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $parts = explode(';', $line, 2);
            if (trim($parts[0]) !== '') {
                $out[] = [mb_substr(trim($parts[0]), 0, 60), Menu::parseMoney($parts[1] ?? '0')];
            }
        }
        return $out;
    }

    /** Recalcula distância e total e guarda o retrato do cálculo (com a versão dos parâmetros usada). */
    public static function refresh(int $id, bool $useCurrentParams = false): void
    {
        global $DB;
        $v = Viajante::row($id);
        if (!$v) {
            return;
        }
        if ($useCurrentParams || !(int) $v['params_version']) {
            $v['params_version'] = (int) Parametro::current()['version'];
        }
        $p = Parametro::version((int) $v['params_version']) ?: Parametro::current();
        if (!(int) $v['km_manual'] && $v['origin_lat'] !== null && $v['dest_lat'] !== null) {
            $v['km_oneway'] = round(Uber::km((float) $v['origin_lat'], (float) $v['origin_lon'], (float) $v['dest_lat'], (float) $v['dest_lon']) * (float) $p['route_factor'], 2);
        }
        $legs = self::calc($v);
        $pending = count(array_filter($legs, fn ($l) => $l[3] === null)) > 0;
        $total = round(array_sum(array_map(fn ($l) => (float) $l[3], $legs)), 2);
        $DB->update(Viajante::getTable(), [
            'params_version' => (int) $v['params_version'], 'km_oneway' => $v['km_oneway'], 'total' => $total, 'total_pending' => $pending ? 1 : 0, 'calc_date' => $_SESSION['glpi_currenttime'],
            'calc' => json_encode(['params' => (int) $v['params_version'], 'route_factor' => (float) $p['route_factor'], 'source' => (int) $v['uber_source'] && Uber::enabled() ? 'uber' : 'interna', 'legs' => $legs, 'total' => $total, 'pending' => $pending], JSON_UNESCAPED_UNICODE),
            'date_mod' => $_SESSION['glpi_currenttime'],
        ], ['id' => $id]);
    }

    // ------------------------------------------------------------------ ações

    public static function action(Evento $ev, string $action, array $post): string
    {
        global $DB;
        if (!Evento::canFinance()) {
            throw new RuntimeException('As viagens são restritas ao perfil Financeiro.');
        }
        Acesso::requireEdit('viagens');
        $now = $_SESSION['glpi_currenttime'];
        $vid = (int) ($post['viajante_id'] ?? 0);
        $v = $vid ? Viajante::row($vid, $ev->getID()) : null;
        if ($action !== 'save' && !$v) {
            throw new RuntimeException('Viajante não encontrado neste evento.');
        }
        switch ($action) {
            case 'save':
                $in = (array) ($post['v'] ?? []);
                $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 255);
                if ($name === '') {
                    throw new RuntimeException('Informe o nome do viajante.');
                }
                $cep = fn ($x) => ($d = preg_replace('/\D/', '', (string) $x)) !== '' ? substr($d, 0, 5) . '-' . substr($d, 5, 3) : null;
                $num = fn ($x) => max(0, round((float) str_replace(',', '.', str_replace('.', '', preg_match('/,/', (string) $x) ? (string) $x : str_replace('.', ',', (string) $x))), 2));
                $data = [
                    'name' => $name, 'mode' => isset(Viajante::MODES[$in['mode'] ?? '']) ? $in['mode'] : 'uber', 'origin_cep' => $cep($in['origin_cep'] ?? ''), 'dest_cep' => $cep($in['dest_cep'] ?? ''),
                    'km_manual' => !empty($in['km_manual']) ? 1 : 0, 'km_oneway' => $num($in['km_oneway'] ?? 0), 'tolls_out' => Menu::parseMoney((string) ($in['tolls_out'] ?? '0')), 'tolls_back' => Menu::parseMoney((string) ($in['tolls_back'] ?? '0')),
                    'peak_out' => !empty($in['peak_out']) ? 1 : 0, 'peak_back' => !empty($in['peak_back']) ? 1 : 0,
                    'airport_origin' => isset(self::AIRPORTS[$in['airport_origin'] ?? '']) ? $in['airport_origin'] : null, 'airport_dest' => isset(self::AIRPORTS[$in['airport_dest'] ?? '']) ? $in['airport_dest'] : null,
                    'km_access_origin' => $num($in['km_access_origin'] ?? 0), 'km_access_dest' => $num($in['km_access_dest'] ?? 0),
                    'air_fare_out' => Menu::parseMoney((string) ($in['air_fare_out'] ?? '0')), 'air_fare_back' => Menu::parseMoney((string) ($in['air_fare_back'] ?? '0')), 'air_extra' => mb_substr((string) ($in['air_extra'] ?? ''), 0, 1000),
                    'hotel_nights' => max(0, (int) ($in['hotel_nights'] ?? 0)), 'hotel_rate' => Menu::parseMoney((string) ($in['hotel_rate'] ?? '0')), 'meal_days' => max(0, (int) ($in['meal_days'] ?? 0)), 'meal_rate' => Menu::parseMoney((string) ($in['meal_rate'] ?? '0')),
                    'parking_days' => max(0, (int) ($in['parking_days'] ?? 0)), 'parking_rate' => Menu::parseMoney((string) ($in['parking_rate'] ?? '0')),
                    'uber_source' => !empty($in['uber_source']) && Uber::enabled() ? 1 : 0, 'uber_policy' => in_array($in['uber_policy'] ?? '', ['min', 'mid', 'max'], true) ? $in['uber_policy'] : 'max',
                ];
                if ($v) {
                    $moved = ($v['origin_cep'] ?? null) !== $data['origin_cep'] || ($v['dest_cep'] ?? null) !== $data['dest_cep'];
                    $DB->update(Viajante::getTable(), $data + ($moved ? ['origin_lat' => null, 'origin_lon' => null, 'dest_lat' => null, 'dest_lon' => null, 'geo_source' => null] : []) + ['date_mod' => $now], ['id' => $vid]);
                } else {
                    $DB->insert(Viajante::getTable(), $data + ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'date_creation' => $now, 'date_mod' => $now]);
                    $vid = (int) $DB->insertId();
                }
                self::refresh($vid);
                Historico::record($ev->getID(), 'producao', 'finance', 'Simulação de viagem ' . ($v ? 'atualizada' : 'criada') . ': ' . $name . '.');
                return 'Viajante salvo e simulação calculada.';

            case 'delete':
                $DB->delete(Cotacao::getTable(), ['viajantes_id' => $vid]);
                $DB->delete(Viajante::getTable(), ['id' => $vid]);
                Historico::record($ev->getID(), 'producao', 'finance', 'Viajante removido da simulação: ' . $v['name'] . '.');
                return 'Viajante removido.';

            case 'geocode':
                $msgs = [];
                $upd = [];
                foreach (['origin' => 'origem', 'dest' => 'local do evento'] as $k => $label) {
                    if (!$v[$k . '_cep']) {
                        $msgs[] = "informe o CEP de $label";
                        continue;
                    }
                    try {
                        [$lbl, $lat, $lon] = self::geocode((string) $v[$k . '_cep']);
                        $upd += [$k . '_label' => mb_substr($lbl, 0, 255), $k . '_lat' => $lat, $k . '_lon' => $lon];
                    } catch (RuntimeException $e) {
                        $msgs[] = "$label: " . $e->getMessage();
                    }
                }
                if ($upd) {
                    $DB->update(Viajante::getTable(), $upd + ['geo_source' => 'viacep+osm', 'date_mod' => $now], ['id' => $vid]);
                    self::refresh($vid);
                }
                if ($msgs) {
                    throw new RuntimeException(($upd ? 'Parcial. ' : '') . implode(' · ', $msgs));
                }
                return 'CEP localizado: distância estimada (linha reta × fator de rota). Não é a rota oficial.';

            case 'airport':
                if ($v['origin_lat'] === null || $v['dest_lat'] === null) {
                    throw new RuntimeException('Busque os CEPs antes: preciso das coordenadas para sugerir o aeroporto.');
                }
                $ao = self::nearestAirport((float) $v['origin_lat'], (float) $v['origin_lon']);
                $ad = self::nearestAirport((float) $v['dest_lat'], (float) $v['dest_lon']);
                $upd = ['airport_origin' => $ao, 'airport_dest' => $ad, 'date_mod' => $now];
                $p = Parametro::version((int) $v['params_version']) ?: Parametro::current();
                if (!(float) $v['km_access_origin']) {
                    $upd['km_access_origin'] = round(Uber::km((float) $v['origin_lat'], (float) $v['origin_lon'], self::AIRPORTS[$ao][1], self::AIRPORTS[$ao][2]) * (float) $p['route_factor'], 2);
                }
                if (!(float) $v['km_access_dest']) {
                    $upd['km_access_dest'] = round(Uber::km((float) $v['dest_lat'], (float) $v['dest_lon'], self::AIRPORTS[$ad][1], self::AIRPORTS[$ad][2]) * (float) $p['route_factor'], 2);
                }
                $DB->update(Viajante::getTable(), $upd, ['id' => $vid]);
                self::refresh($vid);
                return "Sugestão por proximidade: $ao (origem) e $ad (destino). É uma aproximação: troque se precisar.";

            case 'recalc':
                self::refresh($vid, true);
                return 'Simulação recalculada com os parâmetros vigentes (v' . (int) Parametro::current()['version'] . ').';

            case 'quote':
                return self::quote($ev, $v, (string) ($post['leg'] ?? ''));

            case 'adopt':
                $q = $DB->request(['FROM' => Cotacao::getTable(), 'WHERE' => ['id' => (int) ($post['cotacao_id'] ?? 0), 'viajantes_id' => $vid], 'LIMIT' => 1])->current();
                if (!$q) {
                    throw new RuntimeException('Cotação não encontrada.');
                }
                if ($q['low'] === null && $q['high'] === null) {
                    throw new RuntimeException('Esta cotação veio sem valores: peça uma nova ou use a estimativa interna.');
                }
                $DB->update(Cotacao::getTable(), ['is_selected' => 0], ['viajantes_id' => $vid, 'leg' => $q['leg']]);
                $DB->update(Cotacao::getTable(), ['is_selected' => 1], ['id' => $q['id']]);
                self::refresh($vid);
                return 'Categoria adotada para o trecho.';

            case 'link':
                if ((int) $v['total_pending'] || (float) $v['total'] <= 0) {
                    throw new RuntimeException('Há trecho sem estimativa: complete a simulação antes de vincular ao orçamento.');
                }
                $sub = match ($v['mode']) { 'proprio' => 'Locação de carro', 'uber_aereo' => 'Passagens', default => 'Uber/táxi' };
                $data = ['title' => 'Viagem: ' . $v['name'], 'category' => 'Viagens', 'subcategory' => $sub, 'amount_eac' => $v['total'], 'date_mod' => $now,
                    'extra' => json_encode(['source' => 'viagem', 'id' => $vid, 'params' => (int) $v['params_version'], 'at' => $now])];
                $lid = (int) $v['linked_registros_id'];
                if ($lid && Registro::row($lid, $ev->getID())) {
                    $DB->update(Registro::getTable(), $data, ['id' => $lid]);
                } else {
                    $DB->insert(Registro::getTable(), $data + ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'lancamento', 'nature' => 'despesa', 'status' => 'previsto', 'cc' => (string) $ev->fields['cc_number'],
                        'phase' => 'producao', 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now]);
                    $lid = (int) $DB->insertId();
                    $DB->update(Viajante::getTable(), ['linked_registros_id' => $lid], ['id' => $vid]);
                }
                Historico::record($ev->getID(), 'producao', 'finance', 'Estimativa de viagem de ' . $v['name'] . ' vinculada ao EAC: ' . Menu::money((float) $v['total']) . ' (parâmetros v' . (int) $v['params_version'] . '). O Budget aprovado não muda.');
                Financeiro::checkTolerance($ev);
                return 'Estimativa vinculada ao EAC (linha Viagens). O Budget aprovado não foi alterado.';
        }
        throw new RuntimeException('Ação desconhecida.');
    }

    /** Cotação oficial (ou simulada) de um trecho: guarda um retrato por produto e nunca apaga os anteriores. */
    private static function quote(Evento $ev, array $v, string $leg): string
    {
        global $DB;
        if (!isset(Cotacao::LEGS[$leg])) {
            throw new RuntimeException('Trecho inválido.');
        }
        $ao = $v['airport_origin'] ? self::AIRPORTS[$v['airport_origin']] : null;
        $ad = $v['airport_dest'] ? self::AIRPORTS[$v['airport_dest']] : null;
        $o = [(float) $v['origin_lat'], (float) $v['origin_lon']];
        $d = [(float) $v['dest_lat'], (float) $v['dest_lon']];
        if ($v['origin_lat'] === null || $v['dest_lat'] === null) {
            throw new RuntimeException('CEP incompleto: busque os CEPs antes de cotar. Sem coordenadas não há cotação.');
        }
        $pts = match ($leg) {
            'ida' => [$o, $d], 'volta' => [$d, $o],
            'acesso_ida' => $ao ? [$o, [$ao[1], $ao[2]]] : null, 'egresso_ida' => $ad ? [[$ad[1], $ad[2]], $d] : null,
            'acesso_volta' => $ad ? [$d, [$ad[1], $ad[2]]] : null, 'egresso_volta' => $ao ? [[$ao[1], $ao[2]], $o] : null,
        };
        if (!$pts) {
            throw new RuntimeException('Escolha os aeroportos antes de cotar os trechos de acesso.');
        }
        $now = $_SESSION['glpi_currenttime'];
        $base = ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'viajantes_id' => (int) $v['id'], 'leg' => $leg, 'users_id' => (int) Session::getLoginUserID(), 'quoted_at' => $now];
        try {
            $r = Uber::estimate($pts[0][0], $pts[0][1], $pts[1][0], $pts[1][1]);
        } catch (UberException $e) {
            $DB->insert(Cotacao::getTable(), $base + ['source' => 'api', 'status' => 'erro', 'error_code' => $e->errCode]);
            Historico::record($ev->getID(), 'producao', 'integration', 'Cotação Uber falhou (' . Cotacao::LEGS[$leg] . ' de ' . $v['name'] . '): ' . $e->errCode . '.');
            throw new RuntimeException($e->getMessage() . ' Você pode manter a estimativa interna (desmarque "usar cotação Uber" no viajante).');
        }
        $n = 0;
        foreach ($r['products'] as $p) {
            $none = $p['low'] === null && $p['high'] === null;
            $DB->insert(Cotacao::getTable(), $base + ['product_id' => mb_substr($p['product_id'], 0, 64), 'product_name' => mb_substr($p['name'], 0, 100), 'low' => $p['low'], 'high' => $p['high'], 'currency' => $p['currency'],
                'distance_km' => $p['distance_km'], 'duration_min' => $p['duration_min'], 'surge' => $p['surge'], 'source' => $r['source'], 'status' => $none ? 'sem_estimativa' : 'ok', 'quoted_at' => $r['quoted_at']]);
            $n++;
        }
        if (!$n) {
            $DB->insert(Cotacao::getTable(), $base + ['source' => $r['source'], 'status' => 'sem_estimativa', 'error_code' => 'sem_produtos']);
            throw new RuntimeException('A Uber não devolveu nenhuma categoria para este trecho. Use a estimativa interna ou lance o valor manualmente.');
        }
        Historico::record($ev->getID(), 'producao', 'integration', 'Cotação Uber consultada (' . Cotacao::LEGS[$leg] . ' de ' . $v['name'] . '): ' . $n . ' categoria(s).');
        return "$n categoria(s) retornada(s). Escolha qual adotar no trecho. A cotação é instantânea e não garante o preço para a data do evento.";
    }

    // ------------------------------------------------------------------ aba

    public static function tab(Evento $ev, bool $edit): void
    {
        $e = [Menu::class, 'e'];
        $back = Evento::tabUrl($ev->getID(), 'viagens');
        $action = $e(Evento::getFormURL());
        $hid = "<input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e($back) . "'>";
        echo "<div class='ge ge-tab'>" . Abas::banner($ev, 'producao');
        if (!Evento::canFinance()) {
            echo "<div class='alert alert-warning'><i class='ti ti-lock me-1'></i>As viagens são restritas ao perfil Financeiro.</div></div>";
            return;
        }
        $rows = Viajante::forEvento($ev->getID());
        $total = array_sum(array_map(fn ($v) => (float) $v['total'], $rows));
        $pending = count(array_filter($rows, fn ($v) => (int) $v['total_pending']));
        $param = Parametro::current();
        $mode = Uber::mode();
        $uberState = $mode === 'live' ? (Uber::enabled() ? ['Uber API ativa', '#22c55e'] : ['Uber API indisponível (sem autorização)', '#ef4444']) : ($mode === 'mock' ? ['Uber simulado (homologação)', '#f59e0b'] : ['Somente estimativa interna', '#64748b']);
        echo "<div class='alert alert-info py-2'><i class='ti ti-info-circle me-1'></i><b>Estimativa preliminar, nunca preço contratual.</b> A distância usa a linha reta entre os CEPs × fator de rota (não é rota oficial). Parâmetros versionados: cada simulação guarda a versão que usou.</div>";
        echo "<div class='row g-3 mb-3'>" . Aprovacao::tile('Total estimado do evento', Menu::money($total) . ($pending ? ' + pendente' : ''), $pending ? '#f59e0b' : '#14b8a6')
            . Aprovacao::tile('Viajantes', (string) count($rows), '#3b82f6') . Aprovacao::tile('Parâmetros vigentes', 'v' . (int) $param['version'], '#8b5cf6') . Aprovacao::tile('Cotação Uber', $uberState[0], $uberState[1]) . "</div>";

        if ($edit) {
            echo "<form method='post' action='$action' class='card mb-3'>$hid<div class='card-header'><h3 class='card-title'><i class='ti ti-user-plus me-2'></i>Adicionar viajante</h3></div><div class='card-body row g-2 align-items-end'>"
                . "<div class='col-md-3'><label class='form-label small mb-0'>Nome</label><input class='form-control form-control-sm' name='v[name]' maxlength='255' required></div>"
                . "<div class='col-md-3'><label class='form-label small mb-0'>Modo</label><select class='form-select form-select-sm' name='v[mode]'>" . Menu::options(Viajante::MODES) . "</select></div>"
                . "<div class='col-md-2'><label class='form-label small mb-0'>CEP de origem</label><input class='form-control form-control-sm' name='v[origin_cep]' placeholder='00000-000' maxlength='9'></div>"
                . "<div class='col-md-2'><label class='form-label small mb-0'>CEP do local do evento</label><input class='form-control form-control-sm' name='v[dest_cep]' placeholder='00000-000' maxlength='9' value='" . $e($rows ? end($rows)['dest_cep'] : '') . "'></div>"
                . "<div class='col-auto'><button class='btn btn-sm btn-outline-primary' name='viagem_action' value='save'><i class='ti ti-plus me-1'></i>Adicionar</button></div></div>" . Html::closeForm(false);
        }
        foreach ($rows as $v) {
            echo self::card($ev, $v, $edit, $action, $hid);
        }
        if (!$rows) {
            echo "<div class='card'><div class='card-body text-secondary'>Nenhum viajante ainda. O total do evento é a soma dos viajantes.</div></div>";
        }
        echo "</div>";
    }

    private static function card(Evento $ev, array $v, bool $edit, string $action, string $hid): string
    {
        $e = [Menu::class, 'e'];
        $calc = json_decode((string) $v['calc'], true) ?: ['legs' => [], 'source' => 'interna'];
        $vid = (int) $v['id'];
        $form = fn (string $act, string $icon, string $label, string $cls = 'btn-outline-secondary', string $extra = '') => "<form method='post' action='$action' class='d-inline'>$hid<input type='hidden' name='viajante_id' value='$vid'>$extra"
            . "<button class='btn btn-sm $cls' name='viagem_action' value='$act'><i class='ti $icon me-1'></i>$label</button>" . Html::closeForm(false);
        $src = $calc['source'] === 'uber' ? "<span class='badge bg-green-lt'>Cotação Uber</span>" : "<span class='badge bg-secondary-lt'>Estimativa interna</span>";
        $h = "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-user me-2'></i>" . $e($v['name']) . "</h3><div class='card-actions small text-secondary'>"
            . $e(Viajante::MODES[$v['mode']] ?? '') . " · $src · parâmetros v" . (int) $v['params_version'] . " · <b class='fs-4 text-body'>" . Menu::money((float) $v['total']) . "</b>" . ((int) $v['total_pending'] ? " <span class='badge bg-yellow-lt'>há trecho sem estimativa</span>" : '') . "</div></div>";
        $h .= "<div class='table-responsive'><table class='table table-sm card-table table-vcenter'><thead><tr><th>Trecho / item</th><th class='text-end'>Km</th><th>Origem do valor</th><th class='text-end'>Valor</th></tr></thead><tbody>";
        foreach ($calc['legs'] as [$key, $label, $km, $val, $source]) {
            $h .= "<tr><td>" . $e($label) . "</td><td class='text-end'>" . ($km ? Menu::num((float) $km) : '') . "</td><td class='small text-secondary'>" . $e($source) . "</td><td class='text-end'>" . ($val === null ? "<span class='badge bg-yellow-lt'>sem estimativa</span>" : Menu::money((float) $val)) . "</td></tr>";
        }
        $h .= $calc['legs'] ? '' : "<tr><td colspan='4' class='text-secondary small'>Sem cálculo ainda.</td></tr>";
        $h .= "</tbody></table></div>";
        $h .= "<div class='card-body border-top small text-secondary'>" . ($v['origin_label'] ? 'Origem: ' . $e($v['origin_label']) . ' · ' : '') . ($v['dest_label'] ? 'Destino: ' . $e($v['dest_label']) . ' · ' : '')
            . ($v['km_oneway'] > 0 ? 'Distância estimada ' . Menu::num((float) $v['km_oneway']) . ' km' . ((int) $v['km_manual'] ? ' (informada manualmente)' : ' (linha reta × fator ' . Menu::num((float) ($calc['route_factor'] ?? 1.3)) . ')') : 'Sem distância: busque o CEP ou informe o km.')
            . ($v['calc_date'] ? ' · calculado em ' . Menu::date($v['calc_date'], true) : '') . "</div>";
        if ($edit) {
            $h .= "<div class='card-footer d-flex flex-wrap gap-2'>" . $form('geocode', 'ti-map-search', 'Buscar CEP / coordenadas')
                . ($v['mode'] === 'uber_aereo' ? $form('airport', 'ti-plane-departure', 'Sugerir aeroportos') : '')
                . $form('recalc', 'ti-refresh', 'Recalcular com parâmetros vigentes')
                . $form('link', 'ti-link', 'Vincular ao EAC', 'btn-outline-primary')
                . $form('delete', 'ti-trash', 'Remover', 'btn-ghost-danger') . "</div>";
            $h .= self::uberPanel($ev, $v, $action, $hid);
            $h .= "<details class='border-top'><summary class='px-3 py-2 text-primary' style='cursor:pointer'><i class='ti ti-edit me-1'></i>Editar dados da viagem</summary>" . self::editForm($v, $action, $hid) . "</details>";
        }
        return $h . "</div>";
    }

    private static function editForm(array $v, string $action, string $hid): string
    {
        $e = [Menu::class, 'e'];
        $in = fn (string $name, string $label, $val, string $col = 'col-md-2', string $attr = '') => "<div class='$col'><label class='form-label small mb-0'>" . $e($label) . "</label><input class='form-control form-control-sm' name='v[$name]' value='" . $e($val) . "' $attr></div>";
        $money = fn ($x) => (float) $x ? number_format((float) $x, 2, ',', '.') : '';
        $chk = fn (string $name, string $label, $val) => "<div class='col-auto'><label class='form-check form-switch mb-1'><input type='hidden' name='v[$name]' value='0'><input class='form-check-input' type='checkbox' name='v[$name]' value='1'" . ($val ? ' checked' : '') . "><span class='form-check-label'>" . $e($label) . "</span></label></div>";
        $airports = ['' => '—'] + array_map(fn ($a) => $a[0], self::AIRPORTS);
        $airports = array_combine(array_keys($airports), array_map(fn ($k, $n) => $k === '' ? $n : "$k · $n", array_keys($airports), $airports));
        $h = "<form method='post' action='$action' class='card-body row g-2 align-items-end'>$hid<input type='hidden' name='viajante_id' value='" . (int) $v['id'] . "'>";
        $h .= $in('name', 'Nome', $v['name'], 'col-md-3') . "<div class='col-md-3'><label class='form-label small mb-0'>Modo</label><select class='form-select form-select-sm' name='v[mode]'>" . Menu::options(Viajante::MODES, $v['mode']) . "</select></div>"
            . $in('origin_cep', 'CEP de origem', $v['origin_cep']) . $in('dest_cep', 'CEP do local do evento', $v['dest_cep']);
        $h .= $in('km_oneway', 'Km (um sentido)', (float) $v['km_oneway'] ? number_format((float) $v['km_oneway'], 2, ',', '') : '') . $chk('km_manual', 'Km informado manualmente (não recalcular)', $v['km_manual'])
            . $in('tolls_out', 'Pedágio — ida (R$)', $money($v['tolls_out'])) . $in('tolls_back', 'Pedágio — volta (R$)', $money($v['tolls_back']))
            . $chk('peak_out', 'Horário de pico na ida', $v['peak_out']) . $chk('peak_back', 'Horário de pico na volta', $v['peak_back']);
        $h .= "<div class='col-12 small text-secondary pt-2'>Uber + aéreo</div>"
            . "<div class='col-md-3'><label class='form-label small mb-0'>Aeroporto de origem</label><select class='form-select form-select-sm' name='v[airport_origin]'>" . Menu::options($airports, (string) $v['airport_origin']) . "</select></div>"
            . "<div class='col-md-3'><label class='form-label small mb-0'>Aeroporto de destino</label><select class='form-select form-select-sm' name='v[airport_dest]'>" . Menu::options($airports, (string) $v['airport_dest']) . "</select></div>"
            . $in('km_access_origin', 'Km casa ↔ aeroporto', (float) $v['km_access_origin'] ? number_format((float) $v['km_access_origin'], 2, ',', '') : '') . $in('km_access_dest', 'Km aeroporto ↔ local', (float) $v['km_access_dest'] ? number_format((float) $v['km_access_dest'], 2, ',', '') : '')
            . $in('air_fare_out', 'Tarifa aérea — ida (R$)', $money($v['air_fare_out'])) . $in('air_fare_back', 'Tarifa aérea — volta (R$)', $money($v['air_fare_back']))
            . "<div class='col-md-6'><label class='form-label small mb-0'>Trechos adicionais (um por linha: descrição;valor)</label><textarea class='form-control form-control-sm' rows='2' name='v[air_extra]'>" . $e($v['air_extra']) . "</textarea></div>";
        $h .= "<div class='col-12 small text-secondary pt-2'>Estadia (vazio usa os parâmetros vigentes)</div>"
            . $in('hotel_nights', 'Diárias de hotel', (int) $v['hotel_nights'] ?: '') . $in('hotel_rate', 'Diária do hotel (R$)', $money($v['hotel_rate'])) . $in('meal_days', 'Diárias de alimentação', (int) $v['meal_days'] ?: '') . $in('meal_rate', 'Alimentação/dia (R$)', $money($v['meal_rate']))
            . $in('parking_days', 'Diárias de estacionamento (só veículo próprio)', (int) $v['parking_days'] ?: '', 'col-md-3') . $in('parking_rate', 'Estacionamento/dia (R$)', $money($v['parking_rate']));
        if (Uber::enabled()) {
            $h .= "<div class='col-12 small text-secondary pt-2'>Cotação Uber</div>" . $chk('uber_source', 'Usar a cotação Uber nos trechos de carro (senão, estimativa interna)', $v['uber_source'])
                . "<div class='col-md-3'><label class='form-label small mb-0'>Valor de referência da faixa</label><select class='form-select form-select-sm' name='v[uber_policy]'>" . Menu::options(['min' => 'Mínimo', 'mid' => 'Ponto médio', 'max' => 'Máximo (conservador)'], $v['uber_policy']) . "</select></div>";
        }
        return $h . "<div class='col-12'><button class='btn btn-primary btn-sm' name='viagem_action' value='save'><i class='ti ti-device-floppy me-1'></i>Salvar e recalcular</button></div>" . Html::closeForm(false);
    }

    /** Painel de cotação por trecho: consultar, ver as categorias retornadas e adotar uma. */
    private static function uberPanel(Evento $ev, array $v, string $action, string $hid): string
    {
        if (!Uber::enabled() || !(int) $v['uber_source']) {
            return '';
        }
        $e = [Menu::class, 'e'];
        $legs = $v['mode'] === 'uber_aereo' ? ['acesso_ida', 'egresso_ida', 'acesso_volta', 'egresso_volta'] : ($v['mode'] === 'uber' ? ['ida', 'volta'] : []);
        if (!$legs) {
            return '';
        }
        $h = "<div class='border-top p-3'><div class='fw-bold mb-1'><i class='ti ti-brand-uber me-1'></i>Cotação por trecho</div>"
            . "<div class='small text-secondary mb-2'>" . ($v['uber_policy'] === 'mid' ? 'Ponto médio' : ($v['uber_policy'] === 'min' ? 'Mínimo' : 'Máximo')) . " da faixa como valor de referência. A consulta é instantânea: não garante o preço na data do evento, nem disponibilidade; cada trecho é consultado separadamente.</div>";
        foreach ($legs as $leg) {
            $quotes = Cotacao::forViajante((int) $v['id'], $leg);
            $latest = $quotes ? $quotes[0]['quoted_at'] : null;
            $h .= "<div class='mb-2'><div class='d-flex align-items-center gap-2'><b class='small'>" . $e(Cotacao::LEGS[$leg]) . "</b>" . ($latest ? "<span class='small text-secondary'>última consulta " . Menu::date($latest, true) . "</span>" : '')
                . "<form method='post' action='$action' class='ms-auto m-0'>$hid<input type='hidden' name='viajante_id' value='" . (int) $v['id'] . "'><input type='hidden' name='leg' value='$leg'>"
                . "<button class='btn btn-sm btn-outline-primary' name='viagem_action' value='quote'><i class='ti ti-refresh me-1'></i>" . ($quotes ? 'Recotar' : 'Cotar') . "</button>" . Html::closeForm(false) . "</div>";
            $shown = 0;
            $lastAt = $latest;
            foreach ($quotes as $q) {
                if ($q['quoted_at'] !== $lastAt) {
                    break; // só a consulta mais recente na tela; as anteriores ficam guardadas
                }
                $shown++;
                if ($q['status'] === 'erro') {
                    $h .= "<div class='small text-danger'><i class='ti ti-alert-triangle me-1'></i>Falha da consulta: " . $e($q['error_code']) . "</div>";
                    continue;
                }
                $ref = Cotacao::reference($q, (string) $v['uber_policy']);
                $h .= "<div class='d-flex align-items-center gap-2 small ps-2 border-start " . ((int) $q['is_selected'] ? 'border-success' : '') . "'>"
                    . "<span style='min-width:150px'>" . $e($q['product_name'] ?: 'Categoria') . ($q['source'] === 'mock' ? " <span class='badge bg-yellow-lt'>simulado</span>" : '') . "</span>"
                    . "<span>" . ($q['low'] === null && $q['high'] === null ? "<span class='badge bg-yellow-lt'>sem estimativa</span>" : $e($q['currency']) . ' ' . Menu::num((float) ($q['low'] ?? $q['high'])) . ' – ' . Menu::num((float) ($q['high'] ?? $q['low']))) . "</span>"
                    . "<span class='text-secondary'>" . ($q['distance_km'] !== null ? Menu::num((float) $q['distance_km']) . ' km' : '') . ($q['duration_min'] !== null ? ' · ' . (int) $q['duration_min'] . ' min' : '') . ($q['surge'] !== null ? ' · tarifa dinâmica ×' . Menu::num((float) $q['surge']) . ' (já incluída)' : '') . "</span>"
                    . ($ref !== null ? "<b class='ms-auto'>ref. " . Menu::money($ref) . "</b>" : '')
                    . "<form method='post' action='$action' class='m-0 " . ($ref === null ? 'ms-auto' : '') . "'>$hid<input type='hidden' name='viajante_id' value='" . (int) $v['id'] . "'><input type='hidden' name='cotacao_id' value='" . (int) $q['id'] . "'>"
                    . ((int) $q['is_selected'] ? "<span class='badge bg-green-lt'>adotada</span>" : "<button class='btn btn-sm btn-ghost-primary py-0' name='viagem_action' value='adopt'" . ($ref === null ? ' disabled' : '') . ">Adotar</button>") . Html::closeForm(false) . "</div>";
            }
            if (!$shown) {
                $h .= "<div class='small text-secondary'>Sem consulta ainda: o trecho fica sem estimativa até você cotar e adotar uma categoria.</div>";
            }
            $h .= "</div>";
        }
        return $h . "</div>";
    }

    // ------------------------------------------------------------------ configuração Uber (Alçadas e regras)

    public static function showUberConfig(): void
    {
        $e = [Menu::class, 'e'];
        $c = Uber::cfg();
        $mode = Uber::mode();
        echo "<form method='post' action='" . $e(Menu::url('/front/admin.php')) . "?tab=uber' class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-brand-uber me-2'></i>Integração Uber — cotação oficial</h3></div><div class='card-body row g-3'>";
        echo "<div class='col-12 small text-secondary'>A API de estimativa de preço da Uber exige <b>aprovação formal da Uber</b> para o endpoint; cadastrar o aplicativo não basta. Sem a autorização, a opção fica indisponível e o simulador usa só a estimativa interna. "
            . "O token é obtido pelo servidor (OAuth 2.0 client_credentials, escopo <code>ride_request.estimate</code>); as credenciais ficam criptografadas e nunca vão ao navegador, aos relatórios nem aos logs. "
            . "A API não pede corrida nem garante disponibilidade ou preço para uma data futura. Respeite o contrato e os termos de uso da Uber (por exemplo, sem comparativos com concorrentes quando vedado).</div>";
        echo "<div class='col-md-3'><label class='form-label'>Modo</label><select class='form-select' name='uber_mode'>" . Menu::options(['off' => 'Desligado', 'mock' => 'Simulado (homologação da tela)', 'live' => 'Cotação oficial'], $mode) . "</select></div>";
        echo "<div class='col-md-3'><label class='form-label'>Ambiente</label><select class='form-select' name='uber_env'>" . Menu::options(['production' => 'Produção', 'sandbox' => 'Sandbox'], $c['uber_env'] ?? 'production') . "</select></div>";
        echo "<div class='col-md-6 d-flex align-items-end'><label class='form-check form-switch mb-2'><input class='form-check-input' type='checkbox' name='uber_authorized' value='1'" . (($c['uber_authorized'] ?? '0') === '1' ? ' checked' : '') . ">"
            . "<span class='form-check-label'><b>A Uber concedeu formalmente o acesso ao endpoint de preços</b> (RN-24)</span></label></div>";
        echo "<div class='col-md-4'><label class='form-label'>Client ID</label><input class='form-control' name='uber_client_id' autocomplete='off' value='" . $e($c['uber_client_id'] ?? '') . "'></div>";
        echo "<div class='col-md-4'><label class='form-label'>Client secret</label><input type='password' class='form-control' name='uber_client_secret' autocomplete='new-password' placeholder='" . (($c['uber_client_secret'] ?? '') !== '' ? '•••••••• (guardado, deixe vazio para manter)' : 'não informado') . "'></div>";
        echo "<div class='col-md-2'><label class='form-label'>Tempo limite (s)</label><input type='number' min='2' max='30' class='form-control' name='uber_timeout' value='" . (int) ($c['uber_timeout'] ?? 10) . "'></div>";
        echo "<div class='col-md-2'><label class='form-label'>Consultas/min</label><input type='number' min='1' max='600' class='form-control' name='uber_rate_per_min' value='" . (int) ($c['uber_rate_per_min'] ?? 20) . "'></div>";
        echo "<div class='col-md-3'><label class='form-label'>Falhas seguidas para pausar</label><input type='number' min='1' max='50' class='form-control' name='uber_fail_limit' value='" . (int) ($c['uber_fail_limit'] ?? 5) . "'></div>";
        echo "<div class='col-md-3'><label class='form-label'>Referência da faixa (padrão)</label><select class='form-select' name='uber_policy'>" . Menu::options(['min' => 'Mínimo', 'mid' => 'Ponto médio', 'max' => 'Máximo (conservador)'], $c['uber_policy'] ?? 'max') . "</select></div>";
        echo "</div><div class='card-footer d-flex flex-wrap gap-2 align-items-center'><button class='btn btn-primary' name='save_uber' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>"
            . "<button class='btn btn-outline-secondary' name='test_uber' value='1'><i class='ti ti-plug-connected me-1'></i>Testar conexão</button>"
            . "<span class='small text-secondary ms-auto'>Último sucesso: <b>" . $e($c['uber_last_ok'] ?? '—') . "</b> · Último erro: <b>" . $e($c['uber_last_error'] ?? '—') . "</b>"
            . ((int) ($c['uber_open_until'] ?? 0) > time() ? " · <span class='text-danger'>pausada até " . date('H:i', (int) $c['uber_open_until']) . '</span>' : '') . "</span></div>";
        Html::closeForm();
    }
}
