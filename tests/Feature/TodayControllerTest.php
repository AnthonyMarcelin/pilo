<?php

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionItemPhase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'owner']);
    $this->actingAs($this->user);
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeTerminatedItem(Prescription $rx, string $name, string $normalized): PrescriptionItem
{
    $item = PrescriptionItem::create([
        'prescription_id'            => $rx->id,
        'medication_name'            => $name,
        'medication_name_normalized' => $normalized,
        'intake_type'                => 'fixe',
        'morning'                    => 1.0,
        'posologie_brute'            => '1 cp matin pendant 7j',
        'duration_days'              => 7,
        'start_date'                 => Carbon::now()->subDays(30)->toDateString(),
        'end_date'                   => Carbon::now()->subDays(23)->toDateString(), // passée
    ]);

    PrescriptionItemPhase::create([
        'prescription_item_id' => $item->id,
        'phase_order'          => 1,
        'duration_days'        => 7,
        'morning'              => 1.0,
    ]);

    return $item;
}

// ─── Structure de base ────────────────────────────────────────────────────────

it('rend la page Aujourd\'hui avec les sections attendues', function () {
    $this->get(route('today'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->has('regimen.fixed')
            ->has('regimen.asNeeded')
            ->has('regimen.special')
            ->has('todayLabel')
            ->has('alerts')
        );
});

it('n\'expose que les données de l\'utilisateur connecté', function () {
    $other = User::factory()->create(['role' => 'owner']);
    $rx = Prescription::create([
        'user_id'      => $other->id,
        'prescribed_at' => Carbon::now()->toDateString(),
        'source_type'  => 'manual',
        'status'       => 'active',
    ]);
    makeTerminatedItem($rx, 'Médicament tiers', 'médicament tiers');

    $this->get(route('today'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('regimen.fixed.morning', [])
        );
});

// ─── Règle d'or n°4 — jamais masqué, toujours visible ────────────────────────

it('[règle d\'or n°4] item terminé dans ordonnance ACTIVE : visible avec isTerminated=true', function () {
    $rx = Prescription::create([
        'user_id'      => $this->user->id,
        'prescribed_at' => Carbon::now()->subDays(30)->toDateString(),
        'source_type'  => 'manual',
        'status'       => 'active',
    ]);

    makeTerminatedItem($rx, 'Amoxicilline 500 mg', 'amoxicilline');

    $this->get(route('today'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->has('regimen.fixed.morning', 1)                         // présent, pas vide
            ->where('regimen.fixed.morning.0.name', 'Amoxicilline 500 mg')
            ->where('regimen.fixed.morning.0.isTerminated', true)     // grisé, pas masqué
        );
});

it('[règle d\'or n°4] item d\'ordonnance TERMINÉE (status=terminated) : visible avec isTerminated=true', function () {
    $rx = Prescription::create([
        'user_id'      => $this->user->id,
        'prescribed_at' => Carbon::now()->subDays(30)->toDateString(),
        'source_type'  => 'manual',
        'status'       => 'terminated',
    ]);

    makeTerminatedItem($rx, 'Prednisolone 20 mg', 'prednisolone');

    $this->get(route('today'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->has('regimen.fixed.morning', 1)
            ->where('regimen.fixed.morning.0.name', 'Prednisolone 20 mg')
            ->where('regimen.fixed.morning.0.isTerminated', true)
        );
});

it('[règle d\'or n°4] item d\'ordonnance ARCHIVÉE : absent du regimen (archivage = action humaine validée)', function () {
    $rx = Prescription::create([
        'user_id'      => $this->user->id,
        'prescribed_at' => Carbon::now()->subDays(90)->toDateString(),
        'source_type'  => 'manual',
        'status'       => 'archived',
    ]);

    makeTerminatedItem($rx, 'Ibuprofène 400 mg', 'ibuprofène');

    $this->get(route('today'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->where('regimen.fixed.morning', [])  // absent : archivé = hors vue
        );
});

it('[règle d\'or n°4] item actif ET item terminé coexistent : les deux sont présents', function () {
    $rx = Prescription::create([
        'user_id'      => $this->user->id,
        'prescribed_at' => Carbon::now()->subDays(10)->toDateString(),
        'source_type'  => 'manual',
        'status'       => 'active',
    ]);

    // Item actif
    $active = PrescriptionItem::create([
        'prescription_id'            => $rx->id,
        'medication_name'            => 'Levothyrox 100 µg',
        'medication_name_normalized' => 'levothyrox',
        'intake_type'                => 'fixe',
        'morning'                    => 1.0,
        'posologie_brute'            => '1 cp/j 365j',
        'duration_days'              => 365,
        'start_date'                 => Carbon::now()->subDays(10)->toDateString(),
        'end_date'                   => Carbon::now()->addDays(355)->toDateString(),
    ]);
    PrescriptionItemPhase::create([
        'prescription_item_id' => $active->id,
        'phase_order'          => 1,
        'duration_days'        => 365,
        'morning'              => 1.0,
    ]);

    // Item terminé
    makeTerminatedItem($rx, 'Amoxicilline 500 mg', 'amoxicilline');

    $this->get(route('today'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->has('regimen.fixed.morning', 2)  // les deux présents
        );

    // Vérifier explicitement les isTerminated
    $response = $this->get(route('today'));
    $morning  = $response->viewData('page')['props']['regimen']['fixed']['morning'];

    $levo = collect($morning)->firstWhere('name', 'Levothyrox 100 µg');
    $amox = collect($morning)->firstWhere('name', 'Amoxicilline 500 mg');

    expect($levo['isTerminated'])->toBeFalse()
        ->and($amox['isTerminated'])->toBeTrue();
});
