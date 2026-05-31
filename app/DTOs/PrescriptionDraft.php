<?php

namespace App\DTOs;

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
