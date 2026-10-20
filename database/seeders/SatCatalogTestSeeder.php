<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SatCatalogTestSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('sat_tax_regimes')->insert([
            'code' => '601',
            'description' => 'Test tax regime',
            'applies_to_individual' => false,
            'applies_to_legal_entity' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sat_product_services')->insert([
            'code' => '81111500',
            'description' => 'Test product/service',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sat_units')->insert([
            'code' => 'E48',
            'name' => 'Unidad de servicio',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sat_tax_objects')->insert([
            'code' => '02',
            'description' => 'Test taxable object',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sat_payment_forms')->insert([
            'code' => '03',
            'description' => 'Test payment form',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sat_payment_methods')->insert([
            'code' => 'PUE',
            'description' => 'Test payment method',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sat_cfdi_uses')->insert([
            'code' => 'G03',
            'description' => 'Test CFDI use',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
