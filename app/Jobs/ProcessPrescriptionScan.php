<?php

namespace App\Jobs;

use App\Models\PrescriptionScan;
use App\Services\Ocr\LocalOcrProvider;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OllamaClient;
use App\Services\Ocr\PaddleVlClient;
use App\Services\Ocr\PrescriptionDraftMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Traitement asynchrone d'un scan d'ordonnance.
 *
 * Pipeline :
 *   1. Extraction OCR + normalisation JSON (LocalOcrProvider)
 *   2. Stockage du brouillon dans prescription_scans
 *
 * Les services IA (llama-server, paddleocr-vl, ollama) tournent en permanence.
 *
 * En cas d'échec :
 *   → scan.status = 'failed', scan.error_message rempli
 *   → l'UI bascule vers le formulaire vide avec message explicite
 *   → jamais d'enregistrement partiel
 */
class ProcessPrescriptionScan implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 900; // 15 min max : VL CPU ~5 min + Ollama ~5 min + marge
    public int $tries   = 1;   // pas de retry — relancer une inférence lente bloquerait le worker

    public function __construct(
        private readonly string $scanId,
    ) {}

    public function handle(): void
    {
        $scan = PrescriptionScan::find($this->scanId);

        if (! $scan) {
            Log::error("ProcessPrescriptionScan : scan {$this->scanId} introuvable.");
            return;
        }

        $scan->update(['status' => 'processing']);

        try {
            // ── 1. Résolution du chemin image ─────────────────────────────────
            $imagePath = $scan->source_image_path
                ? Storage::disk('local')->path($scan->source_image_path)
                : null;

            if (! $imagePath || ! file_exists($imagePath)) {
                throw new OcrException("Image introuvable : {$scan->source_image_path}");
            }

            // ── 2. Pipeline OCR ───────────────────────────────────────────────
            $provider = new LocalOcrProvider(
                new PaddleVlClient(config('pilo.paddleocr_url')),
                new OllamaClient(config('pilo.ollama_url'), config('pilo.ollama_model')),
                new PrescriptionDraftMapper(),
            );

            $draft = $provider->extract($imagePath);

            // ── 3. Succès : stockage du brouillon ─────────────────────────────
            $scan->update([
                'status' => 'done',
                'draft'  => $draft->jsonSerialize(),
            ]);

        } catch (OcrException $e) {
            Log::warning("ProcessPrescriptionScan {$this->scanId} : {$e->getMessage()}");

            $scan->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

        } catch (\Throwable $e) {
            Log::error("ProcessPrescriptionScan {$this->scanId} : exception inattendue — {$e->getMessage()}");

            $scan->update([
                'status'        => 'failed',
                'error_message' => 'Erreur inattendue lors de la lecture. Saisie manuelle recommandée.',
            ]);
        }
    }
}
