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

                // Indication BDPM : lookup CIP13 direct, sinon matching par nom
                $indication     = null;
                $originatorName = null;
                $isGeneric      = false;
                $cipCode = $group->first(fn ($i) => $i->cip_code)?->cip_code;
                $ref     = $cipCode
                    ? MedicationReference::where('cip13', $cipCode)->select('indication', 'name')->first()
                    : null;

                // Fallback : matching par nom normalisé (nom commercial ou DCI)
                if ($ref === null) {
                    $ref = self::matchByName($normalized);
                }

                if ($ref !== null) {
                    $indication     = $ref->indication;
                    $originatorName = $ref->name;
                    // Générique = premier mot du nom originator ≠ premier mot du nom item.
                    // Ex : "DEROXAT 20 mg, comprimé…" → "deroxat" ≠ "paroxétine" → générique.
                    // Ex : "LEVOTHYROX 100 µg, comprimé…" → "levothyrox" == "levothyrox" → originator.
                    $firstWord = fn (string $s): string => explode(' ', mb_strtolower(trim($s)))[0];
                    $isGeneric = $originatorName !== null
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

    /**
     * Matching tolérant nom OCR → medication_references, sans code CIP.
     *
     * Stratégie (par ordre de priorité) :
     *  1. DCI exacte : dci_name LIKE 'premier_mot%'   (lévothyroxine → LÉVOTHYROXINE SODIQUE)
     *  2. Nom commercial : name LIKE 'premier_mot%'   (levothyrox → LEVOTHYROX 100 µg...)
     *
     * Normalisation : minuscules + translittération ASCII (accents → sans accent) pour
     * contourner la limitation de LIKE SQLite sur les caractères accentués.
     * Préférence aux entrées avec indication non-nulle.
     */
    private static function matchByName(string $normalized): ?MedicationReference
    {
        if (strlen($normalized) < 3) {
            return null;
        }

        // Translittération UTF-8 → ASCII (é→e, à→a, ô→o…) pour LIKE insensible aux accents
        $ascii     = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
        $firstWord = strtolower(explode(' ', $ascii)[0]);

        if (strlen($firstWord) < 3) {
            return null;
        }

        // 1. Substance active (DCI) — couvre "lévothyroxine" → entrée BDPM DCI
        $ref = MedicationReference::whereRaw('LOWER(dci_name) LIKE ?', [$firstWord . '%'])
            ->whereNotNull('indication')
            ->select('indication', 'name')
            ->first();

        if ($ref !== null) {
            return $ref;
        }

        // 2. Nom commercial — couvre "levothyrox", "amlodipine", "paroxetine"
        return MedicationReference::whereRaw('LOWER(name) LIKE ?', [$firstWord . '%'])
            ->whereNotNull('indication')
            ->select('indication', 'name')
            ->first();
    }
}
