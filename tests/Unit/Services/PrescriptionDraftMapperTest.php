<?php

use App\Services\Ocr\PrescriptionDraftMapper;

function mapperFixture(array $override = []): array
{
    return array_merge([
        'prescriber_name' => 'Dr. Martin',
        'prescribed_at'   => '2026-05-20',
        'items'           => [],
    ], $override);
}

function fixeJsonItem(array $override = []): array
{
    return array_merge([
        'medication_name' => 'Amoxicilline 500 mg',
        'dosage'          => '500 mg',
        'intake_type'     => 'fixe',
        'posologie_brute' => '1 gélule matin midi soir pendant 7 jours',
        'morning'         => 1,
        'noon'            => 1,
        'evening'         => 1,
        'bedtime'         => null,
        'duration_days'   => 7,
        'phases'          => [],
    ], $override);
}

// ─── Prescription header ──────────────────────────────────────────────────────

it('map → lit prescriber_name et prescribed_at', function () {
    $draft = (new PrescriptionDraftMapper())->map(mapperFixture());
    expect($draft->prescriber_name)->toBe('Dr. Martin')
        ->and($draft->prescribed_at)->toBe('2026-05-20');
});

it('map → null si prescriber_name absent', function () {
    $draft = (new PrescriptionDraftMapper())->map(mapperFixture(['prescriber_name' => null]));
    expect($draft->prescriber_name)->toBeNull();
});

// ─── Item fixe simple ─────────────────────────────────────────────────────────

it('map → item fixe sans phases → crée 1 phase synthétique', function () {
    $mapper = new PrescriptionDraftMapper();
    $draft  = $mapper->map(mapperFixture(['items' => [fixeJsonItem()]]));

    $item = $draft->items[0];
    expect($item->intake_type)->toBe('fixe')
        ->and($item->phases)->toHaveCount(1)
        ->and($item->phases[0]->morning)->toBe(1.0)
        ->and($item->phases[0]->duration_days)->toBe(7);
});

it('map → item fixe avec phases → phases priment sur dose directe', function () {
    $mapper = new PrescriptionDraftMapper();
    $json = fixeJsonItem([
        'morning' => 3,   // dose directe (sera ignorée au profit des phases)
        'phases'  => [
            ['duration_days' => 5, 'morning' => 3, 'noon' => null, 'evening' => null, 'bedtime' => null],
            ['duration_days' => 5, 'morning' => 2, 'noon' => null, 'evening' => null, 'bedtime' => null],
        ],
    ]);
    $draft = $mapper->map(mapperFixture(['items' => [$json]]));

    $item = $draft->items[0];
    expect($item->phases)->toHaveCount(2)
        ->and($item->phases[0]->morning)->toBe(3.0)
        ->and($item->phases[0]->duration_days)->toBe(5)
        ->and($item->phases[1]->morning)->toBe(2.0);
});

it('map → phase avec duration_days nul est ignorée', function () {
    $mapper = new PrescriptionDraftMapper();
    $json = fixeJsonItem([
        'phases' => [
            ['duration_days' => 7, 'morning' => 1],
            ['duration_days' => null, 'morning' => 0],  // invalide
        ],
    ]);
    $draft = $mapper->map(mapperFixture(['items' => [$json]]));
    expect($draft->items[0]->phases)->toHaveCount(1);
});

// ─── Item si_besoin ───────────────────────────────────────────────────────────

it('map → item si_besoin → pas de phases', function () {
    $mapper = new PrescriptionDraftMapper();
    $json = [
        'medication_name' => 'Diazépam 10 mg',
        'intake_type'     => 'si_besoin',
        'posologie_brute' => 'si douleur, max 1/j',
        'condition'       => 'si douleur',
        'max_per_day'     => 1,
    ];
    $draft = $mapper->map(mapperFixture(['items' => [$json]]));

    $item = $draft->items[0];
    expect($item->intake_type)->toBe('si_besoin')
        ->and($item->phases)->toBeEmpty()
        ->and($item->condition)->toBe('si douleur')
        ->and($item->max_per_day)->toBe(1.0);
});

// ─── Valeurs nulles / manquantes ──────────────────────────────────────────────

it('map → intake_type invalide → fallback sur autre', function () {
    $mapper = new PrescriptionDraftMapper();
    $json = [
        'medication_name' => 'Inconnu',
        'intake_type'     => 'bizarre',
        'posologie_brute' => 'test',
    ];
    $draft = $mapper->map(mapperFixture(['items' => [$json]]));
    expect($draft->items[0]->intake_type)->toBe('autre');
});

it('map → items absent → draft avec items vide', function () {
    $draft = (new PrescriptionDraftMapper())->map(['prescriber_name' => null]);
    expect($draft->items)->toBeEmpty();
});

it('map → medication_name vide → chaîne vide préservée (validation côté formulaire)', function () {
    $mapper = new PrescriptionDraftMapper();
    $json = ['medication_name' => '', 'intake_type' => 'autre', 'posologie_brute' => 'x'];
    $draft = $mapper->map(mapperFixture(['items' => [$json]]));
    expect($draft->items[0]->medication_name)->toBe('');
});

// ─── posologie_brute ──────────────────────────────────────────────────────────

it('map → posologie_brute est toujours préservée verbatim', function () {
    $mapper = new PrescriptionDraftMapper();
    $posologie = '2 cp le matin pendant 7 jours puis 1 cp 15 jours';
    $json = fixeJsonItem(['posologie_brute' => $posologie]);
    $draft = $mapper->map(mapperFixture(['items' => [$json]]));
    expect($draft->items[0]->posologie_brute)->toBe($posologie);
});
