<?php

namespace GlpiPlugin\Cronograma;

use Config;
use RuntimeException;

/**
 * Lê um cronograma do MS Project: .xml (MSPDI) direto, ou .mpp/.mpt convertido
 * para MSPDI pelo MPXJ (Java).
 */
class MsProjectReader
{
    public const EXTENSIONS = ['mpp', 'mpt', 'xml'];
    private const NS = 'http://schemas.microsoft.com/project';

    /**
     * @return array{name: string, tasks: list<array>, resources: array<int,string>}
     */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONS, true)) {
            throw new RuntimeException('Formato não suportado. Envie um arquivo .mpp, .mpt ou .xml do MS Project.');
        }
        if ($ext === 'xml') {
            return self::parse($path);
        }
        $xml = self::convert($path);
        try {
            return self::parse($xml);
        } finally {
            @unlink($xml);
        }
    }

    /** Converte .mpp → MSPDI com MPXJ, sem shell e com ambiente limpo. */
    private static function convert(string $mpp): string
    {
        $cfg  = Config::getConfigurationValues('plugin:cronograma', ['java', 'classpath']);
        $java = $cfg['java'] ?? '/usr/bin/java';
        if (!is_executable($java)) {
            throw new RuntimeException("Java não encontrado em $java (necessário para ler .mpp). Exporte o cronograma como XML no MS Project ou instale o Java.");
        }
        $out = GLPI_TMP_DIR . '/cronograma-' . bin2hex(random_bytes(8)) . '.xml';
        $proc = proc_open(
            [$java, '-Xmx512m', '-cp', $cfg['classpath'] ?? '/opt/mpxj/mpxj.jar:/opt/mpxj/lib/*', 'org.mpxj.sample.MpxjConvert', $mpp, $out],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            GLPI_TMP_DIR,
            ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'HOME' => GLPI_TMP_DIR]
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('Não foi possível iniciar o conversor do MS Project.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || !is_file($out) || filesize($out) === 0) {
            @unlink($out);
            $msg = trim(preg_replace('/^.*Log4j.*$/m', '', $stderr . "\n" . $stdout));
            throw new RuntimeException('Falha ao ler o .mpp' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 300) : '.'));
        }
        return $out;
    }

    private static function parse(string $xmlPath): array
    {
        $prev = libxml_use_internal_errors(true);
        // sem LIBXML_NOENT nem acesso à rede: entidades externas não são resolvidas
        $root = simplexml_load_file($xmlPath, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if ($root === false || $root->getName() !== 'Project') {
            throw new RuntimeException('O arquivo não é um cronograma XML do MS Project (MSPDI).');
        }
        $p = $root->children(self::NS);

        $resources = [];
        foreach ($p->Resources->Resource ?? [] as $r) {
            $name = trim((string) $r->Name);
            if ($name !== '' && $name !== '0') {
                $resources[(int) $r->UID] = $name;
            }
        }
        $assign = [];
        foreach ($p->Assignments->Assignment ?? [] as $a) {
            $rid = (int) $a->ResourceUID;
            if (isset($resources[$rid])) {
                $assign[(int) $a->TaskUID][] = $resources[$rid];
            }
        }

        $tasks = [];
        foreach ($p->Tasks->Task ?? [] as $t) {
            $uid = (int) $t->UID;
            if ((string) $t->Null === '1' || trim((string) $t->Name) === '') {
                continue;
            }
            $links = [];
            foreach ($t->PredecessorLink as $l) {
                $links[] = [
                    'uid'  => (int) $l->PredecessorUID,
                    'type' => (int) $l->Type,               // MSPDI: 0=FF 1=FS 2=SF 3=SS
                    'lag'  => (int) $l->LinkLag,            // décimos de minuto
                ];
            }
            $tasks[] = [
                'uid'          => $uid,
                'level'        => (int) $t->OutlineLevel,
                'name'         => trim((string) $t->Name),
                'notes'        => trim((string) $t->Notes),
                'start'        => self::date($t->Start),
                'finish'       => self::date($t->Finish),
                'actual_start' => self::date($t->ActualStart),
                'actual_finish' => self::date($t->ActualFinish),
                'duration'     => self::seconds((string) $t->Duration),
                'percent'      => max(0, min(100, (int) $t->PercentComplete)),
                'milestone'    => (string) $t->Milestone === '1',
                'summary'      => (string) $t->Summary === '1',
                'links'        => $links,
                'resources'    => array_values(array_unique($assign[$uid] ?? [])),
            ];
        }
        if (!$tasks) {
            throw new RuntimeException('Nenhuma tarefa encontrada no cronograma.');
        }
        return ['name' => trim((string) ($p->Title ?: $p->Name)), 'tasks' => $tasks, 'resources' => $resources];
    }

    private static function date($value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || str_starts_with($v, '1984') || str_starts_with($v, '2049')) { // datas "NA" do Project
            return null;
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** Duração ISO 8601 do MSPDI (ex.: PT352H0M0S) em segundos. */
    private static function seconds(string $iso): int
    {
        if (!preg_match('/P(?:(\d+)D)?T?(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?/', $iso, $m)) {
            return 0;
        }
        return (int) round(((float) ($m[1] ?? 0)) * 86400 + ((float) ($m[2] ?? 0)) * 3600 + ((float) ($m[3] ?? 0)) * 60 + (float) ($m[4] ?? 0));
    }
}
