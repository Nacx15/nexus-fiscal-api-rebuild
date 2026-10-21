<?php

namespace Tests\Feature;

use App\Application\Fiscal\ValidateSignedCfdiForStamping;
use App\Domain\Fiscal\Cfdi\Exceptions\InvalidCfdiXml;
use App\Domain\Fiscal\Cfdi\SignedCfdiResult;
use App\Infrastructure\Fiscal\Xml\Cfdi40XsdValidator;
use PhpCfdi\Credentials\Credential;
use Tests\TestCase;

class Cfdi40XsdValidationTest extends TestCase
{
    private function sampleXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante
    xmlns:cfdi="http://www.sat.gob.mx/cfd/4"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd"
    Version="4.0"
    Serie="F"
    Folio="1"
    Fecha="2026-10-21T12:00:00"
    Sello="AQID"
    FormaPago="03"
    NoCertificado="00001000000500000001"
    Certificado="AQID"
    SubTotal="100.00"
    Moneda="MXN"
    Total="116.00"
    TipoDeComprobante="I"
    Exportacion="01"
    MetodoPago="PUE"
    LugarExpedicion="97000">
    <cfdi:Emisor
        Rfc="AAA010101AAA"
        Nombre="EMPRESA DEMO"
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

    public function test_structurally_correct_xml_passes_xsd(): void
    {
        app(Cfdi40XsdValidator::class)->validate(
            $this->sampleXml()
        );

        $this->assertTrue(true);
    }

    public function test_missing_certificate_number_is_rejected(): void
    {
        $xml = str_replace(
            'NoCertificado="00001000000500000001"',
            '',
            $this->sampleXml()
        );

        $this->expectException(
            InvalidCfdiXml::class
        );

        app(Cfdi40XsdValidator::class)
            ->validate($xml);
    }

    public function test_invalid_certificate_serial_length_is_rejected(): void
    {
        $xml = str_replace(
            '00001000000500000001',
            '12345',
            $this->sampleXml()
        );

        $this->expectException(
            InvalidCfdiXml::class
        );

        app(Cfdi40XsdValidator::class)
            ->validate($xml);
    }

    public function test_missing_receiver_is_rejected(): void
    {
        $xml = preg_replace(
            '~<cfdi:Receptor\b[^>]*/>~',
            '',
            $this->sampleXml()
        );

        $this->expectException(
            InvalidCfdiXml::class
        );

        app(Cfdi40XsdValidator::class)
            ->validate($xml);
    }

    public function test_malformed_xml_is_rejected(): void
    {
        $this->expectException(
            InvalidCfdiXml::class
        );

        app(Cfdi40XsdValidator::class)
            ->validate(
                '<cfdi:Comprobante>'
            );
    }

    public function test_wrong_namespace_is_rejected(): void
    {
        $xml = str_replace(
            'http://www.sat.gob.mx/cfd/4',
            'http://www.example.com/incorrect',
            $this->sampleXml()
        );

        $this->expectException(
            InvalidCfdiXml::class
        );

        app(Cfdi40XsdValidator::class)
            ->validate($xml);
    }

    public function test_invalid_tax_object_is_rejected_by_xsd(): void
    {
        $xml = str_replace(
            'ObjetoImp="02"',
            'ObjetoImp="99"',
            $this->sampleXml()
        );

        $this->expectException(
            InvalidCfdiXml::class
        );

        app(Cfdi40XsdValidator::class)
            ->validate($xml);
    }

    private function makeTestCredential(): Credential
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $this->assertNotFalse($key);

        $csr = openssl_csr_new([
            'commonName' => 'Nexus Fiscal Test',
            'organizationName' => 'Tests Only',
            'countryName' => 'MX',
        ], $key, [
            'digest_alg' => 'sha256',
        ]);

        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign(
            $csr,
            null,
            $key,
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
                $key,
                $keyPem
            )
        );

        return Credential::create(
            $certificatePem,
            $keyPem,
            ''
        );
    }

    public function test_preflight_rejects_fictitious_certificate_and_seal(): void
    {
        $result = new SignedCfdiResult(
            xml: $this->sampleXml(),
            originalString: 'NOT_A_SAT_ORIGINAL_STRING',
            certificateNumber: '00001000000500000001',
            seal: 'AQID'
        );

        $credential = $this->makeTestCredential();

        $this->expectException(
            InvalidCfdiXml::class
        );

        app(ValidateSignedCfdiForStamping::class)
            ->execute(
                $result,
                $credential
            );
    }
}
