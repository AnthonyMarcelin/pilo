<?php

use App\Jobs\ProcessPrescriptionScan;
use App\Models\PrescriptionScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create(['role' => 'owner']);
    $this->actingAs($this->user);
});

// ─── POST /scans ──────────────────────────────────────────────────────────────

it('store → accepte une image valide et dispatche ProcessPrescriptionScan', function () {
    $image = UploadedFile::fake()->image('ordonnance.jpg', 600, 800);

    $response = $this->post(route('scans.store'), ['image' => $image]);

    // Redirige vers la page de statut
    $response->assertRedirect();

    // Job dispatché
    Queue::assertPushed(ProcessPrescriptionScan::class);

    // Scan créé en base
    expect(PrescriptionScan::count())->toBe(1);
    $scan = PrescriptionScan::first();
    expect($scan->status)->toBe('pending')
        ->and($scan->user_id)->toBe($this->user->id)
        ->and($scan->source_image_path)->not->toBeNull();

    // Image stockée
    Storage::disk('local')->assertExists($scan->source_image_path);
});

it('store → accepte un PDF et dispatche ProcessPrescriptionScan', function () {
    $pdf = UploadedFile::fake()->create('ordonnance.pdf', 500, 'application/pdf');
    $response = $this->post(route('scans.store'), ['image' => $pdf]);

    $response->assertRedirect();
    Queue::assertPushed(ProcessPrescriptionScan::class);
    expect(PrescriptionScan::count())->toBe(1);
    Storage::disk('local')->assertExists(PrescriptionScan::first()->source_image_path);
});

it('store → rejette un format non supporté (txt)', function () {
    $txt = UploadedFile::fake()->create('note.txt', 10, 'text/plain');
    $this->post(route('scans.store'), ['image' => $txt])
        ->assertSessionHasErrors('image');
});

it('store → rejette si image absente', function () {
    $this->post(route('scans.store'), [])
        ->assertSessionHasErrors('image');
});

it('store → requiert authentification', function () {
    auth()->logout();
    $image = UploadedFile::fake()->image('x.jpg');
    $this->post(route('scans.store'), ['image' => $image])
        ->assertRedirect(route('login'));
});

// ─── GET /scans/{id}/status ───────────────────────────────────────────────────

it('status → retourne le statut JSON du scan', function () {
    $scan = PrescriptionScan::create([
        'user_id' => $this->user->id,
        'status'  => 'pending',
    ]);

    $this->get(route('scans.status', $scan->id))
        ->assertOk()
        ->assertJson(['status' => 'pending']);
});

it('status → 404 si scan d\'un autre utilisateur', function () {
    $other = User::factory()->create();
    $scan  = PrescriptionScan::create(['user_id' => $other->id, 'status' => 'pending']);

    $this->get(route('scans.status', $scan->id))->assertNotFound();
});

// ─── GET /scans/{id} ──────────────────────────────────────────────────────────

it('show → scan pending affiche Scans/Scanning', function () {
    $scan = PrescriptionScan::create(['user_id' => $this->user->id, 'status' => 'pending']);

    $this->get(route('scans.show', $scan->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Scans/Scanning')->where('scanId', $scan->id));
});

it('show → scan done redirige vers form', function () {
    $scan = PrescriptionScan::create([
        'user_id' => $this->user->id,
        'status'  => 'done',
        'draft'   => ['prescriber_name' => null, 'prescribed_at' => null, 'notes' => null, 'items' => []],
    ]);

    $this->get(route('scans.show', $scan->id))
        ->assertRedirect(route('scans.form', $scan->id));
});

it('show → scan failed redirige vers formulaire manuel avec message', function () {
    $scan = PrescriptionScan::create([
        'user_id'       => $this->user->id,
        'status'        => 'failed',
        'error_message' => 'Image illisible.',
    ]);

    $this->get(route('scans.show', $scan->id))
        ->assertRedirect(route('prescriptions.create.manual'));
});

// ─── GET /scans/{id}/form ─────────────────────────────────────────────────────

it('form → scan done affiche Form.vue avec draft pré-rempli', function () {
    $scan = PrescriptionScan::create([
        'user_id' => $this->user->id,
        'status'  => 'done',
        'draft'   => [
            'prescriber_name' => 'Dr. Test',
            'prescribed_at'   => '2026-06-01',
            'notes'           => null,
            'items'           => [
                [
                    'medication_name' => 'Amoxicilline 500 mg',
                    'intake_type'     => 'fixe',
                    'posologie_brute' => '1 gélule matin midi soir',
                    'phases'          => [
                        ['duration_days' => 7, 'morning' => 1, 'noon' => 1, 'evening' => 1, 'bedtime' => null],
                    ],
                ],
            ],
        ],
    ]);

    $this->get(route('scans.form', $scan->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Prescriptions/Form')
            ->has('draft')
            ->where('draft.prescriber_name', 'Dr. Test')
            ->has('draft.items', 1)
        );
});

it('form → scan pending redirige vers show', function () {
    $scan = PrescriptionScan::create(['user_id' => $this->user->id, 'status' => 'pending']);
    $this->get(route('scans.form', $scan->id))
        ->assertRedirect(route('scans.show', $scan->id));
});
