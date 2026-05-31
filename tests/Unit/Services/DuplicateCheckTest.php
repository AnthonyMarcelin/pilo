<?php

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Services\DuplicateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function dupUser(): User
{
    return User::factory()->create();
}

function activeRxForUser(User $user): Prescription
{
    return Prescription::create([
        'user_id'         => $user->id,
        'prescriber_name' => 'Dr. Test',
        'prescribed_at'   => '2026-05-28',
        'source_type'     => 'manual',
        'status'          => 'active',
    ]);
}

function itemWithName(Prescription $rx, string $name, string $normalized): PrescriptionItem
{
    return PrescriptionItem::create([
        'prescription_id'            => $rx->id,
        'medication_name'            => $name,
        'medication_name_normalized' => $normalized,
        'intake_type'                => 'fixe',
        'posologie_brute'            => '1 cp/j',
        'morning'                    => 1.0,
        'start_date'                 => '2026-05-28',
    ]);
}

// ─── Tests ──────────────────────────────────────────────────────────────────

describe('DuplicateCheck', function () {

    it('retourne null quand aucun doublon', function () {
        $user = dupUser();
        $rx   = activeRxForUser($user);
        itemWithName($rx, 'Levothyrox 100 µg', 'levothyrox');

        $result = (new DuplicateCheck)->findDuplicate($user->id, 'paroxétine');

        expect($result)->toBeNull();
    });

    it('détecte un doublon exact sur le nom normalisé', function () {
        $user = dupUser();
        $rx   = activeRxForUser($user);
        $item = itemWithName($rx, 'Paroxétine 20 mg', 'paroxétine');

        $result = (new DuplicateCheck)->findDuplicate($user->id, 'paroxétine');

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($item->id)
            ->and($result->medication_name)->toBe('Paroxétine 20 mg');
    });

    it('ne détecte PAS un item d\'une ordonnance terminée', function () {
        $user = dupUser();
        $rxTerminated = Prescription::create([
            'user_id'         => $user->id,
            'prescriber_name' => 'Dr. Test',
            'prescribed_at'   => '2026-04-01',
            'source_type'     => 'manual',
            'status'          => 'terminated',
        ]);
        itemWithName($rxTerminated, 'Amoxicilline 500 mg', 'amoxicilline');

        $result = (new DuplicateCheck)->findDuplicate($user->id, 'amoxicilline');

        expect($result)->toBeNull();
    });

    it('n\'affecte pas les autres utilisateurs (scope user_id)', function () {
        $user1 = dupUser();
        $user2 = dupUser();
        $rx1   = activeRxForUser($user1);
        itemWithName($rx1, 'Paroxétine 20 mg', 'paroxétine');

        // user2 cherche → rien trouvé dans ses propres ordonnances
        $result = (new DuplicateCheck)->findDuplicate($user2->id, 'paroxétine');

        expect($result)->toBeNull();
    });

    it('exclut l\'item courant quand excludeItemId est fourni (édition)', function () {
        $user = dupUser();
        $rx   = activeRxForUser($user);
        $item = itemWithName($rx, 'Paroxétine 20 mg', 'paroxétine');

        // En édition : on ne veut pas se signaler comme doublon de soi-même
        $result = (new DuplicateCheck)->findDuplicate($user->id, 'paroxétine', excludeItemId: $item->id);

        expect($result)->toBeNull();
    });

    it('signalement doux uniquement — ne bloque pas, retourne simplement l\'item', function () {
        $user = dupUser();
        $rx1  = activeRxForUser($user);
        $rx2  = activeRxForUser($user); // Deuxième ordonnance active (légitimement possible)
        $item = itemWithName($rx1, 'Paroxétine 20 mg', 'paroxétine');

        // Le second item est en cours de création — on vérifie avant de l'insérer
        $found = (new DuplicateCheck)->findDuplicate($user->id, 'paroxétine');

        // Le service retourne le premier match trouvé — l'humain décide de la suite
        expect($found)->not->toBeNull()
            ->and($found->prescription->status)->toBe('active');
    });
});
