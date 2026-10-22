<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Enums\FiscalDocumentStatus;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Domain\Fiscal\Enums\FiscalStampAuditAction;
use App\Domain\Invoice\Enums\InvoiceStatus;
use App\Infrastructure\Fiscal\Storage\PrivateStampXmlStore;
use App\Models\FiscalDocument;
use App\Models\FiscalStampAttempt;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MarkStampAttemptDispatching
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PrivateStampXmlStore $storage,
        private readonly RecordFiscalStampTransition $audit,
    ) {}

    public function execute(
        FiscalStampAttempt $attempt
    ): FiscalStampAttempt {
        return DB::transaction(function () use ($attempt) {
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

            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($document->documentable_id)
                ->firstOrFail();

            if (
                $document->documentable_type
                !== (new Invoice)->getMorphClass()
                || $document->status
                !== FiscalDocumentStatus::Pending
                || $invoice->status
                !== InvoiceStatus::Ready
                || $attempt->status
                !== FiscalStampAttemptStatus::Prepared
                || $attempt->preflight_passed_at === null
            ) {
                throw new ConflictHttpException(
                    'Stamp attempt is not ready for dispatch.'
                );
            }

            if (! $this->storage->verify($attempt)) {
                throw new ConflictHttpException(
                    'Prepared XML is missing or corrupted.'
                );
            }

            /*
             * A partir de esta transición,
             * asumimos que pudo haber contacto
             * con el PAC.
             */
            $attempt->update([
                'status' => FiscalStampAttemptStatus::Dispatching,
                'dispatch_started_at' => now(),
            ]);

            $document->update([
                'status' => FiscalDocumentStatus::Stamping,
            ]);

            $invoice->update([
                'status' => InvoiceStatus::Stamping,
            ]);

            $this->audit->record(
                $attempt,
                FiscalStampAuditAction::DispatchStarted,
                FiscalStampAttemptStatus::Prepared
            );

            return $attempt->refresh();
        });
    }
}
