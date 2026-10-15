<?php

namespace App\Service;

/**
 * Générateur minimal de classeurs Excel (.xlsx) mis en forme, sans dépendance externe.
 *
 * Usage :
 *   $xlsx = new XlsxWriter();
 *   $feuille = $xlsx->addSheet('Synthèse', [30, 12]);   // largeurs de colonnes
 *   $feuille->row([['Titre', XlsxWriter::TITRE]]);
 *   $feuille->row(['Libellé', 42]);
 *   $contenu = $xlsx->toString();
 *
 * Une cellule est une valeur simple (style par défaut) ou un tableau [valeur, style].
 */
class XlsxWriter
{
    // Styles disponibles (index dans styles.xml)
    public const NORMAL = 0;
    public const TITRE = 1;
    public const SOUS_TITRE = 2;
    public const ENTETE = 3;
    public const TEXTE = 4;
    public const NOMBRE = 5;
    public const POURCENT = 6;
    public const TEXTE_GRAS = 7;
    public const ALERTE = 8;
    public const SECTION = 9;
    public const KPI_VALEUR = 10;
    public const KPI_LIBELLE = 11;
    public const NOMBRE_GRAS = 12;
    public const POURCENT_GRAS = 13;
    public const SUCCES = 14;
    public const TEXTE_LONG = 15;
    public const KPI_POURCENT = 16;
    public const NOMBRE_ALERTE = 17;

    /** @var XlsxSheet[] */
    private array $sheets = [];

    /** @param float[] $largeurs largeurs des colonnes A, B, C… */
    public function addSheet(string $nom, array $largeurs = []): XlsxSheet
    {
        $nom = mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '-', $nom), 0, 31);
        $sheet = new XlsxSheet($nom, $largeurs);
        $this->sheets[] = $sheet;

        return $sheet;
    }

    public function toString(): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($fichier, \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sheet->toXml());
        }
        $zip->close();

        $contenu = file_get_contents($fichier);
        @unlink($fichier);

        return $contenu;
    }

    private function contentTypes(): string
    {
        $feuilles = '';
        foreach (array_keys($this->sheets) as $i) {
            $feuilles .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $feuilles
            . '</Types>';
    }

    private function workbook(): string
    {
        $feuilles = '';
        $noms = '';
        foreach ($this->sheets as $i => $sheet) {
            $feuilles .= sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', XlsxSheet::esc($sheet->nom), $i + 1, $i + 1);
            if ($sheet->filtre) {
                // Plage nommée requise par Excel pour le filtre automatique
                $noms .= sprintf(
                    '<definedName name="_xlnm._FilterDatabase" localSheetId="%d" hidden="1">\'%s\'!%s</definedName>',
                    $i,
                    str_replace("'", "''", XlsxSheet::esc($sheet->nom)),
                    preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $sheet->filtre)
                );
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $feuilles . '</sheets>'
            . ($noms ? '<definedNames>' . $noms . '</definedNames>' : '')
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach (array_keys($this->sheets) as $i) {
            $rels .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $i + 1,
                $i + 1
            );
        }
        $rels .= sprintf(
            '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
            count($this->sheets) + 1
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    /**
     * Polices : 0 normale, 1 titre, 2 sous-titre, 3 en-tête blanc, 4 gras, 5 alerte, 6 section, 7 KPI, 8 libellé KPI, 9 succès.
     * Remplissages : 2 bleu marine (en-têtes), 3 gris clair (totaux), 4 rouge pâle (alertes).
     */
    private function styles(): string
    {
        $xf = fn (int $font, int $fill, int $border, int $numFmt = 0, string $align = '') => sprintf(
            '<xf numFmtId="%d" fontId="%d" fillId="%d" borderId="%d" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"%s>%s</xf>',
            $numFmt, $font, $fill, $border, $align ? ' applyAlignment="1"' : '', $align
        );
        $centre = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
        $gauche = '<alignment vertical="top" wrapText="1"/>';
        $vcentre = '<alignment vertical="center"/>';

        $cellules = [
            self::NORMAL => $xf(0, 0, 0),
            self::TITRE => $xf(1, 0, 0),
            self::SOUS_TITRE => $xf(2, 0, 0),
            self::ENTETE => $xf(3, 2, 1, 0, $centre),
            self::TEXTE => $xf(0, 0, 1, 0, $vcentre),
            self::NOMBRE => $xf(0, 0, 1, 3, $vcentre),
            self::POURCENT => $xf(0, 0, 1, 164, $vcentre),
            self::TEXTE_GRAS => $xf(4, 3, 1, 0, $vcentre),
            self::ALERTE => $xf(5, 4, 1, 0, $vcentre),
            self::SECTION => $xf(6, 0, 0),
            self::KPI_VALEUR => $xf(7, 0, 2, 3, $centre),
            self::KPI_LIBELLE => $xf(8, 0, 0, 0, $centre),
            self::NOMBRE_GRAS => $xf(4, 3, 1, 3, $vcentre),
            self::POURCENT_GRAS => $xf(4, 3, 1, 164, $vcentre),
            self::SUCCES => $xf(9, 0, 1, 0, $vcentre),
            self::TEXTE_LONG => $xf(0, 0, 1, 0, $gauche),
            self::KPI_POURCENT => $xf(7, 0, 2, 164, $centre),
            self::NOMBRE_ALERTE => $xf(5, 4, 1, 3, $vcentre),
        ];
        ksort($cellules);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>'
            . '<fonts count="10">'
            . '<font><sz val="10"/><color rgb="FF1E293B"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="16"/><color rgb="FF0A2540"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF0A2540"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFB91C1C"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><color rgb="FF0A2540"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="20"/><color rgb="FF0A2540"/><name val="Calibri"/></font>'
            . '<font><sz val="9"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF047857"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0A2540"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEF2F2"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right>'
            . '<top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="medium"><color rgb="FFE5A93C"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($cellules) . '">' . implode('', $cellules) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

