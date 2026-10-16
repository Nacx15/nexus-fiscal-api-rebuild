<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,

            'business_name' => $this->business_name,

            'rfc' => $this->rfc,

            'tax_regime' => $this->tax_regime,

            'postal_code' => $this->postal_code,

            'email' => $this->email,

            'phone' => $this->phone,

            'status' => $this->status->value,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
