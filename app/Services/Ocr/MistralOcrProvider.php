<?php

namespace App\Services\Ocr;

use App\DTOs\PrescriptionDraft;

/**
 * Driver OCR Mistral — implémente OcrProvider.
 *
 * Pipeline :
 *   1. MistralOcrClient  → image/PDF → markdown (POST /v1/ocr)
 *   2. MistralChatClient → markdown → JSON structuré (POST /v1/chat/completions)
 *   3. PrescriptionDraftMapper → JSON → PrescriptionDraft
 *
 * Avantages vs pipeline local :
 *   - Zéro RAM GPU/CPU pour les modèles IA (tout en cloud)
 *   - OCR de meilleure qualité (lecteur de 12+ médicaments vs ~7 en local)
 *   - Temps de scan < 10 s vs 5-8 min en local
 *
 * Voir config('pilo.ocr_driver') = 'mistral' pour activer ce driver.
 * Requiert MISTRAL_API_KEY dans .env.
 */
class MistralOcrProvider implements OcrProvider
{
    public function __construct(
        private readonly MistralOcrClient        $ocrClient,
        private readonly MistralChatClient       $chatClient,
        private readonly PrescriptionDraftMapper  $mapper,
    ) {}

    public function extract(string $imagePath): PrescriptionDraft
    {
        // Étape 1 — OCR : image/PDF → texte markdown
        $markdown = $this->ocrClient->extractText($imagePath);

        // Étape 2 — Structuration : markdown → JSON conforme au schéma de prescription
        $json = $this->chatClient->normalize($markdown);

        // Étape 3 — Mapping : JSON → PrescriptionDraft (identique au pipeline local)
        return $this->mapper->map($json);
    }
}
