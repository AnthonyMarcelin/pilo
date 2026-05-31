<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;

/**
 * Client HTTP vers le service paddleocr-vl (FastAPI).
 *
 * Contrat : POST /ocr (multipart image) → liste de blocs ordonnés.
 * Voir SPEC §8 pour le schéma de réponse complet.
 */
class PaddleVlClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int    $timeoutSeconds = 120,
    ) {}

    /**
     * Envoie l'image et retourne les blocs OCR ordonnés.
     *
     * @param  string  $imagePath  Chemin absolu vers l'image.
     * @return array   Tableau de blocs VL (block_id, block_order, block_label, block_content, block_bbox).
     *
     * @throws OcrException  Si le service est indisponible ou renvoie une erreur.
     */
    public function parse(string $imagePath): array
    {
        if (! file_exists($imagePath)) {
            throw new OcrException("Fichier image introuvable : {$imagePath}");
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->attach('image', fopen($imagePath, 'r'), basename($imagePath))
                ->post("{$this->baseUrl}/ocr");
        } catch (\Throwable $e) {
            throw new OcrException("PaddleVlClient : service indisponible — {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new OcrException(
                "PaddleVlClient : réponse HTTP {$response->status()} depuis {$this->baseUrl}/ocr",
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['blocks'])) {
            throw new OcrException('PaddleVlClient : réponse inattendue (champ blocks absent).');
        }

        return $data['blocks'];
    }
}
