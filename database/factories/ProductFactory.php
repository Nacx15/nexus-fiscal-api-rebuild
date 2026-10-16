<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),

            'sku' => strtoupper(
                fake()->unique()->bothify('SKU-#####')
            ),

            /*
             * Son valores de prueba.
             * Más adelante conectaremos catálogos SAT.
             */
            'sat_product_code' => '81111500',

            'sat_unit_code' => 'E48',

            'unit_name' => 'Unidad de servicio',

            'description' => fake()->sentence(),

            'unit_price' => '1000.000000',

            'default_tax_rate' => '0.160000',

            'tax_object' => '02',

            'status' => 'active',
        ];
    }
}
