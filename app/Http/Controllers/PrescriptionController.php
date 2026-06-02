<?php

namespace App\Http\Controllers;

use App\DTOs\PrescriptionDraft;
use App\Http\Requests\StorePrescriptionRequest;
use App\Models\Prescription;
use App\Models\PrescriptionScan;
use App\Services\ComputeDates;
use App\Services\DuplicateCheck;
use App\Services\ImageConverter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrescriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        // La transition active→terminated est assurée par TerminateExpiredPrescriptions
        // (job schedulé quotidien à 01h00) — cette route est désormais lecture pure.
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

    public function image(Request $request, Prescription $prescription): BinaryFileResponse
    {
        abort_if($prescription->user_id !== $request->user()->id, 403);
        abort_unless($prescription->source_image_path, 404);

        $path = self::resolveStoragePath($prescription->source_image_path);

        // response()->file() → Content-Disposition: inline + support Range (mobile/PWA).
        return response()->file($path, [
            'Content-Type' => mime_content_type($path) ?: 'image/jpeg',
        ]);
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
            $imagePath = app(ImageConverter::class)
                ->storeAsJpeg($request->file('source_image'), 'prescriptions');
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

    /**
     * Résout et valide un chemin image depuis le disque local.
     *
     * Protection contre la traversée de répertoire : vérifie que le chemin
     * résolu reste bien sous la racine du disque 'local' (storage/app/private).
     * Un source_image_path corrompu (ex : '../../.env') serait rejeté avec 403.
     */
    public static function resolveStoragePath(string $relativePath): string
    {
        $disk = Storage::disk('local');
        $root = realpath($disk->path('')) ?: $disk->path('');
        $path = $disk->path($relativePath);

        // realpath() résout les .. et symlinks — retourne false si le fichier n'existe pas
        $real = realpath($path);
        abort_unless($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR), 404);

        return $real;
    }

    private static function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        // Supprimer le contenu entre parenthèses : "Concerta ( Méthylphénidate... )" → "concerta"
        $normalized = preg_replace('/\s*\(.*?\)\s*/u', ' ', $normalized);
        // Supprimer le dosage : "Amoxicilline 500 mg" → "amoxicilline"
        $normalized = preg_replace('/\s+\d.*/u', '', $normalized);
        return trim($normalized);
    }
}
