<?php

namespace App\Infrastructure\Fiscal\Xml;

use App\Domain\Fiscal\Cfdi\Exceptions\InvalidStampedCfdi;
use App\Domain\Fiscal\Cfdi\VerifiedStamp;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMText;

final class StampedCfdiVerifier
{
    private const CFDI_NS = 'http://www.sat.gob.mx/cfd/4';

    private const TFD_NS =
        'http://www.sat.gob.mx/TimbreFiscalDigital';

    private const XMLNS_NS =
        'http://www.w3.org/2000/xmlns/';

    private const XSI_NS =
        'http://www.w3.org/2001/XMLSchema-instance';

    public function verify(
        string $originalXml,
        string $stampedXml,
        string $expectedUuid
    ): VerifiedStamp {
        $before = $this->parse($originalXml);
        $after = $this->parse($stampedXml);

        $source = $before->documentElement;
        $target = $after->documentElement;

        if (
            ! $this->isComprobante($source)
            || ! $this->isComprobante($target)
            || $source->getAttribute('Version') !== '4.0'
            || $target->getAttribute('Version') !== '4.0'
        ) {
            throw new InvalidStampedCfdi(
                'Expected two CFDI 4.0 documents.'
            );
        }

        /*
         * Nuestra versión soportada no tiene
         * complementos antes del timbrado.
         */
        if (
            $source->getElementsByTagNameNS(
                self::CFDI_NS,
                'Complemento'
            )->length !== 0
        ) {
            throw new InvalidStampedCfdi(
                'Original XML already contains a complement.'
            );
        }

        /*
         * Comparamos toda la estructura fiscal,
         * excepto el nuevo Complemento raíz.
         */
        if (
            $this->shape($source, true)
            !== $this->shape($target, true)
        ) {
            throw new InvalidStampedCfdi(
                'The PAC modified the original CFDI content.'
            );
        }

        $complements = [];

        foreach ($target->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && $child->namespaceURI === self::CFDI_NS
                && $child->localName === 'Complemento'
            ) {
                $complements[] = $child;
            }
        }

        if (count($complements) !== 1) {
            throw new InvalidStampedCfdi(
                'Exactly one CFDI complement is required.'
            );
        }

        $elements = [];

        foreach ($complements[0]->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }

        if (
            count($elements) !== 1
            || $elements[0]->namespaceURI !== self::TFD_NS
            || $elements[0]->localName
                !== 'TimbreFiscalDigital'
        ) {
            throw new InvalidStampedCfdi(
                'Expected one TimbreFiscalDigital.'
            );
        }

        $timbre = $elements[0];

        $uuid = $timbre->getAttribute('UUID');
        $timestamp = $timbre->getAttribute('FechaTimbrado');
        $cfdiSeal = $source->getAttribute('Sello');
        $satSeal = $timbre->getAttribute('SelloSAT');
        $satSerial = $timbre->getAttribute('NoCertificadoSAT');
        $issuerSerial = $source->getAttribute('NoCertificado');

        if (
            ! preg_match(
                '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i',
                $uuid
            )
            || strcasecmp($uuid, $expectedUuid) !== 0
            || $timbre->getAttribute('Version') !== '1.1'
            || $timbre->getAttribute('SelloCFD') !== $cfdiSeal
            || ! preg_match('/^\d{20}$/', $satSerial)
            || ! preg_match('/^\d{20}$/', $issuerSerial)
            || trim($timbre->getAttribute('RfcProvCertif')) === ''
            || ! $this->validBase64($cfdiSeal)
            || ! $this->validBase64($satSeal)
            || ! $this->validTimestamp($timestamp)
        ) {
            throw new InvalidStampedCfdi(
                'Invalid or inconsistent fiscal stamp attributes.'
            );
        }

        return new VerifiedStamp(
            uuid: strtoupper($uuid),
            stampedAt: $timestamp,
            issuerCertificateNumber: $issuerSerial,
            satCertificateNumber: $satSerial,
            cfdiSeal: $cfdiSeal,
            satSeal: $satSeal,
        );
    }

    private function parse(string $contents): DOMDocument
    {
        if (
            trim($contents) === ''
            || strlen($contents) > 8_000_000
        ) {
            throw new InvalidStampedCfdi(
                'XML size is invalid.'
            );
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        try {
            if (
                ! $document->loadXML(
                    $contents,
                    LIBXML_NONET
                )
                || $document->doctype !== null
            ) {
                throw new InvalidStampedCfdi(
                    'XML is malformed or contains a DOCTYPE.'
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function isComprobante(
        ?DOMElement $element
    ): bool {
        return $element !== null
            && $element->namespaceURI === self::CFDI_NS
            && $element->localName === 'Comprobante';
    }

    /*
     * Representación semántica de elementos,
     * atributos y contenido.
     *
     * Ignoramos prefijos XML, declaraciones xmlns,
     * schemaLocation y whitespace entre nodos.
     */
    private function shape(
        DOMElement $element,
        bool $root = false
    ): array {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI === self::XMLNS_NS) {
                continue;
            }

            if (
                $attribute->namespaceURI === self::XSI_NS
                && $attribute->localName === 'schemaLocation'
            ) {
                continue;
            }

            $key = ($attribute->namespaceURI ?? '')
                .'|'.$attribute->localName;

            $attributes[$key] = $attribute->value;
        }

        ksort($attributes);

        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (
                    $root
                    && $child->namespaceURI === self::CFDI_NS
                    && $child->localName === 'Complemento'
                ) {
                    continue;
                }

                $children[] = $this->shape($child);
            } elseif (
                $child instanceof DOMText
                && trim($child->nodeValue) !== ''
            ) {
                $children[] = [
                    '#text' => $child->nodeValue,
                ];
            }
        }

        return [
            'namespace' => $element->namespaceURI,
            'name' => $element->localName,
            'attributes' => $attributes,
            'children' => $children,
        ];
    }

    private function validBase64(string $value): bool
    {
        return $value !== ''
            && base64_decode($value, true) !== false
            && base64_decode($value, true) !== '';
    }

    private function validTimestamp(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s',
            $value
        );

        return $date !== false
            && $date->format('Y-m-d\TH:i:s') === $value;
    }
}
