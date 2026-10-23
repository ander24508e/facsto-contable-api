<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEmissionPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'establishmentPublicId' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^\d{3}$/'],
            'name' => ['required', 'string', 'max:120'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
