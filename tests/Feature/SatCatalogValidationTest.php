<?php

namespace Tests\Feature;

use App\Rules\ValidSatCatalogCode;
use Database\Seeders\SatCatalogTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SatCatalogValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            SatCatalogTestSeeder::class
        );
    }

    public function test_active_sat_code_is_valid(): void
    {
        $validator = Validator::make(
            [
                'payment_method' => 'PUE',
            ],
            [
                'payment_method' => [
                    'required',
                    new ValidSatCatalogCode(
                        'sat_payment_methods'
                    ),
                ],
            ]
        );

        $this->assertFalse(
            $validator->fails()
        );
    }

    public function test_unknown_sat_code_is_invalid(): void
    {
        $validator = Validator::make(
            [
                'payment_method' => 'XXX',
            ],
            [
                'payment_method' => [
                    'required',
                    new ValidSatCatalogCode(
                        'sat_payment_methods'
                    ),
                ],
            ]
        );

        $this->assertTrue(
            $validator->fails()
        );
    }

    public function test_expired_sat_code_is_invalid(): void
    {
        \DB::table('sat_payment_methods')
            ->insert([
                'code' => 'OLD',
                'description' => 'Expired test code',
                'valid_from' => '2020-01-01',
                'valid_to' => '2020-12-31',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $validator = Validator::make(
            [
                'payment_method' => 'OLD',
            ],
            [
                'payment_method' => [
                    new ValidSatCatalogCode(
                        'sat_payment_methods'
                    ),
                ],
            ]
        );

        $this->assertTrue(
            $validator->fails()
        );
    }

    public function test_future_sat_code_is_invalid(): void
    {
        \DB::table('sat_payment_methods')
            ->insert([
                'code' => 'NEW',
                'description' => 'Future test code',
                'valid_from' => now()
                    ->addYear()
                    ->toDateString(),

                'valid_to' => null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $validator = Validator::make(
            [
                'payment_method' => 'NEW',
            ],
            [
                'payment_method' => [
                    new ValidSatCatalogCode(
                        'sat_payment_methods'
                    ),
                ],
            ]
        );

        $this->assertTrue(
            $validator->fails()
        );
    }



}