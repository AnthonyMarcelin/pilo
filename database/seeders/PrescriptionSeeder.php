<?php

namespace Database\Seeders;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionItemPhase;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PrescriptionSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        // ─────────────────────────────────────────────────────────────────
        // ORDONNANCE 1 — active, Dr. Martin, créée il y a 10 jours
        // ─────────────────────────────────────────────────────────────────
        $prescription1 = Prescription::create([
            'user_id'          => $user->id,
            'prescriber_name'  => 'Dr. Martin',
            'prescribed_at'    => Carbon::now()->subDays(10)->toDateString(),
            'source_type'      => 'manual',
            'status'           => 'active',
            'created_at'       => Carbon::now()->subDays(10),
            'updated_at'       => Carbon::now()->subDays(10),
        ]);

        // ITEM 1.1 — Levothyrox 100 µg — fixe simple (1 phase)
        // units_per_box=15 → stock_end_date ≈ 5j → alerte renouvellement déclenchée
        $item11 = PrescriptionItem::create([
            'prescription_id'          => $prescription1->id,
            'medication_name'          => 'Levothyrox 100 µg',
            'medication_name_normalized' => 'levothyrox',
            'dosage'                   => '100 µg',
            'intake_type'              => 'fixe',
            'morning'                  => 1.00,
            'noon'                     => null,
            'evening'                  => null,
            'bedtime'                  => null,
            'posologie_brute'          => '1 comprimé le matin à jeun',
            'duration_days'            => 365,
            'start_date'               => Carbon::now()->subDays(10)->toDateString(),
            'end_date'                 => Carbon::now()->subDays(10)->addDays(365)->toDateString(),
            'boxes_count'              => 1,
            'units_per_box'            => 15,
            // CIP originator — affiche l'encart BDPM "Indication officielle" sans mention générique
            'cip_code'                 => '3400935266486',
        ]);

        PrescriptionItemPhase::create([
            'prescription_item_id' => $item11->id,
            'phase_order'          => 1,
            'duration_days'        => 365,
            'morning'              => 1.00,
            'noon'                 => null,
            'evening'              => null,
            'bedtime'              => null,
        ]);

        // ITEM 1.2 — Gabapentine 100 mg — fixe multi-moments (1 phase)
        $item12 = PrescriptionItem::create([
            'prescription_id'          => $prescription1->id,
            'medication_name'          => 'Gabapentine 100 mg',
            'medication_name_normalized' => 'gabapentine',
            'dosage'                   => '100 mg',
            'intake_type'              => 'fixe',
            'morning'                  => 1.00,
            'noon'                     => 1.00,
            'evening'                  => 2.00,
            'bedtime'                  => null,
            'posologie_brute'          => '1 gélule matin, 1 gélule midi, 2 gélules soir',
            'duration_days'            => 90,
            'start_date'               => Carbon::now()->subDays(10)->toDateString(),
            'end_date'                 => Carbon::now()->subDays(10)->addDays(90)->toDateString(),
            'boxes_count'              => 1,
            'units_per_box'            => 100,
        ]);

        PrescriptionItemPhase::create([
            'prescription_item_id' => $item12->id,
            'phase_order'          => 1,
            'duration_days'        => 90,
            'morning'              => 1.00,
            'noon'                 => 1.00,
            'evening'              => 2.00,
            'bedtime'              => null,
        ]);

        // ITEM 1.3 — Prednisolone 20 mg — fixe TERMINÉE (5j, démarrée il y a 10j)
        // Démontre l'affichage grisé « terminé le X — à renouveler ? »
        $item13 = PrescriptionItem::create([
            'prescription_id'          => $prescription1->id,
            'medication_name'          => 'Prednisolone 20 mg',
            'medication_name_normalized' => 'prednisolone',
            'dosage'                   => '20 mg',
            'intake_type'              => 'fixe',
            'morning'                  => 2.00,
            'noon'                     => null,
            'evening'                  => null,
            'bedtime'                  => null,
            'posologie_brute'          => '2 comprimés le matin pendant 5 jours',
            'duration_days'            => 5,
            'start_date'               => Carbon::now()->subDays(10)->toDateString(),
            'end_date'                 => Carbon::now()->subDays(10)->addDays(5)->toDateString(),
        ]);

        PrescriptionItemPhase::create([
            'prescription_item_id' => $item13->id,
            'phase_order'          => 1,
            'duration_days'        => 5,
            'morning'              => 2.00,
            'noon'                 => null,
            'evening'              => null,
            'bedtime'              => null,
        ]);

        // ITEM 1.4 — Diazépam 10 mg — si_besoin (pas de phases)
        PrescriptionItem::create([
            'prescription_id'          => $prescription1->id,
            'medication_name'          => 'Diazépam 10 mg',
            'medication_name_normalized' => 'diazépam',
            'dosage'                   => '10 mg',
            'intake_type'              => 'si_besoin',
            'morning'                  => null,
            'noon'                     => null,
            'evening'                  => null,
            'bedtime'                  => null,
            'condition'                => 'selon besoin, si anxiété ou manifestations conversives',
            'max_per_day'              => 1.00,
            'posologie_brute'          => 'selon besoin, si anxiété ou manifestations conversives, 1 comprimé maximum par prise, 1 comprimé au maximum par jour',
            'duration_days'            => null,
            'start_date'               => Carbon::now()->subDays(10)->toDateString(),
            'end_date'                 => null,
        ]);

        // ─────────────────────────────────────────────────────────────────
        // ORDONNANCE 2 — active, Dr. Lefebvre, créée il y a 3 jours
        // ─────────────────────────────────────────────────────────────────
        $prescription2 = Prescription::create([
            'user_id'          => $user->id,
            'prescriber_name'  => 'Dr. Lefebvre',
            'prescribed_at'    => Carbon::now()->subDays(3)->toDateString(),
            'source_type'      => 'manual',
            'status'           => 'active',
            'created_at'       => Carbon::now()->subDays(3),
            'updated_at'       => Carbon::now()->subDays(3),
        ]);

        // ITEM 2.1 — Paroxétine 20 mg — fixe DÉGRESSIVE (2 phases)
        // Phase 1 : 7j à 2 cp/matin, toujours en cours (on est au jour 4)
        // Phase 2 : 15j à 1 cp/matin, commence dans 3 jours
        $item21 = PrescriptionItem::create([
            'prescription_id'          => $prescription2->id,
            'medication_name'          => 'Paroxétine 20 mg',
            'medication_name_normalized' => 'paroxétine',
            'dosage'                   => '20 mg',
            'intake_type'              => 'fixe',
            'morning'                  => 2.00,
            'noon'                     => null,
            'evening'                  => null,
            'bedtime'                  => null,
            'posologie_brute'          => '2 comprimés le matin pendant 7 jours, puis 1 comprimé le matin pendant 15 jours, puis arrêt',
            'duration_days'            => 22,
            'start_date'               => Carbon::now()->subDays(3)->toDateString(),
            'end_date'                 => Carbon::now()->subDays(3)->addDays(22)->toDateString(),
            // CIP générique → ref BDPM stocke le nom+indication de DEROXAT (l'originator)
            // → affiche la mention "Indication du médicament de référence (DEROXAT…), dont Paroxétine 20 mg est un générique"
            'cip_code'                 => '3400937890123',
        ]);

        PrescriptionItemPhase::create([
            'prescription_item_id' => $item21->id,
            'phase_order'          => 1,
            'duration_days'        => 7,
            'morning'              => 2.00,
            'noon'                 => null,
            'evening'              => null,
            'bedtime'              => null,
        ]);

        PrescriptionItemPhase::create([
            'prescription_item_id' => $item21->id,
            'phase_order'          => 2,
            'duration_days'        => 15,
            'morning'              => 1.00,
            'noon'                 => null,
            'evening'              => null,
            'bedtime'              => null,
        ]);

        // ─────────────────────────────────────────────────────────────────
        // ORDONNANCE 3 — terminated, Dr. Martin, ancienne ordonnance
        // ─────────────────────────────────────────────────────────────────
        $prescription3 = Prescription::create([
            'user_id'          => $user->id,
            'prescriber_name'  => 'Dr. Martin',
            'prescribed_at'    => Carbon::now()->subDays(60)->toDateString(),
            'source_type'      => 'manual',
            'status'           => 'terminated',
            'created_at'       => Carbon::now()->subDays(60),
            'updated_at'       => Carbon::now()->subDays(60),
        ]);

        // ITEM 3.1 — Amoxicilline 500 mg — fixe simple TERMINÉE (1 phase)
        // start: -60j, end: -53j — bien dans le passé
        $item31 = PrescriptionItem::create([
            'prescription_id'          => $prescription3->id,
            'medication_name'          => 'Amoxicilline 500 mg',
            'medication_name_normalized' => 'amoxicilline',
            'dosage'                   => '500 mg',
            'intake_type'              => 'fixe',
            'morning'                  => 1.00,
            'noon'                     => 1.00,
            'evening'                  => 1.00,
            'bedtime'                  => null,
            'posologie_brute'          => '1 gélule matin, midi et soir pendant 7 jours',
            'duration_days'            => 7,
            'start_date'               => Carbon::now()->subDays(60)->toDateString(),
            'end_date'                 => Carbon::now()->subDays(60)->addDays(7)->toDateString(),
            'boxes_count'              => 1,
            'units_per_box'            => 12,
        ]);

        PrescriptionItemPhase::create([
            'prescription_item_id' => $item31->id,
            'phase_order'          => 1,
            'duration_days'        => 7,
            'morning'              => 1.00,
            'noon'                 => 1.00,
            'evening'              => 1.00,
            'bedtime'              => null,
        ]);
    }
}
