<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Normalise les images uploadées en JPEG avant stockage.
 *
 * Raison d'être : les iPhones capturent en HEIC par défaut. Ce service
 * accepte HEIC/HEIF et le convertit en JPEG côté serveur, de sorte que
 * le reste de la chaîne (stockage, PaddleOCR-VL, affichage) ne voie
 * jamais que du JPEG — sans que l'utilisatrice ait à changer ses réglages.
 *
 * Non-statique pour être injectable et mockable dans les tests.
 */
class ImageConverter
{
    /**
     * Stocke le fichier image en JPEG sur le disque 'local'.
     *
     * - HEIC/HEIF → converti en JPEG via Imagick (libheif requis), EXIF strippé.
     * - Autres formats (JPG, PNG, WebP) → stockés tels quels.
     *
     * @throws \ImagickException  Si la conversion HEIC échoue (image corrompue, etc.)
     */
    public function storeAsJpeg(UploadedFile $file, string $directory): string
    {
        if ($this->isHeic($file)) {
            return $this->convertHeicToJpeg($file, $directory);
        }

        return $file->store($directory, 'local');
    }

    /**
     * Détecte un fichier HEIC/HEIF par MIME type OU extension.
     * Double vérification car certains navigateurs envoient application/octet-stream
     * pour les HEIC tout en conservant l'extension .heic.
     */
    public function isHeic(UploadedFile $file): bool
    {
        $mime = strtolower($file->getMimeType() ?? '');
        $ext  = strtolower($file->getClientOriginalExtension());

        return in_array($mime, [
            'image/heic',
            'image/heif',
            'image/heic-sequence',
            'image/heif-sequence',
        ], true)
            || in_array($ext, ['heic', 'heif'], true);
    }

    private function convertHeicToJpeg(UploadedFile $file, string $directory): string
    {
        $imagick = new \Imagick($file->getRealPath());
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(90);
        // Strip EXIF : supprime coordonnées GPS, modèle d'appareil, etc.
        // Important pour une app qui stocke des images de santé.
        $imagick->stripImage();

        $filename     = Str::uuid() . '.jpg';
        $relativePath = $directory . '/' . $filename;

        Storage::disk('local')->put($relativePath, $imagick->getImageBlob());
        $imagick->destroy();

        return $relativePath;
    }
}
