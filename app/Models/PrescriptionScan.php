<?php

namespace App\Models;

use App\DTOs\PrescriptionDraft;
use App\DTOs\PrescriptionItemDraft;
use App\DTOs\PrescriptionItemPhaseDraft;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionScan extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'status', 'source_image_path', 'draft', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool    { return $this->status === 'pending'; }
    public function isProcessing(): bool { return $this->status === 'processing'; }
    public function isDone(): bool       { return $this->status === 'done'; }
    public function isFailed(): bool     { return $this->status === 'failed'; }

    /**
     * Reconstitue un PrescriptionDraft depuis le JSON stocké.
     * Retourne null si le draft n'est pas encore prêt.
     */
    public function toDraft(): ?PrescriptionDraft
    {
        if (! $this->draft || ! isset($this->draft['items'])) {
            return null;
        }

        $items = array_map(function (array $raw) {
            $phases = array_map(
                fn (array $p) => new PrescriptionItemPhaseDraft(
                    duration_days: $p['duration_days'] ?? null,
                    morning:       $p['morning']       ?? null,
                    noon:          $p['noon']          ?? null,
                    evening:       $p['evening']       ?? null,
                    bedtime:       $p['bedtime']       ?? null,
                ),
                (array) ($raw['phases'] ?? []),
            );

            return new PrescriptionItemDraft(
                medication_name: $raw['medication_name'] ?? '',
                dosage:          $raw['dosage']          ?? null,
                intake_type:     $raw['intake_type']     ?? 'autre',
                posologie_brute: $raw['posologie_brute'] ?? '',
                condition:       $raw['condition']       ?? null,
                max_per_day:     $raw['max_per_day']     ?? null,
                qsp_days:        $raw['qsp_days']        ?? null,
                duration_days:   $raw['duration_days']   ?? null,
                start_date:      null,
                boxes_count:     null,
                phases:          $phases,
            );
        }, $this->draft['items']);

        return new PrescriptionDraft(
            prescriber_name: $this->draft['prescriber_name'] ?? null,
            prescribed_at:   $this->draft['prescribed_at']   ?? null,
            notes:           $this->draft['notes']           ?? null,
            items:           $items,
        );
    }
}
