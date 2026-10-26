<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:25', 'regex:/^[\pL\pM ]+$/u'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^\d+$/D'],
            'currentPassword' => [Rule::requiredIf(fn (): bool => $this->changesEmail()), 'nullable', 'string', 'current_password:web'],
            'password' => [
                Rule::requiredIf(fn (): bool => $this->changesPassword()),
                'nullable',
                'string',
                'confirmed:passwordConfirmation',
                Password::min(10)->letters()->numbers(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && Hash::check($value, $this->user()->password)) {
                        $fail('La nueva contraseña debe ser distinta de la actual.');
                    }
                },
            ],
            'passwordConfirmation' => [Rule::requiredIf(fn (): bool => $this->changesPassword()), 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'El nombre solo puede contener letras y espacios.',
            'name.max' => 'El nombre no puede superar los 25 caracteres.',
            'phone.regex' => 'El teléfono solo puede contener números.',
            'phone.max' => 'El teléfono no puede superar los 10 dígitos.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'currentPassword.required' => 'Confirma tu contraseña actual para cambiar el correo.',
            'currentPassword.current_password' => 'La contraseña actual no es correcta.',
        ];
    }

    private function changesEmail(): bool
    {
        $email = $this->input('email');

        return is_string($email) && mb_strtolower($email) !== $this->user()->email;
    }

    private function changesPassword(): bool
    {
        return $this->exists('password') || $this->exists('passwordConfirmation');
    }
}
