<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CreateCompanyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            'rolePublicIds' => ['required', 'array', 'min:1', 'max:20'],
            'rolePublicIds.*' => ['required', 'uuid', 'distinct'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'],
        ];
    }
}
