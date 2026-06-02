<?php

namespace App\Services\Ocr;

use App\DTOs\PrescriptionDraft;

/**
 * Contrat unique pour l'extraction d'ordonnance depuis une image.
 *
 * Drivers disponibles (OCR_DRIVER dans .env) :
 *   'local'   → LocalOcrProvider   : PaddleOCR + Ollama, 100 % auto-hébergé
 *   'mistral' → MistralOcrProvider : pixtral-12b vision, 1 appel API cloud
 *
 * Retourne toujours un PrescriptionDraft. En cas d'erreur,
 * le job ProcessPrescriptionScan gère l'échec et stocke status='failed'.
 */
interface OcrProvider
{
    /**
     * Extrait les données de l'ordonnance depuis l'image stockée localement.
     *
     * @param  string  $imagePath  Chemin absolu vers l'image (stockée sur le volume Docker).
     * @return PrescriptionDraft  Brouillon structuré, jamais null.
     *
     * @throws \App\Services\Ocr\OcrException  Si le pipeline échoue et qu'aucun fallback n'est possible.
     */
    public function extract(string $imagePath): PrescriptionDraft;
}
