<?php

namespace App\Http\Requests\Taxes;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompanyTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'validFrom' => ['nullable', 'date_format:Y-m-d'],
            'validUntil' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:validFrom'],
        ];
    }
}
