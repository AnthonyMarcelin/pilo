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
        private readonly int    $timeoutSeconds  = 180,
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
Tu es un assistant pharmaceutique qui normalise des données d'ordonnances médicales.
Tu reçois des blocs de texte extraits par OCR (ordonnés par position de lecture).
Ta tâche : extraire les informations et les retourner au format JSON conforme au schéma.

RÈGLES STRICTES :
- Ne jamais inventer : utilise null si une information est absente ou incertaine.
- posologie_brute : recopier EXACTEMENT la posologie lue pour chaque médicament.
- intake_type : "fixe" = prise horaire régulière, "si_besoin" = sur indication/PRN, "autre" = irrégulier.
- Si phases est non vide pour un item, NE PAS remplir morning/noon/evening/bedtime de l'item.
- Si posologie détaillée (paliers) suit une posologie générale pour un même médicament, les paliers priment.
- Format de date : YYYY-MM-DD ou null. Quantités : décimaux (ex: 0.5, 1, 2.5).

BLOCS OCR (ordonnés, lus sur l'ordonnance) :
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
            throw new OcrException(
                'OllamaClient : JSON invalide — ' . json_last_error_msg() . ' — raw: ' . substr($clean, 0, 200),
            );
        }

        return $decoded;
    }
}
