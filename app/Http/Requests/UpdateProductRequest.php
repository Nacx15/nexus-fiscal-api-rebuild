<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\ValidSatCatalogCode;

class UpdateProductRequest extends FormRequest
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

        if ($this->has('sku')) {
            $data['sku'] = strtoupper(
                trim($this->sku)
            );
        }

        if ($this->has('sat_product_code')) {
            $data['sat_product_code'] = trim(
                $this->sat_product_code
            );
        }

        if ($this->has('sat_unit_code')) {
            $data['sat_unit_code'] = strtoupper(
                trim($this->sat_unit_code)
            );
        }

        if ($this->has('description')) {
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

        $product = $this->route('product');

        return [
            'sku' => [
                'sometimes',
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
                    )
                    ->ignore($product->id),
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
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'description' => [
                'sometimes',
                'required',
                'string',
                'max:1000',
            ],

            'unit_price' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
                'decimal:0,6',
            ],

            'default_tax_rate' => [
                'sometimes',
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
