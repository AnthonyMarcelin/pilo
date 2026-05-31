<?php

namespace App\Services\Ocr;

use App\DTOs\PrescriptionDraft;

/**
 * Contrat unique pour l'extraction d'ordonnance depuis une image.
 *
 * Seul driver autorisé : LocalOcrProvider (100 % local, aucun envoi réseau).
 * L'interface reste enfichable pour d'autres auto-hébergeurs — mais aucun driver
 * cloud ne sera inclus dans Pilo (CLAUDE.md §2.6).
 *
 * Retourne toujours un PrescriptionDraft. En cas d'erreur ou de faible confiance,
 * le draft est vide (items vide). Ne lève jamais d'exception côté appelant — le
 * job ProcessPrescriptionScan gère les échecs.
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
