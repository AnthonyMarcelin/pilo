<?php

namespace App\Services\Ocr;

use App\DTOs\PrescriptionDraft;

/**
 * Contrat pour l'extraction d'ordonnance depuis une image.
 *
 * Driver : MistralOcrProvider — mistral-ocr-latest, 1 appel POST /v1/ocr.
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
