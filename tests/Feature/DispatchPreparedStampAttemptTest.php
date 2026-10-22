<?php

namespace Tests\Feature;

use App\Application\Company\CompanyContext;
use App\Application\Fiscal\BeginStampAttempt;
use App\Application\Fiscal\DispatchPreparedStampAttempt;
use App\Application\Fiscal\StampInvoice;
use App\Domain\Fiscal\Contracts\PacAccessTokenResolver;
use App\Domain\Fiscal\Contracts\PacStampingGateway;
use App\Domain\Fiscal\Dto\PacStampResult;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Domain\Fiscal\Enums\FiscalStampAuditAction;
use App\Infrastructure\Fiscal\Storage\PrivateStampXmlStore;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyFiscalProfile;
use App\Models\FiscalCertificate;
use App\Models\FiscalDocument;
use App\Models\FiscalSequence;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SatCatalogTestSeeder;
use DOMDocument;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class DispatchPreparedStampAttemptTest extends TestCase
{
    use RefreshDatabase;

    private const UUID =
        '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(SatCatalogTestSeeder::class);

        /*
         * La habilitación solamente se aplica
         * al contenedor de esta prueba.
         */
        config()->set(
            'fiscal.pac.dispatch_enabled',
            true
        );
    }

    private function preparedAttempt(): array
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

        $created = $this->postJson(
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

        $created->assertCreated();

        $invoiceId = $created->json('data.id');

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

        $issuedAt = CarbonImmutable::parse(
            '2026-10-21 11:00:00',
            'America/Merida'
        );

        $attempt = app(BeginStampAttempt::class)
            ->execute(
                $document,
                $issuedAt
            );

        $invoice = $document->documentable;

        /*
         * Fixture sintético para probar
         * solamente el flujo de despacho.
         */
        $originalXml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<cfdi:Comprobante'
            .' xmlns:cfdi="http://www.sat.gob.mx/cfd/4"'
            .' Version="4.0"'
            .' Serie="%s"'
            .' Folio="%s"'
            .' Fecha="%s"'
            .' Sello="QUJD"'
            .' NoCertificado="00001000000500000001"'
            .' Certificado="QUJD"'
            .' SubTotal="100.00"'
            .' Total="116.00"'
            .' Moneda="MXN"'
            .' TipoDeComprobante="I">'
            .'<cfdi:Emisor Rfc="%s"/>'
            .'<cfdi:Receptor Rfc="%s"/>'
            .'<cfdi:Conceptos>'
            .'<cfdi:Concepto Descripcion="ORIGINAL"/>'
            .'</cfdi:Conceptos>'
            .'</cfdi:Comprobante>',
            $invoice->series,
            $invoice->folio,
            $attempt->cfdi_date,
            $document->issuer_rfc,
            $document->receiver_rfc
        );

        $metadata = app(PrivateStampXmlStore::class)
            ->put(
                $attempt,
                $originalXml
            );

        /*
         * Simulamos un intento que ya pasó
         * la preparación fiscal.
         *
         * No es una validación XSD real.
         */
        $attempt->update([
            ...$metadata,
            'status' => FiscalStampAttemptStatus::Prepared,
            'preflight_passed_at' => now(),
        ]);

        return [
            $company,
            $document,
            $attempt->refresh(),
            $originalXml,
        ];
    }

    private function mockToken(
        int $companyId,
        string $token = 'fake-sandbox-token'
    ): void {
        $resolver = Mockery::mock(
            PacAccessTokenResolver::class
        );

        $resolver->shouldReceive(
            'resolveForCompany'
        )
            ->once()
            ->with($companyId)
            ->andReturn($token);

        $this->app->instance(
            PacAccessTokenResolver::class,
            $resolver
        );
    }

    private function dispatch(
        $attempt
    ) {
        return app(
            DispatchPreparedStampAttempt::class
        )->execute($attempt);
    }

    private function stampedXml(
        string $originalXml
    ): string {
        $xml = new DOMDocument;

        $this->assertTrue(
            $xml->loadXML($originalXml)
        );

        $complement = $xml->createElementNS(
            'http://www.sat.gob.mx/cfd/4',
            'cfdi:Complemento'
        );

        $tfd = $xml->createElementNS(
            'http://www.sat.gob.mx/TimbreFiscalDigital',
            'tfd:TimbreFiscalDigital'
        );

        foreach ([
            'Version' => '1.1',
            'UUID' => self::UUID,
            'FechaTimbrado' => '2026-10-21T11:00:05',
            'RfcProvCertif' => 'SPR190613I52',
            'SelloCFD' => 'QUJD',
            'NoCertificadoSAT' => '00001000000500000001',
            'SelloSAT' => 'REVG',
        ] as $name => $value) {
            $tfd->setAttribute($name, $value);
        }

        $complement->appendChild($tfd);

        $xml->documentElement->appendChild(
            $complement
        );

        return $xml->saveXML();
    }

    public function test_received_response_completes_stamp_once(): void
    {
        [$company, $document, $attempt, $original] =
            $this->preparedAttempt();

        $this->mockToken($company->id);

        $gateway = Mockery::mock(
            PacStampingGateway::class
        );

        $gateway->shouldReceive('stamp')
            ->once()
            ->withArgs(
                fn ($xml, $customId, $token) => $xml === $original
                    && $customId === $attempt->public_id
                    && $token === 'fake-sandbox-token'
            )
            ->andReturn(
                PacStampResult::received(
                    self::UUID,
                    $this->stampedXml($original)
                )
            );

        $this->app->instance(
            PacStampingGateway::class,
            $gateway
        );

        $result = $this->dispatch($attempt);

        $this->assertSame(
            FiscalStampAttemptStatus::Stamped,
            $result->status
        );

        $this->assertSame(
            'stamped',
            $document->fresh()->status->value
        );

        $this->assertSame(
            'stamped',
            $document->documentable->fresh()->status->value
        );

        $this->assertDatabaseCount(
            'fiscal_stamp_attempts',
            1
        );

        $this->assertSame([
            'reserved',
            'dispatch_started',
            'stamped',
        ], $result->auditEvents()
            ->orderBy('id')
            ->pluck('event_type')
            ->map(fn (FiscalStampAuditAction $action) => $action->value)
            ->all());

        $this->assertDatabaseCount(
            'fiscal_stamp_audit_events',
            3
        );
    }

    public function test_timeout_becomes_unknown_without_retry(): void
    {
        [$company, $document, $attempt, $original] =
            $this->preparedAttempt();

        $this->mockToken($company->id);

        $gateway = Mockery::mock(
            PacStampingGateway::class
        );

        $gateway->shouldReceive('stamp')
            ->once()
            ->andReturn(
                PacStampResult::unknown(
                    'PAC_CONNECTION_UNCERTAIN'
                )
            );

        $this->app->instance(
            PacStampingGateway::class,
            $gateway
        );

        $result = $this->dispatch($attempt);

        $this->assertSame(
            FiscalStampAttemptStatus::Unknown,
            $result->status
        );

        $this->assertSame(
            'unknown',
            $document->fresh()->status->value
        );

        $this->assertSame(
            'stamping',
            $document->documentable->fresh()->status->value
        );

        /*
         * Un segundo despacho tiene que
         * fallar antes de contactar al PAC.
         */
        $this->expectException(
            ConflictHttpException::class
        );

        $this->dispatch($attempt);
    }

    public function test_confirmed_rejection_updates_states(): void
    {
        [$company, $document, $attempt] =
            $this->preparedAttempt();

        $this->mockToken($company->id);

        $gateway = Mockery::mock(
            PacStampingGateway::class
        );

        $gateway->shouldReceive('stamp')
            ->once()
            ->andReturn(
                PacStampResult::rejected('301')
            );

        $this->app->instance(
            PacStampingGateway::class,
            $gateway
        );

        $result = $this->dispatch($attempt);

        $this->assertSame(
            FiscalStampAttemptStatus::Rejected,
            $result->status
        );

        $this->assertSame(
            'stamp_failed',
            $document->fresh()->status->value
        );

        $this->assertSame(
            'stamp_failed',
            $document->documentable->fresh()->status->value
        );
    }

    public function test_gateway_exception_becomes_unknown(): void
    {
        [$company, $document, $attempt] =
            $this->preparedAttempt();

        $this->mockToken($company->id);

        $gateway = Mockery::mock(
            PacStampingGateway::class
        );

        $gateway->shouldReceive('stamp')
            ->once()
            ->andThrow(
                new RuntimeException(
                    'Simulated transport failure'
                )
            );

        $this->app->instance(
            PacStampingGateway::class,
            $gateway
        );

        $result = $this->dispatch($attempt);

        $this->assertSame(
            FiscalStampAttemptStatus::Unknown,
            $result->status
        );

        $this->assertNull(
            $document->fresh()->uuid
        );
    }

    public function test_disabled_dispatch_makes_no_pac_call(): void
    {
        [$company, $document, $attempt] =
            $this->preparedAttempt();

        config()->set(
            'fiscal.pac.dispatch_enabled',
            false
        );

        $gateway = Mockery::mock(
            PacStampingGateway::class
        );

        $gateway->shouldNotReceive('stamp');

        $this->app->instance(
            PacStampingGateway::class,
            $gateway
        );

        $this->expectException(
            ConflictHttpException::class
        );

        try {
            $this->dispatch($attempt);
        } finally {
            $this->assertSame(
                FiscalStampAttemptStatus::Prepared,
                $attempt->fresh()->status
            );
        }
    }

    public function test_missing_token_does_not_begin_dispatch(): void
    {
        [$company, $document, $attempt] =
            $this->preparedAttempt();

        $resolver = Mockery::mock(
            PacAccessTokenResolver::class
        );

        $resolver->shouldReceive(
            'resolveForCompany'
        )
            ->once()
            ->with($company->id)
            ->andThrow(
                new RuntimeException(
                    'No PAC account configured'
                )
            );

        $this->app->instance(
            PacAccessTokenResolver::class,
            $resolver
        );

        $gateway = Mockery::mock(
            PacStampingGateway::class
        );

        $gateway->shouldNotReceive('stamp');

        $this->app->instance(
            PacStampingGateway::class,
            $gateway
        );

        $this->expectException(
            RuntimeException::class
        );

        try {
            $this->dispatch($attempt);
        } finally {
            $this->assertSame(
                FiscalStampAttemptStatus::Prepared,
                $attempt->fresh()->status
            );

            $this->assertSame(
                'pending',
                $document->fresh()->status->value
            );
        }
    }

    public function test_corrupted_xml_never_reaches_gateway(): void
    {
        [$company, $document, $attempt] =
            $this->preparedAttempt();

        Storage::disk('local')->put(
            $attempt->xml_path,
            '<modified/>'
        );

        $gateway = Mockery::mock(
            PacStampingGateway::class
        );

        $gateway->shouldNotReceive('stamp');

        $this->app->instance(
            PacStampingGateway::class,
            $gateway
        );

        $this->expectException(
            ConflictHttpException::class
        );

        try {
            $this->dispatch($attempt);
        } finally {
            $this->assertSame(
                FiscalStampAttemptStatus::Prepared,
                $attempt->fresh()->status
            );

            $this->assertNull(
                $attempt->fresh()->dispatch_started_at
            );
        }
    }

    public function test_another_company_cannot_dispatch(): void
    {
        [$company, $document, $attempt] =
            $this->preparedAttempt();

        $otherCompany = Company::factory()->create();

        app(CompanyContext::class)->set(
            $otherCompany
        );

        $gateway = Mockery::mock(
            PacStampingGateway::class
        );

        $gateway->shouldNotReceive('stamp');

        $this->app->instance(
            PacStampingGateway::class,
            $gateway
        );

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->dispatch($attempt);
    }

    public function test_stamp_invoice_is_disabled_by_default(): void
    {
        config()->set(
            'fiscal.pac.dispatch_enabled',
            false
        );

        $this->expectException(
            ConflictHttpException::class
        );

        app(StampInvoice::class)->execute(
            new FiscalDocument,
            new FiscalCertificate,
            CarbonImmutable::now()
        );
    }
}
