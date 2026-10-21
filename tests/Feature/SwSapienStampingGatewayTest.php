<?php

namespace Tests\Feature;

use App\Domain\Fiscal\Contracts\PacStampingGateway;
use App\Domain\Fiscal\Enums\PacStampOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SwSapienStampingGatewayTest extends TestCase
{
    private const UUID =
        '550e8400-e29b-41d4-a716-446655440000';

    private const CUSTOM_ID =
        '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.sw_sapien.base_url',
            'https://services.test.sw.com.mx'
        );

        Http::preventStrayRequests();
    }

    private function originalXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante
    xmlns:cfdi="http://www.sat.gob.mx/cfd/4"
    Version="4.0"
    Serie="F"
    Folio="1"
    Fecha="2026-10-21T11:00:00"
    Sello="TEST_SIGNATURE"
    NoCertificado="00001000000500000001"
    SubTotal="100.00"
    Total="116.00"
    Moneda="MXN"
    TipoDeComprobante="I">
    <cfdi:Emisor Rfc="AAA010101AAA"/>
    <cfdi:Receptor Rfc="XAXX010101000"/>
</cfdi:Comprobante>
XML;
    }

    private function stampedXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante
    xmlns:cfdi="http://www.sat.gob.mx/cfd/4"
    xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"
    Version="4.0"
    Serie="F"
    Folio="1"
    Fecha="2026-10-21T11:00:00"
    Sello="TEST_SIGNATURE"
    NoCertificado="00001000000500000001"
    SubTotal="100.00"
    Total="116.00"
    Moneda="MXN"
    TipoDeComprobante="I">
    <cfdi:Emisor Rfc="AAA010101AAA"/>
    <cfdi:Receptor Rfc="XAXX010101000"/>
    <cfdi:Complemento>
        <tfd:TimbreFiscalDigital
            Version="1.1"
            UUID="550e8400-e29b-41d4-a716-446655440000"
            FechaTimbrado="2026-10-21T11:00:05"
            SelloCFD="TEST_SIGNATURE"
            SelloSAT="TEST_SAT_SEAL"/>
    </cfdi:Complemento>
</cfdi:Comprobante>
XML;
    }

    private function stamp()
    {
        return app(PacStampingGateway::class)->stamp(
            $this->originalXml(),
            self::CUSTOM_ID,
            'sandbox-test-token'
        );
    }

    public function test_successful_response_is_received(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => [
                    'uuid' => self::UUID,
                    'cfdi' => $this->stampedXml(),
                ],
            ], 200),
        ]);

        $result = $this->stamp();

        $this->assertSame(
            PacStampOutcome::Received,
            $result->outcome
        );

        $this->assertSame(
            self::UUID,
            $result->uuid
        );

        $this->assertSame(
            $this->stampedXml(),
            $result->stampedXml
        );

        Http::assertSentCount(1);

        Http::assertSent(
            fn ($request) => $request->url() ===
                    'https://services.test.sw.com.mx/v4/cfdi33/stamp/v4'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer sandbox-test-token'
                )
                && $request->hasHeader(
                    'customid',
                    self::CUSTOM_ID
                )
        );
    }

    public function test_explicit_structural_error_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'message' => '301 - La estructura del comprobante es incorrecta.',
                'messageDetail' => null,
                'data' => null,
            ], 400),
        ]);

        $result = $this->stamp();

        $this->assertSame(
            PacStampOutcome::Rejected,
            $result->outcome
        );

        $this->assertSame(
            '301',
            $result->code
        );
    }

    public function test_http_500_is_unknown(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'message' => 'Internal error',
            ], 500),
        ]);

        $result = $this->stamp();

        $this->assertSame(
            PacStampOutcome::Unknown,
            $result->outcome
        );

        Http::assertSentCount(1);
    }

    public function test_connection_timeout_is_unknown(): void
    {
        Http::fake(function () {
            throw new ConnectionException(
                'Simulated timeout'
            );
        });

        $result = $this->stamp();

        $this->assertSame(
            PacStampOutcome::Unknown,
            $result->outcome
        );

        $this->assertSame(
            'PAC_CONNECTION_UNCERTAIN',
            $result->code
        );
    }

    public function test_duplicate_custom_id_is_not_treated_as_rejection(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'message' => 'CFDI3307 - Timbre duplicado.',
                'data' => [
                    'tfd' => '<tfd:TimbreFiscalDigital/>',
                ],
            ], 400),
        ]);

        $result = $this->stamp();

        $this->assertSame(
            PacStampOutcome::Unknown,
            $result->outcome
        );
    }

    public function test_invalid_success_xml_is_unknown(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => [
                    'uuid' => self::UUID,
                    'cfdi' => '<invalid/>',
                ],
            ], 200),
        ]);

        $result = $this->stamp();

        $this->assertSame(
            PacStampOutcome::Unknown,
            $result->outcome
        );
    }

    public function test_mismatched_uuid_is_unknown(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => [
                    'uuid' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
                    'cfdi' => $this->stampedXml(),
                ],
            ], 200),
        ]);

        $this->assertSame(
            PacStampOutcome::Unknown,
            $this->stamp()->outcome
        );
    }

    public function test_production_environment_is_blocked(): void
    {
        config()->set(
            'services.sw_sapien.base_url',
            'https://services.sw.com.mx'
        );

        $this->expectException(
            RuntimeException::class
        );

        try {
            $this->stamp();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_custom_id_fixture_is_valid(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9A-HJKMNP-TV-Z]{26}$/',
            self::CUSTOM_ID
        );
    }

    public function test_invalid_custom_id_is_rejected(): void
    {
        Http::fake();

        $this->expectException(
            \InvalidArgumentException::class
        );

        try {
            app(PacStampingGateway::class)->stamp(
                $this->originalXml(),
                'INVALID-CUSTOM-ID',
                'sandbox-test-token'
            );
        } finally {
            Http::assertNothingSent();
        }
    }
}
