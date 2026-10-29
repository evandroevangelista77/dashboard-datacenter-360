<?php

namespace GlpiPlugin\Sistemasinternos;

use CommonGLPI;
use Config;
use GLPIKey;

/**
 * Configuração do plugin (Sistemas Internos → Configurações): conexão com o
 * Zabbix, departamento, cadastro de hosts, alertas e notificações.
 */
class Settings extends CommonGLPI
{
    public const CONTEXT = 'plugin:sistemasinternos';

    public static string $rightname = Menu::RIGHT_CONFIG;

    /** Valores padrão (texto, como o Config do GLPI guarda). */
    public const DEFAULTS = [
        'zabbix_url'      => '',
        'zabbix_token'    => '',
        'zabbix_verify'   => '1',
        'groups'          => '29,19,63,4',
        'entities_id'     => '0',
        'title'           => 'Sistemas Internos',
        'escalation_name'  => '',
        'escalation_phone' => '',
        'si_theme'        => 'azul',
        'templates'       => '',
        'auto_tag'        => 'departamento=Sistemas Internos',
        'require_desc'    => '0',
        'prestadores'     => '',
        'alerts'          => '',
        'poll_seconds'    => '30',
        'popup_seconds'   => '20',
        'popup_max'       => '5',
        'popup_position'  => 'right',
        'everywhere'      => '1',
        'quiet_start'     => '',
        'quiet_end'       => '',
        'voice_rate'      => '1.05',
        'volume'          => '70',
        'mute_minutes'    => '60',
    ];

    /** Canais de cada alerta. */
    public const CHANNELS = [
        'on'     => ['Ativo', 'ti-power'],
        'flag'   => ['Sinalizador', 'ti-alert-hexagon'],
        'sound'  => ['Som', 'ti-volume'],
        'popup'  => ['Pop-up', 'ti-message-2'],
        'voice'  => ['Voz', 'ti-speakerphone'],
        'sticky' => ['Fica até fechar', 'ti-pin'],
    ];

    /** Sons disponíveis (gerados no navegador, sem arquivos). */
    public const TONES = ['bip' => 'Bipe', 'duplo' => 'Bipe duplo', 'sino' => 'Sino', 'sirene' => 'Sirene', 'suave' => 'Suave'];

    /** Tipos de alerta: [rótulo, grupo, ícone, cor, padrão dos canais, som padrão]. */
    public const ALERTS = [
        'zbx_5'        => ['Desastre', 'Zabbix', 'ti-flame', '#e45959', ['on', 'flag', 'sound', 'popup', 'voice', 'sticky'], 'sirene'],
        'zbx_4'        => ['Alta', 'Zabbix', 'ti-alert-octagon', '#e97659', ['on', 'flag', 'sound', 'popup', 'voice', 'sticky'], 'duplo'],
        'zbx_3'        => ['Média', 'Zabbix', 'ti-alert-triangle', '#ffa059', ['on', 'flag', 'sound', 'popup'], 'bip'],
        'zbx_2'        => ['Atenção', 'Zabbix', 'ti-alert-circle', '#ffc859', ['on', 'flag', 'popup'], 'suave'],
        'zbx_1'        => ['Informação', 'Zabbix', 'ti-info-circle', '#7499ff', [], 'suave'],
        'zbx_0'        => ['Não classificada', 'Zabbix', 'ti-help-circle', '#97aab3', [], 'suave'],
        'zbx_ok'       => ['Problema resolvido', 'Zabbix', 'ti-circle-check', '#22c55e', ['on', 'popup'], 'sino'],
        'glpi_new'     => ['Chamado novo na entidade', 'GLPI', 'ti-ticket', '#3769B2', ['on', 'flag', 'popup'], 'sino'],
        'glpi_sla1h'   => ['SLA vence em até 1 h', 'GLPI', 'ti-hourglass-high', '#f59e0b', ['on', 'flag', 'sound', 'popup', 'voice'], 'duplo'],
        'glpi_slalate' => ['SLA vencido', 'GLPI', 'ti-hourglass-empty', '#ef4444', ['on', 'flag', 'sound', 'popup', 'voice', 'sticky'], 'sirene'],
        'host_change'  => ['Host incluído, alterado ou removido por outra pessoa', 'Cadastro', 'ti-server-cog', '#3769B2', ['on', 'popup'], 'suave'],
    ];

    public static function getTypeName($nb = 0)
    {
        return 'Configurações do Sistemas Internos 360';
    }

    public function getRights($interface = 'central')
    {
        return [UPDATE => 'Alterar as configurações'];
    }

    public static function all(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = array_merge(self::DEFAULTS, array_map('strval', Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS))));
            // o GLPI criptografa os "secured_configs" ao gravar, mas não descriptografa ao ler
            if ($cache['zabbix_token'] !== '') {
                $cache['zabbix_token'] = (string) (new GLPIKey())->decrypt($cache['zabbix_token']);
            }
        }
        return $cache;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) self::get($key)));
    }

    /** Lista de IDs (grupos, templates) guardada como "1,2,3". */
    public static function ids(string $key): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', self::get($key))), fn ($v) => ctype_digit($v))));
    }

    public static function isConfigured(): bool
    {
        return self::get('zabbix_url') !== '' && self::get('zabbix_token') !== '';
    }

    /** Matriz dos alertas: tipo → canais ligados e som. */
    public static function alerts(): array
    {
        $saved = json_decode(self::get('alerts') ?: '[]', true) ?: [];
        $out = [];
        foreach (self::ALERTS as $key => [$label, , , , $defaults, $tone]) {
            $row = $saved[$key] ?? null;
            $out[$key] = [
                'channels' => is_array($row['channels'] ?? null) ? array_values(array_intersect($row['channels'], array_keys(self::CHANNELS))) : $defaults,
                'tone'     => isset(self::TONES[$row['tone'] ?? '']) ? $row['tone'] : $tone,
            ];
        }
        return $out;
    }

    public static function alertOn(string $key, string $channel = 'on'): bool
    {
        $a = self::alerts()[$key] ?? null;
        return $a && in_array('on', $a['channels'], true) && in_array($channel, $a['channels'], true);
    }

    /** Silêncio de som e voz no horário configurado (ex.: 22:00 às 06:00). */
    public static function isQuietNow(): bool
    {
        $s = self::get('quiet_start');
        $e = self::get('quiet_end');
        if (!preg_match('/^\d\d:\d\d$/', $s) || !preg_match('/^\d\d:\d\d$/', $e) || $s === $e) {
            return false;
        }
        $now = date('H:i');
        return $s < $e ? ($now >= $s && $now < $e) : ($now >= $s || $now < $e);
    }

    /** Nomes de técnicos prestadores (fora do SLA do departamento). */
    public static function prestadores(): array
    {
        return array_values(array_filter(array_map(fn ($v) => mb_strtolower(trim($v)), explode(',', self::get('prestadores')))));
    }

    /** Tag que todo host cadastrado pelo GLPI recebe (ex.: departamento=Sistemas Internos). */
    public static function autoTag(): ?array
    {
        $raw = trim(self::get('auto_tag'));
        if ($raw === '') {
            return null;
        }
        [$tag, $value] = array_pad(explode('=', $raw, 2), 2, '');
        return trim($tag) === '' ? null : ['tag' => trim($tag), 'value' => trim($value)];
    }

    public static function save(array $values): void
    {
        $clean = array_intersect_key($values, self::DEFAULTS);
        // token em branco no formulário = manter o atual
        if (array_key_exists('zabbix_token', $clean) && trim((string) $clean['zabbix_token']) === '') {
            unset($clean['zabbix_token']);
        }
        Config::setConfigurationValues(self::CONTEXT, array_map('strval', $clean));
    }

    /** Na instalação: grava só o que ainda não existe (atualização mantém a configuração). */
    public static function installDefaults(): void
    {
        global $DB;
        $current = Config::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::DEFAULTS, $current);
        // entidade padrão: OAKMONT > TI > SISTEMAS INTERNOS, se existir
        if (isset($missing['entities_id'])) {
            $row = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['completename' => ['LIKE', '%SISTEMAS INTERNOS']], 'LIMIT' => 1])->current();
            $missing['entities_id'] = (string) ($row['id'] ?? 0);
        }
        if ($missing) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }
}
