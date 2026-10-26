<?php

namespace GlpiPlugin\Reembolso;

use TCPDF;

/**
 * Página dos PDFs do reembolso: faixa e título no topo de todas as páginas e rodapé
 * com a data de geração e "Página x de y".
 */
class PdfDoc extends TCPDF
{
    public string $docKind = '';
    public string $docTitle = '';
    public string $docRight = '';
    public string $docRightSub = '';
    public string $docFoot = '';

    /** Onde o LibreSign desenha a assinatura visível na última página (mm, a partir do topo). */
    public const SIGN_BOX = [102.3, 239.5, 95.3, 27.2];

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8');
        $this->setLanguageArray(['a_meta_charset' => 'UTF-8', 'a_meta_dir' => 'ltr', 'a_meta_language' => 'pt', 'w_page' => 'página']);
        $this->SetCreator('GLPI · Reembolso de despesas');
        $this->SetMargins(14, 27, 14);
        $this->SetHeaderMargin(0);
        $this->SetFooterMargin(14);
        $this->SetAutoPageBreak(true, 20);
        $this->SetFont('dejavusans', '', 8.5);
        $this->setCellHeightRatio(1.35);
    }

    public function Header()
    {
        $this->SetFillColor(14, 116, 144);
        $this->Rect(0, 0, 210, 2.2, 'F');
        $this->SetFillColor(15, 23, 42);
        $this->Rect(14, 8, 1.2, 12, 'F');
        $this->SetXY(17.5, 7.6);
        $this->SetFont('dejavusans', 'B', 6.5);
        $this->SetTextColor(14, 116, 144);
        $this->Cell(110, 4, mb_strtoupper($this->docKind), 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 13);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(110, 7, $this->docTitle, 0, 0, 'L');
        $this->SetXY(110, 8);
        $this->SetFont('dejavusans', 'B', 8.5);
        $this->Cell(86, 4.5, $this->docRight, 0, 2, 'R');
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(86, 4.5, $this->docRightSub, 0, 0, 'R');
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.2);
        $this->Line(14, 22.5, 196, 22.5);
    }

    public function Footer()
    {
        $this->SetY(-13);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->Line(14, $this->GetY(), 196, $this->GetY());
        $this->SetFont('dejavusans', '', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(140, 6, $this->docFoot, 0, 0, 'L');
        $this->Cell(42, 6, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
    }

    /** Quadro reservado para a assinatura digital, com o nome de quem assina ao lado. */
    public function signatureBox(string $name, string $role): void
    {
        [$x, $y, $w, $h] = self::SIGN_BOX;
        $this->SetDrawColor(148, 163, 184);
        $this->SetLineStyle(['width' => 0.25, 'dash' => '2,1.5', 'color' => [148, 163, 184]]);
        $this->RoundedRect($x, $y, $w, $h, 1.5, '1111', 'D');
        $this->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [203, 213, 225]]);
        $this->SetFont('dejavusans', 'B', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->SetXY($x, $y - 4.5);
        $this->Cell($w, 4, 'ASSINATURA DIGITAL (LIBRESIGN)', 0, 0, 'L');
        // à esquerda: quem assina
        $this->SetDrawColor(15, 23, 42);
        $this->Line(14, $y + $h - 7, 96, $y + $h - 7);
        $this->SetXY(14, $y + $h - 6);
        $this->SetFont('dejavusans', 'B', 8.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(82, 4, $name, 0, 2, 'L');
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(82, 4, $role, 0, 0, 'L');
    }
}
