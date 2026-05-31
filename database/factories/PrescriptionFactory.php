<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrescriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'           => User::factory(),
            'prescriber_name'   => 'Dr. ' . fake()->lastName(),
            'prescribed_at'     => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'source_type'       => 'manual',
            'source_image_path' => null,
            'status'            => 'active',
            'notes'             => null,
        ];
    }
}
