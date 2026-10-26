<?php

namespace GlpiPlugin\Rh;

use CommonDBTM;
use Document;
use Document_Item;
use DocumentCategory;
use Dropdown;
use Html;
use RuntimeException;
use Session;

/**
 * Biblioteca de documentos (a mesma nos plugins RH, Reembolso, PMO e Ciclo):
 * envio direto no item (vários arquivos, com categoria e observação), lista com
 * visualização de PDF e imagens no painel lateral, e a página com todos os
 * documentos do plugin em pastas (tipo, origem, item e categoria), em grade ou lista.
 * Os arquivos são documentos do GLPI ligados ao item (aparecem também na aba Documentos).
 *
 * $sources: itemtype => [
 *   'label' => 'Vagas', 'icon' => 'ti-briefcase', 'color' => '#3b82f6',
 *   'code'  => fn (array $f): string,  // ex.: VG-3
 *   'title' => fn (array $f): string,  // nome do item
 *   'sub'   => fn (array $f): string,  // cliente, técnico…
 *   'edit'  => fn (CommonDBTM $item): bool, // opcional; padrão canUpdateItem()
 * ]
 */
class Biblioteca
{
    public const MAX_MB = 20;

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    // ------------------------------------------------------------------ dados

    /** Documentos dos itens das origens (só dos itens que o usuário pode ver), do mais novo. */
    public static function rows(array $sources, ?string $itemtype = null, int $itemId = 0, array $filter = []): array
    {
        global $DB;
        $types = $itemtype ? [$itemtype] : array_keys(array_filter($sources, fn ($s, $t) => $t::canView(), ARRAY_FILTER_USE_BOTH));
        if (!$types) {
            return [];
        }
        $where = ['di.itemtype' => $types, 'd.is_deleted' => 0];
        if ($itemId) {
            $where['di.items_id'] = $itemId;
        }
        if (!empty($filter['category'])) {
            $where['d.documentcategories_id'] = (int) $filter['category'];
        }
        if (!empty($filter['type'])) {
            $ext = ['pdf' => ['pdf'], 'img' => ['png', 'jpg', 'jpeg', 'gif', 'webp'], 'office' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'csv', 'txt']][$filter['type']] ?? [];
            $where[] = ['OR' => array_map(fn ($x) => ['d.filename' => ['LIKE', "%.$x"]], $ext)];
        }
        $rows = [];
        $items = [];
        foreach ($DB->request([
            'SELECT' => ['d.id', 'd.name', 'd.filename', 'd.filepath', 'd.documentcategories_id', 'd.date_creation', 'd.users_id', 'd.comment', 'di.itemtype', 'di.items_id'],
            'FROM' => Document::getTable() . ' AS d',
            'INNER JOIN' => [Document_Item::getTable() . ' AS di' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE' => $where, 'ORDER' => 'd.id DESC', 'LIMIT' => 800,
        ]) as $r) {
            $key = $r['itemtype'] . '#' . $r['items_id'];
            if (!array_key_exists($key, $items)) {
                $obj = new $r['itemtype']();
                $items[$key] = $obj->getFromDB((int) $r['items_id']) && $obj->canViewItem() && empty($obj->fields['is_deleted']) ? $obj->fields : null;
            }
            if ($items[$key] === null) {
                continue;
            }
            $s = $sources[$r['itemtype']];
            $r['item_code'] = ($s['code'])($items[$key]);
            $r['item_title'] = ($s['title'])($items[$key]);
            $r['item_sub'] = isset($s['sub']) ? ($s['sub'])($items[$key]) : '';
            if (!empty($filter['q']) && mb_stripos($r['filename'] . ' ' . $r['name'] . ' ' . $r['item_code'] . ' ' . $r['item_title'] . ' ' . $r['item_sub'], $filter['q']) === false) {
                continue;
            }
            $rows[] = $r;
        }
        return $rows;
    }

    public static function url(array $d): string
    {
        return Html::getPrefixedUrl('/front/document.send.php?docid=' . (int) $d['id'] . '&itemtype=' . rawurlencode((string) $d['itemtype']) . '&items_id=' . (int) $d['items_id']);
    }

    /** [ícone, cor, pré-visualização] pela extensão. */
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

    private static function group(string $filename): string
    {
        $k = self::kind($filename)[2];
        return $k ?: (preg_match('/\.(docx?|xlsx?|pptx?|od[tsp]|rtf|csv|txt)$/i', $filename) ? 'office' : '');
    }

    private static function size(array $d): string
    {
        $f = GLPI_DOC_DIR . '/' . $d['filepath'];
        $b = is_file($f) ? filesize($f) : 0;
        return $b > 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 0, ',', '.') . ' KB';
    }

    private static function category(array $d): string
    {
        return $d['documentcategories_id'] ? Dropdown::getDropdownName(DocumentCategory::getTable(), (int) $d['documentcategories_id'], false, true, false, '') : '';
    }

    private static function date(?string $d, bool $time = false): string
    {
        return $d ? date($time ? 'd/m/Y H:i' : 'd/m/Y', strtotime($d)) : '—';
    }

    private static function tip(array $d, string $meta): string
    {
        [$icon, $color, $preview] = self::kind((string) $d['filename']);
        return json_encode(['title' => (string) $d['filename'], 'url' => self::url($d), 'kind' => $preview, 'icon' => $icon, 'color' => $color, 'meta' => $meta], JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------ envio e remoção

    public static function upload(CommonDBTM $item, array $files, int $category, string $note): int
    {
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
                $errors[] = "$name: falha no envio";
                continue;
            }
            if ((int) $files['size'][$i] > self::MAX_MB * 1024 * 1024) {
                $errors[] = "$name: passa de " . self::MAX_MB . ' MB';
                continue;
            }
            if (!Document::isValidDoc($name)) {
                $errors[] = "$name: tipo de arquivo não permitido no GLPI";
                continue;
            }
            $prefix = 'lib' . bin2hex(random_bytes(6)) . '_';
            if (!move_uploaded_file($files['tmp_name'][$i], GLPI_TMP_DIR . '/' . $prefix . $name)) {
                $errors[] = "$name: não consegui receber o arquivo";
                continue;
            }
            $doc = new Document();
            $id = (int) $doc->add([
                'name' => $name, 'comment' => $note, 'documentcategories_id' => $category, '_filename' => [$prefix . $name], '_prefix_filename' => [$prefix],
                'itemtype' => $item::class, 'items_id' => $item->getID(), 'entities_id' => (int) ($item->fields['entities_id'] ?? 0), 'is_recursive' => 1,
            ]);
            @unlink(GLPI_TMP_DIR . '/' . $prefix . $name);
            $id ? $ok++ : $errors[] = "$name: o GLPI não aceitou o arquivo";
        }
        if ($errors) {
            throw new RuntimeException(($ok ? "$ok arquivo(s) anexado(s). " : '') . 'Não entraram: ' . implode('; ', $errors) . '.');
        }
        return $ok;
    }

    public static function remove(CommonDBTM $item, int $documentId): void
    {
        $link = new Document_Item();
        if (!$link->getFromDBByCrit(['documents_id' => $documentId, 'itemtype' => $item::class, 'items_id' => $item->getID()])) {
            throw new RuntimeException('Documento não encontrado neste item.');
        }
        $link->delete(['id' => $link->getID()], true);
        if (!countElementsInTable(Document_Item::getTable(), ['documents_id' => $documentId])) {
            (new Document())->delete(['id' => $documentId], true);
        }
    }

    /** Trata o POST do card (front/documentos.form.php de cada plugin). */
    public static function handle(array $sources, array $post, array $files): string
    {
        $itemtype = (string) ($post['itemtype'] ?? '');
        if (!isset($sources[$itemtype])) {
            throw new RuntimeException('Tipo de item inválido.');
        }
        $item = new $itemtype();
        if (!$item->getFromDB((int) ($post['items_id'] ?? 0))) {
            throw new RuntimeException('Item não encontrado.');
        }
        $can = isset($sources[$itemtype]['edit']) ? ($sources[$itemtype]['edit'])($item) : $item->canUpdateItem();
        if (!$can) {
            throw new RuntimeException('Você não pode alterar os documentos deste item.');
        }
        if (isset($post['doc_remove'])) {
            self::remove($item, (int) ($post['doc_id'] ?? 0));
            return 'Documento removido.';
        }
        $n = self::upload($item, (array) ($files['docs'] ?? []), (int) ($post['doc_category'] ?? 0), trim((string) ($post['doc_note'] ?? '')));
        return $n ? "$n documento(s) anexado(s)." : 'Escolha pelo menos um arquivo.';
    }

    // ------------------------------------------------------------------ card no formulário do item

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
            default => ['#94a3b8', "<text x='14' y='27' text-anchor='middle' font-size='6.5' font-weight='800' font-family='Arial,sans-serif' fill='#fff'>" . self::e(strtoupper(substr($ext, 0, 4))) . "</text>"],
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
        $e = [self::class, 'e'];
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

    public static function card(array $sources, CommonDBTM $item, string $endpoint, string $title = 'Documentos', string $hint = ''): string
    {
        $e = [self::class, 'e'];
        $itemtype = $item::class;
        $edit = isset($sources[$itemtype]['edit']) ? ($sources[$itemtype]['edit'])($item) : $item->canUpdateItem();
        $back = $e($_SERVER['REQUEST_URI'] ?? '');
        $hidden = "<input type='hidden' name='itemtype' value='" . $e($itemtype) . "'><input type='hidden' name='items_id' value='" . (int) $item->getID() . "'><input type='hidden' name='back' value='$back'>";
        $rows = [];
        foreach (self::rows($sources, $itemtype, $item->getID()) as $d) {
            $cat = self::category($d);
            $preview = self::kind((string) $d['filename'])[2];
            $meta = implode(' · ', array_filter([self::size($d), getUserName((int) $d['users_id']), self::date($d['date_creation'], true), $cat, (string) $d['comment']]));
            $rows[] = $d + [
                'url' => self::url($d), 'tip' => $preview ? self::tip($d, $meta) : '',
                'sub' => self::kib($d) . ($cat ? " · " . $e($cat) : ''),
                'remove' => $edit ? "<form method='post' action='" . $e($endpoint) . "' class='oak-dl-del'>$hidden<input type='hidden' name='doc_id' value='" . (int) $d['id'] . "'>"
                    . "<button name='doc_remove' value='1' title='Remover' onclick=\"return confirm('Remover este documento?')\"><i class='ti ti-x'></i></button>" . Html::closeForm(false) : '',
            ];
        }
        $form = !$edit ? '' : "<form method='post' action='" . $e($endpoint) . "' enctype='multipart/form-data' class='oak-dl-form'>$hidden"
            . "<div class='oak-doc-drop'><i class='ti ti-cloud-upload'></i><div><b>Arraste ou clique para escolher</b><span>" . $e($hint ?: 'Vários de uma vez') . " · até " . self::MAX_MB . " MB cada</span></div>"
            . "<input type='file' name='docs[]' multiple data-doc-input></div><div class='oak-doc-chosen small text-secondary' data-doc-chosen></div>"
            . "<label class='form-label small mb-0 mt-2'>Categoria</label>" . DocumentCategory::dropdown(['name' => 'doc_category', 'display' => false, 'width' => '100%'])
            . "<label class='form-label small mb-0 mt-2'>Observação</label><input class='form-control' name='doc_note' maxlength='255' placeholder='opcional'>"
            . "<button class='btn btn-primary w-100 mt-2' name='doc_upload' value='1'><i class='ti ti-upload me-1'></i>Anexar</button>" . Html::closeForm(false);
        return self::compact('oak-docs', $title, $rows, $form);
    }

    /** Uma linha: ícone, nome, categoria, origem, tamanho, quem enviou, ações. */
    private static function line(array $d, bool $showItem, string $remove = '', array $sources = []): string
    {
        $e = [self::class, 'e'];
        [$icon, $color, $preview] = self::kind((string) $d['filename']);
        $url = self::url($d);
        $cat = self::category($d);
        $meta = array_filter([
            $showItem ? "<a href='" . $e($d['itemtype']::getFormURLWithID((int) $d['items_id'])) . "'><b>" . $e($d['item_code']) . "</b> " . $e($d['item_title']) . "</a>" : '',
            self::size($d), $e(getUserName((int) $d['users_id'])), self::date($d['date_creation'], true), $d['comment'] ? $e($d['comment']) : '',
        ]);
        $tip = $e(self::tip($d, strip_tags(implode(' · ', $meta)) . ($cat ? " · $cat" : '')));
        return "<div class='oak-doc'><span class='oak-doc-icon' style='--c:$color'><i class='ti $icon'></i></span>"
            . "<div class='oak-doc-main'><div class='oak-doc-name'>" . ($preview ? "<a href='#' data-doc='$tip'>" : "<a href='" . $e($url) . "' download>") . $e($d['filename']) . "</a>"
            . ($cat ? "<span class='oak-doc-cat'>" . $e($cat) . "</span>" : '') . "</div><div class='oak-doc-meta'>" . implode(' · ', $meta) . "</div></div>"
            . "<div class='oak-doc-actions'>" . ($preview ? "<button type='button' class='btn btn-sm btn-ghost-primary' data-doc='$tip' title='Visualizar'><i class='ti ti-eye'></i></button>" : '')
            . "<a class='btn btn-sm btn-ghost-secondary' href='" . $e($url) . "' title='Baixar' download><i class='ti ti-download'></i></a>$remove</div></div>";
    }

    /** Cartão da grade. */
    private static function tile(array $d): string
    {
        $e = [self::class, 'e'];
        [$icon, $color, $preview] = self::kind((string) $d['filename']);
        $url = self::url($d);
        $cat = self::category($d);
        $ext = strtoupper(pathinfo((string) $d['filename'], PATHINFO_EXTENSION));
        $palette = ['#2563eb', '#0f766e', '#7c3aed', '#ea580c', '#d97706', '#059669', '#db2777', '#0891b2', '#16a34a', '#4f46e5', '#15803d', '#b45309'];
        $band = $palette[(int) $d['documentcategories_id'] % count($palette)];
        $tip = $e(self::tip($d, $d['item_code'] . ' · ' . $d['item_title'] . ' · ' . self::size($d) . ' · ' . self::date($d['date_creation']) . ($cat ? " · $cat" : '')));
        $thumb = match ($preview) {
            'img' => "<img src='" . $e($url) . "' alt='' loading='lazy'>",
            'pdf' => "<div class='oak-lib-paper'><div class='oak-lib-sheet'><div class='oak-lib-band' style='background:$band'>" . $e($cat ?: pathinfo((string) $d['filename'], PATHINFO_FILENAME)) . "</div>"
                . "<i></i><i></i><i class='s'></i><i></i><i class='s'></i><i></i></div><span class='oak-lib-badge'>PDF</span></div>",
            default => "<div class='oak-lib-type' style='--c:$color'><i class='ti $icon'></i><span>" . $e($ext) . "</span></div>",
        };
        $open = $preview ? "href='#' data-doc='$tip'" : "href='" . $e($url) . "' download";
        return "<div class='oak-lib-tile' style='--c:$color'><a class='oak-lib-thumb' $open>$thumb<span class='oak-lib-hover'><i class='ti " . ($preview ? 'ti-eye' : 'ti-download') . "'></i>" . ($preview ? 'Visualizar' : 'Baixar') . "</span></a>"
            . "<div class='oak-lib-info'><a class='oak-lib-name' $open title='" . $e($d['filename']) . "'>" . $e($d['filename']) . "</a>" . ($cat ? "<span class='oak-doc-cat'>" . $e($cat) . "</span>" : '')
            . "<div class='oak-lib-meta'><a href='" . $e($d['itemtype']::getFormURLWithID((int) $d['items_id'])) . "'>" . $e($d['item_code']) . "</a> · " . self::date($d['date_creation']) . ' · ' . self::size($d) . "</div>"
            . "<div class='oak-lib-actions'><a class='btn btn-sm btn-ghost-secondary' href='" . $e($url) . "' download title='Baixar'><i class='ti ti-download'></i></a></div></div></div>";
    }

    // ------------------------------------------------------------------ página da biblioteca

    /** Pastas (tipo, origem, item, categoria), busca, grade ou lista. O título vem pronto do plugin. */
    public static function page(array $sources, array $get, string $titleHtml): void
    {
        $e = [self::class, 'e'];
        $filter = ['q' => trim((string) ($get['q'] ?? '')), 'category' => (int) ($get['category'] ?? 0), 'type' => in_array($get['type'] ?? '', ['pdf', 'img', 'office'], true) ? $get['type'] : ''];
        $src = isset($sources[$get['src'] ?? '']) ? $get['src'] : '';
        $item = (int) ($get['item'] ?? 0);
        $view = ($get['view'] ?? 'grade') === 'lista' ? 'lista' : 'grade';
        $all = self::rows($sources);
        $rows = self::rows($sources, $src ?: null, $src ? $item : 0, $filter);
        $state = ['src' => $src, 'item' => $item, 'view' => $view] + $filter;
        $url = fn (array $q) => '?' . http_build_query(array_filter($q + $state));

        $folder = function (string $label, int $n, array $q, bool $on, string $icon, string $sub = '') use ($e, $url) {
            return "<a class='oak-lib-folder" . ($on ? ' on' : '') . "' href='" . $e($url($q)) . "'><i class='ti " . ($on && $icon === 'ti-folder' ? 'ti-folder-open' : $icon) . "'></i>"
                . "<span>" . $e($label) . ($sub !== '' ? "<small>" . $e($sub) . "</small>" : '') . "</span><b>$n</b></a>";
        };
        $none = !$src && !$filter['category'] && !$filter['type'];
        $side = $folder('Todos os documentos', count($all), ['src' => '', 'item' => 0, 'category' => 0, 'type' => ''], $none, 'ti-files');
        $side .= "<div class='oak-lib-head'>Tipo</div>";
        foreach (['pdf' => ['PDF', 'ti-file-type-pdf'], 'img' => ['Imagens', 'ti-photo'], 'office' => ['Office e planilhas', 'ti-file-spreadsheet']] as $k => [$label, $icon]) {
            $side .= $folder($label, count(array_filter($all, fn ($d) => self::group((string) $d['filename']) === $k)), ['type' => $filter['type'] === $k ? '' : $k], $filter['type'] === $k, $icon);
        }
        if (count($sources) > 1) {
            $side .= "<div class='oak-lib-head'>Origem</div>";
            foreach ($sources as $type => $s) {
                $n = count(array_filter($all, fn ($d) => $d['itemtype'] === $type));
                if ($n) {
                    $side .= $folder($s['label'], $n, ['src' => $src === $type && !$item ? '' : $type, 'item' => 0], $src === $type && !$item, $s['icon']);
                }
            }
        }
        $byItem = [];
        foreach ($all as $d) {
            $k = $d['itemtype'] . '#' . $d['items_id'];
            $byItem[$k] ??= ['type' => $d['itemtype'], 'id' => (int) $d['items_id'], 'label' => $d['item_code'] . ' · ' . $d['item_title'], 'sub' => $d['item_sub'], 'n' => 0];
            $byItem[$k]['n']++;
        }
        $side .= "<div class='oak-lib-head'>Por item</div>";
        foreach (array_slice($byItem, 0, 40) as $x) {
            $on = $src === $x['type'] && $item === $x['id'];
            $side .= $folder($x['label'], $x['n'], ['src' => $on ? '' : $x['type'], 'item' => $on ? 0 : $x['id']], $on, 'ti-folder', (string) $x['sub']);
        }
        $byCat = [];
        foreach ($all as $d) {
            $byCat[(int) $d['documentcategories_id']] = ($byCat[(int) $d['documentcategories_id']] ?? 0) + 1;
        }
        $side .= "<div class='oak-lib-head'>Por categoria</div>";
        foreach ($byCat as $cat => $n) {
            $side .= $folder($cat ? self::category(['documentcategories_id' => $cat]) : 'Sem categoria', $n, ['category' => $filter['category'] === $cat ? 0 : $cat], $cat && $filter['category'] === $cat, 'ti-tag');
        }

        $where = array_filter([
            $src ? ($item ? ($byItem["$src#$item"]['label'] ?? '') : $sources[$src]['label']) : '',
            $filter['category'] ? self::category(['documentcategories_id' => $filter['category']]) : '',
            $filter['type'] ? ['pdf' => 'PDF', 'img' => 'imagens', 'office' => 'Office e planilhas'][$filter['type']] : '',
        ]);
        echo "<div class='oak-lib-page'>$titleHtml<div class='oak-lib'><aside class='oak-lib-side card'>$side</aside><section class='oak-lib-main'>";
        echo "<div class='card mb-3'><div class='card-body oak-lib-bar'><div class='oak-lib-where'><i class='ti ti-folder-open'></i><b>" . ($where ? $e(implode(' › ', $where)) : 'Todos os documentos') . "</b><span>" . count($rows) . " documento(s)</span></div>"
            . "<form method='get' class='d-flex gap-2 m-0'>" . implode('', array_map(fn ($k) => $state[$k] ? "<input type='hidden' name='$k' value='" . $e($state[$k]) . "'>" : '', ['src', 'item', 'category', 'type', 'view']))
            . "<div class='input-icon'><span class='input-icon-addon'><i class='ti ti-search'></i></span><input class='form-control' name='q' value='" . $e($filter['q']) . "' placeholder='Buscar arquivo ou item' style='width:240px'></div>" . Html::closeForm(false)
            . "<div class='btn-group'><a class='btn " . ($view === 'grade' ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . $e($url(['view' => 'grade'])) . "' title='Grade'><i class='ti ti-layout-grid'></i></a>"
            . "<a class='btn " . ($view === 'lista' ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . $e($url(['view' => 'lista'])) . "' title='Lista'><i class='ti ti-list'></i></a></div></div></div>";
        if (!$rows) {
            echo "<div class='card'><div class='card-body text-secondary text-center py-5'><i class='ti ti-files-off fs-1 d-block mb-2'></i>Nenhum documento" . ($where || $filter['q'] ? ' aqui' : '') . ".</div></div>";
        } elseif ($view === 'lista') {
            echo "<div class='card'><div class='oak-docs-list'>" . implode('', array_map(fn ($d) => self::line($d, true), $rows)) . "</div></div>";
        } else {
            echo "<div class='oak-lib-grid'>" . implode('', array_map([self::class, 'tile'], $rows)) . "</div>";
        }
        echo "</section></div></div>";
    }
}
