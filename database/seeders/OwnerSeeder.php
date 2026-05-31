<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('OWNER_EMAIL', 'owner@example.com');
        $password = env('OWNER_PASSWORD', 'changeme123');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'                 => 'Propriétaire',
                'password'             => Hash::make($password),
                'role'                 => 'owner',
                'must_change_password' => false, // true en prod premier démarrage, false pour le dev/reseed
                'email_verified_at'    => now(),
            ]
        );
    }
}
