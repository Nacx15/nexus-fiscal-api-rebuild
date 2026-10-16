<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SatCatalogTestSeeder;

class ClientTenantIsolationTest extends TestCase
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

    public function test_user_can_create_client_for_own_company(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $this->attachUser($user, $company);

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$company->public_id}/clients",
                [
                    'business_name' => 'Cliente Demo SA de CV',
                    'rfc' => 'ABC010101ABC',
                    'tax_regime' => '601',
                    'postal_code' => '97000',
                    'email' => 'cliente@example.com',
                ]
            );

        $response->assertSuccessful();

        $this->assertDatabaseHas(
            'clients',
            [
                'company_id' => $company->id,
                'rfc' => 'ABC010101ABC',
            ]
        );
    }

    public function test_user_cannot_create_client_for_other_company(): void
    {
        $user = User::factory()->create();

        $ownCompany = Company::factory()->create();

        $otherCompany = Company::factory()->create();

        $this->attachUser(
            $user,
            $ownCompany
        );

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$otherCompany->public_id}/clients",
                [
                    'business_name' => 'Cliente Invalido',
                    'rfc' => 'ABC010101ABC',
                    'tax_regime' => '601',
                    'postal_code' => '97000',
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseMissing(
            'clients',
            [
                'company_id' => $otherCompany->id,
                'rfc' => 'ABC010101ABC',
            ]
        );
    }

    public function test_client_from_another_company_cannot_be_accessed_through_company_route(): void
    {
        $user = User::factory()->create();

        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        /*
         * El usuario pertenece a ambas empresas.
         *
         * Así demostramos que no es el middleware
         * de membresía el que está ocultando
         * el problema.
         */
        $this->attachUser($user, $companyA);
        $this->attachUser($user, $companyB);

        $clientB = Client::factory()
            ->for($companyB)
            ->create();

        $response = $this
            ->actingAs($user)
            ->getJson(
                "/api/v1/companies/{$companyA->public_id}/clients/{$clientB->public_id}"
            );

        $response->assertNotFound();
    }

    public function test_same_rfc_can_exist_in_different_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        Client::factory()->create([
            'company_id' => $companyA->id,
            'rfc' => 'ABC010101ABC',
        ]);

        Client::factory()->create([
            'company_id' => $companyB->id,
            'rfc' => 'ABC010101ABC',
        ]);

        $this->assertDatabaseCount(
            'clients',
            2
        );
    }

    public function test_same_rfc_cannot_be_created_twice_in_same_company(): void
    {
        $user = User::factory()->create();

        $company = Company::factory()->create();

        $this->attachUser($user, $company);

        Client::factory()->create([
            'company_id' => $company->id,
            'rfc' => 'ABC010101ABC',
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$company->public_id}/clients",
                [
                    'business_name' => 'Duplicado',
                    'rfc' => 'ABC010101ABC',
                    'tax_regime' => '601',
                    'postal_code' => '97000',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'rfc',
            ]);

        $this->assertDatabaseCount(
            'clients',
            1
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