<?php

namespace GlpiPlugin\Avisos;

use Closure;
use CronTask;
use Glpi\Toolbox\HttpClient;
use Throwable;

/**
 * Envio para canais do Microsoft Teams por webhook, com um cartão adaptável
 * (Adaptive Card) no formato aceito pelo Workflows do Teams ("Postar em um canal
 * quando uma solicitação de webhook for recebida") e pelos webhooks de entrada.
 *
 * Cada mensagem vai para a fila e é enviada na hora; se o Teams não responder,
 * a ação automática "avisosteams" tenta de novo (até 5 vezes).
 */
class Teams
{
    public const QUEUE = 'glpi_plugin_avisos_filas';
    public const MAX_ATTEMPTS = 5;

    /** Só para testes: substitui o envio HTTP (recebe url e payload, devolve [ok, erro]). */
    public static ?Closure $transport = null;

    private const COLORS = ['info' => 'Accent', 'success' => 'Good', 'warning' => 'Warning', 'danger' => 'Attention'];

    public static function card(string $source, string $title, string $message, array $facts, string $url, array $names, string $level): array
    {
        [$sourceName] = Canal::meta($source);
        $body = [
            ['type' => 'TextBlock', 'text' => mb_strtoupper($sourceName) . ' · GLPI', 'size' => 'Small', 'weight' => 'Bolder', 'color' => self::COLORS[$level] ?? 'Accent', 'spacing' => 'None'],
            ['type' => 'TextBlock', 'text' => $title, 'size' => 'Large', 'weight' => 'Bolder', 'wrap' => true],
        ];
        if ($message !== '') {
            $body[] = ['type' => 'TextBlock', 'text' => $message, 'wrap' => true];
        }
        $factSet = [];
        foreach ($facts as $label => $value) {
            if ((string) $value !== '') {
                $factSet[] = ['title' => (string) $label, 'value' => (string) $value];
            }
        }
        if ($factSet) {
            $body[] = ['type' => 'FactSet', 'facts' => $factSet];
        }
        if ($names) {
            $body[] = ['type' => 'TextBlock', 'text' => 'Para: ' . implode(', ', $names), 'isSubtle' => true, 'wrap' => true, 'size' => 'Small'];
        }
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl'  => null,
                'content'     => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type'    => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body'    => $body,
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => 'Abrir no GLPI', 'url' => Aviso::absolute($url)]],
                ],
            ]],
        ];
    }

    /** Põe na fila e tenta enviar agora. Canal desligado ou sem URL: não envia. */
    public static function queue(string $source, array $payload): void
    {
        global $DB;
        $canal = Canal::forSource($source);
        if (!$canal || !$canal['is_active'] || !Canal::validUrl((string) $canal['webhook_url'])) {
            return;
        }
        $DB->insert(self::QUEUE, [
            'source' => $source, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending', 'date_creation' => date('Y-m-d H:i:s'),
        ]);
        self::deliver((int) $DB->insertId(), $canal);
    }

    private static function deliver(int $id, array $canal): bool
    {
        global $DB;
        $row = $DB->request(['FROM' => self::QUEUE, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        if (!$row) {
            return false;
        }
        [$ok, $error] = self::post((string) $canal['webhook_url'], json_decode((string) $row['payload'], true) ?: []);
        $now = date('Y-m-d H:i:s');
        $attempts = (int) $row['attempts'] + 1;
        $DB->update(self::QUEUE, [
            'attempts' => $attempts, 'last_error' => $ok ? null : $error,
            'status' => $ok ? 'sent' : ($attempts >= self::MAX_ATTEMPTS ? 'failed' : 'error'),
            'date_sent' => $ok ? $now : null,
        ], ['id' => $id]);
        $DB->update(Canal::getTable(), ['date_last' => $now, 'last_status' => $ok ? 'ok' : 'erro', 'last_error' => $ok ? null : $error], ['id' => $canal['id']]);
        return $ok;
    }

    /** POST do JSON; 2xx = entregue. Devolve [ok, mensagem de erro]. */
    public static function post(string $url, array $payload): array
    {
        if (self::$transport) {
            return (self::$transport)($url, $payload);
        }
        if (!Canal::validUrl($url)) {
            return [false, 'URL do webhook inválida (use https://).'];
        }
        try {
            $response = (new HttpClient(self::class, ['timeout' => 8]))->request('POST', $url, ['json' => $payload]);
            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                return [true, ''];
            }
            return [false, "O Teams respondeu HTTP $code: " . mb_substr(trim(strip_tags($response->getContent(false))), 0, 300)];
        } catch (Throwable $e) {
            return [false, mb_substr($e->getMessage(), 0, 500)];
        }
    }

    /** Mensagem de teste pela página de configuração (não usa a fila). */
    public static function test(array $canal): array
    {
        global $DB;
        [$ok, $error] = self::post((string) $canal['webhook_url'], self::card(
            (string) $canal['source'],
            'Teste de integração com o Teams',
            'Se você está lendo isto, os avisos do GLPI vão chegar neste canal.',
            ['Canal' => (string) $canal['name'], 'Enviado em' => date('d/m/Y H:i')],
            '',
            [],
            'success'
        ));
        $DB->update(Canal::getTable(), ['date_last' => date('Y-m-d H:i:s'), 'last_status' => $ok ? 'ok' : 'erro', 'last_error' => $ok ? null : $error], ['id' => $canal['id']]);
        return [$ok, $error];
    }

    // ------------------------------------------------------------------ ação automática

    public static function cronInfo($name)
    {
        return ['description' => 'Avisos: reenvia ao Teams as mensagens que falharam e apaga avisos antigos'];
    }

    public static function cronAvisosteams(CronTask $task): int
    {
        global $DB;
        $done = 0;
        $canais = [];
        foreach ($DB->request(['FROM' => Canal::getTable()]) as $c) {
            $canais[$c['source']] = $c;
        }
        foreach ($DB->request(['SELECT' => ['id', 'source'], 'FROM' => self::QUEUE, 'WHERE' => ['status' => ['pending', 'error']], 'ORDER' => 'id', 'LIMIT' => 50]) as $row) {
            $canal = $canais[$row['source']] ?? null;
            if ($canal && $canal['is_active'] && self::deliver((int) $row['id'], $canal)) {
                $done++;
            }
        }
        // limpeza: avisos lidos mais antigos que a retenção e mensagens já enviadas há mais de 30 dias
        $days = max(30, (int) (\Config::getConfigurationValues('plugin:avisos', ['retention_days'])['retention_days'] ?? 180));
        $DB->delete(Aviso::getTable(), ['is_read' => 1, 'date_creation' => ['<', date('Y-m-d H:i:s', strtotime("-$days days"))]]);
        $DB->delete(self::QUEUE, ['status' => 'sent', 'date_creation' => ['<', date('Y-m-d H:i:s', strtotime('-30 days'))]]);
        $task->addVolume($done);
        return $done ? 1 : 0;
    }
}
