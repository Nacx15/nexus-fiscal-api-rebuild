<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Enums\FiscalDocumentType;
use App\Models\FiscalSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FiscalSequenceService
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function next(
        FiscalDocumentType $documentType,
        string $series
    ): array {
        return DB::transaction(function () use (
            $documentType,
            $series
        ) {
            $sequence = FiscalSequence::query()
                ->where(
                    'company_id',
                    $this->companyContext->id()
                )
                ->where(
                    'document_type',
                    $documentType->value
                )
                ->where('series', $series)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw new RuntimeException(
                    'Fiscal sequence not found.'
                );
            }

            $nextNumber = $sequence->current_number + 1;

            $sequence->update([
                'current_number' => $nextNumber,
            ]);

            $formattedNumber = str_pad(
                (string) $nextNumber,
                $sequence->padding,
                '0',
                STR_PAD_LEFT
            );

            return [
                'sequence_id' => $sequence->id,
                'series' => $sequence->series,
                'number' => $nextNumber,
                'formatted' => $sequence->series
                    .$formattedNumber,
            ];
        });
    }
}
