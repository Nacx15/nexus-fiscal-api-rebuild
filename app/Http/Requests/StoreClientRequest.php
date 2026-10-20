<?php

namespace App\Http\Requests;

use App\Rules\ValidSatCatalogCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'rfc' => $this->rfc
                ? strtoupper(trim($this->rfc))
                : null,

            'business_name' => $this->business_name
                ? trim($this->business_name)
                : null,
        ]);
    }

    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'business_name' => [
                'required',
                'string',
                'max:255',
            ],

            'rfc' => [
                'required',
                'string',
                'min:12',
                'max:13',

                Rule::unique('clients', 'rfc')
                    ->where(
                        fn ($query) => $query->where(
                            'company_id',
                            $company->id
                        )
                    ),
            ],

            'tax_regime' => [
                'required',
                'string',
                'size:3',
                new ValidSatCatalogCode(
                    'sat_tax_regimes'
                ),
            ],

            'postal_code' => [
                'required',
                'regex:/^\d{5}$/',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ];
    }
}
