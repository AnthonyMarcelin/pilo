<?php

namespace App\Services\Regimen;

/** Résultat complet du régime pour une journée. */
final readonly class DailyRegimenResult
{
    public function __construct(
        /** Clés : 'morning', 'noon', 'evening', 'bedtime' → ScheduledEntry[] */
        public array $fixed,
        /** Entrées PRN (type si_besoin) */
        public array $asNeeded,
        /** Entrées texte libre (type autre) */
        public array $special,
        /** Alertes in-app (renouvellement, fin de traitement…) */
        public array $alerts,
    ) {}

    public function hasMoment(string $moment): bool
    {
        return collect($this->fixed[$moment] ?? [])
            ->contains(fn (ScheduledEntry $e) => $e->qtyForMoment($moment) > 0);
    }
}
