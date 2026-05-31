<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['prescription_item_id', 'phase_order', 'duration_days',
            'morning', 'noon', 'evening', 'bedtime'])]
class PrescriptionItemPhase extends Model
{
    protected function casts(): array
    {
        return [
            'morning' => 'decimal:2',
            'noon'    => 'decimal:2',
            'evening' => 'decimal:2',
            'bedtime' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class, 'prescription_item_id');
    }

    public function dailyDose(): float
    {
        return (float)($this->morning ?? 0)
             + (float)($this->noon    ?? 0)
             + (float)($this->evening ?? 0)
             + (float)($this->bedtime ?? 0);
    }
}
