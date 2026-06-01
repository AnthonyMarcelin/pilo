<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP vers Ollama pour la normalisation JSON contrainte.
 *
 * Rôle : blocs OCR ordonnés → JSON structuré (normalisation uniquement).
 * Le paramètre `format` (JSON Schema) est TOUJOURS passé pour activer le
 * constrained decoding — c'est la contrainte de sécurité qui rend le 1.5B viable
 * pour une app médicale (SPEC §8, CLAUDE.md §3.1).
 */
class OllamaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model           = 'qwen2.5:7b-instruct',
        private readonly int    $timeoutSeconds  = 900, // 15 min — 7B CPU ~5-6 min d'inférence ; worker=960
    ) {}

    /**
     * Schéma JSON imposé à Ollama via le paramètre `format`.
     * Conforme au contrat SPEC §8.
     */
    public static function prescriptionSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['items'],
            'properties' => [
                'prescriber_name' => ['type' => ['string', 'null']],
                'prescribed_at'   => ['type' => ['string', 'null']],
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['medication_name', 'intake_type', 'posologie_brute'],
                        'properties' => [
                            'medication_name' => ['type' => 'string'],
                            'dosage'          => ['type' => ['string', 'null']],
                            'intake_type'     => ['type' => 'string', 'enum' => ['fixe', 'si_besoin', 'autre']],
                            'morning'         => ['type' => ['number', 'null']],
                            'noon'            => ['type' => ['number', 'null']],
                            'evening'         => ['type' => ['number', 'null']],
                            'bedtime'         => ['type' => ['number', 'null']],
                            'condition'       => ['type' => ['string', 'null']],
                            'max_per_day'     => ['type' => ['number', 'null']],
                            'duration_days'   => ['type' => ['integer', 'null']],
                            'qsp_days'        => ['type' => ['integer', 'null']],
                            'posologie_brute' => ['type' => 'string'],
                            'phases' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'required'   => ['duration_days'],
                                    'properties' => [
                                        'duration_days' => ['type' => 'integer'],
                                        'morning'       => ['type' => ['number', 'null']],
                                        'noon'          => ['type' => ['number', 'null']],
                                        'evening'       => ['type' => ['number', 'null']],
                                        'bedtime'       => ['type' => ['number', 'null']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Prompt de normalisation envoyé à Ollama.
     *
     * Objectifs : complétude (tous les médicaments), dosage isolé, paliers dégressifs,
     * anti-hallucination stricte.
     *
     * @param  array  $blocks  Blocs ordonnés retournés par PaddleVlClient.
     */
    private function buildPrompt(array $blocks): string
    {
        $blockText = '';
        foreach ($blocks as $b) {
            $label   = $b['block_label']   ?? 'text';
            $content = $b['block_content'] ?? '';
            $order   = $b['block_order']   ?? '?';
            $blockText .= "[{$order}|{$label}] {$content}\n";
        }

        return <<<PROMPT
Tu es un pharmacien assistant qui extrait les données d'une ordonnance médicale en JSON structuré.

═══════════ RÈGLES FONDAMENTALES ═══════════

RÈGLE 0 — FORMAT DE RÉPONSE (absolue) :
Réponds UNIQUEMENT avec un objet JSON valide. Aucun texte avant ou après le JSON.
Les valeurs des champs string contiennent UNIQUEMENT du contenu médical extrait du texte.
INTERDIT dans les valeurs : "Not provided", "interpreted as", "hence", "null because",
  "not applicable", "not specified", ou tout raisonnement en anglais ou en français.
Si une info est absente → null. Jamais une phrase d'explication.

RÈGLE 1 — COMPLÉTUDE (erreur médicale si non respectée) :
Extrais ABSOLUMENT TOUS les médicaments présents dans les blocs, sans exception.
Lis le texte de haut en bas. Pour chaque médicament listé, crée un item JSON.
Si l'ordonnance contient N médicaments, ton JSON doit contenir N items.

RÈGLE 2 — ANTI-LABELS (critique) :
L'OCR capture les cases imprimées du formulaire d'ordonnance :
  "Etablissement", "N° FINESS", "Prescripteur", "N° RPPS", "Médecin traitant",
  "Adresse", "Code postal", "Ville", "Allergies", "Mutuelle", "N° Sécurité sociale",
  "Signature", "Cachet", "Identifiant", "Référence", "Date", "Etablissement - n° FINESS".
Ces labels NE SONT PAS des médicaments. NE JAMAIS les inclure dans items[].
Un médicament est une molécule ou un produit pharmaceutique prescrit, pas un label de case.

RÈGLE 3 — ANTI-INVENTION (sécurité absolue) :
Tout champ absent ou illisible = null. N'invente jamais un médicament, un dosage,
une posologie ou un prescripteur.
prescriber_name = nom du médecin TEL QU'ÉCRIT dans le texte (ex: "Dr Didier Delhaye").
  NE PAS inventer un nom générique ("Dr. Dupont"). null si absent.

RÈGLE 4 — medication_name :
Nom du médicament seul, SANS la concentration.
Correct : "Paroxétine", "Lévothyroxine", "Metformine".
Incorrect : "Paroxétine 20 mg" (le "20 mg" va dans dosage).

RÈGLE 5 — dosage (TOUJOURS extraire si présent) :
La concentration/teneur du médicament, telle qu'écrite dans le texte (quantité + unité).
Ce n'est PAS le nombre de comprimés à prendre — c'est la teneur de la forme pharmaceutique.
Formats : "500 mg", "150 microgrammes", "7,5 mg", "5 mg/5 mL", "10 000 UI", "0,5%".
MÊME si le dosage est accolé au nom ("Amlodipine 5 mg" → name:"Amlodipine" dosage:"5 mg").
MÊME si le dosage est en texte joint ("Diazépam comprimé 5mg" → dosage:"5 mg").
null UNIQUEMENT si aucune concentration ne figure nulle part dans le texte pour ce médicament.

RÈGLE 6 — intake_type :
- "fixe"     : horaire régulier (matin / midi / soir / coucher).
- "si_besoin": conditionnel (si douleur, si fièvre, PRN).
- "autre"    : irrégulier non planifiable (1 jour sur 2, hebdomadaire…).

RÈGLE 7 — morning / noon / evening / bedtime :
Nombre d'unités par prise. null si intake_type ≠ "fixe" OU si phases[] non vide.

RÈGLE 8 — phases[] (PALIERS DÉGRESSIFS) — ERREUR CRITIQUE si non respectée :
Un médicament à posologie dégressive = UN SEUL item JSON avec phases[] rempli.
INTERDIT : créer deux items distincts pour le même médicament (un pour "7j" et un pour "15j").
INTERDIT : mettre morning/noon/evening/bedtime dans l'item parent et laisser phases:[].
Signaux dans le texte : "puis", "ensuite", "progressivement", "réduire à",
  durées successives ("pendant 7 jours PUIS 1 comprimé 15 jours", "3j×2cp puis 5j×1cp").
→ Créer UN objet par segment : {"duration_days": N, "morning": X, "noon": Y, "evening": Z, "bedtime": W}.
→ phases[] non vide → morning/noon/evening/bedtime de l'item parent = null OBLIGATOIREMENT.
→ Paliers priment sur dose directe si les deux apparaissent dans le même item.

RÈGLE 9 — posologie_brute :
Copier VERBATIM. Toujours rempli.

RÈGLE 10 — duration_days / qsp_days / dates :
duration_days = jours (entier). qsp_days = "QSP X jours". Dates : YYYY-MM-DD.

═══════════ EXEMPLES ═══════════

EXEMPLE 1 — Simple :
Blocs : "[1|text] Dr. Didier Delhaye / 15/03/2026 [2|text] Amoxicilline 500 mg 1 gélule matin midi soir 7 jours"
→ {"prescriber_name":"Dr. Didier Delhaye","prescribed_at":"2026-03-15","items":[{"medication_name":"Amoxicilline","dosage":"500 mg","intake_type":"fixe","morning":1,"noon":1,"evening":1,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":7,"qsp_days":null,"posologie_brute":"1 gélule matin midi soir 7 jours","phases":[]}]}

EXEMPLE 2 — Palier dégressif (1 seul item) :
Blocs : "[1|text] Paroxétine 20 mg 2 cp/j pendant 7j PUIS 1 cp/j pendant 15j PUIS arrêt"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Paroxétine","dosage":"20 mg","intake_type":"fixe","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":22,"qsp_days":null,"posologie_brute":"2 cp/j pendant 7j PUIS 1 cp/j pendant 15j PUIS arrêt","phases":[{"duration_days":7,"morning":2,"noon":null,"evening":null,"bedtime":null},{"duration_days":15,"morning":1,"noon":null,"evening":null,"bedtime":null}]}]}

EXEMPLE 4 — Palier dégressif avec dosage séparé (cas fréquent corticoïdes/antidépresseurs) :
Blocs : "[1|text] Dr. Lemaire / 10/05/2026 [2|text] Prednisolone 20 mg 3 comprimés/jour pendant 5 jours PUIS 2 comprimés/jour 5 jours PUIS 1 comprimé/jour 5 jours"
→ {"prescriber_name":"Dr. Lemaire","prescribed_at":"2026-05-10","items":[{"medication_name":"Prednisolone","dosage":"20 mg","intake_type":"fixe","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":15,"qsp_days":null,"posologie_brute":"3 comprimés/jour pendant 5 jours PUIS 2 comprimés/jour 5 jours PUIS 1 comprimé/jour 5 jours","phases":[{"duration_days":5,"morning":3,"noon":null,"evening":null,"bedtime":null},{"duration_days":5,"morning":2,"noon":null,"evening":null,"bedtime":null},{"duration_days":5,"morning":1,"noon":null,"evening":null,"bedtime":null}]}]}
(Note : morning/noon/evening/bedtime de l'item parent = null car phases[] non vide. dosage = "20 mg" extrait du nom.)

EXEMPLE 3 — Labels à ignorer + plusieurs médicaments :
Blocs : "[1|text] Etablissement [2|text] N° FINESS [3|text] Dr. Martin / 01/06/2026 [4|text] Metformine 850 mg 1 cp matin soir [5|text] Paracétamol 1000 mg si douleur max 4/j [6|text] Prescripteur [7|text] N° RPPS"
→ {"prescriber_name":"Dr. Martin","prescribed_at":"2026-06-01","items":[{"medication_name":"Metformine","dosage":"850 mg","intake_type":"fixe","morning":1,"noon":null,"evening":1,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":null,"qsp_days":null,"posologie_brute":"1 cp matin soir","phases":[]},{"medication_name":"Paracétamol","dosage":"1000 mg","intake_type":"si_besoin","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":"si douleur","max_per_day":4,"duration_days":null,"qsp_days":null,"posologie_brute":"si douleur max 4/j","phases":[]}]}
(Note : "Etablissement", "N° FINESS", "Prescripteur", "N° RPPS" ignorés — ce sont des labels de formulaire.)

═══════════ BLOCS OCR À TRAITER ═══════════

{$blockText}
PROMPT;
    }

    /**
     * Envoie les blocs OCR à Ollama et retourne le JSON normalisé (tableau PHP).
     *
     * @param  array  $blocks  Blocs ordonnés retournés par PaddleVlClient.
     * @return array  JSON décodé conforme au schéma de prescription.
     *
     * @throws OcrException  Si Ollama est indisponible, la réponse est vide, ou le JSON est invalide.
     */
    public function normalize(array $blocks): array
    {
        $prompt = $this->buildPrompt($blocks);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/api/generate", [
                    'model'   => $this->model,
                    'prompt'  => $prompt,
                    'format'  => self::prescriptionSchema(),
                    'stream'  => false,
                    'options' => [
                        // Température 0 = déterminisme maximal : l'extraction médicale
                        // doit être reproductible, pas créative. Réduit les dosages
                        // oubliés et les paliers dégressifs aplatis.
                        'temperature' => 0.0,
                        // Graine fixe pour reproductibilité entre relances (debug).
                        'seed'        => 42,
                    ],
                ]);
        } catch (\Throwable $e) {
            throw new OcrException("OllamaClient : service indisponible — {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new OcrException(
                "OllamaClient : réponse HTTP {$response->status()} depuis Ollama",
            );
        }

        $raw = $response->json('response', '');

        // Log de diagnostic — permet de voir si le dosage est produit par le modèle
        // ou perdu dans le mapping. Visible via : docker compose logs queue --tail=100
        // Limité à 3000 chars pour ne pas saturer les logs.
        Log::info('[OllamaClient] JSON brut', [
            'model'    => $this->model,
            'response' => mb_substr($raw, 0, 3000),
        ]);

        return $this->parseJsonDefensively($raw);
    }

    /**
     * Parse défensif : tente de décoder le JSON Ollama en gérant les cas courants :
     * - fences ```json ... ```
     * - espaces parasites
     *
     * @throws OcrException  Si le JSON est invalide ou vide après nettoyage.
     */
    public function parseJsonDefensively(string $raw): array
    {
        $clean = trim($raw);

        // Supprime les fences ```json ... ``` éventuelles
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = trim($m[1]);
        }

        if ($clean === '') {
            throw new OcrException('OllamaClient : réponse vide.');
        }

        $decoded = json_decode($clean, true);

        if (! is_array($decoded)) {
            // Ne pas inclure le contenu brut dans le message : il peut contenir du texte d'ordonnance.
            throw new OcrException('OllamaClient : JSON invalide — ' . json_last_error_msg());
        }

        return $decoded;
    }
}
