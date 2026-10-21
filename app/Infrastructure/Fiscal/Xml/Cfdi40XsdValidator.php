<?php

namespace App\Infrastructure\Fiscal\Xml;

use App\Domain\Fiscal\Cfdi\Exceptions\InvalidCfdiXml;
use DOMDocument;
use DOMElement;

final class Cfdi40XsdValidator
{
    public function __construct(
        private readonly Cfdi40XsdRepository $repository
    ) {}

    public function validate(string $xml): void
    {
        if (
            trim($xml) === ''
            || strlen($xml) > 8_000_000
        ) {
            throw new InvalidCfdiXml([
                'XML is empty or exceeds the supported size.',
            ]);
        }

        /*
         * Fallamos antes de validar si no
         * existe un XSD local confiable.
         */
        $xsd = $this->repository->path();

        $document = new DOMDocument(
            '1.0',
            'UTF-8'
        );

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET
            );

            if (
                ! $loaded
                || $document->doctype !== null
            ) {
                throw new InvalidCfdiXml(
                    $this->errors(
                        'Malformed XML or forbidden DOCTYPE.'
                    )
                );
            }

            $root = $document->documentElement;

            if (
                ! $root instanceof DOMElement
                || $root->namespaceURI
                    !== 'http://www.sat.gob.mx/cfd/4'
                || $root->localName !== 'Comprobante'
            ) {
                throw new InvalidCfdiXml([
                    'Root element must be CFDI 4.0 Comprobante.',
                ]);
            }

            /*
             * Validación contra los XSD
             * oficiales almacenados localmente.
             */
            if (! $document->schemaValidate($xsd)) {
                throw new InvalidCfdiXml(
                    $this->errors(
                        'CFDI 4.0 XSD validation failed.'
                    )
                );
            }
        } finally {
            libxml_clear_errors();

            libxml_use_internal_errors(
                $previous
            );
        }
    }

    private function errors(
        string $fallback
    ): array {
        $issues = [];

        foreach (libxml_get_errors() as $error) {
            $issues[] = sprintf(
                'Line %d, column %d: %s',
                $error->line,
                $error->column,
                trim($error->message)
            );
        }

        return array_slice(
            $issues ?: [$fallback],
            0,
            20
        );
    }
}
