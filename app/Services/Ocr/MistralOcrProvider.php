<?php

namespace App\Services\Ocr;

use App\DTOs\PrescriptionDraft;

/**
 * Driver OCR Mistral — implémente OcrProvider.
 *
 * Pipeline en 1 appel : image/PDF → JSON structuré → PrescriptionDraft.
 * MistralOcrClient envoie le document à mistral-ocr-latest via /v1/ocr avec
 * document_annotation_format (json_schema) et reçoit directement le JSON
 * de prescription dans response["document_annotation"].
 *
 * Avantages vs pipeline local :
 *   - Zéro RAM GPU/CPU pour les modèles IA (tout en cloud)
 *   - Lecture supérieure sur ordonnances manuscrites et tamponnées
 *   - Temps de scan ~2 s vs 5-8 min en local
 *
 * Pré-requis prod : ZDR (zéro rétention) activé sur le compte Mistral
 * avant tout usage avec de vraies ordonnances. Voir config('pilo.ocr_driver').
 */
class MistralOcrProvider implements OcrProvider
{
    public function __construct(
        private readonly MistralOcrClient        $ocrClient,
        private readonly PrescriptionDraftMapper  $mapper,
    ) {}

    public function extract(string $imagePath): PrescriptionDraft
    {
        $json = $this->ocrClient->extract($imagePath);

        return $this->mapper->map($json);
    }
}
