<?php

namespace GlpiPlugin\Rh;

use CommonDropdown;

/**
 * Cargo (lista suspensa).
 */
class Cargo extends CommonDropdown
{
    public static string $rightname = 'dropdown';

    public const DEFAULTS = [
        'Analista de Suporte N1', 'Analista de Suporte N2', 'Analista de Infraestrutura', 'Analista de Redes', 'Analista de Segurança da Informação',
        'Técnico de Campo', 'Desenvolvedor', 'Coordenador de TI', 'Gerente de Projetos', 'Analista Administrativo', 'Analista Financeiro',
        'Analista de RH', 'Executivo de Vendas', 'Pré-vendas', 'Estagiário',
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Cargos' : 'Cargo';
    }

    public static function getIcon()
    {
        return 'ti ti-id-badge';
    }

    public function getAdditionalFields()
    {
        return [
            ['name' => 'cbo', 'label' => 'CBO (Classificação Brasileira de Ocupações)', 'type' => 'text'],
            ['name' => 'job_description', 'label' => 'Job description', 'type' => 'tinymce'],
        ];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = ['id' => 101, 'table' => $this->getTable(), 'field' => 'cbo', 'name' => 'CBO', 'datatype' => 'string'];
        $tab[] = ['id' => 102, 'table' => $this->getTable(), 'field' => 'job_description', 'name' => 'Job description', 'datatype' => 'text', 'htmltext' => true];
        return $tab;
    }

    /** Cargo novo já abre com a estrutura do job description para preencher. */
    public function post_getEmpty()
    {
        $this->fields['job_description'] = JobDescription::blank();
    }

    /** Preenche o job description dos cargos padrão que ainda estão vazios. */
    public static function fillTemplates(): int
    {
        global $DB;
        $n = 0;
        foreach (JobDescription::names() as $name) {
            $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['name' => $name]])->current();
            if ($row && trim(strip_tags((string) $row['job_description'])) === '') {
                $jd = JobDescription::forRole($name);
                $DB->update(self::getTable(), ['job_description' => $jd['html'], 'cbo' => $row['cbo'] ?: $jd['cbo']], ['id' => $row['id']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Job description em texto simples, dividido para a requisição de vaga:
     * missão e responsabilidades vão para "Descrição e atividades"; requisitos e
     * competências para "Requisitos". Sem seções conhecidas, tudo vai na descrição.
     */
    public function jobTextForVaga(): array
    {
        $html = (string) ($this->fields['job_description'] ?? '');
        $parts = preg_split('~<h[1-6][^>]*>(.*?)</h[1-6]>~si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $text = function (string $h): string {
            // parágrafo só em negrito ("Formação e experiência") vira subtítulo com dois-pontos
            $h = preg_replace('~<p[^>]*>\s*<(strong|b)>([^<]+)</\1>\s*</p>~i', "\n$2:\n", $h);
            $h = preg_replace('~<li[^>]*>~i', "\n• ", $h);
            $h = preg_replace('~</(p|div|ul|ol)>|<br\s*/?>~i', "\n", $h);
            $h = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $h = str_replace("\u{a0}", ' ', $h);
            return implode("\n", array_filter(array_map('trim', explode("\n", $h)), fn ($l) => $l !== ''));
        };
        $desc = [];
        $req = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $title = trim(html_entity_decode(strip_tags($parts[$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $body = $text($parts[$i + 1] ?? '');
            if ($body === '') {
                continue;
            }
            $k = mb_strtolower($title);
            if (str_contains($k, 'miss') || str_contains($k, 'responsab') || str_contains($k, 'atividade')) {
                $desc[] = mb_strtoupper($title) . "\n" . $body;
            } elseif (str_contains($k, 'requisit') || str_contains($k, 'compet') || str_contains($k, 'conhecimento') || str_contains($k, 'forma')) {
                $req[] = mb_strtoupper($title) . "\n" . $body;
            }
        }
        if (!$desc && !$req) {
            $desc[] = $text($html);
        }
        return ['name' => (string) $this->fields['name'], 'description' => implode("\n\n", $desc), 'requirements' => implode("\n\n", $req)];
    }
}
