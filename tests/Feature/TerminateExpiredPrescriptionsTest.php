<?php

use App\Jobs\TerminateExpiredPrescriptions;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'owner']);
});

it('passe active→terminated quand tous les items ont end_date dépassée', function () {
    $rx = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'active',
    ]);

    PrescriptionItem::factory()->create([
        'prescription_id' => $rx->id,
        'intake_type'     => 'fixe',
        'end_date'        => now()->subDay()->toDateString(),
    ]);

    (new TerminateExpiredPrescriptions)->handle();

    expect($rx->fresh()->status)->toBe('terminated');
});

it('ne touche pas une ordonnance dont un item n\'a pas de end_date', function () {
    $rx = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'active',
    ]);

    PrescriptionItem::factory()->create([
        'prescription_id' => $rx->id,
        'intake_type'     => 'fixe',
        'end_date'        => null,
    ]);

    (new TerminateExpiredPrescriptions)->handle();

    expect($rx->fresh()->status)->toBe('active');
});

it('ne touche pas une ordonnance dont un item a end_date dans le futur', function () {
    $rx = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'active',
    ]);

    PrescriptionItem::factory()->create([
        'prescription_id' => $rx->id,
        'intake_type'     => 'fixe',
        'end_date'        => now()->addDays(10)->toDateString(),
    ]);

    (new TerminateExpiredPrescriptions)->handle();

    expect($rx->fresh()->status)->toBe('active');
});

it('ne touche pas une ordonnance déjà terminated ou archived', function () {
    $terminated = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'terminated',
    ]);
    $archived = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'archived',
    ]);

    foreach ([$terminated, $archived] as $rx) {
        PrescriptionItem::factory()->create([
            'prescription_id' => $rx->id,
            'intake_type'     => 'fixe',
            'end_date'        => now()->subDay()->toDateString(),
        ]);
    }

    (new TerminateExpiredPrescriptions)->handle();

    expect($terminated->fresh()->status)->toBe('terminated')
        ->and($archived->fresh()->status)->toBe('archived');
});

it('ne touche pas une ordonnance sans items', function () {
    $rx = Prescription::factory()->create([
        'user_id' => $this->user->id,
        'status'  => 'active',
    ]);

    (new TerminateExpiredPrescriptions)->handle();

    expect($rx->fresh()->status)->toBe('active');
});
