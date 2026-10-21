<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Enums\FiscalDocumentStatus;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalStampAttempt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MarkStampAttemptUnknown
{
    public function __construct(
        private readonly CompanyContext $companyContext
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

            if (
                $attempt->status
                === FiscalStampAttemptStatus::Unknown
            ) {
                return $attempt;
            }

            if (
                $attempt->status
                !== FiscalStampAttemptStatus::Dispatching
            ) {
                throw new ConflictHttpException(
                    'Only dispatching attempts can become unknown.'
                );
            }

            $attempt->update([
                'status' => FiscalStampAttemptStatus::Unknown,
            ]);

            $document->update([
                'status' => FiscalDocumentStatus::Unknown,
            ]);

            /*
             * Invoice permanece en stamping,
             * porque aún no existe evidencia
             * suficiente para darlo por fallido.
             */

            return $attempt->refresh();
        });
    }
}
