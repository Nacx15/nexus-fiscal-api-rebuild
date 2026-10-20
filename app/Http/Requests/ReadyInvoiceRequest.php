<?php

namespace App\Http\Requests;

use App\Domain\Fiscal\Enums\FiscalDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadyInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $company = $this->route('company');
        $user = $this->user();

        if (! $company || ! $user) {
            return false;
        }

        return $user->companies()
            ->whereKey($company->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', [
                'owner',
                'admin',
                'billing',
            ])
            ->exists();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('series'))) {
            $this->merge([
                'series' => trim($this->input('series')),
            ]);
        }
    }

    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'series' => [
                'required',
                'string',
                'max:20',

                Rule::exists(
                    'fiscal_sequences',
                    'series'
                )->where(
                    fn ($query) => $query
                        ->where('company_id', $company->id)
                        ->where(
                            'document_type',
                            FiscalDocumentType::Invoice->value
                        )
                        ->where('active', true)
                ),
            ],
        ];
    }
}
