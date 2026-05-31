<?php

namespace App\Services\Regimen;

/** Médicament planifié pour un moment de la journée, avec contexte de phase. */
final readonly class ScheduledEntry
{
    public function __construct(
        public int     $prescriptionItemId,
        public string  $medicationName,
        public string  $dosageLabel,       // ex. "10 mg"
        public float   $morningQty,
        public float   $noonQty,
        public float   $eveningQty,
        public float   $bedtimeQty,
        public string  $posologieBrute,    // filet de sécurité — toujours affiché
        public bool    $isTerminated,
        // Contexte de phase (null si mono-palier sans annotation de progression)
        public ?int    $dayInPhase,        // ex. 4 → "jour 4 de la phase active"
        public ?int    $phaseDurationDays, // ex. 8 → "phase de 8 jours"
        public ?int    $totalPhases,       // 1 = pas de dégression
        public ?string $nextChangeNote,    // ex. "baisse à 1 cp le 9 juin" | null si dernier palier
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
