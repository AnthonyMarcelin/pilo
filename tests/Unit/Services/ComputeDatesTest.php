<?php

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionItemPhase;
use App\Models\User;
use App\Services\ComputeDates;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeUser(): User
{
    return User::factory()->create();
}

function makeActiveRx(User $user, string $prescribedAt = '2026-05-28'): Prescription
{
    return Prescription::create([
        'user_id'         => $user->id,
        'prescriber_name' => 'Dr. Test',
        'prescribed_at'   => $prescribedAt,
        'source_type'     => 'manual',
        'status'          => 'active',
    ]);
}

function makeFixeItem(Prescription $rx, array $attrs = []): PrescriptionItem
{
    return PrescriptionItem::create(array_merge([
        'prescription_id'            => $rx->id,
        'medication_name'            => 'TestMed',
        'medication_name_normalized' => 'testmed',
        'intake_type'                => 'fixe',
        'posologie_brute'            => '1 cp matin',
        'morning'                    => 1.0,
        'start_date'                 => '2026-05-28',
    ], $attrs));
}

function attachPhase(PrescriptionItem $item, int $order, int $days, array $doses = []): PrescriptionItemPhase
{
    return PrescriptionItemPhase::create(array_merge([
        'prescription_item_id' => $item->id,
        'phase_order'          => $order,
        'duration_days'        => $days,
        'morning'              => null,
        'noon'                 => null,
        'evening'              => null,
        'bedtime'              => null,
    ], $doses));
}

// ─── endDate ────────────────────────────────────────────────────────────────

describe('ComputeDates::endDate', function () {
    it('retourne null quand start_date est absent', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        $item = makeFixeItem($rx, ['start_date' => null, 'duration_days' => 7]);
        attachPhase($item, 1, 7, ['morning' => 1.0]);

        expect((new ComputeDates)->endDate($item))->toBeNull();
    });

    it('retourne start + duration_days quand aucune phase (fallback)', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        $item = makeFixeItem($rx, ['start_date' => '2026-05-28', 'duration_days' => 7]);
        // Pas de phase créée (hors invariant — test défensif du fallback)
        $item->setRelation('phases', collect());

        $end = (new ComputeDates)->endDate($item);
        expect($end?->toDateString())->toBe('2026-06-04'); // 28 mai + 7 = 4 juin
    });

    it('retourne start + sum(phases) pour un item mono-palier', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        $item = makeFixeItem($rx, ['start_date' => '2026-05-28', 'duration_days' => 7]);
        attachPhase($item, 1, 7, ['morning' => 1.0]);
        $item->unsetRelation('phases');

        $end = (new ComputeDates)->endDate($item->fresh(['phases']));
        expect($end?->toDateString())->toBe('2026-06-04'); // 28 mai + 7 = 4 juin
    });

    it('retourne start + sum(phases) pour un item multi-paliers (somme, pas durée isolée)', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        // Paroxétine : phase 1 = 7j, phase 2 = 15j → total 22j
        $item = makeFixeItem($rx, ['start_date' => '2026-05-28', 'duration_days' => 22, 'morning' => 2.0]);
        attachPhase($item, 1, 7,  ['morning' => 2.0]);
        attachPhase($item, 2, 15, ['morning' => 1.0]);

        $end = (new ComputeDates)->endDate($item->fresh(['phases']));
        // 28 mai + 22 = 19 juin (borne exclusive)
        expect($end?->toDateString())->toBe('2026-06-19');
    });
});

// ─── stockEndDate ───────────────────────────────────────────────────────────

describe('ComputeDates::stockEndDate', function () {
    it('retourne null pour si_besoin', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        $item = PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Diazépam 10 mg',
            'medication_name_normalized' => 'diazépam',
            'intake_type'                => 'si_besoin',
            'posologie_brute'            => 'au besoin',
            'start_date'                 => '2026-05-28',
        ]);
        $item->setRelation('phases', collect());

        expect((new ComputeDates)->stockEndDate($item))->toBeNull();
    });

    it('utilise qsp_days en priorité', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        $item = makeFixeItem($rx, ['start_date' => '2026-05-28', 'qsp_days' => 30, 'boxes_count' => 1, 'units_per_box' => 100]);
        attachPhase($item, 1, 365, ['morning' => 1.0]);

        $stockEnd = (new ComputeDates)->stockEndDate($item->fresh(['phases']));
        expect($stockEnd?->toDateString())->toBe('2026-06-27'); // 28 mai + 30
    });

    it('calcule via boxes × unitsPerBox ÷ conso (mono-palier)', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        // 1 boîte de 30 cp, 1 cp/matin → 30 jours de stock
        $item = makeFixeItem($rx, ['start_date' => '2026-05-28', 'boxes_count' => 1, 'units_per_box' => 30]);
        attachPhase($item, 1, 365, ['morning' => 1.0]);

        $stockEnd = (new ComputeDates)->stockEndDate($item->fresh(['phases']));
        expect($stockEnd?->toDateString())->toBe('2026-06-27'); // 28 mai + 30
    });

    it('simule la conso phase par phase (multi-paliers — pas de moyenne)', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        // Stock : 21 cp (1 boîte × 21 unités)
        // Phase 1 : 7j × 2 cp/j = 14 cp consommés → 7 cp restants
        // Phase 2 : 1 cp/j → épuisé en 7 jours de phase 2
        // Avec une moyenne (29j ÷ 22 = 1,32 cp/j) on obtiendrait un résultat différent.
        // Simulation exacte : 7 + 7 = 14 jours → start + 14 = 11 juin
        $item = makeFixeItem($rx, [
            'start_date'    => '2026-05-28',
            'morning'       => 2.0,
            'boxes_count'   => 1,
            'units_per_box' => 21,
        ]);
        attachPhase($item, 1, 7,  ['morning' => 2.0]);
        attachPhase($item, 2, 15, ['morning' => 1.0]);

        $stockEnd = (new ComputeDates)->stockEndDate($item->fresh(['phases']));
        expect($stockEnd?->toDateString())->toBe('2026-06-11'); // 28 mai + 14
    });

    it('retourne null quand le stock couvre tout le traitement', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        // 1000 cp pour un traitement de 22j × max 2 cp/j = 44 cp → largement suffisant
        $item = makeFixeItem($rx, ['start_date' => '2026-05-28', 'duration_days' => 22, 'boxes_count' => 10, 'units_per_box' => 100]);
        attachPhase($item, 1, 7,  ['morning' => 2.0]);
        attachPhase($item, 2, 15, ['morning' => 1.0]);

        $stockEnd = (new ComputeDates)->stockEndDate($item->fresh(['phases']));
        expect($stockEnd)->toBeNull();
    });
});

// ─── isTerminated ───────────────────────────────────────────────────────────

describe('ComputeDates::isTerminated', function () {
    it('retourne false le DERNIER jour de traitement (J22 = veille de end_date)', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        $item = makeFixeItem($rx, ['start_date' => '2026-05-28', 'duration_days' => 22]);
        attachPhase($item, 1, 7,  ['morning' => 2.0]);
        attachPhase($item, 2, 15, ['morning' => 1.0]);
        $item = $item->fresh(['phases']);

        // J22 = 28 mai + 21 = 18 juin — encore actif
        expect((new ComputeDates)->isTerminated($item, Carbon::parse('2026-06-18')))->toBeFalse();
    });

    it('retourne true le PREMIER jour après traitement (J23 = end_date exclusive)', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        $item = makeFixeItem($rx, ['start_date' => '2026-05-28', 'duration_days' => 22]);
        attachPhase($item, 1, 7,  ['morning' => 2.0]);
        attachPhase($item, 2, 15, ['morning' => 1.0]);
        $item = $item->fresh(['phases']);

        // J23 = 28 mai + 22 = 19 juin — end_date exclusive, terminé
        expect((new ComputeDates)->isTerminated($item, Carbon::parse('2026-06-19')))->toBeTrue();
    });

    it('retourne false quand duration_days absent (traitement sans durée définie)', function () {
        $user = makeUser();
        $rx   = makeActiveRx($user);
        $item = PrescriptionItem::create([
            'prescription_id'            => $rx->id,
            'medication_name'            => 'Levothyrox',
            'medication_name_normalized' => 'levothyrox',
            'intake_type'                => 'fixe',
            'posologie_brute'            => '1 cp matin',
            'morning'                    => 1.0,
            'start_date'                 => '2026-05-28',
            // duration_days null → pas de end_date calculable
        ]);
        $item->setRelation('phases', collect());

        expect((new ComputeDates)->isTerminated($item, Carbon::parse('2099-01-01')))->toBeFalse();
    });
});
