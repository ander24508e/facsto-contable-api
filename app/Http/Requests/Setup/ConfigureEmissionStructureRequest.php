<?php

namespace App\Http\Requests\Setup;

use App\Domains\Companies\Fiscal\Enums\EmissionType;
use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfigureEmissionStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'establishmentCode' => ['required', 'string', 'regex:/^\d{3}$/'],
            'establishmentName' => ['required', 'string', 'max:120'],
            'establishmentAddress' => ['nullable', 'string', 'max:255'],
            'emissionPointCode' => ['required', 'string', 'regex:/^\d{3}$/'],
            'emissionPointName' => ['required', 'string', 'max:120'],
            'environment' => ['sometimes', Rule::enum(SriEnvironment::class)],
            'emissionType' => ['sometimes', Rule::enum(EmissionType::class)],
        ];
    }
}
