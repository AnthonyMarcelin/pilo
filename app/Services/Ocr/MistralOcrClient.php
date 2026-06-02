<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP vers l'API Mistral OCR (POST /v1/ocr) avec sortie structurée.
 *
 * Pipeline en 1 seul appel :
 *   image/PDF → POST /v1/ocr avec document_annotation_format (json_schema)
 *              → response["document_annotation"] = JSON de prescription
 *
 * Le modèle mistral-ocr-latest est spécialisé OCR : lecture de caractères
 * manuscrits et tamponnés nettement supérieure à un modèle vision généraliste.
 * Validé sur ordonnances réelles : 1.79 s, 12 médicaments lus sans erreur.
 *
 * Formats supportés :
 *   - Images (JPEG, PNG, WebP, GIF) → image_url avec data URI base64
 *   - PDF                           → document_url avec data URI base64
 *   - HEIC : déjà converti en JPEG par ImageConverter avant d'arriver ici.
 *
 * Codes d'erreur API :
 *   401 → clé API invalide          → OcrException
 *   402 → crédits épuisés           → MistralCreditException
 *   422 → quota billing dépassé     → MistralCreditException
 *   429 → rate limit                → OcrException (message dédié)
 *   5xx → erreur serveur Mistral    → OcrException
 */
class MistralOcrClient
{
    private const ENDPOINT = 'https://api.mistral.ai/v1/ocr';
    private const MODEL    = 'mistral-ocr-latest';
    private const TIMEOUT  = 60;

    public function __construct(
        private readonly string $apiKey,
    ) {}

    /**
     * Extrait les données structurées d'une ordonnance en un seul appel OCR.
     *
     * @param  string  $absolutePath  Chemin absolu du fichier (JPEG, PNG, WebP, PDF).
     * @return array   JSON décodé conforme au schéma de prescription.
     *
     * @throws MistralCreditException  Si les crédits sont épuisés (HTTP 402/422).
     * @throws OcrException            Pour toute autre erreur API.
     */
    public function extract(string $absolutePath): array
    {
        $mime    = mime_content_type($absolutePath) ?: 'image/jpeg';
        $isPdf   = ($mime === 'application/pdf');
        $b64     = base64_encode(file_get_contents($absolutePath));
        $dataUri = "data:{$mime};base64,{$b64}";

        $document = $isPdf
            ? ['type' => 'document_url', 'document_url' => $dataUri]
            : ['type' => 'image_url',    'image_url'    => $dataUri];

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withToken($this->apiKey)
                ->post(self::ENDPOINT, [
                    'model'                     => self::MODEL,
                    'document'                  => $document,
                    'document_annotation_format' => [
                        'type'        => 'json_schema',
                        'json_schema' => [
                            'name'   => 'prescription',
                            'strict' => true,
                            'schema' => $this->prescriptionSchema(),
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            throw new OcrException("MistralOcrClient : service inaccessible — {$e->getMessage()}", 0, $e);
        }

        $this->handleHttpError($response);

        $raw = $response->json('document_annotation');

        // L'API peut retourner document_annotation soit comme objet parsé, soit comme
        // string JSON (comportement observé en prod) — les deux cas sont supportés.
        $annotation = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($annotation)) {
            throw new OcrException('MistralOcrClient : document_annotation absent ou invalide dans la réponse.');
        }

        // Log sans PHI : comptage uniquement, jamais de contenu médical
        Log::info('[MistralOcrClient] Extraction terminée', [
            'model'      => $response->json('model', self::MODEL),
            'item_count' => count($annotation['items'] ?? []),
            'pages'      => count($response->json('pages', [])),
        ]);

        return $annotation;
    }

    private function prescriptionSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['items'],
            'additionalProperties' => false,
            'properties'           => [
                'prescriber_name' => [
                    'type'        => ['string', 'null'],
                    'description' => 'Nom du médecin tel qu\'écrit. null si absent.',
                ],
                'prescribed_at' => [
                    'type'        => ['string', 'null'],
                    'description' => 'Date de prescription au format YYYY-MM-DD. null si absente.',
                ],
                'items' => [
                    'type'        => 'array',
                    'description' => 'TOUS les médicaments présents. Jamais de label de formulaire (N° FINESS, Prescripteur, etc.).',
                    'items'       => [
                        'type'                 => 'object',
                        'required'             => ['medication_name', 'intake_type', 'posologie_brute'],
                        'additionalProperties' => false,
                        'properties'           => [
                            'medication_name' => [
                                'type'        => 'string',
                                'description' => 'Nom du médicament SANS concentration. Ex: "Amlodipine" et non "Amlodipine 5 mg".',
                            ],
                            'dosage' => [
                                'type'        => ['string', 'null'],
                                'description' => 'Concentration du médicament (ex: "5 mg", "500 mg", "10 000 UI"). null si absente.',
                            ],
                            'intake_type' => [
                                'type'        => 'string',
                                'enum'        => ['fixe', 'si_besoin', 'autre'],
                                'description' => '"fixe" = horaire régulier, "si_besoin" = conditionnel, "autre" = irrégulier.',
                            ],
                            'morning'   => ['type' => ['number', 'null'], 'description' => 'Unités le matin. null si intake_type≠fixe ou phases[] non vide.'],
                            'noon'      => ['type' => ['number', 'null'], 'description' => 'Unités le midi. null si intake_type≠fixe ou phases[] non vide.'],
                            'evening'   => ['type' => ['number', 'null'], 'description' => 'Unités le soir. null si intake_type≠fixe ou phases[] non vide.'],
                            'bedtime'   => ['type' => ['number', 'null'], 'description' => 'Unités au coucher. null si intake_type≠fixe ou phases[] non vide.'],
                            'condition' => ['type' => ['string', 'null'], 'description' => 'Condition si si_besoin (ex: "si douleur"). null sinon.'],
                            'max_per_day'    => ['type' => ['number', 'null'],  'description' => 'Maximum de prises par jour pour si_besoin. null sinon.'],
                            'duration_days'  => ['type' => ['integer', 'null'], 'description' => 'Durée totale en jours. Pour paliers dégressifs : somme de tous les paliers.'],
                            'qsp_days'       => ['type' => ['integer', 'null'], 'description' => 'Durée QSP en jours. null si absent.'],
                            'posologie_brute' => [
                                'type'        => 'string',
                                'description' => 'Posologie copiée VERBATIM depuis l\'ordonnance. Toujours rempli.',
                            ],
                            'phases' => [
                                'type'        => 'array',
                                'description' => 'Paliers dégressifs : UN item par médicament avec phases[] rempli. INTERDIT de dupliquer le même médicament. morning/noon/evening/bedtime du parent = null si phases[] non vide.',
                                'items'       => [
                                    'type'                 => 'object',
                                    'required'             => ['duration_days'],
                                    'additionalProperties' => false,
                                    'properties'           => [
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

    private function handleHttpError(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body   = $response->json('message') ?? $response->body();

        if ($status === 402 || ($status === 422 && str_contains(strtolower((string) $body), 'quota'))) {
            throw new MistralCreditException(
                'Crédits Mistral insuffisants. Rechargez votre compte sur console.mistral.ai.'
            );
        }

        if ($status === 429) {
            throw new OcrException('Limite de requêtes Mistral atteinte. Réessayez dans quelques secondes.');
        }

        if ($status === 401) {
            throw new OcrException('Clé API Mistral invalide. Vérifiez MISTRAL_API_KEY dans .env.');
        }

        throw new OcrException("MistralOcrClient : erreur HTTP {$status}.");
    }
}
