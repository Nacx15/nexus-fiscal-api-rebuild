<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Cfdi\Exceptions\InvalidCfdiXml;
use App\Domain\Fiscal\Cfdi\SignedCfdiResult;
use App\Domain\Fiscal\Enums\FiscalDocumentStatus;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Domain\Invoice\Enums\InvoiceStatus;
use App\Infrastructure\Fiscal\Storage\PrivateStampXmlStore;
use App\Models\FiscalDocument;
use App\Models\FiscalStampAttempt;
use App\Models\Invoice;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use PhpCfdi\Credentials\Credential;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class StorePreparedStampXml
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ValidateSignedCfdiForStamping $validator,
        private readonly PrivateStampXmlStore $storage
    ) {}

    public function execute(
        FiscalStampAttempt $attempt,
        SignedCfdiResult $signed,
        Credential $credential
    ): FiscalStampAttempt {
        /*
         * No persistimos nada si el XML no
         * superó XSD, reglas admitidas y firma.
         */
        $this->validator->execute(
            $signed,
            $credential
        );

        return DB::transaction(function () use (
            $attempt,
            $signed
        ) {
            $companyId = $this->companyContext->id();

            $document = FiscalDocument::query()
                ->where('company_id', $companyId)
                ->whereKey($attempt->fiscal_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            $attempt = FiscalStampAttempt::query()
                ->where('company_id', $companyId)
                ->where('fiscal_document_id', $document->id)
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $document->status
                !== FiscalDocumentStatus::Pending
            ) {
                throw new ConflictHttpException(
                    'Fiscal document is no longer pending.'
                );
            }

            $xml = new DOMDocument;

            $loaded = $xml->loadXML(
                $signed->xml,
                LIBXML_NONET
            );

            if (
                ! $loaded
                || $xml->doctype !== null
            ) {
                throw new InvalidCfdiXml([
                    'Invalid signed XML.',
                ]);
            }

            $root = $xml->documentElement;

            /*
             * La fecha no puede variar entre
             * reserva y preparación del XML.
             */
            if (
                $root->getAttribute('Fecha') !== $attempt->cfdi_date
                || $root->getAttribute('NoCertificado')
                    !== $signed->certificateNumber
                || $root->getAttribute('Sello') !== $signed->seal
            ) {
                throw new InvalidCfdiXml([
                    'Signed XML does not match the reserved attempt.',
                ]);
            }

            /*
             * Comparamos contra snapshots
             * y folio de la factura original.
             */
            $invoice = $document->documentable;

            $emisor = $xml->getElementsByTagNameNS(
                'http://www.sat.gob.mx/cfd/4',
                'Emisor'
            )->item(0);

            $receptor = $xml->getElementsByTagNameNS(
                'http://www.sat.gob.mx/cfd/4',
                'Receptor'
            )->item(0);

            if (
                ! $invoice instanceof Invoice
                || $invoice->company_id !== $companyId
                || $invoice->status
                    !== InvoiceStatus::Ready
                || ! $emisor instanceof DOMElement
                || ! $receptor instanceof DOMElement
                || $emisor->getAttribute('Rfc')
                    !== $document->issuer_rfc
                || $receptor->getAttribute('Rfc')
                    !== $document->receiver_rfc
                || $root->getAttribute('Serie')
                    !== $invoice->series
                || $root->getAttribute('Folio')
                    !== (string) $invoice->folio
                || bccomp(
                    $root->getAttribute('SubTotal'),
                    $document->subtotal,
                    6
                ) !== 0
                || bccomp(
                    $root->getAttribute('Total'),
                    $document->total,
                    6
                ) !== 0
            ) {
                throw new InvalidCfdiXml([
                    'Signed XML does not match its fiscal document.',
                ]);
            }

            $checksum = hash('sha256', $signed->xml);

            /*
             * Una repetición idéntica devuelve
             * el mismo intento preparado.
             */
            if (
                $attempt->status
                === FiscalStampAttemptStatus::Prepared
            ) {
                if (
                    ! hash_equals(
                        (string) $attempt->xml_sha256,
                        $checksum
                    )
                    || ! $this->storage->verify($attempt)
                ) {
                    throw new ConflictHttpException(
                        'Prepared XML differs or is unavailable.'
                    );
                }

                return $attempt;
            }

            if (
                $attempt->status
                !== FiscalStampAttemptStatus::Preparing
            ) {
                throw new ConflictHttpException(
                    'Stamp attempt cannot be modified.'
                );
            }

            $metadata = $this->storage->put(
                $attempt,
                $signed->xml
            );

            $attempt->update([
                ...$metadata,
                'status' => FiscalStampAttemptStatus::Prepared,
                'preflight_passed_at' => now(),
            ]);

            return $attempt->refresh();
        });
    }
}
