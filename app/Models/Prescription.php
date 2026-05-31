<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'prescriber_name', 'prescribed_at', 'source_type',
            'source_image_path', 'status', 'notes'])]
class Prescription extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'prescribed_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
