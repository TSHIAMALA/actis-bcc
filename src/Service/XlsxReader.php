<?php

namespace App\Service;

/**
 * Lecture minimale de la première feuille d'un classeur Excel (.xlsx), sans dépendance externe.
 */
class XlsxReader
{
    /**
     * Retourne les lignes non vides de la première feuille, indexées par en-tête.
     *
     * @param array<string, string> $colonnes en-tête attendu (insensible à la casse) => clé dans le résultat
     *
     * @return array<int, array<string, string>> numéro de ligne Excel => [clé => valeur]
     */
    public function read(string $chemin, array $colonnes): array
    {
        $grille = $this->readGrid($chemin);

        $entetes = array_map(fn ($v) => mb_strtolower($v), array_shift($grille) ?? []);
        $positions = [];
        foreach ($colonnes as $entete => $cle) {
            $col = array_search(mb_strtolower($entete), $entetes, true);
            if ($col === false) {
                throw new \RuntimeException("Colonne « $entete » introuvable dans le fichier.");
            }
            $positions[$cle] = $col;
        }

        $lignes = [];
        foreach ($grille as $i => $cellules) {
            $ligne = array_map(fn ($col) => $cellules[$col] ?? '', $positions);
            if (implode('', $ligne) !== '') {
                $lignes[$i + 2] = $ligne;
            }
        }

        return $lignes;
    }

    /**
     * @return array<int, array<string, string>> lignes => [lettre de colonne => valeur]
     */
    private function readGrid(string $chemin): array
    {
        if (!is_file($chemin)) {
            throw new \RuntimeException("Fichier introuvable : $chemin");
        }

        $zip = new \ZipArchive();
        if ($zip->open($chemin) !== true) {
            throw new \RuntimeException("Impossible d'ouvrir le fichier Excel : $chemin");
        }

        $partages = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $texte = (string) $si->t;
                foreach ($si->r as $r) {
                    $texte .= (string) $r->t;
                }
                $partages[] = $texte;
            }
        }

        $feuille = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($feuille === false) {
            throw new \RuntimeException('Aucune feuille trouvée dans le fichier Excel.');
        }

        $grille = [];
        foreach (simplexml_load_string($feuille)->sheetData->row as $row) {
            $cellules = [];
            foreach ($row->c as $c) {
                preg_match('/^[A-Z]+/', (string) $c['r'], $col);
                $valeur = (string) $c->v;
                if ((string) $c['t'] === 's') {
                    $valeur = $partages[(int) $valeur] ?? '';
                } elseif ((string) $c['t'] === 'inlineStr') {
                    $valeur = (string) $c->is->t;
                }
                $cellules[$col[0]] = trim(preg_replace('/\s+/u', ' ', $valeur));
            }
            $grille[] = $cellules;
        }

        return $grille;
    }
}
