<?php

namespace GlpiPlugin\Satisfacao;

use TCPDF;

/**
 * Página do relatório mensal (TCPDF, que já vem com o GLPI): faixa e título no topo de todas as
 * páginas, rodapé com "Página x de y" e os gráficos desenhados direto no PDF (colunas, barras,
 * barras empilhadas e a evolução mensal), para o relatório não depender do navegador.
 */
class ReportDoc extends TCPDF
{
    public const X = 14;
    public const W = 182;
    public const TOP = 30;
    public const BOTTOM = 278;

    public const INK = '#0f172a';
    public const MUTED = '#64748b';
    public const LINE = '#e2e8f0';
    public const ACCENT = '#4f46e5';

    public string $docTitle = '';
    public string $docSub = '';
    public string $docFoot = '';

    /** Capa na primeira página: faixa grande com o título, o período e o escopo. */
    public bool $cover = false;
    private bool $coverDrawn = false;
    public string $docMeta = '';
    public const COVER_TOP = 62;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8');
        $this->setLanguageArray(['a_meta_charset' => 'UTF-8', 'a_meta_dir' => 'ltr', 'a_meta_language' => 'pt', 'w_page' => 'página']);
        $this->SetCreator('GLPI · Pesquisa de Satisfação');
        $this->SetMargins(self::X, self::TOP, self::X);
        $this->SetHeaderMargin(0);
        $this->SetFooterMargin(14);
        $this->SetAutoPageBreak(false);
        $this->SetFont('dejavusans', '', 8.5);
        $this->setCellHeightRatio(1.35);
    }

    public function Header()
    {
        // a primeira chamada do cabeçalho é a da página 1: só ela leva a capa
        if ($this->cover && !$this->coverDrawn) {
            $this->coverDrawn = true;
            $this->coverBand();
            return;
        }
        $this->fill(self::ACCENT);
        $this->Rect(0, 0, 210, 2.4, 'F');
        $this->fill(self::INK);
        $this->Rect(self::X, 8, 1.2, 14, 'F');
        $this->SetXY(17.5, 7.6);
        $this->SetFont('dejavusans', 'B', 6.5);
        $this->ink(self::ACCENT);
        $this->Cell(110, 4, 'PESQUISA DE SATISFAÇÃO', 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 13);
        $this->ink(self::INK);
        $this->Cell(110, 7, $this->docTitle, 0, 0, 'L');
        $this->SetXY(110, 9);
        $this->SetFont('dejavusans', 'B', 9);
        $this->Cell(86, 5, $this->docSub, 0, 0, 'R');
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.2);
        $this->Line(self::X, 24.5, self::X + self::W, 24.5);
    }

    /** Faixa de capa: título grande sobre fundo escuro, período e escopo. */
    private function coverBand(): void
    {
        $this->fill('#0f172a');
        $this->Rect(0, 0, 210, 52, 'F');
        $this->fill(self::ACCENT);
        $this->Rect(0, 0, 210, 2.4, 'F');
        $this->Rect(self::X, 14, 1.6, 26, 'F');
        $this->SetXY(self::X + 5, 12);
        $this->SetFont('dejavusans', 'B', 7.5);
        $this->ink('#a5b4fc');
        $this->Cell(130, 4.5, 'RELATÓRIO EXECUTIVO · CSAT / E-NPS', 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 21);
        $this->ink('#ffffff');
        $this->Cell(130, 11, $this->docTitle, 0, 2, 'L');
        $this->SetFont('dejavusans', '', 12);
        $this->ink('#cbd5e1');
        $this->Cell(130, 7, $this->docSub, 0, 2, 'L');
        $this->SetFont('dejavusans', '', 7.2);
        $this->ink('#94a3b8');
        $this->Cell(170, 5, $this->docMeta, 0, 0, 'L');
        $this->SetFont('dejavusans', 'B', 7);
        $this->ink('#94a3b8');
        $this->SetXY(140, 12);
        $this->Cell(56, 4.5, 'USO INTERNO', 0, 0, 'R');
    }

    public function Footer()
    {
        $this->SetY(-13);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->Line(self::X, $this->GetY(), self::X + self::W, $this->GetY());
        $this->SetFont('dejavusans', '', 6.5);
        $this->ink(self::MUTED);
        $this->Cell(140, 6, $this->docFoot, 0, 0, 'L');
        $this->Cell(42, 6, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
    }

    // ------------------------------------------------------------------ cores e textos

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

    /** Abre uma nova página quando $height não cabe no que sobra; devolve o Y onde seguir. */
    public function ensure(float $y, float $height): float
    {
        if ($y + $height > self::BOTTOM) {
            $this->AddPage();
            return self::TOP;
        }
        return $y;
    }

    /** Título de seção com a barra de cor. Devolve o Y de depois dele. */
    public function section(float $y, string $title, string $hint = ''): float
    {
        $y = $this->ensure($y, 16);
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
            return $y + 12.5;
        }
        return $y + 9;
    }

    /** Texto corrido (aceita HTML simples). Devolve o Y de depois dele. */
    public function paragraph(float $y, string $html, float $size = 8.5, float $x = self::X, float $w = self::W): float
    {
        $this->SetFont('dejavusans', '', $size);
        $this->ink(self::INK);
        $this->writeHTMLCell($w, 0, $x, $y, $html, 0, 1, false, true, 'L', true);
        return $this->GetY();
    }

    /** Lista com marcadores. Devolve o Y de depois dela. */
    public function bullets(float $y, array $lines): float
    {
        foreach ($lines as $line) {
            $y = $this->ensure($y, 9);
            $this->fill(self::ACCENT);
            $this->Circle(self::X + 2, $y + 2.4, 0.9, 0, 360, 'F');
            $y = $this->paragraph($y, $line, 8.5, self::X + 5, self::W - 5) + 1.4;
        }
        return $y;
    }

    /** Mistura a cor com branco (0 = branca, 1 = a própria cor), para fundos suaves. */
    public static function tint(string $hex, float $amount): string
    {
        $c = self::rgb($hex);
        return sprintf('#%02x%02x%02x', ...array_map(fn ($v) => (int) round(255 - (255 - $v) * $amount), $c));
    }

    /** Faixa de situação: barra colorida, rótulo em destaque e a frase que resume o mês. Devolve o Y de depois dela. */
    public function banner(float $y, string $label, string $text, string $color): float
    {
        $h = 17;
        $this->fill(self::tint($color, 0.12));
        $this->SetDrawColor(...self::rgb(self::tint($color, 0.35)));
        $this->SetLineWidth(0.25);
        $this->RoundedRect(self::X, $y, self::W, $h, 2, '1111', 'DF');
        $this->fill($color);
        $this->RoundedRect(self::X, $y, 3, $h, 1.5, '1001', 'F');
        $this->SetXY(self::X + 7, $y + 2.6);
        $this->SetFont('dejavusans', 'B', 7);
        $this->ink($color);
        $this->Cell(60, 3.8, mb_strtoupper($label), 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 10.5);
        $this->ink(self::INK);
        $this->MultiCell(self::W - 12, 5, $text, 0, 'L', false, 1);
        return $y + $h + 4;
    }

    /** Lista com marcadores coloridos numa coluna ($x, $w). Devolve o Y de depois dela. */
    public function bulletsAt(float $x, float $y, float $w, array $lines, string $color = self::ACCENT): float
    {
        foreach ($lines as $line) {
            $y = $this->ensure($y, 9);
            $this->fill($color);
            $this->Circle($x + 1.6, $y + 2.4, 0.9, 0, 360, 'F');
            $y = $this->paragraph($y, $line, 8, $x + 4.5, $w - 4.5) + 1.6;
        }
        return $y;
    }

    /** Tabela simples com faixas: $headers lista de rótulos, $rows lista de linhas de células (HTML simples), $widths em %. */
    public function table(float $y, array $headers, array $rows, array $widths, array $aligns): float
    {
        $html = '<table cellpadding="3.2" cellspacing="0" border="0" style="font-size:8pt"><tr style="background-color:#f1f5f9;color:#475569">';
        foreach ($headers as $i => $h) {
            $html .= '<th width="' . $widths[$i] . '%" align="' . $aligns[$i] . '"><b>' . htmlspecialchars($h) . '</b></th>';
        }
        $html .= '</tr>';
        foreach ($rows as $n => $row) {
            $html .= '<tr style="background-color:' . ($n % 2 ? '#f8fafc' : '#ffffff') . '">';
            foreach ($row as $i => $cell) {
                $html .= '<td width="' . $widths[$i] . '%" align="' . $aligns[$i] . '">' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $this->paragraph($y, $html . '</table>', 8) + 3;
    }

    // ------------------------------------------------------------------ cartões e gráficos

    /** Cartão de indicador: rótulo, valor grande, texto de apoio e a variação colorida. */
    public function card(float $x, float $y, float $w, float $h, string $label, string $value, string $sub, string $color, string $delta = '', string $deltaColor = self::MUTED, bool $big = false): void
    {
        $this->SetDrawColor(...self::rgb(self::LINE));
        $this->SetLineWidth(0.25);
        $this->fill('#ffffff');
        $this->RoundedRect($x, $y, $w, $h, 1.8, '1111', 'DF');
        $this->fill($color);
        $this->Rect($x, $y + 1.5, 1.2, $h - 3, 'F');
        $this->SetXY($x + 3.2, $y + 2);
        $this->SetFont('dejavusans', 'B', 6.2);
        $this->ink(self::MUTED);
        $this->Cell($w - 4, 3.6, mb_strtoupper($label), 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', $big ? 22 : 15);
        $this->ink(self::INK);
        $this->Cell($w - 4, $big ? 12 : 8, $value, 0, 2, 'L');
        $this->SetFont('dejavusans', '', 6.8);
        $this->ink(self::MUTED);
        $this->Cell($w - 4, 3.6, $sub, 0, 2, 'L');
        if ($delta !== '') {
            $this->SetFont('dejavusans', 'B', 6.8);
            $this->ink($deltaColor);
            $this->Cell($w - 4, 3.8, $delta, 0, 0, 'L');
        }
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
        $n = count($bars);
        $slot = $w / max(1, $n);
        $bw = min(14, $slot * 0.62);
        $base = $y + $h - 6;
        $this->SetDrawColor(...self::rgb(self::LINE));
        $this->Line($x, $base, $x + $w, $base);
        foreach ($bars as $i => [$label, $value, $color]) {
            $bh = ($h - 14) * $value / $max;
            $bx = $x + $slot * $i + ($slot - $bw) / 2;
            if ($value > 0) {
                $this->fill($color);
                $this->RoundedRect($bx, $base - $bh, $bw, $bh, 0.9, '1100', 'F');
                $this->SetFont('dejavusans', 'B', 7.5);
                $this->ink(self::INK);
                $this->SetXY($bx - 4, $base - $bh - 4.6);
                $this->Cell($bw + 8, 4, (string) $value, 0, 0, 'C');
            }
            $this->SetFont('dejavusans', '', 7);
            $this->ink(self::MUTED);
            $this->SetXY($bx - 5, $base + 1);
            $this->Cell($bw + 10, 4, (string) $label, 0, 0, 'C');
        }
    }

    /**
     * Barras horizontais com rótulo à esquerda e texto à direita. $rows: lista de [rótulo, valor, texto, cor].
     * Com $signed a barra parte do meio: valor negativo vai para a esquerda (eNPS). Devolve o Y de depois.
     */
    public function hbars(float $x, float $y, float $w, array $rows, float $max, float $labelW = 52, float $rowH = 7, bool $signed = false): float
    {
        $barW = $w - $labelW - 22;
        $x0 = $x + $labelW;
        foreach ($rows as [$label, $value, $text, $color]) {
            $y = $this->ensure($y, $rowH);
            $this->SetFont('dejavusans', '', 7.5);
            $this->ink(self::INK);
            $this->SetXY($x, $y);
            $this->Cell($labelW - 2, $rowH - 1, mb_strimwidth((string) $label, 0, 34, '…'), 0, 0, 'L');
            $this->fill('#f1f5f9');
            $this->RoundedRect($x0, $y + 1.2, $barW, 3.6, 1.2, '1111', 'F');
            $share = min(1, abs($value) / $max);
            if ($signed && $value != 0) {
                $len = max(1.5, $barW / 2 * $share);
                $this->fill($color);
                $this->Rect($value > 0 ? $x0 + $barW / 2 : $x0 + $barW / 2 - $len, $y + 1.2, $len, 3.6, 'F');
            } elseif (!$signed && $value > 0) {
                $this->fill($color);
                $this->RoundedRect($x0, $y + 1.2, max(1.5, $barW * $share), 3.6, 1.2, '1111', 'F');
            }
            if ($signed) {
                $this->SetDrawColor(...self::rgb('#94a3b8'));
                $this->Line($x0 + $barW / 2, $y + 0.4, $x0 + $barW / 2, $y + 5.6);
            }
            $this->SetFont('dejavusans', 'B', 7.5);
            $this->SetXY($x0 + $barW + 1.5, $y);
            $this->Cell(20, $rowH - 1, $text, 0, 0, 'L');
            $y += $rowH;
        }
        return $y;
    }

    /**
     * Evolução mensal: colunas empilhadas por humor e a nota média em linha (eixo de 0 a 5 à direita).
     * $months: lista com label, positive, neutral, negative, none e avg.
     */
    public function trend(float $x, float $y, float $w, float $h, array $months, array $moodColors): void
    {
        $n = count($months);
        $max = 1;
        foreach ($months as $m) {
            $max = max($max, $m['positive'] + $m['neutral'] + $m['negative'] + $m['none']);
        }
        $top = $y + 4;
        $base = $y + $h - 7;
        $plot = $base - $top;
        $this->SetDrawColor(...self::rgb(self::LINE));
        $this->SetLineWidth(0.15);
        for ($i = 0; $i <= 4; $i++) {
            $ly = $base - $plot * $i / 4;
            $this->Line($x + 6, $ly, $x + $w - 6, $ly);
            $this->SetFont('dejavusans', '', 6.2);
            $this->ink(self::MUTED);
            $this->SetXY($x - 2, $ly - 1.8);
            $this->Cell(7, 3.6, (string) round($max * $i / 4), 0, 0, 'R');
            $this->SetXY($x + $w - 5.5, $ly - 1.8);
            $this->Cell(7, 3.6, number_format(5 * $i / 4, 1, ',', ''), 0, 0, 'L');
        }
        $slot = ($w - 12) / max(1, $n);
        $bw = min(16, $slot * 0.55);
        $points = [];
        foreach ($months as $i => $m) {
            $bx = $x + 6 + $slot * $i + ($slot - $bw) / 2;
            $cy = $base;
            foreach (['positive', 'neutral', 'negative', 'none'] as $key) {
                $bh = $plot * $m[$key] / $max;
                if ($bh > 0) {
                    $this->fill($moodColors[$key]);
                    $this->Rect($bx, $cy - $bh, $bw, $bh, 'F');
                    $cy -= $bh;
                }
            }
            $this->SetFont('dejavusans', '', 7);
            $this->ink(self::MUTED);
            $this->SetXY($bx - 4, $base + 1.2);
            $this->Cell($bw + 8, 4, $m['label'], 0, 0, 'C');
            if ($m['avg'] !== null) {
                $points[] = [$bx + $bw / 2, $base - $plot * $m['avg'] / 5, $m['avg']];
            }
        }
        $this->SetLineStyle(['width' => 0.7, 'color' => self::rgb('#0d9488')]);
        for ($i = 1; $i < count($points); $i++) {
            $this->Line($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }
        foreach ($points as [$px, $py, $avg]) {
            $this->fill('#ffffff');
            $this->SetLineStyle(['width' => 0.5, 'color' => self::rgb('#0d9488')]);
            $this->Circle($px, $py, 1.1, 0, 360, 'DF');
            $this->SetFont('dejavusans', 'B', 6.5);
            $this->ink('#0f766e');
            $this->SetXY($px - 6, $py - 5);
            $this->Cell(12, 3.6, number_format($avg, 1, ',', ''), 0, 0, 'C');
        }
        $this->SetLineStyle(['width' => 0.2, 'color' => self::rgb('#cbd5e1')]);
    }
}
