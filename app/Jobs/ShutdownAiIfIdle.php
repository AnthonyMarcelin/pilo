<?php

namespace App\Jobs;

use App\Models\PrescriptionScan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Arrête les conteneurs IA UNIQUEMENT si aucun scan n'est en cours ou en attente.
 *
 * Ce job est dispatché avec un délai (config pilo.ai_idle_seconds) après chaque scan.
 * Si un nouveau scan est lancé entre-temps, il y aura un nouveau pilo:ai-up de toute façon.
 */
class ShutdownAiIfIdle implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public int $tries = 1;

    public function handle(): void
    {
        $activeScan = PrescriptionScan::whereIn('status', ['pending', 'processing'])->exists();

        if ($activeScan) {
            Log::info('ShutdownAiIfIdle : scan en cours, arrêt différé annulé.');
            return;
        }

        Log::info('ShutdownAiIfIdle : aucun scan actif, arrêt des conteneurs IA.');
        Artisan::call('pilo:ai-down');
    }
}
