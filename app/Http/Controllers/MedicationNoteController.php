<?php

namespace App\Http\Controllers;

use App\Models\MedicationNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicationNoteController extends Controller
{
    /** Crée ou met à jour la note manuelle pour un médicament normalisé. */
    public function upsert(Request $request, string $normalized): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        MedicationNote::updateOrCreate(
            [
                'user_id'                    => $request->user()->id,
                'medication_name_normalized' => $normalized,
            ],
            ['note' => $request->note]
        );

        return response()->json(['status' => 'ok']);
    }

    /** Supprime la note manuelle. */
    public function destroy(Request $request, string $normalized): JsonResponse
    {
        MedicationNote::where('user_id', $request->user()->id)
            ->where('medication_name_normalized', $normalized)
            ->delete();

        return response()->json(['status' => 'ok']);
    }
}
