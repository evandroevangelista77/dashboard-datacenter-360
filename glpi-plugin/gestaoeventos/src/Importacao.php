<?php

namespace GlpiPlugin\Gestaoeventos;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Session;
use Throwable;

/**
 * Importação da lista de convidados por planilha (xlsx, xls ou csv). A planilha tem exatamente as colunas
 * da tabela: Nome, Empresa, Perfil e Situação. O modelo para baixar já traz as listas de Perfil e Situação.
 */
class Importacao
{
    public const COLUNAS = ['Nome', 'Empresa', 'Perfil', 'Situação'];
    public const MAX_LINHAS = 2000;

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    }

    /** rótulo normalizado → chave, para Perfil e Situação */
    private static function mapa(string $field): array
    {
        $out = [];
        foreach (Registro::KINDS['convidado'][4][$field][2] as $k => $label) {
            $out[self::norm($label)] = $k;
            $out[self::norm($k)] = $k;
        }
        return $out;
    }

    /** Envia o modelo (.xlsx) com as quatro colunas e as listas de Perfil e Situação. */
    public static function modelo(): void
    {
        $perfis = array_values(Registro::KINDS['convidado'][4]['category'][2]);
        $situ = array_values(Registro::KINDS['convidado'][4]['status'][2]);
        $sp = new Spreadsheet();
        $ws = $sp->getActiveSheet();
        $ws->setTitle('Convidados');
        foreach (self::COLUNAS as $i => $c) {
            $ws->setCellValue([$i + 1, 1], $c);
        }
        $ws->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $ws->getStyle('A1:D1')->getFill()->setFillType('solid')->getStartColor()->setRGB('0F766E');
        foreach (['A' => 34, 'B' => 30, 'C' => 18, 'D' => 18] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
        foreach (['C' => $perfis, 'D' => $situ] as $col => $list) {
            for ($r = 2; $r <= 500; $r++) {
                $v = $ws->getCell("$col$r")->getDataValidation();
                $v->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(false)->setShowErrorMessage(true)->setFormula1('"' . implode(',', $list) . '"');
            }
        }
        $ws->freezePane('A2');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="modelo-convidados.xlsx"');
        header('Cache-Control: private, no-store');
        (new Xlsx($sp))->save('php://output');
    }

    /** Importa a planilha para o evento. Devolve o resumo (também com os avisos de linha). */
    public static function convidados(Evento $ev, array $file): string
    {
        global $DB;
        if (empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new RuntimeException('Escolha a planilha (.xlsx, .xls ou .csv).');
        }
        if (!preg_match('/\.(xlsx|xls|csv)$/i', (string) $file['name']) || (int) $file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Envie um arquivo .xlsx, .xls ou .csv de até 5 MB.');
        }
        try {
            $sheet = IOFactory::load((string) $file['tmp_name'])->getActiveSheet();
            $raw = $sheet->toArray(null, false, false, false); // valores como estão, sem calcular fórmulas
        } catch (Throwable $e) {
            throw new RuntimeException('Não consegui ler a planilha: ' . $e->getMessage());
        }
        $raw = array_values(array_filter($raw, fn ($r) => implode('', array_map(fn ($v) => trim((string) $v), $r)) !== ''));
        if (!$raw) {
            throw new RuntimeException('A planilha está vazia.');
        }
        // cabeçalho: exatamente Nome, Empresa, Perfil e Situação (em qualquer ordem)
        $head = array_map(fn ($v) => self::norm((string) $v), $raw[0]);
        $head = array_values(array_filter($head, fn ($v) => $v !== ''));
        $want = array_map([self::class, 'norm'], self::COLUNAS);
        if (array_diff($want, $head) || array_diff($head, $want) || count($head) !== 4) {
            throw new RuntimeException('A primeira linha precisa ter exatamente as colunas: ' . implode(', ', self::COLUNAS) . '. Baixe o modelo para usar o formato correto.');
        }
        $idx = [];
        foreach ($raw[0] as $i => $v) {
            $idx[self::norm((string) $v)] = $i;
        }
        $perfis = self::mapa('category');
        $situ = self::mapa('status');
        $rows = array_slice($raw, 1);
        if (count($rows) > self::MAX_LINHAS) {
            throw new RuntimeException('Limite de ' . self::MAX_LINHAS . ' convidados por importação.');
        }
        $have = [];
        foreach (Registro::forEvento($ev->getID(), 'convidado') as $g) {
            $have[self::norm($g['title'] . '|' . $g['supplier'])] = true;
        }
        $now = $_SESSION['glpi_currenttime'];
        $ok = 0;
        $dup = 0;
        $errors = [];
        foreach ($rows as $n => $r) {
            $line = $n + 2;
            $get = fn (string $col) => mb_substr(trim((string) ($r[$idx[self::norm($col)]] ?? '')), 0, 255);
            $nome = $get('Nome');
            $emp = $get('Empresa');
            $perfil = $get('Perfil');
            $sit = $get('Situação');
            if ($nome === '') {
                $errors[] = "linha $line: Nome em branco";
                continue;
            }
            $pk = $perfil === '' ? 'cliente' : ($perfis[self::norm($perfil)] ?? null);
            $sk = $sit === '' ? 'convidado' : ($situ[self::norm($sit)] ?? null);
            if ($pk === null) {
                $errors[] = "linha $line: Perfil \"$perfil\" inválido";
                continue;
            }
            if ($sk === null) {
                $errors[] = "linha $line: Situação \"$sit\" inválida";
                continue;
            }
            $key = self::norm("$nome|$emp");
            if (isset($have[$key])) {
                $dup++;
                continue;
            }
            $have[$key] = true;
            $DB->insert(Registro::getTable(), ['plugin_gestaoeventos_eventos_id' => $ev->getID(), 'kind' => 'convidado', 'title' => $nome, 'supplier' => $emp, 'category' => $pk, 'status' => $sk,
                'phase' => 'execucao', 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => $now, 'date_mod' => $now]);
            $ok++;
        }
        Historico::record($ev->getID(), null, 'change', "Lista de convidados importada de planilha ({$file['name']}): $ok incluído(s), $dup repetido(s), " . count($errors) . ' com erro.');
        $msg = "$ok convidado(s) importado(s)" . ($dup ? ", $dup já existiam (ignorados)" : '') . (count($errors) ? ', ' . count($errors) . ' linha(s) com erro: ' . implode('; ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? '…' : '') : '') . '.';
        if (!$ok && $errors) {
            throw new RuntimeException($msg);
        }
        return $msg;
    }

    /** Cartão de importação na aba Marketing. */
    public static function card(Evento $ev): string
    {
        $e = [Menu::class, 'e'];
        $back = Evento::tabUrl($ev->getID(), 'marketing');
        return "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-file-spreadsheet me-2'></i>Importar convidados do Excel</h3>"
            . "<div class='card-actions'><a class='btn btn-sm btn-outline-secondary' href='" . $e(Menu::url('/front/modelo.php') . '?tipo=convidados') . "'><i class='ti ti-download me-1'></i>Baixar o modelo (.xlsx)</a></div></div>"
            . "<form method='post' action='" . $e(Evento::getFormURL()) . "' enctype='multipart/form-data' class='card-body row g-2 align-items-end'><input type='hidden' name='id' value='" . $ev->getID() . "'><input type='hidden' name='_back' value='" . $e($back) . "'>"
            . "<div class='col-md-6'><label class='form-label small mb-0'>Planilha (.xlsx, .xls ou .csv)</label><input type='file' class='form-control' name='convidados_xlsx' accept='.xlsx,.xls,.csv' required></div>"
            . "<div class='col-auto'><button class='btn btn-primary' name='import_convidados' value='1'><i class='ti ti-upload me-1'></i>Importar</button></div>"
            . "<div class='col-12 small text-secondary'>A primeira linha deve ter exatamente as colunas <b>" . $e(implode(' · ', self::COLUNAS)) . "</b> (use o modelo). Perfil: " . $e(implode(', ', Registro::KINDS['convidado'][4]['category'][2]))
            . ". Situação: " . $e(implode(', ', Registro::KINDS['convidado'][4]['status'][2])) . ". Perfil em branco vira Cliente e Situação em branco vira Convidado; quem já está na lista (mesmo nome e empresa) é ignorado.</div>"
            . \Html::closeForm(false) . "</div>";
    }
}
