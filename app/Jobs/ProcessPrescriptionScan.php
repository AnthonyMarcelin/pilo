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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Traitement asynchrone d'un scan d'ordonnance.
 *
 * Pipeline :
 *   1. Réveil des conteneurs IA (pilo:ai-up)
 *   2. Extraction OCR + normalisation JSON (LocalOcrProvider)
 *   3. Stockage du brouillon dans prescription_scans
 *   4. Arrêt différé des conteneurs IA (pilo:ai-down, après idle)
 *
 * En cas d'échec à n'importe quelle étape :
 *   → scan.status = 'failed', scan.error_message rempli
 *   → l'UI bascule vers le formulaire vide avec message explicite
 *   → jamais d'enregistrement partiel (CLAUDE.md §2.7)
 */
class ProcessPrescriptionScan implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 600; // 10 min max (chargement modèle inclus)
    public int $tries   = 1;   // pas de retry automatique (l'IA peut être en cours d'arrêt)

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
            // ── 1. Réveil de l'IA ─────────────────────────────────────────────
            $exitCode = Artisan::call('pilo:ai-up');

            if ($exitCode !== 0) {
                throw new OcrException('pilo:ai-up a échoué — les services IA ne sont pas disponibles.');
            }

            // ── 2. Résolution du chemin image ─────────────────────────────────
            $imagePath = $scan->source_image_path
                ? Storage::disk('local')->path($scan->source_image_path)
                : null;

            if (! $imagePath || ! file_exists($imagePath)) {
                throw new OcrException("Image introuvable : {$scan->source_image_path}");
            }

            // ── 3. Pipeline OCR ───────────────────────────────────────────────
            $provider = new LocalOcrProvider(
                new PaddleVlClient(config('pilo.paddleocr_url')),
                new OllamaClient(config('pilo.ollama_url'), config('pilo.ollama_model')),
                new PrescriptionDraftMapper(),
            );

            $draft = $provider->extract($imagePath);

            // ── 4. Succès : stockage du brouillon ─────────────────────────────
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
        } finally {
            // ── 5. Arrêt différé de l'IA ──────────────────────────────────────
            // Dispatche un job différé qui éteindra l'IA après le délai d'inactivité.
            // Si un autre scan démarre entre-temps, le job suivant annulera l'arrêt.
            ShutdownAiIfIdle::dispatch()->delay(
                now()->addSeconds(config('pilo.ai_idle_seconds', 300)),
            );
        }
    }
}
