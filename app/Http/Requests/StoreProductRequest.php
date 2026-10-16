<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\ValidSatCatalogCode;

class StoreProductRequest extends FormRequest
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
        $data = [];

        if ($this->sku !== null) {
            $data['sku'] = strtoupper(
                trim($this->sku)
            );
        }

        if ($this->sat_product_code !== null) {
            $data['sat_product_code'] = trim(
                $this->sat_product_code
            );
        }

        if ($this->sat_unit_code !== null) {
            $data['sat_unit_code'] = strtoupper(
                trim($this->sat_unit_code)
            );
        }

        if ($this->description !== null) {
            $data['description'] = trim(
                $this->description
            );
        }

        $this->merge($data);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'sku' => [
                'required',
                'string',
                'max:100',

                Rule::unique('products', 'sku')
                    ->where(
                        fn ($query) =>
                        $query->where(
                            'company_id',
                            $company->id
                        )
                    ),
            ],

            'sat_product_code' => [
                'required',
                'regex:/^\d{8}$/',
                new ValidSatCatalogCode(
                    'sat_product_services'
                ),
            ],

            'sat_unit_code' => [
                'required',
                'string',
                'max:10',
                new ValidSatCatalogCode(
                    'sat_units'
                ),
            ],

            'unit_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'description' => [
                'required',
                'string',
                'max:1000',
            ],

            'unit_price' => [
                'required',
                'numeric',
                'min:0',
                'decimal:0,6',
            ],

            'default_tax_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'decimal:0,6',
            ],

            'tax_object' => [
                'required',
                'regex:/^\d{2}$/',
                new ValidSatCatalogCode(
                    'sat_tax_objects'
                ),
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
