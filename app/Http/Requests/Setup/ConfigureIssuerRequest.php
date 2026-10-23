<?php

namespace App\Http\Requests\Setup;

use App\Domains\Companies\Context\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfigureIssuerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruc' => ['required', 'string', 'regex:/^\d{13}$/', Rule::unique('companies', 'ruc')->ignore(app(CompanyContext::class)->company()->id)],
            'legalName' => ['required', 'string', 'max:180'],
            'commercialName' => ['nullable', 'string', 'max:180'],
            'mainAddress' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
