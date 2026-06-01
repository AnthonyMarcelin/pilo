<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;

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
        private readonly string $model           = 'qwen2.5:3b-instruct',
        private readonly int    $timeoutSeconds  = 300, // 5 min — Qwen 3B peut être lent sous charge
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

RÈGLE 1 — COMPLÉTUDE (erreur médicale si non respectée) :
Extrais ABSOLUMENT TOUS les médicaments présents dans les blocs, sans exception.
Lis le texte de haut en bas. Pour chaque médicament listé, crée un item JSON.
Si l'ordonnance contient N médicaments, ton JSON doit contenir N items. Ne saute aucune ligne.

RÈGLE 2 — ANTI-INVENTION (règle de sécurité absolue) :
Tout champ absent ou illisible dans le texte = null.
N'invente jamais un médicament, un dosage, une posologie ou une date.
En cas de doute, null vaut mieux qu'une valeur inventée.

RÈGLE 3 — medication_name :
Nom du médicament seul, SANS la concentration/dosage.
Exemples corrects : "Paroxétine", "Lévothyroxine", "Metformine 850" (si le chiffre fait partie du nom commercial).
Exemples INCORRECTS : "Paroxétine 20 mg" (le "20 mg" doit aller dans dosage).

RÈGLE 4 — dosage :
La concentration du médicament telle qu'écrite sur l'ordonnance (quantité + unité).
Ce n'est PAS le nombre de comprimés à prendre — c'est la teneur du médicament.
Formats courants : "500 mg", "150 microgrammes", "7,5 mg", "5 mg/5 mL", "10 000 UI".
Si la concentration figure dans le texte : extraire dans dosage.
Si absente : null.

RÈGLE 5 — intake_type :
- "fixe"     : prise à horaire régulier (matin / midi / soir / coucher).
- "si_besoin": prise conditionnelle (si douleur, si fièvre, au besoin, PRN).
- "autre"    : rythme irrégulier non planifiable (ex : 1 jour sur 2, hebdomadaire).

RÈGLE 6 — morning / noon / evening / bedtime :
Nombre d'unités par moment de prise (1, 0.5, 2…).
null si intake_type ≠ "fixe" OU si phases[] est non vide.

RÈGLE 7 — phases[] (PALIERS DÉGRESSIFS — règle critique) :
Utilise phases[] quand la posologie varie dans le temps avec des durées explicites.
Signaux dans le texte : "puis", "ensuite", "progressivement", durées successives.
Exemples de formulations dégressives :
  - "2 cp/j pendant 7j PUIS 1 cp/j pendant 15j PUIS arrêt"
  - "3 cp matin pendant 5 jours, puis 2 cp pendant 5 jours, puis 1 cp jusqu'à arrêt"
  - "Semaine 1 : 1 cp/j — Semaine 2 : 1 cp tous les 2 jours"
→ Crée un objet phase par palier : {"duration_days": N, "morning": X, "noon": Y, "evening": Z, "bedtime": W}.
→ Si phases[] est non vide : morning/noon/evening/bedtime de l'item parent DOIVENT être null.
→ Si dose simple ET paliers pour le même médicament → les paliers priment, dose simple ignorée.

RÈGLE 8 — posologie_brute :
Copier VERBATIM le texte de posologie tel que lu. Toujours rempli (jamais null).

RÈGLE 9 — duration_days / qsp_days :
duration_days = durée totale en jours (entier). qsp_days = "quantité suffisante pour X jours".

RÈGLE 10 — Dates :
Format YYYY-MM-DD. null si absente ou illisible.

═══════════ EXEMPLES ═══════════

EXEMPLE 1 — Médicament simple :
Blocs : "[1|text] Dr. Bernard / 15/03/2026 [2|text] Amoxicilline 500 mg / 1 gélule matin midi soir / 7 jours"
→ {"prescriber_name":"Dr. Bernard","prescribed_at":"2026-03-15","items":[{"medication_name":"Amoxicilline","dosage":"500 mg","intake_type":"fixe","morning":1,"noon":1,"evening":1,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":7,"qsp_days":null,"posologie_brute":"1 gélule matin midi soir 7 jours","phases":[]}]}

EXEMPLE 2 — Palier dégressif :
Blocs : "[1|text] Prednisolone 20 mg / 2 cp matin pendant 5j PUIS 1 cp matin pendant 5j PUIS arrêt"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Prednisolone","dosage":"20 mg","intake_type":"fixe","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":10,"qsp_days":null,"posologie_brute":"2 cp matin pendant 5j PUIS 1 cp matin pendant 5j PUIS arrêt","phases":[{"duration_days":5,"morning":2,"noon":null,"evening":null,"bedtime":null},{"duration_days":5,"morning":1,"noon":null,"evening":null,"bedtime":null}]}]}

EXEMPLE 3 — Si besoin + plusieurs médicaments :
Blocs : "[1|text] Paracétamol 1000 mg / si douleur max 4 cp/j [2|text] Ibuprofène 400 mg / 1 cp matin soir 5j"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Paracétamol","dosage":"1000 mg","intake_type":"si_besoin","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":"si douleur","max_per_day":4,"duration_days":null,"qsp_days":null,"posologie_brute":"si douleur max 4 cp/j","phases":[]},{"medication_name":"Ibuprofène","dosage":"400 mg","intake_type":"fixe","morning":1,"noon":null,"evening":1,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":5,"qsp_days":null,"posologie_brute":"1 cp matin soir 5j","phases":[]}]}

═══════════ BLOCS OCR (extraire TOUS les médicaments) ═══════════

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
                    'model'  => $this->model,
                    'prompt' => $prompt,
                    'format' => self::prescriptionSchema(),
                    'stream' => false,
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
