<?php

namespace App\Services;

use App\Models\PrescriptionItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Signalement doux d'un doublon de médicament normalisé dans les ordonnances actives.
 *
 * Règles (SPEC §6) :
 *  - Compare uniquement medication_name_normalized (pas de détection de molécule).
 *  - Ne bloque jamais — retourne l'item trouvé ou null.
 *  - Un même médicament peut légitimement figurer sur deux ordonnances (ex. exception).
 *    C'est l'humain qui tranche.
 */
final class DuplicateCheck
{
    /**
     * Retourne le premier item actif avec le même nom normalisé, ou null.
     *
     * @param  int         $userId         Périmètre de recherche
     * @param  string      $normalizedName medication_name_normalized à comparer
     * @param  int|null    $excludeItemId  Exclure cet item (utile à l'édition)
     */
    public function findDuplicate(
        int    $userId,
        string $normalizedName,
        ?int   $excludeItemId = null,
    ): ?PrescriptionItem {
        return PrescriptionItem::query()
            ->whereHas('prescription', fn (Builder $q) => $q
                ->where('user_id', $userId)
                ->where('status', 'active'))
            ->where('medication_name_normalized', $normalizedName)
            ->when($excludeItemId !== null, fn ($q) => $q->where('id', '!=', $excludeItemId))
            ->with('prescription')
            ->first();
    }
}
