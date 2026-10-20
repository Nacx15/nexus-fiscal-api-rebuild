<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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

            'sku' => $this->sku,

            'sat_product_code' => $this->sat_product_code,

            'sat_unit_code' => $this->sat_unit_code,

            'unit_name' => $this->unit_name,

            'description' => $this->description,

            'unit_price' => $this->unit_price,

            'default_tax_rate' => $this->default_tax_rate,

            'tax_object' => $this->tax_object,

            'status' => $this->status->value,

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,
        ];
    }
}
