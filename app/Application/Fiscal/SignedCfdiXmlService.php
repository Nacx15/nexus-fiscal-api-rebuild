<?php

namespace App\Application\Fiscal;

use App\Domain\Fiscal\Cfdi\SignedCfdiResult;
use App\Infrastructure\Fiscal\Xml\Cfdi40OriginalStringGenerator;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use PhpCfdi\Credentials\Credential;
use RuntimeException;

final class SignedCfdiXmlService
{
    private const CFDI_NAMESPACE =
        'http://www.sat.gob.mx/cfd/4';

    public function __construct(
        private readonly Cfdi40OriginalStringGenerator $generator,
        private readonly SignFiscalString $signer,
    ) {}

    public function sign(
        string $unsignedXml,
        Credential $credential
    ): SignedCfdiResult {
        $xml = new DOMDocument('1.0', 'UTF-8');

        if (
            ! $xml->loadXML(
                $unsignedXml,
                LIBXML_NONET
            )
        ) {
            throw new InvalidArgumentException(
                'Unable to parse unsigned CFDI XML.'
            );
        }

        $root = $xml->documentElement;

        if (
            ! $root instanceof DOMElement
            || $root->namespaceURI !== self::CFDI_NAMESPACE
            || $root->localName !== 'Comprobante'
            || $root->getAttribute('Version') !== '4.0'
        ) {
            throw new InvalidArgumentException(
                'The document is not CFDI 4.0.'
            );
        }

        /*
         * Rechazamos documentos ya firmados.
         * También evitamos procesar XML con DTD.
         */
        if (
            $xml->doctype !== null
            || $root->hasAttribute('Sello')
            || $root->hasAttribute('Certificado')
            || $root->hasAttribute('NoCertificado')
        ) {
            throw new InvalidArgumentException(
                'The input must be an unsigned CFDI.'
            );
        }

        $certificate = $credential->certificate();

        $certificateNumber = $certificate
            ->serialNumber()
            ->bytes();

        /*
         * Certificado: X.509 DER en Base64.
         * No incluye cabeceras PEM.
         */
        $root->setAttribute(
            'NoCertificado',
            $certificateNumber
        );

        $root->setAttribute(
            'Certificado',
            $certificate->pemAsOneLine()
        );

        $xmlWithCertificate = $xml->saveXML();

        if ($xmlWithCertificate === false) {
            throw new RuntimeException(
                'Unable to serialize CFDI before signing.'
            );
        }

        /*
         * Generamos la cadena después de
         * insertar el número de certificado.
         */
        $originalString = $this->generator->generate(
            $xmlWithCertificate
        );

        $seal = $this->signer->sign(
            $originalString,
            $credential
        );

        $root->setAttribute('Sello', $seal);

        $signedXml = $xml->saveXML();

        if ($signedXml === false) {
            throw new RuntimeException(
                'Unable to serialize signed CFDI.'
            );
        }

        /*
         * Verificación:
         * volvemos a generar la cadena desde
         * el XML FINAL, ya con el sello.
         */
        $recalculated = $this->generator->generate(
            $signedXml
        );

        if (! hash_equals(
            $originalString,
            $recalculated
        )) {
            throw new RuntimeException(
                'Original string changed after signing.'
            );
        }

        if (! $this->signer->verify(
            $recalculated,
            $seal,
            $credential
        )) {
            throw new RuntimeException(
                'CFDI signature verification failed.'
            );
        }

        return new SignedCfdiResult(
            xml: $signedXml,
            originalString: $originalString,
            certificateNumber: $certificateNumber,
            seal: $seal
        );
    }
}
