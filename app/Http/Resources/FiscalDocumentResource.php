<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalDocumentResource extends JsonResource
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
            'cfdi_type' => $this->cfdi_type,
            'version' => $this->version,

            'uuid' => $this->uuid,
            'status' => $this->status->value,

            'issuer' => [
                'rfc' => $this->issuer_rfc,
                'name' => $this->issuer_name,
                'tax_regime' => $this->issuer_tax_regime,
                'postal_code' => $this->issuer_postal_code,
            ],

            'receiver' => [
                'rfc' => $this->receiver_rfc,
                'name' => $this->receiver_name,
                'tax_regime' => $this->receiver_tax_regime,
                'postal_code' => $this->receiver_postal_code,
            ],

            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,

            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'transferred_taxes' => $this->transferred_taxes,
            'withheld_taxes' => $this->withheld_taxes,
            'total' => $this->total,

            'stamped_at' => $this->stamped_at,

            'files' => $this->whenLoaded(
                'files',
                fn () => $this->files->map(
                    fn ($file) => [
                        'type' => $file->file_type->value,
                        'size' => $file->size,
                        'checksum' => $file->checksum,
                    ]
                )
            ),

            'created_at' => $this->created_at,
        ];
    }
}
