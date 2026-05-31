<?php

namespace App\DTOs;

/**
 * Brouillon d'ordonnance — sert à la fois au formulaire vide (saisie manuelle)
 * et au formulaire pré-rempli (Phase 7 : scan IA).
 *
 * Ces classes sont des Value Objects immuables, sérialisables en JSON pour Inertia.
 */

// ─── Palier de posologie ──────────────────────────────────────────────────────

final readonly class PrescriptionItemPhaseDraft implements \JsonSerializable
{
    public function __construct(
        public ?int   $duration_days = null,
        public ?float $morning       = null,
        public ?float $noon          = null,
        public ?float $evening       = null,
        public ?float $bedtime       = null,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'duration_days' => $this->duration_days,
            'morning'       => $this->morning,
            'noon'          => $this->noon,
            'evening'       => $this->evening,
            'bedtime'       => $this->bedtime,
        ];
    }
}

// ─── Ligne de médicament ─────────────────────────────────────────────────────

final readonly class PrescriptionItemDraft implements \JsonSerializable
{
    /**
     * @param  PrescriptionItemPhaseDraft[]  $phases
     */
    public function __construct(
        public string  $medication_name  = '',
        public ?string $dosage           = null,
        public string  $intake_type      = 'fixe',
        public string  $posologie_brute  = '',
        public ?string $condition        = null,
        public ?float  $max_per_day      = null,
        public ?int    $qsp_days         = null,
        public ?int    $duration_days    = null,
        public ?string $start_date       = null,
        public ?int    $boxes_count      = null,
        public array   $phases           = [],
    ) {}

    public static function empty(): self
    {
        return new self(
            phases: [new PrescriptionItemPhaseDraft()],
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'medication_name' => $this->medication_name,
            'dosage'          => $this->dosage,
            'intake_type'     => $this->intake_type,
            'posologie_brute' => $this->posologie_brute,
            'condition'       => $this->condition,
            'max_per_day'     => $this->max_per_day,
            'qsp_days'        => $this->qsp_days,
            'duration_days'   => $this->duration_days,
            'start_date'      => $this->start_date,
            'boxes_count'     => $this->boxes_count,
            'phases'          => array_map(
                fn ($p) => $p->jsonSerialize(),
                $this->phases,
            ),
        ];
    }
}

// ─── Ordonnance ───────────────────────────────────────────────────────────────

final readonly class PrescriptionDraft implements \JsonSerializable
{
    /**
     * @param  PrescriptionItemDraft[]  $items
     */
    public function __construct(
        public ?string $prescriber_name = null,
        public ?string $prescribed_at   = null,
        public ?string $notes           = null,
        public array   $items           = [],
    ) {}

    public static function empty(): self
    {
        return new self(
            items: [PrescriptionItemDraft::empty()],
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'prescriber_name' => $this->prescriber_name,
            'prescribed_at'   => $this->prescribed_at,
            'notes'           => $this->notes,
            'items'           => array_map(
                fn ($i) => $i->jsonSerialize(),
                $this->items,
            ),
        ];
    }
}
