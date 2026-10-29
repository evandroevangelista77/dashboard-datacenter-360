<?php

namespace GlpiPlugin\Sistemasinternos;

use CommonGLPI;
use RuntimeException;
use Session;

/**
 * Hosts do Zabbix gerenciados pelo GLPI: lista, inclusão, edição, ativar/desativar
 * e exclusão, sempre dentro dos grupos liberados para o perfil e com auditoria.
 */
class Host extends CommonGLPI
{
    public static string $rightname = Menu::RIGHT_HOST;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Hosts do Zabbix' : 'Host do Zabbix';
    }

    public static function getIcon()
    {
        return 'ti ti-server-2';
    }

    public static function getMenuContent()
    {
        $items = Menu::content();
        return $items['host'] ?? false;
    }

    public function getRights($interface = 'central')
    {
        return [
            READ   => 'Ver os hosts',
            CREATE => 'Incluir',
            UPDATE => 'Editar, ativar e desativar',
            PURGE  => 'Excluir definitivamente',
        ];
    }

    private const OUTPUT = ['hostid', 'host', 'name', 'status', 'description'];

    private static function select(array $extra = []): array
    {
        return [
            'output'                => array_merge(self::OUTPUT, Zabbix::atLeast('7.0') ? ['proxyid', 'monitored_by'] : ['proxy_hostid']),
            Zabbix::groupsParam()   => ['groupid', 'name'],
            'selectInterfaces'      => ['interfaceid', 'type', 'main', 'useip', 'ip', 'dns', 'port', 'available', 'error', 'details'],
            'selectParentTemplates' => ['templateid', 'name'],
            'selectTags'            => ['tag', 'value'],
        ] + $extra;
    }

    /** Hosts dos grupos permitidos, com problemas ativos e pior severidade. */
    public static function list(): array
    {
        $groups = Access::allowedGroupIds();
        if (!$groups) {
            return [];
        }
        $hosts = Zabbix::call('host.get', self::select(['groupids' => $groups, 'sortfield' => 'name']));
        $problems = [];
        foreach (Zabbix::call('trigger.get', [
            'groupids' => $groups, 'output' => ['triggerid', 'priority'], 'filter' => ['value' => 1],
            'monitored' => true, 'skipDependent' => true, 'selectHosts' => ['hostid'],
        ]) as $t) {
            foreach ($t['hosts'] as $h) {
                $p = &$problems[$h['hostid']];
                $p['count'] = ($p['count'] ?? 0) + 1;
                $p['sev'] = max($p['sev'] ?? 0, (int) $t['priority']);
                unset($p);
            }
        }
        foreach ($hosts as &$h) {
            $h['problems'] = $problems[$h['hostid']]['count'] ?? 0;
            $h['severity'] = $problems[$h['hostid']]['sev'] ?? -1;
        }
        return $hosts;
    }

    /** Um host com tudo o que o formulário precisa; só se estiver num grupo permitido. */
    public static function find(string $hostid): array
    {
        $r = Zabbix::call('host.get', self::select(['hostids' => [$hostid], 'selectMacros' => ['hostmacroid', 'macro', 'value', 'type', 'description']]));
        if (!$r) {
            throw new RuntimeException('Host não encontrado no Zabbix (ou a conta de serviço não tem acesso a ele).');
        }
        $host = $r[0];
        if (!Access::canSee($host)) {
            throw new RuntimeException('Este host não está em nenhum grupo liberado para o seu perfil.');
        }
        return $host;
    }

    /** Situação de cada interface: 1 disponível, 2 indisponível, 0 desconhecida. */
    public static function availability(array $host): int
    {
        $vals = array_map(fn ($i) => (int) $i['available'], $host['interfaces'] ?? []);
        if (in_array(2, $vals, true)) {
            return 2;
        }
        return in_array(1, $vals, true) ? 1 : 0;
    }

    public static function mainInterface(array $host): ?array
    {
        foreach ($host['interfaces'] ?? [] as $i) {
            if ((int) $i['main'] === 1) {
                return $i;
            }
        }
        return $host['interfaces'][0] ?? null;
    }

    /** Retrato legível do host, para a auditoria (segredos nunca entram). */
    public static function snapshot(array $h): array
    {
        $i = self::mainInterface($h);
        return [
            'Nome técnico'  => $h['host'] ?? '',
            'Nome visível'  => $h['name'] ?? '',
            'Situação'      => (int) ($h['status'] ?? 0) === 0 ? 'Ativo' : 'Desativado',
            'Descrição'     => $h['description'] ?? '',
            'Grupos'        => array_column(Zabbix::hostGroups($h), 'name'),
            'Interface'     => $i ? (Zabbix::INTERFACES[(int) $i['type']][0] ?? 'Tipo ' . $i['type']) . ' ' . ((int) $i['useip'] ? $i['ip'] : $i['dns']) . ':' . $i['port'] : '',
            'Templates'     => array_column($h['parentTemplates'] ?? [], 'name'),
            'Tags'          => array_map(fn ($t) => $t['tag'] . ($t['value'] !== '' ? '=' . $t['value'] : ''), $h['tags'] ?? []),
            'Macros'        => array_map(fn ($m) => $m['macro'] . ((int) ($m['type'] ?? 0) === 0 ? '=' . $m['value'] : ' (secreta)'), $h['macros'] ?? []),
        ];
    }

    // ------------------------------------------------------------------ gravação

    /**
     * Inclui (sem $current) ou edita um host a partir do formulário.
     * Devolve o hostid. Grupos e templates fora do que o perfil pode usar são preservados na edição.
     */
    public static function save(array $in, ?array $current = null): string
    {
        $isNew = $current === null;
        if (!Access::can($isNew ? CREATE : UPDATE)) {
            throw new RuntimeException('Seu perfil não pode ' . ($isNew ? 'incluir' : 'editar') . ' hosts.');
        }

        $host = trim((string) ($in['host'] ?? ''));
        $name = trim((string) ($in['name'] ?? '')) ?: $host;
        if (!preg_match('/^[0-9A-Za-z_. \-]{1,128}$/', $host)) {
            throw new RuntimeException('Nome técnico inválido: use letras sem acento, números, espaço, ponto, hífen e sublinhado (até 128).');
        }
        if (mb_strlen($name) > 128) {
            throw new RuntimeException('Nome visível com mais de 128 caracteres.');
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if ($desc === '' && Settings::get('require_desc') === '1') {
            throw new RuntimeException('Informe a descrição do host (exigido na configuração).');
        }

        // grupos: os escolhidos (só entre os permitidos) + os que o host já tinha fora do alcance do perfil
        $allowed = Access::allowedGroupIds();
        $picked = array_values(array_intersect(array_map('strval', (array) ($in['groups'] ?? [])), $allowed));
        if (!$picked) {
            throw new RuntimeException('Escolha pelo menos um grupo do Zabbix entre os liberados para o seu perfil.');
        }
        $keep = $isNew ? [] : array_values(array_diff(array_column(Zabbix::hostGroups($current), 'groupid'), $allowed));
        $groups = array_map(fn ($id) => ['groupid' => (string) $id], array_unique(array_merge($picked, $keep)));

        // templates: idem
        $allowedT = array_map('strval', array_keys(Access::allowedTemplates()));
        $pickedT = array_values(array_intersect(array_map('strval', (array) ($in['templates'] ?? [])), $allowedT));
        $currentT = $isNew ? [] : array_column($current['parentTemplates'] ?? [], 'templateid');
        $keepT = array_values(array_diff($currentT, $allowedT));
        $templates = array_values(array_unique(array_merge($pickedT, $keepT)));
        $clearT = array_values(array_diff($currentT, $templates));

        $params = [
            'host'        => $host,
            'name'        => $name,
            'description' => $desc,
            'status'      => empty($in['status']) ? 1 : 0, // 0 = monitorado
            'groups'      => $groups,
            'templates'   => array_map(fn ($id) => ['templateid' => $id], $templates),
            'tags'        => self::tags($in),
            'interfaces'  => self::interfaces($in, $current),
        ];
        if ($clearT) {
            $params['templates_clear'] = array_map(fn ($id) => ['templateid' => $id], $clearT);
        }
        $proxy = (string) ($in['proxyid'] ?? '0');
        if (Zabbix::proxies() || $proxy !== '0') {
            if (Zabbix::atLeast('7.0')) {
                $params['monitored_by'] = $proxy !== '0' ? 1 : 0;
                if ($proxy !== '0') {
                    $params['proxyid'] = $proxy;
                }
            } else {
                $params['proxy_hostid'] = $proxy;
            }
        }

        $macros = self::macros($in); // valida antes de mexer no Zabbix
        $before = $isNew ? null : self::snapshot($current);
        try {
            if ($isNew) {
                $hostid = (string) (Zabbix::call('host.create', $params)['hostids'][0] ?? '');
            } else {
                $hostid = (string) $current['hostid'];
                Zabbix::call('host.update', ['hostid' => $hostid] + $params);
            }
            self::saveMacros($hostid, $macros, $current);
        } catch (RuntimeException $e) {
            Audit::log($isNew ? 'create' : 'update', (string) ($current['hostid'] ?? ''), $host, $before, null, 'erro', $e->getMessage());
            throw $e;
        }
        $after = self::find($hostid);
        Audit::log($isNew ? 'create' : 'update', $hostid, $after['name'], $before, self::snapshot($after));
        Painel::forget();
        return $hostid;
    }

    /** Tags do formulário (+ a tag automática do departamento e o vínculo com o ativo do GLPI). */
    private static function tags(array $in): array
    {
        $out = [];
        $names = (array) ($in['tag_name'] ?? []);
        $values = (array) ($in['tag_value'] ?? []);
        foreach ($names as $i => $t) {
            $t = trim((string) $t);
            if ($t !== '' && $t !== 'glpi_computer') {
                $out[$t . '|' . trim((string) ($values[$i] ?? ''))] = ['tag' => mb_substr($t, 0, 255), 'value' => mb_substr(trim((string) ($values[$i] ?? '')), 0, 255)];
            }
        }
        if ($auto = Settings::autoTag()) {
            $out[$auto['tag'] . '|' . $auto['value']] = $auto;
        }
        if (($cid = (int) ($in['computers_id'] ?? 0)) > 0) {
            $out['glpi_computer|' . $cid] = ['tag' => 'glpi_computer', 'value' => (string) $cid];
        }
        return array_values($out);
    }

    /** Interface principal do formulário; as demais interfaces do host ficam como estão. */
    private static function interfaces(array $in, ?array $current): array
    {
        $type = (int) ($in['if_type'] ?? 1);
        if (!isset(Zabbix::INTERFACES[$type])) {
            throw new RuntimeException('Tipo de interface inválido.');
        }
        $useip = ($in['if_useip'] ?? '1') === '1' ? 1 : 0;
        $ip = trim((string) ($in['if_ip'] ?? ''));
        $dns = trim((string) ($in['if_dns'] ?? ''));
        $port = trim((string) ($in['if_port'] ?? '')) ?: (string) Zabbix::INTERFACES[$type][1];
        if ($useip && !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Informe um endereço IP válido para a interface.');
        }
        if (!$useip && !preg_match('/^(?=.{1,255}$)[A-Za-z0-9]([A-Za-z0-9\-]{0,62}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]{0,62}[A-Za-z0-9])?)*$/', $dns)) {
            throw new RuntimeException('Informe um nome DNS válido para a interface.');
        }
        if (!preg_match('/^\{\$[A-Z0-9_.]+\}$/', $port) && !(ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535)) {
            throw new RuntimeException('Porta inválida (1 a 65535 ou uma macro como {$AGENT.PORT}).');
        }
        $main = ['type' => $type, 'main' => 1, 'useip' => $useip, 'ip' => $useip ? $ip : ($ip ?: ''), 'dns' => $dns, 'port' => $port];
        if ($type === 2) {
            $v = (int) ($in['snmp_version'] ?? 2);
            $details = ['version' => in_array($v, [1, 2, 3], true) ? $v : 2, 'bulk' => 1];
            if ($details['version'] < 3) {
                $details['community'] = trim((string) ($in['snmp_community'] ?? '')) ?: '{$SNMP_COMMUNITY}';
            } else {
                $details += [
                    'securityname'   => trim((string) ($in['snmp_securityname'] ?? '')),
                    'securitylevel'  => max(0, min(2, (int) ($in['snmp_securitylevel'] ?? 0))),
                    'authprotocol'   => max(0, min(5, (int) ($in['snmp_authprotocol'] ?? 1))),
                    'authpassphrase' => (string) ($in['snmp_authpassphrase'] ?? ''),
                    'privprotocol'   => max(0, min(5, (int) ($in['snmp_privprotocol'] ?? 1))),
                    'privpassphrase' => (string) ($in['snmp_privpassphrase'] ?? ''),
                ];
            }
            $main['details'] = $details;
        }

        if ($current === null) {
            return [$main];
        }
        // edição: atualiza a interface principal do mesmo tipo e mantém as outras
        $out = [];
        $replaced = false;
        foreach ($current['interfaces'] ?? [] as $i) {
            $keep = ['interfaceid' => $i['interfaceid'], 'type' => (int) $i['type'], 'main' => (int) $i['main'], 'useip' => (int) $i['useip'], 'ip' => $i['ip'], 'dns' => $i['dns'], 'port' => $i['port']];
            if ((int) $i['type'] === 2 && !empty($i['details'])) {
                $keep['details'] = $i['details'];
            }
            if (!$replaced && (int) $i['type'] === $type && (int) $i['main'] === 1) {
                if ($type === 2 && ($main['details']['version'] ?? 2) === 3 && $main['details']['authpassphrase'] === '' && $main['details']['privpassphrase'] === '') {
                    // frases-senha em branco = manter as atuais
                    $main['details']['authpassphrase'] = $i['details']['authpassphrase'] ?? '';
                    $main['details']['privpassphrase'] = $i['details']['privpassphrase'] ?? '';
                }
                $out[] = ['interfaceid' => $i['interfaceid']] + $main;
                $replaced = true;
            } elseif ((int) $i['type'] !== $type || (int) $i['main'] !== 1) {
                // a principal de outro tipo deixa de existir só se for a do tipo trocado
                if ((int) $i['main'] === 1 && self::mainInterface($current)['interfaceid'] === $i['interfaceid']) {
                    continue;
                }
                $out[] = $keep;
            }
        }
        if (!$replaced) {
            $out[] = $main;
        }
        return $out;
    }

    /** Macros do formulário: {$NOME} → valor. */
    private static function macros(array $in): array
    {
        $wanted = [];
        $names = (array) ($in['macro_name'] ?? []);
        $values = (array) ($in['macro_value'] ?? []);
        foreach ($names as $i => $m) {
            $m = strtoupper(trim((string) $m));
            if ($m === '') {
                continue;
            }
            if (!preg_match('/^\{\$[A-Z0-9_.]+(:.+)?\}$/', $m)) {
                throw new RuntimeException("Macro inválida: $m (formato {\$NOME}).");
            }
            $wanted[$m] = (string) ($values[$i] ?? '');
        }
        return $wanted;
    }

    /** Macros de texto: cria, altera e remove; macros secretas e de cofre não são tocadas. */
    private static function saveMacros(string $hostid, array $wanted, ?array $current): void
    {
        $existing = [];
        foreach ($current['macros'] ?? [] as $m) {
            if ((int) ($m['type'] ?? 0) === 0) {
                $existing[$m['macro']] = $m;
            } else {
                unset($wanted[$m['macro']]); // secreta/cofre: mantida
            }
        }
        $create = [];
        foreach ($wanted as $macro => $value) {
            if (!isset($existing[$macro])) {
                $create[] = ['hostid' => $hostid, 'macro' => $macro, 'value' => $value];
            } elseif ($existing[$macro]['value'] !== $value) {
                Zabbix::call('usermacro.update', ['hostmacroid' => $existing[$macro]['hostmacroid'], 'value' => $value]);
            }
        }
        if ($create) {
            Zabbix::call('usermacro.create', $create);
        }
        $delete = array_values(array_map(fn ($m) => $m['hostmacroid'], array_diff_key($existing, $wanted)));
        if ($delete) {
            Zabbix::call('usermacro.delete', $delete);
        }
    }

    /** Ativa ou desativa o monitoramento (o host continua cadastrado). */
    public static function setStatus(array $host, bool $enable): void
    {
        if (!Access::can(UPDATE)) {
            throw new RuntimeException('Seu perfil não pode ativar ou desativar hosts.');
        }
        if (!$enable && !Access::ownsAll($host)) {
            throw new RuntimeException('O host também está em grupos de outros departamentos: desativá-lo afetaria o monitoramento deles. Peça ao administrador do Zabbix.');
        }
        $before = self::snapshot($host);
        $action = $enable ? 'enable' : 'disable';
        try {
            Zabbix::call('host.update', ['hostid' => $host['hostid'], 'status' => $enable ? 0 : 1]);
        } catch (RuntimeException $e) {
            Audit::log($action, $host['hostid'], $host['name'], $before, null, 'erro', $e->getMessage());
            throw $e;
        }
        Audit::log($action, $host['hostid'], $host['name'], $before, ['Situação' => $enable ? 'Ativo' : 'Desativado'] + $before);
        Painel::forget();
    }

    /** Exclui do Zabbix (histórico do host é perdido). Exige digitar o nome técnico. */
    public static function delete(array $host, string $confirm): void
    {
        if (!Access::can(PURGE)) {
            throw new RuntimeException('Seu perfil não pode excluir hosts. Use "Desativar".');
        }
        if (!Access::ownsAll($host)) {
            throw new RuntimeException('O host também está em grupos de outros departamentos: não pode ser excluído por aqui.');
        }
        if (trim($confirm) !== $host['host']) {
            throw new RuntimeException('Para excluir, digite exatamente o nome técnico do host: ' . $host['host']);
        }
        $before = self::snapshot($host);
        try {
            Zabbix::call('host.delete', [$host['hostid']]);
        } catch (RuntimeException $e) {
            Audit::log('delete', $host['hostid'], $host['name'], $before, null, 'erro', $e->getMessage());
            throw $e;
        }
        Audit::log('delete', $host['hostid'], $host['name'], $before, null);
        Painel::forget();
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, PURGE);
    }
}
