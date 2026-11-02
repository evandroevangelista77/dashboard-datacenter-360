<?php

namespace GlpiPlugin\Sistemasinternos;

use TCPDF;

/**
 * Página do relatório executivo de vulnerabilidades (TCPDF, que já vem com o GLPI): capa, rodapé
 * "Página x de y" e os gráficos desenhados direto no PDF, para o relatório não depender do navegador.
 */
class WazuhPdf extends TCPDF
{
    public const X = 14;
    public const W = 182;
    public const TOP = 24;
    public const BOTTOM = 280;

    public const INK = '#0f172a';
    public const MUTED = '#64748b';
    public const LINE = '#e2e8f0';
    public const ACCENT = '#3769B2';
    public const NAVY = '#0b1d3f';

    public string $docTitle = '';
    public string $docSub = '';
    public string $docMeta = '';
    public string $docFoot = '';
    private bool $coverDrawn = false;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8');
        $this->setLanguageArray(['a_meta_charset' => 'UTF-8', 'a_meta_dir' => 'ltr', 'a_meta_language' => 'pt', 'w_page' => 'página']);
        $this->SetCreator('GLPI · Sistemas Internos 360');
        $this->SetMargins(self::X, self::TOP, self::X);
        $this->SetHeaderMargin(0);
        $this->SetFooterMargin(12);
        $this->SetAutoPageBreak(false);
        $this->SetFont('dejavusans', '', 8.5);
        $this->setCellHeightRatio(1.35);
    }

    public function Header()
    {
        if (!$this->coverDrawn) { // a primeira chamada é a da capa
            $this->coverDrawn = true;
            $this->fill(self::NAVY);
            $this->Rect(0, 0, 210, 66, 'F');
            $this->fill(self::ACCENT);
            $this->Rect(0, 0, 210, 2.6, 'F');
            $this->Rect(self::X, 16, 1.8, 34, 'F');
            $this->SetXY(self::X + 6, 14);
            $this->SetFont('dejavusans', 'B', 7.5);
            $this->ink('#8fb5ef');
            $this->Cell(150, 4.5, 'RELATÓRIO EXECUTIVO · SEGURANÇA DA INFORMAÇÃO', 0, 2, 'L');
            $this->SetFont('dejavusans', 'B', 22);
            $this->ink('#ffffff');
            $this->Cell(170, 12, $this->docTitle, 0, 2, 'L');
            $this->SetFont('dejavusans', '', 12);
            $this->ink('#cbd5e1');
            $this->Cell(170, 7, $this->docSub, 0, 2, 'L');
            $this->SetFont('dejavusans', '', 7.5);
            $this->ink('#94a3b8');
            $this->SetXY(self::X + 6, 49);
            $this->Cell(170, 5, $this->docMeta, 0, 0, 'L');
            $this->SetXY(140, 14);
            $this->SetFont('dejavusans', 'B', 7);
            $this->Cell(56, 4.5, 'CONFIDENCIAL · USO INTERNO', 0, 0, 'R');
            // logotipo do Wazuh (fonte dos dados), no canto da capa
            $logo = dirname(__DIR__) . '/public/img/wazuh-mark.svg';
            if (is_file($logo)) {
                // o TCPDF não lê as classes de CSS do SVG: as cores vão direto nos elementos
                $svg = str_replace(['class="st0"', 'class="st1"'], ['fill="#ffffff"', 'fill="#007AFF"'], (string) file_get_contents($logo));
                $this->ImageSVG('@' . $svg, 177, 28, 17);
                $this->SetFont('dejavusans', 'B', 7);
                $this->ink('#ffffff');
                $this->SetXY(150, 46.5);
                $this->Cell(44, 4, 'WAZUH', 0, 0, 'R');
            }
            return;
        }
        $this->fill(self::ACCENT);
        $this->Rect(0, 0, 210, 2.2, 'F');
        $this->SetXY(self::X, 7);
        $this->SetFont('dejavusans', 'B', 7);
        $this->ink(self::ACCENT);
        $this->Cell(100, 4, 'RELATÓRIO EXECUTIVO DE VULNERABILIDADES', 0, 0, 'L');
        $this->SetFont('dejavusans', '', 7);
        $this->ink(self::MUTED);
        $this->Cell(82, 4, $this->docSub, 0, 0, 'R');
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.2);
        $this->Line(self::X, 13, self::X + self::W, 13);
    }

    public function Footer()
    {
        $this->SetY(-12);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->Line(self::X, $this->GetY(), self::X + self::W, $this->GetY());
        $this->SetFont('dejavusans', '', 6.5);
        $this->ink(self::MUTED);
        $this->Cell(140, 6, $this->docFoot, 0, 0, 'L');
        $this->Cell(42, 6, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
    }

    // ------------------------------------------------------------------ utilidades

    /** @return int[] */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public function fill(string $hex): void
    {
        $this->SetFillColor(...self::rgb($hex));
    }

    public function ink(string $hex): void
    {
        $this->SetTextColor(...self::rgb($hex));
    }

    /** Mistura a cor com branco (0 = branca, 1 = a própria cor), para fundos suaves. */
    public static function tint(string $hex, float $amount): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(fn ($v) => (int) round(255 - (255 - $v) * $amount), self::rgb($hex)));
    }

    public function ensure(float $y, float $height): float
    {
        if ($y + $height > self::BOTTOM) {
            $this->AddPage();
            return self::TOP;
        }
        return $y;
    }

    public function section(float $y, string $title, string $hint = '', float $need = 42): float
    {
        $y = $this->ensure($y, $need); // o título nunca fica sozinho no fim da página
        $this->fill(self::ACCENT);
        $this->Rect(self::X, $y + 0.6, 1.4, 5, 'F');
        $this->SetXY(self::X + 3.5, $y);
        $this->SetFont('dejavusans', 'B', 11);
        $this->ink(self::INK);
        $this->Cell(self::W - 4, 6.5, $title, 0, 1, 'L');
        if ($hint !== '') {
            $this->SetXY(self::X + 3.5, $y + 6.5);
            $this->SetFont('dejavusans', '', 7.5);
            $this->ink(self::MUTED);
            $this->Cell(self::W - 4, 4, $hint, 0, 1, 'L');
            return $y + 13;
        }
        return $y + 9.5;
    }

    public function paragraph(float $y, string $html, float $size = 8.5, float $x = self::X, float $w = self::W): float
    {
        $this->SetFont('dejavusans', '', $size);
        $this->ink(self::INK);
        $this->writeHTMLCell($w, 0, $x, $y, $html, 0, 1, false, true, 'L', true);
        return $this->GetY();
    }

    public function bullets(float $y, array $lines, string $color = self::ACCENT): float
    {
        foreach ($lines as $line) {
            $y = $this->ensure($y, 9);
            $this->fill($color);
            $this->Circle(self::X + 2, $y + 2.4, 0.9, 0, 360, 'F');
            $y = $this->paragraph($y, $line, 8.5, self::X + 5, self::W - 5) + 1.5;
        }
        return $y;
    }

    /** Faixa de situação: barra colorida, rótulo e a frase que resume o cenário. */
    public function banner(float $y, string $label, string $text, string $color): float
    {
        $h = 19;
        $this->fill(self::tint($color, 0.12));
        $this->SetDrawColor(...self::rgb(self::tint($color, 0.35)));
        $this->SetLineWidth(0.25);
        $this->RoundedRect(self::X, $y, self::W, $h, 2, '1111', 'DF');
        $this->fill($color);
        $this->RoundedRect(self::X, $y, 3, $h, 1.5, '1001', 'F');
        $this->SetXY(self::X + 7, $y + 2.6);
        $this->SetFont('dejavusans', 'B', 7);
        $this->ink($color);
        $this->Cell(80, 3.8, mb_strtoupper($label), 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 10);
        $this->ink(self::INK);
        $this->MultiCell(self::W - 12, 4.8, $text, 0, 'L', false, 1);
        return $y + $h + 5;
    }

    /** Tabela com faixas. $headers: rótulos; $rows: linhas de células (HTML simples); $widths em %. */
    public function table(float $y, array $headers, array $rows, array $widths, array $aligns, float $size = 7.6): float
    {
        $head = '<tr style="background-color:#eef2f8;color:#475569">';
        foreach ($headers as $i => $h) {
            $head .= '<th width="' . $widths[$i] . '%" align="' . $aligns[$i] . '"><b>' . htmlspecialchars($h) . '</b></th>';
        }
        $head .= '</tr>';
        // o TCPDF não repete o cabeçalho sozinho: quebra em blocos e repete
        $chunk = [];
        $y = $this->ensure($y, 22);
        foreach ($rows as $n => $row) {
            $tr = '<tr style="background-color:' . ($n % 2 ? '#f8fafc' : '#ffffff') . '">';
            foreach ($row as $i => $cell) {
                $tr .= '<td width="' . $widths[$i] . '%" align="' . $aligns[$i] . '">' . $cell . '</td>';
            }
            $chunk[] = $tr . '</tr>';
            if (count($chunk) >= 12 || $n === array_key_last($rows)) {
                $html = '<table cellpadding="3" cellspacing="0" border="0" style="font-size:' . $size . 'pt">' . $head . implode('', $chunk) . '</table>';
                $y = $this->ensure($y, 12 + 7.2 * count($chunk));
                $y = $this->paragraph($y, $html, $size) + 1;
                $chunk = [];
            }
        }
        return $y + 3;
    }

    // ------------------------------------------------------------------ cartões e gráficos

    public function card(float $x, float $y, float $w, float $h, string $label, string $value, string $sub, string $color): void
    {
        $this->SetDrawColor(...self::rgb(self::LINE));
        $this->SetLineWidth(0.25);
        $this->fill('#ffffff');
        $this->RoundedRect($x, $y, $w, $h, 1.8, '1111', 'DF');
        $this->fill($color);
        $this->Rect($x, $y + 1.5, 1.2, $h - 3, 'F');
        $this->SetXY($x + 3.2, $y + 2);
        $this->SetFont('dejavusans', 'B', 6);
        $this->ink(self::MUTED);
        $this->Cell($w - 4, 3.6, mb_strtoupper($label), 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 15);
        $this->ink(self::INK);
        $this->Cell($w - 4, 8, $value, 0, 2, 'L');
        $this->SetFont('dejavusans', '', 6.6);
        $this->ink(self::MUTED);
        $this->Cell($w - 4, 3.6, $sub, 0, 0, 'L');
    }

    /** Barra única dividida em fatias: $segments é lista de [valor, cor]. */
    public function stacked(float $x, float $y, float $w, float $h, array $segments): void
    {
        $total = array_sum(array_column($segments, 0)) ?: 1;
        $cx = $x;
        foreach ($segments as [$value, $color]) {
            if ($value <= 0) {
                continue;
            }
            $sw = $w * $value / $total;
            $this->fill($color);
            $this->Rect($cx, $y, max(0.4, $sw - 0.5), $h, 'F');
            $cx += $sw;
        }
    }

    /** Legenda em linha: lista de [texto, cor]. Devolve o Y de depois dela. */
    public function legend(float $x, float $y, array $items): float
    {
        $this->SetFont('dejavusans', '', 7);
        $cx = $x;
        foreach ($items as [$text, $color]) {
            $this->fill($color);
            $this->Rect($cx, $y + 0.9, 2.6, 2.6, 'F');
            $this->ink(self::MUTED);
            $this->SetXY($cx + 3.6, $y);
            $width = $this->GetStringWidth($text) + 3;
            $this->Cell($width, 4.4, $text, 0, 0, 'L');
            $cx += 3.6 + $width + 3;
        }
        return $y + 6;
    }

    /** Colunas com o valor em cima e o rótulo embaixo. $bars: lista de [rótulo, valor, cor]. */
    public function columns(float $x, float $y, float $w, float $h, array $bars): void
    {
        $max = max(1, ...array_column($bars, 1));
        $slot = $w / max(1, count($bars));
        $bw = min(14, $slot * 0.62);
        $base = $y + $h - 6;
        $this->SetDrawColor(...self::rgb(self::LINE));
        $this->Line($x, $base, $x + $w, $base);
        foreach ($bars as $i => [$label, $value, $color]) {
            $bh = ($h - 14) * $value / $max;
            $bx = $x + $slot * $i + ($slot - $bw) / 2;
            if ($value > 0) {
                $this->fill($color);
                $this->RoundedRect($bx, $base - $bh, $bw, max(0.6, $bh), 0.9, '1100', 'F');
                $this->SetFont('dejavusans', 'B', 6.8);
                $this->ink(self::INK);
                $this->SetXY($bx - 5, $base - $bh - 4.4);
                $this->Cell($bw + 10, 4, number_format($value, 0, ',', '.'), 0, 0, 'C');
            }
            $this->SetFont('dejavusans', '', 6.8);
            $this->ink(self::MUTED);
            $this->SetXY($bx - 5, $base + 1);
            $this->Cell($bw + 10, 4, (string) $label, 0, 0, 'C');
        }
    }

    /**
     * Barras horizontais empilhadas por severidade, com rótulo à esquerda e total à direita.
     * $rows: lista de [rótulo, [valor por série], total]; $colors: cor de cada série.
     */
    public function hstack(float $x, float $y, float $w, array $rows, array $colors, float $rowH = 7.4): float
    {
        $labelW = 58;
        $barX = $x + $labelW;
        $barW = $w - $labelW - 22;
        $max = max(1, ...array_column($rows, 2));
        foreach ($rows as [$label, $vals, $total]) {
            $y = $this->ensure($y, $rowH);
            $this->SetFont('dejavusans', '', 7.4);
            $this->ink(self::INK);
            $this->SetXY($x, $y);
            $this->Cell($labelW - 2, $rowH - 1.6, $this->clip($label, $labelW - 4), 0, 0, 'L');
            $cx = $barX;
            foreach ($vals as $i => $v) {
                if ($v <= 0) {
                    continue;
                }
                $sw = $barW * $v / $max;
                $this->fill($colors[$i]);
                $this->Rect($cx, $y + 0.6, max(0.4, $sw - 0.4), $rowH - 3, 'F');
                $cx += $sw;
            }
            $this->SetFont('dejavusans', 'B', 7.2);
            $this->SetXY($barX + $barW + 1, $y);
            $this->Cell(21, $rowH - 1.6, number_format($total, 0, ',', '.'), 0, 0, 'R');
            $y += $rowH;
        }
        return $y + 1;
    }

    /** Linhas ao longo do tempo. $series: lista de [nome, cor, valores]; $labels: rótulo de cada ponto. */
    public function lines(float $x, float $y, float $w, float $h, array $labels, array $series): void
    {
        $max = max(1, ...array_merge(...array_column($series, 2)));
        $n = max(2, count($labels));
        $left = 14;
        $bottom = $y + $h - 6;
        $top = $y + 3;
        $this->SetDrawColor(...self::rgb(self::LINE));
        $this->SetLineWidth(0.2);
        for ($g = 0; $g <= 4; $g++) {
            $gy = $bottom - ($bottom - $top) * $g / 4;
            $this->Line($x + $left, $gy, $x + $w, $gy);
            $this->SetFont('dejavusans', '', 6.2);
            $this->ink(self::MUTED);
            $this->SetXY($x, $gy - 2);
            $this->Cell($left - 1.5, 4, number_format($max * $g / 4, 0, ',', '.'), 0, 0, 'R');
        }
        $px = fn ($i) => $x + $left + ($w - $left) * $i / ($n - 1);
        $py = fn ($v) => $bottom - ($bottom - $top) * $v / $max;
        foreach ($series as [$name, $color, $vals]) {
            $this->SetDrawColor(...self::rgb($color));
            $this->SetLineWidth(0.6);
            for ($i = 1; $i < count($vals); $i++) {
                $this->Line($px($i - 1), $py($vals[$i - 1]), $px($i), $py($vals[$i]));
            }
        }
        $step = max(1, (int) ceil(count($labels) / 8));
        $this->SetFont('dejavusans', '', 6.2);
        $this->ink(self::MUTED);
        foreach ($labels as $i => $lb) {
            if ($i % $step === 0) {
                $this->SetXY($px($i) - 6, $bottom + 1);
                $this->Cell(12, 4, $lb, 0, 0, 'C');
            }
        }
    }

    /** Corta o texto para caber na largura (mm), com reticências. */
    public function clip(string $text, float $w): string
    {
        $this->SetFont('dejavusans', '', 7.4);
        if ($this->GetStringWidth($text) <= $w) {
            return $text;
        }
        while ($text !== '' && $this->GetStringWidth($text . '…') > $w) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '…';
    }
}
