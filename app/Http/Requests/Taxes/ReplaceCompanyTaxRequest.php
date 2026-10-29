<?php

namespace App\Http\Requests\Taxes;

use Illuminate\Foundation\Http\FormRequest;

final class ReplaceCompanyTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fromPublicId' => ['required', 'uuid'],
            'toPublicId' => ['required', 'uuid', 'different:fromPublicId'],
            'categoryPublicId' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
