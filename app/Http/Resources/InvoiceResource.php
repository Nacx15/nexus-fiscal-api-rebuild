<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,

            'status' =>
                $this->status->value,

            'series' =>
                $this->series,

            'folio' =>
                $this->folio,

            'receiver' => [
                'rfc' =>
                    $this->receiver_rfc,

                'name' =>
                    $this->receiver_name,

                'tax_regime' =>
                    $this->receiver_tax_regime,

                'postal_code' =>
                    $this->receiver_postal_code,
            ],

            'payment_form' =>
                $this->payment_form,

            'payment_method' =>
                $this->payment_method,

            'cfdi_use' =>
                $this->cfdi_use,

            'currency' =>
                $this->currency,

            'subtotal' =>
                $this->subtotal,

            'discount' =>
                $this->discount,

            'transferred_taxes' =>
                $this->transferred_taxes,

            'withheld_taxes' =>
                $this->withheld_taxes,

            'total' =>
                $this->total,

            'items' =>
                $this->whenLoaded(
                    'items',
                    fn () =>
                    $this->items->map(
                        fn ($item) => [
                            'position' =>
                                $item->position,

                            'product_code' =>
                                $item->product_code,

                            'unit_code' =>
                                $item->unit_code,

                            'description' =>
                                $item->description,

                            'quantity' =>
                                $item->quantity,

                            'unit_price' =>
                                $item->unit_price,

                            'discount' =>
                                $item->discount,

                            'subtotal' =>
                                $item->subtotal,

                            'tax_object' =>
                                $item->tax_object,

                            'total' =>
                                $item->total,

                            'taxes' =>
                                $item->taxes
                                    ->map(
                                        fn ($tax) => [
                                            'tax' =>
                                                $tax->tax,

                                            'type' =>
                                                $tax->type,

                                            'factor_type' =>
                                                $tax->factor_type,

                                            'rate_or_quota' =>
                                                $tax->rate_or_quota,

                                            'base' =>
                                                $tax->base,

                                            'amount' =>
                                                $tax->amount,
                                        ]
                                    ),
                        ]
                    )
                ),

            'created_at' =>
                $this->created_at,
        ];
    }
}
