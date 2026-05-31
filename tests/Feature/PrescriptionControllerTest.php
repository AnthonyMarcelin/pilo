<?php

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionItemPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'owner']);
    $this->actingAs($this->user);
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fixeItem(array $override = []): array
{
    return array_merge([
        'medication_name' => 'Amoxicilline 500 mg',
        'dosage'          => '500 mg',
        'intake_type'     => 'fixe',
        'posologie_brute' => '1 gélule matin midi soir pendant 7 jours',
        'phases'          => [
            ['duration_days' => 7, 'morning' => 1, 'noon' => 1, 'evening' => 1, 'bedtime' => null],
        ],
    ], $override);
}

function siBesoinItem(array $override = []): array
{
    return array_merge([
        'medication_name' => 'Paracétamol 1000 mg',
        'dosage'          => '1000 mg',
        'intake_type'     => 'si_besoin',
        'posologie_brute' => '1 comprimé si douleur, max 4/jour',
        'condition'       => 'si douleur',
        'max_per_day'     => 4,
    ], $override);
}

// ─── Page de choix ────────────────────────────────────────────────────────────

it('renders the create choice page', function () {
    $this->get(route('prescriptions.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Prescriptions/Create'));
});

it('renders the manual form page with an empty draft', function () {
    $this->get(route('prescriptions.create.manual'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Prescriptions/Form')
            ->has('draft')
            ->where('draft.prescriber_name', null)
            ->has('draft.items', 1)
        );
});

// ─── Création — succès ────────────────────────────────────────────────────────

it('creates a simple fixe prescription', function () {
    $this->post(route('prescriptions.store'), [
        'prescriber_name' => 'Dr. Martin',
        'prescribed_at'   => '2026-05-01',
        'items'           => [fixeItem()],
    ])->assertRedirect(route('prescriptions.index'));

    assertDatabaseCount('prescriptions', 1);
    assertDatabaseCount('prescription_items', 1);
    assertDatabaseCount('prescription_item_phases', 1);

    $item = PrescriptionItem::first();
    expect($item->medication_name)->toBe('Amoxicilline 500 mg')
        ->and($item->medication_name_normalized)->toBe('amoxicilline')
        ->and($item->intake_type)->toBe('fixe')
        ->and($item->morning)->toEqual('1.00')
        ->and($item->duration_days)->toBe(7)
        ->and($item->end_date)->not->toBeNull();
});

it('creates a si_besoin prescription without phases', function () {
    $this->post(route('prescriptions.store'), [
        'items' => [siBesoinItem()],
    ])->assertRedirect(route('prescriptions.index'));

    $item = PrescriptionItem::first();
    expect($item->intake_type)->toBe('si_besoin')
        ->and($item->condition)->toBe('si douleur')
        ->and($item->max_per_day)->toEqual('4.00');

    assertDatabaseCount('prescription_item_phases', 0);
    expect($item->end_date)->toBeNull(); // si_besoin sans durée explicite
});

it('creates a degressive fixe prescription with multiple phases', function () {
    $this->post(route('prescriptions.store'), [
        'prescribed_at' => '2026-05-20',
        'items'         => [
            array_merge(fixeItem([
                'medication_name' => 'Prednisolone 20 mg',
                'dosage'          => '20 mg',
                'posologie_brute' => '3 cp/j 5j puis 2 cp/j 5j puis 1 cp/j 5j',
                'phases'          => [
                    ['duration_days' => 5, 'morning' => 3, 'noon' => null, 'evening' => null, 'bedtime' => null],
                    ['duration_days' => 5, 'morning' => 2, 'noon' => null, 'evening' => null, 'bedtime' => null],
                    ['duration_days' => 5, 'morning' => 1, 'noon' => null, 'evening' => null, 'bedtime' => null],
                ],
            ])),
        ],
    ])->assertRedirect(route('prescriptions.index'));

    assertDatabaseCount('prescription_item_phases', 3);

    $item = PrescriptionItem::with('phases')->first();
    expect($item->duration_days)->toBe(15) // somme des 3 paliers
        ->and($item->phases)->toHaveCount(3)
        ->and($item->phases[0]->morning)->toEqual('3.00')
        ->and($item->phases[1]->morning)->toEqual('2.00')
        ->and($item->phases[2]->morning)->toEqual('1.00');

    // end_date = start + 15 jours (borne exclusive)
    expect($item->end_date->toDateString())->toBe('2026-06-04');
});

it('creates an autre prescription', function () {
    $this->post(route('prescriptions.store'), [
        'items' => [[
            'medication_name' => 'Méthotrexate 2,5 mg',
            'intake_type'     => 'autre',
            'posologie_brute' => '1 cp par semaine le lundi',
        ]],
    ])->assertRedirect(route('prescriptions.index'));

    $item = PrescriptionItem::first();
    expect($item->intake_type)->toBe('autre');
    assertDatabaseCount('prescription_item_phases', 0);
});

it('normalizes medication name correctly', function () {
    $this->post(route('prescriptions.store'), [
        'items' => [fixeItem(['medication_name' => 'Paroxétine 20 mg'])],
    ]);

    expect(PrescriptionItem::first()->medication_name_normalized)->toBe('paroxétine');
});

it('uses prescribed_at as start_date when start_date is absent', function () {
    $this->post(route('prescriptions.store'), [
        'prescribed_at' => '2026-06-01',
        'items'         => [fixeItem()],
    ]);

    expect(PrescriptionItem::first()->start_date->toDateString())->toBe('2026-06-01');
});

it('creates multiple items in one prescription', function () {
    $this->post(route('prescriptions.store'), [
        'items' => [fixeItem(), siBesoinItem()],
    ])->assertRedirect(route('prescriptions.index'));

    assertDatabaseCount('prescriptions', 1);
    assertDatabaseCount('prescription_items', 2);
    assertDatabaseCount('prescription_item_phases', 1); // only fixe item
});

// ─── Validation ───────────────────────────────────────────────────────────────

it('rejects a submission with no items', function () {
    $this->post(route('prescriptions.store'), ['items' => []])
        ->assertSessionHasErrors('items');
});

it('rejects an item without medication_name', function () {
    $data = fixeItem();
    unset($data['medication_name']);

    $this->post(route('prescriptions.store'), ['items' => [$data]])
        ->assertSessionHasErrors('items.0.medication_name');
});

it('rejects an item without posologie_brute', function () {
    $data = fixeItem(['posologie_brute' => '']);

    $this->post(route('prescriptions.store'), ['items' => [$data]])
        ->assertSessionHasErrors('items.0.posologie_brute');
});

it('rejects a fixe item without phases', function () {
    $data = fixeItem();
    $data['phases'] = [];

    $this->post(route('prescriptions.store'), ['items' => [$data]])
        ->assertSessionHasErrors('items.0.phases');
});

it('rejects a phase with zero duration', function () {
    $data = fixeItem(['phases' => [
        ['duration_days' => 0, 'morning' => 1, 'noon' => null, 'evening' => null, 'bedtime' => null],
    ]]);

    $this->post(route('prescriptions.store'), ['items' => [$data]])
        ->assertSessionHasErrors('items.0.phases.0.duration_days');
});

it('requires authentication', function () {
    auth()->logout();

    $this->post(route('prescriptions.store'), ['items' => [fixeItem()]])
        ->assertRedirect(route('login'));
});

// ─── Index ────────────────────────────────────────────────────────────────────

it('index renders the prescriptions page with user data', function () {
    Prescription::factory()->create([
        'user_id'    => $this->user->id,
        'status'     => 'active',
        'prescribed_at' => '2026-05-01',
    ]);

    $this->get(route('prescriptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Prescriptions/Index')
            ->has('prescriptions', 1)
        );
});

// L'auto-terminate est désormais dans TerminateExpiredPrescriptions (job schedulé).
// GET /prescriptions est lecture pure — ne modifie jamais le statut.
it('index ne modifie pas le statut d\'une ordonnance expirée (lecture pure)', function () {
    $prescription = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'active',
    ]);

    PrescriptionItem::factory()->create([
        'prescription_id' => $prescription->id,
        'intake_type'     => 'fixe',
        'end_date'        => now()->subDay()->toDateString(),
    ]);

    $this->get(route('prescriptions.index'))->assertOk();

    // Statut inchangé — c'est le job TerminateExpiredPrescriptions qui transite.
    expect($prescription->fresh()->status)->toBe('active');
});

it('index does not expose prescriptions of another user', function () {
    $other = User::factory()->create();
    Prescription::factory()->create(['user_id' => $other->id]);

    $this->get(route('prescriptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('prescriptions', 0));
});

// ─── Show ─────────────────────────────────────────────────────────────────────

it('show renders prescription detail', function () {
    $prescription = Prescription::factory()->create([
        'user_id'        => $this->user->id,
        'prescriber_name' => 'Dr. Test',
    ]);

    $this->get(route('prescriptions.show', $prescription))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Prescriptions/Show')
            ->where('prescription.prescriber_name', 'Dr. Test')
        );
});

it('show returns 403 for another user prescription', function () {
    $other        = User::factory()->create();
    $prescription = Prescription::factory()->create(['user_id' => $other->id]);

    $this->get(route('prescriptions.show', $prescription))
        ->assertForbidden();
});

// ─── Archive ─────────────────────────────────────────────────────────────────

it('archive changes status to archived', function () {
    $prescription = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'active',
    ]);

    $this->post(route('prescriptions.archive', $prescription))
        ->assertRedirect(route('prescriptions.index'));

    expect($prescription->fresh()->status)->toBe('archived');
});

it('archive returns 403 for another user prescription', function () {
    $other        = User::factory()->create();
    $prescription = Prescription::factory()->create(['user_id' => $other->id, 'status' => 'active']);

    $this->post(route('prescriptions.archive', $prescription))
        ->assertForbidden();
});

it('archive returns 422 if already archived', function () {
    $prescription = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'archived',
    ]);

    $this->post(route('prescriptions.archive', $prescription))
        ->assertStatus(422);
});

// ─── Dédup ────────────────────────────────────────────────────────────────────

it('store flashes a duplicate warning when a medication already exists in an active prescription', function () {
    // Ordonnance existante avec le même médicament
    $existing = Prescription::factory()->create(['user_id' => $this->user->id, 'status' => 'active']);
    PrescriptionItem::factory()->create([
        'prescription_id'            => $existing->id,
        'medication_name'            => 'Paroxétine 20 mg',
        'medication_name_normalized' => 'paroxétine',
    ]);

    $response = $this->post(route('prescriptions.store'), [
        'items' => [fixeItem(['medication_name' => 'Paroxétine 20 mg'])],
    ]);

    $response->assertRedirect(route('prescriptions.index'));
    $response->assertSessionHas('duplicate_warnings');
    expect(session('duplicate_warnings'))->toHaveCount(1)
        ->and(session('duplicate_warnings')[0])->toContain('Paroxétine 20 mg');
});

it('store does not flag duplicate when medication is in an archived prescription', function () {
    $archived = Prescription::factory()->create(['user_id' => $this->user->id, 'status' => 'archived']);
    PrescriptionItem::factory()->create([
        'prescription_id'            => $archived->id,
        'medication_name'            => 'Amoxicilline 500 mg',
        'medication_name_normalized' => 'amoxicilline',
    ]);

    $response = $this->post(route('prescriptions.store'), [
        'items' => [fixeItem()],
    ]);

    $response->assertSessionMissing('duplicate_warnings');
});
