<?php

namespace GlpiPlugin\Ciclo;

use Document;
use CommonDBTM;
use CommonGLPI;
use Document_Item;
use DocumentCategory;
use Dropdown;
use Html;
use RuntimeException;
use Session;

/**
 * Documentos dos Ciclos: envio direto no Ciclo (vários de uma vez, com
 * categoria e fase), lista com visualização no painel lateral (PDF e imagens),
 * download e remoção, e a página com os documentos de todos os Ciclos.
 * Os arquivos são documentos do GLPI ligados ao Ciclo (aparecem também na aba Documentos).
 */
class Documentos extends CommonGLPI
{
    public const MAX_MB = 20;
    public const TABLE = 'glpi_plugin_ciclo_documentos';

    // ------------------------------------------------------------------ dados

    /** Documentos ligados a Ciclos (de um só ou de todos), do mais novo para o mais antigo. */
    public static function rows(?int $cicloId = null, array $filter = []): array
    {
        global $DB;
        $where = ['di.itemtype' => Ciclo::class, 'd.is_deleted' => 0];
        if ($cicloId) {
            $where['di.items_id'] = $cicloId;
        }
        if (!empty($filter['category'])) {
            $where['d.documentcategories_id'] = (int) $filter['category'];
        }
        if (!empty($filter['code'])) {
            $where['f.code'] = (string) $filter['code'];
        }
        if (!empty($filter['q'])) {
            $q = '%' . $filter['q'] . '%';
            $where[] = ['OR' => ['d.filename' => ['LIKE', $q], 'd.name' => ['LIKE', $q], 'c.name' => ['LIKE', $q], 'c.customer' => ['LIKE', $q]]];
        }
        if (!empty($filter['type'])) {
            $ext = ['pdf' => ['pdf'], 'img' => ['png', 'jpg', 'jpeg', 'gif', 'webp'], 'office' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'csv', 'txt']][$filter['type']] ?? [];
            $where[] = ['OR' => array_map(fn ($x) => ['d.filename' => ['LIKE', "%.$x"]], $ext)];
        }
        return iterator_to_array($DB->request([
            'SELECT' => ['d.id', 'd.name', 'd.filename', 'd.filepath', 'd.mime', 'd.documentcategories_id', 'd.date_creation', 'd.users_id', 'di.id AS link_id', 'di.items_id AS ciclo_id', 'c.name AS ciclo', 'c.customer', 'f.code'],
            'FROM' => Document::getTable() . ' AS d',
            'INNER JOIN' => [
                Document_Item::getTable() . ' AS di' => ['ON' => ['di' => 'documents_id', 'd' => 'id']],
                Ciclo::getTable() . ' AS c' => ['ON' => ['c' => 'id', 'di' => 'items_id']],
            ],
            'LEFT JOIN' => [self::TABLE . ' AS f' => ['ON' => ['f' => 'documents_id', 'd' => 'id', ['AND' => ['f.plugin_ciclo_ciclos_id' => new \Glpi\DBAL\QueryExpression($DB->quoteName('di.items_id'))]]]]],
            'WHERE' => $where + ['c.is_deleted' => 0],
            'ORDER' => 'd.id DESC',
            'LIMIT' => 500,
        ]), false);
    }

    // ------------------------------------------------------------------ envio e remoção

    /** Envia os arquivos do formulário como documentos do GLPI ligados ao Ciclo. Devolve quantos entraram. */
    public static function upload(Ciclo $c, array $files, int $category, string $code, string $note): int
    {
        global $DB;
        $names = (array) ($files['name'] ?? []);
        $ok = 0;
        $errors = [];
        foreach ($names as $i => $name) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = trim(str_replace(['/', '\\', "\0"], '_', basename((string) $name)));
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i])) {
                $errors[] = "$name: falha no envio" . ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ? ' (maior que o limite do servidor)' : '');
                continue;
            }
            if ((int) $files['size'][$i] > self::MAX_MB * 1024 * 1024) {
                $errors[] = "$name: passa de " . self::MAX_MB . ' MB';
                continue;
            }
            if (!Document::isValidDoc($name)) {
                $errors[] = "$name: tipo de arquivo não permitido no GLPI (Configurar → Listas suspensas → Tipos de documento)";
                continue;
            }
            $prefix = 'cf' . bin2hex(random_bytes(6)) . '_';
            if (!move_uploaded_file($files['tmp_name'][$i], GLPI_TMP_DIR . '/' . $prefix . $name)) {
                $errors[] = "$name: não consegui receber o arquivo";
                continue;
            }
            $doc = new Document();
            $id = (int) $doc->add([
                'name' => $name, 'comment' => $note, 'documentcategories_id' => $category,
                '_filename' => [$prefix . $name], '_prefix_filename' => [$prefix],
                'itemtype' => Ciclo::class, 'items_id' => $c->getID(), 'entities_id' => (int) $c->fields['entities_id'], 'is_recursive' => 1,
            ]);
            @unlink(GLPI_TMP_DIR . '/' . $prefix . $name);
            if (!$id) {
                $errors[] = "$name: o GLPI não aceitou o arquivo";
                continue;
            }
            if ($code !== '') {
                $DB->insert(self::TABLE, ['documents_id' => $id, 'plugin_ciclo_ciclos_id' => $c->getID(), 'code' => $code]);
            }
            Evento::record($c->getID(), $code ?: null, 'comment', 'Documento anexado: ' . $name);
            $ok++;
        }
        if ($errors) {
            throw new RuntimeException(($ok ? "$ok arquivo(s) anexado(s). " : '') . 'Não entraram: ' . implode('; ', $errors) . '.');
        }
        return $ok;
    }

    /** Tira o documento do Ciclo (o arquivo é apagado se não estiver ligado a mais nada). */
    public static function remove(Ciclo $c, int $documentId): void
    {
        global $DB;
        $link = new Document_Item();
        if (!$link->getFromDBByCrit(['documents_id' => $documentId, 'itemtype' => Ciclo::class, 'items_id' => $c->getID()])) {
            throw new RuntimeException('Documento não encontrado neste Ciclo.');
        }
        $doc = new Document();
        $doc->getFromDB($documentId);
        $link->delete(['id' => $link->getID()], true);
        $DB->delete(self::TABLE, ['documents_id' => $documentId, 'plugin_ciclo_ciclos_id' => $c->getID()]);
        if (!countElementsInTable(Document_Item::getTable(), ['documents_id' => $documentId])) {
            $doc->delete(['id' => $documentId], true);
        }
        Evento::record($c->getID(), null, 'comment', 'Documento removido: ' . ($doc->fields['filename'] ?? ''));
    }

    // ------------------------------------------------------------------ telas

    public static function url(array $d): string
    {
        return Html::getPrefixedUrl('/front/document.send.php?docid=' . (int) $d['id'] . '&itemtype=' . rawurlencode(Ciclo::class) . '&items_id=' . (int) $d['ciclo_id']);
    }

    /** [ícone, cor, tipo de pré-visualização] pela extensão. */
    public static function kind(string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match (true) {
            $ext === 'pdf' => ['ti-file-type-pdf', '#ef4444', 'pdf'],
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => ['ti-photo', '#a855f7', 'img'],
            in_array($ext, ['doc', 'docx', 'odt', 'rtf'], true) => ['ti-file-type-doc', '#3b82f6', ''],
            in_array($ext, ['xls', 'xlsx', 'ods', 'csv'], true) => ['ti-file-type-xls', '#22c55e', ''],
            in_array($ext, ['ppt', 'pptx', 'odp'], true) => ['ti-file-type-ppt', '#f97316', ''],
            in_array($ext, ['zip', 'rar', '7z'], true) => ['ti-file-zip', '#94a3b8', ''],
            default => ['ti-file', '#94a3b8', ''],
        };
    }

    private static function size(array $d): string
    {
        $f = GLPI_DOC_DIR . '/' . $d['filepath'];
        $b = is_file($f) ? filesize($f) : 0;
        return $b > 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 0, ',', '.') . ' KB';
    }

    /** Um documento na lista: ícone, nome, categoria, fase, tamanho, quem enviou, ações. */
    private static function row(array $d, bool $showCiclo, bool $canRemove, string $removeForm = ''): string
    {
        $e = [Menu::class, 'e'];
        [$icon, $color, $preview] = self::kind((string) $d['filename']);
        $url = self::url($d);
        $cat = $d['documentcategories_id'] ? Dropdown::getDropdownName(DocumentCategory::getTable(), (int) $d['documentcategories_id'], false, true, false, '') : '';
        $meta = array_filter([
            $showCiclo ? "<a href='" . $e(Ciclo::getFormURLWithID((int) $d['ciclo_id'])) . "'><b>" . $e(Ciclo::code((int) $d['ciclo_id'])) . "</b> " . $e($d['ciclo']) . "</a>" : '',
            $d['code'] ? 'fase ' . $e(Fase::name((string) $d['code'])) : '',
            self::size($d), $e(getUserName((int) $d['users_id'])), Menu::date($d['date_creation'], true),
        ]);
        $tip = json_encode(['title' => (string) $d['filename'], 'url' => $url, 'kind' => $preview, 'icon' => $icon, 'color' => $color,
            'meta' => strip_tags(implode(' · ', $meta)) . ($cat ? " · $cat" : '')], JSON_UNESCAPED_UNICODE);
        return "<div class='cf-doc'><span class='cf-doc-icon' style='--c:$color'><i class='ti $icon'></i></span>"
            . "<div class='cf-doc-main'><div class='cf-doc-name'>" . ($preview ? "<a href='#' data-doc='" . $e($tip) . "'>" . $e($d['filename']) . "</a>" : "<a href='" . $e($url) . "'>" . $e($d['filename']) . "</a>")
            . ($cat ? "<span class='cf-doc-cat'>" . $e($cat) . "</span>" : '') . "</div><div class='cf-doc-meta'>" . implode(' · ', $meta) . "</div></div>"
            . "<div class='cf-doc-actions'>" . ($preview ? "<button type='button' class='btn btn-sm btn-ghost-primary' data-doc='" . $e($tip) . "' title='Visualizar'><i class='ti ti-eye'></i></button>" : '')
            . "<a class='btn btn-sm btn-ghost-secondary' href='" . $e($url) . "' title='Baixar' download><i class='ti ti-download'></i></a>"
            . ($canRemove ? $removeForm : '') . "</div></div>";
    }

    /** Ícone de arquivo (folha com dobra) na cor do tipo: PDF, texto, planilha, imagem, apresentação. */
    public static function fileIcon(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        [$color, $glyph] = match (true) {
            $ext === 'pdf' => ['#e5484d', "<text x='14' y='27' text-anchor='middle' font-size='7.5' font-weight='800' font-family='Arial,sans-serif' fill='#fff'>PDF</text>"],
            in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'txt'], true) => ['#3b74d6', "<path d='M7 17h14M7 21h14M7 25h9' stroke='#fff' stroke-width='1.8' stroke-linecap='round'/>"],
            in_array($ext, ['xls', 'xlsx', 'ods', 'csv'], true) => ['#1f9d55', "<rect x='7' y='16' width='14' height='11' rx='1.5' fill='none' stroke='#fff' stroke-width='1.6'/><path d='M7 21.5h14M14 16v11' stroke='#fff' stroke-width='1.6'/>"],
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => ['#8b5cf6', "<circle cx='10.5' cy='18' r='2' fill='#fff'/><path d='M6 27l5-5 3 3 4-5 4 7z' fill='#fff'/>"],
            in_array($ext, ['ppt', 'pptx', 'odp'], true) => ['#ea7a1f', "<rect x='7' y='16' width='14' height='9' rx='1.5' fill='none' stroke='#fff' stroke-width='1.6'/><path d='M14 25v3' stroke='#fff' stroke-width='1.6'/>"],
            default => ['#94a3b8', "<text x='14' y='27' text-anchor='middle' font-size='6.5' font-weight='800' font-family='Arial,sans-serif' fill='#fff'>" . Menu::e(strtoupper(substr($ext, 0, 4))) . "</text>"],
        };
        return "<svg class='oak-dl-icon' viewBox='0 0 28 34' aria-hidden='true'><path d='M4 0h14l10 10v20a4 4 0 0 1-4 4H4a4 4 0 0 1-4-4V4a4 4 0 0 1 4-4z' fill='$color'/>"
            . "<path d='M18 0v6a4 4 0 0 0 4 4h6z' fill='#fff' fill-opacity='.45'/>$glyph</svg>";
    }

    /** Tamanho do arquivo em KiB / MiB, como na lista de documentos da abertura do chamado. */
    public static function kib(array $d): string
    {
        $f = GLPI_DOC_DIR . '/' . $d['filepath'];
        $b = is_file($f) ? filesize($f) : 0;
        return $b >= 1048576 ? number_format($b / 1048576, 2, ',', '.') . ' MiB' : number_format($b / 1024, 2, ',', '.') . ' KiB';
    }

    /**
     * Cartão compacto: título em caixa alta e a contagem, uma linha por arquivo (ícone, nome,
     * tamanho e "Abrir", que mostra PDF e imagens no painel lateral) e o envio recolhido em "Anexar".
     * $rows já vem com 'url', 'tip' (JSON do painel, ou '' quando só baixa), 'sub' e 'remove' (form).
     */
    public static function compact(string $id, string $title, array $rows, string $form = ''): string
    {
        $e = [Menu::class, 'e'];
        $n = count($rows);
        $h = "<div class='card mb-3 oak-dl' id='" . $e($id) . "'><div class='oak-dl-head'><span>" . $e($title) . "</span><small>$n " . ($n === 1 ? 'arquivo' : 'arquivos') . "</small></div><div class='oak-dl-list'>";
        foreach ($rows as $d) {
            $open = $d['tip'] !== '' ? "href='#' data-doc='" . $e($d['tip']) . "'" : "href='" . $e($d['url']) . "' target='_blank' rel='noopener'";
            $h .= "<div class='oak-dl-row'>" . self::fileIcon((string) $d['filename'])
                . "<div class='oak-dl-main'><a class='oak-dl-name' $open title='" . $e($d['filename']) . "'>" . $e($d['filename']) . "</a><div class='oak-dl-sub'>" . $d['sub'] . "</div></div>"
                . "<a class='oak-dl-open' $open>Abrir<i class='ti ti-arrow-up-right'></i></a>" . ($d['remove'] ?? '') . "</div>";
        }
        if (!$rows) {
            $h .= "<div class='oak-dl-empty'><i class='ti ti-files'></i>Nenhum arquivo anexado ainda.</div>";
        }
        $h .= "</div>";
        if ($form !== '') {
            $h .= "<details class='oak-dl-add'" . ($rows ? '' : ' open') . "><summary><i class='ti ti-paperclip'></i>Anexar arquivos</summary>$form</details>";
        }
        return $h . "</div>";
    }

    /** Card no formulário do Ciclo: lista compacta e o envio recolhido em "Anexar arquivos". */
    public static function card(Ciclo $c, bool $edit): string
    {
        $e = [Menu::class, 'e'];
        $action = $e(Ciclo::getFormURL());
        $hidden = "<input type='hidden' name='id' value='" . (int) $c->getID() . "'>";
        $rows = [];
        foreach (self::rows($c->getID()) as $d) {
            [$icon, $color, $preview] = self::kind((string) $d['filename']);
            $cat = $d['documentcategories_id'] ? Dropdown::getDropdownName(DocumentCategory::getTable(), (int) $d['documentcategories_id'], false, true, false, '') : '';
            $phase = $d['code'] ? Fase::name((string) $d['code']) : '';
            $meta = implode(' · ', array_filter([$phase ? "fase $phase" : '', self::size($d), getUserName((int) $d['users_id']), Menu::date($d['date_creation'], true), $cat]));
            $rows[] = $d + [
                'url' => self::url($d),
                'tip' => $preview ? json_encode(['title' => (string) $d['filename'], 'url' => self::url($d), 'kind' => $preview, 'icon' => $icon, 'color' => $color, 'meta' => $meta], JSON_UNESCAPED_UNICODE) : '',
                'sub' => implode(' · ', array_map($e, array_filter([self::kib($d), $phase, $cat]))),
                'remove' => $edit ? "<form method='post' action='$action' class='oak-dl-del'>$hidden<input type='hidden' name='doc_id' value='" . (int) $d['id'] . "'>"
                    . "<button name='doc_remove' value='1' title='Remover do Ciclo' onclick=\"return confirm('Remover este documento do Ciclo?')\"><i class='ti ti-x'></i></button>" . Html::closeForm(false) : '',
            ];
        }
        $form = '';
        if ($edit) {
            $phases = ['' => '— sem fase —'];
            foreach (Fase::forCiclo($c->getID()) as $code => $p) {
                $phases[$code] = Fase::name($code);
            }
            $active = Fase::active($c->getID());
            $form = "<form method='post' action='$action' enctype='multipart/form-data' class='oak-dl-form'>$hidden"
                . "<div class='cf-doc-drop'><i class='ti ti-cloud-upload'></i><div><b>Arraste ou clique para escolher</b><span>NF, contrato, termo de aceite, cotação, pedido… até " . self::MAX_MB . " MB cada</span></div>"
                . "<input type='file' name='docs[]' multiple data-doc-input></div><div class='cf-doc-chosen small text-secondary' data-doc-chosen></div>"
                . "<div class='row g-2 align-items-end mt-1'><div class='col-md-4'><label class='form-label small mb-0'>Categoria</label>" . DocumentCategory::dropdown(['name' => 'doc_category', 'display' => false, 'width' => '100%']) . "</div>"
                . "<div class='col-md-3'><label class='form-label small mb-0'>Fase</label>" . Dropdown::showFromArray('doc_code', $phases, ['value' => $active['code'] ?? '', 'display' => false, 'width' => '100%']) . "</div>"
                . "<div class='col-md-3'><label class='form-label small mb-0'>Observação</label><input class='form-control' name='doc_note' maxlength='255' placeholder='opcional'></div>"
                . "<div class='col-md-2'><button class='btn btn-primary w-100' name='doc_upload' value='1'><i class='ti ti-upload me-1'></i>Anexar</button></div></div>" . Html::closeForm(false);
        }
        return self::compact('cf-docs', 'Documentos do Ciclo', $rows, $form);
    }

    /**
     * Biblioteca de documentos dos Ciclos: pastas à esquerda (por Ciclo, por
     * categoria e por fase, com a contagem), busca, grade de cartões (imagens
     * com miniatura) ou lista, e visualização no painel lateral.
     */
    public static function page(array $get): void
    {
        $e = [Menu::class, 'e'];
        $etapas = Etapa::all();
        $filter = [
            'q' => trim((string) ($get['q'] ?? '')), 'category' => (int) ($get['category'] ?? 0),
            'code' => isset($etapas[$get['code'] ?? '']) ? $get['code'] : '', 'type' => in_array($get['type'] ?? '', ['pdf', 'img', 'office'], true) ? $get['type'] : '',
        ];
        $ciclo = (int) ($get['ciclo'] ?? 0);
        $view = ($get['view'] ?? 'grade') === 'lista' ? 'lista' : 'grade';
        $all = self::rows();
        $rows = self::rows($ciclo ?: null, $filter);
        $url = fn (array $q) => '?' . http_build_query(array_filter($q + ['ciclo' => $ciclo, 'view' => $view] + $filter));

        // pastas com a contagem (sempre sobre todos os documentos)
        $byCiclo = [];
        $byCat = [];
        $byCode = [];
        foreach ($all as $d) {
            $byCiclo[(int) $d['ciclo_id']] ??= ['n' => 0, 'name' => $d['ciclo'], 'customer' => $d['customer']];
            $byCiclo[(int) $d['ciclo_id']]['n']++;
            $byCat[(int) $d['documentcategories_id']] = ($byCat[(int) $d['documentcategories_id']] ?? 0) + 1;
            if ($d['code']) {
                $byCode[$d['code']] = ($byCode[$d['code']] ?? 0) + 1;
            }
        }
        $folder = function (string $label, int $n, array $q, bool $on, string $icon = 'ti-folder', string $sub = '') use ($e, $url) {
            return "<a class='cf-lib-folder" . ($on ? ' on' : '') . "' href='" . $e($url($q)) . "'><i class='ti " . ($on ? str_replace('ti-folder', 'ti-folder-open', $icon) : $icon) . "'></i>"
                . "<span>" . $e($label) . ($sub !== '' ? "<small>" . $e($sub) . "</small>" : '') . "</span><b>$n</b></a>";
        };
        $none = !$ciclo && !$filter['category'] && !$filter['code'] && !$filter['type'];
        $side = $folder('Todos os documentos', count($all), ['ciclo' => 0, 'category' => 0, 'code' => '', 'type' => ''], $none, 'ti-files');
        $side .= "<div class='cf-lib-head'>Tipo</div>";
        foreach (['pdf' => ['PDF', 'ti-file-type-pdf'], 'img' => ['Imagens', 'ti-photo'], 'office' => ['Office e planilhas', 'ti-file-spreadsheet']] as $k => [$label, $icon]) {
            $n = count(array_filter($all, fn ($d) => self::group((string) $d['filename']) === $k));
            $side .= $folder($label, $n, ['type' => $filter['type'] === $k ? '' : $k], $filter['type'] === $k, $icon);
        }
        $side .= "<div class='cf-lib-head'>Por Ciclo</div>";
        foreach ($byCiclo as $id => $x) {
            $side .= $folder(Ciclo::code($id) . ' · ' . $x['name'], $x['n'], ['ciclo' => $ciclo === $id ? 0 : $id], $ciclo === $id, 'ti-folder', (string) $x['customer']);
        }
        $side .= "<div class='cf-lib-head'>Por categoria</div>";
        foreach ($byCat as $cat => $n) {
            $side .= $folder($cat ? Dropdown::getDropdownName(DocumentCategory::getTable(), $cat, false, true, false, '') : 'Sem categoria', $n, ['category' => $filter['category'] === $cat ? 0 : $cat], $filter['category'] === $cat && $cat, 'ti-tag');
        }
        $side .= "<div class='cf-lib-head'>Por fase</div>";
        foreach ($etapas as $code => $et) {
            if (!empty($byCode[$code])) {
                $side .= $folder($et['name'], $byCode[$code], ['code' => $filter['code'] === $code ? '' : $code], $filter['code'] === $code, 'ti-git-commit');
            }
        }

        // título do que está aberto
        $crumbs = array_filter([
            $ciclo ? Ciclo::code($ciclo) . ' · ' . ($byCiclo[$ciclo]['name'] ?? '') : '',
            $filter['category'] ? Dropdown::getDropdownName(DocumentCategory::getTable(), $filter['category'], false, true, false, '') : '',
            $filter['code'] ? 'fase ' . ($etapas[$filter['code']]['name'] ?? '') : '',
            $filter['type'] ? ['pdf' => 'PDF', 'img' => 'imagens', 'office' => 'Office e planilhas'][$filter['type']] : '',
        ]);

        echo "<div class='cf'>" . Menu::title('Documentos dos Ciclos', 'documentos', Menu::button('Ciclos', Ciclo::getSearchURL(), 'ti-list', 'btn-outline-secondary'));
        echo "<div class='cf-lib'><aside class='cf-lib-side card'>$side</aside><section class='cf-lib-main'>";
        echo "<div class='card mb-3'><div class='card-body cf-lib-bar'>"
            . "<div class='cf-lib-where'><i class='ti ti-folder-open'></i><b>" . ($crumbs ? $e(implode(' › ', $crumbs)) : 'Todos os documentos') . "</b><span>" . count($rows) . " documento(s)</span></div>"
            . "<form method='get' class='d-flex gap-2 m-0'>" . implode('', array_map(fn ($k, $v) => $v ? "<input type='hidden' name='$k' value='" . $e($v) . "'>" : '', array_keys(['ciclo' => 1, 'category' => 1, 'code' => 1, 'type' => 1, 'view' => 1]), [$ciclo, $filter['category'], $filter['code'], $filter['type'], $view]))
            . "<div class='input-icon'><span class='input-icon-addon'><i class='ti ti-search'></i></span><input class='form-control' name='q' value='" . $e($filter['q']) . "' placeholder='Buscar arquivo, Ciclo ou cliente' style='width:260px'></div>" . Html::closeForm(false)
            . "<div class='btn-group'><a class='btn " . ($view === 'grade' ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . $e($url(['view' => 'grade'])) . "' title='Grade'><i class='ti ti-layout-grid'></i></a>"
            . "<a class='btn " . ($view === 'lista' ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . $e($url(['view' => 'lista'])) . "' title='Lista'><i class='ti ti-list'></i></a></div></div></div>";

        if (!$rows) {
            echo "<div class='card'><div class='card-body text-secondary text-center py-5'><i class='ti ti-files-off fs-1 d-block mb-2'></i>Nenhum documento" . ($crumbs || $filter['q'] ? ' aqui' : '') . ".</div></div>";
        } elseif ($view === 'lista') {
            echo "<div class='card'><div class='cf-docs-list'>";
            foreach ($rows as $d) {
                echo self::row($d, true, false);
            }
            echo "</div></div>";
        } else {
            echo "<div class='cf-lib-grid'>";
            foreach ($rows as $d) {
                echo self::tile($d);
            }
            echo "</div>";
        }
        echo "</section></div>" . Menu::script('drawer.js') . Menu::script('ciclo.js') . "</div>";
    }

    /** pdf | img | office | '' */
    private static function group(string $filename): string
    {
        $k = self::kind($filename)[2];
        return $k ?: (preg_match('/\.(docx?|xlsx?|pptx?|od[tsp]|rtf|csv|txt)$/i', $filename) ? 'office' : '');
    }

    /** Cartão da grade: miniatura (a própria imagem ou o tipo do arquivo), nome, Ciclo, categoria e data. */
    private static function tile(array $d): string
    {
        $e = [Menu::class, 'e'];
        [$icon, $color, $preview] = self::kind((string) $d['filename']);
        $url = self::url($d);
        $ext = strtoupper(pathinfo((string) $d['filename'], PATHINFO_EXTENSION));
        $cat = $d['documentcategories_id'] ? Dropdown::getDropdownName(DocumentCategory::getTable(), (int) $d['documentcategories_id'], false, true, false, '') : '';
        $meta = Ciclo::code((int) $d['ciclo_id']) . ' · ' . $d['ciclo'] . ($d['code'] ? ' · fase ' . Fase::name((string) $d['code']) : '') . ' · ' . self::size($d) . ' · ' . Menu::date($d['date_creation']);
        $tip = json_encode(['title' => (string) $d['filename'], 'url' => $url, 'kind' => $preview, 'icon' => $icon, 'color' => $color, 'meta' => $meta . ($cat ? " · $cat" : '')], JSON_UNESCAPED_UNICODE);
        // miniatura: a própria imagem; PDF vira uma folha com a categoria no cabeçalho (cor por categoria); os demais, o tipo do arquivo
        $palette = ['#2563eb', '#0f766e', '#7c3aed', '#ea580c', '#d97706', '#059669', '#db2777', '#0891b2', '#16a34a', '#4f46e5', '#15803d', '#b45309'];
        $band = $palette[(int) $d['documentcategories_id'] % count($palette)];
        $thumb = match ($preview) {
            'img' => "<img src='" . $e($url) . "' alt='' loading='lazy'>",
            'pdf' => "<div class='cf-lib-paper'><div class='cf-lib-sheet'><div class='cf-lib-band' style='background:$band'>" . $e($cat ?: pathinfo((string) $d['filename'], PATHINFO_FILENAME)) . "</div>"
                . "<i></i><i></i><i class='s'></i><i></i><i class='s'></i><i></i></div><span class='cf-lib-badge'>PDF</span></div>",
            default => "<div class='cf-lib-type' style='--c:$color'><i class='ti $icon'></i><span>$ext</span></div>",
        };
        $open = $preview ? "href='#' data-doc='" . $e($tip) . "'" : "href='" . $e($url) . "' download";
        return "<div class='cf-lib-tile' style='--c:$color'><a class='cf-lib-thumb' $open title='" . ($preview ? 'Visualizar' : 'Baixar') . "'>$thumb"
            . "<span class='cf-lib-hover'><i class='ti " . ($preview ? 'ti-eye' : 'ti-download') . "'></i>" . ($preview ? 'Visualizar' : 'Baixar') . "</span></a>"
            . "<div class='cf-lib-info'><a class='cf-lib-name' $open title='" . $e($d['filename']) . "'>" . $e($d['filename']) . "</a>"
            . ($cat ? "<span class='cf-doc-cat'>" . $e($cat) . "</span>" : '')
            . "<div class='cf-lib-meta'><a href='" . $e(Ciclo::getFormURLWithID((int) $d['ciclo_id'])) . "'>" . $e(Ciclo::code((int) $d['ciclo_id'])) . "</a> · " . $e(Menu::date($d['date_creation'])) . ' · ' . $e(self::size($d)) . "</div>"
            . "<div class='cf-lib-actions'><a class='btn btn-sm btn-ghost-secondary' href='" . $e($url) . "' download title='Baixar'><i class='ti ti-download'></i></a></div></div></div>";
    }

    // ------------------------------------------------------------------ aba Documentos (no lugar da aba nativa)

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem()) {
            return '';
        }
        $n = $_SESSION['glpishow_count_on_tabs'] ? countElementsInTable(Document_Item::getTable(), ['itemtype' => $item::class, 'items_id' => $item->getID()]) : 0;
        return self::createTabEntry('Documentos', $n, $item::class, 'ti ti-files');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        echo "<div class='oak-dl-tab'>" . self::card($item, $item->canEditData()) . Menu::script('drawer.js') . Menu::script('ciclo.js') . "</div>";
        return true;
    }
}
