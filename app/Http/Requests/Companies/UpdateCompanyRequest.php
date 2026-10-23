<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'legalName' => ['sometimes', 'required', 'string', 'max:180'],
            'commercialName' => ['sometimes', 'nullable', 'string', 'max:180'],
            'mainAddress' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['legalName' => 'legal_name', 'commercialName' => 'commercial_name', 'mainAddress' => 'main_address'] as $input => $attribute) {
            if ($this->exists($input)) {
                $this->merge([$attribute => $this->input($input)]);
            }
        }
    }
}
