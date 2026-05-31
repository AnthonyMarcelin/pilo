<?php

namespace App\Services\Regimen;

/** Médicament planifié pour une journée (grille fixe, au besoin ou particulier). */
final readonly class ScheduledEntry
{
    public function __construct(
        public int     $prescriptionItemId,
        public string  $medicationName,
        public string  $dosageLabel,
        public float   $morningQty,
        public float   $noonQty,
        public float   $eveningQty,
        public float   $bedtimeQty,
        public string  $posologieBrute,
        public bool    $isTerminated,
        // Contexte de phase (null si mono-palier sans annotation, ou si_besoin/autre)
        public ?int    $dayInPhase,
        public ?int    $phaseDurationDays,
        public ?int    $totalPhases,
        public ?string $nextChangeNote,
        // Champs si_besoin uniquement
        public ?string $condition    = null,
        public ?float  $maxPerDay    = null,
        // Label de fin de traitement pour items terminés : « terminé le 26 mai »
        public ?string $endDateLabel = null,
    ) {}

    public function hasTapering(): bool
    {
        return ($this->totalPhases ?? 1) > 1;
    }

    public function qtyForMoment(string $moment): float
    {
        return match ($moment) {
            'morning' => $this->morningQty,
            'noon'    => $this->noonQty,
            'evening' => $this->eveningQty,
            'bedtime' => $this->bedtimeQty,
            default   => 0.0,
        };
    }
}
