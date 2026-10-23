<?php

namespace GlpiPlugin\Assinatura;

use CommonGLPI;
use Config;
use GLPIKey;
use Html;
use Session;

/**
 * Configuração da conexão com o LibreSign (Configurar → Plugins → Assinatura digital).
 */
class Settings extends CommonGLPI
{
    public const CONTEXT = 'plugin:assinatura';

    public static string $rightname = 'config';

    public static function getTypeName($nb = 0)
    {
        return 'Assinatura digital (LibreSign)';
    }

    public static function get(string $key, string $default = ''): string
    {
        $values = Config::getConfigurationValues(self::CONTEXT, [$key]);
        $value = (string) ($values[$key] ?? $default);
        // o GLPI criptografa os "secured_configs" ao gravar, mas não descriptografa ao ler
        if ($key === 'app_password' && $value !== '') {
            $value = (string) (new GLPIKey())->decrypt($value);
        }
        return $value;
    }

    public static function isConfigured(): bool
    {
        return self::get('nextcloud_url') !== '' && self::get('username') !== '' && self::get('app_password') !== '';
    }

    public static function save(array $input): void
    {
        $values = [
            'nextcloud_url'     => rtrim(trim((string) ($input['nextcloud_url'] ?? '')), '/'),
            'username'          => trim((string) ($input['username'] ?? '')),
            'default_message'   => trim((string) ($input['default_message'] ?? '')),
            'accept_validation' => !empty($input['accept_validation']) ? '1' : '0',
        ];
        // senha em branco no formulário = manter a atual
        if (trim((string) ($input['app_password'] ?? '')) !== '') {
            $values['app_password'] = trim((string) $input['app_password']);
        }
        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    public static function showForm(): void
    {
        $url   = htmlspecialchars(self::get('nextcloud_url'), ENT_QUOTES);
        $user  = htmlspecialchars(self::get('username'), ENT_QUOTES);
        $msg   = htmlspecialchars(self::get('default_message'), ENT_QUOTES);
        $pwd   = self::get('app_password') !== '' ? 'definida (deixe em branco para manter)' : 'não definida';
        $accept = self::get('accept_validation', '1') === '1' ? 'checked' : '';
        global $CFG_GLPI;
        $action = htmlspecialchars($CFG_GLPI['root_doc'] . '/plugins/assinatura/front/config.form.php', ENT_QUOTES);

        echo <<<HTML
            <div class="card m-3" style="max-width: 860px">
              <div class="card-header"><h3 class="card-title"><i class="ti ti-signature me-2"></i>Assinatura digital (LibreSign)</h3></div>
              <form method="post" action="$action" class="card-body">
                <div class="mb-3">
                  <label class="form-label">URL do Nextcloud</label>
                  <input class="form-control" name="nextcloud_url" value="$url" placeholder="http://192.168.15.45:8080" required>
                  <div class="form-hint">Endereço base do Nextcloud onde o LibreSign está instalado.</div>
                </div>
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Usuário do Nextcloud</label>
                    <input class="form-control" name="username" value="$user" required>
                    <div class="form-hint">Conta de serviço com permissão para solicitar assinaturas no LibreSign.</div>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Senha de aplicativo</label>
                    <input class="form-control" type="password" name="app_password" autocomplete="new-password" placeholder="$pwd">
                    <div class="form-hint">Gerada em Nextcloud → Configurações → Segurança. Gravada criptografada.</div>
                  </div>
                </div>
                <div class="mb-3">
                  <label class="form-label">Mensagem padrão ao assinante</label>
                  <textarea class="form-control" name="default_message" rows="2">$msg</textarea>
                </div>
                <label class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="accept_validation" value="1" $accept>
                  <span class="form-check-label">Ao assinar, aprovar automaticamente a validação pendente do assinante no chamado</span>
                </label>
                <div class="d-flex gap-2">
                  <button class="btn btn-primary" type="submit" name="save" value="1"><i class="ti ti-device-floppy me-1"></i>Salvar</button>
                  <button class="btn btn-outline-secondary" type="submit" name="test" value="1"><i class="ti ti-plug-connected me-1"></i>Testar conexão</button>
                </div>
            HTML;
        Html::closeForm();
        echo '</div>';
    }
}
