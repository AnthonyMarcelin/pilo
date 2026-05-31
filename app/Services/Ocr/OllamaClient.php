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
        private readonly string $model           = 'qwen2.5:1.5b-instruct',
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
Tu es un assistant pharmaceutique. Tu normalises les blocs OCR d'une ordonnance vers un JSON structuré.

RÈGLES :
1. NE JAMAIS INVENTER : utilise null si absent ou incertain.
2. posologie_brute : COPIER VERBATIM le texte OCR de la posologie, sans résumer.
3. intake_type : "fixe" si horaire régulier (matin/midi/soir/coucher), "si_besoin" si sur indication/PRN, "autre" sinon.
4. morning/noon/evening/bedtime : nombre de prises (ex: 1, 2, 0.5). null si non spécifié ou si phases non vide.
5. phases : remplir UNIQUEMENT si la posologie est dégressive (plusieurs paliers avec durées distinctes).
   - Si phases non vide → morning/noon/evening/bedtime de l'item DOIVENT être null (les phases priment).
   - Si dose simple ET schéma par paliers pour le MÊME médicament → les paliers priment, ignorer la dose simple.
6. duration_days : durée totale en jours (nombre entier). qsp_days si "QSP X jours/mois".
7. Date format : YYYY-MM-DD.

EXEMPLE :
Blocs : "Amoxicilline 500 mg / 1 gélule matin midi soir pendant 7 jours / Dr. Bernard / 15/03/2026"
→ {"prescriber_name":"Dr. Bernard","prescribed_at":"2026-03-15","items":[{"medication_name":"Amoxicilline","dosage":"500 mg","intake_type":"fixe","morning":1,"noon":1,"evening":1,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":7,"qsp_days":null,"posologie_brute":"1 gélule matin midi soir pendant 7 jours","phases":[]}]}

EXEMPLE DÉGRESSIF :
Blocs : "Prednisolone 20 mg / 2 cp matin 5j puis 1 cp matin 5j puis arrêt"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Prednisolone","dosage":"20 mg","intake_type":"fixe","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":null,"qsp_days":null,"posologie_brute":"2 cp matin 5j puis 1 cp matin 5j puis arrêt","phases":[{"duration_days":5,"morning":2,"noon":null,"evening":null,"bedtime":null},{"duration_days":5,"morning":1,"noon":null,"evening":null,"bedtime":null}]}]}

EXEMPLE SI BESOIN :
Blocs : "Paracétamol 1000 mg / si douleur, max 4 comprimés/jour"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Paracétamol","dosage":"1000 mg","intake_type":"si_besoin","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":"si douleur","max_per_day":4,"duration_days":null,"qsp_days":null,"posologie_brute":"si douleur, max 4 comprimés/jour","phases":[]}]}

BLOCS OCR DE L'ORDONNANCE (ordonnés par position de lecture) :
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
