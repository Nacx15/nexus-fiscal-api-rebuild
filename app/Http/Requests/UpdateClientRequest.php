<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\ValidSatCatalogCode;

class UpdateClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('rfc')) {
            $this->merge([
                'rfc' => strtoupper(trim($this->rfc)),
            ]);
        }

        if ($this->has('business_name')) {
            $this->merge([
                'business_name' =>
                    trim($this->business_name),
            ]);
        }
    }
    
    public function rules(): array
    {
        $company = $this->route('company');
        $client = $this->route('client');

        return [
            'business_name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'rfc' => [
                'sometimes',
                'required',
                'string',
                'min:12',
                'max:13',

                Rule::unique('clients', 'rfc')
                    ->where(
                        fn ($query) =>
                        $query->where(
                            'company_id',
                            $company->id
                        )
                    )
                    ->ignore($client->id),
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
                'sometimes',
                'required',
                'regex:/^\d{5}$/',
            ],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            'phone' => [
                'sometimes',
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
