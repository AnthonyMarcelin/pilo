<?php

namespace App\DTOs;

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
