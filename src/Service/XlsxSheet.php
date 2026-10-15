<?php

namespace App\Service;

/**
 * Une feuille du classeur : lignes, fusions, volet figé et filtre automatique.
 */
class XlsxSheet
{
    /** @var string[] */
    private array $lignes = [];
    /** @var string[] */
    private array $fusions = [];
    private ?int $ligneFigee = null;
    public ?string $filtre = null;

    public function __construct(public readonly string $nom, private array $largeurs)
    {
    }

    /** Numéro de la prochaine ligne (1-based) */
    public function nextRow(): int
    {
        return count($this->lignes) + 1;
    }

    /**
     * @param array<int, mixed> $cellules valeur, ou [valeur, style]
     */
    public function row(array $cellules = [], ?float $hauteur = null): int
    {
        $n = $this->nextRow();
        $xml = '';
        foreach (array_values($cellules) as $i => $cellule) {
            [$valeur, $style] = is_array($cellule) ? $cellule + [null, XlsxWriter::NORMAL] : [$cellule, XlsxWriter::NORMAL];
            $ref = self::colonne($i) . $n;
            if ($valeur === null || $valeur === '') {
                $xml .= sprintf('<c r="%s" s="%d"/>', $ref, $style);
            } elseif (is_int($valeur) || is_float($valeur)) {
                $xml .= sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, $style, rtrim(rtrim(number_format($valeur, 6, '.', ''), '0'), '.'));
            } else {
                $xml .= sprintf('<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $ref, $style, self::esc((string) $valeur));
            }
        }
        $attrs = $hauteur ? sprintf(' ht="%s" customHeight="1"', $hauteur) : '';
        $this->lignes[] = sprintf('<row r="%d"%s>%s</row>', $n, $attrs, $xml);

        return $n;
    }

    public function merge(string $plage): static
    {
        $this->fusions[] = $plage;
        return $this;
    }

    /** Fige les lignes au-dessus de $ligne (ex. 5 : lignes 1 à 4 toujours visibles) */
    public function freezeAbove(int $ligne): static
    {
        $this->ligneFigee = $ligne;
        return $this;
    }

    public function autoFilter(string $plage): static
    {
        $this->filtre = $plage;
        return $this;
    }

    public static function colonne(int $index): string
    {
        $lettres = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $lettres = chr(65 + ($i - 1) % 26) . $lettres;
        }
        return $lettres;
    }

    public static function esc(string $texte): string
    {
        // Retire les caractères de contrôle interdits en XML
        $texte = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texte);
        return htmlspecialchars($texte, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function toXml(): string
    {
        $vue = '<sheetView workbookViewId="0" showGridLines="0">';
        if ($this->ligneFigee) {
            $vue .= sprintf('<pane ySplit="%d" topLeftCell="A%d" activePane="bottomLeft" state="frozen"/>', $this->ligneFigee - 1, $this->ligneFigee);
        }
        $vue .= '</sheetView>';

        $colonnes = '';
        foreach ($this->largeurs as $i => $largeur) {
            $colonnes .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $i + 1, $i + 1, $largeur);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            // Impression : toutes les colonnes tiennent sur la largeur d'une page
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews>' . $vue . '</sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($colonnes ? '<cols>' . $colonnes . '</cols>' : '')
            . '<sheetData>' . implode('', $this->lignes) . '</sheetData>'
            . ($this->filtre ? sprintf('<autoFilter ref="%s"/>', $this->filtre) : '')
            . ($this->fusions ? '<mergeCells count="' . count($this->fusions) . '">' . implode('', array_map(fn ($p) => '<mergeCell ref="' . $p . '"/>', $this->fusions)) . '</mergeCells>' : '')
            . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';
    }
}
