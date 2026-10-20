<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrepareFiscalDocumentRequest extends FormRequest
{
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

    public function rules(): array
    {
        return [];
    }
}
