<?php

namespace Database\Factories;

use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrescriptionItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'prescription_id'            => Prescription::factory(),
            'medication_name'            => fake()->word() . ' ' . fake()->numberBetween(5, 500) . ' mg',
            'medication_name_normalized' => fake()->word(),
            'dosage'                     => null,
            'intake_type'                => 'fixe',
            'morning'                    => 1,
            'noon'                       => null,
            'evening'                    => 1,
            'bedtime'                    => null,
            'condition'                  => null,
            'max_per_day'                => null,
            'posologie_brute'            => '1 cp matin et soir',
            'duration_days'              => 30,
            'qsp_days'                   => null,
            'start_date'                 => now()->toDateString(),
            'end_date'                   => null,
            'stock_units'                => null,
            'boxes_count'                => null,
            'units_per_box'              => null,
            'cip_code'                   => null,
            'stock_end_date'             => null,
        ];
    }
}
