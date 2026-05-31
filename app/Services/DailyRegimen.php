<?php

namespace App\Services;

use App\Services\Regimen\DailyRegimenResult;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcule le régime médicamenteux pour une date donnée.
 *
 * Logique (implémentée en Phase 3) :
 *  1. Charge les prescription_items actifs (ordonnances actives, end_date >= date).
 *  2. Pour chaque item fixe, résout la phase active via resolveActivePhase().
 *  3. Groupe les ScheduledEntry par moment (morning/noon/evening/bedtime).
 *  4. Collecte items si_besoin et autre séparément.
 *  5. Construit les alertes.
 */
final class DailyRegimen
{
    public function __construct(private readonly int $userId) {}

    /**
     * Retourne le régime complet pour la date donnée.
     */
    public function forDate(Carbon $date): DailyRegimenResult
    {
        throw new \LogicException('DailyRegimen::forDate() — implémentation Phase 3.');
    }

    // ─── Méthodes privées (signatures pour guider les tests) ─────────────────

    /**
     * Trouve la phase active pour un item fixe à la date donnée.
     *
     * Algorithme :
     *   dayNumber = (date - item.start_date).days + 1
     *   Parcourt les phases par phase_order, accumule duration_days.
     *   Retourne la phase où cumulant_avant < dayNumber <= cumulant_courant.
     *   Retourne null si dayNumber > somme totale (item terminé).
     *
     * @param  Collection $phases  ordonnées par phase_order
     * @return array  keys: phase (object|null), dayInPhase (int), cumulBefore (int)
     */
    private function resolveActivePhase(Carbon $date, Carbon $startDate, Collection $phases): array
    {
        throw new \LogicException('resolveActivePhase() — implémentation Phase 3.');
    }

    /**
     * Construit la note de prochain changement.
     * Ex : "baisse à 1 cp à partir du 9 juin" | null si dernier palier.
     */
    private function buildNextChangeNote(
        Carbon     $startDate,
        Collection $phases,
        int        $currentPhaseOrder,
        int        $cumulBefore,
    ): ?string {
        throw new \LogicException('buildNextChangeNote() — implémentation Phase 3.');
    }
}
