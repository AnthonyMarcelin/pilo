<?php

namespace App\Services\Ocr;

use App\DTOs\PrescriptionDraft;

/**
 * Provider OCR local : chaîne PaddleVlClient → OllamaClient → PrescriptionDraftMapper.
 *
 * 100 % local — aucun appel réseau vers l'extérieur (CLAUDE.md §2.6).
 * En cas d'échec à n'importe quelle étape, lève une OcrException que le job
 * ProcessPrescriptionScan intercepte pour basculer sur le formulaire vide.
 */
class LocalOcrProvider implements OcrProvider
{
    public function __construct(
        private readonly PaddleVlClient       $paddle,
        private readonly OllamaClient         $ollama,
        private readonly PrescriptionDraftMapper $mapper,
    ) {}

    public function extract(string $imagePath): PrescriptionDraft
    {
        // Étape 1 : PaddleOCR-VL → blocs ordonnés avec relations spatiales
        $blocks = $this->paddle->parse($imagePath);

        if (empty($blocks)) {
            throw new OcrException('PaddleVL : aucun bloc extrait (image vide ou illisible).');
        }

        // Étape 2 : Ollama → normalisation JSON contrainte par schéma
        $json = $this->ollama->normalize($blocks);

        // Étape 3 : Mapping → PrescriptionDraft
        return $this->mapper->map($json);
    }
}
