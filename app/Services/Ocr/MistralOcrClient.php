<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP vers l'API Mistral OCR (POST /v1/ocr).
 *
 * Rôle : image ou PDF → texte markdown brut.
 * La structuration JSON est ensuite faite par MistralChatClient.
 *
 * Formats supportés :
 *   - Images (JPEG, PNG, WebP, GIF) → ImageURLChunk avec data URI base64
 *   - PDF → DocumentURLChunk avec data URI base64
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
    private const TIMEOUT  = 60; // L'API Mistral répond en < 5 s pour une ordonnance

    public function __construct(
        private readonly string $apiKey,
    ) {}

    /**
     * Envoie le fichier à l'API Mistral OCR et retourne le markdown concaténé
     * de toutes les pages (multi-pages pour les PDF).
     *
     * @param  string  $absolutePath  Chemin absolu du fichier (JPEG, PNG, WebP, PDF).
     * @return string  Texte markdown extrait.
     *
     * @throws MistralCreditException  Si les crédits sont épuisés (HTTP 402/422).
     * @throws OcrException            Pour toute autre erreur API.
     */
    public function extractText(string $absolutePath): string
    {
        $mime    = mime_content_type($absolutePath) ?: 'image/jpeg';
        $isPdf   = ($mime === 'application/pdf');
        $b64     = base64_encode(file_get_contents($absolutePath));
        $dataUri = "data:{$mime};base64,{$b64}";

        // ImageURLChunk pour les images, DocumentURLChunk pour les PDF
        $document = $isPdf
            ? ['type' => 'document_url', 'document_url' => $dataUri]
            : ['type' => 'image_url',    'image_url'    => $dataUri];

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withToken($this->apiKey)
                ->post(self::ENDPOINT, [
                    'model'    => self::MODEL,
                    'document' => $document,
                ]);
        } catch (\Throwable $e) {
            throw new OcrException("MistralOcrClient : service inaccessible — {$e->getMessage()}", 0, $e);
        }

        $this->handleHttpError($response);

        // Concaténer le markdown de toutes les pages (multi-pages PDF)
        $pages    = $response->json('pages', []);
        $markdown = implode("\n\n", array_column($pages, 'markdown'));

        if (trim($markdown) === '') {
            throw new OcrException('MistralOcrClient : réponse OCR vide (image illisible ou format non supporté).');
        }

        Log::info('[MistralOcrClient] OCR terminé', [
            'pages'    => count($pages),
            'chars'    => mb_strlen($markdown),
            'model'    => $response->json('model'),
        ]);

        return $markdown;
    }

    /**
     * Lève l'exception appropriée selon le code HTTP retourné par Mistral.
     */
    private function handleHttpError(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body   = $response->json('message') ?? $response->body();

        // 402 Payment Required OU 422 avec mention quota/billing → crédits épuisés
        if ($status === 402 || ($status === 422 && str_contains(strtolower((string) $body), 'quota'))) {
            throw new MistralCreditException(
                'Crédits Mistral insuffisants. Rechargez votre compte sur console.mistral.ai.'
            );
        }

        // 429 Too Many Requests → rate limit temporaire
        if ($status === 429) {
            throw new OcrException(
                'Limite de requêtes Mistral atteinte. Réessayez dans quelques secondes.'
            );
        }

        // 401 Unauthorized → clé invalide
        if ($status === 401) {
            throw new OcrException(
                'Clé API Mistral invalide ou expirée. Vérifiez MISTRAL_API_KEY dans .env.'
            );
        }

        throw new OcrException("MistralOcrClient : erreur HTTP {$status} — {$body}");
    }
}
