<?php

namespace App\Application\Invoices;

use App\Application\Company\CompanyContext;
use App\Application\Fiscal\FiscalSequenceService;
use App\Domain\Fiscal\Enums\FiscalDocumentType;
use App\Domain\Invoice\Enums\InvoiceStatus;
use App\Models\CompanyFiscalProfile;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MarkInvoiceReady
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly FiscalSequenceService $folioService
    ) {}

    public function execute(
        Invoice $invoice,
        string $series
    ): Invoice {
        return DB::transaction(function () use (
            $invoice,
            $series
        ) {
            $companyId = $this->companyContext->id();

            /*
             * Bloqueamos el registro para que dos
             * peticiones simultáneas no preparen
             * la misma factura dos veces.
             */
            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Petición repetida:
             * no consumimos otro folio.
             */
            if ($invoice->status === InvoiceStatus::Ready) {
                if ($invoice->series !== $series) {
                    throw new ConflictHttpException(
                        'Invoice already uses another series.'
                    );
                }

                return $invoice->load('items.taxes');
            }

            /*
             * Sólo los borradores pueden
             * pasar a ready.
             */
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new ConflictHttpException(
                    'Only draft invoices can be marked as ready.'
                );
            }

            /*
             * Debe existir un perfil fiscal
             * activo para la empresa.
             */
            $hasFiscalProfile = CompanyFiscalProfile::query()
                ->where('company_id', $companyId)
                ->where('active', true)
                ->exists();

            if (! $hasFiscalProfile) {
                throw ValidationException::withMessages([
                    'company' => [
                        'The company needs an active fiscal profile.',
                    ],
                ]);
            }

            $invoice->load('items.taxes');

            if ($invoice->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => [
                        'The invoice must contain at least one item.',
                    ],
                ]);
            }

            /*
             * Validaciones básicas de conceptos.
             */
            foreach ($invoice->items as $item) {
                if (
                    bccomp($item->quantity, '0', 6) <= 0
                ) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Item quantities must be greater than zero.',
                        ],
                    ]);
                }

                /*
                 * ObjetoImp 02: requiere
                 * información de impuestos.
                 */
                if (
                    $item->tax_object === '02'
                    && $item->taxes->isEmpty()
                ) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Taxable items require tax information.',
                        ],
                    ]);
                }

                /*
                 * ObjetoImp 01: no desglosar
                 * impuestos en el concepto.
                 */
                if (
                    $item->tax_object === '01'
                    && $item->taxes->isNotEmpty()
                ) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Non-taxable items cannot contain item taxes.',
                        ],
                    ]);
                }
            }

            /*
             * Esta llamada se ejecuta dentro
             * de la transacción exterior.
             */
            $folio = $this->folioService->next(
                FiscalDocumentType::Invoice,
                $series
            );

            $invoice->update([
                'sequence_id' => $folio['sequence_id'],
                'series' => $folio['series'],
                'folio' => $folio['number'],
                'status' => InvoiceStatus::Ready,
            ]);

            return $invoice
                ->refresh()
                ->load('items.taxes');
        });
    }
}
