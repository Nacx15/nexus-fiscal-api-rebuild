<?php

namespace App\Application\Fiscal;

use App\Domain\Fiscal\Cfdi\Exceptions\InvalidCfdiXml;
use App\Domain\Fiscal\Cfdi\SignedCfdiResult;
use App\Infrastructure\Fiscal\Xml\Cfdi40OriginalStringGenerator;
use App\Infrastructure\Fiscal\Xml\Cfdi40XsdValidator;
use DOMDocument;
use DOMXPath;
use PhpCfdi\Credentials\Credential;

final class ValidateSignedCfdiForStamping
{
    private const NS = 'http://www.sat.gob.mx/cfd/4';

    public function __construct(
        private readonly Cfdi40XsdValidator $xsdValidator,
        private readonly Cfdi40OriginalStringGenerator $chainGenerator,
        private readonly SignFiscalString $signer,
    ) {}

    public function execute(
        SignedCfdiResult $result,
        Credential $credential
    ): void {
        /*
         * Primera barrera:
         * XML estructuralmente válido.
         */
        $this->xsdValidator->validate(
            $result->xml
        );

        $xml = new DOMDocument;

        $xml->loadXML(
            $result->xml,
            LIBXML_NONET
        );

        $root = $xml->documentElement;

        $certificate = $credential->certificate();

        $expectedSerial = $certificate
            ->serialNumber()
            ->bytes();

        /*
         * Los atributos finales deben coincidir
         * exactamente con el resultado firmado.
         */
        if (
            $root->getAttribute('Sello')
                !== $result->seal
            || $root->getAttribute('NoCertificado')
                !== $result->certificateNumber
            || $result->certificateNumber
                !== $expectedSerial
            || $root->getAttribute('Certificado')
                !== $certificate->pemAsOneLine()
        ) {
            throw new InvalidCfdiXml([
                'Certificate or signature attributes do not match.',
            ]);
        }

        $xpath = new DOMXPath($xml);

        $xpath->registerNamespace(
            'cfdi',
            self::NS
        );

        $issuer = $xpath->query(
            '/cfdi:Comprobante/cfdi:Emisor'
        )->item(0);

        if (
            $issuer === null
            || strtoupper($issuer->getAttribute('Rfc'))
                !== strtoupper($credential->rfc())
        ) {
            throw new InvalidCfdiXml([
                'Issuer RFC and signing certificate differ.',
            ]);
        }

        /*
         * Verificamos sobre el XML final,
         * no sobre un XML anterior al sello.
         */
        $chain = $this->chainGenerator->generate(
            $result->xml
        );

        if (
            ! hash_equals(
                $result->originalString,
                $chain
            )
            || ! $this->signer->verify(
                $chain,
                $result->seal,
                $credential
            )
        ) {
            throw new InvalidCfdiXml([
                'CFDI digital seal verification failed.',
            ]);
        }

        /*
         * Reglas del subconjunto que
         * actualmente soporta Nexus Fiscal.
         */
        $this->validateSupportedOperation(
            $xml,
            $xpath
        );
    }

    private function validateSupportedOperation(
        DOMDocument $xml,
        DOMXPath $xpath
    ): void {
        $root = $xml->documentElement;

        if (
            $root->getAttribute('TipoDeComprobante') !== 'I'
            || $root->getAttribute('Moneda') !== 'MXN'
            || $root->getAttribute('Exportacion') !== '01'
        ) {
            throw new InvalidCfdiXml([
                'Only domestic MXN income invoices are supported.',
            ]);
        }

        $method = $root->getAttribute('MetodoPago');
        $form = $root->getAttribute('FormaPago');

        if (
            ! in_array($method, ['PUE', 'PPD'], true)
            || ($method === 'PPD' && $form !== '99')
            || ($method === 'PUE' && $form === '99')
        ) {
            throw new InvalidCfdiXml([
                'Unsupported payment method and form combination.',
            ]);
        }

        if (
            $xpath->query(
                '/cfdi:Comprobante/cfdi:Complemento'
                .' | /cfdi:Comprobante/cfdi:CfdiRelacionados'
                .' | /cfdi:Comprobante/cfdi:InformacionGlobal'
            )->length > 0
        ) {
            throw new InvalidCfdiXml([
                'Complements, related CFDIs and global information '
                .'are not supported yet.',
            ]);
        }

        $concepts = $xpath->query(
            '/cfdi:Comprobante/cfdi:Conceptos/cfdi:Concepto'
        );

        if ($concepts->length === 0) {
            throw new InvalidCfdiXml([
                'At least one invoice concept is required.',
            ]);
        }

        foreach ($concepts as $concept) {
            $taxObject = $concept->getAttribute('ObjetoImp');

            $taxes = $xpath->query(
                './cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado',
                $concept
            );

            $withheld = $xpath->query(
                './cfdi:Impuestos/cfdi:Retenciones/cfdi:Retencion',
                $concept
            );

            if ($withheld->length > 0) {
                throw new InvalidCfdiXml([
                    'Tax withholdings are not supported yet.',
                ]);
            }

            if ($taxObject === '01') {
                if ($taxes->length !== 0) {
                    throw new InvalidCfdiXml([
                        'Non-taxable concepts must not contain transfers.',
                    ]);
                }

                continue;
            }

            if (
                $taxObject !== '02'
                || $taxes->length !== 1
            ) {
                throw new InvalidCfdiXml([
                    'Unsupported concept tax configuration.',
                ]);
            }

            $tax = $taxes->item(0);

            if (
                $tax->getAttribute('Impuesto') !== '002'
                || $tax->getAttribute('TipoFactor') !== 'Tasa'
                || bccomp(
                    $tax->getAttribute('TasaOCuota'),
                    '0.160000',
                    6
                ) !== 0
            ) {
                throw new InvalidCfdiXml([
                    'Only IVA 16% transfers are supported.',
                ]);
            }

            $subtotal = bcmul(
                $concept->getAttribute('Cantidad'),
                $concept->getAttribute('ValorUnitario'),
                6
            );

            if (
                bccomp(
                    $subtotal,
                    $concept->getAttribute('Importe'),
                    6
                ) !== 0
            ) {
                throw new InvalidCfdiXml([
                    'Concept amount does not match quantity and price.',
                ]);
            }

            $base = bcsub(
                $concept->getAttribute('Importe'),
                $concept->getAttribute('Descuento') ?: '0',
                6
            );

            $expectedTax = bcmul(
                $base,
                '0.160000',
                6
            );

            if (
                bccomp(
                    $base,
                    $tax->getAttribute('Base'),
                    6
                ) !== 0
                || bccomp(
                    $expectedTax,
                    $tax->getAttribute('Importe'),
                    6
                ) !== 0
            ) {
                throw new InvalidCfdiXml([
                    'Concept tax base or amount is inconsistent.',
                ]);
            }
        }
    }
}
