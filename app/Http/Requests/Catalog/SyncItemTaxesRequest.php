<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SyncItemTaxesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['taxPublicIds' => ['required', 'array'], 'taxPublicIds.*' => ['required', 'uuid', 'distinct', Rule::exists('company_taxes', 'public_id')]];
    }
}
