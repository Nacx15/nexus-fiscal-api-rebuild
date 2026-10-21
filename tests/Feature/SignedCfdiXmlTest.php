<?php

namespace Tests\Feature;

use App\Application\Fiscal\SignedCfdiXmlService;
use App\Infrastructure\Fiscal\Xml\Cfdi40OriginalStringGenerator;
use DOMDocument;
use InvalidArgumentException;
use PhpCfdi\Credentials\Credential;
use Tests\TestCase;

class SignedCfdiXmlTest extends TestCase
{
    private function makeCredential(): Credential
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $this->assertNotFalse($privateKey);

        $csr = openssl_csr_new([
            'commonName' => 'Nexus Fiscal Test',
            'organizationName' => 'Tests Only',
            'countryName' => 'MX',
        ], $privateKey, [
            'digest_alg' => 'sha256',
        ]);

        $this->assertNotFalse($csr);

        /*
         * Serial de prueba, no del SAT.
         * 0x3132 representa los bytes ASCII "12".
         */
        $certificate = openssl_csr_sign(
            $csr,
            null,
            $privateKey,
            1,
            ['digest_alg' => 'sha256'],
            0x3132
        );

        $this->assertNotFalse($certificate);

        $this->assertTrue(
            openssl_x509_export(
                $certificate,
                $certificatePem
            )
        );

        $this->assertTrue(
            openssl_pkey_export(
                $privateKey,
                $privateKeyPem
            )
        );

        return Credential::create(
            $certificatePem,
            $privateKeyPem,
            ''
        );
    }

    private function unsignedXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante
    xmlns:cfdi="http://www.sat.gob.mx/cfd/4"
    Version="4.0"
    Serie="F"
    Folio="1"
    Fecha="2026-10-21T12:00:00"
    FormaPago="03"
    SubTotal="100.00"
    Moneda="MXN"
    Total="116.00"
    TipoDeComprobante="I"
    Exportacion="01"
    MetodoPago="PUE"
    LugarExpedicion="97000">
    <cfdi:Emisor
        Rfc="AAA010101AAA"
        Nombre="EMPRESA DE PRUEBA"
        RegimenFiscal="601"/>
    <cfdi:Receptor
        Rfc="XAXX010101000"
        Nombre="PUBLICO EN GENERAL"
        DomicilioFiscalReceptor="97000"
        RegimenFiscalReceptor="616"
        UsoCFDI="S01"/>
    <cfdi:Conceptos>
        <cfdi:Concepto
            ClaveProdServ="81111500"
            Cantidad="1.000000"
            ClaveUnidad="E48"
            Descripcion="SERVICIO DE PRUEBA"
            ValorUnitario="100.000000"
            Importe="100.00"
            ObjetoImp="02">
            <cfdi:Impuestos>
                <cfdi:Traslados>
                    <cfdi:Traslado
                        Base="100.00"
                        Impuesto="002"
                        TipoFactor="Tasa"
                        TasaOCuota="0.160000"
                        Importe="16.00"/>
                </cfdi:Traslados>
            </cfdi:Impuestos>
        </cfdi:Concepto>
    </cfdi:Conceptos>
    <cfdi:Impuestos TotalImpuestosTrasladados="16.00">
        <cfdi:Traslados>
            <cfdi:Traslado
                Base="100.00"
                Impuesto="002"
                TipoFactor="Tasa"
                TasaOCuota="0.160000"
                Importe="16.00"/>
        </cfdi:Traslados>
    </cfdi:Impuestos>
</cfdi:Comprobante>
XML;
    }

    public function test_it_generates_original_string_using_sat_xslt(): void
    {
        $credential = $this->makeCredential();

        $result = app(SignedCfdiXmlService::class)
            ->sign(
                $this->unsignedXml(),
                $credential
            );

        $this->assertStringStartsWith(
            '||4.0|',
            $result->originalString
        );

        $this->assertStringContainsString(
            '100.00',
            $result->originalString
        );

        $this->assertStringContainsString(
            '116.00',
            $result->originalString
        );
    }

    public function test_it_adds_certificate_and_valid_seal(): void
    {
        $credential = $this->makeCredential();

        $result = app(SignedCfdiXmlService::class)
            ->sign(
                $this->unsignedXml(),
                $credential
            );

        $xml = new DOMDocument;

        $this->assertTrue(
            $xml->loadXML($result->xml)
        );

        $root = $xml->documentElement;

        $this->assertSame(
            $credential->certificate()
                ->serialNumber()
                ->bytes(),
            $root->getAttribute('NoCertificado')
        );

        $this->assertSame(
            $credential->certificate()
                ->pemAsOneLine(),
            $root->getAttribute('Certificado')
        );

        $this->assertNotEmpty(
            $root->getAttribute('Sello')
        );

        $cadena = app(
            Cfdi40OriginalStringGenerator::class
        )->generate($result->xml);

        $this->assertSame(
            $result->originalString,
            $cadena
        );

        $this->assertTrue(
            $credential->verify(
                $cadena,
                base64_decode($result->seal, true)
            )
        );
    }

    public function test_modified_xml_invalidates_signature(): void
    {
        $credential = $this->makeCredential();

        $result = app(SignedCfdiXmlService::class)
            ->sign(
                $this->unsignedXml(),
                $credential
            );

        /*
         * Cambiamos el total después
         * de generar el sello.
         */
        $tamperedXml = str_replace(
            'Total="116.00"',
            'Total="999.00"',
            $result->xml
        );

        $this->assertNotSame(
            $result->xml,
            $tamperedXml
        );

        $tamperedChain = app(
            Cfdi40OriginalStringGenerator::class
        )->generate($tamperedXml);

        $this->assertFalse(
            $credential->verify(
                $tamperedChain,
                base64_decode($result->seal, true)
            )
        );
    }

    public function test_another_public_key_cannot_verify_the_seal(): void
    {
        $credentialA = $this->makeCredential();
        $credentialB = $this->makeCredential();

        $result = app(SignedCfdiXmlService::class)
            ->sign(
                $this->unsignedXml(),
                $credentialA
            );

        $this->assertFalse(
            $credentialB->verify(
                $result->originalString,
                base64_decode($result->seal, true)
            )
        );
    }

    public function test_it_rejects_non_cfdi_xml(): void
    {
        $credential = $this->makeCredential();

        $this->expectException(
            InvalidArgumentException::class
        );

        app(SignedCfdiXmlService::class)->sign(
            '<documento version="1.0"/>',
            $credential
        );
    }
}
