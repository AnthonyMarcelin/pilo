<?php

namespace App\DTOs;

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
