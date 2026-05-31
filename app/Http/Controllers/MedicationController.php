<?php

namespace App\Http\Controllers;

use App\Models\MedicationNote;
use App\Models\MedicationReference;
use App\Models\PrescriptionItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Tous les items des ordonnances actives ou terminées (jamais archivées)
        $items = PrescriptionItem::query()
            ->with('prescription:id,status,prescriber_name,prescribed_at')
            ->whereHas('prescription', fn ($q) => $q
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'terminated'])
            )
            ->orderByDesc('created_at')
            ->get();

        // Notes manuelles de l'utilisatrice
        $notes = MedicationNote::where('user_id', $user->id)
            ->pluck('note', 'medication_name_normalized');

        // Regroupement par nom normalisé
        $medications = $items
            ->groupBy('medication_name_normalized')
            ->map(function ($group, $normalized) use ($notes) {
                $latest   = $group->first();
                $isActive = $group->contains(fn ($item) => $item->prescription->status === 'active');

                // Indication BDPM via cip_code (null si non renseigné)
                $indication     = null;
                $originatorName = null;
                $isGeneric      = false;
                $cipCode = $group->first(fn ($i) => $i->cip_code)?->cip_code;
                if ($cipCode) {
                    $ref = MedicationReference::where('cip13', $cipCode)
                        ->select('indication', 'name')
                        ->first();
                    $indication     = $ref?->indication;
                    $originatorName = $ref?->name;
                    // Générique = premier mot du nom originator ≠ premier mot du nom item.
                    // Ex : "DEROXAT 20 mg, comprimé…" → "deroxat" ≠ "paroxétine" → générique.
                    // Ex : "LEVOTHYROX 100 µg, comprimé…" → "levothyrox" == "levothyrox" → originator.
                    // On compare le premier token (avant espace ou virgule) pour ignorer
                    // la forme galénique qui est toujours dans le nom de référence BDPM.
                    $firstWord  = fn (string $s): string => explode(' ', mb_strtolower(trim($s)))[0];
                    $isGeneric  = $originatorName !== null
                        && $firstWord($originatorName) !== $firstWord($latest->medication_name);
                }

                return [
                    'normalized'      => $normalized,
                    'name'            => $latest->medication_name,
                    'dosage'          => $latest->dosage,
                    'is_active'       => $isActive,
                    'note'            => $notes->get($normalized),
                    'indication'      => $indication,
                    'originator_name' => $originatorName,
                    'is_generic'      => $isGeneric,
                ];
            })
            ->values();

        return Inertia::render('Medications/Index', [
            'active'   => $medications->filter(fn ($m) => $m['is_active'])->values(),
            'inactive' => $medications->filter(fn ($m) => ! $m['is_active'])->values(),
        ]);
    }
}
