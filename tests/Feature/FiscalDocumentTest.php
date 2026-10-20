<?php

namespace Tests\Feature;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Enums\FiscalFileType;
use App\Infrastructure\Storage\StoreFiscalDocumentFile;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyFiscalProfile;
use App\Models\FiscalDocument;
use App\Models\FiscalSequence;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\SatCatalogTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FiscalDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SatCatalogTestSeeder::class);
    }

    private function setupCompany(): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $user->companies()->attach($company->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $profile = CompanyFiscalProfile::create([
            'company_id' => $company->id,
            'business_name' => $company->legal_name,
            'rfc' => $company->rfc,
            'tax_regime' => '601',
            'postal_code' => '97000',
            'active' => true,
        ]);

        FiscalSequence::create([
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
            $profile,
            $client,
            $product,
        ];
    }

    private function createInvoice(
        Company $company,
        Client $client,
        Product $product,
        bool $ready = true
    ): string {
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
                        'taxes' => [
                            [
                                'tax' => '002',
                                'type' => 'transferred',
                                'factor_type' => 'Tasa',
                                'rate_or_quota' => '0.160000',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $response->assertCreated();

        $invoiceId = $response->json('data.id');

        if ($ready) {
            $this->postJson(
                "/api/v1/companies/{$company->public_id}/invoices/{$invoiceId}/ready",
                ['series' => 'F']
            )->assertOk();
        }

        return $invoiceId;
    }

    private function documentUrl(
        Company $company,
        string $invoiceId
    ): string {
        return '/api/v1/companies/'
            ."{$company->public_id}/invoices/"
            ."{$invoiceId}/fiscal-document";
    }

    public function test_ready_invoice_can_create_fiscal_document(): void
    {
        [
            $user,
            $company,
            $profile,
            $client,
            $product,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createInvoice(
            $company,
            $client,
            $product
        );

        $response = $this->postJson(
            $this->documentUrl($company, $invoiceId)
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.cfdi_type', 'I')
            ->assertJsonPath('data.version', '4.0')
            ->assertJsonPath('data.uuid', null)
            ->assertJsonPath(
                'data.issuer.rfc',
                $profile->rfc
            );

        $invoice = Invoice::query()
            ->where('public_id', $invoiceId)
            ->firstOrFail();

        $this->assertDatabaseHas('fiscal_documents', [
            'company_id' => $company->id,
            'documentable_type' => 'invoice',
            'documentable_id' => $invoice->id,
            'status' => 'pending',
            'uuid' => null,
        ]);
    }

    public function test_repeated_request_returns_same_document(): void
    {
        [
            $user,
            $company,
            $profile,
            $client,
            $product,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createInvoice(
            $company,
            $client,
            $product
        );

        $url = $this->documentUrl(
            $company,
            $invoiceId
        );

        $first = $this->postJson($url)->assertCreated();

        $second = $this->postJson($url)->assertOk();

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id')
        );

        $this->assertDatabaseCount(
            'fiscal_documents',
            1
        );
    }

    public function test_draft_invoice_cannot_create_fiscal_document(): void
    {
        [
            $user,
            $company,
            $profile,
            $client,
            $product,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createInvoice(
            $company,
            $client,
            $product,
            ready: false
        );

        $this->postJson(
            $this->documentUrl($company, $invoiceId)
        )->assertStatus(409);

        $this->assertDatabaseCount(
            'fiscal_documents',
            0
        );
    }

    public function test_issuer_snapshot_does_not_change(): void
    {
        [
            $user,
            $company,
            $profile,
            $client,
            $product,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createInvoice(
            $company,
            $client,
            $product
        );

        $url = $this->documentUrl(
            $company,
            $invoiceId
        );

        $this->postJson($url)->assertCreated();

        $originalName = $profile->business_name;

        $profile->update([
            'business_name' => 'EMPRESA MODIFICADA',
        ]);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath(
                'data.issuer.name',
                $originalName
            );
    }

    public function test_invoice_document_is_isolated_by_company(): void
    {
        [
            $user,
            $companyA,
        ] = $this->setupCompany();

        [
            $otherUser,
            $companyB,
            $profileB,
            $clientB,
            $productB,
        ] = $this->setupCompany();

        $user->companies()->attach($companyB->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user);

        $invoiceId = $this->createInvoice(
            $companyB,
            $clientB,
            $productB
        );

        $this->postJson(
            $this->documentUrl($companyB, $invoiceId)
        )->assertCreated();

        $this->getJson(
            $this->documentUrl($companyA, $invoiceId)
        )->assertNotFound();

        $this->postJson(
            $this->documentUrl($companyA, $invoiceId)
        )->assertNotFound();

        $this->assertDatabaseCount(
            'fiscal_documents',
            1
        );
    }

    public function test_fiscal_file_is_stored_privately_with_checksum(): void
    {
        Storage::fake('local');

        [
            $user,
            $company,
            $profile,
            $client,
            $product,
        ] = $this->setupCompany();

        $this->actingAs($user);

        $invoiceId = $this->createInvoice(
            $company,
            $client,
            $product
        );

        $this->postJson(
            $this->documentUrl($company, $invoiceId)
        )->assertCreated();

        $document = FiscalDocument::query()->firstOrFail();

        app(CompanyContext::class)->set($company);

        /*
         * Contenido de prueba de almacenamiento.
         * NO representa un CFDI válido o timbrado.
         */
        $contents = '<test>Storage check</test>';

        $file = app(StoreFiscalDocumentFile::class)
            ->execute(
                $document,
                FiscalFileType::Xml,
                $contents
            );

        Storage::disk('local')->assertExists(
            $file->path
        );

        $this->assertSame(
            hash('sha256', $contents),
            $file->checksum
        );

        $this->assertSame(
            strlen($contents),
            $file->size
        );

        $this->assertSame(
            'local',
            $file->disk
        );
    }
}
