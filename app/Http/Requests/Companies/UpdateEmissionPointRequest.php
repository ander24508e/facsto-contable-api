<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEmissionPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'regex:/^\d{3}$/'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'isActive' => ['sometimes', 'boolean'],
            'isDefault' => ['sometimes', 'boolean'],
        ];
    }
}
