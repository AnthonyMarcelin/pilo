<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'prescription_id', 'medication_name', 'medication_name_normalized', 'dosage',
    'intake_type', 'morning', 'noon', 'evening', 'bedtime',
    'condition', 'max_per_day', 'posologie_brute',
    'duration_days', 'qsp_days', 'start_date', 'end_date',
    'stock_units', 'boxes_count', 'units_per_box', 'cip_code', 'stock_end_date',
])]
class PrescriptionItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date'      => 'date',
            'end_date'        => 'date',
            'stock_end_date'  => 'date',
            'morning'         => 'decimal:2',
            'noon'            => 'decimal:2',
            'evening'         => 'decimal:2',
            'bedtime'         => 'decimal:2',
            'max_per_day'     => 'decimal:2',
        ];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(PrescriptionItemPhase::class)->orderBy('phase_order');
    }

    public function isFixe(): bool   { return $this->intake_type === 'fixe'; }
    public function isSiBesoin(): bool { return $this->intake_type === 'si_besoin'; }
    public function isAutre(): bool  { return $this->intake_type === 'autre'; }
}
