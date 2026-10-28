<?php

namespace App\Http\Requests\Companies;

use App\Domains\Companies\Fiscal\Services\EcuadorRucValidator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Datos de la empresa": every field is optional so the client can save what it has; each one is only
 * checked for its own format. What electronic invoicing still needs is shown by its checklist.
 */
final class SaveCompanyFiscalIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruc' => ['nullable', 'string', 'regex:/^\d{13}$/', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match('/^\d{13}$/', $value) === 1 && ! app(EcuadorRucValidator::class)->isStructurallyValid($value)) {
                    $fail('El RUC no tiene una estructura ecuatoriana válida.');
                }
            }],
            'legalName' => ['nullable', 'string', 'max:180'],
            'commercialName' => ['nullable', 'string', 'max:180'],
            'mainAddress' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'regex:/^\d{9,10}$/'],
            'certificate' => ['nullable', 'file', 'extensions:p12,pfx', 'max:10240'],
            'password' => ['required_with:certificate', 'nullable', 'string', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'ruc.regex' => 'El RUC debe tener exactamente 13 dígitos.',
            'email.email' => 'Escribe un correo válido.',
            'phone.regex' => 'El teléfono debe tener 9 o 10 dígitos, sin espacios ni signos.',
            'certificate.extensions' => 'La firma electrónica debe ser un archivo .p12 o .pfx.',
            'certificate.max' => 'La firma electrónica no puede superar 10 MB.',
            'password.required_with' => 'Escribe la contraseña de la firma electrónica.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['ruc', 'legalName', 'commercialName', 'mainAddress', 'email', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }
}
