<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Cfdi\Exceptions\InvalidStampedCfdi;
use App\Domain\Fiscal\Dto\PacStampResult;
use App\Domain\Fiscal\Enums\FiscalDocumentStatus;
use App\Domain\Fiscal\Enums\FiscalFileType;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Domain\Fiscal\Enums\PacStampOutcome;
use App\Domain\Invoice\Enums\InvoiceStatus;
use App\Infrastructure\Fiscal\Storage\PrivateStampXmlStore;
use App\Infrastructure\Fiscal\Xml\StampedCfdiVerifier;
use App\Infrastructure\Storage\StoreFiscalDocumentFile;
use App\Models\FiscalDocument;
use App\Models\FiscalStampAttempt;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class CompleteStampAttempt
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly MarkStampAttemptUnknown $markUnknown,
        private readonly PrivateStampXmlStore $originalStore,
        private readonly StoreFiscalDocumentFile $fileStore,
        private readonly StampedCfdiVerifier $verifier,
    ) {}

    public function execute(
        FiscalStampAttempt $attempt,
        PacStampResult $result
    ): FiscalStampAttempt {
        /*
         * Verificamos tenant incluso antes de
         * decidir cuál resultado procesar.
         */
        $attempt = FiscalStampAttempt::query()
            ->where('company_id', $this->context->id())
            ->whereKey($attempt->id)
            ->firstOrFail();

        if ($result->outcome === PacStampOutcome::Unknown) {
            return $this->markUnknown->execute($attempt);
        }

        if ($result->outcome === PacStampOutcome::Rejected) {
            return $this->completeRejection(
                $attempt,
                $result
            );
        }

        /*
         * Una respuesta exitosa del PAC no
         * basta: necesitamos guardar evidencia.
         */
        if (
            $result->outcome !== PacStampOutcome::Received
            || $result->uuid === null
            || $result->stampedXml === null
        ) {
            return $this->markUnknown->execute($attempt);
        }

        try {
            return DB::transaction(
                fn () => $this->completeReceived(
                    $attempt,
                    $result
                )
            );
        } catch (InvalidStampedCfdi $exception) {
            /*
             * El PAC pudo timbrar, pero no
             * tenemos evidencia verificable.
             * Nunca reintentamos automáticamente.
             */
            return $this->markUnknown->execute($attempt);
        } catch (ConflictHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            /*
             * Puede haber fallado el disco, la
             * base de datos o la confirmación.
             *
             * Intentamos conservar unknown
             * después del rollback.
             */
            report($exception);

            return $this->markUnknown->execute($attempt);
        }
    }

    private function completeReceived(
        FiscalStampAttempt $input,
        PacStampResult $result
    ): FiscalStampAttempt {
        $companyId = $this->context->id();

        /*
         * Mismo orden de locks que las Actions
         * anteriores: documento -> intento.
         */
        $document = FiscalDocument::query()
            ->where('company_id', $companyId)
            ->whereKey($input->fiscal_document_id)
            ->lockForUpdate()
            ->firstOrFail();

        $attempt = FiscalStampAttempt::query()
            ->where('company_id', $companyId)
            ->where('fiscal_document_id', $document->id)
            ->whereKey($input->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $attempt->status === FiscalStampAttemptStatus::Stamped
        ) {
            $file = $document->files()
                ->where('file_type', FiscalFileType::Xml->value)
                ->first();

            if (
                $document->uuid === null
                || strcasecmp($document->uuid, $result->uuid) !== 0
                || $file === null
                || ! hash_equals(
                    $file->checksum,
                    hash('sha256', $result->stampedXml)
                )
                || ! Storage::disk($file->disk)->exists($file->path)
                || ! hash_equals(
                    $file->checksum,
                    hash(
                        'sha256',
                        Storage::disk($file->disk)->get($file->path)
                    )
                )
            ) {
                throw new ConflictHttpException(
                    'Stored stamping confirmation differs.'
                );
            }

            return $attempt;
        }

        $invoice = Invoice::query()
            ->where('company_id', $companyId)
            ->whereKey($document->documentable_id)
            ->lockForUpdate()
            ->firstOrFail();

        /*
        * Hay dos caminos permitidos:
        *
        * A) Respuesta inicial del PAC:
        *    dispatching + stamping.
        *
        * B) Recuperación de evidencia:
        *    unknown + unknown.
        *
        * En ambos casos Invoice permanece
        * en stamping hasta completar.
        */
        $initialConfirmation = (
            $attempt->status
                === FiscalStampAttemptStatus::Dispatching
            && $document->status
                === FiscalDocumentStatus::Stamping
        );

        $recoveredConfirmation = (
            $attempt->status
                === FiscalStampAttemptStatus::Unknown
            && $document->status
                === FiscalDocumentStatus::Unknown
        );

        if (
            $document->documentable_type
                !== (new Invoice)->getMorphClass()
            || (
                ! $initialConfirmation
                && ! $recoveredConfirmation
            )
            || $invoice->status
                !== InvoiceStatus::Stamping
            || $attempt->preflight_passed_at === null
        ) {
            throw new ConflictHttpException(
                'Attempt cannot be confirmed in its current state.'
            );
        }

        /*
        * La evidencia local debe conservar
        * los mismos bytes que enviamos.
        */
        if (! $this->originalStore->verify($attempt)) {
            throw new InvalidStampedCfdi(
                'The original signed XML is missing or corrupted.'
            );
        }

        /*
        * 2. Validar la integridad del XML enviado.
        *
        * El PAC pudo haber timbrado el documento,
        * pero si nuestra copia original está
        * corrupta, no podemos confirmar
        * de forma segura el resultado.
        */
        if (! $this->originalStore->verify($attempt)) {
            throw new InvalidStampedCfdi(
                'The original signed XML is missing or corrupted.'
            );
        }

        $originalXml = Storage::disk(
            $attempt->xml_disk
        )->get($attempt->xml_path);

        $stamp = $this->verifier->verify(
            $originalXml,
            $result->stampedXml,
            $result->uuid
        );

        /*
         * Los bytes del XML timbrado se guardan
         * antes de cambiar los estados.
         */
        $file = $this->fileStore->execute(
            $document,
            FiscalFileType::Xml,
            $result->stampedXml
        );

        /*
         * Comprobamos la persistencia real.
         */
        if (
            ! Storage::disk($file->disk)->exists($file->path)
            || ! hash_equals(
                $file->checksum,
                hash(
                    'sha256',
                    Storage::disk($file->disk)->get($file->path)
                )
            )
        ) {
            throw new InvalidStampedCfdi(
                'Stamped XML could not be verified after storage.'
            );
        }

        /*
         * FechaTimbrado es una fecha/hora local
         * del XML, sin offset explícito.
         */
        $document->update([
            'uuid' => $stamp->uuid,
            'status' => FiscalDocumentStatus::Stamped,
            'stamped_at' => str_replace(
                'T',
                ' ',
                $stamp->stampedAt
            ),
            'certificate_number' => $stamp->issuerCertificateNumber,
            'sat_certificate_number' => $stamp->satCertificateNumber,
            'cfdi_seal' => $stamp->cfdiSeal,
            'sat_seal' => $stamp->satSeal,
        ]);

        $invoice->update([
            'status' => InvoiceStatus::Stamped,
            'issued_at' => str_replace(
                'T',
                ' ',
                $attempt->cfdi_date
            ),
        ]);

        $attempt->update([
            'status' => FiscalStampAttemptStatus::Stamped,
            'provider_reference' => $stamp->uuid,
            'completed_at' => now(),
        ]);

        return $attempt->refresh();
    }

    private function completeRejection(
        FiscalStampAttempt $input,
        PacStampResult $result
    ): FiscalStampAttempt {
        return DB::transaction(function () use (
            $input,
            $result
        ) {
            $companyId = $this->context->id();

            $document = FiscalDocument::query()
                ->where('company_id', $companyId)
                ->whereKey($input->fiscal_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            $attempt = FiscalStampAttempt::query()
                ->where('company_id', $companyId)
                ->where('fiscal_document_id', $document->id)
                ->whereKey($input->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $attempt->status
                !== FiscalStampAttemptStatus::Dispatching
                || $document->status
                !== FiscalDocumentStatus::Stamping
            ) {
                throw new ConflictHttpException(
                    'Attempt cannot be rejected in its current state.'
                );
            }

            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($document->documentable_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $document->documentable_type
                !== (new Invoice)->getMorphClass()
                || $invoice->status !== InvoiceStatus::Stamping
            ) {
                throw new ConflictHttpException(
                    'Invoice is not in stamping state.'
                );
            }

            $attempt->update([
                'status' => FiscalStampAttemptStatus::Rejected,
                'failure_code' => substr(
                    (string) $result->code,
                    0,
                    100
                ),
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => FiscalDocumentStatus::StampFailed,
            ]);

            $invoice->update([
                'status' => InvoiceStatus::StampFailed,
            ]);

            return $attempt->refresh();
        });
    }
}
