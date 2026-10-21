<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Enums\FiscalDocumentStatus;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Domain\Invoice\Enums\InvoiceStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalStampAttempt;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BeginStampAttempt
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function execute(
        FiscalDocument $document,
        CarbonImmutable $issuedAt
    ): FiscalStampAttempt {
        return DB::transaction(function () use (
            $document,
            $issuedAt
        ) {
            $companyId = $this->companyContext->id();

            /*
             * Lock principal por documento.
             * Todas las operaciones del ciclo
             * deberán respetar este orden.
             */
            $document = FiscalDocument::query()
                ->where('company_id', $companyId)
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $document->status
                !== FiscalDocumentStatus::Pending
            ) {
                throw new ConflictHttpException(
                    'Fiscal document is not pending.'
                );
            }

            /*
             * Sólo soportamos CFDI de ingreso
             * asociados a facturas ready.
             */
            if (
                $document->documentable_type
                !== (new Invoice)->getMorphClass()
                || $document->cfdi_type !== 'I'
            ) {
                throw new ConflictHttpException(
                    'Unsupported fiscal document type.'
                );
            }

            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($document->documentable_id)
                ->firstOrFail();

            if (
                $invoice->status !== InvoiceStatus::Ready
                || $invoice->folio === null
                || $invoice->series === null
            ) {
                throw new ConflictHttpException(
                    'Invoice is not ready for stamping.'
                );
            }

            /*
             * No se crea otro intento si ya
             * existe uno. Su resolución vendrá
             * mediante conciliación explícita.
             */
            $existing = $document->stampAttempts()
                ->orderByDesc('attempt_number')
                ->first();

            if ($existing !== null) {
                if (in_array(
                    $existing->status,
                    [
                        FiscalStampAttemptStatus::Preparing,
                        FiscalStampAttemptStatus::Prepared,
                    ],
                    true
                )) {
                    /*
                     * Idempotencia.
                     * Conserva la fecha original.
                     */
                    return $existing;
                }

                throw new ConflictHttpException(
                    'Previous stamping attempt requires resolution.'
                );
            }

            /*
             * La fecha y zona horaria quedan
             * congeladas desde este momento.
             */
            return $document->stampAttempts()->create([
                'company_id' => $companyId,
                'attempt_number' => 1,
                'status' => FiscalStampAttemptStatus::Preparing,
                'cfdi_date' => $issuedAt->format('Y-m-d\TH:i:s'),
                'cfdi_timezone' => $issuedAt->getTimezone()->getName(),
                'provider' => 'sw_sapien',
            ]);
        }, 3);
    }
}
