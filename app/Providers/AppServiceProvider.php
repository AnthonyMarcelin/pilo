<?php

namespace App\Providers;

use App\Services\Ocr\MistralOcrClient;
use App\Services\Ocr\MistralOcrProvider;
use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\PrescriptionDraftMapper;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Driver OCR : mistral-ocr-latest via POST /v1/ocr (document_annotation_format).
     * Requiert MISTRAL_API_KEY dans .env.
     * Pré-requis prod : ZDR activé sur console.mistral.ai.
     */
    public function register(): void
    {
        $this->app->singleton(OcrProvider::class, function () {
            $apiKey = config('pilo.mistral_api_key');
            if (empty($apiKey)) {
                throw new \RuntimeException(
                    'MISTRAL_API_KEY est vide. Ajoutez-la dans .env.'
                );
            }
            return new MistralOcrProvider(
                new MistralOcrClient($apiKey),
                new PrescriptionDraftMapper(),
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
