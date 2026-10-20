<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyFiscalProfile;
use App\Models\FiscalSequence;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\SatCatalogTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceReadyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SatCatalogTestSeeder::class);
    }

    private function setupCompany(
        string $role = 'owner',
        bool $withProfile = true
    ): array {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $user->companies()->attach($company->id, [
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        if ($withProfile) {
            CompanyFiscalProfile::create([
                'company_id' => $company->id,
                'business_name' => $company->legal_name,
                'rfc' => $company->rfc,
                'tax_regime' => '601',
                'postal_code' => '97000',
                'active' => true,
            ]);
        }

        $sequence = FiscalSequence::create([
            'company_id' => $company->id,
            'document_type' => 'invoice',
            'series' => 'F',
            'current_number' => 0,
            'padding' => 6,
            'active' => true,
        ]);

        $client = Client::factory()
            ->for($company)
            ->create();

        $product = Product::factory()
            ->for($company)
            ->create([
                'tax_object' => '02',
                'unit_price' => '100.000000',
            ]);

        return [
            $user,
            $company,
            $client,
            $product,
            $sequence,
        ];
    }

    private function createDraft(
        Company $company,
        Client $client,
        Product $product,
        bool $withTaxes = true
    ): string {
        $taxes = $withTaxes ? [
            [
                'tax' => '002',
                'type' => 'transferred',
                'factor_type' => 'Tasa',
                'rate_or_quota' => '0.160000',
            ],
        ] : [];

        $response = $this->postJson(
            "/api/v1/companies/{$company->public_id}/invoices",
            [
                'client_id' => $client->public_id,
                'payment_form' => '03',
                'payment_method' => 'PUE',
                'cfdi_use' => 'G03',
                'items' => [
                    [
                        'product_id' => $product->public_id,
                        'quantity' => '1.000000',
                        'taxes' => $taxes,
                    ],
                ],
            ]
        );

        $response->assertCreated();

        return $response->json('data.id');
    }

    public function test_draft_can_be_marked_ready(): void
    {
        [
            $user,
            $company,
            $client,
            $product,
            $sequence,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createDraft(
            $company,
            $client,
            $product
        );

        $url = "/api/v1/companies/"
            . "{$company->public_id}/invoices/"
            . "{$invoiceId}/ready";

        $response = $this->postJson($url, [
            'series' => 'F',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.series', 'F')
            ->assertJsonPath('data.folio', 1);

        $this->assertDatabaseHas('invoices', [
            'public_id' => $invoiceId,
            'company_id' => $company->id,
            'status' => 'ready',
            'sequence_id' => $sequence->id,
            'series' => 'F',
            'folio' => 1,
        ]);

        $this->assertSame(
            1,
            $sequence->fresh()->current_number
        );
    }

    public function test_repeated_ready_request_does_not_consume_another_folio(): void
    {
        [
            $user,
            $company,
            $client,
            $product,
            $sequence,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createDraft(
            $company,
            $client,
            $product
        );

        $url = "/api/v1/companies/"
            . "{$company->public_id}/invoices/"
            . "{$invoiceId}/ready";

        $this->postJson($url, [
            'series' => 'F',
        ])->assertOk();

        $this->postJson($url, [
            'series' => 'F',
        ])
            ->assertOk()
            ->assertJsonPath('data.folio', 1);

        $this->assertSame(
            1,
            $sequence->fresh()->current_number
        );
    }

    public function test_missing_fiscal_profile_prevents_ready(): void
    {
        [
            $user,
            $company,
            $client,
            $product,
            $sequence,
        ] = $this->setupCompany(
            withProfile: false
        );

        $this->actingAs($user);

        $invoiceId = $this->createDraft(
            $company,
            $client,
            $product
        );

        $this->postJson(
            "/api/v1/companies/{$company->public_id}/invoices/{$invoiceId}/ready",
            ['series' => 'F']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company');

        $this->assertSame(
            0,
            $sequence->fresh()->current_number
        );

        $this->assertDatabaseHas('invoices', [
            'public_id' => $invoiceId,
            'status' => 'draft',
            'folio' => null,
        ]);
    }

    public function test_taxable_item_without_taxes_cannot_be_ready(): void
    {
        [
            $user,
            $company,
            $client,
            $product,
            $sequence,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createDraft(
            $company,
            $client,
            $product,
            withTaxes: false
        );

        $this->postJson(
            "/api/v1/companies/{$company->public_id}/invoices/{$invoiceId}/ready",
            ['series' => 'F']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(
            0,
            $sequence->fresh()->current_number
        );
    }

    
    public function test_viewer_cannot_mark_invoice_ready(): void
    {
        [
            $user,
            $company,
            $client,
            $product,
            $sequence,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createDraft(
            $company,
            $client,
            $product
        );

        $user->companies()->updateExistingPivot(
            $company->id,
            ['role' => 'viewer']
        );

        $this->postJson(
            "/api/v1/companies/{$company->public_id}/invoices/{$invoiceId}/ready",
            ['series' => 'F']
        )->assertForbidden();

        $this->assertSame(
            0,
            $sequence->fresh()->current_number
        );
    }

    public function test_invoice_cannot_be_prepared_under_another_company(): void
    {
        [
            $user,
            $companyA,
        ] = $this->setupCompany();

        [
            $otherUser,
            $companyB,
            $clientB,
            $productB,
            $sequenceB,
        ] = $this->setupCompany();

        /*
        * El usuario tiene acceso a ambas empresas.
        */
        $user->companies()->attach($companyB->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user);

        $invoiceId = $this->createDraft(
            $companyB,
            $clientB,
            $productB
        );

        /*
        * Intentamos utilizar la ruta de Empresa A
        * para preparar una factura de Empresa B.
        */
        $this->postJson(
            "/api/v1/companies/{$companyA->public_id}/invoices/{$invoiceId}/ready",
            ['series' => 'F']
        )->assertNotFound();

        $this->assertSame(
            0,
            $sequenceB->fresh()->current_number
        );

        $this->assertDatabaseHas('invoices', [
            'public_id' => $invoiceId,
            'company_id' => $companyB->id,
            'status' => 'draft',
            'folio' => null,
        ]);
    }

}
