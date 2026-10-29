<?php

namespace GlpiPlugin\Sistemasinternos;

use Session;

/**
 * Seção "Sistemas Internos" do menu lateral, direitos e cabeçalho das páginas.
 */
class Menu
{
    /** Chave da seção no menu lateral. */
    public const SECTOR = 'sistemasinternos';

    public const ICON = 'ti ti-building-community';

    /** Cor do departamento (Sistemas da Informação). */
    public const COLOR = '#3769B2';

    public const RIGHT_PAINEL = 'plugin_sistemasinternos_painel';
    public const RIGHT_HOST   = 'plugin_sistemasinternos_host';
    public const RIGHT_CONFIG = 'plugin_sistemasinternos_config';
    public const RIGHTS       = [self::RIGHT_PAINEL, self::RIGHT_HOST, self::RIGHT_CONFIG];

    /** Itens da seção conforme os direitos do usuário. */
    public static function content(): array
    {
        $items = [];
        if (Session::haveRight(self::RIGHT_PAINEL, READ)) {
            $items['visao'] = ['title' => 'Visão 360', 'page' => '/plugins/sistemasinternos/front/visao.php', 'icon' => 'ti ti-layout-dashboard'];
            $items['topologia'] = ['title' => 'Topologia', 'page' => '/plugins/sistemasinternos/front/visao.php?topologia=1', 'icon' => 'ti ti-topology-star-3'];
            $items['painel'] = ['title' => 'Resumo rápido', 'page' => '/plugins/sistemasinternos/front/painel.php', 'icon' => 'ti ti-chart-donut-3'];
        }
        if (Session::haveRight(self::RIGHT_HOST, READ)) {
            $items['host'] = ['title' => 'Hosts do Zabbix', 'page' => '/plugins/sistemasinternos/front/hosts.php', 'icon' => 'ti ti-server-2'];
            $items['auditoria'] = ['title' => 'Auditoria dos hosts', 'page' => '/plugins/sistemasinternos/front/auditoria.php', 'icon' => 'ti ti-history'];
        }
        if (Session::haveRight(self::RIGHT_CONFIG, UPDATE)) {
            $items['config'] = ['title' => 'Configurações', 'page' => '/plugins/sistemasinternos/front/config.form.php', 'icon' => 'ti ti-settings'];
        }
        return $items;
    }

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/sistemasinternos' . $path;
    }

    /** Versão do arquivo para o ?v= (com a data do arquivo, para o navegador não ficar com o antigo). */
    public static function fileVersion(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . $path;
        return PLUGIN_SISTEMASINTERNOS_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
    }

    public const SUBTITLES = [
        'visao'     => 'O mesmo dashboard do Sistemas Internos 360: situação geral, mapa, infraestrutura, APs do wi-fi, vulnerabilidades, atendimento, linha do tempo, voz e book, dentro do GLPI.',
        'topologia' => 'Links, firewalls, switches, APs do wi-fi, servidores físicos, VMs e serviços, ao vivo.',
        'painel'    => 'O que o Zabbix monitora nos grupos do departamento e os chamados da entidade no GLPI: disponibilidade, problemas ativos, SLA e fila de atendimento.',
        'host'      => 'Hosts do Zabbix nos grupos liberados para o seu perfil. Inclua, edite, desative ou exclua sem sair do GLPI: tudo fica registrado na auditoria.',
        'host_form' => 'Dados do host no Zabbix: grupos, interface de coleta, templates, tags e macros. Os grupos e templates disponíveis dependem do seu perfil e da configuração.',
        'auditoria' => 'Tudo o que foi feito nos hosts do Zabbix pelo GLPI: quem, quando, o quê, o antes e o depois.',
        'config'    => 'Conexão com o Zabbix (conta de serviço), departamento, regras do cadastro de hosts e, para cada tipo de alerta, o sinalizador, o som, a voz e o pop-up no rodapé.',
    ];

    /** Abre a página: fundo da árvore na cor do departamento, marca-d'água e título. */
    public static function open(string $title, string $key, string $actions = ''): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        // o CSS do plugin já vem em todas as telas (Hooks::ADD_CSS)
        return "<div class='si360'><div class='si360-bg' aria-hidden='true'></div><div class='si360-mark' aria-hidden='true'></div>"
            . "<div class='si360-title'><div><div class='si360-crumb'><i class='" . self::ICON . " me-1'></i>" . $e(Settings::get('title') ?: 'Sistemas Internos') . " · visão 360</div>"
            . "<h2>" . $e($title) . "</h2>"
            . (isset(self::SUBTITLES[$key]) ? "<div class='si360-subtitle'>" . $e(self::SUBTITLES[$key]) . "</div>" : '')
            . "</div>" . ($actions ? "<div class='d-flex flex-wrap gap-2 align-items-center'>$actions</div>" : '') . "</div>";
    }

    public static function close(): string
    {
        return '</div>';
    }

    public static function css(): string
    {
        return "<link rel='stylesheet' href='" . htmlspecialchars(self::url('/css/sistemasinternos.css') . '?v=' . self::fileVersion('css/sistemasinternos.css'), ENT_QUOTES) . "'>";
    }

    /** Aviso quando a conta de serviço do Zabbix ainda não foi configurada. */
    public static function notConfigured(): string
    {
        $link = Session::haveRight(self::RIGHT_CONFIG, UPDATE)
            ? " <a href='" . htmlspecialchars(self::url('/front/config.form.php'), ENT_QUOTES) . "'>Configurar agora</a>."
            : ' Peça ao administrador do GLPI para configurar.';
        return "<div class='alert alert-warning'><i class='ti ti-plug-x me-2'></i>A conexão com o Zabbix ainda não foi configurada.$link</div>";
    }

    public static function error(string $msg): string
    {
        return "<div class='alert alert-danger'><i class='ti ti-alert-triangle me-2'></i>" . htmlspecialchars($msg, ENT_QUOTES) . "</div>";
    }
}
