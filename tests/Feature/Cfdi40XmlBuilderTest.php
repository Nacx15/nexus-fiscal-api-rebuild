<?php

namespace Tests\Feature;

use App\Application\Company\CompanyContext;
use App\Application\Fiscal\BuildInvoiceCfdi;
use App\Domain\Fiscal\Cfdi\Exceptions\UnsupportedCfdiOperation;
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
use DOMXPath;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Cfdi40XmlBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const NS = 'http://www.sat.gob.mx/cfd/4';

    protected function setUp(): void
    {
        parent::setUp();

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
            'business_name' => 'EMPRESA DEMO SA DE CV',
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
            ->create([
                'business_name' => 'CLIENTE DEMO',
                'tax_regime' => '601',
                'postal_code' => '97000',
            ]);

        $product = Product::factory()
            ->for($company)
            ->create([
                'description' => 'DESARROLLO DE SOFTWARE',
                'unit_price' => '100.000000',
                'tax_object' => '02',
                'sat_product_code' => '81111500',
                'sat_unit_code' => 'E48',
            ]);

        $this->actingAs($user);

        $baseUrl = "/api/v1/companies/{$company->public_id}";

        $response = $this->postJson(
            "{$baseUrl}/invoices",
            [
                'client_id' => $client->public_id,
                'payment_form' => '03',
                'payment_method' => 'PUE',
                'cfdi_use' => 'G03',
                'items' => [
                    [
                        'product_id' => $product->public_id,
                        'quantity' => '2.000000',
                        'unit_price' => '100.000000',
                        'discount' => '10.000000',
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
            "{$baseUrl}/invoices/{$invoiceId}/ready",
            ['series' => 'F']
        )->assertOk();

        $this->postJson(
            "{$baseUrl}/invoices/{$invoiceId}/fiscal-document"
        )->assertCreated();

        $document = FiscalDocument::query()
            ->where('company_id', $company->id)
            ->firstOrFail();

        app(CompanyContext::class)->set($company);

        return [$company, $document];
    }

    private function buildXml(
        FiscalDocument $document
    ): string {
        $date = CarbonImmutable::parse(
            '2026-10-20 12:00:00',
            'America/Mexico_City'
        );

        return app(BuildInvoiceCfdi::class)
            ->execute($document, $date);
    }

    public function test_it_builds_income_cfdi_xml_structure(): void
    {
        [$company, $document] = $this->prepareFixture();

        $xmlString = $this->buildXml($document);

        $xml = new DOMDocument;

        $this->assertTrue(
            $xml->loadXML($xmlString)
        );

        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('cfdi', self::NS);

        $root = $xml->documentElement;

        $this->assertSame(
            self::NS,
            $root->namespaceURI
        );

        $this->assertSame(
            'Comprobante',
            $root->localName
        );

        $this->assertSame(
            '4.0',
            $root->getAttribute('Version')
        );

        $this->assertSame(
            'I',
            $root->getAttribute('TipoDeComprobante')
        );

        $this->assertSame(
            '01',
            $root->getAttribute('Exportacion')
        );

        $this->assertSame(
            'F',
            $root->getAttribute('Serie')
        );

        $this->assertSame(
            '1',
            $root->getAttribute('Folio')
        );

        $this->assertSame(
            '2026-10-20T12:00:00',
            $root->getAttribute('Fecha')
        );

        $this->assertSame(
            '97000',
            $root->getAttribute('LugarExpedicion')
        );

        $this->assertCount(
            1,
            $xpath->query('//cfdi:Emisor')
        );

        $this->assertCount(
            1,
            $xpath->query('//cfdi:Receptor')
        );

        $this->assertCount(
            1,
            $xpath->query('//cfdi:Concepto')
        );

        /*
         * El XML base todavía no está firmado.
         */
        $this->assertFalse(
            $root->hasAttribute('Sello')
        );

        $this->assertFalse(
            $root->hasAttribute('Certificado')
        );

        $this->assertFalse(
            $root->hasAttribute('NoCertificado')
        );

        $this->assertDatabaseCount(
            'fiscal_document_files',
            0
        );
    }

    public function test_it_serializes_expected_amounts_and_iva(): void
    {
        [$company, $document] = $this->prepareFixture();

        $xml = new DOMDocument;
        $xml->loadXML($this->buildXml($document));

        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('cfdi', self::NS);

        $root = $xml->documentElement;

        $this->assertSame(
            '200.00',
            $root->getAttribute('SubTotal')
        );

        $this->assertSame(
            '10.00',
            $root->getAttribute('Descuento')
        );

        $this->assertSame(
            '220.40',
            $root->getAttribute('Total')
        );

        $concept = $xpath
            ->query('//cfdi:Concepto')
            ->item(0);

        $this->assertSame(
            '200.00',
            $concept->getAttribute('Importe')
        );

        $tax = $xpath->query(
            '//cfdi:Concepto/cfdi:Impuestos/'
            .'cfdi:Traslados/cfdi:Traslado'
        )->item(0);

        $this->assertSame(
            '190.00',
            $tax->getAttribute('Base')
        );

        $this->assertSame(
            '0.160000',
            $tax->getAttribute('TasaOCuota')
        );

        $this->assertSame(
            '30.40',
            $tax->getAttribute('Importe')
        );

        $globalTaxes = $xpath->query(
            '/cfdi:Comprobante/cfdi:Impuestos'
        )->item(0);

        $this->assertSame(
            '30.40',
            $globalTaxes->getAttribute(
                'TotalImpuestosTrasladados'
            )
        );
    }

    public function test_it_rejects_unsupported_tax_rate(): void
    {
        [$company, $document] = $this->prepareFixture();

        $invoice = $document->documentable;
        $item = $invoice->items()->firstOrFail();

        $item->taxes()->firstOrFail()->update([
            'rate_or_quota' => '0.080000',
        ]);

        $this->expectException(
            UnsupportedCfdiOperation::class
        );

        $this->buildXml($document->fresh());
    }

    public function test_it_rejects_document_from_another_company(): void
    {
        [$company, $document] = $this->prepareFixture();

        $otherCompany = Company::factory()->create();

        app(CompanyContext::class)->set(
            $otherCompany
        );

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->buildXml($document);
    }

    public function test_xml_escapes_special_characters(): void
    {
        [$company, $document] = $this->prepareFixture();

        $invoice = $document->documentable;

        $invoice->items()->firstOrFail()->update([
            'description' => 'SOPORTE & DESARROLLO <API>',
        ]);

        $xmlString = $this->buildXml(
            $document->fresh()
        );

        $this->assertStringContainsString(
            '&amp;',
            $xmlString
        );

        $this->assertStringContainsString(
            '&lt;API&gt;',
            $xmlString
        );

        $xml = new DOMDocument;

        $this->assertTrue(
            $xml->loadXML($xmlString)
        );

        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace(
            'cfdi',
            self::NS
        );

        $concept = $xpath
            ->query('//cfdi:Concepto')
            ->item(0);

        $this->assertSame(
            'SOPORTE & DESARROLLO <API>',
            $concept->getAttribute('Descripcion')
        );
    }
}
