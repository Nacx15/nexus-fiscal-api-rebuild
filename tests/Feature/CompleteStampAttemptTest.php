<?php

namespace Tests\Feature;

use App\Application\Company\CompanyContext;
use App\Application\Fiscal\BeginStampAttempt;
use App\Application\Fiscal\CompleteStampAttempt;
use App\Application\Fiscal\MarkStampAttemptDispatching;
use App\Application\Fiscal\MarkStampAttemptUnknown;
use App\Application\Fiscal\ReconcileStampAttempt;
use App\Domain\Fiscal\Contracts\PacAccessTokenResolver;
use App\Domain\Fiscal\Contracts\PacStampRecoveryGateway;
use App\Domain\Fiscal\Dto\PacRecoveryResult;
use App\Domain\Fiscal\Dto\PacStampResult;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Domain\Fiscal\Enums\FiscalStampAuditAction;
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
use DOMDocument;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class CompleteStampAttemptTest extends TestCase
{
    use RefreshDatabase;

    private const UUID =
        '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(SatCatalogTestSeeder::class);
    }

    private function prepareFixture(): array
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

        $attempt = app(BeginStampAttempt::class)
            ->execute(
                $document,
                CarbonImmutable::parse(
                    '2026-10-21 11:00:00',
                    'America/Merida'
                )
            );

        $invoice = $document->documentable;

        /*
         * XML sintético usado para probar
         * persistencia y máquina de estados.
         */
        $originalXml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4"'
            .' Version="4.0" Serie="%s" Folio="%s"'
            .' Fecha="%s" Sello="QUJD"'
            .' NoCertificado="00001000000500000001"'
            .' Certificado="QUJD"'
            .' SubTotal="100.00" Total="116.00"'
            .' Moneda="MXN" TipoDeComprobante="I">'
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
            ->put($attempt, $originalXml);

        /*
         * Simulación exclusiva del test.
         * En producción sólo StorePreparedStampXml
         * podrá registrar la prevalidación.
         */
        $attempt->update([
            ...$metadata,
            'status' => FiscalStampAttemptStatus::Prepared,
            'preflight_passed_at' => now(),
        ]);

        $attempt = app(
            MarkStampAttemptDispatching::class
        )->execute($attempt);

        return [
            $company,
            $document,
            $attempt,
            $originalXml,
        ];
    }

    private function stampedXml(string $originalXml): string
    {
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
        $xml->documentElement->appendChild($complement);

        return $xml->saveXML();
    }

    private function complete(
        $attempt,
        PacStampResult $result
    ) {
        return app(CompleteStampAttempt::class)
            ->execute($attempt, $result);
    }

    public function test_received_stamp_is_persisted_and_confirmed(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        $stampedXml = $this->stampedXml($original);

        $result = $this->complete(
            $attempt,
            PacStampResult::received(
                self::UUID,
                $stampedXml
            )
        );

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

        $this->assertSame(
            strtoupper(self::UUID),
            $document->fresh()->uuid
        );

        $file = $document->files()
            ->where('file_type', 'xml')
            ->firstOrFail();

        Storage::disk('local')->assertExists($file->path);

        $this->assertSame(
            hash('sha256', $stampedXml),
            $file->checksum
        );

        $this->assertSame(
            $stampedXml,
            Storage::disk('local')->get($file->path)
        );

        /*
         * El XML enviado al PAC se conserva.
         */
        $this->assertSame(
            $original,
            Storage::disk('local')->get(
                $attempt->xml_path
            )
        );
    }

    public function test_repeated_confirmation_is_idempotent(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        $response = PacStampResult::received(
            self::UUID,
            $this->stampedXml($original)
        );

        $first = $this->complete($attempt, $response);
        $second = $this->complete($attempt, $response);

        $this->assertSame($first->id, $second->id);

        $this->assertDatabaseCount(
            'fiscal_document_files',
            1
        );

        $this->assertDatabaseCount(
            'fiscal_stamp_attempts',
            1
        );
    }

    public function test_modified_concept_keeps_result_unknown(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        $tampered = str_replace(
            'Descripcion="ORIGINAL"',
            'Descripcion="ALTERADO"',
            $this->stampedXml($original)
        );

        $result = $this->complete(
            $attempt,
            PacStampResult::received(
                self::UUID,
                $tampered
            )
        );

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

        $this->assertDatabaseCount(
            'fiscal_document_files',
            0
        );
    }

    public function test_confirmed_rejection_marks_stamp_failed(): void
    {
        [$company, $document, $attempt] =
            $this->prepareFixture();

        $result = $this->complete(
            $attempt,
            PacStampResult::rejected('301')
        );

        $this->assertSame(
            FiscalStampAttemptStatus::Rejected,
            $result->status
        );

        $this->assertSame(
            '301',
            $result->failure_code
        );

        $this->assertSame(
            'stamp_failed',
            $document->fresh()->status->value
        );

        $this->assertSame(
            'stamp_failed',
            $document->documentable->fresh()->status->value
        );

        $this->assertDatabaseCount(
            'fiscal_document_files',
            0
        );
    }

    public function test_uncertain_response_does_not_stamp(): void
    {
        [$company, $document, $attempt] =
            $this->prepareFixture();

        $result = $this->complete(
            $attempt,
            PacStampResult::unknown(
                'PAC_CONNECTION_UNCERTAIN'
            )
        );

        $this->assertSame(
            FiscalStampAttemptStatus::Unknown,
            $result->status
        );

        $this->assertNull($document->fresh()->uuid);

        $this->assertDatabaseCount(
            'fiscal_document_files',
            0
        );
    }

    public function test_corrupted_original_xml_cannot_be_confirmed(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        Storage::disk('local')->put(
            $attempt->xml_path,
            '<modified/>'
        );

        $result = $this->complete(
            $attempt,
            PacStampResult::received(
                self::UUID,
                $this->stampedXml($original)
            )
        );

        $this->assertSame(
            FiscalStampAttemptStatus::Unknown,
            $result->status
        );

        $this->assertNull($document->fresh()->uuid);
    }

    public function test_other_company_cannot_complete_attempt(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        $otherCompany = Company::factory()->create();

        app(CompanyContext::class)->set($otherCompany);

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->complete(
            $attempt,
            PacStampResult::received(
                self::UUID,
                $this->stampedXml($original)
            )
        );
    }

    public function test_prepared_attempt_cannot_confirm_a_stamp(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        /*
        * Volvemos el intento a prepared
        * para simular una confirmación
        * fuera del flujo permitido.
        */
        $attempt->update([
            'status' => FiscalStampAttemptStatus::Prepared,
        ]);

        $this->expectException(
            ConflictHttpException::class
        );

        try {
            $this->complete(
                $attempt,
                PacStampResult::received(
                    self::UUID,
                    $this->stampedXml($original)
                )
            );
        } finally {
            $this->assertSame(
                FiscalStampAttemptStatus::Prepared,
                $attempt->fresh()->status
            );

            $this->assertNull(
                $document->fresh()->uuid
            );
        }
    }

    public function test_unknown_attempt_can_be_reconciled_with_recovered_xml(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        app(
            MarkStampAttemptUnknown::class
        )->execute($attempt);

        $tokenResolver = Mockery::mock(
            PacAccessTokenResolver::class
        );

        $tokenResolver->shouldReceive(
            'resolveForCompany'
        )
            ->once()
            ->with($company->id)
            ->andReturn('sandbox-token');

        $this->app->instance(
            PacAccessTokenResolver::class,
            $tokenResolver
        );

        $gateway = Mockery::mock(
            PacStampRecoveryGateway::class
        );

        $gateway->shouldReceive('recoverByUuid')
            ->once()
            ->with(
                self::UUID,
                'sandbox-token'
            )
            ->andReturn(
                PacRecoveryResult::found(
                    self::UUID,
                    $this->stampedXml($original)
                )
            );

        $this->app->instance(
            PacStampRecoveryGateway::class,
            $gateway
        );

        $result = app(ReconcileStampAttempt::class)
            ->execute(
                $attempt,
                self::UUID
            );

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

        $this->assertSame(
            strtoupper(self::UUID),
            $document->fresh()->uuid
        );

        $this->assertDatabaseCount(
            'fiscal_stamp_attempts',
            1
        );

        $this->assertDatabaseCount(
            'fiscal_document_files',
            1
        );
    }

    public function test_missing_uuid_at_pac_keeps_attempt_unknown(): void
    {
        [$company, $document, $attempt] =
            $this->prepareFixture();

        $tokenResolver = Mockery::mock(
            PacAccessTokenResolver::class
        );

        $tokenResolver->shouldReceive(
            'resolveForCompany'
        )
            ->once()
            ->with($company->id)
            ->andReturn('sandbox-token');

        $this->app->instance(
            PacAccessTokenResolver::class,
            $tokenResolver
        );

        $gateway = Mockery::mock(
            PacStampRecoveryGateway::class
        );

        $gateway->shouldReceive('recoverByUuid')
            ->once()
            ->andReturn(
                PacRecoveryResult::notFound()
            );

        $this->app->instance(
            PacStampRecoveryGateway::class,
            $gateway
        );

        $result = app(ReconcileStampAttempt::class)
            ->execute(
                $attempt,
                self::UUID
            );

        $this->assertSame(
            FiscalStampAttemptStatus::Unknown,
            $result->status
        );

        $this->assertSame(
            'unknown',
            $document->fresh()->status->value
        );

        $this->assertNull(
            $document->fresh()->uuid
        );

        $this->assertDatabaseCount(
            'fiscal_stamp_attempts',
            1
        );
    }

    public function test_reconciliation_without_uuid_never_contacts_pac(): void
    {
        [$company, $document, $attempt] =
            $this->prepareFixture();

        $gateway = Mockery::mock(
            PacStampRecoveryGateway::class
        );

        $gateway->shouldNotReceive(
            'recoverByUuid'
        );

        $this->app->instance(
            PacStampRecoveryGateway::class,
            $gateway
        );

        $result = app(ReconcileStampAttempt::class)
            ->execute(
                $attempt,
                null
            );

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
    }

    public function test_recovered_xml_must_match_original_invoice(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        app(
            MarkStampAttemptUnknown::class
        )->execute($attempt);

        $tokenResolver = Mockery::mock(
            PacAccessTokenResolver::class
        );

        $tokenResolver->shouldReceive(
            'resolveForCompany'
        )
            ->once()
            ->with($company->id)
            ->andReturn('sandbox-token');

        $this->app->instance(
            PacAccessTokenResolver::class,
            $tokenResolver
        );

        $tamperedXml = str_replace(
            'Descripcion="ORIGINAL"',
            'Descripcion="OTRA FACTURA"',
            $this->stampedXml($original)
        );

        $gateway = Mockery::mock(
            PacStampRecoveryGateway::class
        );

        $gateway->shouldReceive('recoverByUuid')
            ->once()
            ->andReturn(
                PacRecoveryResult::found(
                    self::UUID,
                    $tamperedXml
                )
            );

        $this->app->instance(
            PacStampRecoveryGateway::class,
            $gateway
        );

        $result = app(ReconcileStampAttempt::class)
            ->execute(
                $attempt,
                self::UUID
            );

        $this->assertSame(
            FiscalStampAttemptStatus::Unknown,
            $result->status
        );

        $this->assertNull(
            $document->fresh()->uuid
        );

        $this->assertDatabaseCount(
            'fiscal_document_files',
            0
        );
    }

    public function test_confirmed_stamp_is_audited_with_uuid_and_hash(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        $result = $this->complete(
            $attempt,
            PacStampResult::received(
                self::UUID,
                $this->stampedXml($original)
            )
        );

        $this->assertSame(
            FiscalStampAttemptStatus::Stamped,
            $result->status
        );

        $event = $result->auditEvents()
            ->where('event_type', 'stamped')
            ->firstOrFail();

        $this->assertSame(
            'dispatching',
            $event->previous_status
        );

        $this->assertSame(
            'stamped',
            $event->current_status
        );

        $this->assertSame(
            strtoupper(self::UUID),
            $event->uuid
        );

        $this->assertSame(
            hash('sha256', $original),
            $event->xml_sha256
        );

        $this->assertSame(
            $company->id,
            $event->company_id
        );

        $this->assertDatabaseCount(
            'fiscal_stamp_audit_events',
            3
        );
    }

    public function test_recovered_stamp_audits_unknown_to_stamped(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        $attempt = app(
            MarkStampAttemptUnknown::class
        )->execute($attempt);

        $this->assertSame(
            FiscalStampAttemptStatus::Unknown,
            $attempt->status
        );

        /*
        * Simulamos el resultado recuperado
        * de un UUID previamente confirmado.
        *
        * Aquí utilizamos directamente el
        * servicio de confirmación, ya probado.
        */
        $result = $this->complete(
            $attempt,
            PacStampResult::received(
                self::UUID,
                $this->stampedXml($original)
            )
        );

        $this->assertSame(
            FiscalStampAttemptStatus::Stamped,
            $result->status
        );

        $event = $result->auditEvents()
            ->where('event_type', 'stamped')
            ->firstOrFail();

        $this->assertSame(
            'unknown',
            $event->previous_status
        );

        $this->assertSame(
            'stamped',
            $event->current_status
        );

        $this->assertSame(
            strtoupper(self::UUID),
            $event->uuid
        );

        $this->assertSame([
            'reserved',
            'dispatch_started',
            'became_unknown',
            'stamped',
        ], $result->auditEvents()
            ->orderBy('id')
            ->pluck('event_type')
            ->map(fn (FiscalStampAuditAction $action) => $action->value)
            ->all());
    }

    public function test_repeated_stamp_confirmation_does_not_duplicate_audit(): void
    {
        [$company, $document, $attempt, $original] =
            $this->prepareFixture();

        $response = PacStampResult::received(
            self::UUID,
            $this->stampedXml($original)
        );

        $first = $this->complete(
            $attempt,
            $response
        );

        $second = $this->complete(
            $attempt,
            $response
        );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            1,
            $first->auditEvents()
                ->where('event_type', 'stamped')
                ->count()
        );

        $this->assertDatabaseCount(
            'fiscal_stamp_audit_events',
            3
        );
    }
}
