<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Invoice\Enums\InvoiceStatus;
use App\Models\CompanyFiscalProfile;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PrepareFiscalDocument
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function execute(Invoice $invoice): FiscalDocument
    {
        return DB::transaction(function () use ($invoice) {
            $companyId = $this->companyContext->id();

            /*
             * La factura debe pertenecer a la
             * empresa activa.
             *
             * Bloqueamos para evitar duplicados
             * entre peticiones concurrentes.
             */
            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status !== InvoiceStatus::Ready) {
                throw new ConflictHttpException(
                    'The invoice must be ready.'
                );
            }

            /*
             * Si ya existe, devolvemos el mismo
             * documento sin volver a crearlo.
             */
            $existing = $invoice->fiscalDocument()
                ->first();

            if ($existing !== null) {
                return $existing->load('files');
            }

            $profile = CompanyFiscalProfile::query()
                ->where('company_id', $companyId)
                ->where('active', true)
                ->first();

            if (! $profile) {
                throw ValidationException::withMessages([
                    'company' => [
                        'The company requires an active fiscal profile.',
                    ],
                ]);
            }

            /*
             * Snapshot de los datos fiscales.
             * No volveremos a depender del
             * catálogo maestro para reconstruir
             * los datos históricos.
             */
            $document = $invoice->fiscalDocument()
                ->create([
                    'company_id' => $companyId,

                    'cfdi_type' => 'I',
                    'version' => '4.0',
                    'status' => 'pending',

                    'issuer_rfc' => $profile->rfc,
                    'issuer_name' => $profile->business_name,
                    'issuer_tax_regime' => $profile->tax_regime,
                    'issuer_postal_code' => $profile->postal_code,

                    'receiver_rfc' => $invoice->receiver_rfc,
                    'receiver_name' => $invoice->receiver_name,
                    'receiver_tax_regime' => $invoice->receiver_tax_regime,
                    'receiver_postal_code' => $invoice->receiver_postal_code,

                    'currency' => $invoice->currency,
                    'exchange_rate' => $invoice->exchange_rate,

                    'subtotal' => $invoice->subtotal,
                    'discount' => $invoice->discount,
                    'transferred_taxes' => $invoice->transferred_taxes,
                    'withheld_taxes' => $invoice->withheld_taxes,
                    'total' => $invoice->total,
                ]);

            return $document->load('files');
        });
    }
}
