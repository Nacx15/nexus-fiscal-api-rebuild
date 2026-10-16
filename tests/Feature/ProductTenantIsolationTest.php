<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SatCatalogTestSeeder;

class ProductTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function attachUser(
        User $user,
        Company $company
    ): void {
        $user->companies()->attach(
            $company->id,
            [
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]
        );
    }

    public function test_user_can_create_product_for_own_company(): void
    {
        $user = User::factory()->create();

        $company = Company::factory()->create();

        $this->attachUser(
            $user,
            $company
        );

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$company->public_id}/products",
                [
                    'sku' => 'SERV-001',
                    'sat_product_code' => '81111500',
                    'sat_unit_code' => 'E48',
                    'unit_name' => 'Unidad de servicio',
                    'description' => 'Desarrollo de software',
                    'unit_price' => '1500.000000',
                    'default_tax_rate' => '0.160000',
                    'tax_object' => '02',
                ]
            );

        $response->assertSuccessful();

        $this->assertDatabaseHas(
            'products',
            [
                'company_id' => $company->id,
                'sku' => 'SERV-001',
            ]
        );
    }

    public function test_user_cannot_create_product_for_other_company(): void
    {
        $user = User::factory()->create();

        $ownCompany =
            Company::factory()->create();

        $otherCompany =
            Company::factory()->create();

        $this->attachUser(
            $user,
            $ownCompany
        );

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$otherCompany->public_id}/products",
                [
                    'sku' => 'SERV-001',
                    'sat_product_code' => '81111500',
                    'sat_unit_code' => 'E48',
                    'description' => 'Producto ajeno',
                    'unit_price' => '1000.000000',
                    'default_tax_rate' => '0.160000',
                    'tax_object' => '02',
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseMissing(
            'products',
            [
                'company_id' =>
                    $otherCompany->id,

                'sku' =>
                    'SERV-001',
            ]
        );
    }

    public function test_product_from_another_company_cannot_be_accessed_through_company_route(): void
    {
        $user = User::factory()->create();

        $companyA =
            Company::factory()->create();

        $companyB =
            Company::factory()->create();

        /*
         * El usuario tiene permiso
         * sobre ambas empresas.
         *
         * El test comprueba realmente
         * scoped bindings.
         */
        $this->attachUser(
            $user,
            $companyA
        );

        $this->attachUser(
            $user,
            $companyB
        );

        $productB = Product::factory()
            ->for($companyB)
            ->create();

        $response = $this
            ->actingAs($user)
            ->getJson(
                "/api/v1/companies/{$companyA->public_id}/products/{$productB->public_id}"
            );

        $response->assertNotFound();
    }

    public function test_same_sku_can_exist_in_different_companies(): void
    {
        $companyA =
            Company::factory()->create();

        $companyB =
            Company::factory()->create();

        Product::factory()->create([
            'company_id' => $companyA->id,
            'sku' => 'SERV-001',
        ]);

        Product::factory()->create([
            'company_id' => $companyB->id,
            'sku' => 'SERV-001',
        ]);

        $this->assertDatabaseCount(
            'products',
            2
        );
    }

    public function test_same_sku_cannot_exist_twice_in_same_company(): void
    {
        $user = User::factory()->create();

        $company =
            Company::factory()->create();

        $this->attachUser(
            $user,
            $company
        );

        Product::factory()->create([
            'company_id' => $company->id,
            'sku' => 'SERV-001',
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$company->public_id}/products",
                [
                    'sku' => 'SERV-001',
                    'sat_product_code' => '81111500',
                    'sat_unit_code' => 'E48',
                    'description' => 'Duplicado',
                    'unit_price' => '1000.000000',
                    'default_tax_rate' => '0.160000',
                    'tax_object' => '02',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sku',
            ]);

        $this->assertDatabaseCount(
            'products',
            1
        );
    }

    public function test_product_preserves_decimal_precision(): void
    {
        $company =
            Company::factory()->create();

        $product = Product::factory()->create([
            'company_id' => $company->id,

            'unit_price' =>
                '123.456789',

            'default_tax_rate' =>
                '0.160000',
        ]);

        $product->refresh();

        $this->assertSame(
            '123.456789',
            $product->unit_price
        );

        $this->assertSame(
            '0.160000',
            $product->default_tax_rate
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            SatCatalogTestSeeder::class
        );
    }
    
}