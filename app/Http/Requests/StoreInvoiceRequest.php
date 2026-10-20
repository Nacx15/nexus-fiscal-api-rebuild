<?php

namespace App\Http\Requests;

use App\Rules\ValidSatCatalogCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
            'client_id' => [
                'required',

                Rule::exists(
                    'clients',
                    'public_id'
                )->where(
                    fn ($query) => $query
                        ->where(
                            'company_id',
                            $company->id
                        )
                        ->where(
                            'status',
                            'active'
                        )
                ),
            ],

            'payment_form' => [
                'required',
                new ValidSatCatalogCode(
                    'sat_payment_forms'
                ),
            ],

            'payment_method' => [
                'required',
                new ValidSatCatalogCode(
                    'sat_payment_methods'
                ),
            ],

            'cfdi_use' => [
                'required',
                new ValidSatCatalogCode(
                    'sat_cfdi_uses'
                ),
            ],

            /*
             * Primera versión:
             * solamente MXN.
             */
            'currency' => [
                'sometimes',
                Rule::in(['MXN']),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
                'max:500',
            ],

            'items.*.product_id' => [
                'required',

                Rule::exists(
                    'products',
                    'public_id'
                )->where(
                    fn ($query) => $query
                        ->where(
                            'company_id',
                            $company->id
                        )
                        ->where(
                            'status',
                            'active'
                        )
                ),
            ],

            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,6',
            ],

            /*
             * Permite modificar el precio
             * para esta factura.
             */
            'items.*.unit_price' => [
                'sometimes',
                'numeric',
                'min:0',
                'decimal:0,6',
            ],

            /*
             * Importe de descuento,
             * no porcentaje.
             */
            'items.*.discount' => [
                'sometimes',
                'numeric',
                'min:0',
                'decimal:0,6',
            ],

            'items.*.taxes' => [
                'sometimes',
                'array',
            ],

            'items.*.taxes.*.tax' => [
                'required',
                Rule::in([
                    '001',
                    '002',
                    '003',
                ]),
            ],

            'items.*.taxes.*.type' => [
                'required',
                Rule::in([
                    'transferred',
                    'withheld',
                ]),
            ],

            'items.*.taxes.*.factor_type' => [
                'required',
                Rule::in([
                    'Tasa',
                    'Cuota',
                    'Exento',
                ]),
            ],

            'items.*.taxes.*.rate_or_quota' => [
                'nullable',
                'numeric',
                'min:0',
                'decimal:0,6',
            ],
        ];
    }
}
