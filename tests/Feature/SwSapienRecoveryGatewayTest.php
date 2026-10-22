<?php

namespace Tests\Feature;

use App\Domain\Fiscal\Contracts\PacStampRecoveryGateway;
use App\Domain\Fiscal\Enums\PacRecoveryOutcome;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SwSapienRecoveryGatewayTest extends TestCase
{
    private const UUID =
        '550e8400-e29b-41d4-a716-446655440000';

    private const XML_URL =
        'https://smarter-datawarehouse-storage-test.s3.amazonaws.com/'
        .'cfdi/550e8400.xml?X-Amz-Signature=example';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.sw_sapien.base_url',
            'https://services.test.sw.com.mx'
        );

        Http::preventStrayRequests();
    }

    private function recovery(
        ?string $uuid = null
    ) {
        return app(
            PacStampRecoveryGateway::class
        )->recoverByUuid(
            $uuid ?? self::UUID,
            'sandbox-test-token'
        );
    }

    private function record(
        array $overrides = []
    ): array {
        return array_replace([
            'uuid' => self::UUID,
            'status' => 'Vigente',
            'version' => '4.0',
            'urlXml' => self::XML_URL,
        ], $overrides);
    }

    private function metadataResponse(
        array $records
    ): array {
        return [
            'status' => 'success',
            'data' => [
                'records' => $records,
            ],
        ];
    }

    public function test_it_recovers_xml_without_resending_cfdi(): void
    {
        $xml = '<?xml version="1.0"?><test/>';

        Http::fake([
            'api.test.sw.com.mx/*' => Http::response(
                $this->metadataResponse([
                    $this->record(),
                ]),
                200
            ),

            'smarter-datawarehouse-storage-test.s3.amazonaws.com/*' => Http::response(
                $xml,
                200,
                ['Content-Type' => 'application/xml']
            ),
        ]);

        $result = $this->recovery();

        $this->assertSame(
            PacRecoveryOutcome::Found,
            $result->outcome
        );

        $this->assertSame(
            self::UUID,
            $result->uuid
        );

        $this->assertSame(
            $xml,
            $result->stampedXml
        );

        Http::assertSentCount(2);

        Http::assertSent(
            fn ($request) => $request->method() === 'GET'
                && $request->url() ===
                    'https://api.test.sw.com.mx'
                    .'/datawarehouse/v1/live/'
                    .self::UUID
                && $request->hasHeader(
                    'Authorization',
                    'Bearer sandbox-test-token'
                )
        );

        /*
         * La descarga de S3 no recibe
         * el Bearer Token del PAC.
         */
        Http::assertSent(
            fn ($request) => $request->url() === self::XML_URL
                && $request->method() === 'GET'
                && ! $request->hasHeader(
                    'Authorization'
                )
        );
    }

    public function test_empty_records_do_not_authorize_retry(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->metadataResponse([]),
                200
            ),
        ]);

        $result = $this->recovery();

        $this->assertSame(
            PacRecoveryOutcome::NotFound,
            $result->outcome
        );

        Http::assertSentCount(1);
    }

    public function test_http_error_is_unknown(): void
    {
        Http::fake([
            '*' => Http::response(
                ['status' => 'error'],
                500
            ),
        ]);

        $this->assertSame(
            PacRecoveryOutcome::Unknown,
            $this->recovery()->outcome
        );
    }

    public function test_mismatched_uuid_is_unknown(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->metadataResponse([
                    $this->record([
                        'uuid' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
                    ]),
                ]),
                200
            ),
        ]);

        $this->assertSame(
            PacRecoveryOutcome::Unknown,
            $this->recovery()->outcome
        );

        Http::assertSentCount(1);
    }

    public function test_untrusted_xml_url_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->metadataResponse([
                    $this->record([
                        'urlXml' => 'http://127.0.0.1/internal.xml',
                    ]),
                ]),
                200
            ),
        ]);

        $this->assertSame(
            PacRecoveryOutcome::Unknown,
            $this->recovery()->outcome
        );

        /*
         * Nunca se intentó descargar
         * la URL no confiable.
         */
        Http::assertSentCount(1);
    }

    public function test_cancelled_record_requires_manual_review(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->metadataResponse([
                    $this->record([
                        'status' => 'Cancelado',
                    ]),
                ]),
                200
            ),
        ]);

        $this->assertSame(
            PacRecoveryOutcome::Unknown,
            $this->recovery()->outcome
        );

        Http::assertSentCount(1);
    }

    public function test_invalid_uuid_makes_no_http_request(): void
    {
        Http::fake();

        $this->assertSame(
            PacRecoveryOutcome::Unknown,
            $this->recovery(
                'not-a-valid-uuid'
            )->outcome
        );

        Http::assertNothingSent();
    }

    public function test_production_url_cannot_be_used(): void
    {
        config()->set(
            'services.sw_sapien.base_url',
            'https://services.sw.com.mx'
        );

        Http::fake();

        $this->assertSame(
            PacRecoveryOutcome::Unknown,
            $this->recovery()->outcome
        );

        Http::assertNothingSent();
    }
}
