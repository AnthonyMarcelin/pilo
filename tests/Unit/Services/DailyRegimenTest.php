<?php

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionItemPhase;
use App\Models\User;
use App\Services\DailyRegimen;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function rxUser(): User
{
    return User::factory()->create();
}

function activeRx(User $user, string $date = '2026-05-28'): Prescription
{
    return Prescription::create([
        'user_id'         => $user->id,
        'prescriber_name' => 'Dr. Test',
        'prescribed_at'   => $date,
        'source_type'     => 'manual',
        'status'          => 'active',
    ]);
}

/**
 * Paroxétine 20 mg dégressive :
 *   Phase 1 : 7j → M=2
 *   Phase 2 : 15j → M=1
 *   start_date = 2026-05-28
 *   end_date   = 2026-06-19 (exclusive, J23)
 *
 * Calendrier des phases :
 *   J1-J7   → 28 mai – 3 juin   (phase 1, M=2)
 *   J8-J22  → 4 juin – 18 juin  (phase 2, M=1)
 *   J23+    → 19 juin+           (terminé)
 */
function makeParoxetine(Prescription $rx): PrescriptionItem
{
    $item = PrescriptionItem::create([
        'prescription_id'            => $rx->id,
        'medication_name'            => 'Paroxétine 20 mg',
        'medication_name_normalized' => 'paroxétine',
        'dosage'                     => '20 mg',
        'intake_type'                => 'fixe',
        'morning'                    => 2.00,
        'posologie_brute'            => '2 cp matin 7j puis 1 cp matin 15j',
        'duration_days'              => 22,
        'start_date'                 => '2026-05-28',
        'end_date'                   => '2026-06-19',
    ]);
    PrescriptionItemPhase::create(['prescription_item_id' => $item->id, 'phase_order' => 1, 'duration_days' => 7,  'morning' => 2.00]);
    PrescriptionItemPhase::create(['prescription_item_id' => $item->id, 'phase_order' => 2, 'duration_days' => 15, 'morning' => 1.00]);
    return $item;
}

// ─── Transitions de phase (Paroxétine dégressive) ───────────────────────────
// C'est LE test critique : une erreur d'un jour = mauvaise dose affichée.

describe('DailyRegimen — transitions de phase (Paroxétine dégressive)', function () {

    it('J1 = start_date : phase 1, M=2, dayInPhase=1/7', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-05-28'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox)->not->toBeNull()
            ->and($parox->morningQty)->toBe(2.0)
            ->and($parox->isTerminated)->toBeFalse()
            ->and($parox->dayInPhase)->toBe(1)
            ->and($parox->phaseDurationDays)->toBe(7)
            ->and($parox->totalPhases)->toBe(2);
    });

    it('J4 = milieu de phase 1 : M=2, dayInPhase=4', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-05-31'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox->morningQty)->toBe(2.0)
            ->and($parox->isTerminated)->toBeFalse()
            ->and($parox->dayInPhase)->toBe(4);
    });

    it('J7 = DERNIER jour de phase 1 : encore M=2 (pas encore basculé)', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        // J7 = 28 mai + 6 = 3 juin
        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-03'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox->morningQty)->toBe(2.0)
            ->and($parox->isTerminated)->toBeFalse()
            ->and($parox->dayInPhase)->toBe(7)
            ->and($parox->phaseDurationDays)->toBe(7);
    });

    it('J8 = PREMIER jour de phase 2 (transition) : bascule M=1, dayInPhase=1/15', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        // J8 = 28 mai + 7 = 4 juin — LE test de transition
        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-04'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox->morningQty)->toBe(1.0)      // Basculé en phase 2
            ->and($parox->isTerminated)->toBeFalse()
            ->and($parox->dayInPhase)->toBe(1)      // Premier jour de la phase 2
            ->and($parox->phaseDurationDays)->toBe(15)
            ->and($parox->nextChangeNote)->toBeNull(); // Plus de palier suivant
    });

    it('J15 = milieu de phase 2 : M=1, dayInPhase=8', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        // J15 = 28 mai + 14 = 11 juin
        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-11'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox->morningQty)->toBe(1.0)
            ->and($parox->isTerminated)->toBeFalse()
            ->and($parox->dayInPhase)->toBe(8);
    });

    it('J22 = DERNIER jour de phase 2 : encore actif, M=1', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        // J22 = 28 mai + 21 = 18 juin
        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-18'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox->morningQty)->toBe(1.0)
            ->and($parox->isTerminated)->toBeFalse()
            ->and($parox->dayInPhase)->toBe(15); // Dernier jour de la phase 2
    });

    it('J23 = end_date (exclusive) : isTerminated, plus de dose', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        // J23 = 28 mai + 22 = 19 juin = end_date exclusive
        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-19'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox)->not->toBeNull()    // Toujours présent (jamais masqué)
            ->and($parox->isTerminated)->toBeTrue()
            ->and($parox->morningQty)->toBe(0.0);
    });

    it('J30 = bien après la fin : toujours isTerminated, toujours visible', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-27'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox)->not->toBeNull()
            ->and($parox->isTerminated)->toBeTrue();
    });

    it('nextChangeNote est non-null en phase 1 (prochain palier = phase 2)', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-05-31'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        // La note doit mentionner la date de bascule (4 juin) et une dose réduite
        expect($parox->nextChangeNote)->toBeString()
            ->and($parox->nextChangeNote)->toContain('↓')
            ->and($parox->nextChangeNote)->toContain('4');  // "4 juin"
    });

    it('nextChangeNote est null en phase 2 (pas de palier suivant)', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-04'));
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox->nextChangeNote)->toBeNull();
    });
});

// ─── Fixe simple constant (Levothyrox) ──────────────────────────────────────

describe('DailyRegimen — fixe simple (Levothyrox 1 cp/matin, 365j)', function () {

    it('dose constante en début de traitement', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Levothyrox 100 µg',
            'medication_name_normalized' => 'levothyrox',
            'intake_type'                => 'fixe',
            'morning'                    => 1.0,
            'posologie_brute'            => '1 cp matin à jeun',
            'duration_days'              => 365,
            'start_date'                 => '2026-05-28',
            'end_date'                   => '2027-05-28',
        ]);
        PrescriptionItemPhase::create(['prescription_item_id' => PrescriptionItem::latest()->first()->id, 'phase_order' => 1, 'duration_days' => 365, 'morning' => 1.0]);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-15'));
        $levo   = collect($result->fixed['morning'])->first(fn ($e) => str_contains($e->medicationName, 'Levothyrox'));

        expect($levo->morningQty)->toBe(1.0)
            ->and($levo->isTerminated)->toBeFalse()
            ->and($levo->totalPhases)->toBe(1)
            ->and($levo->hasTapering())->toBeFalse();
    });

    it('dose constante bien après le milieu du traitement', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        $item = PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Levothyrox 100 µg',
            'medication_name_normalized' => 'levothyrox',
            'intake_type'                => 'fixe',
            'morning'                    => 1.0,
            'posologie_brute'            => '1 cp matin à jeun',
            'duration_days'              => 365,
            'start_date'                 => '2026-05-28',
            'end_date'                   => '2027-05-28',
        ]);
        PrescriptionItemPhase::create(['prescription_item_id' => $item->id, 'phase_order' => 1, 'duration_days' => 365, 'morning' => 1.0]);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2027-01-01'));
        $levo   = collect($result->fixed['morning'])->first(fn ($e) => str_contains($e->medicationName, 'Levothyrox'));

        expect($levo->morningQty)->toBe(1.0)
            ->and($levo->isTerminated)->toBeFalse();
    });
});

// ─── Fixe multi-moments (Gabapentine 1/1/2) ─────────────────────────────────

describe('DailyRegimen — fixe multi-moments (Gabapentine 1/midi/1/soir/2)', function () {

    it('apparaît dans morning, noon ET evening avec les bonnes doses', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        $item = PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Gabapentine 100 mg',
            'medication_name_normalized' => 'gabapentine',
            'intake_type'                => 'fixe',
            'morning'                    => 1.0,
            'noon'                       => 1.0,
            'evening'                    => 2.0,
            'posologie_brute'            => '1 gél matin, 1 gél midi, 2 gél soir',
            'duration_days'              => 90,
            'start_date'                 => '2026-05-28',
            'end_date'                   => '2026-08-26',
        ]);
        PrescriptionItemPhase::create(['prescription_item_id' => $item->id, 'phase_order' => 1, 'duration_days' => 90, 'morning' => 1.0, 'noon' => 1.0, 'evening' => 2.0]);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-01'));

        $matin = collect($result->fixed['morning'])->first(fn ($e) => str_contains($e->medicationName, 'Gabapentine'));
        $midi  = collect($result->fixed['noon']   )->first(fn ($e) => str_contains($e->medicationName, 'Gabapentine'));
        $soir  = collect($result->fixed['evening'])->first(fn ($e) => str_contains($e->medicationName, 'Gabapentine'));

        expect($matin)->not->toBeNull()->and($matin->morningQty)->toBe(1.0)
            ->and($midi)->not->toBeNull()->and($midi->noonQty)->toBe(1.0)
            ->and($soir)->not->toBeNull()->and($soir->eveningQty)->toBe(2.0);
    });

    it('n\'apparaît PAS dans bedtime (aucune dose au coucher)', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        $item = PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Gabapentine 100 mg',
            'medication_name_normalized' => 'gabapentine',
            'intake_type'                => 'fixe',
            'morning'                    => 1.0,
            'noon'                       => 1.0,
            'evening'                    => 2.0,
            'posologie_brute'            => '1 gél matin, 1 gél midi, 2 gél soir',
            'duration_days'              => 90,
            'start_date'                 => '2026-05-28',
        ]);
        PrescriptionItemPhase::create(['prescription_item_id' => $item->id, 'phase_order' => 1, 'duration_days' => 90, 'morning' => 1.0, 'noon' => 1.0, 'evening' => 2.0]);

        $result  = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-06-01'));
        $coucher = collect($result->fixed['bedtime'])->first(fn ($e) => str_contains($e->medicationName, 'Gabapentine'));

        expect($coucher)->toBeNull();
    });
});

// ─── si_besoin (Diazépam) ───────────────────────────────────────────────────

describe('DailyRegimen — si_besoin (Diazépam)', function () {

    it('est dans asNeeded, JAMAIS dans la grille des 4 moments', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Diazépam 10 mg',
            'medication_name_normalized' => 'diazépam',
            'intake_type'                => 'si_besoin',
            'condition'                  => 'si anxiété ou manifestations conversives',
            'max_per_day'                => 1.0,
            'posologie_brute'            => 'selon besoin, si anxiété, 1 cp max/j',
            'start_date'                 => '2026-05-28',
        ]);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-05-31'));

        $diaz = collect($result->asNeeded)->first(fn ($e) => str_contains($e->medicationName, 'Diazépam'));

        expect($diaz)->not->toBeNull()
            ->and($diaz->condition)->toBe('si anxiété ou manifestations conversives')
            ->and($diaz->maxPerDay)->toBe(1.0)
            ->and($diaz->isTerminated)->toBeFalse();

        // Vérification exhaustive : absent de tous les moments
        foreach (['morning', 'noon', 'evening', 'bedtime'] as $moment) {
            $found = collect($result->fixed[$moment])->first(fn ($e) => str_contains($e->medicationName, 'Diazépam'));
            expect($found)->toBeNull("Diazépam ne doit pas être dans le slot {$moment}");
        }
    });

    it('n\'a pas de stock estimé (pas dans ComputeDates::stockEndDate)', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        $item = PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Diazépam 10 mg',
            'medication_name_normalized' => 'diazépam',
            'intake_type'                => 'si_besoin',
            'posologie_brute'            => 'au besoin',
            'start_date'                 => '2026-05-28',
            'boxes_count'                => 1,
            'units_per_box'              => 40,
        ]);
        $item->setRelation('phases', collect());

        expect((new \App\Services\ComputeDates)->stockEndDate($item))->toBeNull();
    });
});

// ─── Terminé non masqué (règle d'or) ────────────────────────────────────────

describe('DailyRegimen — item terminé (grisé, jamais masqué)', function () {

    it('un item terminé dans une ordonnance active reste visible avec isTerminated=true', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');

        // Amoxicilline 7j déjà terminée depuis longtemps (dans une ordo active)
        $item = PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Amoxicilline 500 mg',
            'medication_name_normalized' => 'amoxicilline',
            'intake_type'                => 'fixe',
            'morning'                    => 1.0,
            'noon'                       => 1.0,
            'evening'                    => 1.0,
            'posologie_brute'            => '1 gél matin, midi et soir pendant 7j',
            'duration_days'              => 7,
            'start_date'                 => '2026-05-01',
            'end_date'                   => '2026-05-08',
        ]);
        PrescriptionItemPhase::create(['prescription_item_id' => $item->id, 'phase_order' => 1, 'duration_days' => 7, 'morning' => 1.0, 'noon' => 1.0, 'evening' => 1.0]);

        // On consulte le regimen bien après la fin du traitement
        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-05-31'));

        $amox = collect($result->fixed['morning'])->first(fn ($e) => str_contains($e->medicationName, 'Amoxicilline'));

        expect($amox)->not->toBeNull()    // Non masqué
            ->and($amox->isTerminated)->toBeTrue()
            ->and($amox->morningQty)->toBe(0.0); // Dose zéro car terminé
    });

    it('un item d\'une ordonnance TERMINÉE n\'apparaît pas dans le regimen du jour', function () {
        $user = rxUser();

        // Ordonnance terminée (status = terminated)
        $rxTerminated = Prescription::create([
            'user_id'         => $user->id,
            'prescriber_name' => 'Dr. Ancien',
            'prescribed_at'   => '2026-04-01',
            'source_type'     => 'manual',
            'status'          => 'terminated',
        ]);
        $item = PrescriptionItem::create([
            'prescription_id'            => $rxTerminated->id,
            'medication_name'            => 'Amoxicilline 500 mg',
            'medication_name_normalized' => 'amoxicilline',
            'intake_type'                => 'fixe',
            'morning'                    => 1.0,
            'posologie_brute'            => '1 gél matin',
            'duration_days'              => 7,
            'start_date'                 => '2026-04-01',
            'end_date'                   => '2026-04-08',
        ]);
        PrescriptionItemPhase::create(['prescription_item_id' => $item->id, 'phase_order' => 1, 'duration_days' => 7, 'morning' => 1.0]);

        // Ordonnance active par ailleurs
        $rxActive = activeRx($user, '2026-05-28');
        $activeItem = PrescriptionItem::create([
            'prescription_id'            => $rxActive->id,
            'medication_name'            => 'Levothyrox 100 µg',
            'medication_name_normalized' => 'levothyrox',
            'intake_type'                => 'fixe',
            'morning'                    => 1.0,
            'posologie_brute'            => '1 cp matin',
            'duration_days'              => 365,
            'start_date'                 => '2026-05-28',
        ]);
        PrescriptionItemPhase::create(['prescription_item_id' => $activeItem->id, 'phase_order' => 1, 'duration_days' => 365, 'morning' => 1.0]);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-05-31'));

        // L'Amoxicilline de l'ordo terminée NE doit PAS apparaître
        $amox = collect([...$result->fixed['morning'], ...$result->fixed['noon'], ...$result->fixed['evening'], ...$result->fixed['bedtime']])
            ->first(fn ($e) => str_contains($e->medicationName, 'Amoxicilline'));
        expect($amox)->toBeNull();

        // Le Levothyrox de l'ordo active DOIT apparaître
        $levo = collect($result->fixed['morning'])->first(fn ($e) => str_contains($e->medicationName, 'Levothyrox'));
        expect($levo)->not->toBeNull();
    });
});

// ─── Item pas encore commencé ────────────────────────────────────────────────

describe('DailyRegimen — item pas encore démarré', function () {

    it('un item dont start_date est dans le futur n\'apparaît pas', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        $item = PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'FuturMed 10 mg',
            'medication_name_normalized' => 'futurmed',
            'intake_type'                => 'fixe',
            'morning'                    => 1.0,
            'posologie_brute'            => '1 cp matin',
            'duration_days'              => 30,
            'start_date'                 => '2026-06-10',
            'end_date'                   => '2026-07-10',
        ]);
        PrescriptionItemPhase::create(['prescription_item_id' => $item->id, 'phase_order' => 1, 'duration_days' => 30, 'morning' => 1.0]);

        $result = (new DailyRegimen($user->id))->forDate(Carbon::parse('2026-05-31'));
        $found  = collect([...$result->fixed['morning']])->first(fn ($e) => str_contains($e->medicationName, 'FuturMed'));

        expect($found)->toBeNull();
    });
});

// ─── travelTo() — pattern déterministe ──────────────────────────────────────
// Démontre que le service réagit à Carbon::now() via travelTo quand
// le code appelant utilise now() plutôt qu'une date explicite.

describe('DailyRegimen — pattern travelTo() avec now()', function () {

    afterEach(fn () => Carbon::setTestNow(null));

    it('travelTo() gèle now() → résultat déterministe sans dépendre de la date réelle', function () {
        $user = rxUser();
        $rx   = activeRx($user, '2026-05-28');
        makeParoxetine($rx);

        // Gèle "aujourd'hui" au 4 juin (J8 = transition phase 1→2)
        $this->travelTo('2026-06-04');

        // L'appelant utilise now() — la date gelée est propagée
        $result = (new DailyRegimen($user->id))->forDate(now()->startOfDay());
        $parox  = collect($result->fixed['morning'])->first(fn ($e) => $e->medicationName === 'Paroxétine 20 mg');

        expect($parox->morningQty)->toBe(1.0)   // Phase 2
            ->and($parox->dayInPhase)->toBe(1);  // Premier jour de la phase 2
    });
});
