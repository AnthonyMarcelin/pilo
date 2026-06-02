<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success'            => fn () => $request->session()->get('success'),
                'duplicate_warnings' => fn () => $request->session()->get('duplicate_warnings', []),
                'scan_error'         => fn () => $request->session()->get('scan_error'),
            ],
            // Driver OCR actif — accessible dans tous les composants via usePage().props.ocr_driver.
            // Utilisé pour afficher la bannière "image envoyée à Mistral" sur Create.vue
            // et adapter le message d'attente sur Scanning.vue.
            'ocr_driver' => config('pilo.ocr_driver', 'local'),
        ];
    }
}
