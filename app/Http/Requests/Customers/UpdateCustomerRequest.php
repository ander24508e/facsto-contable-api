<?php

namespace App\Http\Requests\Customers;

use App\Domains\Customers\Enums\IdentificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identificationType' => ['sometimes', Rule::enum(IdentificationType::class)],
            'identification' => ['sometimes', 'string', 'max:20'],
            'legalName' => ['sometimes', 'string', 'max:180'],
            'commercialName' => ['sometimes', 'nullable', 'string', 'max:180'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'additionalEmails' => ['sometimes', 'nullable', 'array', 'max:5'],
            'additionalEmails.*' => ['email', 'max:254', 'distinct'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'identification_type' => $this->input('identificationType'),
            'legal_name' => $this->input('legalName'),
            'commercial_name' => $this->input('commercialName'),
        ], fn ($value) => $value !== null));
    }
}
