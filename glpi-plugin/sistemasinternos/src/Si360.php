<?php

namespace GlpiPlugin\Sistemasinternos;

use DateTime;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Motor do dashboard Sistemas Internos 360 dentro do GLPI: o que antes vinha do painel
 * do Zabbix (porta 8090), calculado aqui, com a conta de serviço do plugin e só nos
 * hostgroups do departamento.
 *
 * Disponibilidade e tempo fora: eventos de problema dos triggers de indisponibilidade
 * (ICMP, agente, SNMP), como o Zabbix os registra. Os dados ficam no cache do GLPI;
 * vencido, a tela recebe o anterior na hora e o novo é calculado depois da resposta.
 */
class Si360
{
    public const SEVERITIES = [0 => 'Não classificado', 1 => 'Informação', 2 => 'Atenção', 3 => 'Média', 4 => 'Alta', 5 => 'Desastre'];
    public const PERIODS = ['hoje' => 'Hoje', '24h' => 'Últimas 24 h', 'ontem' => 'Ontem', '7d' => 'Últimos 7 dias',
        '30d' => 'Últimos 30 dias', 'mes_atual' => 'Mês atual', 'mes_anterior' => 'Mês anterior'];

    private const CACHE_SECONDS = 30;
    private const REFRESH_SECONDS = 30;
    private const ALERT_POLL_SECONDS = 15;
    private const SLO_TARGET = 99.9;
    private const LOOKBACK_DAYS = 60;
    private const AVAIL_PATTERNS = ['Unavailable by ICMP ping', 'Zabbix agent is not available', 'Unavailable by Zabbix agent',
        'SNMP agent is not available', 'Unavailable by SNMP', 'No SNMP data collection'];
    private const METRIC_KEYS = [
        'cpu'  => ['system.cpu.util'],
        'mem'  => ['vm.memory.utilization', 'vm.memory.util'],
        'ping' => ['icmppingsec'],
        'loss' => ['icmppingloss'],
    ];
    private const ESC_MIN_SEV = 4;
    private const ESC_GROUPS = ['sistemas internos', 'ha-datacenter', 'applications']; // trecho do nome do hostgroup
    private const SEVMAP_MIN = 4;
    private const DRILL_LIMIT = 2000;
    private const BIZ_START = 8;
    private const BIZ_END = 18;
    private const BIZ_DAYS = [0, 1, 2, 3, 4]; // 0 = segunda … 6 = domingo
    private const PATCH_GRACE_DAYS = 7;
    private const PATCH_TARGET_BUILD = 20348;

    /** Respostas de cada rota (as mesmas do painel do Zabbix). */
    public static function route(string $path, array $q): array
    {
        $parts = explode('/', trim($path, '/'));
        $period = (string) ($q['period'] ?? '30d');
        switch ($parts[0] ?? '') {
            case 'config':
                return ['demo' => false, 'refresh_seconds' => self::REFRESH_SECONDS, 'slo' => self::SLO_TARGET,
                    'periods' => array_map(fn ($k, $v) => ['id' => $k, 'label' => $v], array_keys(self::PERIODS), self::PERIODS),
                    'severities' => self::obj(self::SEVERITIES), 'timezone' => self::tz()->getName(),
                    'alert_poll_seconds' => self::ALERT_POLL_SECONDS];
            case 'alerts':
                return self::alertsNow($period);
            case 'overview':
                return self::overview(self::model($period));
            case 'group':
                $gid = self::gid((string) ($parts[1] ?? ''));
                if (($parts[2] ?? '') === 'incidents') {
                    $limit = min((int) ($q['limit'] ?? 300), 20000);
                    $min = (int) ($q['min_severity'] ?? 0);
                    $model = self::model($period);
                    self::known($model, $gid);
                    return ['incidents' => array_values(array_filter(self::incidentsFor($model, $gid, $limit), fn ($i) => $i['sev'] >= $min)), 'limit' => $limit];
                }
                if (($parts[2] ?? '') === 'sevmap') {
                    $model = self::model($period);
                    self::known($model, $gid);
                    $pn = $model['period']['name'];
                    return self::cached("sevmap|$gid|$pn", 60, fn () => self::sevmap(self::model($pn), $gid));
                }
                return self::groupView(self::model($period), $gid);
            case 'topology':
                $gid = self::gid((string) ($q['group'] ?? ''));
                self::known(self::model('24h'), $gid);
                return self::cached("topo|$gid", 30, fn () => self::topology(self::model('24h'), $gid));
            case 'patches':
                $gid = self::gid((string) ($q['group'] ?? ''));
                self::known(self::model('24h'), $gid);
                return self::cached("patches|$gid", 300, fn () => self::patches($gid));
            case 'hw':
                $ids = array_slice(array_values(array_filter(explode(',', (string) ($q['hosts'] ?? '')), fn ($x) => ctype_digit(trim($x)))), 0, 12);
                if (!$ids) {
                    throw new NotFound('informe os hosts');
                }
                self::ownHosts($ids);
                sort($ids);
                return self::cached('hw|' . implode(',', $ids), 60, fn () => self::hw($ids));
            case 'db':
                $gid = self::gid((string) ($q['group'] ?? ''));
                self::known(self::model('24h'), $gid);
                return self::cached("db|$gid", 120, fn () => self::db($gid));
            case 'drill':
                return self::drill(self::model($period), $q);
            case 'host':
                $hid = (string) ($parts[1] ?? '');
                self::ownHosts([$hid]);
                $d = self::cached("hostd|$hid", 30, fn () => self::hostDetail($hid), false);
                $model = self::model($period);
                if (isset($model['hosts'][$hid])) {
                    $h = $model['hosts'][$hid];
                    $d = array_merge($d, ['stats' => self::hostRow($h), 'state' => $h['state'], 'period' => $model['period']['label']]);
                }
                return $d;
            case 'event':
                $eid = (string) ($parts[1] ?? '');
                if (!ctype_digit($eid)) {
                    throw new NotFound('evento não encontrado');
                }
                $d = self::cached("event|$eid", 30, fn () => self::eventDetail($eid), false);
                self::ownHosts(array_column($d['hosts'] ?? [], 'id'));
                return $d;
            case 'sla':
                try {
                    $slas = self::cached('sla', 300, fn () => self::sla());
                    $groups = self::model('30d')['groups'];
                    $seen = $out = [];
                    foreach (self::gids(self::gid((string) ($q['group'] ?? Settings::get('groups')))) as $g) {
                        foreach (self::slaForGroup($slas, $groups[$g] ?? '') as $s) {
                            if (!isset($seen[$s['id']])) {
                                $seen[$s['id']] = true;
                                $out[] = $s;
                            }
                        }
                    }
                    return ['slas' => $out, 'slo_default' => self::SLO_TARGET];
                } catch (Throwable $e) {
                    return ['slas' => [], 'aviso' => $e->getMessage()]; // SLA é opcional (Zabbix 6.0+ com SLAs configurados)
                }
            case 'trendx':
                $metric = isset(self::TREND_LBL[$q['metric'] ?? '']) ? $q['metric'] : 'cpu';
                $hours = max(24, min((int) ($q['hours'] ?? 168), 720));
                $gid = self::gid((string) ($q['group'] ?? Settings::get('groups')));
                $hostid = ctype_digit((string) ($q['host'] ?? '')) ? (string) $q['host'] : '';
                if ($hostid !== '') {
                    self::ownHosts([$hostid]);
                }
                return self::cached("trendx|$metric|$hours|$gid|$hostid", 180, fn () => self::trendx($metric, $hours, $gid, $hostid));
            case 'trend-hosts':
                $gid = self::gid((string) ($q['group'] ?? Settings::get('groups')));
                return self::cached("trendhosts|$gid", 600, function () use ($gid) {
                    $ids = array_unique(array_column(self::zbx('item.get', ['output' => ['hostid'], 'monitored' => true, 'groupids' => self::gids($gid),
                        'searchByAny' => true, 'search' => ['key_' => ['system.cpu.util', 'vm.memory.util', 'vfs.fs.size[']]]), 'hostid'));
                    $hs = $ids ? self::zbx('host.get', ['hostids' => array_values($ids), 'output' => ['hostid', 'name']]) : [];
                    $out = array_map(fn ($h) => ['id' => $h['hostid'], 'nome' => $h['name']], $hs);
                    usort($out, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));
                    return $out;
                });
            case 'trend':
                $metric = isset(self::METRIC_KEYS[$q['metric'] ?? '']) ? $q['metric'] : 'cpu';
                $hours = min((int) ($q['hours'] ?? 168), 720);
                $gid = self::gid((string) ($q['group'] ?? Settings::get('groups')));
                return self::cached("trend|$metric|$hours|$gid", 120, fn () => self::trend($metric, $hours, $gid));
            case 'logos':
                return ['logos' => self::obj([]), 'dir' => 'static/logos'];
            case 'ia':
                return ['ultima' => null]; // a OAK IA roda fora do GLPI: sem análises aqui
        }
        throw new NotFound('rota desconhecida');
    }

    // ------------------------------------------------------------------ acesso e utilidades

    /** Grupos pedidos pela tela: só os do departamento. */
    private static function gid(string $gid): string
    {
        $allowed = Settings::ids('groups');
        $want = array_values(array_filter(array_map('trim', explode(',', $gid)), fn ($g) => $g !== ''));
        if (!$want || array_diff($want, $allowed)) {
            throw new NotFound('grupo não encontrado');
        }
        return implode(',', $want);
    }

    private static function gids(string $gid): array
    {
        return array_values(array_filter(explode(',', $gid), fn ($g) => $g !== ''));
    }

    private static function inGroups(array $h, string $gid): bool
    {
        return (bool) array_intersect(self::gids($gid), $h['groups']);
    }

    private static function known(array $model, string $gid): void
    {
        foreach (self::gids($gid) as $g) {
            if (!isset($model['groups'][$g])) {
                throw new NotFound('grupo não encontrado');
            }
        }
    }

    private static function groupsName(array $model, string $gid): string
    {
        return implode(' + ', array_map(fn ($g) => $model['groups'][$g] ?? $g, self::gids($gid)));
    }

    /** Hosts pedidos pela tela precisam ser do departamento. */
    private static function ownHosts(array $ids): void
    {
        $hosts = self::model('24h')['hosts'];
        foreach ($ids as $id) {
            if (!isset($hosts[(string) $id])) {
                throw new NotFound('host fora do departamento');
            }
        }
    }

    /** Mapa com chaves numéricas que precisa ir como objeto no JSON. */
    public static function obj(array $a): object
    {
        return (object) $a;
    }

    private static function zbx(string $method, array $params = []): mixed
    {
        return Zabbix::call($method, $params, null, null, 120);
    }

    private static function zbxGroups(string $method, array $params, array $fields): array
    {
        return self::zbx($method, $params + [Zabbix::groupsParam() => $fields]);
    }

    /** event.get com os reconhecimentos (selectAcknowledges no 7.x, select_acknowledges no 6.x). */
    private static function acks(array $params, mixed $fields): array
    {
        try {
            return self::zbx('event.get', $params + ['selectAcknowledges' => $fields]);
        } catch (RuntimeException) {
            return self::zbx('event.get', $params + ['select_acknowledges' => $fields]);
        }
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) (float) $v : null;
    }

    /** Fim de cada evento resolvido: id do evento de recuperação → horário. */
    private static function recoveries(array $evs): array
    {
        $rids = [];
        foreach ($evs as $e) {
            if (!in_array($e['r_eventid'] ?? '0', [null, '0', 0], true)) {
                $rids[] = $e['r_eventid'];
            }
        }
        $map = [];
        foreach (array_chunk($rids, 500) as $part) {
            foreach (self::zbx('event.get', ['output' => ['eventid', 'clock'], 'eventids' => $part]) as $r) {
                $map[$r['eventid']] = (int) $r['clock'];
            }
        }
        return $map;
    }

    private static function closed(array $e): bool
    {
        return !in_array($e['r_eventid'] ?? '0', [null, '0', 0], true);
    }

    /**
     * Cache compartilhado (cache do GLPI). Vencido: devolve o anterior e recalcula
     * depois de responder (fastcgi_finish_request); velho demais: espera o novo.
     */
    public static function cached(string $key, int $ttl, callable $fn, bool $background = true): mixed
    {
        global $GLPI_CACHE;
        $k = self::key($key);
        $hit = null;
        try {
            $hit = $GLPI_CACHE?->get($k);
        } catch (Throwable) {
        }
        $now = time();
        $store = function () use ($k, $fn, $ttl) {
            global $GLPI_CACHE;
            $v = $fn();
            try {
                $GLPI_CACHE?->set($k, ['t' => time(), 'v' => $v], max(5 * $ttl, 300));
            } catch (Throwable) {
            }
            return $v;
        };
        if (is_array($hit) && isset($hit['t']) && $now - $hit['t'] <= max(5 * $ttl, 300)) {
            if ($now - $hit['t'] >= $ttl && $background) {
                self::later($k, $store);
            } elseif ($now - $hit['t'] >= $ttl) {
                return self::once($k, $store);
            }
            return $hit['v'];
        }
        return self::once($k, $store);
    }

    /** Uma busca por chave: quem chega enquanto outro calcula espera e usa o mesmo resultado. */
    private static function once(string $k, callable $store): mixed
    {
        global $GLPI_CACHE;
        $dir = defined('GLPI_TMP_DIR') && is_writable(GLPI_TMP_DIR) ? GLPI_TMP_DIR : sys_get_temp_dir();
        $fh = @fopen($dir . '/' . $k . '.lock', 'c');
        if (!$fh) {
            return $store();
        }
        try {
            $t0 = time();
            flock($fh, LOCK_EX);
            try { // outro calculou enquanto esperava
                $hit = $GLPI_CACHE?->get($k);
                if (is_array($hit) && ($hit['t'] ?? 0) >= $t0) {
                    return $hit['v'];
                }
            } catch (Throwable) {
            }
            return $store();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function key(string $key): string
    {
        return 'plugin_si360_' . md5($key . '|' . Settings::get('zabbix_url') . '|' . Settings::get('groups'));
    }

    /** O que está no cache agora (sem calcular). */
    private static function peek(string $key): mixed
    {
        global $GLPI_CACHE;
        try {
            $hit = $GLPI_CACHE?->get(self::key($key));
        } catch (Throwable) {
            return null;
        }
        return is_array($hit) ? $hit['v'] ?? null : null;
    }

    /** Marca como vencido: a próxima tela ainda vê o anterior, e o novo é calculado depois da resposta. */
    private static function expire(string $key, int $ttl): void
    {
        global $GLPI_CACHE;
        try {
            $hit = $GLPI_CACHE?->get(self::key($key));
            if (is_array($hit) && isset($hit['t'])) {
                $GLPI_CACHE->set(self::key($key), ['t' => min($hit['t'], time() - $ttl), 'v' => $hit['v']], max(5 * $ttl, 300));
            }
        } catch (Throwable) {
        }
    }

    private static array $later = [];

    /** Atualização depois da resposta (uma vez por chave e por requisição; um aviso evita repetir em paralelo). */
    private static function later(string $k, callable $store): void
    {
        global $GLPI_CACHE;
        if (isset(self::$later[$k])) {
            return;
        }
        try {
            if ($GLPI_CACHE?->get($k . '_busy')) {
                return;
            }
            $GLPI_CACHE?->set($k . '_busy', 1, 120);
        } catch (Throwable) {
        }
        if (!self::$later) {
            register_shutdown_function(function () {
                global $GLPI_CACHE;
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                ignore_user_abort(true);
                set_time_limit(300);
                foreach (self::$later as $key => $fn) {
                    try {
                        $fn();
                    } catch (Throwable) {
                    }
                    try {
                        $GLPI_CACHE?->delete($key . '_busy');
                    } catch (Throwable) {
                    }
                }
            });
        }
        self::$later[$k] = $store;
    }

    // ------------------------------------------------------------------ períodos (fuso do GLPI)

    /** Fuso do GLPI; servidor em UTC (sem fuso configurado) usa o de Brasília, como o painel original. */
    private static function tz(): DateTimeZone
    {
        $tz = date_default_timezone_get();
        return new DateTimeZone($tz === 'UTC' ? 'America/Sao_Paulo' : $tz);
    }

    private static function midnight(int $ts): int
    {
        return (new DateTime('@' . $ts))->setTimezone(self::tz())->setTime(0, 0)->getTimestamp();
    }

    private static function addDays(int $ts, int $n): int
    {
        return (new DateTime('@' . self::midnight($ts)))->setTimezone(self::tz())->modify(($n >= 0 ? '+' : '') . $n . ' days')->setTime(0, 0)->getTimestamp();
    }

    private static function firstOfMonth(int $ts, int $offset): int
    {
        $d = (new DateTime('@' . $ts))->setTimezone(self::tz());
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n') + $offset;
        while ($m < 1) {
            $m += 12;
            $y--;
        }
        while ($m > 12) {
            $m -= 12;
            $y++;
        }
        return (new DateTime(sprintf('%04d-%02d-01 00:00:00', $y, $m), self::tz()))->getTimestamp();
    }

    public static function period(string $name): array
    {
        $now = time();
        $mid = self::midnight($now);
        [$s, $e, $k] = match ($name) {
            '24h'          => [$now - 86400, $now, 'hour'],
            'hoje'         => [$mid, $now, 'hour'],
            'ontem'        => [self::addDays($mid, -1), $mid, 'hour'],
            '7d'           => [self::addDays($mid, -6), $now, 'day'],
            'mes_atual'    => [self::firstOfMonth($now, 0), $now, 'day'],
            'mes_anterior' => [self::firstOfMonth($now, -1), self::firstOfMonth($now, 0), 'day'],
            default        => [self::addDays($mid, -29), $now, 'day'],
        };
        if (!isset(self::PERIODS[$name]) || $name === '30d') {
            $name = '30d';
        }
        return ['name' => $name, 'label' => self::PERIODS[$name], 'start' => $s, 'end' => $e, 'bucket' => $k];
    }

    private static function buckets(int $start, int $end, string $kind): array
    {
        $out = [];
        if ($kind === 'hour') {
            for ($t = $start - $start % 3600; $t < $end; $t += 3600) {
                $out[] = [$t, $t + 3600];
            }
        } else {
            for ($t = self::midnight($start); $t < $end; $t = $n) {
                $n = self::addDays($t, 1);
                $out[] = [$t, $n];
            }
        }
        return $out;
    }

    private static function merge(array $iv): array
    {
        sort($iv);
        $out = [];
        foreach ($iv as [$s, $e]) {
            $n = count($out);
            if ($n && $s <= $out[$n - 1][1]) {
                $out[$n - 1][1] = max($out[$n - 1][1], $e);
            } else {
                $out[] = [$s, $e];
            }
        }
        return $out;
    }

    private static function overlap(array $iv, int $a, int $b): int
    {
        $t = 0;
        foreach ($iv as [$s, $e]) {
            $t += max(0, min($e, $b) - max($s, $a));
        }
        return $t;
    }

    // ------------------------------------------------------------------ carga do Zabbix (só os grupos do departamento)

    /** Problemas abertos dos grupos do departamento (um por evento), com os hosts de cada um. */
    public static function problems(): array
    {
        return self::cached('problems', max(5, self::ALERT_POLL_SECONDS - 2), function () {
            $probs = self::zbx('problem.get', ['output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'suppressed'],
                'groupids' => Settings::ids('groups'), 'recent' => false, 'sortfield' => ['eventid'], 'sortorder' => 'DESC']);
            $mon = self::monitored(array_column($probs, 'objectid'));
            $hostmap = [];
            foreach (array_chunk(array_column($probs, 'eventid'), 500) as $part) {
                foreach (self::zbx('event.get', ['output' => ['eventid'], 'eventids' => $part, 'selectHosts' => ['hostid', 'name', 'status']]) as $e) {
                    $hostmap[$e['eventid']] = $e['hosts'] ?? [];
                }
            }
            $out = [];
            foreach ($probs as $p) {
                $hs = $hostmap[$p['eventid']] ?? [];
                $out[] = ['id' => $p['eventid'], 'name' => $p['name'], 'sev' => (int) $p['severity'], 'clock' => (int) $p['clock'],
                    'host' => implode(', ', array_column($hs, 'name')), 'hostids' => array_column($hs, 'hostid'),
                    'ack' => ($p['acknowledged'] ?? '0') === '1', 'suppressed' => ($p['suppressed'] ?? '0') === '1',
                    'host_active' => (bool) array_filter($hs, fn ($h) => ($h['status'] ?? '0') === '0'),
                    'visible' => isset($mon[$p['objectid']])];
            }
            return $out;
        });
    }

    /** Triggers ativos com item e host ativos (o critério da tela Problemas do Zabbix). */
    private static function monitored(array $tids): array
    {
        $out = [];
        $tids = array_values(array_unique($tids));
        sort($tids);
        foreach (array_chunk($tids, 500) as $part) {
            foreach (self::zbx('trigger.get', ['triggerids' => $part, 'output' => ['triggerid'], 'monitored' => true]) as $t) {
                $out[$t['triggerid']] = true;
            }
        }
        return $out;
    }

    private static function load(int $start, int $end): array
    {
        $groups = Settings::ids('groups');
        $raw = self::zbxGroups('host.get', ['output' => ['hostid', 'name', 'status', 'maintenance_status'], 'groupids' => $groups,
            'selectInterfaces' => ['available', 'main']], ['groupid', 'name']);
        usort($raw, fn ($a, $b) => (int) $a['hostid'] <=> (int) $b['hostid']);
        $hosts = $gnames = [];
        foreach (self::zbx('hostgroup.get', ['output' => ['groupid', 'name'], 'groupids' => $groups]) as $g) {
            $gnames[$g['groupid']] = $g['name'];
        }
        foreach ($raw as $h) {
            $gl = Zabbix::hostGroups($h);
            foreach ($gl as $g) {
                $gnames[$g['groupid']] = $g['name'];
            }
            $hosts[$h['hostid']] = ['id' => $h['hostid'], 'name' => $h['name'], 'status' => (int) $h['status'],
                'maint' => ($h['maintenance_status'] ?? '0') === '1',
                'iface_down' => (bool) array_filter($h['interfaces'] ?? [], fn ($i) => ($i['main'] ?? '') === '1' && ($i['available'] ?? '') === '2'),
                'groups' => array_column($gl, 'groupid')];
        }

        // eventos de indisponibilidade
        $pat = ['name' => self::AVAIL_PATTERNS];
        $evs = self::zbx('event.get', ['output' => 'extend', 'source' => 0, 'object' => 0, 'value' => 1, 'groupids' => $groups,
            'time_from' => $start - self::LOOKBACK_DAYS * 86400, 'time_till' => $end, 'search' => $pat, 'searchByAny' => true,
            'selectHosts' => ['hostid'], 'sortfield' => ['eventid'], 'sortorder' => 'ASC']);
        $seen = array_flip(array_column($evs, 'eventid'));
        try { // problemas ainda abertos, mesmo que anteriores ao período de busca
            $open = self::zbx('problem.get', ['output' => ['eventid'], 'groupids' => $groups, 'search' => $pat, 'searchByAny' => true, 'recent' => false]);
            $missing = array_values(array_filter(array_column($open, 'eventid'), fn ($id) => !isset($seen[$id])));
            foreach (array_chunk($missing, 500) as $part) {
                $evs = array_merge($evs, self::zbx('event.get', ['output' => 'extend', 'eventids' => $part, 'selectHosts' => ['hostid']]));
            }
        } catch (RuntimeException) {
        }
        $rmap = self::recoveries($evs);
        $events = [];
        foreach ($evs as $e) {
            $s = (int) $e['clock'];
            $endTs = self::closed($e) ? ($rmap[$e['r_eventid']] ?? $s + 60) : null;
            foreach ($e['hosts'] ?? [] as $h) {
                $events[$h['hostid']][] = ['id' => $e['eventid'], 's' => $s, 'e' => $endTs, 'name' => $e['name'], 'sev' => (int) ($e['severity'] ?? 0)];
            }
        }

        // problemas ativos por host (mesma fonte da tela Problemas e dos alertas)
        $active = [];
        foreach (self::problems() as $pr) {
            if ($pr['suppressed'] || !$pr['visible']) {
                continue;
            }
            foreach ($pr['hostids'] as $hid) {
                $active[$hid][] = $pr['sev'];
            }
        }
        return [$hosts, $gnames, $events, $active, self::metrics($groups)];
    }

    /** Último valor de CPU, memória, latência e perda por host. */
    private static function metrics(array $groups): array
    {
        $out = $acc = [];
        try {
            $keys = array_merge(...array_values(self::METRIC_KEYS));
            $items = self::zbx('item.get', ['output' => ['hostid', 'key_', 'lastvalue', 'value_type'], 'groupids' => $groups,
                'filter' => ['key_' => $keys], 'monitored' => true]);
        } catch (RuntimeException) {
            return $out;
        }
        foreach ($items as $it) {
            if (!in_array($it['value_type'], ['0', '3'], true) || ($it['lastvalue'] ?? '') === '') {
                continue;
            }
            foreach (self::METRIC_KEYS as $metric => $ks) {
                if (in_array($it['key_'], $ks, true)) {
                    $v = (float) $it['lastvalue'];
                    $acc[$it['hostid']][$metric][] = $metric === 'ping' ? $v * 1000.0 : $v;
                }
            }
        }
        foreach ($acc as $hid => $d) {
            foreach ($d as $m => $v) {
                $out[$hid][$m] = array_sum($v) / count($v);
            }
        }
        return $out;
    }

    private static function hostStats(array $evs, int $start, int $cap, int $end, array $buckets): array
    {
        $now = time();
        $ivs = $durs = $starts = [];
        $outages = $longest = 0;
        $last = $openSince = null;
        foreach ($evs as $ev) {
            $s = $ev['s'];
            $e = $ev['e'] ?? $now;
            if ($ev['e'] === null) {
                $openSince = $openSince === null ? $s : min($openSince, $s);
            }
            if ($e <= $start || $s >= $end) {
                continue;
            }
            $outages++;
            $cs = max($s, $start);
            $ce = min($e, $cap);
            if ($ce > $cs) {
                $ivs[] = [$cs, $ce];
                $longest = max($longest, $ce - $cs);
            }
            if ($ev['e'] !== null) {
                $durs[] = $ev['e'] - $ev['s'];
            }
            if ($s >= $start) {
                $starts[] = $s;
            }
            $last = $last === null ? $s : max($last, $s);
        }
        $merged = self::merge($ivs);
        $down = 0;
        foreach ($merged as [$s, $e]) {
            $down += $e - $s;
        }
        $span = max(1, $cap - $start);
        $bd = $bs = [];
        foreach ($buckets as [$a, $b]) {
            $bb = min($b, $cap);
            $bs[] = max(0, $bb - $a);
            $bd[] = $bb > $a ? self::overlap($merged, $a, $bb) : 0;
        }
        return ['down' => $down, 'avail' => 100.0 * (1 - $down / $span), 'outages' => $outages, 'longest' => $longest, 'last' => $last,
            'open_since' => $openSince, 'dur_sum' => array_sum($durs), 'dur_n' => count($durs), 'starts' => $starts, 'bd' => $bd, 'bs' => $bs];
    }

    public static function model(string $periodName): array
    {
        $p = self::period($periodName);
        return self::cached('model|' . $p['name'], self::CACHE_SECONDS, function () use ($p) {
            $start = $p['start'];
            $end = $p['end'];
            $cap = max(min($end, time()), $start + 60);
            [$hosts, $groups, $events, $active, $metrics] = self::load($start, $end);
            $buckets = self::buckets($start, $end, $p['bucket']);
            foreach ($hosts as $hid => &$h) {
                $hid = (string) $hid;
                $st = self::hostStats($events[$hid] ?? [], $start, $cap, $end, $buckets);
                $h += $st;
                $h['state'] = $h['status'] === 1 ? 'disabled' : (($st['open_since'] || $h['iface_down']) ? 'down' : ($h['maint'] ? 'maintenance' : 'up'));
                $pr = array_fill_keys(array_keys(self::SEVERITIES), 0);
                foreach ($h['status'] === 0 ? ($active[$hid] ?? []) : [] as $sv) { // host desativado: o Zabbix não mostra os problemas
                    $pr[$sv] = ($pr[$sv] ?? 0) + 1;
                }
                $h['problems'] = $pr;
                $h['metrics'] = $metrics[$hid] ?? [];
            }
            unset($h);
            return ['period' => $p, 'hosts' => $hosts, 'groups' => $groups, 'events' => $events, 'buckets' => $buckets, 'generated' => time()];
        });
    }

    // ------------------------------------------------------------------ agregação

    private static function avg(array $vals): ?float
    {
        $vals = array_filter($vals, fn ($v) => $v !== null);
        return $vals ? array_sum($vals) / count($vals) : null;
    }

    private static function summarize(array $hs, array $buckets): array
    {
        $act = array_values(array_filter($hs, fn ($h) => $h['state'] !== 'disabled'));
        $states = ['up' => 0, 'down' => 0, 'maintenance' => 0, 'disabled' => 0];
        foreach ($hs as $h) {
            $states[$h['state']]++;
        }
        $sev = array_fill_keys(array_keys(self::SEVERITIES), 0);
        $dSum = $dN = 0;
        foreach ($act as $h) {
            foreach ($h['problems'] as $s => $c) {
                $sev[(int) $s] += $c;
            }
            $dSum += $h['dur_sum'];
            $dN += $h['dur_n'];
        }
        $sa = $sd = $so = [];
        foreach ($buckets as $i => [$a, $b]) {
            $span = $dn = $o = 0;
            foreach ($act as $h) {
                $span += $h['bs'][$i];
                $dn += $h['bd'][$i];
                foreach ($h['starts'] as $t) {
                    $o += ($a <= $t && $t < $b) ? 1 : 0;
                }
            }
            $sa[] = $span > 0 ? round(100.0 * (1 - $dn / $span), 4) : null;
            $sd[] = $dn;
            $so[] = $o;
        }
        $worst = null;
        foreach ($act as $h) {
            if ($worst === null || $h['avail'] < $worst['avail']) {
                $worst = $h;
            }
        }
        $metric = fn ($m) => self::avg(array_map(fn ($h) => $h['metrics'][$m] ?? null, $act));
        return [
            'hosts' => count($hs), 'monitored' => count($act), 'states' => $states,
            'avail' => $act ? round(self::avg(array_column($act, 'avail')), 4) : null,
            'avail_min' => $worst ? round($worst['avail'], 4) : null,
            'worst_host' => $worst['name'] ?? null,
            'hosts_below_slo' => count(array_filter($act, fn ($h) => $h['avail'] < self::SLO_TARGET)),
            'downtime' => array_sum(array_column($act, 'down')),
            'outages' => array_sum(array_column($act, 'outages')),
            'mttr' => $dN ? $dSum / $dN : null,
            'longest' => max(array_merge([0], array_column($act, 'longest'))),
            'problems' => self::obj($sev), 'problems_total' => array_sum($sev),
            'cpu' => $metric('cpu'), 'mem' => $metric('mem'), 'ping' => $metric('ping'), 'loss' => $metric('loss'),
            'series' => ['avail' => $sa, 'downtime' => $sd, 'outages' => $so],
        ];
    }

    private static function hostRow(array $h): array
    {
        return ['id' => $h['id'], 'name' => $h['name'], 'state' => $h['state'], 'avail' => round($h['avail'], 4),
            'downtime' => $h['down'], 'outages' => $h['outages'], 'longest' => $h['longest'], 'last' => $h['last'],
            'mttr' => $h['dur_n'] ? $h['dur_sum'] / $h['dur_n'] : null, 'open_since' => $h['open_since'],
            'problems' => self::obj($h['problems']), 'problems_total' => array_sum($h['problems']),
            'cpu' => $h['metrics']['cpu'] ?? null, 'mem' => $h['metrics']['mem'] ?? null,
            'ping' => $h['metrics']['ping'] ?? null, 'loss' => $h['metrics']['loss'] ?? null];
    }

    private static function bucketInfo(array $model): array
    {
        return [array_map(fn ($b) => ['from' => $b[0], 'to' => $b[1]], $model['buckets']), $model['period']['bucket']];
    }

    private static function groupView(array $model, string $gid): array
    {
        self::known($model, $gid);
        $hs = array_values(array_filter($model['hosts'], fn ($h) => self::inGroups($h, $gid)));
        $summ = self::summarize($hs, $model['buckets']);
        $series = $summ['series'];
        unset($summ['series']);
        $rows = array_map([self::class, 'hostRow'], $hs);
        usort($rows, fn ($a, $b) => [$a['avail'], $a['name']] <=> [$b['avail'], $b['name']]);
        // heatmap: até 40 hosts, os com mais tempo fora primeiro
        $top = array_values(array_filter($hs, fn ($h) => $h['state'] !== 'disabled'));
        usort($top, fn ($a, $b) => [-$a['down'], $a['name']] <=> [-$b['down'], $b['name']]);
        $top = array_slice($top, 0, 40);
        $cells = [];
        foreach ($top as $yi => $h) {
            foreach ($h['bd'] as $xi => $d) {
                $s = $h['bs'][$xi];
                $cells[] = [$xi, $yi, $s > 0 ? round(100.0 * (1 - $d / $s), 3) : null];
            }
        }
        [$b, $kind] = self::bucketInfo($model);
        return ['group' => ['id' => $gid, 'name' => self::groupsName($model, $gid)], 'period' => $model['period'], 'slo' => self::SLO_TARGET,
            'summary' => $summ, 'buckets' => $b, 'bucket_kind' => $kind, 'series' => $series,
            'heatmap' => ['hosts' => array_column($top, 'name'), 'cells' => $cells], 'hosts' => $rows, 'generated' => $model['generated']];
    }

    private static function overview(array $model): array
    {
        $groups = [];
        foreach (Settings::ids('groups') as $gid) {
            if (!isset($model['groups'][$gid])) {
                continue;
            }
            $hs = array_values(array_filter($model['hosts'], fn ($h) => in_array((string) $gid, $h['groups'], true)));
            if (!array_filter($hs, fn ($h) => $h['status'] === 0)) {
                continue;
            }
            $s = self::summarize($hs, $model['buckets']);
            unset($s['series']);
            $groups[] = ['id' => (string) $gid, 'name' => $model['groups'][$gid]] + $s;
        }
        usort($groups, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $total = self::summarize(array_values($model['hosts']), $model['buckets']);
        $series = $total['series'];
        unset($total['series']);
        [$b, $kind] = self::bucketInfo($model);
        return ['period' => $model['period'], 'slo' => self::SLO_TARGET, 'groups' => $groups, 'total' => $total,
            'buckets' => $b, 'bucket_kind' => $kind, 'series' => $series, 'generated' => $model['generated']];
    }

    // ------------------------------------------------------------------ alertas e incidentes

    private static function alertsNow(string $period): array
    {
        $model = self::model('30d');
        $hosts = $model['hosts'];
        $escName = Settings::get('escalation_name');
        $alerts = [];
        foreach (self::problems() as $p) {
            if (!$p['visible'] || $p['suppressed'] || !$p['host_active'] || !array_intersect($p['hostids'], array_keys($hosts))) {
                continue;
            }
            if ($escName !== '' && $p['sev'] >= self::ESC_MIN_SEV) { // acionar quem atende: Alta e Desastre nos grupos de acionamento
                foreach ($p['hostids'] as $hid) {
                    foreach ($hosts[$hid]['groups'] ?? [] as $g) {
                        $gn = mb_strtolower($model['groups'][$g] ?? '');
                        foreach (self::ESC_GROUPS as $e) {
                            if (str_contains($gn, $e)) {
                                $p['escalate'] = true;
                            }
                        }
                    }
                }
            }
            $alerts[] = $p;
        }
        $ids = array_column($alerts, 'id');
        sort($ids);
        $sig = substr(md5(implode(',', $ids)), 0, 16);
        $prev = self::peek('alertsig');
        if ($prev !== $sig) {
            if ($prev !== null) { // problema novo ou resolvido: os modelos precisam de dados frescos
                foreach (array_keys(self::PERIODS) as $pn) {
                    self::expire('model|' . $pn, self::CACHE_SECONDS);
                }
            }
            global $GLPI_CACHE;
            try {
                $GLPI_CACHE?->set(self::key('alertsig'), ['t' => time(), 'v' => $sig], 86400);
            } catch (Throwable) {
            }
        }
        return ['alerts' => $alerts, 'sig' => $sig, 'gen' => self::peek('model|' . self::period($period)['name'])['generated'] ?? null,
            'escalation' => $escName !== '' ? ['name' => $escName, 'phone' => Settings::get('escalation_phone')] : null];
    }

    private static function incidents(?string $gid, int $start, int $end, int $limit, ?array $hostids = null): array
    {
        $params = ['output' => ['eventid', 'clock', 'name', 'severity', 'r_eventid', 'acknowledged'], 'source' => 0, 'object' => 0, 'value' => 1,
            'time_from' => $start, 'time_till' => $end, 'selectHosts' => ['hostid', 'name'], 'sortfield' => ['eventid'], 'sortorder' => 'DESC',
            'limit' => $limit, 'groupids' => self::gids($gid ?: Settings::get('groups'))];
        if ($hostids) {
            $params['hostids'] = array_values($hostids);
        }
        $evs = self::zbx('event.get', $params);
        $rmap = self::recoveries($evs);
        $out = [];
        foreach ($evs as $e) {
            $s = (int) $e['clock'];
            $hs = $e['hosts'] ?: [['hostid' => '', 'name' => '-']];
            $out[] = ['id' => $e['eventid'], 'hostid' => $hs[0]['hostid'], 'host' => $hs[0]['name'], 'name' => $e['name'],
                'sev' => (int) ($e['severity'] ?? 0), 'start' => $s, 'end' => self::closed($e) ? ($rmap[$e['r_eventid']] ?? $s + 60) : null,
                'ack' => ($e['acknowledged'] ?? '0') === '1'];
        }
        // quem reconheceu e quando (só dos reconhecidos)
        $acked = array_column(array_filter($out, fn ($o) => $o['ack']), 'id');
        $who = [];
        foreach (array_chunk($acked, 500) as $part) {
            foreach (self::acks(['eventids' => $part, 'output' => ['eventid']], ['clock', 'action', 'username', 'name', 'surname']) as $e) {
                $acks = $e['acknowledges'] ?? [];
                usort($acks, fn ($a, $b) => (int) $a['clock'] <=> (int) $b['clock']);
                $ack2 = array_values(array_filter($acks, fn ($x) => ((int) ($x['action'] ?? 0)) & 2)) ?: $acks; // ação 2 = reconhecer
                if ($ack2) {
                    $x = $ack2[0];
                    $nm = trim(implode(' ', array_filter([$x['name'] ?? '', $x['surname'] ?? ''])));
                    $who[$e['eventid']] = [$nm ?: ($x['username'] ?? ''), (int) ($x['clock'] ?? 0)];
                }
            }
        }
        foreach ($out as &$o) {
            if (isset($who[$o['id']])) {
                [$o['ack_by'], $o['ack_at']] = $who[$o['id']];
            }
        }
        unset($o);
        return $out;
    }

    private static function incidentsFor(array $model, string $gid, int $limit): array
    {
        $p = $model['period'];
        return self::cached("inc|$gid|{$p['name']}|$limit", 120, fn () => self::incidents($gid, $p['start'], $p['end'], $limit));
    }

    private static function drill(array $model, array $a): array
    {
        $p = $model['period'];
        $now = time();
        $gid = ($a['group'] ?? '') !== '' ? self::gid((string) $a['group']) : null;
        $host = trim((string) ($a['host'] ?? ''));
        if ($gid) {
            self::known($model, $gid);
        }
        $hs = array_filter($model['hosts'], fn ($h) => (!$gid || self::inGroups($h, $gid)) && ($host === '' || $h['name'] === $host));
        $gname = fn ($h) => implode(', ', array_map(fn ($g) => $model['groups'][$g] ?? $g, $h['groups']));
        $frm = self::int($a['from'] ?? null) ?: $p['start'];
        $to = min(self::int($a['to'] ?? null) ?: $p['end'], $now);
        $sevs = array_map('intval', array_filter(explode(',', (string) ($a['sev'] ?? '')), fn ($x) => ctype_digit(trim($x))));
        $kind = $a['kind'] ?? 'outages';
        $items = [];
        if ($kind === 'outages') {
            foreach ($hs as $h) {
                foreach ($model['events'][$h['id']] ?? [] as $e) {
                    $end = $e['e'] ?? $now;
                    if ($end <= $frm || $e['s'] >= $to || ($sevs && !in_array($e['sev'], $sevs, true))) {
                        continue;
                    }
                    $items[] = ['id' => $e['id'], 'host' => $h['name'], 'hostid' => $h['id'], 'group' => $gname($h), 'name' => $e['name'],
                        'sev' => $e['sev'], 'start' => $e['s'], 'end' => $e['e'], 'dur' => $end - $e['s'],
                        'in_window' => min($end, $to) - max($e['s'], $frm), 'state' => $h['state']];
                }
            }
            usort($items, fn ($x, $y) => $y['start'] <=> $x['start']);
        } elseif ($kind === 'problems') {
            foreach (self::alertsNow('30d')['alerts'] as $al) {
                $hid = null;
                foreach ($al['hostids'] as $x) {
                    if (isset($hs[$x]) && $model['hosts'][$x]['state'] !== 'disabled') {
                        $hid = $x;
                        break;
                    }
                }
                if ($hid === null || ($sevs && !in_array($al['sev'], $sevs, true))) {
                    continue;
                }
                $h = $model['hosts'][$hid];
                $items[] = ['id' => $al['id'], 'host' => $al['host'] ?: $h['name'], 'hostid' => $hid, 'group' => $gname($h), 'name' => $al['name'],
                    'sev' => $al['sev'], 'start' => $al['clock'], 'end' => null, 'dur' => $now - $al['clock'], 'ack' => $al['ack'], 'state' => $h['state']];
            }
            usort($items, fn ($x, $y) => [-$x['sev'], -$x['start']] <=> [-$y['sev'], -$y['start']]);
        } elseif ($kind === 'events') {
            $hostids = $host !== '' ? array_keys($hs) : null;
            $ov = !empty($a['overlap']); // ativos na janela, inclusive os que começaram antes
            $q0 = $ov ? $frm - 7 * 86400 : $frm;
            $raw = self::cached('drill-ev|' . $gid . '|' . $host . "|$q0|$to", 60,
                fn () => self::incidents($gid, $q0, $to, self::DRILL_LIMIT, $hostids), false);
            $seen = [];
            foreach ($raw as $i) {
                $fora = $ov ? (($i['end'] ?? $now) <= $frm || $i['start'] >= $to) : ($i['start'] < $frm || $i['start'] > $to);
                if (isset($seen[$i['id']]) || $fora || ($sevs && !in_array($i['sev'], $sevs, true))) {
                    continue;
                }
                $seen[$i['id']] = true;
                $h = $model['hosts'][$i['hostid']] ?? null;
                $items[] = $i + ['group' => $h ? $gname($h) : '', 'dur' => max(0, ($i['end'] ?? $now) - $i['start']), 'state' => $h['state'] ?? null];
            }
            usort($items, fn ($x, $y) => $y['start'] <=> $x['start']);
        } elseif ($kind === 'hosts') {
            $st = (string) ($a['state'] ?? '');
            foreach ($hs as $h) {
                $r = self::hostRow($h);
                if ($st === 'below') {
                    if ($h['state'] === 'disabled' || $r['avail'] >= self::SLO_TARGET) {
                        continue;
                    }
                } elseif ($st === 'online') {
                    if (!in_array($h['state'], ['up', 'maintenance'], true)) {
                        continue;
                    }
                } elseif ($st === 'monitored') {
                    if ($h['state'] === 'disabled') {
                        continue;
                    }
                } elseif ($st !== '' && $h['state'] !== $st) {
                    continue;
                }
                $cur = null;
                foreach ($model['events'][$h['id']] ?? [] as $e) {
                    if ($e['e'] === null) {
                        $cur = $e;
                        break;
                    }
                }
                $items[] = $r + ['group' => $gname($h), 'open_name' => $cur['name'] ?? null];
            }
            usort($items, fn ($x, $y) => [$x['avail'], $x['name']] <=> [$y['avail'], $y['name']]);
        } else {
            throw new NotFound('tipo de detalhamento desconhecido');
        }
        return ['kind' => $kind, 'items' => array_slice($items, 0, self::DRILL_LIMIT), 'total' => count($items), 'from' => $frm, 'to' => $to,
            'front' => rtrim(Zabbix::webUrl(), '/')];
    }

    // ------------------------------------------------------------------ fichas do evento e do host

    private const IFACE = ['1' => 'Agente', '2' => 'SNMP', '3' => 'IPMI', '4' => 'JMX'];
    private const AVAIL = ['1' => 'disponível', '2' => 'indisponível'];

    private static function eventDetail(string $eid): array
    {
        $evs = self::acks(['output' => 'extend', 'eventids' => [$eid], 'selectHosts' => ['hostid', 'name'], 'selectTags' => 'extend'], 'extend');
        if (!$evs) {
            throw new NotFound('evento não encontrado no Zabbix');
        }
        $e = $evs[0];
        $front = rtrim(Zabbix::webUrl(), '/');
        $out = ['id' => $e['eventid'], 'name' => $e['name'], 'sev' => (int) ($e['severity'] ?? 0), 'start' => (int) $e['clock'], 'end' => null,
            'ack' => ($e['acknowledged'] ?? '0') === '1', 'opdata' => $e['opdata'] ?? '', 'suppressed' => ($e['suppressed'] ?? '0') === '1',
            'tags' => array_map(fn ($t) => ['tag' => $t['tag'], 'value' => $t['value'] ?? ''], $e['tags'] ?? []),
            'hosts' => array_map(fn ($h) => ['id' => $h['hostid'], 'name' => $h['name']], $e['hosts'] ?? []),
            'acks' => array_map(fn ($x) => ['clock' => (int) $x['clock'], 'message' => $x['message'] ?? '',
                'user' => trim(implode(' ', array_filter([$x['name'] ?? '', $x['surname'] ?? '']))) ?: ($x['username'] ?? $x['alias'] ?? ''),
                'action' => (int) ($x['action'] ?? 0)], $e['acknowledges'] ?? [])];
        if (self::closed($e)) {
            $r = self::zbx('event.get', ['output' => ['clock'], 'eventids' => [$e['r_eventid']]]);
            if ($r) {
                $out['end'] = (int) $r[0]['clock'];
            }
        }
        $tid = $e['objectid'] ?? null;
        $out['triggerid'] = $tid;
        if ($tid && (string) ($e['object'] ?? '') === '0') {
            $tr = self::zbxGroups('trigger.get', ['triggerids' => [$tid], 'output' => ['description', 'comments', 'priority', 'url', 'expression',
                'recovery_mode', 'manual_close', 'lastchange', 'value'], 'expandExpression' => true,
                'selectItems' => ['itemid', 'name', 'key_', 'lastvalue', 'units', 'lastclock']], ['name']);
            if ($tr) {
                $t = $tr[0];
                $out['trigger'] = ['description' => $t['description'] ?? '', 'comments' => $t['comments'] ?? '', 'url' => $t['url'] ?? '',
                    'expression' => $t['expression'] ?? '', 'manual_close' => ($t['manual_close'] ?? '0') === '1',
                    'groups' => array_column(Zabbix::hostGroups($t), 'name'),
                    'items' => array_map(fn ($i) => ['name' => $i['name'], 'key' => $i['key_'], 'value' => $i['lastvalue'] ?? null,
                        'units' => $i['units'] ?? '', 'clock' => self::int($i['lastclock'] ?? null)], $t['items'] ?? [])];
            }
            try {
                $out['recurrences'] = (int) self::zbx('event.get', ['countOutput' => true, 'source' => 0, 'object' => 0, 'value' => 1,
                    'objectids' => [$tid], 'time_from' => time() - 30 * 86400]);
            } catch (RuntimeException) {
            }
        }
        if ($out['hosts']) {
            $hid = $out['hosts'][0]['id'];
            try {
                $hh = self::zbx('host.get', ['hostids' => [$hid], 'output' => ['host', 'name', 'description', 'status', 'maintenance_status'],
                    'selectInterfaces' => ['ip', 'dns', 'port', 'type', 'main', 'available']]);
                if ($hh) {
                    $h = $hh[0];
                    $ifs = $h['interfaces'] ?? [];
                    usort($ifs, fn ($a, $b) => (($a['main'] ?? '') !== '1') <=> (($b['main'] ?? '') !== '1'));
                    $out['host'] = ['id' => $hid, 'name' => $h['name'], 'technical' => $h['host'] ?? null, 'description' => $h['description'] ?? '',
                        'maintenance' => ($h['maintenance_status'] ?? '0') === '1',
                        'interfaces' => array_map(fn ($i) => ['ip' => $i['ip'] ?? null, 'dns' => $i['dns'] ?? null, 'port' => $i['port'] ?? null,
                            'type' => self::IFACE[(string) ($i['type'] ?? '')] ?? '?', 'available' => self::AVAIL[(string) ($i['available'] ?? '')] ?? 'desconhecido'], $ifs)];
                }
            } catch (RuntimeException) {
            }
            $out['links'] = ['event' => $tid ? "$front/tr_events.php?triggerid=$tid&eventid={$out['id']}" : null,
                'host' => "$front/zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=$hid"];
        }
        return $out;
    }

    private const HOST_INFO_KEYS = ['system.uptime', 'system.sw.os', 'system.hostname', 'agent.version', 'system.cpu.num',
        'vm.memory.size[total]', 'sysUpTime', 'sysName', 'sysDescr', 'sysLocation', 'sysContact',
        'system.hw.serialnumber', 'system.hw.model', 'system.descr', 'system.location', 'system.contact'];

    private static function hostDetail(string $hid): array
    {
        $hh = self::zbxGroups('host.get', ['hostids' => [$hid], 'output' => ['hostid', 'host', 'name', 'description', 'status', 'maintenance_status',
            'maintenanceid', 'maintenance_from', 'proxyid', 'inventory_mode'],
            'selectInterfaces' => ['type', 'main', 'ip', 'dns', 'port', 'useip', 'available', 'error'],
            'selectInventory' => 'extend', 'selectTags' => ['tag', 'value'], 'selectParentTemplates' => ['name']], ['groupid', 'name']);
        if (!$hh) {
            throw new NotFound('host não encontrado no Zabbix');
        }
        $h = $hh[0];
        $inv = is_array($h['inventory'] ?? null) && !array_is_list($h['inventory']) ? $h['inventory'] : [];
        $front = rtrim(Zabbix::webUrl(), '/');
        $out = ['id' => $h['hostid'], 'technical' => $h['host'] ?? null, 'name' => $h['name'] ?? null, 'description' => $h['description'] ?? '',
            'status' => ($h['status'] ?? '0') === '1' ? 'desativado' : 'monitorado',
            'inventory_mode' => ['-1' => 'desativado', '0' => 'manual', '1' => 'automático'][(string) ($h['inventory_mode'] ?? '')] ?? '',
            'inventory' => self::obj(array_filter($inv, fn ($v, $k) => !in_array($v, [null, '', '0'], true) && !in_array($k, ['hostid', 'inventory_mode'], true), ARRAY_FILTER_USE_BOTH)),
            'tags' => $h['tags'] ?? [], 'templates' => array_column($h['parentTemplates'] ?? [], 'name'),
            'groups' => array_column(Zabbix::hostGroups($h), 'name'),
            'interfaces' => array_map(fn ($i) => ['type' => self::IFACE[(string) ($i['type'] ?? '')] ?? '?', 'main' => ($i['main'] ?? '') === '1',
                'address' => ($i['useip'] ?? '1') === '1' ? ($i['ip'] ?? '') : ($i['dns'] ?? ''), 'ip' => $i['ip'] ?? '', 'dns' => $i['dns'] ?? '',
                'port' => $i['port'] ?? '', 'available' => self::AVAIL[(string) ($i['available'] ?? '')] ?? 'desconhecido', 'error' => $i['error'] ?? ''], $h['interfaces'] ?? []),
            'maintenance' => null, 'proxy' => null, 'problems' => [], 'info' => [], 'history' => [],
            'link' => "$front/zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=$hid",
            'link_latest' => "$front/zabbix.php?action=latest.view&filter_set=1&hostids%5B%5D=$hid"];
        if (($h['maintenance_status'] ?? '0') === '1' && !in_array($h['maintenanceid'] ?? '0', [null, '0'], true)) {
            try {
                $m = self::zbx('maintenance.get', ['maintenanceids' => [$h['maintenanceid']], 'output' => ['name', 'description', 'active_since', 'active_till']]);
                if ($m) {
                    $out['maintenance'] = ['name' => $m[0]['name'], 'description' => $m[0]['description'] ?? '',
                        'since' => self::int($h['maintenance_from'] ?? null) ?: self::int($m[0]['active_since'] ?? null), 'till' => self::int($m[0]['active_till'] ?? null)];
                }
            } catch (RuntimeException) {
                $out['maintenance'] = ['name' => 'em manutenção'];
            }
        }
        if (!in_array($h['proxyid'] ?? '0', [null, '', '0'], true)) {
            try {
                $px = self::zbx('proxy.get', ['proxyids' => [$h['proxyid']], 'output' => 'extend']);
                $out['proxy'] = $px ? ($px[0]['name'] ?? $px[0]['host'] ?? null) : null;
            } catch (RuntimeException) {
            }
        }
        try {
            $probs = self::zbx('problem.get', ['hostids' => [$hid], 'output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'suppressed'],
                'recent' => false, 'sortfield' => ['eventid'], 'sortorder' => 'DESC']);
            $mon = self::monitored(array_column($probs, 'objectid'));
            $probs = array_filter($probs, fn ($p) => isset($mon[$p['objectid']]) && ($p['suppressed'] ?? '0') !== '1');
            $hist = self::zbx('event.get', ['hostids' => [$hid], 'source' => 0, 'object' => 0, 'value' => 1, 'time_from' => time() - 7 * 86400,
                'output' => ['eventid', 'clock', 'name', 'severity', 'r_eventid', 'acknowledged'], 'sortfield' => ['clock'], 'sortorder' => 'DESC', 'limit' => 500]);
            $rmap = self::recoveries($hist);
            $out['history'] = array_map(fn ($e) => ['id' => $e['eventid'], 'name' => $e['name'], 'sev' => (int) $e['severity'], 'start' => (int) $e['clock'],
                'end' => self::closed($e) ? ($rmap[$e['r_eventid']] ?? null) : null, 'ack' => ($e['acknowledged'] ?? '0') === '1'], $hist);
            $out['problems'] = array_values(array_map(fn ($p) => ['id' => $p['eventid'], 'name' => $p['name'], 'sev' => (int) $p['severity'],
                'start' => (int) $p['clock'], 'end' => null, 'ack' => ($p['acknowledged'] ?? '0') === '1'], $probs));
        } catch (RuntimeException) {
        }
        try {
            $seen = [];
            foreach (self::zbx('item.get', ['hostids' => [$hid], 'output' => ['name', 'key_', 'lastvalue', 'units', 'lastclock'],
                'search' => ['key_' => self::HOST_INFO_KEYS], 'searchByAny' => true, 'monitored' => true, 'limit' => 40]) as $i) {
                if (($i['lastvalue'] ?? '') === '' || isset($seen[$i['name']])) {
                    continue;
                }
                $seen[$i['name']] = true;
                $out['info'][] = ['name' => $i['name'], 'key' => $i['key_'], 'value' => $i['lastvalue'], 'units' => $i['units'] ?? '', 'clock' => self::int($i['lastclock'] ?? null)];
            }
        } catch (RuntimeException) {
        }
        return $out;
    }

    // ------------------------------------------------------------------ SLA

    private static function sla(): array
    {
        $out = [];
        foreach (self::zbx('sla.get', ['output' => 'extend']) as $s) {
            $ptype = (int) ($s['period'] ?? 2);
            $n = [0 => 31, 1 => 12, 2 => 12, 3 => 8, 4 => 5][$ptype] ?? 12;
            try {
                $r = self::zbx('sla.getsli', ['slaid' => $s['slaid'], 'periods' => $n]);
            } catch (RuntimeException $ex) {
                $out[] = ['id' => $s['slaid'], 'name' => $s['name'], 'slo' => (float) $s['slo'], 'period' => $ptype, 'services' => [], 'erro' => $ex->getMessage()];
                continue;
            }
            $sids = $r['serviceids'] ?? [];
            $names = [];
            if ($sids) {
                foreach (self::zbx('service.get', ['output' => ['serviceid', 'name'], 'serviceids' => $sids]) as $sv) {
                    $names[$sv['serviceid']] = $sv['name'];
                }
            }
            $per = $r['periods'] ?? [];
            $sli = $r['sli'] ?? [];
            $services = [];
            foreach ($sids as $si => $sid) {
                $rows = [];
                foreach ($per as $pi => $p) {
                    $c = $sli[$pi][$si] ?? null;
                    if ($c === null) {
                        continue;
                    }
                    $v = (float) ($c['sli'] ?? -1);
                    $rows[] = ['from' => (int) $p['period_from'], 'to' => (int) $p['period_to'], 'sli' => $v >= 0 ? $v : null,
                        'uptime' => (int) ($c['uptime'] ?? 0), 'downtime' => (int) ($c['downtime'] ?? 0), 'budget' => (int) ($c['error_budget'] ?? 0),
                        'excluded' => count($c['excluded_downtimes'] ?? [])];
                }
                usort($rows, fn ($a, $b) => $a['from'] <=> $b['from']);
                $services[] = ['id' => $sid, 'name' => $names[$sid] ?? $sid, 'periods' => $rows];
            }
            $out[] = ['id' => $s['slaid'], 'name' => $s['name'], 'slo' => (float) $s['slo'], 'period' => $ptype, 'services' => $services];
        }
        return $out;
    }

    /** SLAs do grupo: os que citam o nome do grupo (o de Sistemas Internos vê todos). */
    private static function slaForGroup(array $slas, string $gname): array
    {
        $low = mb_strtolower($gname);
        if (str_contains($low, 'sistemas internos')) {
            return $slas;
        }
        $stop = ['grupo', 'group', 'oakmont', 'cliente', 'clientes', 'servers', 'servidores'];
        $toks = array_filter(preg_split('/\W+/u', $low), fn ($t) => mb_strlen($t) > 3 && !in_array($t, $stop, true));
        return array_values(array_filter($slas, function ($s) use ($toks) {
            $blob = mb_strtolower($s['name'] . ' ' . implode(' ', array_column($s['services'], 'name')));
            foreach ($toks as $t) {
                if (str_contains($blob, $t)) {
                    return true;
                }
            }
            return false;
        }));
    }

    // ------------------------------------------------------------------ mapa de problemas graves

    private static function sevmap(array $model, string $gid): array
    {
        $p = $model['period'];
        $now = time();
        $start = $p['start'];
        $end = min($p['end'], $now);
        $hs = array_filter($model['hosts'], fn ($h) => self::inGroups($h, $gid) && $h['status'] === 0);
        $sevs = array_values(array_filter(array_keys(self::SEVERITIES), fn ($s) => $s >= self::SEVMAP_MIN));
        $out = ['eventid', 'clock', 'severity', 'r_eventid', 'objectid'];
        $evs = self::zbx('event.get', ['output' => $out, 'source' => 0, 'object' => 0, 'value' => 1, 'groupids' => self::gids($gid), 'severities' => $sevs,
            'time_from' => $start - 7 * 86400, 'time_till' => $end, 'selectHosts' => ['hostid'], 'sortfield' => ['clock'], 'sortorder' => 'ASC', 'limit' => 50000]);
        $seen = array_flip(array_column($evs, 'eventid'));
        try {
            $opn = self::zbx('problem.get', ['output' => ['eventid'], 'groupids' => self::gids($gid), 'severities' => $sevs, 'recent' => false]);
            $miss = array_values(array_filter(array_column($opn, 'eventid'), fn ($id) => !isset($seen[$id])));
            foreach (array_chunk($miss, 500) as $part) {
                $evs = array_merge($evs, self::zbx('event.get', ['output' => $out, 'eventids' => $part, 'selectHosts' => ['hostid']]));
            }
        } catch (RuntimeException) {
        }
        $rmap = self::recoveries($evs);
        $mon = self::monitored(array_column(array_filter($evs, fn ($e) => !self::closed($e)), 'objectid'));
        $per = [];
        foreach ($evs as $e) {
            $s0 = (int) $e['clock'];
            $closed = self::closed($e);
            if (!$closed && !isset($mon[$e['objectid']])) {
                continue; // problema "preso" em trigger desativado: o Zabbix não mostra
            }
            $e0 = $closed ? ($rmap[$e['r_eventid']] ?? $s0 + 60) : $now;
            if ($e0 <= $start || $s0 >= $end) {
                continue;
            }
            foreach ($e['hosts'] ?? [] as $h) {
                if (isset($hs[$h['hostid']])) {
                    $per[$h['hostid']][] = [max($s0, $start), min($e0, $end), (int) $e['severity']];
                }
            }
        }
        $rows = [];
        foreach ($hs as $hid => $h) {
            $ivs = $per[$hid] ?? [];
            $allm = self::merge(array_map(fn ($x) => [$x[0], $x[1]], $ivs));
            $tot = 0;
            foreach ($allm as [$a, $b]) {
                $tot += $b - $a;
            }
            $cells = [];
            foreach ($model['buckets'] as [$a, $b]) {
                $bb = min($b, $end);
                if ($bb <= $a) {
                    $cells[] = null;
                    continue;
                }
                $mx = 0;
                foreach ($ivs as [$x0, $x1, $sv]) {
                    if ($x0 < $bb && $x1 > $a) {
                        $mx = max($mx, $sv);
                    }
                }
                $cells[] = [self::overlap($allm, $a, $bb), $mx];
            }
            $rows[] = [$tot, $h['name'], $cells];
        }
        usort($rows, fn ($a, $b) => [-$a[0], mb_strtolower($a[1])] <=> [-$b[0], mb_strtolower($b[1])]);
        $rows = array_slice($rows, 0, 40);
        $cells = [];
        foreach ($rows as $y => $r) {
            foreach ($r[2] as $x => $c) {
                if ($c !== null) {
                    $cells[] = [$x, $y, $c[0], $c[1]];
                }
            }
        }
        return ['hosts' => array_column($rows, 1), 'total' => array_column($rows, 0), 'cells' => $cells, 'min_sev' => self::SEVMAP_MIN];
    }

    // ------------------------------------------------------------------ topologia

    private const TOPO_TIERS = [['link', 'Links de internet'], ['firewall', 'Firewalls'], ['switch', 'Switches'],
        ['fisico', 'Servidores físicos e virtualização'], ['vm', 'Servidores virtuais'], ['web', 'Serviços web'], ['outros', 'Outros dispositivos']];
    private const LINK_RENAMES = ['Operadora Claro' => 'VPN Logística'];
    private const FAP_STATE = ['0' => 'outro', '1' => 'offline', '2' => 'online', '3' => 'baixando firmware', '4' => 'firmware conectado', '5' => 'standby'];

    private static function tier(array $h): string
    {
        $n = strtoupper(trim($h['name']));
        $pre = str_contains($n, '|') ? trim(explode('|', $n)[0]) : '';
        return match (true) {
            $pre === 'FW' => 'firewall',
            $pre === 'SW' => 'switch',
            $pre === 'SRV' || str_contains($n, 'ESXI') || str_contains($n, 'IDRAC') || (bool) preg_match('/^ADVSERVER01[34]\b/', $n) => 'fisico',
            $pre === 'VM' || str_starts_with($n, 'VM|') || str_starts_with($n, 'VM ') => 'vm',
            $pre === 'WEB' => 'web',
            default => 'outros',
        };
    }

    private static function topology(array $model, string $gid): array
    {
        $nodes = [];
        foreach ($model['hosts'] as $h) {
            if (!self::inGroups($h, $gid) || $h['status'] !== 0) {
                continue;
            }
            $worst = -1;
            foreach ($h['problems'] as $sv => $c) {
                if ($c) {
                    $worst = max($worst, (int) $sv);
                }
            }
            $nodes[] = ['id' => $h['id'], 'name' => $h['name'], 'tier' => self::tier($h), 'state' => $h['state'], 'problems' => array_sum($h['problems']),
                'worst' => $worst, 'avail' => round($h['avail'], 3), 'open_since' => $h['open_since']];
        }
        if ($nodes) {
            $ids = array_column($nodes, 'id');
            $inv = [];
            foreach (self::zbx('host.get', ['hostids' => $ids, 'output' => ['hostid'], 'selectInventory' => ['os', 'model', 'vendor', 'hardware', 'type']]) as $h) {
                $inv[$h['hostid']] = is_array($h['inventory'] ?? null) && !array_is_list($h['inventory']) ? $h['inventory'] : [];
            }
            $desc = [];
            foreach (self::zbx('item.get', ['hostids' => $ids, 'output' => ['hostid', 'lastvalue'], 'monitored' => true, 'searchByAny' => true,
                'search' => ['key_' => ['system.sw.os', 'sysDescr', 'system.descr', 'system.uname']]]) as $i) {
                if (($i['lastvalue'] ?? '') !== '' && !isset($desc[$i['hostid']])) {
                    $desc[$i['hostid']] = mb_substr($i['lastvalue'], 0, 200);
                }
            }
            // uptime: o melhor item de cada host (agente, SNMP do equipamento ou VMware)
            $pri = fn ($k) => $k === 'system.uptime' ? 0 : (stripos($k, 'fgsysuptime') !== false ? 1 : (str_starts_with($k, 'vmware.hv.uptime') ? 2
                : ((str_starts_with($k, 'system.net.uptime') || str_starts_with($k, 'system.uptime[')) ? 3 : (str_starts_with($k, 'system.hw.uptime') ? 4 : 9))));
            $best = [];
            foreach (self::zbx('item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'units'], 'monitored' => true,
                'search' => ['key_' => 'uptime']]) as $i) {
                $k = $i['key_'];
                if (preg_match('/mysql|wtp|zabbix\[/i', $k) || !in_array($i['units'] ?? '', ['uptime', 's'], true)) {
                    continue;
                }
                $val = is_numeric($i['lastvalue'] ?? null) ? (float) $i['lastvalue'] : 0;
                if ($val <= 0) {
                    continue;
                }
                $cur = $best[$i['hostid']] ?? null;
                if ($cur === null || $pri($k) < $cur[0]) {
                    $best[$i['hostid']] = [$pri($k), $val, self::int($i['lastclock'] ?? null)];
                }
            }
            foreach ($nodes as &$n) {
                $v = $inv[$n['id']] ?? [];
                $b = $best[$n['id']] ?? null;
                $ends = array_filter(array_column($model['events'][$n['id']] ?? [], 'e'));
                $n += ['os' => $v['os'] ?? '', 'model' => $v['model'] ?? '', 'vendor' => $v['vendor'] ?? '',
                    'hw' => ($v['hardware'] ?? '') ?: ($v['type'] ?? ''), 'descr' => $desc[$n['id']] ?? '',
                    'uptime' => $b ? (int) round($b[1]) : null, 'uptime_at' => $b[2] ?? null,
                    'since' => $ends && $n['state'] !== 'down' ? max($ends) : null];
            }
            unset($n);
        }
        $fws = array_values(array_filter($nodes, fn ($n) => $n['tier'] === 'firewall'));
        $links = [];
        if ($fws) {
            foreach (self::zbx('item.get', ['hostids' => array_column($fws, 'id'), 'output' => ['hostid', 'name', 'key_', 'lastvalue', 'lastclock'],
                'search' => ['name' => 'Operational status'], 'monitored' => true]) as $i) {
                $low = mb_strtolower($i['name']);
                if (preg_match('/fortilink|vlink|vpn|ssl\./', $low) || !preg_match('/wan\d*\(|link|operadora|americanet|internet/', $low)) {
                    continue;
                }
                $lab = preg_replace('/^Interface\s+|:\s*Operational status$/i', '', $i['name']);
                foreach (self::LINK_RENAMES as $de => $para) { // nome no mapa diferente do apelido no FortiGate
                    $lab = preg_replace('/' . preg_quote($de, '/') . '/i', $para, $lab);
                }
                $idx = preg_match('/\.(\d+)\]$/', (string) ($i['key_'] ?? ''), $m) ? $m[1] : null;
                $links[] = ['fw' => $i['hostid'], 'name' => $lab, 'up' => ($i['lastvalue'] ?? '') === '1', 'value' => $i['lastvalue'] ?? null,
                    'clock' => self::int($i['lastclock'] ?? null), 'idx' => $idx];
            }
            foreach ($links as &$l) { // consumo do link (agora e no horário comercial)
                $l['uso'] = $l['idx'] ? self::cached("linkuso|{$l['fw']}|{$l['idx']}", 120, fn () => self::linkUsage($l['fw'], $l['idx'], $l['name']), false) : null;
            }
            unset($l);
            $links = self::mergeLinks($links);
        }
        return ['tiers' => array_map(fn ($t) => ['id' => $t[0], 'label' => $t[1]], self::TOPO_TIERS), 'nodes' => $nodes, 'links' => $links,
            'aps' => $fws ? self::aps($fws) : [], 'generated' => time()];
    }

    private static function local(int $ts): DateTime
    {
        return (new DateTime('@' . $ts))->setTimezone(self::tz());
    }

    /** dia da semana: 0 = segunda … 6 = domingo (como no Python). */
    private static function weekday(DateTime $d): int
    {
        return ((int) $d->format('N')) - 1;
    }

    /** Horário comercial: hoje (até agora) se já começou; senão o último dia útil inteiro. */
    private static function bizWindow(int $ref): ?array
    {
        for ($back = 0; $back < 8; $back++) {
            $day = self::local($ref)->setTime(0, 0)->modify("-$back days");
            if (!in_array(self::weekday($day), self::BIZ_DAYS, true)) {
                continue;
            }
            $a = (clone $day)->setTime(self::BIZ_START, 0)->getTimestamp();
            $b = (clone $day)->setTime(self::BIZ_END, 0)->getTimestamp();
            if ($a < $ref) {
                return [$a, min($b, $ref), $back === 0 && $ref < $b];
            }
        }
        return null;
    }

    private static function linkUsage(string $hostid, string $idx, string $name): ?array
    {
        $its = self::zbx('item.get', ['hostids' => [$hostid], 'output' => ['itemid', 'key_', 'lastvalue', 'value_type'],
            'filter' => ['key_' => ["net.if.in[ifHCInOctets.$idx]", "net.if.out[ifHCOutOctets.$idx]", "net.if.speed[ifHighSpeed.$idx]"]]]);
        $by = [];
        foreach ($its as $i) {
            $by[explode('[', $i['key_'])[0]] = $i;
        }
        if (!isset($by['net.if.in'])) {
            return null;
        }
        $cap = preg_match('/(\d+(?:[.,]\d+)?)\s*(G|M)B/i', $name, $m) ? (float) str_replace(',', '.', $m[1]) * (strtoupper($m[2]) === 'G' ? 1e9 : 1e6) : null;
        $out = ['agora' => ['in' => self::int($by['net.if.in']['lastvalue'] ?? null), 'out' => self::int($by['net.if.out']['lastvalue'] ?? null)],
            'capacidade' => $cap, 'capacidade_txt' => $cap ? 'contratada' : 'porta', 'porta' => self::int($by['net.if.speed']['lastvalue'] ?? null),
            'horario' => sprintf('%02dh–%02dh', self::BIZ_START, self::BIZ_END), 'dias' => self::BIZ_DAYS];
        $now = time();
        if ($win = self::bizWindow($now)) {
            [$a, $b, $hoje] = $win;
            $serie = $stat = [];
            foreach (['in', 'out'] as $d) {
                $it = $by['net.if.' . $d] ?? null;
                if (!$it) {
                    continue;
                }
                $h = self::zbx('history.get', ['itemids' => [$it['itemid']], 'history' => (int) ($it['value_type'] ?? 3), 'time_from' => $a, 'time_till' => $b,
                    'output' => ['clock', 'value'], 'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => 5000]);
                $vals = array_map(fn ($x) => (float) $x['value'], $h);
                $mx = $vals ? max($vals) : null;
                $stat[$d] = ['media' => $vals ? (int) round(array_sum($vals) / count($vals)) : null, 'pico' => $vals ? (int) round($mx) : null,
                    'pico_em' => $vals ? (int) $h[array_search($mx, $vals, true)]['clock'] : null];
                foreach ($h as $x) { // série de 15 em 15 min
                    $serie[intdiv((int) $x['clock'], 900) * 900][$d][] = (float) $x['value'];
                }
            }
            ksort($serie);
            $mean = fn ($v) => (int) round(array_sum($v ?: [0]) / max(1, count($v ?: [0])));
            $out['comercial'] = ['de' => $a, 'ate' => $b, 'hoje' => $hoje] + $stat + ['serie' => array_map(fn ($k, $v) => ['t' => $k,
                'in' => $mean($v['in'] ?? []), 'out' => $mean($v['out'] ?? [])], array_keys($serie), $serie)];
        }
        // últimos 7 dias úteis no horário comercial (médias e máximos por hora)
        $sem = [];
        foreach (['in', 'out'] as $d) {
            $it = $by['net.if.' . $d] ?? null;
            if (!$it) {
                continue;
            }
            $tr = self::zbx('trend.get', ['itemids' => [$it['itemid']], 'time_from' => $now - 10 * 86400, 'time_till' => $now, 'output' => ['clock', 'value_avg', 'value_max']]);
            $sel = array_values(array_filter($tr, function ($t) {
                $dt = self::local((int) $t['clock']);
                $hr = (int) $dt->format('G');
                return in_array(self::weekday($dt), self::BIZ_DAYS, true) && self::BIZ_START <= $hr && $hr < self::BIZ_END;
            }));
            $dias = array_unique(array_map(fn ($t) => self::local((int) $t['clock'])->format('Y-m-d'), $sel));
            sort($dias);
            $dias = array_slice($dias, -7);
            $sel = array_values(array_filter($sel, fn ($t) => in_array(self::local((int) $t['clock'])->format('Y-m-d'), $dias, true)));
            if ($sel) {
                $mx = $sel[0];
                foreach ($sel as $t) {
                    if ((float) $t['value_max'] > (float) $mx['value_max']) {
                        $mx = $t;
                    }
                }
                $sem[$d] = ['media' => (int) round(array_sum(array_map(fn ($t) => (float) $t['value_avg'], $sel)) / count($sel)),
                    'pico' => (int) round((float) $mx['value_max']), 'pico_em' => (int) $mx['clock']];
            }
            $sem['dias'] = count($dias);
        }
        $out['semana'] = self::obj($sem);
        return $out;
    }

    /** A mesma operadora como interface lógica (velocidade no nome) e porta física wanX (tráfego): um link só. */
    private static function mergeLinks(array $links): array
    {
        $prov = function ($l) {
            $desc = preg_match('/\(([^)]*)\)/', $l['name'], $m) ? strtoupper($m[1]) : '';
            $toks = array_values(array_filter(preg_split('/[^A-Z]+/', $desc), fn ($t) => $t !== '' && !in_array($t, ['LINK', 'MB', 'GB', 'IT', 'OPERADORA'], true)));
            return $toks[0] ?? null;
        };
        $by = $out = [];
        foreach ($links as $l) {
            $p = $prov($l);
            if ($p) {
                $by[$l['fw'] . '|' . $p][] = $l;
            } else {
                $out[] = $l;
            }
        }
        foreach ($by as $grp) {
            if (count($grp) === 1) {
                $out[] = $grp[0];
                continue;
            }
            $comCap = null;
            foreach ($grp as $l) {
                if (!empty($l['uso']['capacidade'])) {
                    $comCap = $l;
                    break;
                }
            }
            $score = fn ($l) => ($l['uso']['agora']['in'] ?? 0) + ($l['uso']['comercial']['in']['media'] ?? 0);
            $fisico = $grp[0];
            foreach ($grp as $l) {
                if ($score($l) > $score($fisico)) {
                    $fisico = $l;
                }
            }
            $m = $fisico;
            if ($comCap && $comCap !== $fisico) {
                $m['name'] = $comCap['name'];
                $m['porta'] = preg_replace('/\(.*$/', '', $fisico['name']);
                if (!empty($m['uso'])) {
                    $m['uso'] = array_merge($m['uso'], ['capacidade' => $comCap['uso']['capacidade'], 'capacidade_txt' => 'contratada']);
                }
            }
            $m['up'] = $fisico['up'] ? !in_array(false, array_column($grp, 'up'), true) : false;
            $out[] = $m;
        }
        return $out;
    }

    /** FortiAP dos firewalls com nome no trigger "FortiAP <nome> in <firewall> is DOWN". */
    private static function aps(array $fws): array
    {
        $ids = array_column($fws, 'id');
        $aps = [];
        foreach (self::zbx('trigger.get', ['hostids' => $ids, 'output' => ['triggerid', 'description', 'value', 'status', 'priority'], 'expandDescription' => true,
            'search' => ['description' => 'FortiAP'], 'selectItems' => ['key_'], 'filter' => ['status' => 0]]) as $t) {
            if (!preg_match('/^FortiAP (.+?) in (.+) is DOWN$/', trim($t['description']), $m)) {
                continue;
            }
            $sn = null;
            foreach (array_column($t['items'] ?? [], 'key_') as $k) {
                if (str_starts_with($k, 'fgWcWtpSessionConnectionState[') && preg_match('/\[(\w+)\]/', $k, $s)) {
                    $sn = $s[1];
                    break;
                }
            }
            if (!$sn || preg_match('/^FP\w{10,}$/', $m[1])) {
                continue;
            }
            $aps[$sn] = ['name' => trim($m[1]), 'serial' => $sn, 'fw' => trim($m[2]), 'down' => $t['value'] === '1',
                'priority' => (int) ($t['priority'] ?? 0), 'triggerid' => $t['triggerid']];
        }
        if (!$aps) {
            return [];
        }
        foreach (self::zbx('item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'], 'search' => ['key_' => 'fgWcWtpSession'], 'monitored' => true]) as $i) {
            if (!preg_match('/\[(\w+)\]/', $i['key_'], $sm) || !isset($aps[$sm[1]])) {
                continue;
            }
            $ap = &$aps[$sm[1]];
            $k = $i['key_'];
            $v = $i['lastvalue'] ?? null;
            if (str_starts_with($k, 'fgWcWtpSessionConnectionState[')) {
                $ap['state'] = $v;
                $ap['state_txt'] = self::FAP_STATE[(string) $v] ?? (string) $v;
                $ap['fwid'] = $i['hostid'];
            } elseif (str_contains($k, 'WtpSessionUpTime')) {
                $ap['uptime'] = self::int($v);
                $ap['uptime_at'] = self::int($i['lastclock'] ?? null);
            } elseif (str_contains($k, 'WtpCpuUsage')) {
                $ap['cpu'] = self::int($v);
            } elseif (str_contains($k, 'WtpMemoryUsage')) {
                $ap['mem'] = self::int($v);
            } elseif (str_contains($k, 'WtpStationCount')) { // clientes wi-fi conectados
                $ap['clients'] = self::int($v);
            }
            unset($ap);
        }
        $out = [];
        foreach ($aps as $ap) {
            $ap['up'] = !$ap['down'] && in_array($ap['state'] ?? null, ['2', '4'], true);
            $out[] = $ap;
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    // ------------------------------------------------------------------ patches dos servidores Windows

    private const PATCH_KEYS = [
        'hotfix' => 'wmi.getall[root\\cimv2,"SELECT HotFixID,InstalledOn FROM Win32_QuickFixEngineering"]',
        'ubr'    => 'registry.data["HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion",UBR]',
        'reboot' => 'registry.get["HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\WindowsUpdate\\Auto Update\\RebootRequired"]',
        'wu'     => 'service.info[wuauserv,startup]',
    ];
    private const WIN_BUILDS = [9600 => '2012 R2', 14393 => '2016', 17763 => '2019', 20348 => '2022', 26100 => '2025'];
    private const WIN_EOL = ['2012 R2' => '2023-10-10', '2016' => '2027-01-12', '2019' => '2029-01-09', '2022' => '2031-10-14', '2025' => '2034-10-10', 'Windows 10' => '2025-10-14'];

    /** 2ª terça-feira do mês, ao meio-dia. */
    private static function patchTuesday(int $y, int $m): int
    {
        $first = new DateTime(sprintf('%04d-%02d-01 12:00:00', $y, $m), self::tz());
        $wd = self::weekday($first);
        return (clone $first)->modify('+' . ((1 - $wd + 7) % 7 + 7) . ' days')->getTimestamp();
    }

    private static function patchTuesdays(int $now, int $n = 14): array
    {
        $out = [];
        $d = self::local($now);
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n');
        while (count($out) < $n) {
            $pt = self::patchTuesday($y, $m);
            if ($pt <= $now) {
                $out[] = $pt;
            }
            if (--$m === 0) {
                $y--;
                $m = 12;
            }
        }
        return $out;
    }

    private static function nextPatchTuesday(int $now): ?int
    {
        $d = self::local($now);
        for ($add = 0; $add < 2; $add++) {
            $mm = (int) $d->format('n') + $add - 1;
            $pt = self::patchTuesday((int) $d->format('Y') + intdiv($mm, 12), $mm % 12 + 1);
            if ($pt > $now) {
                return $pt;
            }
        }
        return null;
    }

    private static function hotfixDate(mixed $v): ?int
    {
        $s = explode(' ', (string) $v)[0];
        foreach (['m/d/Y', 'd/m/Y', 'Ymd', 'Y-m-d'] as $fmt) {
            $d = DateTime::createFromFormat('!' . $fmt, $s, self::tz());
            if ($d && $d->format($fmt) === $s) {
                return $d->getTimestamp();
            }
        }
        return null;
    }

    private static function patches(string $gid): array
    {
        $now = time();
        $hosts = self::zbx('host.get', ['groupids' => self::gids($gid), 'output' => ['hostid', 'name', 'status'], 'filter' => ['status' => 0],
            'selectInterfaces' => ['available', 'type']]);
        $ids = array_column($hosts, 'hostid');
        $osTxt = $up = $extra = [];
        if ($ids) {
            foreach (self::zbx('item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'], 'monitored' => true,
                'filter' => ['key_' => ['system.sw.os', 'system.uname', 'system.uptime']]]) as $i) {
                if ($i['key_'] === 'system.uptime') {
                    $up[$i['hostid']] = $i;
                } elseif (stripos((string) ($i['lastvalue'] ?? ''), 'windows') !== false || !isset($osTxt[$i['hostid']])) {
                    $osTxt[$i['hostid']] = $i;
                }
            }
            foreach (self::zbx('item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'state', 'error'],
                'filter' => ['key_' => array_values(self::PATCH_KEYS)]]) as $i) {
                $k = array_search($i['key_'], self::PATCH_KEYS, true);
                if ($k !== false) {
                    $extra[$i['hostid']][$k] = $i;
                }
            }
        }
        $pts = self::patchTuesdays($now);
        $curPt = $now - $pts[0] >= self::PATCH_GRACE_DAYS * 86400 ? $pts[0] : $pts[1]; // o patch que já devia estar aplicado
        $out = [];
        foreach ($hosts as $h) {
            $o = (string) ($osTxt[$h['hostid']]['lastvalue'] ?? '');
            $ex = $extra[$h['hostid']] ?? [];
            if (stripos($o, 'windows') === false && !$ex) {
                continue;
            }
            $build = (preg_match('/10\.0\.(\d{4,5})/', $o, $mb) || preg_match('/\b(9600|7601)\b/', $o, $mb)) ? (int) $mb[1] : null;
            $ver = preg_match('/Windows Server \d{4}(?: R2)?/', $o, $mv) ? $mv[0]
                : ($build && isset(self::WIN_BUILDS[$build]) ? 'Windows Server ' . self::WIN_BUILDS[$build]
                : ($build && $build >= 19041 && $build <= 19045 ? 'Windows 10' : ($build && $build >= 22000 ? 'Windows 11' : ($o !== '' ? 'Windows' : ''))));
            $eolK = $ver === 'Windows 10' ? 'Windows 10' : str_replace('Windows Server ', '', $ver);
            $eol = isset(self::WIN_EOL[$eolK]) ? (new DateTime(self::WIN_EOL[$eolK] . ' 00:00:00', self::tz()))->getTimestamp() : null;
            $u = $up[$h['hostid']] ?? null;
            $boot = $u && is_numeric($u['lastvalue'] ?? null) && (float) $u['lastvalue'] > 0 ? $now - (float) $u['lastvalue'] : null;
            $ubr = ($ex['ubr']['state'] ?? '') === '0' ? ($ex['ubr']['lastvalue'] ?? null) : null;
            $kb = $kbAt = null;
            $hf = $ex['hotfix'] ?? null;
            if ($hf && ($hf['state'] ?? '') === '0' && ($hf['lastvalue'] ?? '') !== '') {
                $rows = json_decode($hf['lastvalue'], true);
                foreach (is_array($rows) ? $rows : [] as $r) {
                    $dt = self::hotfixDate($r['InstalledOn'] ?? null);
                    if ($dt && ($kbAt === null || $dt > $kbAt)) {
                        [$kb, $kbAt] = [$r['HotFixID'] ?? null, $dt];
                    }
                }
            }
            $reboot = isset($ex['reboot']) ? ($ex['reboot']['state'] ?? '') === '0' : null; // chave existe = Windows Update pedindo reinício
            $wu = ($ex['wu']['state'] ?? '') === '0' ? ($ex['wu']['lastvalue'] ?? null) : null;
            [$ref, $fonte] = $kbAt ? [$kbAt, 'kb'] : ($boot ? [$boot, 'reinicio'] : [null, null]);
            $ciclos = $ref === null ? null : count(array_filter($pts, fn ($pt) => $ref < $pt && $pt <= $curPt));
            $situacao = $ref === null ? 'sem_dados' : ($ciclos ? 'atrasado' : ($ref >= $pts[0] ? 'em_dia' : 'janela'));
            $agents = array_filter($h['interfaces'] ?? [], fn ($x) => (string) ($x['type'] ?? '') === '1');
            $out[] = ['id' => $h['hostid'], 'name' => $h['name'], 'os' => mb_substr($o, 0, 200), 'versao' => $ver, 'build' => $build, 'ubr' => $ubr,
                'alvo' => (bool) ($build && $build >= self::PATCH_TARGET_BUILD), 'boot' => $boot, 'kb' => $kb, 'kb_em' => $kbAt, 'fonte' => $fonte,
                'ciclos' => $ciclos, 'situacao' => $situacao, 'fim_suporte' => $eol, 'sem_suporte' => (bool) ($eol && $eol < $now),
                'reinicio_pendente' => $reboot, 'wu_desativado' => $wu !== null ? $wu === '4' : null,
                'agente_ok' => $agents ? (bool) array_filter($agents, fn ($x) => (string) ($x['available'] ?? '') === '1') : null];
        }
        usort($out, function ($a, $b) {
            $k = fn ($r) => [-(int) $r['sem_suporte'], -($r['ciclos'] ?? 99), ['janela' => 0, 'em_dia' => 1][$r['situacao']] ?? -1, mb_strtolower($r['name'])];
            return $k($a) <=> $k($b);
        });
        return ['hosts' => $out, 'patch_tuesday' => $curPt, 'ultimo_pt' => $pts[0], 'proximo' => self::nextPatchTuesday($now),
            'carencia_dias' => self::PATCH_GRACE_DAYS, 'alvo_build' => self::PATCH_TARGET_BUILD,
            'alvo_txt' => 'Windows Server ' . (self::WIN_BUILDS[self::PATCH_TARGET_BUILD] ?? self::PATCH_TARGET_BUILD),
            'coleta_detalhada' => count(array_filter($out, fn ($r) => $r['fonte'] === 'kb' || $r['ubr'] || $r['reinicio_pendente'] !== null)),
            'chaves' => self::PATCH_KEYS, 'generated' => $now];
    }

    // ------------------------------------------------------------------ hardware do servidor físico (iDRAC + VMware)

    private const DELL_ST = ['1' => 'outro', '2' => 'desconhecido', '3' => 'ok', '4' => 'atenção', '5' => 'crítico', '6' => 'irrecuperável'];
    private const DELL_MEDIA = ['1' => 'desconhecido', '2' => 'HDD', '3' => 'SSD'];
    private const DELL_RAID = ['1' => 'outro', '2' => 'RAID 0', '3' => 'RAID 1', '4' => 'RAID 5', '5' => 'RAID 6', '6' => 'RAID 10', '7' => 'RAID 50', '8' => 'RAID 60', '9' => 'RAID 1 concat.', '10' => 'RAID 5 concat.'];
    private const DELL_VD = ['1' => 'desconhecido', '2' => 'online', '3' => 'falhou', '4' => 'degradado'];
    private const DELL_VDOP = ['1' => 'normal', '2' => 'reconstruindo', '3' => 'ressincronizando', '4' => 'inicializando', '5' => 'inicialização em segundo plano'];
    private const DELL_RD = ['1' => 'sem leitura antecipada', '2' => 'leitura antecipada', '3' => 'leitura antecipada adaptativa'];
    private const DELL_WR = ['1' => 'write-through', '2' => 'write-back', '3' => 'write-back forçado'];
    private const DELL_BAT = ['1' => 'desconhecido', '2' => 'pronta', '3' => 'falhou', '4' => 'degradada', '5' => 'ausente', '6' => 'carregando', '7' => 'abaixo do limite'];
    private const VM_ST = ['0' => 'cinza (sem dados)', '1' => 'verde (ok)', '2' => 'amarelo (atenção)', '3' => 'vermelho (crítico)'];

    private static function map(array $m, mixed $v): mixed
    {
        return $m[(string) $v] ?? $v;
    }

    private static function hw(array $ids): array
    {
        $hosts = [];
        foreach (self::zbx('host.get', ['hostids' => $ids, 'output' => ['hostid', 'name']]) as $h) {
            $hosts[$h['hostid']] = $h['name'];
        }
        $by = [];
        foreach (self::zbx('item.get', ['hostids' => array_keys($hosts), 'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type'], 'monitored' => true]) as $i) {
            $by[$i['key_']][] = $i;
        }
        $one = function (string $prefix) use ($by) {
            foreach ($by as $k => $l) {
                if (str_starts_with($k, $prefix)) {
                    foreach ($l as $i) {
                        if (($i['lastvalue'] ?? '') !== '') {
                            return $i['lastvalue'];
                        }
                    }
                }
            }
            return null;
        };
        $num = fn ($v) => ($v === null || $v === '') ? null : (float) $v;
        $idx = fn ($k) => preg_match('/\.(\d+(?:\.\d+)*)\]$/', $k, $m) || preg_match('/\[(.*)\]$/', $k, $m) ? $m[1] : $k;
        $table = function (array $prefixes) use ($by, $idx) {
            $rows = [];
            foreach ($prefixes as $field => $pre) {
                foreach ($by as $k => $l) {
                    if (str_starts_with($k, $pre)) {
                        foreach ($l as $i) {
                            $rows[$idx($k)] ??= ['nome' => trim(explode(': ', $i['name'])[0])];
                            $rows[$idx($k)][$field] = $i['lastvalue'] ?? null;
                        }
                    }
                }
            }
            uksort($rows, fn ($a, $b) => strnatcmp((string) $a, (string) $b));
            return array_values($rows);
        };
        $pd = array_map(fn ($r) => array_merge($r, ['tipo' => self::map(self::DELL_MEDIA, $r['tipo'] ?? null), 'status' => self::map(self::DELL_ST, $r['status'] ?? null),
            'smart' => ($r['smart'] ?? null) === '1' ? 'alerta' : (($r['smart'] ?? null) === '0' ? 'ok' : ($r['smart'] ?? null)), 'tamanho' => $num($r['tamanho'] ?? null)]),
            $table(['modelo' => 'system.hw.physicaldisk.model[', 'tipo' => 'system.hw.physicaldisk.media_type[', 'tamanho' => 'system.hw.physicaldisk.size[',
                'status' => 'system.hw.physicaldisk.status[', 'smart' => 'system.hw.physicaldisk.smart_status[', 'serie' => 'system.hw.physicaldisk.serialnumber[',
                'part' => 'system.hw.physicaldisk.part_number[']));
        $vd = array_map(fn ($r) => array_merge($r, ['nome' => preg_replace('/^Disk \d+\((.*)\)$/', '$1', $r['nome']), 'raid' => self::map(self::DELL_RAID, $r['raid'] ?? null),
            'status' => self::map(self::DELL_VD, $r['status'] ?? null), 'estado' => self::map(self::DELL_VDOP, $r['estado'] ?? null),
            'leitura' => self::map(self::DELL_RD, $r['leitura'] ?? null), 'escrita' => self::map(self::DELL_WR, $r['escrita'] ?? null), 'tamanho' => $num($r['tamanho'] ?? null)]),
            $table(['raid' => 'system.hw.virtualdisk.layout[', 'tamanho' => 'system.hw.virtualdisk.size[', 'status' => 'system.hw.virtualdisk.status[',
                'estado' => 'system.hw.virtualdisk.state[', 'leitura' => 'system.hw.virtualdisk.readpolicy[', 'escrita' => 'system.hw.virtualdisk.writepolicy[']));
        $ds = [];
        foreach ($by as $k => $l) {
            if (!preg_match('/^vmware\.(?:hv\.)?datastore\.(size|read|write)\[(.*)\]$/', $k, $m) || !$l) {
                continue;
            }
            $parts = array_values(array_filter(explode(',', $m[2]), fn ($x) => !str_starts_with($x, '{')));
            if (!$parts) {
                continue;
            }
            $name = $parts[0];
            $sub = $parts[1] ?? null;
            $ds[$name] ??= ['nome' => $name];
            $v = $num($l[0]['lastvalue'] ?? null);
            if ($m[1] === 'size') {
                $ds[$name][$sub === 'pfree' ? 'livre_pct' : 'total'] = $v;
            } else {
                $ds[$name]['lat_' . ($m[1] === 'read' ? 'leitura' : 'escrita')] = $v;
            }
        }
        foreach ($ds as &$d) {
            if (isset($d['total'], $d['livre_pct'])) {
                $d['usado'] = $d['total'] * (1 - $d['livre_pct'] / 100);
            }
        }
        unset($d);
        ksort($ds);
        $fans = array_map(fn ($r) => array_merge($r, ['status' => self::map(self::DELL_ST, $r['status'] ?? null), 'rpm' => $num($r['rpm'] ?? null)]),
            $table(['rpm' => 'sensor.fan.speed[', 'status' => 'sensor.fan.status[']));
        $psus = array_map(fn ($r) => array_merge($r, ['status' => self::map(self::DELL_ST, $r['status'] ?? null)]), $table(['status' => 'sensor.psu.status[']));
        $temps = array_map(fn ($r) => array_merge($r, ['status' => self::map(self::DELL_ST, $r['status'] ?? null), 'valor' => $num($r['valor'] ?? null)]),
            $table(['valor' => 'sensor.temp.value[', 'status' => 'sensor.temp.status[']));
        $memT = $num($one('vmware.hv.hw.memory['));
        $memU = $num($one('vmware.hv.memory.used['));
        $trend = function (string $prefix) use ($by) { // 7 dias, média e pico por hora
            foreach ($by as $k => $l) {
                if (str_starts_with($k, $prefix) && $l) {
                    try {
                        $rs = self::zbx('trend.get', ['itemids' => [$l[0]['itemid']], 'time_from' => time() - 7 * 86400, 'output' => ['clock', 'value_avg', 'value_max']]);
                    } catch (RuntimeException) {
                        $rs = [];
                    }
                    usort($rs, fn ($a, $b) => (int) $a['clock'] <=> (int) $b['clock']);
                    return array_map(fn ($r) => ['t' => (int) $r['clock'], 'avg' => (float) $r['value_avg'], 'max' => (float) $r['value_max']], $rs);
                }
            }
            return [];
        };
        $memTr = $memT ? array_map(fn ($p) => ['t' => $p['t'], 'avg' => $p['avg'] / $memT * 100, 'max' => $p['max'] / $memT * 100], $trend('vmware.hv.memory.used[')) : [];
        return [
            'hosts' => array_map(fn ($k, $v) => ['id' => (string) $k, 'nome' => $v], array_keys($hosts), $hosts),
            'identificacao' => ['fabricante' => $one('vmware.hv.hw.vendor['), 'modelo' => $one('vmware.hv.hw.model[') ?? $one('system.hw.model'),
                'service_tag' => $one('system.hw.serialnumber'), 'firmware_idrac' => $one('system.hw.firmware'), 'bios_uuid' => $one('vmware.hv.hw.uuid['),
                'esxi' => $one('vmware.hv.fullname[') ?? $one('vmware.fullname['), 'saude_geral' => self::DELL_ST[(string) $one('system.status[')] ?? null,
                'status_vmware' => self::VM_ST[(string) $one('vmware.hv.status[')] ?? null, 'ligado_s' => $num($one('vmware.hv.uptime[')) ?: $num($one('system.net.uptime[')),
                'vms' => $num($one('vmware.hv.vm.num[')), 'energia_w' => $num($one('vmware.hv.power[{$VMWARE.URL},{$VMWARE.HV.UUID}]'))],
            'processador' => ['modelo' => $one('vmware.hv.hw.cpu.model['), 'nucleos' => $num($one('vmware.hv.hw.cpu.num[')), 'threads' => $num($one('vmware.hv.hw.cpu.threads[')),
                'frequencia_hz' => $num($one('vmware.hv.hw.cpu.freq[')), 'uso_pct' => $num($one('vmware.hv.cpu.usage.perf[')),
                'utilizacao_pct' => $num($one('vmware.hv.cpu.utilization[')), 'uso_hz' => $num($one('vmware.hv.cpu.usage[')),
                'temperaturas' => array_values(array_filter($temps, fn ($t) => str_contains(strtoupper($t['nome']), 'CPU'))), 'tendencia' => $trend('vmware.hv.cpu.usage.perf[')],
            'memoria' => ['total' => $memT, 'usada' => $memU, 'uso_pct' => $memT && $memU !== null ? $memU / $memT * 100 : null,
                'balloon' => $num($one('vmware.hv.memory.size.ballooned[')), 'tendencia' => $memTr],
            'discos' => ['controladora' => $one('system.hw.diskarray.model['), 'controladora_status' => self::DELL_ST[(string) $one('system.hw.diskarray.status[')] ?? null,
                'bateria_cache' => self::DELL_BAT[(string) $one('system.hw.diskarray.cache.battery.status[')] ?? null,
                'fisicos' => $pd, 'virtuais' => $vd, 'datastores' => array_values($ds)],
            'energia' => ['fontes' => $psus, 'ventoinhas' => $fans, 'temperaturas' => $temps],
            'generated' => microtime(true),
        ];
    }

    // ------------------------------------------------------------------ bancos de dados

    private const WIN_SVC_ST = ['0' => 'rodando', '1' => 'pausado', '2' => 'iniciando', '3' => 'pausando', '4' => 'retomando', '5' => 'parando', '6' => 'parado', '7' => 'desconhecido', '255' => 'não existe'];
    private const DB_SVC = [
        'SQL Server' => '/^(MSSQLSERVER|MSSQL\$|SQLSERVERAGENT|SQLAgent\$|SQLBrowser|SQLWriter|SQLTELEMETRY|SqlServerExtension|MSSQLFDLauncher|SSIS|MSSQLServerOLAP|ReportServer)/i',
        'PostgreSQL' => '/postgre/i',
        'MySQL'      => '/^(mysql|mariadb)/i',
    ];
    private const APP_SVC = '/totvs|tss|licen|license|protheus|\brm\b|w3svc|iis|apache|nginx|tomcat|incontrol/i';
    private const MYSQL_KEYS = [['mysql.version', 'Versão'], ['mysql.uptime', 'Ligado há'], ['mysql.ping', 'Responde (ping)'], ['mysql.queries.rate', 'Consultas/s'],
        ['mysql.questions.rate', 'Perguntas/s'], ['mysql.connections.rate', 'Conexões/s'], ['mysql.threads_connected', 'Conexões abertas'],
        ['mysql.threads_running', 'Threads em execução'], ['mysql.max_used_connections', 'Pico de conexões'], ['mysql.slow_queries.rate', 'Consultas lentas/s'],
        ['mysql.com_select.rate', 'SELECT/s'], ['mysql.com_insert.rate', 'INSERT/s'], ['mysql.com_update.rate', 'UPDATE/s'], ['mysql.com_delete.rate', 'DELETE/s'],
        ['mysql.buffer_pool_utilization', 'Uso do buffer pool (%)'], ['mysql.buffer_pool_efficiency', 'Eficiência do buffer pool (%)'],
        ['mysql.innodb_row_lock_waits', 'Esperas por bloqueio de linha'], ['mysql.aborted_connects.rate', 'Conexões abortadas/s'],
        ['mysql.created_tmp_disk_tables.rate', 'Tabelas temporárias em disco/s'], ['mysql.bytes_received.rate', 'Recebido (B/s)'], ['mysql.bytes_sent.rate', 'Enviado (B/s)']];

    private static function db(string $gid): array
    {
        $hosts = [];
        foreach (self::zbx('host.get', ['groupids' => self::gids($gid), 'output' => ['hostid', 'name'], 'filter' => ['status' => 0]]) as $h) {
            $hosts[$h['hostid']] = $h['name'];
        }
        if (!$hosts) {
            return ['hosts' => []];
        }
        $per = [];
        foreach (self::zbx('item.get', ['hostids' => array_keys($hosts), 'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'state', 'error'],
            'monitored' => true, 'searchByAny' => true, 'search' => ['key_' => ['service.info[', 'proc.num[', 'mysql.', 'pgsql.', 'mssql.', 'vfs.fs.size[', 'system.cpu.util',
                'vm.memory', 'perf_counter_en["\\PhysicalDisk', 'perf_counter_en["\\Paging file']]]) as $i) {
            $per[$i['hostid']][] = $i;
        }
        $out = [];
        foreach ($per as $hid => $l) {
            $eng = $svc = $app = [];
            foreach ($l as $i) {
                $k = $i['key_'];
                if (preg_match('/^service\.info\["?([^",\]]+)"?,state\]/', $k, $m)) {
                    $nm = $m[1];
                    $desc = preg_match('/\((.*)\)\s*$/', $i['name'], $dm) ? $dm[1] : '';
                    $row = ['nome' => $nm, 'descricao' => $desc, 'estado' => self::map(self::WIN_SVC_ST, $i['lastvalue'] ?? null), 'ok' => ($i['lastvalue'] ?? null) === '0'];
                    $hit = null;
                    foreach (self::DB_SVC as $e => $rx) {
                        if (preg_match($rx, $nm) || preg_match($rx, $desc)) {
                            $hit = $e;
                            break;
                        }
                    }
                    if ($hit) {
                        $eng[$hit] = true;
                        $svc[] = $row;
                    } elseif (preg_match(self::APP_SVC, "$nm $desc") && !preg_match('/lumu|sophos|zabbix/i', $nm)) {
                        $app[] = $row;
                    }
                }
                if (preg_match('/^proc\.num\[(mysqld|mariadbd|postgres|sqlservr)/', $k, $m)) {
                    $eng[['mysqld' => 'MySQL', 'mariadbd' => 'MySQL', 'postgres' => 'PostgreSQL', 'sqlservr' => 'SQL Server'][$m[1]]] = true;
                    $svc[] = ['nome' => "processo {$m[1]}", 'descricao' => $i['name'], 'estado' => ($i['lastvalue'] ?? '') . ' em execução',
                        'ok' => !in_array($i['lastvalue'] ?? '0', ['0', ''], true)];
                }
                foreach (['mysql.' => 'MySQL', 'pgsql.' => 'PostgreSQL', 'mssql.' => 'SQL Server'] as $pre => $e) {
                    if (str_starts_with($k, $pre)) {
                        $eng[$e] = true;
                    }
                }
            }
            // só o VSS Writer / telemetria do SQL não é banco: exige o serviço do motor
            $hasMs = (bool) array_filter($l, fn ($i) => str_starts_with($i['key_'], 'mssql.'));
            if (isset($eng['SQL Server']) && !$hasMs && !array_filter($svc, fn ($r) => preg_match('/^(MSSQLSERVER|MSSQL\$)/i', $r['nome']) || str_starts_with($r['nome'], 'processo sqlservr'))) {
                unset($eng['SQL Server']);
                $svc = array_values(array_filter($svc, fn ($r) => !preg_match('/^(SQL|Sql)/', $r['nome'])));
            }
            if (!$eng) {
                continue;
            }
            $g = function (string $key) use ($l) {
                foreach ($l as $i) {
                    if ($i['key_'] === $key) {
                        return $i;
                    }
                }
                return null;
            };
            $num = fn ($i) => $i && ($i['lastvalue'] ?? '') !== '' && ($i['state'] ?? '') === '0' ? (float) $i['lastvalue'] : null;
            $disks = $perf = [];
            foreach ($l as $i) {
                if (($i['state'] ?? '') !== '0') {
                    continue;
                }
                if (preg_match('/^vfs\.fs\.size\[([^,]+),(pused|total|used)\]$/', $i['key_'], $m)) {
                    $disks[$m[1]] ??= ['volume' => $m[1], 'nome' => trim(preg_replace('/:\s*Space utilization|:\s*Total space|:\s*Used space/', '', $i['name']))];
                    $disks[$m[1]][$m[2]] = (float) $i['lastvalue'];
                }
                if (preg_match('/^perf_counter_en\["\\\\PhysicalDisk\(([^)]+)\)\\\\(Avg\. Disk sec\/Read|Avg\. Disk sec\/Write|Current Disk Queue Length|% Idle Time)"/', $i['key_'], $m)) {
                    $f = ['Avg. Disk sec/Read' => 'leitura_ms', 'Avg. Disk sec/Write' => 'escrita_ms', 'Current Disk Queue Length' => 'fila', '% Idle Time' => 'utilizacao'][$m[2]];
                    $perf[$m[1]] ??= ['disco' => $m[1]];
                    $perf[$m[1]][$f] = (float) $i['lastvalue'] * (str_contains($m[2], 'sec/') ? 1000 : 1);
                }
            }
            $my = null;
            if (isset($eng['MySQL']) && array_filter($l, fn ($i) => str_starts_with($i['key_'], 'mysql.'))) {
                $st = null;
                foreach (['mysql.get_status_variables', 'mysql.ping'] as $pre) {
                    foreach ($l as $i) {
                        if (str_starts_with($i['key_'], $pre)) {
                            $st = $i;
                            break 2;
                        }
                    }
                }
                $metricas = [];
                foreach (self::MYSQL_KEYS as [$key, $lbl]) {
                    foreach ($l as $x) {
                        if (str_starts_with($x['key_'], $key)) {
                            if (($x['lastvalue'] ?? '') !== '') {
                                $metricas[] = ['nome' => $lbl, 'valor' => $x['lastvalue'], 'unidade' => $x['units'] ?? null];
                            }
                            break;
                        }
                    }
                }
                $my = ['coleta_ok' => (bool) ($st && ($st['state'] ?? '') === '0' && ($st['lastvalue'] ?? '') !== ''), 'erro' => $st['error'] ?? '', 'metricas' => $metricas];
            }
            // tendência de 7 dias: CPU, memória e ocupação de cada volume
            $tr = [];
            $series = [['cpu', $g('system.cpu.util')], ['memoria', $g('vm.memory.util') ?? $g('vm.memory.utilization')]];
            foreach (array_keys($disks) as $v) {
                $series[] = ["disco $v", $g("vfs.fs.size[$v,pused]")];
            }
            foreach ($series as [$nm, $it]) {
                if ($it) {
                    try {
                        $rs = self::zbx('trend.get', ['itemids' => [$it['itemid']], 'time_from' => time() - 7 * 86400, 'output' => ['clock', 'value_avg', 'value_max']]);
                        usort($rs, fn ($a, $b) => (int) $a['clock'] <=> (int) $b['clock']);
                        $tr[$nm] = array_map(fn ($r) => ['t' => (int) $r['clock'], 'avg' => (float) $r['value_avg'], 'max' => (float) $r['value_max']], $rs);
                    } catch (RuntimeException) {
                    }
                }
            }
            $sortOk = fn ($a, $b) => [$a['ok'], $a['nome']] <=> [$b['ok'], $b['nome']];
            usort($svc, $sortOk);
            usort($app, $sortOk);
            ksort($disks);
            ksort($perf);
            $engs = array_keys($eng);
            sort($engs);
            $mem = $num($g('vm.memory.util'));
            $out[] = ['id' => (string) $hid, 'name' => $hosts[$hid] ?? $hid, 'motores' => $engs, 'servicos' => $svc, 'servicos_app' => $app,
                'cpu' => $num($g('system.cpu.util')), 'memoria' => $mem ?? $num($g('vm.memory.utilization')), 'memoria_total' => $num($g('vm.memory.size[total]')),
                'agente2_falta' => (bool) ($my && str_contains($my['erro'] ?? '', 'Unsupported item key')),
                'swap' => $num($g('perf_counter_en["\\Paging file(_Total)\\% Usage"]')),
                'discos' => array_values($disks), 'desempenho_disco' => array_values($perf), 'mysql' => $my, 'tendencia' => self::obj($tr)];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return ['hosts' => $out, 'generated' => microtime(true)];
    }

    // ------------------------------------------------------------------ tendência

    private const TREND_LBL = ['cpu' => 'CPU', 'mem' => 'Memória', 'disk' => 'Disco', 'ping' => 'Latência', 'loss' => 'Perda'];

    private static function trendItems(string $metric, string $gid, string $hostid): array
    {
        $p = ['output' => ['itemid', 'hostid', 'key_', 'name', 'value_type'], 'monitored' => true, 'limit' => 2000];
        if ($hostid !== '') {
            $p['hostids'] = [$hostid];
        } else {
            $p['groupids'] = self::gids($gid);
        }
        if ($metric === 'disk') {
            $p['search'] = ['key_' => 'vfs.fs.size['];
            $its = array_filter(self::zbx('item.get', $p), fn ($i) => str_ends_with($i['key_'], ',pused]'));
        } else {
            $p['filter'] = ['key_' => self::METRIC_KEYS[$metric]];
            $its = self::zbx('item.get', $p);
        }
        return array_values(array_filter($its, fn ($i) => in_array($i['value_type'], ['0', '3'], true)));
    }

    /** Regressão linear da média: inclinação por dia, projeção em 30 dias e quando cruza 90% / 100%. */
    private static function analyse(array $pts, bool $pct): ?array
    {
        $n = count($pts);
        if ($n < 3) {
            return null;
        }
        $xs = array_map(fn ($p) => $p['t'] / 86400000, $pts);
        $ys = array_column($pts, 'avg');
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxx = $sxy = $syy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($ys[$i] - $my);
            $syy += ($ys[$i] - $my) ** 2;
        }
        $slope = $sxx ? $sxy / $sxx : 0.0;
        $b = $my - $slope * $mx;
        $fitNow = $slope * $xs[$n - 1] + $b;
        $r2 = $syy ? ($slope ** 2 * $sxx / $syy) : 0.0;
        $half = intdiv($n, 2);
        $a1 = array_sum(array_slice($ys, 0, $half)) / max(1, $half);
        $a2 = array_sum(array_slice($ys, $half)) / max(1, $n - $half);
        $srt = array_column($pts, 'max');
        sort($srt);
        $out = ['atual' => round($ys[$n - 1], 2), 'media' => round($my, 2), 'pico' => round(max(array_column($pts, 'max')), 2),
            'minimo' => round(min(array_column($pts, 'min')), 2), 'p95' => round($srt[(int) (0.95 * ($n - 1))], 2), 'inclinacao_dia' => round($slope, 4),
            'ajuste_inicio' => round($slope * $xs[0] + $b, 2), 'ajuste_fim' => round($fitNow, 2), 'projecao_30d' => round($fitNow + $slope * 30, 2),
            'variacao_metades' => round($a2 - $a1, 2), 'r2' => round($r2, 2), 'confiavel' => $r2 >= 0.3 && $n >= 24];
        if ($pct) {
            $out['projecao_30d'] = round(max(0.0, min(100.0, $out['projecao_30d'])), 2);
            foreach ([90, 100] as $lim) {
                $d = ($slope > 0 && $fitNow < $lim) ? round(($lim - $fitNow) / $slope, 1) : ($fitNow >= $lim ? 0 : null);
                $out["dias_ate_$lim"] = ($d !== null && $out['confiavel'] && $d <= 365) ? $d : ($d === 0 ? 0 : null);
            }
        }
        return $out;
    }

    private static function trendx(string $metric, int $hours, string $gid, string $hostid): array
    {
        $its = self::trendItems($metric, $gid, $hostid);
        $hosts = [];
        if ($its) {
            foreach (self::zbx('host.get', ['hostids' => array_values(array_unique(array_column($its, 'hostid'))), 'output' => ['hostid', 'name']]) as $h) {
                $hosts[$h['hostid']] = $h['name'];
            }
        }
        $from = time() - $hours * 3600;
        $k = $metric === 'ping' ? 1000.0 : 1.0;
        $per = [];
        foreach (array_chunk(array_column($its, 'itemid'), 100) as $part) {
            foreach (self::zbx('trend.get', ['output' => ['itemid', 'clock', 'value_avg', 'value_min', 'value_max'], 'itemids' => $part, 'time_from' => $from]) as $t) {
                $per[$t['itemid']][] = $t;
            }
        }
        $pct = in_array($metric, ['cpu', 'mem', 'disk', 'loss'], true);
        $minRows = max(3, intdiv($hours, 8));
        $rowsOf = function ($i) use ($per) {
            $rows = $per[$i['itemid']] ?? [];
            usort($rows, fn ($a, $b) => (int) $a['clock'] <=> (int) $b['clock']);
            return $rows;
        };
        $vol = fn ($i) => trim(preg_replace('/:\s*Space utilization$/', '', $i['name']));
        $series = [];
        if ($hostid !== '') { // um host: uma linha por item (no disco, uma por volume)
            foreach ($its as $i) {
                $rows = $rowsOf($i);
                if (count($rows) < $minRows) {
                    continue;
                }
                $pts = array_map(fn ($r) => ['t' => (int) $r['clock'] * 1000, 'avg' => round((float) $r['value_avg'] * $k, 2),
                    'min' => round((float) $r['value_min'] * $k, 2), 'max' => round((float) $r['value_max'] * $k, 2)], $rows);
                $series[] = ['nome' => $metric === 'disk' ? $vol($i) : self::TREND_LBL[$metric], 'pts' => $pts, 'analise' => self::analyse($pts, $pct)];
            }
            usort($series, fn ($a, $b) => ($b['analise']['atual'] ?? 0) <=> ($a['analise']['atual'] ?? 0));
        } else {
            $buckets = [];
            foreach ($per as $rows) {
                foreach ($rows as $r) {
                    $c = (int) $r['clock'];
                    $buckets[$c]['a'][] = (float) $r['value_avg'];
                    $buckets[$c]['n'][] = (float) $r['value_min'];
                    $buckets[$c]['x'][] = (float) $r['value_max'];
                }
            }
            ksort($buckets);
            $pts = [];
            foreach ($buckets as $c => $b) {
                $pts[] = ['t' => $c * 1000, 'avg' => round(array_sum($b['a']) / count($b['a']) * $k, 2), 'min' => round(min($b['n']) * $k, 2), 'max' => round(max($b['x']) * $k, 2)];
            }
            if ($pts) {
                $series[] = ['nome' => 'Média do grupo', 'pts' => $pts, 'analise' => self::analyse($pts, $pct)];
            }
            $top = []; // quem mais pesa: maior tendência de alta e maior valor atual
            foreach ($its as $i) {
                $rows = $rowsOf($i);
                if (count($rows) < $minRows) {
                    continue;
                }
                $a = self::analyse(array_map(fn ($r) => ['t' => (int) $r['clock'] * 1000, 'avg' => (float) $r['value_avg'] * $k,
                    'min' => (float) $r['value_min'] * $k, 'max' => (float) $r['value_max'] * $k], $rows), $pct);
                $row = ['host' => $hosts[$i['hostid']] ?? $i['hostid'], 'hostid' => $i['hostid'], 'volume' => $metric === 'disk' ? $vol($i) : ''];
                foreach (['atual', 'pico', 'inclinacao_dia', 'projecao_30d', 'confiavel', 'r2'] as $x) {
                    $row[$x] = $a[$x];
                }
                if ($pct) {
                    $row['dias_ate_90'] = $a['dias_ate_90'] ?? null;
                }
                $top[] = $row;
            }
            usort($top, fn ($a, $b) => ($b['confiavel'] ? $b['projecao_30d'] : $b['atual']) <=> ($a['confiavel'] ? $a['projecao_30d'] : $a['atual']));
            if ($series) {
                $series[0]['ranking'] = array_slice($top, 0, 12);
            }
        }
        $hl = array_map(fn ($h, $n) => ['id' => (string) $h, 'nome' => $n], array_keys($hosts), $hosts);
        usort($hl, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));
        return ['metrica' => $metric, 'nome' => self::TREND_LBL[$metric], 'unidade' => $metric === 'ping' ? ' ms' : '%', 'horas' => $hours,
            'host' => $hostid !== '' ? ($hosts[$hostid] ?? null) : null, 'series' => $series, 'hosts' => $hostid !== '' ? [] : $hl];
    }

    private static function trend(string $metric, int $hours, string $gid): array
    {
        $ids = array_column(array_filter(self::zbx('item.get', ['output' => ['itemid', 'value_type'], 'filter' => ['key_' => self::METRIC_KEYS[$metric]],
            'monitored' => true, 'limit' => 300, 'groupids' => self::gids($gid)]), fn ($i) => in_array($i['value_type'], ['0', '3'], true)), 'itemid');
        if (!$ids) {
            return [];
        }
        $buckets = [];
        foreach (array_chunk($ids, 100) as $part) {
            foreach (self::zbx('trend.get', ['output' => ['itemid', 'clock', 'value_avg', 'value_min', 'value_max'], 'itemids' => $part, 'time_from' => time() - $hours * 3600]) as $t) {
                $c = (int) $t['clock'];
                $buckets[$c]['a'][] = (float) $t['value_avg'];
                $buckets[$c]['n'][] = (float) $t['value_min'];
                $buckets[$c]['x'][] = (float) $t['value_max'];
            }
        }
        ksort($buckets);
        $k = $metric === 'ping' ? 1000.0 : 1.0;
        $out = [];
        foreach ($buckets as $c => $b) {
            $out[] = ['t' => $c * 1000, 'avg' => round(array_sum($b['a']) / count($b['a']) * $k, 2), 'min' => round(min($b['n']) * $k, 2), 'max' => round(max($b['x']) * $k, 2)];
        }
        return $out;
    }
}
