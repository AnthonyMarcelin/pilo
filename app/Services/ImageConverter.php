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
     * Stocke le fichier sur le disque 'local', avec conversion HEIC→JPEG si besoin.
     *
     * - HEIC/HEIF → converti en JPEG via Imagick (libheif requis), EXIF strippé.
     * - PDF       → stocké tel quel (.pdf). La rasterisation PDF→image est déléguée
     *               au service paddleocr-vl (pypdfium2) pour ne pas charger Ghostscript
     *               côté PHP et garder la conversion dans la même couche que l'OCR.
     * - Autres images (JPG, PNG, WebP…) → stockées telles quelles.
     *
     * @throws \ImagickException  Si la conversion HEIC échoue (image corrompue, etc.)
     */
    public function storeAsJpeg(UploadedFile $file, string $directory): string
    {
        if ($this->isHeic($file)) {
            return $this->convertHeicToJpeg($file, $directory);
        }

        // PDF et autres formats : stockage direct (extension préservée).
        return $file->store($directory, 'local');
    }

    /**
     * Détecte un fichier PDF par MIME type ou extension.
     */
    public function isPdf(UploadedFile $file): bool
    {
        $mime = strtolower($file->getMimeType() ?? '');
        $ext  = strtolower($file->getClientOriginalExtension());

        return $mime === 'application/pdf' || $ext === 'pdf';
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
