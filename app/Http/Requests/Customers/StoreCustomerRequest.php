<?php

namespace App\Http\Requests\Customers;

use App\Domains\Customers\Enums\IdentificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identificationType' => ['required', Rule::enum(IdentificationType::class)],
            'identification' => ['required', 'string', 'max:20'],
            'legalName' => ['required', 'string', 'max:180'],
            'commercialName' => ['nullable', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'identification_type' => $this->input('identificationType'),
            'legal_name' => $this->input('legalName'),
            'commercial_name' => $this->input('commercialName'),
        ]);
    }
}
