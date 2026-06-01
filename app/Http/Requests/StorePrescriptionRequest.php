<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StorePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Ordonnance ────────────────────────────────────────────────
            'prescriber_name'                  => ['nullable', 'string', 'max:255'],
            'prescribed_at'                    => ['nullable', 'date'],
            'notes'                            => ['nullable', 'string', 'max:2000'],
            // 'image' exclut HEIC — on liste les types explicitement (iPhones envoient du HEIC)
            'source_image'                     => ['nullable', File::types(['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'])->max(10240)],
            'scan_id'                          => ['nullable', 'string', 'exists:prescription_scans,id'],

            // ── Lignes ────────────────────────────────────────────────────
            'items'                            => ['required', 'array', 'min:1', 'max:20'],
            'items.*.medication_name'          => ['required', 'string', 'max:255'],
            'items.*.dosage'                   => ['nullable', 'string', 'max:100'],
            'items.*.intake_type'              => ['required', Rule::in(['fixe', 'si_besoin', 'autre'])],
            'items.*.posologie_brute'          => ['required', 'string', 'max:1000'],

            // si_besoin
            'items.*.condition'                => ['nullable', 'string', 'max:500'],
            'items.*.max_per_day'              => ['nullable', 'numeric', 'min:0', 'max:99.99'],

            // durée / stock (tous types)
            'items.*.duration_days'            => ['nullable', 'integer', 'min:1'],
            'items.*.qsp_days'                 => ['nullable', 'integer', 'min:1'],
            'items.*.start_date'               => ['nullable', 'date'],
            'items.*.boxes_count'              => ['nullable', 'integer', 'min:0'],

            // ── Paliers (fixe uniquement) ─────────────────────────────────
            // phases est toujours présent dans le payload (emptyPhase() pour non-fixe),
            // mais duration_days est nullable : la règle required est vérifiée dans
            // withValidator uniquement pour les items fixe qui ont des phases.
            'items.*.phases'                   => ['sometimes', 'array'],
            'items.*.phases.*.duration_days'   => ['nullable', 'integer', 'min:1', 'max:730'],
            'items.*.phases.*.morning'         => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'items.*.phases.*.noon'            => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'items.*.phases.*.evening'         => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'items.*.phases.*.bedtime'         => ['nullable', 'numeric', 'min:0', 'max:99.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ($this->input('items', []) as $i => $item) {
                if (($item['intake_type'] ?? '') !== 'fixe') {
                    continue; // pas de validation de phases pour si_besoin/autre
                }

                $phases = $item['phases'] ?? [];

                if (empty($phases)) {
                    $v->errors()->add(
                        "items.{$i}.phases",
                        'Un médicament fixe requiert au moins un palier posologique.',
                    );
                    continue;
                }

                // Pour les items fixe, duration_days est requis sur chaque palier
                foreach ($phases as $j => $phase) {
                    if (empty($phase['duration_days'])) {
                        $v->errors()->add(
                            "items.{$i}.phases.{$j}.duration_days",
                            'La durée du palier est obligatoire.',
                        );
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required'                             => 'Ajoutez au moins un médicament.',
            'items.min'                                  => 'Ajoutez au moins un médicament.',
            'items.*.medication_name.required'           => 'Le nom du médicament est obligatoire.',
            'items.*.intake_type.required'               => 'Le type de prise est obligatoire.',
            'items.*.posologie_brute.required'           => 'La posologie en texte est obligatoire.',
            'items.*.phases.*.duration_days.required'    => 'La durée du palier est obligatoire.',
            'items.*.phases.*.duration_days.min'         => 'La durée doit être d\'au moins 1 jour.',
        ];
    }
}
