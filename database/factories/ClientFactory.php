<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),

            'business_name' => fake()->company(),

            'rfc' => strtoupper(
                fake()->unique()->bothify('????######???')
            ),

            'tax_regime' => '601',

            'postal_code' => fake()->numerify('#####'),

            'email' => fake()->safeEmail(),

            'phone' => fake()->numerify('##########'),

            'status' => 'active',
        ];
    }
}
