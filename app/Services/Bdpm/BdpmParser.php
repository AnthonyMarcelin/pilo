<?php

namespace App\Services\Bdpm;

/**
 * Logique de parsing des 3 fichiers BDPM.
 *
 * Tous les fichiers sont encodés ISO-8859-1 (Latin-1), tab-séparés, sans en-tête.
 *
 * — CIS_CIP_bdpm.txt   : conditionnement (units_per_box, nom, CIP13)
 * — CIS_HAS_SMR_bdpm.txt : indication thérapeutique (libelle_SMR)
 * — CIS_GENER_bdpm.txt  : liaison générique → CIS originator
 *
 * Voir CLAUDE.md §3, SPEC §9.
 */
final class BdpmParser
{
    /**
     * CIS_CIP_bdpm.txt → indexé par CIS.
     *
     * Colonnes (tab, sans en-tête) :
     *   0  CIS
     *   1  CIP7
     *   2  Libellé de la présentation
     *   3  Statut administratif AMM
     *   4  État de commercialisation
     *   5  Date de la déclaration
     *   6  CIP13
     *   7  Agrément collectivités
     *   8  Taux de remboursement
     *   9  Prix TTC
     *
     * Si plusieurs CIP pour un même CIS, on garde le dernier lu
     * (order BDPM = par CIP croissant, donc on obtient le plus gros conditionnement).
     *
     * @return array<string, array{cip13:string|null, name:string, presentation_label:string, units_per_box:int|null}>
     */
    public function parseCisCip(string $filePath): array
    {
        $result = [];

        foreach ($this->readLines($filePath) as $line) {
            $cols = explode("\t", $line);
            if (count($cols) < 3) {
                continue;
            }

            $cis    = trim($cols[0]);
            $label  = trim($cols[2]);
            $cip13  = isset($cols[6]) ? trim($cols[6]) : null;

            if ($cis === '') {
                continue;
            }

            $result[$cis] = [
                'cip13'              => ($cip13 !== '') ? $cip13 : null,
                'name'               => $this->extractName($label),
                'presentation_label' => $label,
                'units_per_box'      => $this->extractUnitsPerBox($label),
            ];
        }

        return $result;
    }

    /**
     * CIS_HAS_SMR_bdpm.txt → CIS → indication (libelle_SMR le plus récent non creux).
     *
     * Colonnes (tab, sans en-tête) :
     *   0  CIS
     *   1  Code HAS
     *   2  Motif d'évaluation
     *   3  Date de l'avis (JJ/MM/AAAA)
     *   4  Valeur SMR
     *   5  Libellé SMR
     *
     * Algorithme :
     *   - Trier par date DESC par CIS.
     *   - Prendre le premier libelle_SMR non vide et ne se terminant pas par
     *     « dans l'indication de l'AMM » (formule creuse).
     *
     * @return array<string, string>  CIS → libellé nettoyé
     */
    public function parseCisHasSMR(string $filePath): array
    {
        // Accumule toutes les lignes par CIS : [cis => [[date, libelle], ...]]
        $rows = [];

        foreach ($this->readLines($filePath) as $line) {
            $cols = explode("\t", $line);
            if (count($cols) < 6) {
                continue;
            }

            $cis     = trim($cols[0]);
            $date    = trim($cols[3]);   // JJ/MM/AAAA
            $libelle = trim($cols[5]);

            if ($cis === '') {
                continue;
            }

            $rows[$cis][] = [$this->parseDate($date), $libelle];
        }

        $result = [];

        foreach ($rows as $cis => $entries) {
            // Trier par date décroissante
            usort($entries, fn ($a, $b) => strcmp($b[0], $a[0]));

            foreach ($entries as [$date, $libelle]) {
                if ($this->isValidLibelle($libelle)) {
                    $result[$cis] = $this->cleanLibelle($libelle);
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * CIS_GENER_bdpm.txt → CIS générique → CIS originator.
     *
     * Colonnes (tab, sans en-tête) :
     *   0  groupe_id
     *   1  libelle_groupe
     *   2  CIS
     *   3  type  (0 = originator, 1 = générique)
     *   4  rang
     *
     * @return array<string, string>  CIS générique → CIS originator
     */
    public function parseCisGener(string $filePath): array
    {
        // Collecte les groupes : groupe_id → [cis_originator, [cis_generique, ...]]
        $originators = [];   // groupe_id → CIS originator
        $generiques  = [];   // groupe_id → [CIS génériques]

        foreach ($this->readLines($filePath) as $line) {
            $cols = explode("\t", $line);
            if (count($cols) < 4) {
                continue;
            }

            $groupId = trim($cols[0]);
            $cis     = trim($cols[2]);
            $type    = (int) trim($cols[3]);

            if ($groupId === '' || $cis === '') {
                continue;
            }

            if ($type === 0) {
                $originators[$groupId] = $cis;
            } else {
                $generiques[$groupId][] = $cis;
            }
        }

        // Mappe chaque générique → son originator
        $result = [];
        foreach ($generiques as $groupId => $cisList) {
            $originator = $originators[$groupId] ?? null;
            if ($originator === null) {
                continue;
            }
            foreach ($cisList as $cisg) {
                $result[$cisg] = $originator;
            }
        }

        return $result;
    }

    /**
     * CIS_COMPO_bdpm.txt → CIS → substance active (DCI/INN).
     *
     * Colonnes réelles (tab, sans en-tête, 8 colonnes) :
     *   0  CIS
     *   1  Désignation de l'élément pharmaceutique (ex : "comprimé pelliculé")
     *   2  Code substance (numérique)
     *   3  Nom de la substance  ← DCI (ex : "AMLODIPINE", "LÉVOTHYROXINE SODIQUE")
     *   4  Dosage de la substance
     *   5  Référence de ce dosage
     *   6  Nature du composant  (SA = substance active, FT = fraction thérapeutique)
     *   7  Numéro de liaison SA/FT
     *
     * On garde uniquement les lignes de type "SA" pour la substance principale.
     * Si plusieurs SA pour un CIS (ex : associations fixes), on concatène avec " / ".
     *
     * @return array<string, string>  CIS → nom de la substance (DCI)
     */
    public function parseCisCompo(string $filePath): array
    {
        $rows = [];

        foreach ($this->readLines($filePath) as $line) {
            $cols = explode("\t", $line);
            if (count($cols) < 7) {
                continue;
            }

            $cis    = trim($cols[0]);
            $nom    = trim($cols[3]);  // col 3 = Nom substance, pas col 2 (= code numérique)
            $nature = strtoupper(trim($cols[6]));  // col 6 = Nature (SA/FT)

            if ($cis === '' || $nom === '' || $nature !== 'SA') {
                continue;
            }

            $rows[$cis][] = $nom;
        }

        return array_map(
            fn (array $names) => implode(' / ', array_unique($names)),
            $rows,
        );
    }

    // ─── Helpers publics (testables séparément) ───────────────────────────────

    /**
     * Extrait le nombre d'unités par boîte depuis le libellé de présentation.
     *
     * Exemples :
     *   "PARACETAMOL 500 mg - Boîte de 30 comprimés" → 30
     *   "plaquette(s) de 28 comprimés" → 28
     *   "3 flacons de 100 mL" → 3
     */
    public function extractUnitsPerBox(string $label): ?int
    {
        // Patterns explicites : "boîte/plaquette/flacon/sachet de N"
        if (preg_match(
            '/(?:boîte|plaquette|flacon|sachet|tube|ampoule|stylo|seringue|dose|applicateur)\s*\(?s?\)?\s+de\s+(\d+)/iu',
            $label,
            $m,
        )) {
            return (int) $m[1];
        }

        // "N flacons/boîtes/..." en début de libellé de conditionnement
        if (preg_match(
            '/^[^-]+[-–]\s*(\d+)\s+(?:flacon|boîte|tube|ampoule|sachet|stylo|seringue|applicateur)/iu',
            $label,
            $m,
        )) {
            return (int) $m[1];
        }

        // Nombre final avant unité pharmacologique
        if (preg_match(
            '/(\d+)\s+(?:comprimé|gélule|capsule|suppositoire|ovule|patch|implant|lyoph|sachet-doses?)/iu',
            $label,
            $m,
        )) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Extrait le nom du médicament depuis le libellé de présentation.
     * Tout ce qui précède le " - " final (packaging info).
     */
    public function extractName(string $label): string
    {
        // Le libellé BDPM suit souvent "NOM dosage, forme - conditionnement"
        $pos = strrpos($label, ' - ');
        if ($pos !== false) {
            return trim(substr($label, 0, $pos));
        }
        return trim($label);
    }

    // ─── Privés ───────────────────────────────────────────────────────────────

    /**
     * Lit un fichier ISO-8859-1 ligne par ligne, convertit en UTF-8.
     *
     * @return iterable<string>
     */
    private function readLines(string $filePath): iterable
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Impossible d'ouvrir le fichier : {$filePath}");
        }

        try {
            while (($raw = fgets($handle)) !== false) {
                $line = mb_convert_encoding(rtrim($raw, "\r\n"), 'UTF-8', 'ISO-8859-1');
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** Convertit JJ/MM/AAAA en AAAA-MM-JJ pour tri lexicographique. */
    private function parseDate(string $date): string
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return $date; // fallback : laisse tel quel
    }

    /** Un libellé SMR est valide s'il n'est pas vide et n'est pas une formule creuse. */
    private function isValidLibelle(string $libelle): bool
    {
        if ($libelle === '') {
            return false;
        }
        // Formule creuse type "Insuffisant dans l'indication de l'AMM"
        if (str_ends_with(mb_strtolower($libelle), "dans l'indication de l'amm")) {
            return false;
        }
        return true;
    }

    /** Nettoie les entités HTML du libellé SMR. */
    private function cleanLibelle(string $libelle): string
    {
        // &lt;br&gt; → \n, autres entités HTML → caractère réel
        $clean = str_replace(['&lt;br&gt;', '&lt;BR&gt;', '<br>', '<BR>'], "\n", $libelle);
        return html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
