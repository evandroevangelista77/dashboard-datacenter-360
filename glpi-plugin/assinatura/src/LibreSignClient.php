<?php

namespace GlpiPlugin\Assinatura;

use RuntimeException;

/**
 * Cliente mínimo da API OCS do LibreSign 15 (Nextcloud), autenticado com senha de aplicativo.
 */
class LibreSignClient
{
    private string $base;
    private string $user;
    private string $password;

    public function __construct()
    {
        if (!Settings::isConfigured()) {
            throw new RuntimeException('Plugin de assinatura não configurado (Configurar → Plugins → Assinatura digital).');
        }
        $this->base     = Settings::get('nextcloud_url');
        $this->user     = Settings::get('username');
        $this->password = Settings::get('app_password');
    }

    /** Cria a solicitação de assinatura e devolve os dados do arquivo (uuid, id, signers…). */
    public function requestSignature(string $name, string $pdf, array $signers): array
    {
        return $this->ocs('POST', '/request-signature', [
            'name'    => $name,
            'file'    => ['base64' => base64_encode($pdf)],
            'signers' => $signers,
        ]);
    }

    /**
     * Campo de assinatura visível no canto inferior direito da página, logo acima do
     * rodapé de validação do LibreSign. Coordenadas a partir do canto inferior
     * esquerdo, então valem para qualquer altura de página.
     */
    public function addVisibleSignature(string $fileUuid, int $fileId, int $signRequestId, int $page): array
    {
        return $this->ocs('POST', '/file-element/' . rawurlencode($fileUuid), [
            'signRequestId' => $signRequestId,
            'fileId'        => $fileId,
            'type'          => 'signature',
            // 270×77 pt: proporção 3,5:1 do carimbo do LibreSign (desenho à esquerda, texto à direita)
            'coordinates'   => ['page' => $page, 'llx' => 290, 'lly' => 86, 'urx' => 560, 'ury' => 163],
        ]);
    }

    public function validate(string $uuid): array
    {
        return $this->ocs('GET', '/file/validate/uuid/' . rawurlencode($uuid));
    }

    public function delete(int $fileId): array
    {
        return $this->ocs('DELETE', '/sign/file_id/' . $fileId);
    }

    /** PDF atual do arquivo (assinado, depois que todos assinam). */
    public function downloadPdf(string $uuid): string
    {
        [$code, $body, $type] = $this->http('GET', $this->base . '/index.php/apps/libresign/p/pdf/' . rawurlencode($uuid));
        if ($code !== 200 || !str_starts_with($body, '%PDF')) {
            throw new RuntimeException("Download do PDF falhou (HTTP $code, $type)");
        }
        return $body;
    }

    /** Testa as credenciais e a permissão de uso do LibreSign. */
    public function ping(): string
    {
        $data = $this->ocs('GET', '/file/list?length=1');
        return 'Conexão OK (' . ((int) ($data['pagination']['total'] ?? 0)) . ' documento(s) visíveis para a conta)';
    }

    private function ocs(string $method, string $path, ?array $json = null): array
    {
        $url = $this->base . '/ocs/v2.php/apps/libresign/api/v1' . $path;
        [$code, $body] = $this->http($method, $url, $json, ['OCS-APIRequest: true', 'Accept: application/json']);
        $decoded = json_decode($body, true);
        $data = $decoded['ocs']['data'] ?? null;
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) ? ($data['message'] ?? '') : '';
            throw new RuntimeException(trim("LibreSign HTTP $code " . ($msg ?: substr(strip_tags($body), 0, 200))));
        }
        return $data;
    }

    /** @return array{0:int,1:string,2:string} */
    private function http(string $method, string $url, ?array $json = null, array $headers = []): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERPWD        => $this->user . ':' . $this->password,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('Nextcloud inacessível: ' . curl_error($ch));
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $body, (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    }
}
