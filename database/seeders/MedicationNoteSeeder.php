<?php

namespace Database\Seeders;

use App\Models\MedicationNote;
use App\Models\User;
use Illuminate\Database\Seeder;

class MedicationNoteSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        // Note manuelle de l'utilisatrice — JAMAIS générée automatiquement.
        // Clé : medication_name_normalized = "paroxétine" couvre toutes les présentations.
        MedicationNote::updateOrCreate(
            [
                'user_id'                    => $user->id,
                'medication_name_normalized' => 'paroxétine',
            ],
            [
                'note' => 'pour mon anxiété et mes épisodes dépressifs',
            ]
        );
    }
}
