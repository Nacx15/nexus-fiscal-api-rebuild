<?php

namespace Tests\Feature;

use App\Application\Company\CompanyContext;
use App\Application\Fiscal\BeginStampAttempt;
use App\Application\Fiscal\MarkStampAttemptDispatching;
use App\Application\Fiscal\MarkStampAttemptUnknown;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Infrastructure\Fiscal\Storage\PrivateStampXmlStore;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyFiscalProfile;
use App\Models\FiscalDocument;
use App\Models\FiscalSequence;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SatCatalogTestSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class FiscalStampAttemptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SatCatalogTestSeeder::class);
    }

    private function prepareDocument(): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $user->companies()->attach($company->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        CompanyFiscalProfile::create([
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

        $this->actingAs($user);

        $base = "/api/v1/companies/{$company->public_id}";

        $response = $this->postJson(
            "{$base}/invoices",
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

        $this->postJson(
            "{$base}/invoices/{$invoiceId}/ready",
            ['series' => 'F']
        )->assertOk();

        $this->postJson(
            "{$base}/invoices/{$invoiceId}/fiscal-document"
        )->assertCreated();

        $document = FiscalDocument::query()
            ->where('company_id', $company->id)
            ->firstOrFail();

        app(CompanyContext::class)->set($company);

        return [$company, $document];
    }

    private function issueDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            '2026-10-21 11:00:00',
            'America/Merida'
        );
    }

    private function begin(
        FiscalDocument $document
    ) {
        return app(BeginStampAttempt::class)
            ->execute(
                $document,
                $this->issueDate()
            );
    }

    public function test_it_reserves_an_attempt_without_dispatching(): void
    {
        [$company, $document] = $this->prepareDocument();

        $attempt = $this->begin($document);

        $this->assertSame(
            FiscalStampAttemptStatus::Preparing,
            $attempt->status
        );

        $this->assertSame(1, $attempt->attempt_number);

        $this->assertSame(
            '2026-10-21T11:00:00',
            $attempt->cfdi_date
        );

        $this->assertSame(
            'America/Merida',
            $attempt->cfdi_timezone
        );

        $this->assertNull($attempt->xml_path);
        $this->assertNull($attempt->dispatch_started_at);

        $this->assertDatabaseCount(
            'fiscal_stamp_attempts',
            1
        );
    }

    public function test_repeated_reservation_keeps_same_attempt_and_date(): void
    {
        [$company, $document] = $this->prepareDocument();

        $first = $this->begin($document);

        $second = app(BeginStampAttempt::class)
            ->execute(
                $document,
                $this->issueDate()->addMinute()
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            $first->cfdi_date,
            $second->cfdi_date
        );

        $this->assertDatabaseCount(
            'fiscal_stamp_attempts',
            1
        );
    }

    public function test_unknown_attempt_blocks_new_reservation(): void
    {
        [$company, $document] = $this->prepareDocument();

        $attempt = $this->begin($document);

        $attempt->update([
            'status' => FiscalStampAttemptStatus::Unknown,
        ]);

        $this->expectException(
            ConflictHttpException::class
        );

        $this->begin($document);
    }

    public function test_another_company_cannot_reserve_attempt(): void
    {
        [$company, $document] = $this->prepareDocument();

        $otherCompany = Company::factory()->create();

        app(CompanyContext::class)->set($otherCompany);

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->begin($document);
    }

    public function test_invoice_must_still_be_ready(): void
    {
        [$company, $document] = $this->prepareDocument();

        $document->documentable->update([
            'status' => 'draft',
        ]);

        $this->expectException(
            ConflictHttpException::class
        );

        $this->begin($document);
    }

    public function test_it_stores_xml_privately_and_detects_tampering(): void
    {
        Storage::fake('local');

        [$company, $document] = $this->prepareDocument();

        $attempt = $this->begin($document);

        $contents = '<test>Sample payload</test>';

        $storage = app(PrivateStampXmlStore::class);

        $metadata = $storage->put(
            $attempt,
            $contents
        );

        /*
         * Simulamos el registro de metadatos
         * para comprobar la infraestructura.
         * No afirmamos que este sea un CFDI real.
         */
        $attempt->update([
            ...$metadata,
            'status' => FiscalStampAttemptStatus::Prepared,
        ]);

        Storage::disk('local')->assertExists(
            $attempt->xml_path
        );

        $this->assertTrue(
            $storage->verify($attempt)
        );

        Storage::disk('local')->put(
            $attempt->xml_path,
            '<test>Modified payload</test>'
        );

        $this->assertFalse(
            $storage->verify($attempt)
        );
    }

    public function test_it_cannot_dispatch_without_prepared_xml(): void
    {
        [$company, $document] = $this->prepareDocument();

        $attempt = $this->begin($document);

        $this->expectException(
            ConflictHttpException::class
        );

        app(MarkStampAttemptDispatching::class)
            ->execute($attempt);
    }

    public function test_dispatch_and_unknown_transition_block_retries(): void
    {
        Storage::fake('local');

        [$company, $document] = $this->prepareDocument();

        $attempt = $this->begin($document);

        /*
         * Fixture interno de estados.
         * No prueba validación de firma fiscal.
         */
        $metadata = app(PrivateStampXmlStore::class)
            ->put(
                $attempt,
                '<test>State machine fixture</test>'
            );

        $attempt->update([
            ...$metadata,
            'status' => FiscalStampAttemptStatus::Prepared,
            'preflight_passed_at' => now(),
        ]);

        $attempt = app(
            MarkStampAttemptDispatching::class
        )->execute($attempt);

        $this->assertSame(
            FiscalStampAttemptStatus::Dispatching,
            $attempt->status
        );

        $this->assertSame(
            'stamping',
            $document->fresh()->status->value
        );

        $attempt = app(
            MarkStampAttemptUnknown::class
        )->execute($attempt);

        $this->assertSame(
            FiscalStampAttemptStatus::Unknown,
            $attempt->status
        );

        $this->assertSame(
            'unknown',
            $document->fresh()->status->value
        );

        try {
            $this->begin($document);

            $this->fail(
                'An unknown attempt must block a new reservation.'
            );
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount(
                'fiscal_stamp_attempts',
                1
            );
        }
    }
}
