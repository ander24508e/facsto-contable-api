<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                'confirmed:passwordConfirmation',
                Password::min(10)->letters()->numbers(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && Hash::check($value, $this->user()->password)) {
                        $fail('La nueva contraseña debe ser distinta de la actual.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
