<?php

namespace App\Services;

use App\Models\PrescriptionItem;
use App\Services\Regimen\DailyRegimenResult;
use App\Services\Regimen\ScheduledEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Calcule le régime médicamenteux pour une date donnée.
 *
 * Convention J1 / borne exclusive (voir SPEC §5) :
 *   day_number = (date − start_date).days + 1
 *   Phase k active si  cumul(k-1) < day_number ≤ cumul(k)
 *   Terminé si          day_number > sum(toutes les phases)
 */
final class DailyRegimen
{
    public function __construct(private readonly int $userId) {}

    /**
     * Retourne le régime complet pour la date donnée.
     *
     * Charge uniquement les items des ordonnances actives.
     * Les items dont la phase est épuisée sont inclus avec isTerminated=true
     * (jamais masqués — cf. règles d'or).
     */
    public function forDate(Carbon $date): DailyRegimenResult
    {
        $date = $date->copy()->startOfDay();

        // Charge les items des ordonnances actives ET terminées.
        // Les terminées restent affichées grisées ("terminé le X") jusqu'à archivage
        // explicite par l'utilisateur — règle d'or n°4 : pas de disparition silencieuse.
        // Les archivées (action humaine) sont les seules exclues.
        $items = PrescriptionItem::query()
            ->whereHas('prescription', fn (Builder $q) => $q
                ->where('user_id', $this->userId)
                ->whereIn('status', ['active', 'terminated']))
            ->with('phases')
            ->get();

        $fixed    = ['morning' => [], 'noon' => [], 'evening' => [], 'bedtime' => []];
        $asNeeded = [];
        $special  = [];
        $alerts   = [];

        $computeDates     = new ComputeDates();
        $stockThreshold   = (int) config('pilo.stock_alert_days', 7);

        foreach ($items as $item) {
            if (! $item->start_date || $item->start_date->copy()->startOfDay()->gt($date)) {
                continue; // Pas encore commencé
            }

            if ($item->isFixe()) {
                $this->appendFixe($item, $date, $fixed);

                // Alerte renouvellement : stock_end_date ≤ aujourd'hui + seuil
                $stockEnd = $computeDates->stockEndDate($item);
                if ($stockEnd !== null) {
                    $stockEndDay = $stockEnd->copy()->startOfDay();
                    $limitDay    = $date->copy()->addDays($stockThreshold);

                    if ($stockEndDay->lte($limitDay)) {
                        $daysLeft = $stockEndDay->gte($date)
                            ? (int) $date->diffInDays($stockEndDay)
                            : 0;

                        $alerts[] = [
                            'type'       => 'renewal',
                            'medication' => $item->medication_name,
                            'daysLeft'   => $daysLeft,
                        ];
                    }
                }
            } elseif ($item->isSiBesoin()) {
                $asNeeded[] = new ScheduledEntry(
                    prescriptionItemId: $item->id,
                    medicationName:     $item->medication_name,
                    dosageLabel:        $item->dosage ?? '',
                    morningQty:         0.0,
                    noonQty:            0.0,
                    eveningQty:         0.0,
                    bedtimeQty:         0.0,
                    posologieBrute:     $item->posologie_brute,
                    isTerminated:       false,
                    dayInPhase:         null,
                    phaseDurationDays:  null,
                    totalPhases:        null,
                    nextChangeNote:     null,
                    condition:          $item->condition,
                    maxPerDay:          $item->max_per_day ? (float) $item->max_per_day : null,
                );
            } elseif ($item->isAutre()) {
                $special[] = new ScheduledEntry(
                    prescriptionItemId: $item->id,
                    medicationName:     $item->medication_name,
                    dosageLabel:        $item->dosage ?? '',
                    morningQty:         0.0,
                    noonQty:            0.0,
                    eveningQty:         0.0,
                    bedtimeQty:         0.0,
                    posologieBrute:     $item->posologie_brute,
                    isTerminated:       false,
                    dayInPhase:         null,
                    phaseDurationDays:  null,
                    totalPhases:        null,
                    nextChangeNote:     null,
                );
            }
        }

        return new DailyRegimenResult(
            fixed:    $fixed,
            asNeeded: $asNeeded,
            special:  $special,
            alerts:   $alerts,
        );
    }

    // ─── Helpers privés ───────────────────────────────────────────────────────

    private function appendFixe(PrescriptionItem $item, Carbon $date, array &$fixed): void
    {
        $phases   = $item->phases; // Déjà triées par phase_order (voir modèle)
        $resolved = $this->resolveActivePhase($date, $item->start_date->copy()->startOfDay(), $phases);

        $isTerminated    = ($resolved['phase'] === null);
        $phase           = $resolved['phase'];
        $nextChangeNote  = null;
        $endDateLabel    = null;

        if (! $isTerminated) {
            $nextChangeNote = $this->buildNextChangeNote(
                $item->start_date->copy()->startOfDay(),
                $phases,
                $phase->phase_order,
                $resolved['cumulBefore'],
            );
        } else {
            // Dernier jour de prise = end_date exclusive - 1 jour
            $endDate = $item->end_date ?? $item->start_date->copy()->addDays(
                (int) $phases->sum('duration_days') ?: (int) ($item->duration_days ?? 0)
            );
            $lastDay      = $endDate->copy()->subDay();
            $endDateLabel = 'terminé le ' . $lastDay->locale('fr')->translatedFormat('j F');
        }

        $entry = new ScheduledEntry(
            prescriptionItemId: $item->id,
            medicationName:     $item->medication_name,
            dosageLabel:        $item->dosage ?? '',
            morningQty:         $isTerminated ? 0.0 : (float) ($phase->morning ?? 0),
            noonQty:            $isTerminated ? 0.0 : (float) ($phase->noon    ?? 0),
            eveningQty:         $isTerminated ? 0.0 : (float) ($phase->evening ?? 0),
            bedtimeQty:         $isTerminated ? 0.0 : (float) ($phase->bedtime ?? 0),
            posologieBrute:     $item->posologie_brute,
            isTerminated:       $isTerminated,
            dayInPhase:         $resolved['dayInPhase'],
            phaseDurationDays:  $phase?->duration_days,
            totalPhases:        $phases->count() ?: 1,
            nextChangeNote:     $nextChangeNote,
            endDateLabel:       $endDateLabel,
        );

        // Les moments de référence pour déterminer dans quels slots insérer l'entrée :
        // - Item actif  → doses de la phase courante
        // - Item terminé → doses du premier palier (item.morning/noon/…) = dernière info connue
        $moments  = ['morning', 'noon', 'evening', 'bedtime'];
        $addedAny = false;

        foreach ($moments as $moment) {
            $refQty = $isTerminated
                ? (float) ($item->$moment ?? 0)
                : (float) ($phase->$moment ?? 0);

            if ($refQty > 0) {
                $fixed[$moment][] = $entry;
                $addedAny = true;
            }
        }

        if (! $addedAny) {
            // Garde-fou : item fixe sans aucune dose renseignée → morning par défaut
            $fixed['morning'][] = $entry;
        }
    }

    /**
     * Trouve la phase active pour un item fixe à la date donnée.
     *
     * day_number = (date − startDate).days + 1  [J1]
     * Phase k active si  cumul(k-1) < day_number ≤ cumul(k)
     *
     * @return array{phase: mixed|null, dayInPhase: int|null, cumulBefore: int}
     */
    private function resolveActivePhase(Carbon $date, Carbon $startDate, Collection $phases): array
    {
        $dayNumber   = (int) $startDate->diffInDays($date) + 1;
        $cumulBefore = 0;

        foreach ($phases as $phase) {
            $cumulAfter = $cumulBefore + (int) $phase->duration_days;

            if ($dayNumber <= $cumulAfter) {
                return [
                    'phase'       => $phase,
                    'dayInPhase'  => $dayNumber - $cumulBefore,
                    'cumulBefore' => $cumulBefore,
                ];
            }

            $cumulBefore = $cumulAfter;
        }

        // day_number > total → terminé
        return ['phase' => null, 'dayInPhase' => null, 'cumulBefore' => $cumulBefore];
    }

    /**
     * Construit la note de prochain changement de palier.
     * Ex : "↓ 1 cp à partir du 4 juin"   (null si dernier palier)
     */
    private function buildNextChangeNote(
        Carbon     $startDate,
        Collection $phases,
        int        $currentPhaseOrder,
        int        $cumulBefore,
    ): ?string {
        /** @var \App\Models\PrescriptionItemPhase|null $currentPhase */
        $currentPhase = $phases->firstWhere('phase_order', $currentPhaseOrder);
        $nextPhase    = $phases->firstWhere('phase_order', $currentPhaseOrder + 1);

        if (! $nextPhase || ! $currentPhase) {
            return null;
        }

        // Le palier suivant commence le jour cumulBefore + currentPhase.duration_days + 1 (J1)
        // soit : start + (cumulBefore + currentPhase.duration_days) jours calendaires
        $nextStartDate = $startDate->copy()->addDays($cumulBefore + (int) $currentPhase->duration_days);

        $nextDose    = $nextPhase->dailyDose();
        $currentDose = $currentPhase->dailyDose();
        $arrow       = $nextDose < $currentDose ? '↓' : ($nextDose > $currentDose ? '↑' : '→');

        $dateStr = $nextStartDate->locale('fr')->translatedFormat('j F');

        return "{$arrow} {$nextDose} cp à partir du {$dateStr}";
    }
}
