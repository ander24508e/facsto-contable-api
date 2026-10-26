<?php

namespace App\Http\Requests\Companies\Fiscal;

use App\Domains\Companies\Fiscal\Enums\RimpeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFiscalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accountingRequired' => ['sometimes', 'boolean'],
            'specialTaxpayerNumber' => ['sometimes', 'nullable', 'string', 'max:20'],
            'withholdingAgentNumber' => ['sometimes', 'nullable', 'string', 'max:20'],
            'rimpeType' => ['sometimes', 'nullable', Rule::enum(RimpeType::class)],
        ];
    }
}
