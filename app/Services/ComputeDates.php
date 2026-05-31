<?php

namespace App\Services;

use App\Models\PrescriptionItem;
use Carbon\Carbon;

/**
 * Calcul des trois dates d'un prescription_item.
 *
 * Convention J1 / borne exclusive :
 *   - start_date = J1 (premier jour de prise).
 *   - end_date   = start_date + total_duration  →  borne EXCLUSIVE.
 *   - Actif  : date <  end_date
 *   - Terminé: date >= end_date
 *
 * Voir SPEC §4.
 */
final class ComputeDates
{
    /**
     * Dernier jour de traitement + 1 (borne exclusive).
     * = start_date + sum(phases.duration_days)  [phases présentes]
     * = start_date + duration_days              [fallback sans phases]
     * null si start_date ou durée absents.
     */
    public function endDate(PrescriptionItem $item): ?Carbon
    {
        if (! $item->start_date) {
            return null;
        }

        $phases = $item->phases;

        if ($phases->isNotEmpty()) {
            $total = (int) $phases->sum('duration_days');
            return $item->start_date->copy()->addDays($total);
        }

        if ($item->duration_days) {
            return $item->start_date->copy()->addDays((int) $item->duration_days);
        }

        return null;
    }

    /**
     * Estimation de la fin de stock (UNIQUEMENT pour intake_type = fixe).
     *
     * Priorité :
     *   1. qsp_days connu → start + qsp_days
     *   2. Sinon, stock_units ÷ conso journalière (simulée phase par phase).
     *
     * Retourne null pour si_besoin ou si les données sont insuffisantes.
     * Toujours affichée avec « ~ » dans l'UI (estimation, jamais exacte).
     */
    public function stockEndDate(PrescriptionItem $item): ?Carbon
    {
        if ($item->isSiBesoin() || ! $item->start_date) {
            return null;
        }

        if ($item->qsp_days) {
            return $item->start_date->copy()->addDays((int) $item->qsp_days);
        }

        $stock = $this->resolveStockUnits($item);
        if ($stock === null || $stock <= 0) {
            return null;
        }

        $phases = $item->phases->sortBy('phase_order');

        if ($phases->isEmpty()) {
            $daily = $this->directDailyDose($item);
            if ($daily <= 0) {
                return null;
            }
            return $item->start_date->copy()->addDays((int) floor($stock / $daily));
        }

        // Simulation phase par phase (conso variable selon le palier)
        $remaining = (float) $stock;
        $daysElapsed = 0;

        foreach ($phases as $phase) {
            $daily = $phase->dailyDose();

            if ($daily <= 0) {
                $daysElapsed += (int) $phase->duration_days;
                continue;
            }

            $unitsInPhase = $daily * (int) $phase->duration_days;

            if ($remaining <= $unitsInPhase) {
                $daysElapsed += (int) floor($remaining / $daily);
                return $item->start_date->copy()->addDays($daysElapsed);
            }

            $remaining    -= $unitsInPhase;
            $daysElapsed  += (int) $phase->duration_days;
        }

        return null; // Le stock couvre tout le traitement
    }

    /**
     * Vrai si le traitement est terminé à la date $on (date >= end_date exclusive).
     */
    public function isTerminated(PrescriptionItem $item, Carbon $on): bool
    {
        $end = $this->endDate($item);
        return $end !== null && $on->copy()->startOfDay()->gte($end);
    }

    // ─── Helpers privés ───────────────────────────────────────────────────────

    private function resolveStockUnits(PrescriptionItem $item): ?float
    {
        if ($item->stock_units !== null) {
            return (float) $item->stock_units;
        }

        if ($item->boxes_count && $item->units_per_box) {
            return (float) ($item->boxes_count * $item->units_per_box);
        }

        return null;
    }

    private function directDailyDose(PrescriptionItem $item): float
    {
        return (float) ($item->morning  ?? 0)
             + (float) ($item->noon     ?? 0)
             + (float) ($item->evening  ?? 0)
             + (float) ($item->bedtime  ?? 0);
    }
}
