<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\SatCatalogTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceCreationTest extends TestCase
{
   use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            SatCatalogTestSeeder::class
        );
    }

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

    public function test_invoice_draft_can_be_created(): void
    {
        $user = User::factory()->create();

        $company =
            Company::factory()->create();

        $this->attachUser(
            $user,
            $company
        );

        $client = Client::factory()
            ->for($company)
            ->create([
                'rfc' => 'ABC010101ABC',
                'business_name' =>
                    'CLIENTE ORIGINAL',
                'tax_regime' => '601',
                'postal_code' => '97000',
            ]);

        $product = Product::factory()
            ->for($company)
            ->create([
                'sat_product_code' =>
                    '81111500',

                'sat_unit_code' =>
                    'E48',

                'description' =>
                    'Desarrollo de software',

                'unit_price' =>
                    '100.000000',

                'tax_object' =>
                    '02',
            ]);

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$company->public_id}/invoices",
                [
                    'client_id' =>
                        $client->public_id,

                    'payment_form' => '03',

                    'payment_method' => 'PUE',

                    'cfdi_use' => 'G03',

                    'items' => [
                        [
                            'product_id' =>
                                $product->public_id,

                            'quantity' =>
                                '2.000000',

                            'unit_price' =>
                                '100.000000',

                            'discount' =>
                                '10.000000',

                            'taxes' => [
                                [
                                    'tax' => '002',

                                    'type' =>
                                        'transferred',

                                    'factor_type' =>
                                        'Tasa',

                                    'rate_or_quota' =>
                                        '0.160000',
                                ],
                            ],
                        ],
                    ],
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.status',
                'draft'
            )
            ->assertJsonPath(
                'data.subtotal',
                '200.000000'
            )
            ->assertJsonPath(
                'data.discount',
                '10.000000'
            )
            ->assertJsonPath(
                'data.transferred_taxes',
                '30.400000'
            )
            ->assertJsonPath(
                'data.total',
                '220.400000'
            );

        $this->assertDatabaseHas(
            'invoices',
            [
                'company_id' =>
                    $company->id,

                'client_id' =>
                    $client->id,

                'status' =>
                    'draft',

                'series' =>
                    null,

                'folio' =>
                    null,
            ]
        );
    }

    public function test_invoice_preserves_client_and_product_snapshots(): void
    {
        $user = User::factory()->create();

        $company =
            Company::factory()->create();

        $this->attachUser(
            $user,
            $company
        );

        $client = Client::factory()
            ->for($company)
            ->create([
                'business_name' =>
                    'CLIENTE ORIGINAL',

                'rfc' =>
                    'ABC010101ABC',

                'tax_regime' =>
                    '601',

                'postal_code' =>
                    '97000',
            ]);

        $product = Product::factory()
            ->for($company)
            ->create([
                'description' =>
                    'SERVICIO ORIGINAL',

                'unit_price' =>
                    '100.000000',

                'tax_object' =>
                    '02',
            ]);

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$company->public_id}/invoices",
                [
                    'client_id' =>
                        $client->public_id,

                    'payment_form' => '03',
                    'payment_method' => 'PUE',
                    'cfdi_use' => 'G03',

                    'items' => [
                        [
                            'product_id' =>
                                $product->public_id,

                            'quantity' =>
                                '1.000000',

                            'taxes' => [],
                        ],
                    ],
                ]
            );

        $response->assertCreated();

        $invoiceId =
            $response->json('data.id');

        $client->update([
            'business_name' =>
                'CLIENTE MODIFICADO',
        ]);

        $product->update([
            'description' =>
                'SERVICIO MODIFICADO',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(
                "/api/v1/companies/{$company->public_id}/invoices/{$invoiceId}"
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.receiver.name',
                'CLIENTE ORIGINAL'
            )
            ->assertJsonPath(
                'data.items.0.description',
                'SERVICIO ORIGINAL'
            );
    }

    public function test_invoice_from_other_company_is_not_accessible(): void
    {
        $user = User::factory()->create();

        $companyA =
            Company::factory()->create();

        $companyB =
            Company::factory()->create();

        $this->attachUser(
            $user,
            $companyA
        );

        $this->attachUser(
            $user,
            $companyB
        );

        $client = Client::factory()
            ->for($companyB)
            ->create();

        $product = Product::factory()
            ->for($companyB)
            ->create();

        $response = $this
            ->actingAs($user)
            ->postJson(
                "/api/v1/companies/{$companyB->public_id}/invoices",
                [
                    'client_id' =>
                        $client->public_id,

                    'payment_form' => '03',
                    'payment_method' => 'PUE',
                    'cfdi_use' => 'G03',

                    'items' => [
                        [
                            'product_id' =>
                                $product->public_id,

                            'quantity' =>
                                '1.000000',

                            'taxes' => [],
                        ],
                    ],
                ]
            );

        $response->assertCreated();

        $invoiceId =
            $response->json('data.id');

        $this
            ->actingAs($user)
            ->getJson(
                "/api/v1/companies/{$companyA->public_id}/invoices/{$invoiceId}"
            )
            ->assertNotFound();
    }

    
}
