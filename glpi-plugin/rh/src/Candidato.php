<?php

namespace GlpiPlugin\Rh;

use CommonDBTM;
use Document;
use Document_Item;
use Dropdown;
use Html;
use Log;
use Session;

/**
 * Banco de candidatos (o currículo vai na aba Documentos).
 */
class Candidato extends CommonDBTM
{
    public static string $rightname = 'plugin_rh_recrutamento';
    public bool $dohistory = true;

    public const SOURCES = [
        'linkedin' => 'LinkedIn', 'indicacao' => 'Indicação', 'site' => 'Site / trabalhe conosco', 'agencia' => 'Agência / consultoria',
        'banco' => 'Banco de talentos', 'universidade' => 'Universidade', 'portal' => 'Portal de vagas', 'outros' => 'Outros',
    ];

    /** Logotipo (Tabler Icons, que já vêm com o GLPI) e cor de cada origem. */
    public const SOURCE_META = [
        'linkedin'     => ['ti-brand-linkedin', '#0A66C2'],
        'indicacao'    => ['ti-user-heart', '#22c55e'],
        'portal'       => ['ti-briefcase', '#6366f1'],
        'site'         => ['ti-world-www', '#ec4899'],
        'agencia'      => ['ti-building-skyscraper', '#14b8a6'],
        'banco'        => ['ti-database', '#f59e0b'],
        'universidade' => ['ti-school', '#a855f7'],
        'outros'       => ['ti-dots', '#64748b'],
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Candidatos' : 'Candidato';
    }

    public static function getIcon()
    {
        return 'ti ti-user-search';
    }

    // banco de candidatos: só o RH
    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, Vaga::READALL);
    }

    public static function canCreate(): bool
    {
        return Vaga::isRH();
    }

    public static function canUpdate(): bool
    {
        return Vaga::isRH();
    }

    public static function canPurge(): bool
    {
        return Vaga::isRH() && Session::haveRight(self::$rightname, PURGE);
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Document_Item::class, $ong, $options);
        $this->addStandardTab(Log::class, $ong, $options);
        return $ong;
    }

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'email', 'name' => 'E-mail', 'datatype' => 'email'],
            ['id' => 4, 'table' => $t, 'field' => 'phone', 'name' => 'Telefone', 'datatype' => 'string'],
            ['id' => 5, 'table' => $t, 'field' => 'city', 'name' => 'Cidade', 'datatype' => 'string'],
            ['id' => 6, 'table' => $t, 'field' => 'source', 'name' => 'Origem', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
            ['id' => 7, 'table' => $t, 'field' => 'salary_expectation', 'name' => 'Pretensão salarial', 'datatype' => 'decimal'],
            ['id' => 8, 'table' => $t, 'field' => 'linkedin', 'name' => 'LinkedIn', 'datatype' => 'weblink'],
            ['id' => 9, 'table' => Cargo::getTable(), 'field' => 'name', 'linkfield' => 'plugin_rh_cargos_id', 'name' => 'Cargo pretendido', 'datatype' => 'dropdown'],
            ['id' => 16, 'table' => $t, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text'],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'source') {
            return Menu::e(self::SOURCES[$values[$field]] ?? '');
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'source') {
            return Dropdown::showFromArray($name, self::SOURCES, ['value' => $values[$field] ?? '', 'display' => false] + $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function prepareInputForAdd($input)
    {
        $input['entities_id'] ??= $_SESSION['glpiactive_entity'] ?? 0;
        $input['is_recursive'] ??= 1;
        return $this->clean($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->clean($input);
    }

    private function clean(array $input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect('Informe o nome do candidato.', false, ERROR);
            return false;
        }
        if (!empty($input['email']) && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            Session::addMessageAfterRedirect('E-mail inválido.', false, ERROR);
            return false;
        }
        if (isset($input['salary_expectation'])) {
            $input['salary_expectation'] = max(0, Menu::parseMoney((string) $input['salary_expectation']));
        }
        if (isset($input['plugin_rh_cargos_id'])) {
            $input['plugin_rh_cargos_id'] = max(0, (int) $input['plugin_rh_cargos_id']);
        }
        if (isset($input['source']) && !isset(self::SOURCES[$input['source']])) {
            $input['source'] = 'outros';
        }
        return $input;
    }

    /** Pasta das fotos (dentro da pasta de imagens do GLPI). */
    public static function pictureDir(): string
    {
        return GLPI_PICTURE_DIR . '/rh';
    }

    public static function pictureUrl(array $row): string
    {
        return empty($row['picture']) ? '' : Menu::url('/front/foto.php?id=' . (int) $row['id'] . '&v=' . substr(md5((string) $row['picture']), 0, 8));
    }

    /** Avatar: a foto ou as iniciais. */
    public static function avatar(array $row, string $size = ''): string
    {
        $cls = 'rh-avatar' . ($size ? " rh-avatar-$size" : '');
        $url = self::pictureUrl($row);
        return $url
            ? "<img class='$cls rh-photo' src='" . Menu::e($url) . "' alt='" . Menu::e($row['name'] ?? '') . "'>"
            : "<span class='$cls'>" . Menu::e(Menu::initials((string) ($row['name'] ?? '?'))) . "</span>";
    }

    /**
     * Grava a foto enviada: só JPG, PNG ou WebP até 3 MB; recorta em quadrado,
     * reduz para 400×400 e regrava como JPEG (descarta qualquer outro conteúdo).
     */
    public function savePicture(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Falha no envio da foto.');
        }
        if ($file['size'] > 3 * 1024 * 1024) {
            throw new \RuntimeException('A foto passa de 3 MB.');
        }
        $info = @getimagesize($file['tmp_name']);
        $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        if (!$info || !isset($loaders[$info[2]])) {
            throw new \RuntimeException('Envie uma imagem JPG, PNG ou WebP.');
        }
        $src = @$loaders[$info[2]]($file['tmp_name']);
        if (!$src) {
            throw new \RuntimeException('Não consegui ler a imagem.');
        }
        // foto de celular deitada: corrige pela orientação do EXIF
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
            $src = match ($o) { 3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => $src };
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(400, 400);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        // recorte centralizado, um pouco acima do meio (rostos ficam no terço superior)
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) max(0, ($h - $side) * 0.35), 400, 400, $side, $side);
        @mkdir(self::pictureDir(), 0755, true);
        $name = 'cand_' . (int) $this->getID() . '_' . bin2hex(random_bytes(6)) . '.jpg';
        if (!imagejpeg($dst, self::pictureDir() . '/' . $name, 86)) {
            throw new \RuntimeException('Não consegui gravar a foto.');
        }
        $this->removePictureFile();
        $this->update(['id' => $this->getID(), 'picture' => $name]);
    }

    public const RESUME_EXT = ['pdf', 'doc', 'docx', 'odt', 'rtf'];

    /**
     * Anexa o currículo enviado como documento do GLPI ligado ao candidato
     * (fica também na aba Documentos). PDF, Word, ODT ou RTF até 10 MB.
     */
    public function saveResume(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Falha no envio do currículo.');
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new \RuntimeException('O currículo passa de 10 MB.');
        }
        $name = trim(str_replace(['/', '\\', "\0"], '_', basename((string) $file['name'])));
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::RESUME_EXT, true)) {
            throw new \RuntimeException('Envie o currículo em PDF, Word (DOC/DOCX), ODT ou RTF.');
        }
        $prefix = 'rhcv' . bin2hex(random_bytes(6)) . '_';
        if (!move_uploaded_file($file['tmp_name'], GLPI_TMP_DIR . '/' . $prefix . $name)) {
            throw new \RuntimeException('Não consegui receber o currículo.');
        }
        $doc = new Document();
        $ok = $doc->add([
            'name' => 'Currículo - ' . $this->fields['name'], '_filename' => [$prefix . $name], '_prefix_filename' => [$prefix],
            'itemtype' => self::class, 'items_id' => $this->getID(), 'entities_id' => $this->fields['entities_id'], 'is_recursive' => 1,
        ]);
        @unlink(GLPI_TMP_DIR . '/' . $prefix . $name);
        if (!$ok) {
            throw new \RuntimeException('O GLPI não aceitou o arquivo do currículo.');
        }
    }

    /** Documentos anexados ao candidato, do mais novo para o mais antigo. */
    public function resumes(): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'SELECT' => ['d.id', 'd.filename', 'd.filepath', 'd.date_creation', 'di.id AS link_id'],
            'FROM' => Document::getTable() . ' AS d',
            'INNER JOIN' => [Document_Item::getTable() . ' AS di' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE' => ['di.itemtype' => self::class, 'di.items_id' => $this->getID(), 'd.is_deleted' => 0],
            'ORDER' => 'd.id DESC',
        ]), false);
    }

    public function removePictureFile(): void
    {
        $old = (string) ($this->fields['picture'] ?? '');
        if ($old !== '' && basename($old) === $old && is_file(self::pictureDir() . '/' . $old)) {
            @unlink(self::pictureDir() . '/' . $old);
        }
    }

    /** Pode ver a foto: o RH ou quem acompanha uma vaga em que o candidato está. */
    public function canSeePicture(): bool
    {
        global $DB;
        if (self::canView()) {
            return true;
        }
        foreach ($DB->request(['SELECT' => 'plugin_rh_vagas_id', 'FROM' => Candidatura::getTable(), 'WHERE' => ['plugin_rh_candidatos_id' => $this->getID()]]) as $c) {
            $v = new Vaga();
            if ($v->getFromDB((int) $c['plugin_rh_vagas_id']) && $v->canViewItem()) {
                return true;
            }
        }
        return false;
    }

    public function cleanDBonPurge()
    {
        $this->removePictureFile();
        global $DB;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Candidatura::getTable(), 'WHERE' => ['plugin_rh_candidatos_id' => $this->getID()]]) as $c) {
            $DB->delete('glpi_plugin_rh_historicos', ['plugin_rh_candidaturas_id' => $c['id']]);
        }
        $DB->delete(Candidatura::getTable(), ['plugin_rh_candidatos_id' => $this->getID()]);
    }

    public function showForm($ID, array $options = [])
    {
        global $DB;
        $new = $this->isNewItem();
        if ($new) {
            $this->getEmpty();
        }
        $f = $this->fields;
        $e = [Menu::class, 'e'];
        $edit = self::canUpdate();
        $dis = $edit ? '' : 'disabled';

        echo "<div class='rh'><form method='post' action='" . $e(self::getFormURL()) . "' class='card mb-3' enctype='multipart/form-data'>";
        echo "<div class='card-header'><div class='me-3'>" . self::avatar($f + ['name' => $f['name'] ?: '?'], 'xl') . "</div><div class='flex-grow-1'><div class='rh-crumb'>Candidato</div><h3 class='card-title m-0'>" . ($new ? 'Novo candidato' : $e($f['name'])) . "</h3>"
            . ($new ? '' : "<div class='small text-secondary'>" . $e(implode(' · ', array_filter([$f['plugin_rh_cargos_id'] ? \Dropdown::getDropdownName(Cargo::getTable(), (int) $f['plugin_rh_cargos_id'], false, true, false, '') : '', self::SOURCES[$f['source']] ?? '', $f['city'], $f['email']]))) . "</div>") . "</div>";
        if ($edit) {
            echo "<div class='rh-photo-up'><label class='form-label mb-1'><i class='ti ti-camera me-1'></i>Foto</label><input type='file' class='form-control form-control-sm' name='picture' accept='image/jpeg,image/png,image/webp'>"
                . "<div class='form-hint'>JPG, PNG ou WebP até 3 MB. Recortada em quadrado.</div>"
                . (!$new && $f['picture'] ? "<label class='form-check mt-1 mb-0 small'><input type='checkbox' class='form-check-input' name='remove_picture' value='1'><span class='form-check-label'>Remover a foto atual</span></label>" : '') . "</div>";
        }
        echo "</div><div class='card-body row g-3'>";
        echo "<div class='col-md-6'><label class='form-label required'>Nome completo</label><input class='form-control' name='name' maxlength='255' value='" . $e($f['name']) . "' required $dis></div>";
        echo "<div class='col-md-6'><label class='form-label'>E-mail</label><input type='email' class='form-control' name='email' maxlength='255' value='" . $e($f['email']) . "' $dis></div>";
        echo "<div class='col-md-4'><label class='form-label'>Telefone / WhatsApp</label><input class='form-control' name='phone' maxlength='50' value='" . $e($f['phone']) . "' $dis></div>";
        echo "<div class='col-md-4'><label class='form-label'>Cidade</label><input class='form-control' name='city' maxlength='255' value='" . $e($f['city']) . "' $dis></div>";
        echo "<div class='col-md-4'><label class='form-label'>Origem</label>" . Dropdown::showFromArray('source', self::SOURCES, ['value' => $f['source'] ?: 'outros', 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        echo "<div class='col-md-6'><label class='form-label'>Cargo pretendido</label>" . Cargo::dropdown(['name' => 'plugin_rh_cargos_id', 'value' => (int) $f['plugin_rh_cargos_id'], 'display' => false, 'width' => '100%', 'disabled' => !$edit]) . "</div>";
        echo "<div class='col-md-6'><label class='form-label'>Pretensão salarial (R$)</label><input class='form-control' name='salary_expectation' value='" . ((float) $f['salary_expectation'] ? number_format((float) $f['salary_expectation'], 2, ',', '.') : '') . "' $dis></div>";
        echo "<div class='col-12'><label class='form-label'>LinkedIn</label><input class='form-control' name='linkedin' maxlength='255' placeholder='https://www.linkedin.com/in/…' value='" . $e($f['linkedin']) . "' $dis></div>";
        echo "<div class='col-12'><label class='form-label'><i class='ti ti-file-cv me-1'></i>Currículo</label><div class='rh-cv'>";
        foreach ($new ? [] : $this->resumes() as $d) {
            $ext = strtolower(pathinfo((string) $d['filename'], PATHINFO_EXTENSION));
            $size = is_file(GLPI_DOC_DIR . '/' . $d['filepath']) ? filesize(GLPI_DOC_DIR . '/' . $d['filepath']) : 0;
            $url = Html::getPrefixedUrl('/front/document.send.php?docid=' . (int) $d['id'] . '&itemtype=' . rawurlencode(self::class) . '&items_id=' . (int) $this->getID());
            echo "<a class='rh-cv-file' href='" . $e($url) . "' target='_blank'><i class='ti " . ($ext === 'pdf' ? 'ti-file-type-pdf text-red' : 'ti-file-text text-blue') . "'></i>"
                . "<span><b>" . $e($d['filename']) . "</b><small>" . ($size ? number_format($size / 1024, 0, ',', '.') . ' KB · ' : '') . 'enviado em ' . Html::convDateTime($d['date_creation']) . "</small></span><i class='ti ti-download ms-auto'></i></a>";
        }
        if ($edit) {
            echo "<div class='rh-cv-up'><input type='file' class='form-control' name='resume' accept='.pdf,.doc,.docx,.odt,.rtf'><div class='form-hint'>PDF, Word (DOC/DOCX), ODT ou RTF até 10 MB. Cada envio fica guardado; para remover, use a aba <b>Documentos</b>.</div></div>";
        } elseif ($new || !$this->resumes()) {
            echo "<div class='text-secondary small'>Nenhum currículo anexado.</div>";
        }
        echo "</div></div>";
        echo "<div class='col-12'><label class='form-label'>Observações</label><textarea class='form-control' rows='3' name='comment' $dis>" . $e($f['comment']) . "</textarea></div>";
        echo "</div>";
        if ($edit) {
            echo "<div class='card-footer d-flex gap-2'>";
            if ($new) {
                echo "<button class='btn btn-primary' name='add' value='1'><i class='ti ti-device-floppy me-1'></i>Cadastrar</button>";
            } else {
                echo "<input type='hidden' name='id' value='" . (int) $this->getID() . "'><button class='btn btn-primary' name='update' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>";
                if (self::canPurge()) {
                    echo "<button class='btn btn-ghost-danger ms-auto' name='purge' value='1' onclick=\"return confirm('Excluir o candidato e as candidaturas dele?')\"><i class='ti ti-trash'></i></button>";
                }
            }
            echo "</div>";
        }
        Html::closeForm();

        if (!$new) {
            $rows = iterator_to_array($DB->request([
                'SELECT' => ['c.*', 'v.name AS vaga', 'v.status AS vaga_status'],
                'FROM' => Candidatura::getTable() . ' AS c',
                'INNER JOIN' => [Vaga::getTable() . ' AS v' => ['ON' => ['v' => 'id', 'c' => 'plugin_rh_vagas_id']]],
                'WHERE' => ['c.plugin_rh_candidatos_id' => $this->getID()],
                'ORDER' => 'c.id DESC',
            ]), false);
            echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-briefcase-2 me-2'></i>Processos seletivos</h3></div>";
            if (!$rows) {
                echo "<div class='card-body text-secondary'>Ainda não participa de nenhuma vaga. Adicione pelo funil de seleção de uma vaga aberta.</div>";
            } else {
                echo "<div class='table-responsive'><table class='table card-table table-vcenter'><thead><tr><th>Vaga</th><th>Etapa</th><th>Situação</th><th>Nota</th><th>Desde</th></tr></thead><tbody>";
                foreach ($rows as $r) {
                    echo "<tr><td><a href='" . $e(Menu::url('/front/pipeline.php?vaga=' . $r['plugin_rh_vagas_id'])) . "'>" . $e($r['vaga']) . "</a></td>"
                        . "<td>" . Candidatura::stageBadge($r['stage']) . "</td><td>" . Candidatura::statusBadge($r['status']) . "</td>"
                        . "<td>" . Candidatura::stars((int) $r['rating']) . "</td><td>" . Html::convDateTime($r['date_creation']) . "</td></tr>";
                }
                echo "</tbody></table></div>";
            }
            echo "</div>";
        }
        echo "</div>";
        return true;
    }
}
