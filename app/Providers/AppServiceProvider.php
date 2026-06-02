<?php

namespace App\Providers;

use App\Services\Ocr\LocalOcrProvider;
use App\Services\Ocr\MistralOcrClient;
use App\Services\Ocr\MistralOcrProvider;
use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\OllamaClient;
use App\Services\Ocr\PaddleVlClient;
use App\Services\Ocr\PrescriptionDraftMapper;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Binding du driver OCR selon OCR_DRIVER dans .env.
     *
     * 'mistral' → MistralOcrProvider (mistral-ocr-latest, 1 appel /v1/ocr, cloud)
     * 'local'   → LocalOcrProvider  (PaddleOCR + Ollama, auto-hébergé)
     *
     * Changer de driver = modifier une seule ligne dans .env, sans toucher au code.
     * Pré-requis prod pour 'mistral' : ZDR activé sur console.mistral.ai.
     */
    public function register(): void
    {
        $this->app->singleton(OcrProvider::class, function () {
            $driver = config('pilo.ocr_driver', 'local');
            $mapper = new PrescriptionDraftMapper();

            if ($driver === 'mistral') {
                $apiKey = config('pilo.mistral_api_key');
                if (empty($apiKey)) {
                    throw new \RuntimeException(
                        'OCR_DRIVER=mistral mais MISTRAL_API_KEY est vide. Ajoutez-la dans .env.'
                    );
                }
                return new MistralOcrProvider(
                    new MistralOcrClient($apiKey),
                    $mapper,
                );
            }

            // Driver local (défaut) — pipeline PaddleOCR + Ollama inchangé
            return new LocalOcrProvider(
                new PaddleVlClient(config('pilo.paddleocr_url')),
                new OllamaClient(config('pilo.ollama_url'), config('pilo.ollama_model')),
                $mapper,
            );
        });
    }

    public function boot(): void
    {
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);
    }
}
