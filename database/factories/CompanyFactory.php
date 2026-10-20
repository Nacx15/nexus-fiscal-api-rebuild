<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'legal_name' => fake()->company(),
            'trade_name' => fake()->company(),
            'rfc' => strtoupper(fake()->bothify('???########??')),
            'status' => 'active',
            'timezone' => 'America/Mexico_City',
            'currency' => 'MXN',
        ];
    }
}
