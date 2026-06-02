<?php

namespace App\Jobs;

use App\Models\Prescription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Passe les ordonnances actives dont TOUS les items ont end_date dépassée
 * au statut "terminated". Exécuté quotidiennement par le scheduler.
 *
 * Séparé de GET /prescriptions pour garantir que la page reste lecture pure
 * et que la transition se produit même si l'utilisatrice ne visite pas la page.
 */
class TerminateExpiredPrescriptions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $today = Carbon::today();

        // chunkById() utilise WHERE id > dernier_id LIMIT N au lieu d'OFFSET N.
        // Avec each()/chunk(), les updates de statut en cours d'itération décalent
        // l'offset du prochain lot → des ordonnances sont sautées.
        // chunkById() est immunisé contre ce problème.
        Prescription::where('status', 'active')
            ->with('items')
            ->chunkById(200, function ($prescriptions) use ($today): void {
                foreach ($prescriptions as $p) {
                    if ($p->items->isEmpty()) {
                        continue;
                    }

                    $allDone = $p->items->every(
                        fn ($item) => $item->end_date !== null && $item->end_date->lt($today)
                    );

                    if ($allDone) {
                        $p->update(['status' => 'terminated']);
                    }
                }
            });
    }
}
