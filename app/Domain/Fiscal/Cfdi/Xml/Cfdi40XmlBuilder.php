<?php

namespace App\Domain\Fiscal\Cfdi\Xml;

use App\Domain\Fiscal\Cfdi\Exceptions\UnsupportedCfdiOperation;
use App\Domain\Fiscal\Enums\FiscalDocumentStatus;
use App\Domain\Invoice\Enums\InvoiceStatus;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class Cfdi40XmlBuilder
{
    private const NS = 'http://www.sat.gob.mx/cfd/4';

    private const XSI =
        'http://www.w3.org/2001/XMLSchema-instance';

    private const XSD =
        'http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd';

    public function build(
        FiscalDocument $document,
        CarbonImmutable $issuedAt
    ): string {
        if (
            $document->status !== FiscalDocumentStatus::Pending
            || $document->cfdi_type !== 'I'
        ) {
            throw new UnsupportedCfdiOperation(
                'Only pending income documents are supported.'
            );
        }

        $invoice = $document->documentable;

        if (
            ! $invoice instanceof Invoice
            || $invoice->company_id !== $document->company_id
            || $invoice->status !== InvoiceStatus::Ready
        ) {
            throw new UnsupportedCfdiOperation(
                'The fiscal document requires a ready invoice.'
            );
        }

        $invoice->loadMissing('items.taxes');

        if (
            $invoice->items->isEmpty()
            || ! $invoice->series
            || ! $invoice->folio
        ) {
            throw new UnsupportedCfdiOperation(
                'Invoice items and fiscal folio are required.'
            );
        }

        if (
            $invoice->currency !== 'MXN'
            || $document->currency !== 'MXN'
            || bccomp($invoice->exchange_rate, '1', 6) !== 0
        ) {
            throw new UnsupportedCfdiOperation(
                'Only MXN invoices are supported.'
            );
        }

        if (! in_array(
            $invoice->payment_method,
            ['PUE', 'PPD'],
            true
        )) {
            throw new UnsupportedCfdiOperation(
                'Unsupported payment method.'
            );
        }

        if (
            ($invoice->payment_method === 'PPD'
                && $invoice->payment_form !== '99')
            ||
            ($invoice->payment_method === 'PUE'
                && $invoice->payment_form === '99')
        ) {
            throw new UnsupportedCfdiOperation(
                'Invalid payment form and method combination.'
            );
        }

        /*
         * Primera versión: operaciones nacionales
         * sin exportación ni complementos.
         */
        foreach ([
            $document->issuer_rfc,
            $document->issuer_name,
            $document->issuer_tax_regime,
            $document->issuer_postal_code,
            $document->receiver_rfc,
            $document->receiver_name,
            $document->receiver_tax_regime,
            $document->receiver_postal_code,
            $invoice->cfdi_use,
        ] as $requiredValue) {
            if (trim((string) $requiredValue) === '') {
                throw new UnsupportedCfdiOperation(
                    'Missing required fiscal data.'
                );
            }
        }

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;

        $root = $xml->createElementNS(
            self::NS,
            'cfdi:Comprobante'
        );

        $xml->appendChild($root);

        $root->setAttributeNS(
            self::XSI,
            'xsi:schemaLocation',
            self::NS.' '.self::XSD
        );

        $this->attributes($root, [
            'Version' => '4.0',
            'Serie' => $invoice->series,
            'Folio' => (string) $invoice->folio,
            'Fecha' => $issuedAt->format('Y-m-d\TH:i:s'),
            'FormaPago' => $invoice->payment_form,
            'SubTotal' => $this->money(
                $document->subtotal
            ),
            'Moneda' => 'MXN',
            'Total' => $this->money($document->total),
            'TipoDeComprobante' => 'I',
            'Exportacion' => '01',
            'MetodoPago' => $invoice->payment_method,
            'LugarExpedicion' => $document->issuer_postal_code,
        ]);

        if (bccomp($document->discount, '0', 6) > 0) {
            $root->setAttribute(
                'Descuento',
                $this->money($document->discount)
            );
        }

        $emisor = $this->element(
            $xml,
            $root,
            'Emisor'
        );

        $this->attributes($emisor, [
            'Rfc' => $document->issuer_rfc,
            'Nombre' => $document->issuer_name,
            'RegimenFiscal' => $document->issuer_tax_regime,
        ]);

        $receptor = $this->element(
            $xml,
            $root,
            'Receptor'
        );

        $this->attributes($receptor, [
            'Rfc' => $document->receiver_rfc,
            'Nombre' => $document->receiver_name,
            'DomicilioFiscalReceptor' => $document->receiver_postal_code,
            'RegimenFiscalReceptor' => $document->receiver_tax_regime,
            'UsoCFDI' => $invoice->cfdi_use,
        ]);

        $conceptos = $this->element(
            $xml,
            $root,
            'Conceptos'
        );

        $subtotal = '0.000000';
        $discount = '0.000000';
        $transferred = '0.000000';

        $taxBaseTotal = '0.000000';
        $hasTaxTransfers = false;

        foreach ($invoice->items as $item) {
            $itemSubtotal = $this->money(
                $item->subtotal
            );

            if (
                bccomp($item->quantity, '0', 6) <= 0
                || bccomp($item->unit_price, '0', 6) < 0
            ) {
                throw new UnsupportedCfdiOperation(
                    'Invalid item quantity or unit price.'
                );
            }

            $calculatedSubtotal = bcmul(
                $item->quantity,
                $item->unit_price,
                6
            );

            if (
                bccomp(
                    $calculatedSubtotal,
                    $item->subtotal,
                    6
                ) !== 0
            ) {
                throw new UnsupportedCfdiOperation(
                    'Item subtotal does not match quantity and price.'
                );
            }

            $itemDiscount = $this->money(
                $item->discount
            );

            $base = bcsub(
                $itemSubtotal,
                $itemDiscount,
                6
            );

            if (bccomp($base, '0', 6) < 0) {
                throw new UnsupportedCfdiOperation(
                    'Negative item tax base.'
                );
            }

            $concepto = $this->element(
                $xml,
                $conceptos,
                'Concepto'
            );

            $this->attributes($concepto, [
                'ClaveProdServ' => $item->product_code,
                'Cantidad' => $item->quantity,
                'ClaveUnidad' => $item->unit_code,
                'Descripcion' => $item->description,
                'ValorUnitario' => $item->unit_price,
                'Importe' => $itemSubtotal,
                'ObjetoImp' => $item->tax_object,
            ]);

            if (bccomp($itemDiscount, '0', 6) > 0) {
                $concepto->setAttribute(
                    'Descuento',
                    $itemDiscount
                );
            }

            $itemTaxAmount = '0.000000';

            if ($item->tax_object === '01') {
                if ($item->taxes->isNotEmpty()) {
                    throw new UnsupportedCfdiOperation(
                        'Non-taxable item contains taxes.'
                    );
                }
            } elseif ($item->tax_object === '02') {
                /*
                 * Alcance actual:
                 * un traslado IVA 16% por concepto.
                 */
                if ($item->taxes->count() !== 1) {
                    throw new UnsupportedCfdiOperation(
                        'Exactly one IVA transfer is required.'
                    );
                }

                $tax = $item->taxes->first();

                if (
                    $tax->tax !== '002'
                    || $tax->type !== 'transferred'
                    || $tax->factor_type !== 'Tasa'
                    || bccomp(
                        (string) $tax->rate_or_quota,
                        '0.160000',
                        6
                    ) !== 0
                    || bccomp($tax->base, $base, 6) !== 0
                ) {
                    throw new UnsupportedCfdiOperation(
                        'Only IVA 16% transfers are supported.'
                    );
                }

                $itemTaxAmount = $this->money(
                    $tax->amount
                );

                /*
                 * Evitamos aceptar importes
                 * fiscales incongruentes.
                 */
                $expectedTax = bcmul(
                    $base,
                    '0.160000',
                    6
                );

                if (
                    bccomp(
                        $expectedTax,
                        $itemTaxAmount,
                        6
                    ) !== 0
                ) {
                    throw new UnsupportedCfdiOperation(
                        'Tax amount does not match its base.'
                    );
                }

                $impuestos = $this->element(
                    $xml,
                    $concepto,
                    'Impuestos'
                );

                $traslados = $this->element(
                    $xml,
                    $impuestos,
                    'Traslados'
                );

                $traslado = $this->element(
                    $xml,
                    $traslados,
                    'Traslado'
                );

                $this->attributes($traslado, [
                    'Base' => $this->money($base),
                    'Impuesto' => '002',
                    'TipoFactor' => 'Tasa',
                    'TasaOCuota' => '0.160000',
                    'Importe' => $itemTaxAmount,
                ]);

                $transferred = bcadd(
                    $transferred,
                    $itemTaxAmount,
                    6
                );

                $taxBaseTotal = bcadd(
                    $taxBaseTotal,
                    $base,
                    6
                );

                $hasTaxTransfers = true;
            } else {
                throw new UnsupportedCfdiOperation(
                    'Unsupported tax object.'
                );
            }

            $expectedItemTotal = bcadd(
                $base,
                $itemTaxAmount,
                6
            );

            if (
                bccomp(
                    $expectedItemTotal,
                    $item->total,
                    6
                ) !== 0
            ) {
                throw new UnsupportedCfdiOperation(
                    'Item total is inconsistent.'
                );
            }

            $subtotal = bcadd(
                $subtotal,
                $itemSubtotal,
                6
            );

            $discount = bcadd(
                $discount,
                $itemDiscount,
                6
            );
        }

        $expectedTotal = bcadd(
            bcsub($subtotal, $discount, 6),
            $transferred,
            6
        );

        /*
         * Comparación contra factura y snapshot.
         */
        foreach ([
            [$subtotal, $invoice->subtotal],
            [$subtotal, $document->subtotal],
            [$discount, $invoice->discount],
            [$discount, $document->discount],
            [$transferred, $invoice->transferred_taxes],
            [$transferred, $document->transferred_taxes],
            [$expectedTotal, $invoice->total],
            [$expectedTotal, $document->total],
            ['0', $invoice->withheld_taxes],
            ['0', $document->withheld_taxes],
        ] as [$calculated, $stored]) {
            if (bccomp($calculated, $stored, 6) !== 0) {
                throw new UnsupportedCfdiOperation(
                    'Invoice and fiscal snapshot totals differ.'
                );
            }
        }

        if ($hasTaxTransfers) {
            $impuestos = $this->element(
                $xml,
                $root,
                'Impuestos'
            );

            $impuestos->setAttribute(
                'TotalImpuestosTrasladados',
                $this->money($transferred)
            );

            $traslados = $this->element(
                $xml,
                $impuestos,
                'Traslados'
            );

            $traslado = $this->element(
                $xml,
                $traslados,
                'Traslado'
            );

            $this->attributes($traslado, [
                'Base' => $this->money($taxBaseTotal),
                'Impuesto' => '002',
                'TipoFactor' => 'Tasa',
                'TasaOCuota' => '0.160000',
                'Importe' => $this->money($transferred),
            ]);
        }

        $result = $xml->saveXML();

        if ($result === false) {
            throw new RuntimeException(
                'Unable to serialize CFDI XML.'
            );
        }

        return $result;
    }

    private function element(
        DOMDocument $xml,
        DOMElement $parent,
        string $name
    ): DOMElement {
        $element = $xml->createElementNS(
            self::NS,
            'cfdi:'.$name
        );

        $parent->appendChild($element);

        return $element;
    }

    private function attributes(
        DOMElement $element,
        array $attributes
    ): void {
        foreach ($attributes as $name => $value) {
            $element->setAttribute(
                $name,
                (string) $value
            );
        }
    }

    /*
     * Restricción temporal:
     * montos exactos a centavos para MXN.
     *
     * No redondeamos silenciosamente.
     */
    private function money(string $value): string
    {
        if (
            bccomp($value, '0', 6) < 0
            || bccomp(
                $value,
                bcadd($value, '0', 2),
                6
            ) !== 0
        ) {
            throw new UnsupportedCfdiOperation(
                'Only exact two-decimal monetary amounts are supported.'
            );
        }

        return bcadd($value, '0', 2);
    }
}
