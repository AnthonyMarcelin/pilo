<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPrescriptionScan;
use App\Models\PrescriptionScan;
use App\Services\ImageConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ScanController extends Controller
{
    /**
     * Upload de l'image → création du scan + dispatch du job.
     * Redirige vers la page de statut.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            // File::image() exclut HEIC et PDF — on liste les MIME explicitement.
            // Conversion HEIC→JPEG : ImageConverter. PDF→image : paddleocr-vl (pypdfium2).
            // Max 25 Mo : cohérent avec upload_max_filesize=25M (PHP) et nginx 30m.
            'image' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'])
                    ->max(25 * 1024),
            ],
        ]);

        $path = app(ImageConverter::class)
            ->storeAsJpeg($request->file('image'), 'prescriptions/scans');

        $scan = PrescriptionScan::create([
            'user_id'           => $request->user()->id,
            'status'            => 'pending',
            'source_image_path' => $path,
        ]);

        ProcessPrescriptionScan::dispatch($scan->id);

        return redirect()->route('scans.show', $scan->id);
    }

    /**
     * Page de statut ("Lecture en cours…").
     * Si le scan est terminé ou en échec, bascule directement vers le formulaire.
     */
    public function show(Request $request, string $id): Response|RedirectResponse
    {
        $scan = PrescriptionScan::where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Scan terminé → vers le formulaire pré-rempli
        if ($scan->isDone()) {
            return redirect()->route('scans.form', $id);
        }

        // Scan en échec → vers formulaire vide + message
        if ($scan->isFailed()) {
            return redirect()
                ->route('prescriptions.create.manual')
                ->with('scan_error', $scan->error_message ?? 'Lecture impossible. Saisie manuelle.');
        }

        // En cours → page de polling
        return Inertia::render('Scans/Scanning', [
            'scanId'  => $scan->id,
            'message' => 'Lecture de l\'ordonnance en cours…',
        ]);
    }

    /**
     * Endpoint de polling (JSON) : retourne le statut courant du scan.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $scan = PrescriptionScan::where('user_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json([
            'status'        => $scan->status,
            'error_message' => $scan->error_message,
        ]);
    }

    /**
     * Formulaire pré-rempli à partir du draft IA.
     */
    public function form(Request $request, string $id): Response|RedirectResponse
    {
        $scan = PrescriptionScan::where('user_id', $request->user()->id)
            ->findOrFail($id);

        if (! $scan->isDone()) {
            return redirect()->route('scans.show', $id);
        }

        $draft = $scan->toDraft();

        if ($draft === null) {
            return redirect()
                ->route('prescriptions.create.manual')
                ->with('scan_error', 'Brouillon indisponible. Saisie manuelle.');
        }

        return Inertia::render('Prescriptions/Form', [
            'draft'     => $draft,
            'scanId'    => $scan->id,
            'imageUrl'  => $scan->source_image_path
                ? route('scans.image', $id)
                : null,
        ]);
    }

    /**
     * Sert l'image d'ordonnance originale (pour affichage dans le formulaire de validation).
     *
     * Authentification : via auth middleware (routes/web.php).
     * Autorisation     : where('user_id') ci-dessous garantit que l'utilisateur
     *                    ne peut accéder qu'à ses propres scans.
     */
    public function image(Request $request, string $id): BinaryFileResponse
    {
        $scan = PrescriptionScan::where('user_id', $request->user()->id)
            ->findOrFail($id);

        abort_unless($scan->source_image_path, 404);

        // resolveStoragePath() vérifie que le chemin reste sous la racine du disque
        // local (protection traversée de répertoire) et retourne le chemin absolu réel.
        $path = PrescriptionController::resolveStoragePath($scan->source_image_path);

        // response()->file() → Content-Disposition: inline + support Range (mobile/PWA).
        return response()->file($path, [
            'Content-Type' => mime_content_type($path) ?: 'image/jpeg',
        ]);
    }
}
