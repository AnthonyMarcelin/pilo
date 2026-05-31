<?php

namespace App\Http\Controllers;

use App\DTOs\PrescriptionDraft;
use App\Http\Requests\StorePrescriptionRequest;
use App\Models\Prescription;
use App\Services\ComputeDates;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PrescriptionController extends Controller
{
    /**
     * Formulaire de saisie manuelle (brouillon vide).
     * Phase 7 : un second point d'entrée passera un brouillon pré-rempli par l'IA.
     */
    public function create(): Response
    {
        return Inertia::render('Prescriptions/Form', [
            'draft' => PrescriptionDraft::empty(),
        ]);
    }

    public function store(StorePrescriptionRequest $request): RedirectResponse
    {
        $imagePath = null;
        if ($request->hasFile('source_image')) {
            $imagePath = $request->file('source_image')
                ->store('prescriptions', 'local');
        }

        $prescription = Prescription::create([
            'user_id'           => $request->user()->id,
            'prescriber_name'   => $request->prescriber_name,
            'prescribed_at'     => $request->prescribed_at,
            'source_type'       => 'manual',
            'source_image_path' => $imagePath,
            'status'            => 'active',
            'notes'             => $request->notes,
        ]);

        $compute = new ComputeDates();

        foreach ($request->input('items') as $itemData) {
            $phases     = $itemData['phases'] ?? [];
            $isFixe     = $itemData['intake_type'] === 'fixe';

            // Pour fixe : durée totale = somme des paliers ; sinon champ explicite
            $totalPhaseDays = $isFixe
                ? array_sum(array_column($phases, 'duration_days'))
                : 0;

            $durationDays = $isFixe
                ? ($totalPhaseDays ?: ($itemData['duration_days'] ?? null))
                : ($itemData['duration_days'] ?? null);

            // Les colonnes morning/noon/evening/bedtime de l'item = doses du palier 1
            $phase1 = $phases[0] ?? [];

            $item = $prescription->items()->create([
                'medication_name'            => $itemData['medication_name'],
                'medication_name_normalized' => self::normalizeName($itemData['medication_name']),
                'dosage'                     => $itemData['dosage'] ?? null,
                'intake_type'                => $itemData['intake_type'],
                'morning'                    => $isFixe ? ($phase1['morning'] ?? null) : null,
                'noon'                       => $isFixe ? ($phase1['noon']    ?? null) : null,
                'evening'                    => $isFixe ? ($phase1['evening'] ?? null) : null,
                'bedtime'                    => $isFixe ? ($phase1['bedtime'] ?? null) : null,
                'condition'                  => $itemData['condition']   ?? null,
                'max_per_day'                => $itemData['max_per_day'] ?? null,
                'posologie_brute'            => $itemData['posologie_brute'],
                'duration_days'              => $durationDays,
                'qsp_days'                   => $itemData['qsp_days'] ?? null,
                'start_date'                 => $itemData['start_date'] ?? $prescription->prescribed_at,
                'boxes_count'                => $itemData['boxes_count'] ?? null,
            ]);

            // Paliers — fixe uniquement
            if ($isFixe && ! empty($phases)) {
                foreach ($phases as $order => $phaseData) {
                    $item->phases()->create([
                        'phase_order'   => $order + 1,
                        'duration_days' => (int) $phaseData['duration_days'],
                        'morning'       => $phaseData['morning'] ?? null,
                        'noon'          => $phaseData['noon']    ?? null,
                        'evening'       => $phaseData['evening'] ?? null,
                        'bedtime'       => $phaseData['bedtime'] ?? null,
                    ]);
                }
            }

            // Calcul et stockage des dates (end_date, stock_end_date)
            $item->load('phases');
            $item->update([
                'end_date'       => $compute->endDate($item),
                'stock_end_date' => $compute->stockEndDate($item),
            ]);
        }

        return redirect()
            ->route('prescriptions.index')
            ->with('success', 'Ordonnance enregistrée.');
    }

    /**
     * Normalisation du nom : minuscules, sans dosage.
     * "Paroxétine 20 mg" → "paroxétine"
     */
    private static function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        // Supprime le premier token numérique et tout ce qui suit
        $normalized = preg_replace('/\s+\d.*/u', '', $normalized);
        return trim($normalized);
    }
}
