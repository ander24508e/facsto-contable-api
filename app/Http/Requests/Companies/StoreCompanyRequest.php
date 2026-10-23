<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruc' => ['nullable', 'string', 'regex:/^\d{13}$/', Rule::unique('companies', 'ruc')],
            'legalName' => ['nullable', 'string', 'max:180'],
            'commercialName' => ['nullable', 'string', 'max:180'],
            'mainAddress' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'legal_name' => $this->input('legalName'),
            'commercial_name' => $this->input('commercialName'),
            'main_address' => $this->input('mainAddress'),
        ]);
    }
}
