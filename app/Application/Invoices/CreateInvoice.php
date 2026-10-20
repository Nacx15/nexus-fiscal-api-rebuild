<?php

namespace App\Application\Invoices;

use App\Application\Company\CompanyContext;
use App\Domain\Shared\Services\DecimalCalculator;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateInvoice
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly DecimalCalculator $decimal
    ) {
    }

    public function execute(
        array $data,
        User $user
    ): Invoice {
        return DB::transaction(function () use (
            $data,
            $user
        ) {
            $companyId =
                $this->companyContext->id();

            $client = Client::query()
                ->where(
                    'company_id',
                    $companyId
                )
                ->where(
                    'public_id',
                    $data['client_id']
                )
                ->where(
                    'status',
                    'active'
                )
                ->firstOrFail();

            $invoice = Invoice::create([
                'company_id' => $companyId,

                'client_id' => $client->id,

                'created_by' => $user->id,

                /*
                 * Snapshot del receptor.
                 */
                'receiver_rfc' =>
                    $client->rfc,

                'receiver_name' =>
                    $client->business_name,

                'receiver_tax_regime' =>
                    $client->tax_regime,

                'receiver_postal_code' =>
                    $client->postal_code,

                'payment_form' =>
                    $data['payment_form'],

                'payment_method' =>
                    $data['payment_method'],

                'cfdi_use' =>
                    $data['cfdi_use'],

                'currency' =>
                    $data['currency'] ?? 'MXN',

                'exchange_rate' =>
                    '1.000000',

                'notes' =>
                    $data['notes'] ?? null,
            ]);

            $invoiceSubtotal = '0.000000';
            $invoiceDiscount = '0.000000';

            $invoiceTransferred =
                '0.000000';

            $invoiceWithheld =
                '0.000000';

            $invoiceTotal =
                '0.000000';

            foreach (
                $data['items']
                as $index => $itemData
            ) {
                $product = Product::query()
                    ->where(
                        'company_id',
                        $companyId
                    )
                    ->where(
                        'public_id',
                        $itemData['product_id']
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->firstOrFail();

                $quantity =
                    $this->decimal->normalize(
                        $itemData['quantity']
                    );

                $unitPrice =
                    $this->decimal->normalize(
                        $itemData['unit_price']
                            ?? $product->unit_price
                    );

                $discount =
                    $this->decimal->normalize(
                        $itemData['discount']
                            ?? '0'
                    );

                $subtotal =
                    $this->decimal->multiply(
                        $quantity,
                        $unitPrice
                    );

                if (
                    $this->decimal->compare(
                        $discount,
                        $subtotal
                    ) > 0
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.discount" =>
                            'The discount cannot exceed the item subtotal.',
                    ]);
                }

                $taxBase =
                    $this->decimal->subtract(
                        $subtotal,
                        $discount
                    );

                $transferred =
                    '0.000000';

                $withheld =
                    '0.000000';

                $item = $invoice
                    ->items()
                    ->create([
                        'product_id' =>
                            $product->id,

                        'position' =>
                            $index + 1,

                        'product_code' =>
                            $product->sat_product_code,

                        'unit_code' =>
                            $product->sat_unit_code,

                        'unit_name' =>
                            $product->unit_name,

                        'description' =>
                            $product->description,

                        'quantity' =>
                            $quantity,

                        'unit_price' =>
                            $unitPrice,

                        'discount' =>
                            $discount,

                        'subtotal' =>
                            $subtotal,

                        'tax_object' =>
                            $product->tax_object,

                        /*
                         * Se actualiza al terminar
                         * impuestos.
                         */
                        'total' =>
                            '0.000000',
                    ]);

                foreach (
                    $itemData['taxes'] ?? []
                    as $taxData
                ) {
                    $rate =
                        $taxData['rate_or_quota']
                        ?? null;

                    if (
                        $taxData['factor_type']
                        !== 'Exento'
                        && $rate === null
                    ) {
                        throw ValidationException::withMessages([
                            "items.{$index}.taxes" =>
                                'rate_or_quota is required for non-exempt taxes.',
                        ]);
                    }

                    $amount = '0.000000';

                    if (
                        $taxData['factor_type']
                        !== 'Exento'
                    ) {
                        $amount =
                            $this->decimal->multiply(
                                $taxBase,
                                $this->decimal->normalize(
                                    $rate
                                )
                            );
                    }

                    $item->taxes()->create([
                        'tax' =>
                            $taxData['tax'],

                        'type' =>
                            $taxData['type'],

                        'factor_type' =>
                            $taxData['factor_type'],

                        'rate_or_quota' =>
                            $rate !== null
                                ? $this->decimal
                                    ->normalize($rate)
                                : null,

                        'base' =>
                            $taxBase,

                        'amount' =>
                            $amount,
                    ]);

                    if (
                        $taxData['type']
                        === 'transferred'
                    ) {
                        $transferred =
                            $this->decimal->add(
                                $transferred,
                                $amount
                            );
                    } else {
                        $withheld =
                            $this->decimal->add(
                                $withheld,
                                $amount
                            );
                    }
                }

                $itemTotal =
                    $this->decimal->add(
                        $taxBase,
                        $transferred
                    );

                $itemTotal =
                    $this->decimal->subtract(
                        $itemTotal,
                        $withheld
                    );

                $item->update([
                    'total' => $itemTotal,
                ]);

                $invoiceSubtotal =
                    $this->decimal->add(
                        $invoiceSubtotal,
                        $subtotal
                    );

                $invoiceDiscount =
                    $this->decimal->add(
                        $invoiceDiscount,
                        $discount
                    );

                $invoiceTransferred =
                    $this->decimal->add(
                        $invoiceTransferred,
                        $transferred
                    );

                $invoiceWithheld =
                    $this->decimal->add(
                        $invoiceWithheld,
                        $withheld
                    );

                $invoiceTotal =
                    $this->decimal->add(
                        $invoiceTotal,
                        $itemTotal
                    );
            }

            $invoice->update([
                'subtotal' =>
                    $invoiceSubtotal,

                'discount' =>
                    $invoiceDiscount,

                'transferred_taxes' =>
                    $invoiceTransferred,

                'withheld_taxes' =>
                    $invoiceWithheld,

                'total' =>
                    $invoiceTotal,
            ]);

            return $invoice
                ->refresh()
                ->load('items.taxes');
        });
    }
}