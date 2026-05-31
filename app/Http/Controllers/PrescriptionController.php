<?php

namespace App\Http\Controllers;

use App\DTOs\PrescriptionDraft;
use App\Http\Requests\StorePrescriptionRequest;
use App\Models\Prescription;
use App\Models\PrescriptionScan;
use App\Services\ComputeDates;
use App\Services\DuplicateCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrescriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $today  = now()->startOfDay();

        // Auto-terminate : prescriptions actives dont tous les items ont end_date dépassée
        Prescription::where('user_id', $userId)
            ->where('status', 'active')
            ->with('items')
            ->get()
            ->each(function (Prescription $p) use ($today) {
                if ($p->items->isEmpty()) return;
                $allDone = $p->items->every(
                    fn ($item) => $item->end_date !== null && $item->end_date->lt($today)
                );
                if ($allDone) {
                    $p->update(['status' => 'terminated']);
                }
            });

        $prescriptions = Prescription::where('user_id', $userId)
            ->with('items')
            ->orderByDesc('prescribed_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Prescription $p) => [
                'id'              => $p->id,
                'prescriber_name' => $p->prescriber_name,
                'prescribed_at'   => $p->prescribed_at?->toDateString(),
                'source_type'     => $p->source_type,
                'status'          => $p->status,
                'notes'           => $p->notes,
                'items_count'     => $p->items->count(),
                'item_names'      => $p->items->take(3)->pluck('medication_name')->all(),
            ]);

        return Inertia::render('Prescriptions/Index', [
            'prescriptions' => $prescriptions,
        ]);
    }

    public function show(Request $request, Prescription $prescription): Response
    {
        abort_if($prescription->user_id !== $request->user()->id, 403);

        $prescription->load(['items.phases']);

        return Inertia::render('Prescriptions/Show', [
            'prescription' => [
                'id'              => $prescription->id,
                'prescriber_name' => $prescription->prescriber_name,
                'prescribed_at'   => $prescription->prescribed_at?->toDateString(),
                'source_type'     => $prescription->source_type,
                'status'          => $prescription->status,
                'notes'           => $prescription->notes,
                'has_image'       => $prescription->source_image_path !== null,
                'items'           => $prescription->items->map(fn ($item) => [
                    'id'              => $item->id,
                    'medication_name' => $item->medication_name,
                    'dosage'          => $item->dosage,
                    'intake_type'     => $item->intake_type,
                    'posologie_brute' => $item->posologie_brute,
                    'condition'       => $item->condition,
                    'max_per_day'     => $item->max_per_day,
                    'morning'         => $item->morning,
                    'noon'            => $item->noon,
                    'evening'         => $item->evening,
                    'bedtime'         => $item->bedtime,
                    'start_date'      => $item->start_date?->toDateString(),
                    'end_date'        => $item->end_date?->toDateString(),
                    'stock_end_date'  => $item->stock_end_date?->toDateString(),
                ])->all(),
            ],
        ]);
    }

    public function archive(Request $request, Prescription $prescription): RedirectResponse
    {
        abort_if($prescription->user_id !== $request->user()->id, 403);
        abort_if($prescription->status === 'archived', 422);

        $prescription->update(['status' => 'archived']);

        return redirect()
            ->route('prescriptions.index')
            ->with('success', 'Ordonnance archivée.');
    }

    public function image(Request $request, Prescription $prescription): StreamedResponse
    {
        abort_if($prescription->user_id !== $request->user()->id, 403);
        abort_unless($prescription->source_image_path, 404);

        $path = storage_path("app/{$prescription->source_image_path}");
        abort_unless(file_exists($path), 404);

        $mime = mime_content_type($path) ?: 'image/jpeg';

        return response()->streamDownload(
            fn () => readfile($path),
            basename($prescription->source_image_path),
            ['Content-Type' => $mime],
        );
    }

    public function create(): Response
    {
        return Inertia::render('Prescriptions/Form', [
            'draft' => PrescriptionDraft::empty(),
        ]);
    }

    public function store(StorePrescriptionRequest $request): RedirectResponse
    {
        // Source : scan ou saisie manuelle
        $scan      = null;
        $imagePath = null;
        $sourceType = 'manual';

        if ($request->filled('scan_id')) {
            $scan       = PrescriptionScan::where('user_id', $request->user()->id)
                ->findOrFail($request->scan_id);
            $imagePath  = $scan->source_image_path;
            $sourceType = 'scan';
        } elseif ($request->hasFile('source_image')) {
            $imagePath = $request->file('source_image')->store('prescriptions', 'local');
        }

        $prescription = Prescription::create([
            'user_id'           => $request->user()->id,
            'prescriber_name'   => $request->prescriber_name,
            'prescribed_at'     => $request->prescribed_at,
            'source_type'       => $sourceType,
            'source_image_path' => $imagePath,
            'status'            => 'active',
            'notes'             => $request->notes,
        ]);

        $compute      = new ComputeDates();
        $createdItems = [];

        foreach ($request->input('items') as $itemData) {
            $phases         = $itemData['phases'] ?? [];
            $isFixe         = $itemData['intake_type'] === 'fixe';
            $totalPhaseDays = $isFixe ? array_sum(array_column($phases, 'duration_days')) : 0;
            $durationDays   = $isFixe
                ? ($totalPhaseDays ?: ($itemData['duration_days'] ?? null))
                : ($itemData['duration_days'] ?? null);

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

            $item->load('phases');
            $item->update([
                'end_date'       => $compute->endDate($item),
                'stock_end_date' => $compute->stockEndDate($item),
            ]);

            $createdItems[] = $item;
        }

        // Dédup : signalement doux — non-bloquant, ne jamais empêcher l'enregistrement
        $duplicateWarnings = [];
        $dupeCheck         = new DuplicateCheck();

        foreach ($createdItems as $item) {
            $dup = $dupeCheck->findDuplicate(
                userId: $request->user()->id,
                normalizedName: $item->medication_name_normalized,
                excludePrescriptionId: $prescription->id,
            );
            if ($dup) {
                $date      = $dup->prescription->prescribed_at?->format('d/m/Y') ?? 'date inconnue';
                $prescriber = $dup->prescription->prescriber_name ?? 'prescripteur inconnu';
                $duplicateWarnings[] = "{$item->medication_name} est déjà présent dans l'ordonnance"
                    . " de {$prescriber} ({$date}).";
            }
        }

        $redirect = redirect()
            ->route('prescriptions.index')
            ->with('success', 'Ordonnance enregistrée.');

        if (! empty($duplicateWarnings)) {
            $redirect = $redirect->with('duplicate_warnings', $duplicateWarnings);
        }

        return $redirect;
    }

    private static function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/\s+\d.*/u', '', $normalized);
        return trim($normalized);
    }
}
