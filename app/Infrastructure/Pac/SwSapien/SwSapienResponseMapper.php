<?php

namespace App\Infrastructure\Pac\SwSapien;

use App\Domain\Fiscal\Dto\PacStampResult;
use DOMDocument;
use DOMElement;

final class SwSapienResponseMapper
{
    private const CFDI_NS =
        'http://www.sat.gob.mx/cfd/4';

    private const TFD_NS =
        'http://www.sat.gob.mx/TimbreFiscalDigital';

    public function map(
        int $httpStatus,
        ?array $payload,
        string $originalXml
    ): PacStampResult {
        if ($payload === null) {
            return PacStampResult::unknown(
                'PAC_INVALID_RESPONSE'
            );
        }

        if (
            $httpStatus === 200
            && ($payload['status'] ?? null) === 'success'
        ) {
            $data = $payload['data'] ?? null;

            if (! is_array($data)) {
                return PacStampResult::unknown(
                    'PAC_MISSING_DATA'
                );
            }

            $uuid = $data['uuid'] ?? null;
            $stampedXml = $data['cfdi'] ?? null;

            if (
                ! is_string($uuid)
                || ! is_string($stampedXml)
                || ! $this->matches(
                    $originalXml,
                    $stampedXml,
                    $uuid
                )
            ) {
                return PacStampResult::unknown(
                    'PAC_INVALID_STAMPED_XML'
                );
            }

            return PacStampResult::received(
                $uuid,
                $stampedXml
            );
        }

        /*
         * Sólo algunos errores explícitos,
         * sin evidencia de timbrado, se
         * clasifican como rechazo definitivo.
         *
         * La lista es intencionalmente reducida.
         */
        if (
            $httpStatus === 400
            && ($payload['status'] ?? null) === 'error'
            && empty($payload['data'])
        ) {
            $message = $payload['message'] ?? '';

            if (
                is_string($message)
                && preg_match(
                    '/^(301|CFDI40101)\s*-/',
                    $message,
                    $matches
                )
            ) {
                return PacStampResult::rejected(
                    $matches[1]
                );
            }
        }

        /*
         * Incluye:
         * - errores HTTP 5xx
         * - respuestas incompletas
         * - errores de autenticación no analizados
         * - duplicados reportados por SW
         * - errores no clasificados
         *
         * Ninguno habilita reenvío automático.
         */
        return PacStampResult::unknown(
            'PAC_UNCONFIRMED_RESULT'
        );
    }

    private function matches(
        string $original,
        string $stamped,
        string $expectedUuid
    ): bool {
        if (
            ! preg_match(
                '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i',
                $expectedUuid
            )
            || strlen($stamped) > 8_000_000
        ) {
            return false;
        }

        $before = $this->parse($original);
        $after = $this->parse($stamped);

        if ($before === null || $after === null) {
            return false;
        }

        $source = $before->documentElement;
        $target = $after->documentElement;

        if (
            ! $source instanceof DOMElement
            || ! $target instanceof DOMElement
            || $source->namespaceURI !== self::CFDI_NS
            || $target->namespaceURI !== self::CFDI_NS
            || $source->localName !== 'Comprobante'
            || $target->localName !== 'Comprobante'
        ) {
            return false;
        }

        foreach ([
            'Version',
            'Serie',
            'Folio',
            'Fecha',
            'Sello',
            'NoCertificado',
            'SubTotal',
            'Total',
            'Moneda',
            'TipoDeComprobante',
        ] as $attribute) {
            if (
                $source->getAttribute($attribute)
                !== $target->getAttribute($attribute)
            ) {
                return false;
            }
        }

        foreach (['Emisor', 'Receptor'] as $name) {
            $sourceNode = $source
                ->getElementsByTagNameNS(
                    self::CFDI_NS,
                    $name
                )->item(0);

            $targetNode = $target
                ->getElementsByTagNameNS(
                    self::CFDI_NS,
                    $name
                )->item(0);

            if (
                ! $sourceNode instanceof DOMElement
                || ! $targetNode instanceof DOMElement
                || $sourceNode->getAttribute('Rfc')
                    !== $targetNode->getAttribute('Rfc')
            ) {
                return false;
            }
        }

        $complements = $target
            ->getElementsByTagNameNS(
                self::CFDI_NS,
                'Complemento'
            );

        if ($complements->length !== 1) {
            return false;
        }

        $timbres = $complements->item(0)
            ->getElementsByTagNameNS(
                self::TFD_NS,
                'TimbreFiscalDigital'
            );

        if ($timbres->length !== 1) {
            return false;
        }

        $timbre = $timbres->item(0);

        return
            strcasecmp(
                $timbre->getAttribute('UUID'),
                $expectedUuid
            ) === 0
            && $timbre->getAttribute('Version') === '1.1'
            && $timbre->getAttribute('SelloCFD')
                === $source->getAttribute('Sello')
            && $timbre->getAttribute('SelloSAT') !== ''
            && $timbre->getAttribute('FechaTimbrado') !== '';
    }

    private function parse(string $contents): ?DOMDocument
    {
        if (
            trim($contents) === ''
            || strlen($contents) > 8_000_000
        ) {
            return null;
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            if (
                ! $document->loadXML(
                    $contents,
                    LIBXML_NONET
                )
                || $document->doctype !== null
            ) {
                return null;
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
