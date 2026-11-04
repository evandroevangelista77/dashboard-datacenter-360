<?php

namespace GlpiPlugin\Operacional360;

use Config;
use GLPIKey;

/**
 * Configuração do plugin (Configurar → Plugins → Operacional 360), só para quem
 * tem o direito de configuração do GLPI (administrador).
 *
 * O "mapa" liga cada entidade do GLPI a um ou mais host groups do Zabbix, e para
 * cada host group diz quais severidades aparecem no painel da entidade.
 */
class Settings
{
    public const CONTEXT = 'plugin:operacional360';

    /** Valores padrão (texto, como o Config do GLPI guarda). */
    public const DEFAULTS = [
        'zabbix_url'       => '',
        'zabbix_token'     => '',
        'zabbix_verify'    => '1',
        'zabbix_front'     => '',
        'entity_root'      => 'Root > NOC',
        'glpi_front'       => '',
        'severities'       => '5,4,2',
        'sla_hours'        => '6:1,5:2,4:4,3:8,2:24,1:48',
        'list_max'         => '400',
        'glpi_max_tickets' => '8000',
        'cache_seconds'    => '20',
        'mapping'          => '[]',
        'alerts'           => '{}',
    ];

    /** Rótulos das severidades do Zabbix. */
    public const SEVERITY_LABELS = [
        5 => 'Desastre', 4 => 'Alta', 3 => 'Média', 2 => 'Atenção', 1 => 'Informação', 0 => 'Não classificada',
    ];

    /** Tipos de alerta do navegador: [rótulo, grupo, ícone, cor, canais padrão, som padrão]. */
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

    /** Lê tudo, com o token do Zabbix descriptografado (o GLPI grava "secured configs" cifrados). */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $values = array_merge(self::DEFAULTS, array_map('strval', Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS))));
            if ($values['zabbix_token'] !== '') {
                $values['zabbix_token'] = (string) (new GLPIKey())->decrypt($values['zabbix_token']);
            }
            self::$cache = $values;
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) self::get($key)));
    }

    public static function isConfigured(): bool
    {
        return self::get('zabbix_url') !== '' && self::get('zabbix_token') !== '';
    }

    /** Severidades exibidas no painel (padrão: Desastre, Alta e Atenção). */
    public static function severities(): array
    {
        $out = [];
        foreach (explode(',', self::get('severities')) as $v) {
            $v = trim($v);
            if (ctype_digit($v) && isset(self::SEVERITY_LABELS[(int) $v])) {
                $out[(int) $v] = (int) $v;
            }
        }
        $out = array_values($out);
        rsort($out);
        return $out ?: [5, 4, 2];
    }

    /** Meta de solução por prioridade do GLPI (horas), no formato "prioridade:horas,...". */
    public static function slaHours(): array
    {
        $out = [];
        foreach (explode(',', self::get('sla_hours')) as $pair) {
            [$p, $h] = array_pad(explode(':', trim($pair), 2), 2, '');
            if (ctype_digit($p) && ctype_digit($h)) {
                $out[(int) $p] = (int) $h;
            }
        }
        return $out ?: [6 => 1, 5 => 2, 4 => 4, 3 => 8, 2 => 24, 1 => 48];
    }

    /** Entidade raiz do painel: só ela e as abaixo dela (NOC para baixo) podem entrar no mapa. */
    public static function entityRoot(): string
    {
        return trim(self::get('entity_root'));
    }

    /** A entidade está na raiz configurada (ela mesma ou uma filha). */
    public static function inEntityRoot(string $fullName): bool
    {
        $root = self::entityRoot();
        return $root === '' || $fullName === $root || str_starts_with($fullName, $root . ' > ');
    }

    /** Endereço do Zabbix para os links (padrão: o endereço da API sem "api_jsonrpc.php"). */
    public static function zabbixFront(): string
    {
        $front = self::get('zabbix_front');
        if ($front === '') {
            $front = preg_replace('#/api_jsonrpc\.php$#', '', self::get('zabbix_url'));
        }
        return rtrim((string) $front, '/');
    }

    /** Endereço do GLPI para os links de chamado (padrão: o endereço do próprio GLPI). */
    public static function glpiFront(): string
    {
        global $CFG_GLPI;
        $front = self::get('glpi_front') ?: ($CFG_GLPI['url_base'] ?? $CFG_GLPI['root_doc'] ?? '');
        return rtrim((string) $front, '/');
    }

    /** Alertas do navegador (canais, som, voz e pop-up), com o que foi salvo sobre o padrão. */
    public static function alerts(): array
    {
        $saved = json_decode(self::get('alerts') ?: '{}', true);
        $saved = is_array($saved) ? $saved : [];
        $tipos = [];
        foreach (self::ALERT_TYPES as $key => [, , , , $canais, $tom]) {
            $o = $saved['tipos'][$key] ?? [];
            $tipos[$key] = [
                'channels' => is_array($o['channels'] ?? null) ? array_values(array_intersect($o['channels'], ['flag', 'sound', 'popup', 'voice', 'sticky'])) : $canais,
                'tone'     => is_string($o['tone'] ?? null) ? $o['tone'] : $tom,
            ];
        }
        return [
            'tipos'           => $tipos,
            'poll'            => (int) ($saved['poll'] ?? 20),
            'volume'          => (int) ($saved['volume'] ?? 80),
            'voice_rate'      => (float) ($saved['voice_rate'] ?? 1.05),
            'popup_seconds'   => (int) ($saved['popup_seconds'] ?? 20),
            'popup_max'       => (int) ($saved['popup_max'] ?? 5),
            'mute_minutes'    => (int) ($saved['mute_minutes'] ?? 60),
            'sla_warn_minutes' => (int) ($saved['sla_warn_minutes'] ?? 60),
            'quiet'           => $saved['quiet'] ?? null,
        ];
    }

    /**
     * Mapa entidade → host group, normalizado: [entity_id, group_id, group_name, severities[]].
     * A ordem é a da configuração (o primeiro host group que casar dá o "cliente" do host).
     */
    public static function mapping(): array
    {
        $rows = json_decode(self::get('mapping') ?: '[]', true);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $eid = (int) ($r['entity_id'] ?? 0);
            $gid = trim((string) ($r['group_id'] ?? ''));
            if ($eid <= 0 || !ctype_digit($gid)) {
                continue;
            }
            $sev = array_values(array_intersect(array_map('intval', (array) ($r['severities'] ?? [])), array_keys(self::SEVERITY_LABELS)));
            rsort($sev);
            $out[] = [
                'entity_id'  => $eid,
                'group_id'   => $gid,
                'group_name' => mb_substr(trim((string) ($r['group_name'] ?? '')), 0, 255),
                'severities' => $sev ?: [5, 4, 2],
            ];
        }
        return $out;
    }

    /** Grava a configuração (campos em branco do token mantêm o token atual). */
    public static function save(array $values): void
    {
        $clean = array_intersect_key($values, self::DEFAULTS);
        if (array_key_exists('zabbix_token', $clean) && trim((string) $clean['zabbix_token']) === '') {
            unset($clean['zabbix_token']);
        }
        Config::setConfigurationValues(self::CONTEXT, array_map('strval', $clean));
        self::forget();
    }

    /** Na instalação: grava só o que ainda não existe (atualizar o plugin mantém a configuração). */
    public static function installDefaults(): void
    {
        $current = Config::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::DEFAULTS, $current);
        if ($missing) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
        self::forget();
    }

    /** Limpa o cache da requisição (depois de gravar). */
    private static function forget(): void
    {
        self::$cache = null;
    }
}
